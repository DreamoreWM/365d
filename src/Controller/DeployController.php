<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DeployController extends AbstractController
{
    #[Route('/deploy', name: 'deploy_webhook', methods: ['POST'])]
    public function deploy(Request $request): Response
    {
        $secret = $_ENV['DEPLOY_SECRET'] ?? '';
        $output = [];
        $output[] = '[' . date('Y-m-d H:i:s') . '] Webhook reçu';

        // Vérification signature GitHub
        $signature = $request->headers->get('X-Hub-Signature-256');
        if (!$signature) {
            $output[] = 'ERREUR: Signature manquante';
            return new Response(implode("\n", $output), 401);
        }

        $payload = $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            $output[] = 'ERREUR: Signature invalide';
            $output[] = 'Expected: ' . substr($expected, 0, 20) . '...';
            $output[] = 'Received: ' . substr($signature, 0, 20) . '...';
            return new Response(implode("\n", $output), 401);
        }

        $output[] = 'Signature valide ✓';

        $projectDir = dirname(__DIR__, 2);
        $output[] = 'Project dir: ' . $projectDir;

        // Git pull (compatible Windows)
        $gitCommand = 'cd /d "' . $projectDir . '" && git pull --rebase --autostash 2>&1';
        $output[] = 'Commande: ' . $gitCommand;

        exec($gitCommand, $gitLines, $gitCode);
        $output[] = '--- GIT PULL (code: ' . $gitCode . ') ---';
        $output[] = implode("\n", $gitLines);

        // Cache clear
        $cacheCommand = 'php "' . $projectDir . '/bin/console" cache:clear --no-warmup 2>&1';
        $output[] = '--- CACHE CLEAR ---';

        exec($cacheCommand, $cacheLines, $cacheCode);
        $output[] = 'Code retour: ' . $cacheCode;
        $output[] = implode("\n", $cacheLines);

        // Log dans un fichier
        $logFile = $projectDir . '/var/log/deploy.log';
        $logContent = implode("\n", $output) . "\n\n";
        @file_put_contents($logFile, $logContent, FILE_APPEND);

        return new Response(implode("\n", $output), 200);
    }
}
