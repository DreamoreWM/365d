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
     * - pas de date => A_PROGRAMMER
     * - date == aujourd'hui (comparaison jour seul) => EN_COURS
     * - date > aujourd'hui => PROGRAMME
     * - date < aujourd'hui :
     *     - si la prestation était PROGRAMME => NON_EFFECTUE
     *     - si déjà NON_EFFECTUE => on garde NON_EFFECTUE
     *     - si déjà TERMINE => on garde TERMINE
     *     - sinon => TERMINE
     */
    public function updatePrestationStatut(Prestation $prestation): void
    {
        $date = $prestation->getDatePrestation();

        if (!$date) {
            $prestation->setStatut(StatutPrestation::A_PROGRAMMER);
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

        // Date passée
        $currentStatut = $prestation->getStatut();

        // Si déjà terminé, on garde ce statut
        if ($currentStatut === StatutPrestation::TERMINE) {
            return;
        }

        // Si déjà non effectué, on garde ce statut
        if ($currentStatut === StatutPrestation::NON_EFFECTUE) {
            return;
        }

        // Si elle était programmée ou en cours et la date est passée => non effectué
        // (car elle n'a pas été complétée)
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
            $bon->setNombrePrestationsNecessaires(
                $bon->getTypePrestation()->getNombrePrestationsNecessaires()
            );
        }

        // Détermination du statut du bon de commande
        // FIX: vérifier le quota AVANT les non effectuées
        if ($prestations->isEmpty()) {
            $bon->setStatut(StatutBonDeCommande::A_PROGRAMMER);
        } elseif ($terminees >= $bon->getNombrePrestationsNecessaires() && $bon->getNombrePrestationsNecessaires() > 0) {
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
