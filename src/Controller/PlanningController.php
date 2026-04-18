<?php

namespace App\Controller;

use App\Entity\Prestation;
use App\Entity\User;
use App\Entity\BonDeCommande;
use App\Enum\CreneauPrestation;
use App\Enum\StatutBonDeCommande;
use App\Enum\StatutPrestation;
use App\Repository\PrestationRepository;
use App\Repository\UserRepository;
use App\Repository\BonDeCommandeRepository;
use App\Repository\GroupeGeographiqueRepository;
use App\Repository\TypePrestationRepository;
use App\Service\GeocodingService;
use App\Service\ParametreService;
use App\Service\PrestationManager;
use App\Service\TourneeOptimizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/planning')]
class PlanningController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PrestationRepository $prestationRepo,
        private UserRepository $userRepo,
        private BonDeCommandeRepository $bonRepo,
        private GroupeGeographiqueRepository $groupeGeoRepo,
        private TypePrestationRepository $typePrestationRepo,
        private PrestationManager $prestationManager,
        private GeocodingService $geocoder,
        private TourneeOptimizer $optimizer,
        private ParametreService $parametres,
    ) {}

    private function hmToMin(?string $hm): int
    {
        if (!$hm) return 0;
        [$h, $m] = array_pad(explode(':', $hm), 2, 0);
        return ((int) $h) * 60 + ((int) $m);
    }

    /**
     * Derives a flexible creneau from a prestation's start time using the admin parameters.
     * Used when the form didn't pass an explicit slot — falls back to FIXE for off-hour times.
     */
    private function inferCreneau(\DateTimeImmutable $dateTime): CreneauPrestation
    {
        $pauseDeb = $this->hmToMin($this->parametres->get(ParametreService::HEURE_PAUSE_DEBUT));
        $pauseFin = $this->hmToMin($this->parametres->get(ParametreService::HEURE_PAUSE_FIN));
        $finJour  = $this->hmToMin($this->parametres->get(ParametreService::HEURE_FIN_JOURNEE));
        $debutJ   = $this->hmToMin($this->parametres->get(ParametreService::HEURE_PREMIERE));
        $tMin     = (int) $dateTime->format('H') * 60 + (int) $dateTime->format('i');
        if ($tMin >= $debutJ && $tMin < $pauseDeb)      return CreneauPrestation::MATIN;
        if ($tMin >= $pauseFin && $tMin < $finJour)     return CreneauPrestation::APREM;
        return CreneauPrestation::FIXE;
    }

    // =====================================================
    // PAGE PRINCIPALE DU PLANNING
    // =====================================================
    #[Route('/', name: 'admin_planning_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $selectedDate = $request->query->get('date', date('Y-m-d', strtotime('+1 day')));
        $selectedEmployeId = $request->query->get('employe');

        // Liste des employés
        $employes = $this->userRepo->findAll();

        // Prestations du jour sélectionné
        $date = new \DateTimeImmutable($selectedDate);
        $dateStart = $date->setTime(0, 0, 0);
        $dateEnd = $date->setTime(23, 59, 59);

        $qb = $this->prestationRepo->createQueryBuilder('p')
            ->where('p.datePrestation >= :start')
            ->andWhere('p.datePrestation <= :end')
            ->setParameter('start', $dateStart)
            ->setParameter('end', $dateEnd)
            ->orderBy('p.datePrestation', 'ASC');

        // Filtre par employé si sélectionné
        if ($selectedEmployeId) {
            $qb->andWhere('p.employe = :employe')
               ->setParameter('employe', $selectedEmployeId);
        }

        $prestations = $qb->getQuery()->getResult();

        // Groupes géographiques pour le filtrage
        $groupesGeo = $this->groupeGeoRepo->findAllActifs();

        // Types de prestation pour le filtrage
        $typesPrestations = $this->typePrestationRepo->findAll();

        return $this->render('admin/planning/index.html.twig', [
            'employes' => $employes,
            'selectedDate' => $selectedDate,
            'selectedEmployeId' => $selectedEmployeId,
            'prestations' => $prestations,
            'groupesGeo' => $groupesGeo,
            'typesPrestations' => $typesPrestations,
        ]);
    }

    // =====================================================
    // CRÉER UNE PRESTATION DEPUIS LE PLANNING
    // =====================================================
    #[Route('/create-prestation', name: 'admin_planning_create_prestation', methods: ['POST'])]
    public function createPrestation(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $bonId = $request->request->get('bon');
        $employeId = $request->request->get('employe');
        $date = $request->request->get('date');
        $heure = $request->request->get('heure', '09:00');
        $creneauStr = $request->request->get('creneau');

        // Validation
        if (!$bonId || !$employeId || !$date) {
            $this->addFlash('danger', 'Tous les champs sont obligatoires');
            return $this->redirectToRoute('admin_planning_index');
        }

        $bon = $this->bonRepo->find($bonId);
        $employe = $this->userRepo->find($employeId);

        if (!$bon || !$employe) {
            $this->addFlash('danger', 'Bon de commande ou employé introuvable');
            return $this->redirectToRoute('admin_planning_index');
        }

        // Créer la prestation
        $prestation = new Prestation();
        $prestation->setBonDeCommande($bon);
        $prestation->setEmploye($employe);
        $prestation->setTypePrestation($bon->getTypePrestation());
        $prestation->setDescription($request->request->get('description', ''));

        // Date + heure
        try {
            $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $heure);
            $prestation->setDatePrestation($dateTime);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Format de date invalide');
            return $this->redirectToRoute('admin_planning_index');
        }

        // Créneau (matin/aprem/fixe) — when the form leaves it empty, infer from the chosen
        // time so the prestation is flexible and eligible for tour optimization.
        $creneau = CreneauPrestation::tryFrom($creneauStr ?? '') ?? $this->inferCreneau($dateTime);
        $prestation->setCreneau($creneau);

        // Snapshot duration: type's théorique → fallback to default param
        $type = $bon->getTypePrestation();
        $duree = $type?->getDureeTheoriqueMinutes()
            ?? $this->parametres->getInt(ParametreService::DUREE_DEFAUT_MINUTES);
        $prestation->setDureeMinutes($duree);

        // Mettre à jour le statut
        $this->prestationManager->updatePrestationStatut($prestation);

        $this->em->persist($prestation);
        $this->em->flush();

        // Geocode bon if needed (best-effort)
        $this->geocoder->ensureGeocoded($bon);
        $this->em->flush();

        // Re-optimize the day's tour for this employee (skip if creneau=fixe — anchor only)
        try {
            $diag = $this->optimizer->optimizeDay($dateTime, $employe);
            if ($diag['depotMissing']) {
                $this->addFlash('warning',
                    'Adresse de la société non géolocalisée — les tournées ne peuvent pas être optimisées. Renseignez-la dans Paramètres.');
            }
            if (!empty($diag['notGeocoded'])) {
                $noms = implode(', ', array_slice($diag['notGeocoded'], 0, 5));
                $reste = count($diag['notGeocoded']) - 5;
                $this->addFlash('warning', sprintf(
                    'Géocodage impossible pour %d bon(s) : %s%s. Les trajets de ces bons sont ignorés par l\'optimiseur.',
                    count($diag['notGeocoded']), $noms, $reste > 0 ? " (+$reste autres)" : ''
                ));
            }
        } catch (\Throwable $e) {
            // Optimization is best-effort — never block creation
        }

        // Mettre à jour le bon de commande
        $this->prestationManager->updateBonDeCommande($bon);

        $this->addFlash('success', 'Prestation créée avec succès pour ' . $employe->getNom());

        return $this->redirectToRoute('admin_planning_index', [
            'date' => $date,
            'employe' => $employeId
        ]);
    }

    // =====================================================
    // RE-OPTIMISER UNE JOURNÉE (recalcule ordre + trajets)
    // =====================================================
    #[Route('/reoptimize', name: 'admin_planning_reoptimize', methods: ['POST'])]
    public function reoptimize(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $dateStr   = $request->request->get('date');
        $employeId = $request->request->get('employe');
        $reclass   = $request->request->get('reclasser') === '1';

        if (!$dateStr || !$employeId) {
            $this->addFlash('danger', 'Date et employé obligatoires');
            return $this->redirectToRoute('admin_planning_index');
        }

        $employe = $this->userRepo->find($employeId);
        if (!$employe) {
            $this->addFlash('danger', 'Employé introuvable');
            return $this->redirectToRoute('admin_planning_index');
        }

        try {
            $date = new \DateTimeImmutable($dateStr);
        } catch (\Throwable) {
            $this->addFlash('danger', 'Date invalide');
            return $this->redirectToRoute('admin_planning_index');
        }

        // Option: convert existing FIXE prestations (created before the inference fix)
        // into MATIN/APREM based on time so the optimizer can reorder them.
        if ($reclass) {
            $start = $date->setTime(0, 0, 0);
            $end   = $date->setTime(23, 59, 59);
            $ps = $this->prestationRepo->createQueryBuilder('p')
                ->where('p.datePrestation >= :s')->andWhere('p.datePrestation <= :e')
                ->andWhere('p.employe = :emp')
                ->setParameter('s', $start)->setParameter('e', $end)
                ->setParameter('emp', $employe)
                ->getQuery()->getResult();
            foreach ($ps as $p) {
                if ($p->getCreneau() === CreneauPrestation::FIXE || $p->getCreneau() === null) {
                    $p->setCreneau($this->inferCreneau($p->getDatePrestation()));
                }
            }
            $this->em->flush();
        }

        try {
            $diag = $this->optimizer->optimizeDay($date, $employe);
            if ($diag['depotMissing']) {
                $this->addFlash('warning',
                    'Adresse de la société non géolocalisée — optimisation limitée. Renseignez-la dans Paramètres.');
            }
            if (!empty($diag['notGeocoded'])) {
                $this->addFlash('warning', sprintf(
                    'Géocodage impossible pour %d bon(s). Leurs trajets seront ignorés.',
                    count($diag['notGeocoded'])
                ));
            } else {
                $this->addFlash('success', 'Tournée recalculée.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erreur lors de l\'optimisation : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_planning_index', [
            'date' => $dateStr,
            'employe' => $employeId,
        ]);
    }

    // =====================================================
    // SUPPRIMER UNE PRESTATION DEPUIS LE PLANNING
    // =====================================================
    #[Route('/delete-prestation/{id}', name: 'admin_planning_delete_prestation', methods: ['POST'])]
    public function deletePrestation(Prestation $prestation): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $bon = $prestation->getBonDeCommande();
        $date = $prestation->getDatePrestation()->format('Y-m-d');
        $employeId = $prestation->getEmploye()?->getId();

        $this->em->remove($prestation);
        $this->em->flush();

        if ($bon) {
            $this->prestationManager->updateBonDeCommande($bon);
        }

        $this->addFlash('success', 'Prestation supprimée');

        return $this->redirectToRoute('admin_planning_index', [
            'date' => $date,
            'employe' => $employeId
        ]);
    }

    // =====================================================
    // CRÉER PLUSIEURS PRESTATIONS EN LOT (AJAX)
    // =====================================================
    #[Route('/create-batch', name: 'admin_planning_create_batch', methods: ['POST'])]
    public function createBatch(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = json_decode($request->getContent(), true);

        $bonIds = $data['bonIds'] ?? [];
        $employeId = $data['employeId'] ?? null;
        $date = $data['date'] ?? null;
        $heure = $data['heure'] ?? '09:00';

        // Validation
        if (empty($bonIds) || !$employeId || !$date) {
            return $this->json(['error' => 'Données manquantes'], 400);
        }

        $employe = $this->userRepo->find($employeId);
        if (!$employe) {
            return $this->json(['error' => 'Employé introuvable'], 404);
        }

        $created = [];
        $errors = [];

        foreach ($bonIds as $bonId) {
            try {
                $bon = $this->bonRepo->find($bonId);
                if (!$bon) {
                    $errors[] = "Bon #$bonId introuvable";
                    continue;
                }

                // Créer la prestation
                $prestation = new Prestation();
                $prestation->setBonDeCommande($bon);
                $prestation->setEmploye($employe);
                $prestation->setTypePrestation($bon->getTypePrestation());
                $prestation->setDescription('');

                // Date + heure
                $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $heure);
                $prestation->setDatePrestation($dateTime);

                // Infer creneau from time so batch prestations are eligible for optimization
                $prestation->setCreneau($this->inferCreneau($dateTime));
                $type = $bon->getTypePrestation();
                $prestation->setDureeMinutes(
                    $type?->getDureeTheoriqueMinutes()
                    ?? $this->parametres->getInt(ParametreService::DUREE_DEFAUT_MINUTES)
                );

                // Mettre à jour le statut
                $this->prestationManager->updatePrestationStatut($prestation);

                $this->em->persist($prestation);
                $this->em->flush();

                // Mettre à jour le bon de commande
                $this->prestationManager->updateBonDeCommande($bon);

                $created[] = [
                    'prestationId' => $prestation->getId(),
                    'bonId' => $bon->getId(),
                    'clientNom' => $bon->getClientNom(),
                ];

            } catch (\Exception $e) {
                $errors[] = "Erreur pour bon #$bonId: " . $e->getMessage();
            }
        }

        return $this->json([
            'success' => true,
            'created' => $created,
            'errors' => $errors,
            'count' => count($created),
        ]);
    }

    // =====================================================
    // RÉCUPÉRER LES BONS DISPONIBLES
    // =====================================================
    #[Route('/bons-disponibles', name: 'admin_planning_bons_disponibles', methods: ['GET'])]
    public function bonsDisponibles(BonDeCommandeRepository $bonRepo): JsonResponse
    {
        // Récupérer les bons qui ont encore des prestations à programmer
        $qb = $bonRepo->createQueryBuilder('b')
            ->where('b.nombrePrestations < b.nombrePrestationsNecessaires')
            ->orWhere('b.nombrePrestations IS NULL')
            ->orderBy('b.dateCommande', 'DESC')
            ->setMaxResults(50);
        
        $bons = $qb->getQuery()->getResult();
        
        $groupes = $this->groupeGeoRepo->findAllActifs();

        $results = [];
        foreach ($bons as $bon) {
            $codePostal = null;
            if (preg_match('/\b(\d{5})\b/', $bon->getClientAdresse(), $matches)) {
                $codePostal = $matches[1];
            }

            $groupeGeo = null;
            if ($codePostal) {
                foreach ($groupes as $g) {
                    if (in_array($codePostal, array_column($g->getVillesData(), 'codePostal'))) {
                        $groupeGeo = ['id' => $g->getId(), 'nom' => $g->getNom(), 'couleur' => $g->getCouleur()];
                        break;
                    }
                }
            }

            $results[] = [
                'id' => $bon->getId(),
                'clientNom' => $bon->getClientNom(),
                'clientAdresse' => $bon->getClientAdresse(),
                'numeroCommande' => $bon->getNumeroCommande(),
                'nombrePrestations' => $bon->getNombrePrestations() ?? 0,
                'nombrePrestationsNecessaires' => $bon->getNombrePrestationsNecessaires(),
                'dateCommande' => $bon->getDateCommande()?->format('d/m/Y'),
                'statut' => $bon->getStatut()->value,
                'codePostal' => $codePostal,
                'groupeGeo' => $groupeGeo,
                'typePrestation' => $bon->getTypePrestation() ? [
                    'id' => $bon->getTypePrestation()->getId(),
                    'nom' => $bon->getTypePrestation()->getNom(),
                    'dureeMinutes' => $bon->getTypePrestation()->getDureeTheoriqueMinutes(),
                ] : null,
                'dureeMinutes' => $bon->getTypePrestation()?->getDureeTheoriqueMinutes()
                    ?? $this->parametres->getInt(ParametreService::DUREE_DEFAUT_MINUTES),
            ];
        }

        return $this->json($results);
    }

    // =====================================================
    // RECHERCHE DE BONS AVEC FILTRES AVANCÉS
    // =====================================================
    #[Route('/search-bons', name: 'admin_planning_search_bons', methods: ['GET'])]
    public function searchBons(Request $request, BonDeCommandeRepository $bonRepo): JsonResponse
    {
        $query = $request->query->get('q', '');
        $statut = $request->query->get('statut', '');
        $groupeGeoId = $request->query->get('groupe_geo', '');
        $typePrestationId = $request->query->get('type_prestation', '');
        $sort = $request->query->get('sort', 'date_desc');
        $all = $request->query->get('all', '');

        // Sans filtre et sans flag all= → raccourci bons disponibles
        if (!$all && strlen($query) < 2 && !$statut && !$groupeGeoId && !$typePrestationId) {
            return $this->bonsDisponibles($bonRepo);
        }
        
        $qb = $bonRepo->createQueryBuilder('b');

        // Depuis le wizard planning (all=1) : uniquement les bons à programmer
        if ($all && !$statut) {
            $qb->andWhere('b.statut = :aprog')
               ->setParameter('aprog', StatutBonDeCommande::A_PROGRAMMER->value);
        }

        // Recherche textuelle
        if (strlen($query) >= 2) {
            $qb->andWhere('(b.clientNom LIKE :query OR b.clientAdresse LIKE :query OR b.numeroCommande LIKE :query)')
               ->setParameter('query', '%' . $query . '%');
        }
        
        // Filtre par statut
        if ($statut) {
            if ($statut === 'disponible') {
                $qb->andWhere('(b.nombrePrestations < b.nombrePrestationsNecessaires OR b.nombrePrestations IS NULL)');
            } else {
                $qb->andWhere('b.statut = :statut')
                   ->setParameter('statut', $statut);
            }
        }
        
        // Filtre par groupe géographique (basé sur le nom de ville dans l'adresse)
        if ($groupeGeoId) {
            $groupe = $this->groupeGeoRepo->find($groupeGeoId);
            if ($groupe && !empty($groupe->getVilles())) {
                $villeConditions = [];
                foreach ($groupe->getVilles() as $index => $ville) {
                    // Sanitize parameter name (city names may contain spaces/accents)
                    $paramKey = 'ville_' . $index;
                    $villeConditions[] = "b.clientAdresse LIKE :" . $paramKey;
                    // No trailing space: city name is often at end of address (e.g. "59120 Loos")
                    $qb->setParameter($paramKey, '% ' . $ville . '%');
                }
                if (!empty($villeConditions)) {
                    $qb->andWhere('(' . implode(' OR ', $villeConditions) . ')');
                }
            }
        }

        // Filtre par type de prestation
        if ($typePrestationId) {
            $qb->andWhere('b.typePrestation = :typePrestation')
               ->setParameter('typePrestation', $typePrestationId);
        }
        
        // Tri
        switch ($sort) {
            case 'date_asc':
                $qb->orderBy('b.dateCommande', 'ASC');
                break;
            case 'client_asc':
                $qb->orderBy('b.clientNom', 'ASC');
                break;
            case 'client_desc':
                $qb->orderBy('b.clientNom', 'DESC');
                break;
            case 'prestations':
                $qb->orderBy('b.nombrePrestations', 'ASC');
                break;
            default:
                $qb->orderBy('b.dateCommande', 'DESC');
        }
        
        $qb->setMaxResults(100);
        
        $bons = $qb->getQuery()->getResult();
        
        $results = [];
        foreach ($bons as $bon) {
            // Extraire le code postal de l'adresse
            $codePostal = null;
            if (preg_match('/\b(\d{5})\b/', $bon->getClientAdresse(), $matches)) {
                $codePostal = $matches[1];
            }
            
            // Déterminer le groupe géographique
            // villes[] stores city names, villesData[] stores objects with codePostal
            $groupeGeo = null;
            if ($codePostal) {
                $groupes = $this->groupeGeoRepo->findAllActifs();
                foreach ($groupes as $groupe) {
                    $codesPostauxGroupe = array_column($groupe->getVillesData(), 'codePostal');
                    if (in_array($codePostal, $codesPostauxGroupe)) {
                        $groupeGeo = [
                            'id' => $groupe->getId(),
                            'nom' => $groupe->getNom(),
                            'couleur' => $groupe->getCouleur()
                        ];
                        break;
                    }
                }
            }
            
            $results[] = [
                'id' => $bon->getId(),
                'clientNom' => $bon->getClientNom(),
                'clientAdresse' => $bon->getClientAdresse(),
                'numeroCommande' => $bon->getNumeroCommande(),
                'nombrePrestations' => $bon->getNombrePrestations() ?? 0,
                'nombrePrestationsNecessaires' => $bon->getNombrePrestationsNecessaires(),
                'dateCommande' => $bon->getDateCommande()?->format('d/m/Y'),
                'statut' => $bon->getStatut()->value,
                'codePostal' => $codePostal,
                'groupeGeo' => $groupeGeo,
                'typePrestation' => $bon->getTypePrestation() ? [
                    'id' => $bon->getTypePrestation()->getId(),
                    'nom' => $bon->getTypePrestation()->getNom(),
                    'dureeMinutes' => $bon->getTypePrestation()->getDureeTheoriqueMinutes(),
                ] : null,
                'dureeMinutes' => $bon->getTypePrestation()?->getDureeTheoriqueMinutes()
                    ?? $this->parametres->getInt(ParametreService::DUREE_DEFAUT_MINUTES),
            ];
        }

        return $this->json($results);
    }

    // =====================================================
    // RÉCUPÉRER UN BON SPÉCIFIQUE
    // =====================================================
    #[Route('/bon/{id}', name: 'admin_planning_get_bon', methods: ['GET'])]
    public function getBon(BonDeCommande $bon): JsonResponse
    {
        // Extraire le code postal
        $codePostal = null;
        if (preg_match('/\b(\d{5})\b/', $bon->getClientAdresse(), $matches)) {
            $codePostal = $matches[1];
        }
        
        return $this->json([
            'id' => $bon->getId(),
            'clientNom' => $bon->getClientNom(),
            'clientAdresse' => $bon->getClientAdresse(),
            'numeroCommande' => $bon->getNumeroCommande(),
            'nombrePrestations' => $bon->getNombrePrestations() ?? 0,
            'nombrePrestationsNecessaires' => $bon->getNombrePrestationsNecessaires(),
            'dateCommande' => $bon->getDateCommande()?->format('d/m/Y'),
            'statut' => $bon->getStatut()->value,
            'codePostal' => $codePostal,
        ]);
    }

    // =====================================================
    // VÉRIFIER LES DOUBLONS AVANT ASSIGNATION
    // =====================================================
    #[Route('/check-duplicate', name: 'admin_planning_check_duplicate', methods: ['POST'])]
    public function checkDuplicate(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = json_decode($request->getContent(), true);
        $bonId = $data['bonId'] ?? null;
        $employeId = $data['employeId'] ?? null;
        $date = $data['date'] ?? null;

        if (!$bonId || !$employeId || !$date) {
            return $this->json(['valid' => false, 'error' => 'Paramètres manquants'], 400);
        }

        $bon = $this->bonRepo->find($bonId);
        $employe = $this->userRepo->find($employeId);

        if (!$bon || !$employe) {
            return $this->json(['valid' => false, 'error' => 'Bon ou employé introuvable'], 404);
        }

        // Vérifier si une prestation existe déjà pour ce bon + employé + date
        $dateObj = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        $dateStart = $dateObj->setTime(0, 0, 0);
        $dateEnd = $dateObj->setTime(23, 59, 59);

        $existing = $this->prestationRepo->createQueryBuilder('p')
            ->where('p.bonDeCommande = :bon')
            ->andWhere('p.employe = :employe')
            ->andWhere('p.datePrestation >= :start')
            ->andWhere('p.datePrestation <= :end')
            ->setParameter('bon', $bon)
            ->setParameter('employe', $employe)
            ->setParameter('start', $dateStart)
            ->setParameter('end', $dateEnd)
            ->getQuery()
            ->getResult();

        if (count($existing) > 0) {
            return $this->json([
                'valid' => false,
                'duplicate' => true,
                'message' => sprintf(
                    'Le bon "%s" est déjà assigné à %s le %s',
                    $bon->getClientNom(),
                    $employe->getNom(),
                    $dateObj->format('d/m/Y')
                )
            ]);
        }

        // Vérifier si le bon est déjà assigné à un autre employé le même jour
        $existingOtherEmploye = $this->prestationRepo->createQueryBuilder('p')
            ->where('p.bonDeCommande = :bon')
            ->andWhere('p.employe != :employe')
            ->andWhere('p.datePrestation >= :start')
            ->andWhere('p.datePrestation <= :end')
            ->setParameter('bon', $bon)
            ->setParameter('employe', $employe)
            ->setParameter('start', $dateStart)
            ->setParameter('end', $dateEnd)
            ->getQuery()
            ->getResult();

        if (count($existingOtherEmploye) > 0) {
            $otherEmploye = $existingOtherEmploye[0]->getEmploye();
            return $this->json([
                'valid' => false,
                'duplicate' => true,
                'message' => sprintf(
                    'Le bon "%s" est déjà assigné à %s le %s',
                    $bon->getClientNom(),
                    $otherEmploye->getNom(),
                    $dateObj->format('d/m/Y')
                )
            ]);
        }

        return $this->json(['valid' => true, 'duplicate' => false]);
    }

    // =====================================================
    // ÉVÉNEMENTS CALENDRIER (FULLCALENDAR)
    // =====================================================
    #[Route('/events', name: 'admin_planning_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $start = $request->query->get('start');
        $end = $request->query->get('end');
        $employeIds = $request->query->all('employes');

        if (!$start || !$end) {
            return $this->json([]);
        }

        $startDate = new \DateTimeImmutable(str_replace(' ', '+', $start));
        $endDate   = new \DateTimeImmutable(str_replace(' ', '+', $end));

        $qb = $this->prestationRepo->createQueryBuilder('p')
            ->leftJoin('p.employe', 'e')
            ->leftJoin('p.bonDeCommande', 'b')
            ->leftJoin('p.typePrestation', 't')
            ->where('p.datePrestation >= :start')
            ->andWhere('p.datePrestation <= :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('p.datePrestation', 'ASC');

        if (!empty($employeIds)) {
            $qb->andWhere('p.employe IN (:employes)')
               ->setParameter('employes', $employeIds);
        }

        $prestations = $qb->getQuery()->getResult();

        $defaultColors = [
            '#e91e63','#9c27b0','#673ab7','#3f51b5','#2196f3',
            '#009688','#4caf50','#ff9800','#ff5722','#795548',
            '#607d8b','#00bcd4',
        ];

        $events = [];
        foreach ($prestations as $p) {
            $employe = $p->getEmploye();
            $bon = $p->getBonDeCommande();
            $type = $p->getTypePrestation();

            $color = $defaultColors[($employe?->getId() ?? 0) % count($defaultColors)];

            $startDt = $p->getDatePrestation();
            $duree = $p->getDureeMinutes()
                ?? $type?->getDureeTheoriqueMinutes()
                ?? $this->parametres->getInt(ParametreService::DUREE_DEFAUT_MINUTES);
            $endDt = $startDt->modify('+' . $duree . ' minutes');

            $events[] = [
                'id' => $p->getId(),
                'title' => $bon ? $bon->getClientNom() : 'Prestation #' . $p->getId(),
                'start' => $startDt->format('c'),
                'end' => $endDt->format('c'),
                'color' => $color,
                'textColor' => '#ffffff',
                'extendedProps' => [
                    'employeId' => $employe?->getId(),
                    'employeNom' => $employe?->getNom() ?? 'Non assigné',
                    'statut' => $p->getStatut()?->value,
                    'statutLabel' => $p->getStatut()?->label(),
                    'statutCssClass' => $p->getStatut()?->cssClass(),
                    'statutIcon' => $p->getStatut()?->icon(),
                    'clientAdresse' => $bon?->getClientAdresse(),
                    'typePrestation' => $type ? (string) $type : null,
                    'bonId' => $bon?->getId(),
                    'numeroCommande' => $bon?->getNumeroCommande(),
                    'prestationId' => $p->getId(),
                    'creneau' => $p->getCreneau()?->value,
                    'creneauLabel' => $p->getCreneau()?->label(),
                    'dureeMinutes' => $duree,
                    'dureeTrajetMinutes' => $p->getDureeTrajetMinutes(),
                ],
            ];
        }

        return $this->json($events);
    }

    // =====================================================
    // LISTE DES EMPLOYÉS (POUR LE PANNEAU CALENDRIER)
    // =====================================================
    #[Route('/employes', name: 'admin_planning_employes', methods: ['GET'])]
    public function employes(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $employes = $this->userRepo->findAll();

        $defaultColors = [
            '#e91e63','#9c27b0','#673ab7','#3f51b5','#2196f3',
            '#009688','#4caf50','#ff9800','#ff5722','#795548',
            '#607d8b','#00bcd4',
        ];

        $result = [];
        foreach ($employes as $emp) {
            $result[] = [
                'id' => $emp->getId(),
                'nom' => $emp->getNom(),
                'couleur' => $defaultColors[$emp->getId() % count($defaultColors)],
            ];
        }

        return $this->json($result);
    }

    // =====================================================
    // STATISTIQUES DU PLANNING
    // =====================================================
    #[Route('/stats', name: 'admin_planning_stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $date = $request->query->get('date', date('Y-m-d'));
        $dateObj   = new \DateTimeImmutable($date);
        $dateStart = $dateObj->setTime(0, 0, 0);
        $dateEnd   = $dateObj->setTime(23, 59, 59);

        // Statistiques du jour
        $statsJour = $this->prestationRepo->createQueryBuilder('p')
            ->select('COUNT(p.id) as total')
            ->addSelect('SUM(CASE WHEN p.statut = :programme THEN 1 ELSE 0 END) as programme')
            ->addSelect('SUM(CASE WHEN p.statut = :en_cours THEN 1 ELSE 0 END) as en_cours')
            ->addSelect('SUM(CASE WHEN p.statut = :termine THEN 1 ELSE 0 END) as termine')
            ->where('p.datePrestation >= :start')
            ->andWhere('p.datePrestation <= :end')
            ->setParameter('programme', StatutPrestation::PROGRAMME->value)
            ->setParameter('en_cours', StatutPrestation::EN_COURS->value)
            ->setParameter('termine', StatutPrestation::TERMINE->value)
            ->setParameter('start', $dateStart)
            ->setParameter('end', $dateEnd)
            ->getQuery()
            ->getSingleResult();

        // Charge par employé
        $chargeEmployes = $this->prestationRepo->createQueryBuilder('p')
            ->select('e.id, e.nom')
            ->addSelect('COUNT(p.id) as nombre_prestations')
            ->join('p.employe', 'e')
            ->where('p.datePrestation >= :start')
            ->andWhere('p.datePrestation <= :end')
            ->setParameter('start', $dateStart)
            ->setParameter('end', $dateEnd)
            ->groupBy('e.id')
            ->orderBy('nombre_prestations', 'DESC')
            ->getQuery()
            ->getResult();
        
        return $this->json([
            'date' => $date,
            'stats' => $statsJour,
            'chargeEmployes' => $chargeEmployes,
        ]);
    }
}
