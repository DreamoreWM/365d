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

        @mkdir(dirname($logFile), 0775, true);

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

        $log('Signature valide, déploiement détaché en arrière-plan');

        // Sur certains SAPI (php -S, apache en mode non-fastcgi, proxys type ngrok…)
        // les fallbacks ob_end_flush / flush() ne coupent pas la connexion TCP : la
        // requête reste ouverte tout le temps du déploiement (git pull + cache +
        // migrations, ~30 s à plusieurs minutes) et ngrok finit par renvoyer 503 à
        // GitHub. On détache donc le déploiement dans un process indépendant —
        // la réponse HTTP part immédiatement et le déploiement tourne en parallèle
        // en écrivant dans var/log/deploy.log.
        $this->spawnDeploy($projectDir, $logFile);

        return new Response("Webhook accepté, déploiement lancé\n", 202);
    }

    /**
     * Lance `git pull + cache:clear + migrations` dans un processus totalement
     * détaché (nohup … &) pour que la requête HTTP puisse rendre la main tout
     * de suite quel que soit le SAPI.
     */
    private function spawnDeploy(string $projectDir, string $logFile): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            // La prod tourne sous Linux — fallback inline sous Windows seulement.
            $this->runInline($projectDir, $logFile);
            return;
        }

        $env = $_ENV['APP_ENV'] ?? 'prod';

        $steps = [
            'cd ' . escapeshellarg($projectDir),
            'echo "--- $(date \'+%Y-%m-%d %H:%M:%S\') GIT PULL ---"',
            'git pull --rebase --autostash',
            'echo "--- $(date \'+%Y-%m-%d %H:%M:%S\') CACHE CLEAR ---"',
            'php bin/console cache:clear --no-warmup --env=' . escapeshellarg($env),
            'echo "--- $(date \'+%Y-%m-%d %H:%M:%S\') MIGRATIONS ---"',
            'php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=' . escapeshellarg($env),
            'echo "--- $(date \'+%Y-%m-%d %H:%M:%S\') DEPLOY TERMINÉ ---"',
        ];
        $script = implode(' && ', $steps);

        $cmd = sprintf(
            'nohup bash -c %s >> %s 2>&1 &',
            escapeshellarg($script),
            escapeshellarg($logFile),
        );
        exec($cmd);
    }

    private function runInline(string $projectDir, string $logFile): void
    {
        $log = function (string $line) use ($logFile): void {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
        };
        $env = $_ENV['APP_ENV'] ?? 'prod';
        $cd = 'cd /d "' . $projectDir . '" && ';

        foreach ([
            'GIT PULL'    => $cd . 'git pull --rebase --autostash 2>&1',
            'CACHE CLEAR' => $cd . 'php "' . $projectDir . '/bin/console" cache:clear --no-warmup --env=' . $env . ' 2>&1',
            'MIGRATIONS'  => $cd . 'php "' . $projectDir . '/bin/console" doctrine:migrations:migrate --no-interaction --allow-no-migration --env=' . $env . ' 2>&1',
        ] as $label => $command) {
            $lines = [];
            exec($command, $lines, $code);
            $log($label . ' (code ' . $code . ')');
            foreach ($lines as $line) $log('  ' . $line);
        }
        $log('Déploiement terminé');
    }
}
