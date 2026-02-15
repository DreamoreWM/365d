<?php

namespace App\Controller;

use App\Entity\Prestation;
use App\Entity\User;
use App\Entity\BonDeCommande;
use App\Enum\StatutPrestation;
use App\Repository\PrestationRepository;
use App\Repository\UserRepository;
use App\Repository\BonDeCommandeRepository;
use App\Repository\GroupeGeographiqueRepository;
use App\Service\PrestationManager;
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
        private PrestationManager $prestationManager
    ) {}

    // =====================================================
    // PAGE PRINCIPALE DU PLANNING
    // =====================================================
    #[Route('/', name: 'admin_planning_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $selectedDate = $request->query->get('date', date('Y-m-d'));
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

        return $this->render('admin/planning/index.html.twig', [
            'employes' => $employes,
            'selectedDate' => $selectedDate,
            'selectedEmployeId' => $selectedEmployeId,
            'prestations' => $prestations,
            'groupesGeo' => $groupesGeo,
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

        // Mettre à jour le statut
        $this->prestationManager->updatePrestationStatut($prestation);

        $this->em->persist($prestation);
        $this->em->flush();

        // Mettre à jour le bon de commande
        $this->prestationManager->updateBonDeCommande($bon);

        $this->addFlash('success', 'Prestation créée avec succès pour ' . $employe->getNom());

        return $this->redirectToRoute('admin_planning_index', [
            'date' => $date,
            'employe' => $employeId
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
        
        $results = [];
        foreach ($bons as $bon) {
            // Extraire le code postal de l'adresse
            $codePostal = null;
            if (preg_match('/\b(\d{5})\b/', $bon->getClientAdresse(), $matches)) {
                $codePostal = $matches[1];
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
        $sort = $request->query->get('sort', 'date_desc');
        
        // Si aucun filtre n'est appliqué et pas de recherche
        if (strlen($query) < 2 && !$statut && !$groupeGeoId) {
            // Retourner les bons disponibles par défaut
            return $this->bonsDisponibles($bonRepo);
        }
        
        $qb = $bonRepo->createQueryBuilder('b');
        
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
        
        // Filtre par groupe géographique (basé sur le code postal dans l'adresse)
        if ($groupeGeoId) {
            $groupe = $this->groupeGeoRepo->find($groupeGeoId);
            if ($groupe && !empty($groupe->getVilles())) {
                // Créer une condition pour matcher les codes postaux
                $codePostalConditions = [];
                foreach ($groupe->getVilles() as $codePostal) {
                    $codePostalConditions[] = "b.clientAdresse LIKE :cp_" . $codePostal;
                    $qb->setParameter('cp_' . $codePostal, '% ' . $codePostal . ' %');
                }
                if (!empty($codePostalConditions)) {
                    $qb->andWhere('(' . implode(' OR ', $codePostalConditions) . ')');
                }
            }
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
            $groupeGeo = null;
            if ($codePostal) {
                $groupes = $this->groupeGeoRepo->findAllActifs();
                foreach ($groupes as $groupe) {
                    if (in_array($codePostal, $groupe->getVilles())) {
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

        $startDate = new \DateTimeImmutable($start);
        $endDate = new \DateTimeImmutable($end);

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
            $endDt = $startDt->modify('+1 hour');

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
        $dateObj = new \DateTimeImmutable($date);
        
        // Statistiques du jour
        $statsJour = $this->prestationRepo->createQueryBuilder('p')
            ->select('COUNT(p.id) as total')
            ->addSelect('SUM(CASE WHEN p.statut = :programme THEN 1 ELSE 0 END) as programme')
            ->addSelect('SUM(CASE WHEN p.statut = :en_cours THEN 1 ELSE 0 END) as en_cours')
            ->addSelect('SUM(CASE WHEN p.statut = :termine THEN 1 ELSE 0 END) as termine')
            ->where('DATE(p.datePrestation) = :date')
            ->setParameter('programme', StatutPrestation::PROGRAMME->value)
            ->setParameter('en_cours', StatutPrestation::EN_COURS->value)
            ->setParameter('termine', StatutPrestation::TERMINE->value)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleResult();
        
        // Charge par employé
        $chargeEmployes = $this->prestationRepo->createQueryBuilder('p')
            ->select('e.id, e.nom')
            ->addSelect('COUNT(p.id) as nombre_prestations')
            ->join('p.employe', 'e')
            ->where('DATE(p.datePrestation) = :date')
            ->setParameter('date', $date)
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
