<?php

namespace App\Command;

use App\Service\PrestationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-bons',
    description: 'Met à jour les statuts des prestations et des bons',
)]
class UpdateBonsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private PrestationManager $manager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ---------------------------
        // 1) MISE A JOUR DES PRESTATIONS
        // ---------------------------
        $prestations = $this->em->getRepository(\App\Entity\Prestation::class)->findAll();

        foreach ($prestations as $p) {
            $this->manager->updatePrestationStatut($p);
            $this->em->persist($p);
        }

        $this->em->flush();

        // ---------------------------
        // 2) MISE A JOUR DES BONS
        // ---------------------------
        $bons = $this->em->getRepository(\App\Entity\BonDeCommande::class)->findAll();

        foreach ($bons as $b) {
            $this->manager->updateBonDeCommande($b);
        }

        $io->success('Statuts des prestations et bons mis à jour.');

        return Command::SUCCESS;
    }
}
