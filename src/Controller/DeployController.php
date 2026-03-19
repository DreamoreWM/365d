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

        // Vérification signature GitHub
        $signature = $request->headers->get('X-Hub-Signature-256');
        if (!$signature) {
            return new Response('Signature manquante', 401);
        }

        $payload = $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            return new Response('Signature invalide', 401);
        }

        $projectDir = dirname(__DIR__, 2);
        $output = [];

        // git pull
        exec('cd ' . escapeshellarg($projectDir) . ' && git pull --rebase 2>&1', $lines, $code);
        $output[] = 'git pull: ' . implode(' | ', $lines);

        // cache:clear
        exec('php ' . escapeshellarg($projectDir . '/bin/console') . ' cache:clear 2>&1', $lines2, $code2);
        $output[] = 'cache:clear: ' . implode(' | ', $lines2);

        return new Response(implode("\n", $output), 200);
    }
}
