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

    /**
     * Met à jour le statut d'une prestation selon la date et le statut actuel.
     *
     * Règles :
     * - pas de date => 'à programmer'
     * - date == aujourd'hui (comparaison jour seul) => 'en cours'
     * - date > aujourd'hui => 'programmé'
     * - date < aujourd'hui :
     *     - si la prestation était 'programmé' => 'non effectué'
     *     - si déjà 'non effectué' => on garde 'non effectué'
     *     - si déjà 'terminé' => on garde 'terminé'
     *     - sinon => 'terminé'
     */
    public function updatePrestationStatut(Prestation $prestation): void
    {
        $date = $prestation->getDatePrestation();

        if (!$date) {
            $prestation->setStatut('à programmer');
            return;
        }

        $today = new \DateTimeImmutable('today');

        // Comparaison par jour seulement pour éviter les problèmes d'heures lors des tests
        $dateDay = $date->format('Y-m-d');
        $todayDay = $today->format('Y-m-d');

        // Aujourd'hui
        if ($dateDay === $todayDay) {
            $prestation->setStatut('en cours');
            return;
        }

        // Futur
        if ($date > $today) {
            $prestation->setStatut('programmé');
            return;
        }

        // Date passée
        $currentStatut = $prestation->getStatut() ?? '';

        // Si elle était programmée et la date est passée => non effectué
        if ($currentStatut === 'programmé') {
            $prestation->setStatut('non effectué');
            return;
        }

        // Si déjà non effectué ou terminé, on garde ce statut
        if ($currentStatut === 'non effectué' || $currentStatut === 'terminé') {
            // On ne change pas le statut
            return;
        }

        // Sinon, on considère la prestation comme terminée
        $prestation->setStatut('terminé');
    }

    /**
     * Met à jour le statut / compteurs du bon de commande.
     */
    public function updateBonDeCommande(BonDeCommande $bon): void
    {
        $prestations = $bon->getPrestations();

        $terminees = 0;
        $hasNonEffectuee = false;
        $hasEnCours = false;
        $hasProgrammee = false;

        // Pas besoin de re-modifier les statuts ici, 
        // ils ont déjà été mis à jour par updatePrestationStatut
        foreach ($prestations as $p) {
            $statut = $p->getStatut();

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

        // Compteur : on ne compte QUE les prestations terminées (selon ta règle)
        $bon->setNombrePrestations($terminees);

        if ($bon->getTypePrestation()) {
            $bon->setNombrePrestationsNecessaires(
                $bon->getTypePrestation()->getNombrePrestationsNecessaires()
            );
        }

        if ($prestations->isEmpty()) {
            $bon->setStatut('à programmer');
        } elseif ($hasNonEffectuee) {
            $bon->setStatut('à programmer');
        } elseif ($terminees >= $bon->getNombrePrestationsNecessaires() && $bon->getNombrePrestationsNecessaires() > 0) {
            $bon->setStatut('terminé');
        } elseif ($hasEnCours) {
            $bon->setStatut('en cours');
        } elseif ($hasProgrammee) {
            $bon->setStatut('programmé');
        } else {
            $bon->setStatut('à programmer');
        }

        $this->em->persist($bon);
        $this->em->flush();
    }

    public function updateAllBonDeCommande(): void
    {
        $bons = $this->em->getRepository(BonDeCommande::class)->findAll();

        foreach ($bons as $bon) {

            // 1) Mettre à jour CHAQUE PRESTATION avant le bon
            foreach ($bon->getPrestations() as $p) {
                $this->updatePrestationStatut($p);
            }

            // 2) Puis mettre à jour le bon
            $this->updateBonDeCommande($bon);
        }
    }

}
