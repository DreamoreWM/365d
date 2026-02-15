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

        // Compter les urgents pour le badge (seulement les bons "à programmer")
        $urgentsCount = $this->repository->createQueryBuilder('b2')
            ->select('COUNT(DISTINCT b2.id)')
            ->leftJoin('b2.prestations', 'p2')
            ->where('b2.statut = :aProgrammer2')
            ->andWhere(
                'p2.statut = :nonEffectue2 OR (b2.dateLimiteExecution IS NOT NULL AND b2.dateLimiteExecution <= :deadlineProche2)'
            )
            ->setParameter('nonEffectue2', StatutPrestation::NON_EFFECTUE)
            ->setParameter('deadlineProche2', new \DateTimeImmutable('+7 days'))
            ->setParameter('aProgrammer2', StatutBonDeCommande::A_PROGRAMMER)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('admin/bon_commande/index.html.twig', [
            'bonDeCommandes' => $bonDeCommandes,
            'currentTab' => $tab,
            'urgentsCount' => (int) $urgentsCount,
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
    // IMPORT OCR
    // =====================================================
    #[Route('/import-ocr', name: 'admin_bon_commande_import_ocr', methods: ['POST'])]
    public function importViaOcr(Request $request): Response
    {
        if ($request->isMethod('POST') && $file = $request->files->get('photo')) {
            $tmpPath = sys_get_temp_dir() . '/' . uniqid('ocr_') . '.' . $file->guessExtension();
            $file->move(sys_get_temp_dir(), basename($tmpPath));

            // Preprocessing pour améliorer la reconnaissance OCR
            $processedPath = $this->preprocessImageForOcr($tmpPath);

            $ocr = new TesseractOCR($processedPath);
            $text = $ocr->lang('fra', 'eng')->psm(3)->run();

            unlink($tmpPath);
            if ($processedPath !== $tmpPath) {
                unlink($processedPath);
            }

            $textBrut = $text; // Texte brut avant corrections

            // ---- Corrections OCR courantes ----
            $text = $this->fixOcrText($text);

            $allLines = array_values(array_filter(array_map('trim', explode("\n", $text))));

            $numeroCommande = '';
            $complement = '';
            $adresse = '';
            $nomClient = '';
            $telephone = '';
            $codePostalVille = '';
            $dateLimite = '';

            // 0. Date limite : "Travaux à réaliser pour le DD/MM/YYYY"
            foreach ($allLines as $line) {
                if (preg_match('/Travaux\s+.+\s+pour\s+le\s+(\d{2}\/\d{2}\/\d{4})/iu', $line, $m)) {
                    $parts = explode('/', $m[1]);
                    $dateLimite = $parts[2] . '-' . $parts[1] . '-' . $parts[0]; // YYYY-MM-DD
                    break;
                }
            }

            // 1. N° Commande : chercher dans TOUT le texte "Commande n°XXXXX"
            // Capture alphanumérique car le 1er caractère peut être une lettre (ex: I26280)
            foreach ($allLines as $line) {
                if (preg_match('/Commande\s*n.?\s*([A-Z0-9]{4,})/iu', $line, $m)) {
                    $numeroCommande = trim($m[1]);
                    // OCR confond souvent I→1, O→0 : le 1er caractère est toujours une lettre (Partenord)
                    $firstChar = $numeroCommande[0] ?? '';
                    if (ctype_digit($firstChar)) {
                        $map = ['1' => 'I', '0' => 'O'];
                        if (isset($map[$firstChar])) {
                            $numeroCommande = $map[$firstChar] . substr($numeroCommande, 1);
                        }
                    }
                    break;
                }
            }

            // 2. Trouver la section "Prestation Parties Privatives" - les infos client sont APRÈS
            $startIndex = 0;
            foreach ($allLines as $i => $line) {
                if (stripos($line, 'Prestation') !== false && stripos($line, 'Privatives') !== false) {
                    $startIndex = $i + 1;
                    break;
                }
            }

            // 3. Extraire les données uniquement APRÈS le marqueur
            $clientLines = array_slice($allLines, $startIndex);
            foreach ($clientLines as $line) {
                // Adresse : lettre ou numéro + type de voie
                if (!$adresse && preg_match('/[A-Z0-9]+\s+(RUE|R|AVENUE|AV|BOULEVARD|BLVD|BD|ALL[EÉ]E|IMPASSE|IMP|CHEMIN|CH|PLACE|PL|ROUTE|RTE|PASSAGE|VOIE)\b/i', $line)) {
                    $adresse = trim($line);
                }

                // Complément : ligne LOGEMENT n° avec étage/type
                if (!$complement && preg_match('/LOGEMENT\s*n.?\s*\d+/iu', $line)) {
                    $complement = trim($line);
                }

                // Code postal + ville (ex: "59120 LOOS")
                if (!$codePostalVille && preg_match('/^\d{5}\s+[A-ZÉÈÊÀÂ\s-]+$/u', $line)) {
                    $codePostalVille = trim($line);
                }

                // Nom : plusieurs formats possibles
                if (!$nomClient) {
                    $nameExtracted = null;
                    // "M. MME NOM - ..." ou "M. NOM - ..."
                    if (preg_match('/^M[.\s]+(MME\s+)?(.+?)\s*-\s*/i', $line, $m)) {
                        $nameExtracted = trim($m[2]);
                    // "MR NOM - ..." ou "MR ET MME NOM - ..."
                    } elseif (preg_match('/^MR\s+(ET\s+MME\s+)?(.+?)\s*-\s*/i', $line, $m)) {
                        $nameExtracted = trim($m[2]);
                    // "Logement Occupé : MME NOM - ..." ou ": M. NOM - ..."
                    } elseif (preg_match('/Logement\s+Occup.+?\s*:\s*(MR?\s+(?:ET\s+MME\s+)?|MME?\s+|M\.\s+)?(.+?)\s*-\s*/iu', $line, $m)) {
                        $nameExtracted = trim($m[2]);
                    // Ligne commençant par ": M. NOM -" (Logement Occupé sur ligne précédente)
                    } elseif (preg_match('/^:\s*(MR?\s+(?:ET\s+MME\s+)?|MME?\s+|M\.\s+)(.+?)\s*-\s*/i', $line, $m)) {
                        $nameExtracted = trim($m[2]);
                    }
                    if ($nameExtracted) {
                        $nomClient = $nameExtracted;
                    }
                }

                // Téléphone : priorité Portable > Téléphone
                if (!$telephone && preg_match('/Portable\s*:\s*(\d+)/i', $line, $m)) {
                    $telephone = trim($m[1]);
                }
                if (!$telephone && preg_match('/Téléphone\s*:\s*(\d+)/i', $line, $m)) {
                    $telephone = trim($m[1]);
                }
            }

            // Combiner adresse + code postal
            if ($codePostalVille && $adresse) {
                $adresse = $adresse . "\n" . $codePostalVille;
            } elseif ($codePostalVille && !$adresse) {
                $adresse = $codePostalVille;
            }

            // Détection automatique du type de prestation via code OCR
            $detectedTypeId = '';
            $typePrestations = $this->em->getRepository(TypePrestation::class)->findAll();
            foreach ($typePrestations as $tp) {
                $code = $tp->getCode();
                if ($code && stripos($text, $code) !== false) {
                    $detectedTypeId = $tp->getId();
                    break;
                }
            }

            // Log OCR pour debug
            $logPath = $this->getParameter('kernel.project_dir') . '/var/ocr_debug.log';
            $log = "=== OCR Import " . date('Y-m-d H:i:s') . " ===\n";
            $log .= "Fichier: " . $file->getClientOriginalName() . "\n";
            $log .= "\n--- TEXTE BRUT (avant corrections) ---\n";
            $log .= $textBrut . "\n";
            $log .= "\n--- TEXTE CORRIGÉ (après fixOcrText) ---\n";
            $log .= $text . "\n";
            $log .= "\n--- DONNÉES EXTRAITES ---\n";
            $log .= "N° Commande: " . ($numeroCommande ?: 'NON TROUVÉ') . "\n";
            $log .= "Nom client: " . ($nomClient ?: 'NON TROUVÉ') . "\n";
            $log .= "Téléphone: " . ($telephone ?: 'NON TROUVÉ') . "\n";
            $log .= "Adresse: " . ($adresse ?: 'NON TROUVÉ') . "\n";
            $log .= "Complément: " . ($complement ?: 'NON TROUVÉ') . "\n";
            $log .= "Code postal+ville: " . ($codePostalVille ?: 'NON TROUVÉ') . "\n";
            $log .= "Date limite: " . ($dateLimite ?: 'NON TROUVÉ') . "\n";
            $log .= "Type prestation détecté: " . ($detectedTypeId ?: 'NON TROUVÉ') . "\n";
            $log .= "Section client (après index $startIndex) ---\n";
            foreach ($clientLines as $i => $line) {
                $log .= "  [$i] $line\n";
            }
            $log .= "\n--- TOUTES LES LIGNES ---\n";
            foreach ($allLines as $i => $line) {
                $log .= "[$i] $line\n";
            }
            $log .= "\n\n";
            file_put_contents($logPath, $log, FILE_APPEND);

            // Redirection vers formulaire "nouveau bon" avec données pré-remplies
            return $this->redirectToRoute('admin_bon_commande_new', [
                'numeroCommande' => $numeroCommande,
                'clientNom' => $nomClient,
                'clientAdresse' => $adresse,
                'clientTelephone' => $telephone,
                'clientComplementAdresse' => $complement,
                'dateLimiteExecution' => $dateLimite,
                'typePrestation' => $detectedTypeId,
            ]);
        }

        $this->addFlash('danger', 'Aucun fichier reçu');
        return $this->redirectToRoute('admin_bon_commande_index');
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