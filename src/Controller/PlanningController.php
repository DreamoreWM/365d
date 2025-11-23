<?php

namespace App\Controller;

use App\Entity\Prestation;
use App\Entity\User;
use App\Entity\BonDeCommande;
use App\Repository\PrestationRepository;
use App\Repository\UserRepository;
use App\Repository\BonDeCommandeRepository;
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

        // Bons de commande disponibles (non terminés)
        $bonsDisponibles = $this->bonRepo->createQueryBuilder('b')
            ->where('b.statut != :termine')
            ->setParameter('termine', 'terminé')
            ->orderBy('b.dateCommande', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/planning/index.html.twig', [
            'employes' => $employes,
            'selectedDate' => $selectedDate,
            'selectedEmployeId' => $selectedEmployeId,
            'prestations' => $prestations,
            'bonsDisponibles' => $bonsDisponibles,
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
        $prestation->setDescription('');
        
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

    // src/Controller/Admin/PlanningController.php

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
            $results[] = [
                'id' => $bon->getId(),
                'clientNom' => $bon->getClientNom(),
                'clientAdresse' => $bon->getClientAdresse(),
                'numeroCommande' => $bon->getNumeroCommande(),
                'nombrePrestations' => $bon->getNombrePrestations() ?? 0,
                'nombrePrestationsNecessaires' => $bon->getNombrePrestationsNecessaires(),
                'dateCommande' => $bon->getDateCommande()?->format('d/m/Y'),
            ];
        }
        
        return $this->json($results);
    }

    #[Route('/search-bons', name: 'admin_planning_search_bons', methods: ['GET'])]
    public function searchBons(Request $request, BonDeCommandeRepository $bonRepo): JsonResponse
    {
        $query = $request->query->get('q', '');
        
        if (strlen($query) < 2) {
            return $this->json([]);
        }
        
        $qb = $bonRepo->createQueryBuilder('b')
            ->where('b.clientNom LIKE :query')
            ->orWhere('b.clientAdresse LIKE :query')
            ->orWhere('b.numeroCommande LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('b.dateCommande', 'DESC')
            ->setMaxResults(20);
        
        $bons = $qb->getQuery()->getResult();
        
        $results = [];
        foreach ($bons as $bon) {
            $results[] = [
                'id' => $bon->getId(),
                'clientNom' => $bon->getClientNom(),
                'clientAdresse' => $bon->getClientAdresse(),
                'numeroCommande' => $bon->getNumeroCommande(),
                'nombrePrestations' => $bon->getNombrePrestations() ?? 0,
                'nombrePrestationsNecessaires' => $bon->getNombrePrestationsNecessaires(),
                'dateCommande' => $bon->getDateCommande()?->format('d/m/Y'),
            ];
        }
        
        return $this->json($results);
    }

    #[Route('/bon/{id}', name: 'admin_planning_get_bon', methods: ['GET'])]
    public function getBon(BonDeCommande $bon): JsonResponse
    {
        return $this->json([
            'id' => $bon->getId(),
            'clientNom' => $bon->getClientNom(),
            'clientAdresse' => $bon->getClientAdresse(),
            'numeroCommande' => $bon->getNumeroCommande(),
            'nombrePrestations' => $bon->getNombrePrestations() ?? 0,
            'nombrePrestationsNecessaires' => $bon->getNombrePrestationsNecessaires(),
            'dateCommande' => $bon->getDateCommande()?->format('d/m/Y'),
        ]);
    }
}