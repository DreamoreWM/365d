<?php

namespace App\Controller;

use App\Entity\BonDeCommande;
use App\Entity\Relance;
use App\Entity\TypePrestation;
use App\Enum\StatutBonDeCommande;
use App\Enum\StatutPrestation;
use App\Repository\BonDeCommandeRepository;
use App\Repository\PdfImportConfigRepository;
use App\Service\PdfConfigExtractor;
use App\Service\PdfTextExtractor;
use App\Service\PrestationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use thiagoalessio\TesseractOCR\TesseractOCR;

#[Route('/admin/bon-commande')]
class BonDeCommandeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private BonDeCommandeRepository $repository,
        private PrestationManager $prestationManager,
        private PdfTextExtractor $pdfTextExtractor,
        private PdfConfigExtractor $pdfConfigExtractor,
        private PdfImportConfigRepository $pdfConfigRepository,
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
        $tab = $request->query->get('tab', 'tous');

        $qb = $this->repository->createQueryBuilder('b')
            ->leftJoin('b.prestations', 'p')
            ->addSelect('p');

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

        // Filtre par onglet
        if ($tab === 'urgents') {
            // Urgents = bons "à programmer" uniquement (pas terminés, pas déjà programmés)
            // avec une prestation non effectuée OU une deadline proche
            $qb->andWhere('b.statut = :aProgrammer')
               ->andWhere(
                $qb->expr()->orX(
                    'p.statut = :nonEffectue',
                    '(b.dateLimiteExecution IS NOT NULL AND b.dateLimiteExecution <= :deadlineProche)'
                )
            )
            ->setParameter('nonEffectue', StatutPrestation::NON_EFFECTUE)
            ->setParameter('deadlineProche', new \DateTimeImmutable('+7 days'))
            ->setParameter('aProgrammer', StatutBonDeCommande::A_PROGRAMMER);
        } elseif ($tab === 'a_programmer') {
            $qb->andWhere('b.statut = :statutTab')
               ->setParameter('statutTab', StatutBonDeCommande::A_PROGRAMMER);
        } elseif ($tab === 'en_cours') {
            $qb->andWhere('b.statut IN (:statutsTab)')
               ->setParameter('statutsTab', [StatutBonDeCommande::PROGRAMME, StatutBonDeCommande::EN_COURS]);
        } elseif ($tab === 'termines') {
            $qb->andWhere('b.statut = :statutTab')
               ->setParameter('statutTab', StatutBonDeCommande::TERMINE);
        }

        // Pré-tri SQL : rough ordering avant le tri PHP métier
        $qb->addOrderBy('b.dateLimiteExecution', 'ASC')
           ->addOrderBy('b.dateCommande', 'DESC');

        $bonDeCommandes = $qb->getQuery()->getResult();

        // Tri PHP : cooldown et réactivité client en priorité
        $statutOrder = ['à programmer' => 0, 'programmé' => 1, 'en cours' => 2, 'terminé' => 3];
        usort($bonDeCommandes, static function (BonDeCommande $a, BonDeCommande $b) use ($statutOrder): int {
            // 1. Bons NON en cooldown avant ceux en cooldown
            $cmp = (int) $a->estEnCooldown() - (int) $b->estEnCooldown();
            if ($cmp !== 0) return $cmp;

            // 2. Clients réactifs avant clients peu réactifs
            $cmp = (int) $a->estPeuReactif() - (int) $b->estPeuReactif();
            if ($cmp !== 0) return $cmp;

            // 3. Statut (à programmer d'abord)
            $cmp = ($statutOrder[$a->getStatut()->value] ?? 99) - ($statutOrder[$b->getStatut()->value] ?? 99);
            if ($cmp !== 0) return $cmp;

            // 4. Deadline la plus proche d'abord (null en dernier)
            $aDate = $a->getDateLimiteExecution();
            $bDate = $b->getDateLimiteExecution();
            if ($aDate && $bDate) return $aDate <=> $bDate;
            if ($aDate) return -1;
            if ($bDate) return 1;

            // 5. Commande la plus récente d'abord
            return $b->getDateCommande() <=> $a->getDateCommande();
        });

        // Compter les bons par statut en une seule requête GROUP BY
        $rows = $this->repository->createQueryBuilder('bc')
            ->select('bc.statut AS statut, COUNT(bc.id) AS cnt')
            ->groupBy('bc.statut')
            ->getQuery()
            ->getResult();

        $byStatut = [];
        foreach ($rows as $row) {
            $key = $row['statut'] instanceof \BackedEnum ? $row['statut']->value : (string) $row['statut'];
            $byStatut[$key] = (int) $row['cnt'];
        }

        $tousCount       = array_sum($byStatut);
        $aProgrammerCount = $byStatut[StatutBonDeCommande::A_PROGRAMMER->value] ?? 0;
        $enCoursCount    = ($byStatut[StatutBonDeCommande::PROGRAMME->value] ?? 0)
                         + ($byStatut[StatutBonDeCommande::EN_COURS->value] ?? 0);
        $terminesCount   = $byStatut[StatutBonDeCommande::TERMINE->value] ?? 0;

        // Urgents = bons "à programmer" avec deadline dans les 7 prochains jours
        $urgentsCount = (int) $this->repository->createQueryBuilder('b2')
            ->select('COUNT(b2.id)')
            ->where('b2.statut = :ap')
            ->andWhere('b2.dateLimiteExecution IS NOT NULL')
            ->andWhere('b2.dateLimiteExecution <= :deadline')
            ->setParameter('ap', StatutBonDeCommande::A_PROGRAMMER)
            ->setParameter('deadline', new \DateTimeImmutable('+7 days'))
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('admin/bon_commande/index.html.twig', [
            'bonDeCommandes' => $bonDeCommandes,
            'currentTab' => $tab,
            'urgentsCount' => (int) $urgentsCount,
            'tousCount' => (int) $tousCount,
            'aProgrammerCount' => (int) $aProgrammerCount,
            'enCoursCount' => (int) $enCoursCount,
            'terminesCount' => (int) $terminesCount,
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
            $dateLimite = $request->query->get('dateLimiteExecution', '');
            if ($dateLimite) {
                $bon->setDateLimiteExecution(new \DateTimeImmutable($dateLimite));
            }
            $typePrestationId = $request->query->get('typePrestation', '');
            if ($typePrestationId) {
                $typePrestation = $this->em->getRepository(TypePrestation::class)->find($typePrestationId);
                if ($typePrestation) {
                    $bon->setTypePrestation($typePrestation);
                }
            }
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
    #[Route('/{id}', name: 'admin_bon_commande_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(BonDeCommande $bon): Response
    {
        // Mise à jour du statut avant affichage
        $this->prestationManager->updateBonDeCommande($bon);
        
        // Vérifier la dernière prestation (la plus récente)
        $derniereNonEffectuee = false;
        $dernierePrestation = null;
        
        $prestations = $bon->getPrestations()->toArray();
        if (count($prestations) > 0) {
            // Trier les prestations par date (la plus récente en dernier)
            usort($prestations, function($a, $b) {
                return $a->getDatePrestation() <=> $b->getDatePrestation();
            });
            
            // Prendre la dernière prestation (la plus récente)
            $dernierePrestation = end($prestations);
            
            // Vérifier si elle est non effectuée
            if ($dernierePrestation && $dernierePrestation->getStatut() === StatutPrestation::NON_EFFECTUE) {
                $derniereNonEffectuee = true;
            }
        }

        return $this->render('admin/bon_commande/show.html.twig', [
            'bon' => $bon,
            'derniereNonEffectuee' => $derniereNonEffectuee,
            'dernierePrestation' => $dernierePrestation,
        ]);
    }

    // =====================================================
    // MODIFIER UN BON
    // =====================================================
    #[Route('/{id}/edit', name: 'admin_bon_commande_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
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
    // CRÉER UNE PRESTATION POUR UN BON
    // =====================================================
    #[Route('/{bonId}/nouvelle-prestation', name: 'admin_bon_commande_new_prestation', methods: ['GET'], requirements: ['bonId' => '\d+'])]
    public function newForBon(int $bonId): Response
    {
        $bon = $this->em->getRepository(BonDeCommande::class)->find($bonId);
        
        if (!$bon) {
            throw $this->createNotFoundException('Bon de commande introuvable');
        }
        
        // Rediriger vers le planning avec le bon pré-sélectionné
        return $this->redirectToRoute('admin_planning_index', ['bonId' => $bonId]);
    }

    // =====================================================
    // RÉOUVRIR UN BON TERMINÉ
    // =====================================================
    #[Route('/{id}/reopen', name: 'admin_bon_commande_reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reopen(Request $request, BonDeCommande $bon): Response
    {
        if (!$this->isCsrfTokenValid('reopen' . $bon->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('admin_bon_commande_show', ['id' => $bon->getId()]);
        }

        if ($bon->getStatut() !== StatutBonDeCommande::TERMINE) {
            $this->addFlash('warning', 'Ce bon de commande n\'est pas terminé');
            return $this->redirectToRoute('admin_bon_commande_show', ['id' => $bon->getId()]);
        }

        $prestationsSupplementaires = max(1, (int) $request->request->get('prestations_supplementaires', 1));

        $bon->setNombrePrestationsNecessaires(
            $bon->getNombrePrestationsNecessaires() + $prestationsSupplementaires
        );
        $bon->setStatut(StatutBonDeCommande::A_PROGRAMMER);

        $this->em->persist($bon);
        $this->em->flush();

        $this->addFlash('success', "Le bon de commande a été réouvert. {$prestationsSupplementaires} prestation(s) supplémentaire(s) requise(s).");

        return $this->redirectToRoute('admin_bon_commande_show', ['id' => $bon->getId()]);
    }

    // =====================================================
    // RELANCER UN BON
    // =====================================================
    #[Route('/{id}/relancer', name: 'admin_bon_commande_relancer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function relancer(Request $request, BonDeCommande $bon): Response
    {
        if (!$this->isCsrfTokenValid('relancer' . $bon->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('admin_bon_commande_show', ['id' => $bon->getId()]);
        }

        if ($bon->estEnCooldown()) {
            $this->addFlash('warning', 'Une relance a déjà été enregistrée récemment pour ce bon. Attendez avant de relancer.');
            return $this->redirectToRoute('admin_bon_commande_show', ['id' => $bon->getId()]);
        }

        $relance = new Relance();
        $relance->setBonDeCommande($bon);
        $relance->setNote($request->request->get('note') ?: null);
        $relance->setAuteur($this->getUser());

        $bon->enregistrerRelance();

        $this->em->persist($relance);
        $this->em->flush();

        $this->addFlash('success', 'Relance enregistrée. Ce bon sera remis en tête de liste dans 24h.');
        return $this->redirectToRoute('admin_bon_commande_show', ['id' => $bon->getId()]);
    }

    // =====================================================
    // SUPPRIMER UN BON
    // =====================================================
    #[Route('/{id}/delete', name: 'admin_bon_commande_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
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
    // IMPORT OCR (PDF Parser pour les PDFs, Tesseract pour les images)
    // =====================================================
    #[Route('/import-ocr', name: 'admin_bon_commande_import_ocr', methods: ['POST'])]
    public function importViaOcr(Request $request): Response
    {
        if ($request->isMethod('POST') && $file = $request->files->get('photo')) {
            $ext          = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? ''));
            $originalName = $file->getClientOriginalName();
            $fileSize     = $file->getSize(); // avant move() car SplFileInfo::getSize() lève une exception après
            $tmpPath      = sys_get_temp_dir() . '/' . uniqid('ocr_') . '.' . ($ext ?: 'bin');
            $file->move(sys_get_temp_dir(), basename($tmpPath));

            $logPath = $this->getParameter('kernel.project_dir') . '/var/ocr_debug.log';

            // Log immédiat pour confirmer la réception du fichier
            file_put_contents($logPath,
                "=== UPLOAD REÇU " . date('Y-m-d H:i:s') . " ===\n" .
                "Nom: " . $originalName . "\n" .
                "Extension détectée: $ext\n" .
                "Taille: " . $fileSize . " octets\n\n",
                FILE_APPEND
            );

            // === EXTRACTION DU TEXTE ===
            if ($ext === 'pdf') {
                $textBrut = $this->pdfTextExtractor->extractRaw($tmpPath);
                $engine   = 'PDF Parser';
            } else {
                $processedPath = $this->preprocessImageForOcr($tmpPath);
                $textBrut = (new TesseractOCR($processedPath))->lang('fra', 'eng')->psm(3)->run();
                if ($processedPath !== $tmpPath) {
                    unlink($processedPath);
                }
                $engine = 'Tesseract';
            }
            unlink($tmpPath);

            // === PARSING ET EXTRACTION ===
            $text     = $this->pdfTextExtractor->fixOcrText($textBrut);
            $allLines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            $data     = $this->extractOcrFields($ext, $text, $allLines);

            $numeroCommande = $data['numeroCommande'];
            $nomClient      = $data['clientNom'];
            $adresse        = $data['clientAdresse'];
            $telephone      = $data['clientTelephone'];
            $complement     = $data['clientComplementAdresse'];
            $dateLimite     = $data['dateLimiteExecution'];
            $detectedTypeId = $data['typePrestation'];

            // Log
            $log  = "=== $engine OCR " . date('Y-m-d H:i:s') . " ===\n";
            $log .= "Fichier: " . $originalName . "\n";
            $log .= "\n--- TEXTE EXTRAIT ---\n" . $textBrut . "\n";
            $log .= "\n--- DONNÉES EXTRAITES ---\n";
            $log .= "N° Commande: " . ($numeroCommande ?: 'NON TROUVÉ') . "\n";
            $log .= "Nom client: "  . ($nomClient      ?: 'NON TROUVÉ') . "\n";
            $log .= "Téléphone: "   . ($telephone      ?: 'NON TROUVÉ') . "\n";
            $log .= "Adresse: "     . ($adresse        ?: 'NON TROUVÉ') . "\n";
            $log .= "Complément: "  . ($complement     ?: 'NON TROUVÉ') . "\n";
            $log .= "Date limite: " . ($dateLimite     ?: 'NON TROUVÉ') . "\n\n";
            file_put_contents($logPath, $log, FILE_APPEND);

            // === RÉPONSE ===
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return new JsonResponse([
                    'success' => true,
                    'redirectUrl' => $this->generateUrl('admin_bon_commande_new', [
                        'numeroCommande'          => $numeroCommande,
                        'clientNom'               => $nomClient,
                        'clientAdresse'           => $adresse,
                        'clientTelephone'         => $telephone,
                        'clientComplementAdresse' => $complement,
                        'dateLimiteExecution'     => $dateLimite,
                        'typePrestation'          => $detectedTypeId,
                    ]),
                    'data' => $data,
                ]);
            }

            return $this->redirectToRoute('admin_bon_commande_new', [
                'numeroCommande'          => $numeroCommande,
                'clientNom'               => $nomClient,
                'clientAdresse'           => $adresse,
                'clientTelephone'         => $telephone,
                'clientComplementAdresse' => $complement,
                'dateLimiteExecution'     => $dateLimite,
                'typePrestation'          => $detectedTypeId,
            ]);
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'error' => 'Aucun fichier reçu'], 400);
        }
        $this->addFlash('danger', 'Aucun fichier reçu');
        return $this->redirectToRoute('admin_bon_commande_index');
    }

    // =====================================================
    // IMPORT OCR MULTI (Plusieurs fichiers)
    // =====================================================
    #[Route('/import-ocr-multi', name: 'admin_bon_commande_import_ocr_multi', methods: ['POST'])]
    public function importViaOcrMulti(Request $request): JsonResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $files = $request->files->get('photos') ?? [];
        if (empty($files)) {
            return new JsonResponse(['success' => false, 'error' => 'Aucun fichier reçu'], 400);
        }
        if (!is_array($files)) {
            $files = [$files];
        }

        $logPath = $this->getParameter('kernel.project_dir') . '/var/ocr_debug.log';
        $results = [];

        foreach ($files as $file) {
            $ext          = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? ''));
            $originalName = $file->getClientOriginalName();
            $fileSize     = $file->getSize();
            $token        = 'ocr_preview_' . uniqid('', true);
            $tmpName      = $token . '.' . ($ext ?: 'bin');
            $tmpDir       = sys_get_temp_dir();
            $file->move($tmpDir, $tmpName);
            $tmpPath = $tmpDir . '/' . $tmpName;

            file_put_contents($logPath,
                "=== MULTI UPLOAD " . date('Y-m-d H:i:s') . " ===\n" .
                "Nom: $originalName\nExtension: $ext\nTaille: $fileSize octets\n\n",
                FILE_APPEND
            );

            try {
                if ($ext === 'pdf') {
                    $textBrut = $this->pdfTextExtractor->extractRaw($tmpPath);
                    $engine   = 'PDF Parser';
                } else {
                    $processedPath = $this->preprocessImageForOcr($tmpPath);
                    $textBrut      = (new TesseractOCR($processedPath))->lang('fra', 'eng')->psm(3)->run();
                    if ($processedPath !== $tmpPath) {
                        @unlink($processedPath);
                    }
                    $engine = 'Tesseract';
                }

                $text     = $this->pdfTextExtractor->fixOcrText($textBrut);
                $allLines = array_values(array_filter(array_map('trim', explode("\n", $text))));
                $data     = $this->extractOcrFields($ext, $text, $allLines);

                $log  = "=== $engine OCR MULTI " . date('Y-m-d H:i:s') . " ===\n";
                $log .= "Fichier: $originalName\n";
                $log .= "N° Commande: " . ($data['numeroCommande'] ?: 'NON TROUVÉ') . "\n";
                $log .= "Nom client: "  . ($data['clientNom']      ?: 'NON TROUVÉ') . "\n\n";
                file_put_contents($logPath, $log, FILE_APPEND);

                $results[] = [
                    'token'    => $token,
                    'filename' => $originalName,
                    'ext'      => $ext,
                    'tmpPath'  => $tmpPath,
                    'data'     => $data,
                ];
            } catch (\Throwable $e) {
                file_put_contents($logPath,
                    "=== ERREUR FICHIER $originalName " . date('Y-m-d H:i:s') . " ===\n" . $e->getMessage() . "\n\n",
                    FILE_APPEND
                );
                $results[] = [
                    'token'    => $token,
                    'filename' => $originalName,
                    'ext'      => $ext,
                    'tmpPath'  => $tmpPath,
                    'data'     => [],
                    'error'    => 'Erreur lors du traitement : ' . $e->getMessage(),
                ];
            } finally {
                $this->em->clear();
                gc_collect_cycles();
            }
        }

        $request->getSession()->set('ocr_import_results', $results);

        return new JsonResponse([
            'success'     => true,
            'redirectUrl' => $this->generateUrl('admin_bon_commande_ocr_review'),
            'count'       => count($results),
        ]);
    }

    // =====================================================
    // PAGE DE RÉVISION OCR MULTI
    // =====================================================
    #[Route('/ocr-review', name: 'admin_bon_commande_ocr_review', methods: ['GET'])]
    public function ocrReview(Request $request): Response
    {
        $results = $request->getSession()->get('ocr_import_results', []);

        if (empty($results)) {
            $this->addFlash('warning', 'Aucun résultat OCR à réviser');
            return $this->redirectToRoute('admin_bon_commande_index');
        }

        $typePrestations = $this->em->getRepository(TypePrestation::class)->findAll();

        return $this->render('admin/bon_commande/ocr_review.html.twig', [
            'results'         => $results,
            'typePrestations' => $typePrestations,
        ]);
    }

    // =====================================================
    // SERVIR LE FICHIER TEMP POUR PRÉVISUALISATION OCR
    // =====================================================
    #[Route('/preview-ocr/{token}', name: 'admin_bon_commande_preview_ocr', methods: ['GET'])]
    public function previewOcrFile(string $token): Response
    {
        if (!preg_match('/^ocr_preview_[a-zA-Z0-9.]+$/', $token)) {
            throw $this->createNotFoundException('Token invalide');
        }

        $files = glob(sys_get_temp_dir() . '/' . $token . '.*');
        if (empty($files)) {
            throw $this->createNotFoundException('Fichier introuvable ou expiré');
        }

        $filePath    = $files[0];
        $ext         = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'pdf'        => 'application/pdf',
            'png'        => 'image/png',
            'jpg','jpeg' => 'image/jpeg',
            'webp'       => 'image/webp',
            default      => 'application/octet-stream',
        };

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $contentType);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        return $response;
    }

    // =====================================================
    // CRÉER UN BON DEPUIS L'INTERFACE DE RÉVISION OCR
    // =====================================================
    #[Route('/create-from-ocr', name: 'admin_bon_commande_create_from_ocr', methods: ['POST'])]
    public function createFromOcr(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        $clientNom       = trim($data['clientNom'] ?? '');
        $clientTelephone = trim($data['clientTelephone'] ?? '');

        if (!$clientNom || !$clientTelephone) {
            return new JsonResponse(['success' => false, 'error' => 'Nom et téléphone obligatoires'], 400);
        }

        $bon = new BonDeCommande();
        $bon->setNumeroCommande($data['numeroCommande'] ?? null);
        $bon->setClientNom($clientNom);
        $bon->setClientTelephone($clientTelephone);
        $bon->setClientAdresse($data['clientAdresse'] ?? '');
        $bon->setClientComplementAdresse($data['clientComplementAdresse'] ?? '');
        $bon->setClientEmail(!empty($data['clientEmail']) ? $data['clientEmail'] : null);

        $dateLimite = $data['dateLimiteExecution'] ?? '';
        if ($dateLimite) {
            try {
                $bon->setDateLimiteExecution(new \DateTimeImmutable($dateLimite));
            } catch (\Exception) {}
        }

        $typePrestationId = $data['typePrestation'] ?? '';
        if ($typePrestationId) {
            $typePrestation = $this->em->getRepository(TypePrestation::class)->find($typePrestationId);
            if ($typePrestation) {
                $bon->setTypePrestation($typePrestation);
                $bon->setNombrePrestationsNecessaires($typePrestation->getNombrePrestationsNecessaires());
            }
        }

        if ($bon->getNumeroCommande()) {
            $existant = $this->repository->findOneBy(['numeroCommande' => $bon->getNumeroCommande()]);
            if ($existant) {
                return new JsonResponse([
                    'success'     => false,
                    'error'       => 'Ce numéro de commande existe déjà',
                    'existingId'  => $existant->getId(),
                    'existingUrl' => $this->generateUrl('admin_bon_commande_show', ['id' => $existant->getId()]),
                ], 409);
            }
        }

        $this->em->persist($bon);
        $this->em->flush();
        $this->prestationManager->updateBonDeCommande($bon);

        return new JsonResponse([
            'success' => true,
            'id'      => $bon->getId(),
            'url'     => $this->generateUrl('admin_bon_commande_show', ['id' => $bon->getId()]),
        ]);
    }

    // =====================================================
    // MÉTHODE PRIVÉE : EXTRACTION DES CHAMPS OCR
    // =====================================================
    private function extractOcrFields(string $ext, string $text, array $allLines): array
    {
        $numeroCommande  = '';
        $complement      = '';
        $adresse         = '';
        $nomClient       = '';
        $telephone       = '';
        $codePostalVille = '';
        $dateLimite      = '';
        $detectedTypeId  = '';

        if ($ext === 'pdf') {
            $pdfConfigs = $this->pdfConfigRepository->findActifsByPriorite();
            foreach ($pdfConfigs as $pdfConfig) {
                if ($pdfConfig->getIdentifiantTexte() && stripos($text, $pdfConfig->getIdentifiantTexte()) !== false) {
                    $extracted       = $this->pdfConfigExtractor->extract($allLines, $text, $pdfConfig);
                    $numeroCommande  = $extracted['numeroCommande']          ?? $numeroCommande;
                    $nomClient       = $extracted['clientNom']               ?? $nomClient;
                    $telephone       = $extracted['clientTelephone']         ?? $telephone;
                    $adresse         = $extracted['clientAdresse']           ?? $adresse;
                    $complement      = $extracted['clientComplementAdresse'] ?? $complement;
                    $dateLimite      = $extracted['dateLimiteExecution']     ?? $dateLimite;
                    $detectedTypeId  = $extracted['typePrestation']          ?? $detectedTypeId;
                    break;
                }
            }
        }

        if (!$dateLimite && preg_match('/Travaux\s+.+?pour\s+le\s+(\d{2}\/\d{2}\/\d{4})/isu', $text, $m)) {
            $parts      = explode('/', $m[1]);
            $dateLimite = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }

        // ICF Habitat: "COMMANDE N° RNE 15/745676/E" (multi-word number with space)
        if (!$numeroCommande && preg_match('/Commande\s+N.?\s+([A-Z]{2,}\s+\S+)/iu', $text, $m)) {
            $numeroCommande = trim($m[1]);
        }

        // Supports Partenord format (H95598) and Vilogia format (LOG/N38872)
        if (!$numeroCommande && preg_match('/Commande\s*n.?\s*:?\s*([A-Z0-9][A-Z0-9\/]{3,})/iu', $text, $m)) {
            $numeroCommande = trim($m[1]);
            $firstChar      = $numeroCommande[0] ?? '';
            if (ctype_digit($firstChar)) {
                $map = ['1' => 'I', '0' => 'O'];
                if (isset($map[$firstChar])) {
                    $numeroCommande = $map[$firstChar] . substr($numeroCommande, 1);
                }
            }
        }

        // Partenord: "Logement Occupé : MME DUPONT Marie - Portable : ..."
        if (!$nomClient && preg_match('/Logement\s+Occup.+?\s*:\s*(?:MR?\s+(?:ET\s+MME\s+)?|MME?\s+|M\.\s+)?(.+?)\s*-\s*(?:Portable|T[eé]l)/isu', $text, $m)) {
            $nomClient = trim($m[1]);
        }

        // Vilogia: "Occupant actuel : M. MERIMI MOHAMMED 211051/76"
        if (!$nomClient && preg_match('/Occupant\s+actuel\s*:\s*(?:M\.?\s*|MME?\.?\s+|MR?\s+)?(.+?)(?:\s+\d{5,}\/\d+)?\s*$/im', $text, $m)) {
            $nomClient = trim($m[1]);
        }

        // Partenord phone from "Logement Occupé" line
        if (!$telephone && preg_match('/Logement\s+Occup.+?(?:Portable|T[eé]l[eé]phone)\s*:\s*(\d[\d\s]{8,})/isu', $text, $m)) {
            $telephone = preg_replace('/\s+/', '', trim($m[1]));
        }

        // Vilogia phone: "domicile : bureau : portable : 0695348928" near "Occupant actuel"
        if (!$telephone && preg_match('/Occupant\s+actuel.+?portable\s*:\s*(\d[\d\s]{8,})/isu', $text, $m)) {
            $telephone = preg_replace('/\s+/', '', trim($m[1]));
        }

        if (!$telephone && preg_match_all('/Portable\s*:\s*(\d[\d\s]{8,})/iu', $text, $allMatches)) {
            $telephone = preg_replace('/\s+/', '', trim(end($allMatches[1])));
        }

        if (!$adresse && !$complement) {
            $startIndex = 0;
            foreach ($allLines as $i => $line) {
                if (stripos($line, 'Prestation') !== false && stripos($line, 'Privatives') !== false) {
                    $startIndex = $i + 1;
                    break;
                }
            }
            foreach (array_slice($allLines, $startIndex) as $line) {
                if (!$adresse && preg_match('/\d+\s+(RUE|AVENUE|AV|BOULEVARD|BD|ALL[EÉ]E|IMPASSE|CHEMIN|PLACE|ROUTE|PASSAGE|VOIE)\b/i', $line)) {
                    $adresse = trim($line);
                }
                if (!$complement && preg_match('/LOGEMENT\s*n.?\s*\d+/iu', $line)) {
                    $complement = trim($line);
                }
                if (!$codePostalVille && preg_match('/^\d{5}\s+[A-ZÉÈÊÀÂ\s-]+$/u', $line)) {
                    $codePostalVille = trim($line);
                }
                // ICF Habitat reversed format: "LILLE 59800"
                if (!$codePostalVille && preg_match('/^([A-ZÉÈÊÀÂ][A-ZÉÈÊÀÂ\s-]{2,})\s+(\d{5})$/u', $line, $cp)) {
                    $codePostalVille = trim($cp[2]) . ' ' . trim($cp[1]);
                }
            }
            if ($codePostalVille && $adresse) {
                $adresse = $adresse . "\n" . $codePostalVille;
            } elseif ($codePostalVille) {
                $adresse = $codePostalVille;
            }
        }

        if (!$detectedTypeId) {
            foreach ($this->em->getRepository(TypePrestation::class)->findAll() as $tp) {
                foreach ($tp->getAllCodesOcr() as $code) {
                    if (stripos($text, $code) !== false) {
                        $detectedTypeId = $tp->getId();
                        break 2;
                    }
                }
            }
        }

        return [
            'numeroCommande'          => $numeroCommande,
            'clientNom'               => $nomClient,
            'clientAdresse'           => $adresse,
            'clientTelephone'         => $telephone,
            'clientComplementAdresse' => $complement,
            'dateLimiteExecution'     => $dateLimite,
            'typePrestation'          => $detectedTypeId,
        ];
    }

    // =====================================================
    // MÉTHODE PRIVÉE : PREPROCESSING IMAGE POUR OCR
    // =====================================================
    private function preprocessImageForOcr(string $imagePath): string
    {
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        // Charger l'image
        $image = match ($ext) {
            'png' => @imagecreatefrompng($imagePath),
            'jpg', 'jpeg' => @imagecreatefromjpeg($imagePath),
            'webp' => @imagecreatefromwebp($imagePath),
            'bmp' => @imagecreatefrombmp($imagePath),
            default => false,
        };

        if (!$image) {
            return $imagePath;
        }

        // 1. Corriger l'orientation EXIF (photos téléphone)
        if (in_array($ext, ['jpg', 'jpeg'])) {
            $exif = @exif_read_data($imagePath);
            if ($exif && isset($exif['Orientation'])) {
                $image = match ((int)$exif['Orientation']) {
                    3 => imagerotate($image, 180, 0),
                    6 => imagerotate($image, -90, 0),
                    8 => imagerotate($image, 90, 0),
                    default => $image,
                };
            }
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // 2. Upscale si image trop petite (Tesseract optimal avec des images larges)
        if ($width < 2000) {
            $scale = 2000 / $width;
            $newWidth = (int)($width * $scale);
            $newHeight = (int)($height * $scale);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        // 3. Niveaux de gris (aide Tesseract à se concentrer sur le texte)
        imagefilter($image, IMG_FILTER_GRAYSCALE);

        // Sauvegarder en PNG (sans perte)
        $outputPath = sys_get_temp_dir() . '/' . uniqid('ocr_processed_') . '.png';
        imagepng($image, $outputPath, 0);
        imagedestroy($image);

        return $outputPath;
    }

    // =====================================================
    // MÉTHODE PRIVÉE : TRAITEMENT DU FORMULAIRE
    // =====================================================
    private function handleForm(Request $request, BonDeCommande $bon, bool $isNew): Response
    {
        $bon->setNumeroCommande($request->request->get('numeroCommande'));
        $bon->setClientNom($request->request->get('clientNom'));
        $bon->setClientEmail($request->request->get('clientEmail') ?: null);
        $bon->setClientTelephone($request->request->get('clientTelephone'));
        $bon->setClientAdresse($request->request->get('clientAdresse'));
        $bon->setClientComplementAdresse($request->request->get('clientComplementAdresse'));

        // Date limite d'exécution
        $dateLimite = $request->request->get('dateLimiteExecution');
        if ($dateLimite) {
            $bon->setDateLimiteExecution(new \DateTimeImmutable($dateLimite));
        } else {
            $bon->setDateLimiteExecution(null);
        }

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
        if (!$bon->getClientNom() || !$bon->getClientTelephone()) {
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
}