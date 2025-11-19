<?php

namespace App\Controller;

use App\Entity\BonDeCommande;
use App\Entity\TypePrestation;
use App\Repository\BonDeCommandeRepository;
use App\Service\PrestationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use thiagoalessio\TesseractOCR\TesseractOCR;

#[Route('/adm/bon-commande')]
class BonDeCommandeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private BonDeCommandeRepository $repository,
        private PrestationManager $prestationManager
    ) {}

    // =====================================================
    // LISTE DES BONS DE COMMANDE
    // =====================================================
    #[Route('/', name: 'admin_bon_commande_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Mise à jour des statuts avant affichage
        $this->prestationManager->updateAllBonDeCommande(); 

        $search = $request->query->get('search', '');
        $statut = $request->query->get('statut', '');

        $qb = $this->repository->createQueryBuilder('b')
            ->orderBy('b.dateCommande', 'DESC');

        // Filtre de recherche
        if ($search) {
            $qb->andWhere('b.clientNom LIKE :search 
                        OR b.clientEmail LIKE :search 
                        OR b.clientTelephone LIKE :search 
                        OR b.numeroCommande LIKE :search')
            ->setParameter('search', '%' . $search . '%');
        }

        // Filtre par statut
        if ($statut) {
            $qb->andWhere('b.statut = :statut')
            ->setParameter('statut', $statut);
        }

        $bonDeCommandes = $qb->getQuery()->getResult();

        return $this->render('admin/bon_commande/index.html.twig', [
            'bonDeCommandes' => $bonDeCommandes,
        ]);
    }

    // =====================================================
    // CRÉER UN NOUVEAU BON
    // =====================================================
    #[Route('/new', name: 'admin_bon_commande_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $bon = new BonDeCommande();

        // Préremplissage depuis OCR (paramètres GET)
        if ($request->query->has('numeroCommande')) {
            $bon->setNumeroCommande($request->query->get('numeroCommande'));
            $bon->setClientNom($request->query->get('clientNom', ''));
            $bon->setClientAdresse($request->query->get('clientAdresse', ''));
            $bon->setClientTelephone($request->query->get('clientTelephone', ''));
            $bon->setClientComplementAdresse($request->query->get('clientComplementAdresse', ''));
        }

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $bon, true);
        }

        $typePrestations = $this->em->getRepository(TypePrestation::class)->findAll();

        return $this->render('admin/bon_commande/form.html.twig', [
            'bon' => $bon,
            'typePrestations' => $typePrestations,
            'isNew' => true,
        ]);
    }

    // =====================================================
    // VOIR UN BON
    // =====================================================
    #[Route('/{id}', name: 'admin_bon_commande_show', methods: ['GET'])]
    public function show(BonDeCommande $bon): Response
    {
        // Mise à jour du statut avant affichage
        $this->prestationManager->updateBonDeCommande($bon);

        return $this->render('admin/bon_commande/show.html.twig', [
            'bon' => $bon,
        ]);
    }

    // =====================================================
    // MODIFIER UN BON
    // =====================================================
    #[Route('/{id}/edit', name: 'admin_bon_commande_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, BonDeCommande $bon): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $bon, false);
        }

        $typePrestations = $this->em->getRepository(TypePrestation::class)->findAll();

        return $this->render('admin/bon_commande/form.html.twig', [
            'bon' => $bon,
            'typePrestations' => $typePrestations,
            'isNew' => false,
        ]);
    }

    // =====================================================
    // SUPPRIMER UN BON
    // =====================================================
    #[Route('/{id}/delete', name: 'admin_bon_commande_delete', methods: ['POST'])]
    public function delete(Request $request, BonDeCommande $bon): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('admin_bon_commande_index');
        }

        $clientNom = $bon->getClientNom();

        $this->em->remove($bon);
        $this->em->flush();

        $this->addFlash('success', "Le bon de commande de {$clientNom} a été supprimé");

        return $this->redirectToRoute('admin_bon_commande_index');
    }

    // =====================================================
    // MÉTHODE PRIVÉE : TRAITEMENT DU FORMULAIRE
    // =====================================================
    private function handleForm(Request $request, BonDeCommande $bon, bool $isNew): Response
    {
        $bon->setNumeroCommande($request->request->get('numeroCommande'));
        $bon->setClientNom($request->request->get('clientNom'));
        $bon->setClientEmail($request->request->get('clientEmail'));
        $bon->setClientTelephone($request->request->get('clientTelephone'));
        $bon->setClientAdresse($request->request->get('clientAdresse'));
        $bon->setClientComplementAdresse($request->request->get('clientComplementAdresse'));

        // Type de prestation
        $typePrestationId = $request->request->get('typePrestation');
        if ($typePrestationId) {
            $typePrestation = $this->em->getRepository(TypePrestation::class)->find($typePrestationId);
            $bon->setTypePrestation($typePrestation);
            
            if ($typePrestation) {
                $bon->setNombrePrestationsNecessaires($typePrestation->getNombrePrestationsNecessaires());
            }
        }

        // Validation basique
        if (!$bon->getClientNom() || !$bon->getClientEmail() || !$bon->getClientTelephone()) {
            $this->addFlash('danger', 'Veuillez remplir tous les champs obligatoires');
            return $this->redirectToRoute($isNew ? 'admin_bon_commande_new' : 'admin_bon_commande_edit', 
                $isNew ? [] : ['id' => $bon->getId()]
            );
        }

        // Vérification unicité numéro de commande
        if ($isNew && $bon->getNumeroCommande()) {
            $existant = $this->repository->findOneBy(['numeroCommande' => $bon->getNumeroCommande()]);
            if ($existant) {
                $this->addFlash('danger', 'Ce numéro de commande existe déjà');
                return $this->redirectToRoute('admin_bon_commande_edit', ['id' => $existant->getId()]);
            }
        }

        $this->em->persist($bon);
        $this->em->flush();

        // ⭐ Mise à jour du statut via le service après la sauvegarde
        $this->prestationManager->updateBonDeCommande($bon);

        $this->addFlash('success', $isNew 
            ? 'Bon de commande créé avec succès' 
            : 'Bon de commande modifié avec succès'
        );

        return $this->redirectToRoute('admin_bon_commande_show', ['id' => $bon->getId()]);
    }

    // Dans ton PrestationController (ou crée-le si tu ne l'as pas)
    #[Route('/admin/prestation/new-for-bon/{bonId}', name: 'admin_prestation_new_for_bon')]
    public function newForBon(int $bonId): Response
    {
        $bon = $this->em->getRepository(BonDeCommande::class)->find($bonId);
        
        if (!$bon) {
            throw $this->createNotFoundException('Bon de commande introuvable');
        }
        
        // Créer une nouvelle prestation liée à ce bon
        $prestation = new Prestation();
        $prestation->setBonDeCommande($bon);
        
        // Rediriger vers ton formulaire de création de prestation
        // ou afficher un formulaire directement
    }

    #[Route('/import-ocr', name: 'admin_bon_commande_import_ocr', methods: ['POST'])]
    public function importViaOcr(Request $request): Response
    {
        if ($request->isMethod('POST') && $file = $request->files->get('photo')) {
            $tmpPath = sys_get_temp_dir() . '/' . uniqid('ocr_') . '.' . $file->guessExtension();
            $file->move(sys_get_temp_dir(), basename($tmpPath));

            $ocr = new TesseractOCR($tmpPath);
            $text = $ocr->lang('fra', 'eng')->psm(3)->run();

            unlink($tmpPath);

            // ---- Extraction basique ----
            preg_match('/Commande\s*(?:n[\s°º]*)?[:\-\s]*([A-Z0-9]+)/i', $text, $mNumero);
            preg_match('/éditée[, ]+le\s*([0-9\/]+)/i', $text, $mDate);

            $start = stripos($text, 'Travaux à réaliser');
            if ($start !== false) {
                $text = substr($text, $start);
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            $indexPrestation = null;
            foreach ($lines as $i => $line) {
                if (stripos($line, 'Prestation Parties Privatives') !== false) {
                    $indexPrestation = $i;
                    break;
                }
            }

            $complement = '';
            $adresse = '';
            $nomClient = '';
            $telephone = '';

            if ($indexPrestation !== null) {
                $complement = $lines[$indexPrestation + 1] ?? '';
                $adresseLigne1 = $lines[$indexPrestation + 2] ?? '';
                $adresseLigne2 = $lines[$indexPrestation + 3] ?? '';
                $adresse = trim($adresseLigne1 . "\n" . $adresseLigne2);

                foreach ($lines as $line) {
                    if (stripos($line, 'Logement Occupé') !== false) {
                        if (preg_match('/Logement\s+Occupé\s*:\s*(.*?)\s*-\s*Portable\s*:\s*([0-9]+)/i', $line, $m)) {
                            $nomClient = trim($m[1]);
                            $telephone = trim($m[2]);
                        } elseif (preg_match('/Logement\s+Occupé\s*:\s*(.*)/i', $line, $m)) {
                            $nomClient = trim($m[1]);
                        }
                        break;
                    }
                }
            }

            // 🔁 Redirection vers formulaire "nouveau bon" avec données pré-remplies
            return $this->redirectToRoute('admin_bon_commande_new', [
                'numeroCommande' => $mNumero[1] ?? '',
                'clientNom' => $nomClient,
                'clientAdresse' => $adresse,
                'clientTelephone' => $telephone,
                'clientComplementAdresse' => $complement,
            ]);
        }

        $this->addFlash('danger', 'Aucun fichier reçu');
        return $this->redirectToRoute('admin_bon_commande_index');
    }
}