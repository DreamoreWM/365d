<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/ocr-debug')]
class OcrDebugController extends AbstractController
{
    private function checkToken(Request $request): bool
    {
        $token = $_ENV['OCR_DEBUG_TOKEN'] ?? '';
        return $token && $request->query->get('token') === $token;
    }

    // =====================================================
    // LOG VIEWER — GET /ocr-debug/log?token=XXX
    // =====================================================
    #[Route('/log', name: 'ocr_debug_log', methods: ['GET'])]
    public function log(Request $request): Response
    {
        if (!$this->checkToken($request)) {
            return new Response('403 Forbidden', 403);
        }

        $logPath = $this->getParameter('kernel.project_dir') . '/var/ocr_debug.log';
        $content = file_exists($logPath) ? file_get_contents($logPath) : '(log vide)';

        $clear = $request->query->getBoolean('clear');
        if ($clear && file_exists($logPath)) {
            file_put_contents($logPath, '');
            $content = '(log effacé)';
        }

        $token = $request->query->get('token');
        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>OCR Debug Log</title>
<meta http-equiv="refresh" content="5">
<style>
body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:20px;margin:0}
pre{white-space:pre-wrap;word-break:break-all;background:#252526;padding:16px;border-radius:6px;font-size:13px}
.toolbar{display:flex;gap:12px;margin-bottom:16px;align-items:center}
a{color:#4fc3f7;text-decoration:none;padding:6px 12px;background:#333;border-radius:4px}
a:hover{background:#444}
h2{color:#4fc3f7;margin:0 0 16px}
.refresh{color:#aaa;font-size:12px}
</style></head><body>
<h2>OCR Debug Log</h2>
<div class="toolbar">
  <a href="/ocr-debug/log?token=' . htmlspecialchars($token) . '">Rafraîchir</a>
  <a href="/ocr-debug/log?token=' . htmlspecialchars($token) . '&clear=1">Effacer le log</a>
  <a href="/ocr-debug/test?token=' . htmlspecialchars($token) . '">Tester un PDF</a>
  <span class="refresh">Auto-refresh 5s</span>
</div>
<pre>' . htmlspecialchars($content) . '</pre>
</body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // =====================================================
    // TEST PDF — GET/POST /ocr-debug/test?token=XXX
    // Affiche le texte brut extrait + les données parsées
    // =====================================================
    #[Route('/test', name: 'ocr_debug_test', methods: ['GET', 'POST'])]
    public function test(Request $request): Response
    {
        if (!$this->checkToken($request)) {
            return new Response('403 Forbidden', 403);
        }

        $token   = $request->query->get('token');
        $result  = '';
        $rawText = '';

        if ($request->isMethod('POST') && $file = $request->files->get('pdf')) {
            $ext     = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? ''));
            $tmpPath  = sys_get_temp_dir() . '/' . uniqid('ocrdebug_') . '.' . ($ext ?: 'bin');
            $fileSize = $file->getSize();
            $file->move(sys_get_temp_dir(), basename($tmpPath));

            $result .= "Fichier : " . htmlspecialchars($file->getClientOriginalName()) . "\n";
            $result .= "Extension : $ext\n";
            $result .= "Taille : " . number_format($fileSize / 1024, 1) . " Ko\n\n";

            if ($ext === 'pdf') {
                $rawText    = '';
                $pdftotext  = trim((string) shell_exec('which pdftotext 2>/dev/null'));
                if ($pdftotext) {
                    $outPath = $tmpPath . '.txt';
                    $cmd     = escapeshellcmd($pdftotext) . ' -layout -enc UTF-8 ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($outPath) . ' 2>/dev/null';
                    exec($cmd, $cmdOut, $code);
                    $result .= "pdftotext exit code : $code\n";
                    if ($code === 0 && file_exists($outPath)) {
                        $rawText = (string) file_get_contents($outPath);
                        @unlink($outPath);
                    }
                } else {
                    $result .= "pdftotext non disponible sur ce serveur\n";
                }
                if ($rawText) {
                    $result .= "=== TEXTE BRUT EXTRAIT DU PDF ===\n" . $rawText . "\n\n";
                } else {
                    $result .= "Aucun texte extrait\n\n";
                }
            } else {
                $result .= "Ce fichier n'est pas un PDF (ext=$ext). Uploadez un .pdf\n\n";
            }

            if ($rawText) {
                $result .= "=== PARSING REGEX ===\n";
                $text     = $rawText;
                $allLines = array_values(array_filter(array_map('trim', explode("\n", $text))));

                // Date
                if (preg_match('/Travaux\s+.+?pour\s+le\s+(\d{2}\/\d{2}\/\d{4})/isu', $text, $m)) {
                    $p = explode('/', $m[1]);
                    $result .= "Date limite  : " . $p[2].'-'.$p[1].'-'.$p[0] . "\n";
                } else {
                    $result .= "Date limite  : NON TROUVÉE\n";
                }

                // Commande: ICF Habitat (RNE 15/745676/E) / Vilogia (LOG/N38872) / Partenord (H95598)
                if (preg_match('/Commande\s+N.?\s+([A-Z]{2,}\s+\S+)/iu', $text, $m)) {
                    $result .= "N° Commande  : " . trim($m[1]) . " (ICF)\n";
                } elseif (preg_match('/Commande\s*n.?\s*:?\s*([A-Z0-9][A-Z0-9\/]{3,})/iu', $text, $m)) {
                    $result .= "N° Commande  : " . trim($m[1]) . "\n";
                } else {
                    $result .= "N° Commande  : NON TROUVÉ\n";
                }

                // Locataire Partenord: "Logement Occupé : MME DUPONT Marie - Portable : ..."
                if (preg_match('/Logement\s+Occup.+?\s*:\s*(?:MR?\s+(?:ET\s+MME\s+)?|MME?\s+|M\.\s+)?(.+?)\s*-\s*(?:Portable|T[eé]l)/isu', $text, $m)) {
                    $result .= "Nom locataire: " . trim($m[1]) . " (Partenord)\n";
                // Locataire Vilogia/ICF: "Occupant actuel : M. MERIMI MOHAMMED 211051/76"
                } elseif (preg_match('/Occupant\s+actuel\s*:\s*(?:M\.?\s*|MME?\.?\s+|MR?\s+)?(.+?)(?:\s+\d{5,}\/\d+)?\s*$/im', $text, $m)) {
                    $result .= "Nom locataire: " . trim($m[1]) . " (Vilogia/ICF)\n";
                } else {
                    $result .= "Nom locataire: NON TROUVÉ\n";
                }

                // Téléphone Partenord
                if (preg_match('/Logement\s+Occup.+?(?:Portable|T[eé]l[eé]phone)\s*:\s*(\d[\d\s]{8,})/isu', $text, $m)) {
                    $result .= "Téléphone    : " . preg_replace('/\s+/', '', trim($m[1])) . " (Partenord)\n";
                // Téléphone Vilogia/ICF: "portable : 0695348928" near "Occupant actuel"
                } elseif (preg_match('/Occupant\s+actuel.+?portable\s*:\s*(\d[\d\s]{8,})/isu', $text, $m)) {
                    $result .= "Téléphone    : " . preg_replace('/\s+/', '', trim($m[1])) . " (Vilogia/ICF)\n";
                } else {
                    $result .= "Téléphone    : NON TROUVÉ\n";
                }

                // Adresse
                $startIndex = 0;
                foreach ($allLines as $i => $line) {
                    if (stripos($line, 'Prestation') !== false && stripos($line, 'Privatives') !== false) {
                        $startIndex = $i + 1; break;
                    }
                }
                $found = false;
                foreach (array_slice($allLines, $startIndex) as $line) {
                    if (preg_match('/\d+\s+(RUE|AVENUE|AV|BOULEVARD|BD|ALL[EÉ]E|IMPASSE|CHEMIN|PLACE|ROUTE|PASSAGE|VOIE)\b/i', $line)) {
                        $result .= "Adresse      : $line\n"; $found = true; break;
                    }
                }
                if (!$found) $result .= "Adresse      : NON TROUVÉE\n";

                // Code postal (standard: "59800 LILLE", ou ICF inversé: "LILLE 59800")
                $found = false;
                foreach (array_slice($allLines, $startIndex) as $line) {
                    if (preg_match('/^\d{5}\s+[A-ZÉÈÊÀÂ\s-]+$/u', $line)) {
                        $result .= "CP + Ville   : $line\n"; $found = true; break;
                    }
                    if (preg_match('/^([A-ZÉÈÊÀÂ][A-ZÉÈÊÀÂ\s-]{2,})\s+(\d{5})$/u', $line, $cp)) {
                        $result .= "CP + Ville   : " . trim($cp[2]) . ' ' . trim($cp[1]) . " (inversé ICF)\n"; $found = true; break;
                    }
                }
                if (!$found) $result .= "CP + Ville   : NON TROUVÉ\n";

                // Complément
                $found = false;
                foreach ($allLines as $line) {
                    if (preg_match('/LOGEMENT\s*n.?\s*\d+/iu', $line)) {
                        $result .= "Complément   : $line\n"; $found = true; break;
                    }
                }
                if (!$found) $result .= "Complément   : NON TROUVÉ\n";
            }

            @unlink($tmpPath);
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Test PDF Parser</title>
<style>
body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:20px;margin:0}
h2{color:#4fc3f7;margin:0 0 16px}
form{background:#252526;padding:16px;border-radius:6px;margin-bottom:20px}
input[type=file]{color:#d4d4d4;margin-bottom:12px;display:block}
button{background:#0078d4;color:#fff;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-size:14px}
button:hover{background:#006cbd}
pre{white-space:pre-wrap;word-break:break-all;background:#252526;padding:16px;border-radius:6px;font-size:13px}
a{color:#4fc3f7;text-decoration:none;padding:6px 12px;background:#333;border-radius:4px}
.toolbar{margin-bottom:16px}
</style></head><body>
<h2>Test extraction PDF</h2>
<div class="toolbar">
  <a href="/ocr-debug/log?token=' . htmlspecialchars($token) . '">Voir les logs</a>
</div>
<form method="post" enctype="multipart/form-data">
  <input type="file" name="pdf" accept=".pdf" required>
  <button type="submit">Extraire et analyser</button>
</form>'
. ($result ? '<pre>' . htmlspecialchars($result) . '</pre>' : '')
. '</body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

}
