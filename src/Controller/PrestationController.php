<?php

namespace App\Controller;

use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use App\Entity\User;
use App\Entity\TypePrestation;
use App\Enum\StatutPrestation;
use App\Repository\PrestationRepository;
use App\Service\PrestationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/adm/prestation')]
class PrestationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PrestationRepository $repository,
        private PrestationManager $prestationManager
    ) {}

    // =====================================================
    // LISTE DES PRESTATIONS
    // =====================================================
    #[Route('/', name: 'admin_prestation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Mise à jour des statuts avant affichage
        $this->prestationManager->updateAllBonDeCommande();

        $search = $request->query->get('search', '');
        $statut = $request->query->get('statut', '');
        $employe = $request->query->get('employe', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');

        $tab = $request->query->get('tab', 'tous');

        $qb = $this->repository->createQueryBuilder('p')
            ->leftJoin('p.bonDeCommande', 'b')
            ->leftJoin('p.employe', 'e')
            ->leftJoin('p.typePrestation', 't');

        // Filtre de recherche
        if ($search) {
            $qb->andWhere('b.clientNom LIKE :search 
                        OR b.numeroCommande LIKE :search
                        OR e.nom LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filtre par statut
        if ($statut) {
            $qb->andWhere('p.statut = :statut')
               ->setParameter('statut', $statut);
        }

        // Filtre par employé
        if ($employe) {
            $qb->andWhere('p.employe = :employe')
               ->setParameter('employe', $employe);
        }

        // Filtre par dates
        if ($dateDebut) {
            $qb->andWhere('p.datePrestation >= :dateDebut')
               ->setParameter('dateDebut', new \DateTimeImmutable($dateDebut . ' 00:00:00'));
        }

        if ($dateFin) {
            $qb->andWhere('p.datePrestation <= :dateFin')
               ->setParameter('dateFin', new \DateTimeImmutable($dateFin . ' 23:59:59'));
        }

        // Filtre par onglet
        if ($tab === 'non_effectue') {
            $qb->andWhere('p.statut = :statutTab')
               ->setParameter('statutTab', StatutPrestation::NON_EFFECTUE);
        } elseif ($tab === 'programme') {
            $qb->andWhere('p.statut = :statutTab')
               ->setParameter('statutTab', StatutPrestation::PROGRAMME);
        } elseif ($tab === 'en_cours') {
            $qb->andWhere('p.statut = :statutTab')
               ->setParameter('statutTab', StatutPrestation::EN_COURS);
        } elseif ($tab === 'termines') {
            $qb->andWhere('p.statut = :statutTab')
               ->setParameter('statutTab', StatutPrestation::TERMINE);
        }

        // Tri : non effectué et en cours en premier, puis par date
        $qb->addOrderBy('p.datePrestation', 'ASC');

        $prestations = $qb->getQuery()->getResult();
        $employes = $this->em->getRepository(User::class)->findAll();

        return $this->render('admin/prestation/index.html.twig', [
            'prestations' => $prestations,
            'employes' => $employes,
            'currentTab' => $tab,
        ]);
    }

    // =====================================================
    // CRÉER UNE NOUVELLE PRESTATION
    // =====================================================
    #[Route('/new', name: 'admin_prestation_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $prestation = new Prestation();
        $prestation->setStatut(StatutPrestation::A_PROGRAMMER);
        $prestation->setDescription('');

        // Pré-remplissage si lié à un bon
        $bonId = $request->query->get('bonId');
        if ($bonId) {
            $bon = $this->em->getRepository(BonDeCommande::class)->find($bonId);
            if ($bon) {
                $prestation->setBonDeCommande($bon);
                $prestation->setTypePrestation($bon->getTypePrestation());
            }
        }

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $prestation, true);
        }

        $bons = $this->em->getRepository(BonDeCommande::class)->findAll();
        $employes = $this->em->getRepository(User::class)->findAll();
        $types = $this->em->getRepository(TypePrestation::class)->findAll();

        return $this->render('admin/prestation/form.html.twig', [
            'prestation' => $prestation,
            'bons' => $bons,
            'employes' => $employes,
            'types' => $types,
            'isNew' => true,
        ]);
    }

    // =====================================================
    // VOIR UNE PRESTATION
    // =====================================================
    #[Route('/{id}', name: 'admin_prestation_show', methods: ['GET'])]
    public function show(Prestation $prestation): Response
    {
        return $this->render('admin/prestation/show.html.twig', [
            'prestation' => $prestation,
        ]);
    }

    // =====================================================
    // MODIFIER UNE PRESTATION
    // =====================================================
    #[Route('/{id}/edit', name: 'admin_prestation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Prestation $prestation): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $prestation, false);
        }

        $bons = $this->em->getRepository(BonDeCommande::class)->findAll();
        $employes = $this->em->getRepository(User::class)->findAll();
        $types = $this->em->getRepository(TypePrestation::class)->findAll();

        return $this->render('admin/prestation/form.html.twig', [
            'prestation' => $prestation,
            'bons' => $bons,
            'employes' => $employes,
            'types' => $types,
            'isNew' => false,
        ]);
    }

    // =====================================================
    // SUPPRIMER UNE PRESTATION
    // =====================================================
    #[Route('/{id}/delete', name: 'admin_prestation_delete', methods: ['POST'])]
    public function delete(Request $request, Prestation $prestation): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('admin_prestation_index');
        }

        $bon = $prestation->getBonDeCommande();
        
        $this->em->remove($prestation);
        $this->em->flush();

        // Mise à jour du bon de commande après suppression
        if ($bon) {
            $this->prestationManager->updateBonDeCommande($bon);
        }

        $this->addFlash('success', 'La prestation a été supprimée');

        return $this->redirectToRoute('admin_prestation_index');
    }

    // =====================================================
    // MÉTHODE PRIVÉE : TRAITEMENT DU FORMULAIRE
    // =====================================================
    private function handleForm(Request $request, Prestation $prestation, bool $isNew): Response
    {
        // Date et heure
        $date = $request->request->get('date');
        $heure = $request->request->get('heure');
        
        if ($date && $heure) {
            try {
                $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $heure);
                if ($dateTime) {
                    $prestation->setDatePrestation($dateTime);
                }
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Format de date invalide');
                return $this->redirectToRoute($isNew ? 'admin_prestation_new' : 'admin_prestation_edit', 
                    $isNew ? [] : ['id' => $prestation->getId()]
                );
            }
        }

        $prestation->setDescription($request->request->get('description', ''));

        // Bon de commande
        $bonId = $request->request->get('bonDeCommande');
        if ($bonId) {
            $bon = $this->em->getRepository(BonDeCommande::class)->find($bonId);
            $prestation->setBonDeCommande($bon);
        }

        // Employé
        $employeId = $request->request->get('employe');
        if ($employeId) {
            $employe = $this->em->getRepository(User::class)->find($employeId);
            $prestation->setEmploye($employe);
        }

        // Type de prestation
        $typeId = $request->request->get('typePrestation');
        if ($typeId) {
            $type = $this->em->getRepository(TypePrestation::class)->find($typeId);
            $prestation->setTypePrestation($type);
        }

        // Validation basique
        if (!$prestation->getDatePrestation() || !$employeId) {
            $this->addFlash('danger', !$prestation->getDatePrestation() ? 'La date de prestation est obligatoire' : 'L\'employé est obligatoire');
            return $this->redirectToRoute($isNew ? 'admin_prestation_new' : 'admin_prestation_edit', 
                $isNew ? [] : ['id' => $prestation->getId()]
            );
        }

        // Mise à jour du statut AVANT la persistance
        $this->prestationManager->updatePrestationStatut($prestation);

        $this->em->persist($prestation);
        $this->em->flush();

        // Mise à jour du bon de commande APRÈS la persistance
        $bon = $prestation->getBonDeCommande();
        if ($bon) {
            $this->prestationManager->updateBonDeCommande($bon);
        }

        $this->addFlash('success', $isNew 
            ? 'Prestation créée avec succès' 
            : 'Prestation modifiée avec succès'
        );

        return $this->redirectToRoute('admin_prestation_show', ['id' => $prestation->getId()]);
    }
}