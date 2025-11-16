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
        if (!$date) {
            $prestation->setStatut('à programmer');
            return;
        }

        $now = new \DateTimeImmutable('today');

        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            $prestation->setStatut('en cours');
        } elseif ($date > $now) {
            $prestation->setStatut('programmé');
        } else {
            $prestation->setStatut('terminé');
        }
    }

    public function updateBonDeCommande(BonDeCommande $bon): void
    {
        $prestations = $bon->getPrestations();
        $bon->setNombrePrestations($prestations->count());

        if ($bon->getTypePrestation()) {
            $bon->setNombrePrestationsNecessaires(
                $bon->getTypePrestation()->getNombrePrestationsNecessaires()
            );
        }

        // S’il n’y a aucune prestation → à programmer
        if ($prestations->count() === 0) {
            $bon->setStatut('à programmer');
            return;
        }

        $now = new \DateTimeImmutable('today');

        $toutesTerminees = true;
        $enCours = false;
        $nonEffectuee = false;
        $programmee = false;

        foreach ($prestations as $p) {

            $date = $p->getDatePrestation();
            $statut = $p->getStatut();

            // Terminé ?
            if ($statut !== 'terminé') {
                $toutesTerminees = false;
            }

            // En cours ?
            if ($statut === 'en cours') {
                $enCours = true;
            }

            // Programmée ?
            if ($statut === 'programmé') {
                $programmee = true;

                // En retard ?
                if ($date && $date < $now) {
                    $nonEffectuee = true;
                }
            }
        }

        // ⚠️ Ordre logique correct
        if ($toutesTerminees && $bon->getNombrePrestations() >= $bon->getNombrePrestationsNecessaires()) {
            $bon->setStatut('terminé');
        } elseif ($enCours) {
            $bon->setStatut('en cours');
        } elseif ($nonEffectuee) {   // priorité !
            $bon->setStatut('non effectué');
        } elseif ($programmee) {
            $bon->setStatut('programmé');
        } else {
            $bon->setStatut('à programmer');
        }

        $this->em->persist($bon);
        $this->em->flush();
    }
}
