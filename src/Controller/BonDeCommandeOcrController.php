<?php

namespace App\Controller;

use App\Entity\BonDeCommande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use thiagoalessio\TesseractOCR\TesseractOCR;

class BonDeCommandeOcrController extends AbstractController
{
    #[Route('/bon/upload', name: 'app_bon_upload')]
    public function upload(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $file = $request->files->get('photo');

            if ($file) {
                // 🔹 Copie temporaire dans /tmp
                $tmpPath = sys_get_temp_dir() . '/' . uniqid('ocr_') . '.' . $file->guessExtension();
                try {
                    $file->move(sys_get_temp_dir(), basename($tmpPath));
                } catch (FileException $e) {
                    $this->addFlash('danger', 'Erreur pendant la copie du fichier temporaire.');
                    return $this->redirectToRoute('app_bon_upload');
                }

                // 🧠 OCR
                $ocr = new TesseractOCR($tmpPath);
                $text = $ocr
                    ->lang('fra', 'eng')
                    ->psm(3)
                    ->oem(1)
                    ->config('preserve_interword_spaces', 1)
                    ->run();

                // 🗑️ Suppression du fichier image temporaire
                if (file_exists($tmpPath)) {
                    unlink($tmpPath);
                }

                // 💾 Sauvegarde du texte OCR brut pour debug
                $textDir = $this->getParameter('kernel.project_dir') . '/var/ocr_results';
                if (!is_dir($textDir)) {
                    mkdir($textDir, 0777, true);
                }
                $textFile = $textDir . '/' . 'ocr_' . date('Ymd_His') . '.txt';
                file_put_contents($textFile, $text);

                // 🔍 Extraction des infos principales
                preg_match('/Commande\s*(?:n[\s°º]*)?[:\-\s]*([A-Z0-9]+)/i', $text, $mNumero);
                preg_match('/éditée[, ]+le\s*([0-9\/]+)/i', $text, $mDate);

                // On garde uniquement la partie après "Travaux à réaliser"
                $start = stripos($text, 'Travaux à réaliser');
                if ($start !== false) {
                    $text = substr($text, $start);
                }

                // -------------------------------------------------------
                // ✅ Bloc PRESTATION PARTIES PRIVATIVES — extraction structurée
                // -------------------------------------------------------
                $lines = explode("\n", $text);
                $lines = array_map('trim', $lines);
                $lines = array_values(array_filter($lines));

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

                    // 🔹 Recherche du "Logement Occupé"
                    foreach ($lines as $line) {
                        if (stripos($line, 'Logement Occupé') !== false) {
                            // Cas avec téléphone
                            if (preg_match('/Logement\s+Occupé\s*:\s*(.*?)\s*-\s*Portable\s*:\s*([0-9]+)/i', $line, $m)) {
                                $nomClient = trim($m[1]);
                                $telephone = trim($m[2]);
                            }
                            // Cas sans téléphone
                            elseif (preg_match('/Logement\s+Occupé\s*:\s*(.*)/i', $line, $m)) {
                                $nomClient = trim($m[1]);
                            }
                            break;
                        }
                    }
                }

                // -------------------------------------------------------
                // ✅ Enregistrement en BDD
                // -------------------------------------------------------
                $bon = new BonDeCommande();
                $bon->setNumeroCommande($mNumero[1] ?? 'Inconnu');
                $bon->setDateCommande(
                    isset($mDate[1])
                        ? \DateTimeImmutable::createFromFormat('d/m/Y', $mDate[1]) ?: new \DateTimeImmutable()
                        : new \DateTimeImmutable()
                );
                $bon->setClientNom($nomClient ?: 'Inconnu');
                $bon->setClientTelephone($telephone);
                $bon->setClientComplementAdresse($complement);
                $bon->setClientAdresse($adresse);
                $bon->setClientEmail('');
                $bon->setStatut('à programmer');
                $bon->setNombrePrestations(0);
                $bon->setNombrePrestationsNecessaires(0);

                $em->persist($bon);
                $em->flush();

                $this->addFlash('success', "✅ Bon enregistré : {$bon->getNumeroCommande()} ({$bon->getClientNom()})");
                if ($telephone) {
                    $this->addFlash('info', "📞 Téléphone détecté : {$telephone}");
                }
                $this->addFlash('info', "📄 Résultat OCR sauvegardé dans : {$textFile}");

                return $this->redirectToRoute('app_bon_upload');
            }

            $this->addFlash('warning', 'Aucun fichier reçu.');
            return $this->redirectToRoute('app_bon_upload');
        }

        return $this->render('bon/upload.html.twig');
    }
}
