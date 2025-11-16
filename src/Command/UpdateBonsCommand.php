<?php

namespace App\Command;

use App\Service\PrestationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:update-bons',
    description: 'Met à jour automatiquement les bons de commande.'
)]
class UpdateBonsCommand extends Command
{
    public function __construct(private PrestationManager $manager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->manager->updateAllBonDeCommande();

        $output->writeln('Mise à jour automatique des bons réalisée.');

        return Command::SUCCESS;
    }
}
