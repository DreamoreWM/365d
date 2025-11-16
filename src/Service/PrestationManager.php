<?php

namespace App\Service;

use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use Doctrine\ORM\EntityManagerInterface;

class PrestationManager
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function updatePrestationStatut(Prestation $prestation): void
    {
        $date = $prestation->getDatePrestation();

        // Pas de date → "à programmer"
        if (!$date) {
            $prestation->setStatut('à programmer');
            return;
        }

        $now = new \DateTimeImmutable('today');

        // CAS 1 : prestation programmée et dépassée → "non effectué"
        if ($prestation->getStatut() === 'programmé' && $date < $now) {
            $prestation->setStatut('non effectué');
            return;
        }

        // CAS 2 : date aujourd'hui → en cours
        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            $prestation->setStatut('en cours');
            return;
        }

        // CAS 3 : date future → programmé
        if ($date > $now) {
            $prestation->setStatut('programmé');
            return;
        }

        // CAS 4 : date passée → terminé (pour tous sauf "programmé")
        if ($date < $now) {
            $prestation->setStatut('terminé');
            return;
        }
    }



    public function updateBonDeCommande(BonDeCommande $bon): void
    {
        $prestations = $bon->getPrestations();

        $terminees = 0;
        $hasNonEffectuee = false;
        $hasEnCours = false;
        $hasProgrammee = false;

        $now = new \DateTimeImmutable('today');

        foreach ($prestations as $p) {

            $statut = $p->getStatut();
            $date = $p->getDatePrestation();

            // Programmée mais passée → non effectué
            if ($statut === 'programmé' && $date && $date < $now) {
                $p->setStatut('non effectué');
                $statut = 'non effectué';
            }

            if ($statut === 'terminé') {
                $terminees++;
            } elseif ($statut === 'non effectué') {
                $hasNonEffectuee = true;
            } elseif ($statut === 'en cours') {
                $hasEnCours = true;
            } elseif ($statut === 'programmé') {
                $hasProgrammee = true;
            }
        }

        // Mise à jour du compteur
        $bon->setNombrePrestations($terminees);

        // Quota
        if ($bon->getTypePrestation()) {
            $bon->setNombrePrestationsNecessaires(
                $bon->getTypePrestation()->getNombrePrestationsNecessaires()
            );
        }

        // 📌 LOGIQUE DES TESTS
        if ($prestations->isEmpty()) {
            $bon->setStatut('à programmer');
        }
        elseif ($hasNonEffectuee) {
            $bon->setStatut('à programmer');
        }
        elseif ($terminees >= $bon->getNombrePrestationsNecessaires() && $bon->getNombrePrestationsNecessaires() > 0) {
            $bon->setStatut('terminé');
        }
        elseif ($hasEnCours) {
            $bon->setStatut('en cours');
        }
        elseif ($hasProgrammee) {
            $bon->setStatut('programmé');
        }
        else {
            $bon->setStatut('à programmer');
        }

        $this->em->persist($bon);
        $this->em->flush();
    }

    public function updateAllBonDeCommande(): void
    {
        $bons = $this->em->getRepository(BonDeCommande::class)->findAll();

        foreach ($bons as $bon) {
            $this->updateBonDeCommande($bon);
        }
    }



}
