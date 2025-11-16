<?php

namespace App\EventListener;

use App\Entity\Prestation;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class PrestationListener
{
    public function postPersist(Prestation $prestation, LifecycleEventArgs $args): void
    {
        $this->updateBonDeCommande($prestation);
    }

    public function postUpdate(Prestation $prestation, LifecycleEventArgs $args): void
    {
        $this->updateBonDeCommande($prestation);
    }

    public function postRemove(Prestation $prestation, LifecycleEventArgs $args): void
    {
        $this->updateBonDeCommande($prestation);
    }

    private function updateBonDeCommande(Prestation $prestation): void
    {
        $bon = $prestation->getBonDeCommande();
        if (!$bon) return;

        $prestations = $bon->getPrestations();

        $bon->setNombrePrestations($prestations->count());

        // Déterminer le statut
        if ($prestations->isEmpty()) {
            $bon->setStatut('à programmer');
        } else {
            $allTerminees = true;
            $oneProgrammee = false;

            foreach ($prestations as $p) {
                if ($p->getStatut() === 'programmé') {
                    $oneProgrammee = true;
                    $allTerminees = false;
                } elseif ($p->getStatut() !== 'terminé') {
                    $allTerminees = false;
                }
            }

            if ($allTerminees) {
                $bon->setStatut('terminé');
            } elseif ($oneProgrammee) {
                $bon->setStatut('programmé');
            } else {
                $bon->setStatut('à programmer');
            }
        }

        $em = $args->getObjectManager(); // récupère l’EntityManager
        $em->persist($bon);
    }
}
