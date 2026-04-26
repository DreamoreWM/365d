<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:discord:deploy-status',
    description: 'Envoie le statut du déploiement sur Discord.',
)]
class DiscordDeployNotifyCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $webhookUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('status', InputArgument::REQUIRED, 'success ou failed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (empty($this->webhookUrl)) {
            return Command::SUCCESS;
        }

        $status = $input->getArgument('status');
        $success = $status === 'success';

        $embed = [
            'title'     => $success ? '✅ Déploiement terminé' : '❌ Déploiement échoué',
            'color'     => $success ? 0x2ecc71 : 0xe74c3c,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'footer'    => ['text' => '365d · ' . ($_SERVER['APP_ENV'] ?? 'prod')],
        ];

        try {
            $this->httpClient->request('POST', $this->webhookUrl, [
                'json'    => ['username' => '365d Deploy', 'embeds' => [$embed]],
                'timeout' => 5,
            ]);
        } catch (\Throwable) {
            // Pas critique si la notification échoue
        }

        return Command::SUCCESS;
    }
}
