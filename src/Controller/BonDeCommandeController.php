<?php

namespace App\Controller;

use App\Entity\BonDeCommande;
use App\Entity\TypePrestation;
use App\Enum\StatutBonDeCommande;
use App\Enum\StatutPrestation;
use App\Repository\BonDeCommandeRepository;
use App\Service\PrestationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use thiagoalessio\TesseractOCR\TesseractOCR;

#[Route('/admin/bon-commande')]
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

        // Tri prioritaire : urgents d'abord, puis deadline proche, puis date commande
        $qb->addOrderBy('b.statut', 'ASC') // à programmer < programmé < en cours < terminé
           ->addOrderBy('b.dateLimiteExecution', 'ASC') // deadline la plus proche d'abord
           ->addOrderBy('b.dateCommande', 'DESC');

        $bonDeCommandes = $qb->getQuery()->getResult();

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
    #[Route('/{id}', name: 'admin_bon_commande_show', methods: ['GET'])]
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
    // CRÉER UNE PRESTATION POUR UN BON
    // =====================================================
    #[Route('/{bonId}/nouvelle-prestation', name: 'admin_bon_commande_new_prestation', methods: ['GET'])]
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
                $textBrut = $this->extractTextFromPdf($tmpPath);
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

            // === PARSING REGEX COMMUN ===
            $text     = $this->fixOcrText($textBrut);
            $allLines = array_values(array_filter(array_map('trim', explode("\n", $text))));

            $numeroCommande  = '';
            $complement      = '';
            $adresse         = '';
            $nomClient       = '';
            $telephone       = '';
            $codePostalVille = '';
            $dateLimite      = '';

            // Recherche dans le texte complet (fonctionne même si tout est sur une ligne)
            if (preg_match('/Travaux\s+.+?pour\s+le\s+(\d{2}\/\d{2}\/\d{4})/isu', $text, $m)) {
                $parts      = explode('/', $m[1]);
                $dateLimite = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }

            if (preg_match('/Commande\s*n.?\s*([A-Z0-9]{4,})/iu', $text, $m)) {
                $numeroCommande = trim($m[1]);
                $firstChar      = $numeroCommande[0] ?? '';
                if (ctype_digit($firstChar)) {
                    $map = ['1' => 'I', '0' => 'O'];
                    if (isset($map[$firstChar])) {
                        $numeroCommande = $map[$firstChar] . substr($numeroCommande, 1);
                    }
                }
            }

            // Logement Occupé — cherche dans le texte complet
            if (preg_match('/Logement\s+Occup.+?\s*:\s*(?:MR?\s+(?:ET\s+MME\s+)?|MME?\s+|M\.\s+)?(.+?)\s*-\s*(?:Portable|T[eé]l)/isu', $text, $m)) {
                $nomClient = trim($m[1]);
            }
            // Prendre le portable sur la même ligne que "Logement Occupé" (= locataire, pas bailleur)
            if (preg_match('/Logement\s+Occup.+?(?:Portable|T[eé]l[eé]phone)\s*:\s*(\d[\d\s]{8,})/isu', $text, $m)) {
                $telephone = preg_replace('/\s+/', '', trim($m[1]));
            }
            // Fallback : dernier portable dans le texte (le locataire est toujours après le bailleur)
            if (!$telephone && preg_match_all('/Portable\s*:\s*(\d[\d\s]{8,})/iu', $text, $allMatches)) {
                $telephone = preg_replace('/\s+/', '', trim(end($allMatches[1])));
            }

            // Recherche après "Prestation Parties Privatives" pour adresse et complément
            $startIndex = 0;
            foreach ($allLines as $i => $line) {
                if (stripos($line, 'Prestation') !== false && stripos($line, 'Privatives') !== false) {
                    $startIndex = $i + 1;
                    break;
                }
            }

            $clientLines = array_slice($allLines, $startIndex);
            foreach ($clientLines as $line) {
                if (!$adresse && preg_match('/\d+\s+(RUE|AVENUE|AV|BOULEVARD|BD|ALL[EÉ]E|IMPASSE|CHEMIN|PLACE|ROUTE|PASSAGE|VOIE)\b/i', $line)) {
                    $adresse = trim($line);
                }
                if (!$complement && preg_match('/LOGEMENT\s*n.?\s*\d+/iu', $line)) {
                    $complement = trim($line);
                }
                if (!$codePostalVille && preg_match('/^\d{5}\s+[A-ZÉÈÊÀÂ\s-]+$/u', $line)) {
                    $codePostalVille = trim($line);
                }
            }

            if ($codePostalVille && $adresse) {
                $adresse = $adresse . "\n" . $codePostalVille;
            } elseif ($codePostalVille && !$adresse) {
                $adresse = $codePostalVille;
            }

            $detectedTypeId  = '';
            $typePrestations = $this->em->getRepository(TypePrestation::class)->findAll();
            foreach ($typePrestations as $tp) {
                $code = $tp->getCode();
                if ($code && stripos($text, $code) !== false) {
                    $detectedTypeId = $tp->getId();
                    break;
                }
            }

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
                    'data' => [
                        'numeroCommande'          => $numeroCommande,
                        'clientNom'               => $nomClient,
                        'clientAdresse'           => $adresse,
                        'clientTelephone'         => $telephone,
                        'clientComplementAdresse' => $complement,
                        'dateLimiteExecution'     => $dateLimite,
                        'typePrestation'          => $detectedTypeId,
                    ],
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
    // MÉTHODE PRIVÉE : EXTRACTION TEXTE DEPUIS UN PDF (pur PHP, sans library)
    // =====================================================
    private function extractTextFromPdf(string $pdfPath): string
    {
        $raw = @file_get_contents($pdfPath);
        if ($raw === false) {
            return '';
        }
        return $this->parsePdfText($raw);
    }

    private function parsePdfText(string $raw): string
    {
        $text = '';

        // Extraire tous les content streams (compressés ou non)
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);

        foreach ($streams[1] as $stream) {
            // Tenter décompression zlib (FlateDecode)
            $decompressed = @gzuncompress($stream);
            if ($decompressed === false) {
                $decompressed = @gzinflate($stream);
            }
            $content = ($decompressed !== false) ? $decompressed : $stream;

            // Extraire le texte des opérateurs PDF (Tj, TJ, ')
            // Opérateur Tj : (texte)Tj
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $content, $tj);
            foreach ($tj[1] as $t) {
                $text .= $this->decodePdfString($t) . ' ';
            }

            // Opérateur TJ : [(texte) offset (texte)] TJ
            preg_match_all('/\[([^\]]*)\]\s*TJ/s', $content, $tjArr);
            foreach ($tjArr[1] as $t) {
                preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/s', $t, $parts);
                foreach ($parts[1] as $p) {
                    $text .= $this->decodePdfString($p);
                }
                $text .= ' ';
            }

            // Opérateur ' (idem Tj avec saut de ligne)
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*\'/s', $content, $tick);
            foreach ($tick[1] as $t) {
                $text .= $this->decodePdfString($t) . "\n";
            }

            // Opérateur ET (BT...ET blocs de texte) — ajouter saut de ligne entre blocs
            if (preg_match('/BT\b/', $content)) {
                $text .= "\n";
            }
        }

        // Nettoyer : espaces multiples, lignes vides
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function decodePdfString(string $s): string
    {
        // Décoder les séquences d'échappement PDF
        $s = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $s);
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', fn($m) => chr(octdec($m[1])), $s);
        $s = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s);
        return $s;
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
    // MÉTHODE PRIVÉE : CORRECTIONS OCR
    // =====================================================
    private function fixOcrText(string $text): string
    {
        // [ ou ] confondu avec 1 en début de ligne/mot (ex: "[ RUE" → "1 RUE")
        $text = preg_replace('/\[\s+(?=[A-ZÉÈÊÀÂ])/u', '1 ', $text);
        $text = preg_replace('/\]\s+(?=[A-ZÉÈÊÀÂ])/u', '1 ', $text);

        // [ ou ] collé à un mot (ex: "[RUE" → "1 RUE")
        $text = preg_replace('/\[(?=[A-ZÉÈÊÀÂ])/u', '1', $text);
        $text = preg_replace('/\](?=[A-ZÉÈÊÀÂ])/u', '1', $text);

        // | confondu avec I (lettre) devant un nom de rue
        $text = preg_replace('/\|\s+(?=RUE|AVENUE|BOULEVARD|BLVD|ALLEE|ALLÉE|IMPASSE|CHEMIN|PLACE|ROUTE|PASSAGE)\b/i', 'I ', $text);

        // O confondu avec 0 dans les numéros de téléphone (séquences de chiffres)
        $text = preg_replace_callback('/\b([\d]{2}[\s.]?){4}[\d]{2}\b/', function ($m) {
            return str_replace(['O', 'o'], '0', $m[0]);
        }, $text);

        return $text;
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