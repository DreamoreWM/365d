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
        $projectDir = dirname(__DIR__, 2);
        $logFile = $projectDir . '/var/log/deploy.log';

        $log = function (string $line) use ($logFile): void {
            @file_put_contents(
                $logFile,
                '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n",
                FILE_APPEND
            );
        };

        $log('Webhook reçu');

        // Vérification signature GitHub
        $signature = $request->headers->get('X-Hub-Signature-256');
        if (!$signature) {
            $log('ERREUR: Signature manquante');
            return new Response("ERREUR: Signature manquante\n", 401);
        }

        $payload = $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            $log('ERREUR: Signature invalide');
            return new Response("ERREUR: Signature invalide\n", 401);
        }

        $log('Signature valide, déploiement en arrière-plan');

        // Réponse immédiate à GitHub (timeout webhook = 10s)
        // Le déploiement (git pull + cache:clear) continue après la réponse.
        $response = new Response("Webhook accepté, déploiement lancé\n", 202);
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));
        $response->sendHeaders();
        $response->sendContent();

        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (\function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            // Fallback: vider les buffers pour libérer le client
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }

        // À partir d'ici, GitHub a déjà reçu sa réponse.
        @ignore_user_abort(true);
        @set_time_limit(600);

        $this->runDeploy($projectDir, $log);

        return $response;
    }

    private function runDeploy(string $projectDir, callable $log): void
    {
        $isWindows = \DIRECTORY_SEPARATOR === '\\';
        $cdCommand = $isWindows
            ? 'cd /d "' . $projectDir . '"'
            : 'cd ' . escapeshellarg($projectDir);

        // Git pull
        $gitCommand = $cdCommand . ' && git pull --rebase --autostash 2>&1';
        exec($gitCommand, $gitLines, $gitCode);
        $log('GIT PULL (code ' . $gitCode . ')');
        foreach ($gitLines as $line) {
            $log('  ' . $line);
        }

        // Cache clear
        $cacheCommand = 'php "' . $projectDir . '/bin/console" cache:clear --no-warmup 2>&1';
        exec($cacheCommand, $cacheLines, $cacheCode);
        $log('CACHE CLEAR (code ' . $cacheCode . ')');
        foreach ($cacheLines as $line) {
            $log('  ' . $line);
        }

        // Doctrine migrations — run only what's pending. --no-interaction so it never
        // blocks on prompts, --allow-no-migration so an up-to-date db exits cleanly.
        $migrationCommand = 'php "' . $projectDir . '/bin/console" '
            . 'doctrine:migrations:migrate --no-interaction --allow-no-migration --env=' . ($_ENV['APP_ENV'] ?? 'prod') . ' 2>&1';
        exec($migrationCommand, $migrationLines, $migrationCode);
        $log('MIGRATIONS (code ' . $migrationCode . ')');
        foreach ($migrationLines as $line) {
            $log('  ' . $line);
        }

        $log('Déploiement terminé');
    }
}
