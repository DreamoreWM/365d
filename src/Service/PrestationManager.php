<?php

namespace App\Service;

use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use App\Enum\StatutPrestation;
use App\Enum\StatutBonDeCommande;
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
     * - si déjà TERMINE ou NON_EFFECTUE => on garde (statuts terminaux verrouillés)
     * - pas de date => A_PROGRAMMER
     * - date == aujourd'hui (comparaison jour seul) => EN_COURS
     * - date > aujourd'hui => PROGRAMME
     * - date < aujourd'hui :
     *     - si la prestation était PROGRAMME ou EN_COURS => NON_EFFECTUE
     *     - sinon => TERMINE
     */
    public function updatePrestationStatut(Prestation $prestation): void
    {
        $date = $prestation->getDatePrestation();

        if (!$date) {
            $prestation->setStatut(StatutPrestation::A_PROGRAMMER);
            return;
        }

        // Les statuts terminaux ne changent jamais automatiquement
        $currentStatut = $prestation->getStatut();
        if ($currentStatut === StatutPrestation::TERMINE || $currentStatut === StatutPrestation::NON_EFFECTUE) {
            return;
        }

        $today = new \DateTimeImmutable('today');

        // Comparaison par jour seulement pour éviter les problèmes d'heures lors des tests
        $dateDay = $date->format('Y-m-d');
        $todayDay = $today->format('Y-m-d');

        // Aujourd'hui
        if ($dateDay === $todayDay) {
            $prestation->setStatut(StatutPrestation::EN_COURS);
            return;
        }

        // Futur
        if ($date > $today) {
            $prestation->setStatut(StatutPrestation::PROGRAMME);
            return;
        }

        // Date passée : la prestation était PROGRAMME ou EN_COURS => non effectué
        if ($currentStatut === StatutPrestation::PROGRAMME || $currentStatut === StatutPrestation::EN_COURS) {
            $prestation->setStatut(StatutPrestation::NON_EFFECTUE);
            return;
        }

        // Pour les autres cas (A_PROGRAMMER avec date passée), on met à non effectué aussi
        $prestation->setStatut(StatutPrestation::NON_EFFECTUE);
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

        foreach ($prestations as $p) {
            $statut = $p->getStatut();

            if ($statut === StatutPrestation::TERMINE) {
                $terminees++;
            } elseif ($statut === StatutPrestation::NON_EFFECTUE) {
                $hasNonEffectuee = true;
            } elseif ($statut === StatutPrestation::EN_COURS) {
                $hasEnCours = true;
            } elseif ($statut === StatutPrestation::PROGRAMME) {
                $hasProgrammee = true;
            }
        }

        // Compteur : on ne compte QUE les prestations terminées
        $bon->setNombrePrestations($terminees);

        if ($bon->getTypePrestation()) {
            $typeCount = $bon->getTypePrestation()->getNombrePrestationsNecessaires();
            // Ne pas réduire si la valeur a été augmentée manuellement (ex: réouverture)
            if ($bon->getNombrePrestationsNecessaires() < $typeCount) {
                $bon->setNombrePrestationsNecessaires($typeCount);
            }
        }

        // Détermination du statut du bon de commande
        // Si pas de type de prestation, on considère qu'1 prestation terminée suffit
        $needed = max(1, $bon->getNombrePrestationsNecessaires());

        if ($prestations->isEmpty()) {
            $bon->setStatut(StatutBonDeCommande::A_PROGRAMMER);
        } elseif ($terminees >= $needed) {
            $bon->setStatut(StatutBonDeCommande::TERMINE);
        } elseif ($hasEnCours) {
            $bon->setStatut(StatutBonDeCommande::EN_COURS);
        } elseif ($hasProgrammee) {
            $bon->setStatut(StatutBonDeCommande::PROGRAMME);
        } elseif ($hasNonEffectuee) {
            $bon->setStatut(StatutBonDeCommande::A_PROGRAMMER);
        } else {
            $bon->setStatut(StatutBonDeCommande::A_PROGRAMMER);
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
