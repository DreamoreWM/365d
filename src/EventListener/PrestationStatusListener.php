<?php

namespace App\EventListener;

use App\Entity\Prestation;
use App\Enum\StatutPrestation;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Detects when a Prestation status transitions to NON_EFFECTUE
 * and creates a notification for the assigned employee.
 *
 * Uses the preUpdate / postFlush pair to avoid nested flush issues:
 * - preUpdate collects affected prestations into a pending list
 * - postFlush processes the list after the main flush is done
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
class PrestationStatusListener
{
    /** @var Prestation[] */
    private array $pendingNotifications = [];

    public function __construct(private NotificationService $notificationService) {}

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Prestation) {
            return;
        }

        if (!$args->hasChangedField('statut')) {
            return;
        }

        $oldStatut = $args->getOldValue('statut');
        $newStatut = $args->getNewValue('statut');

        // Only notify on a genuine transition *to* NON_EFFECTUE
        if (
            $newStatut === StatutPrestation::NON_EFFECTUE
            && $oldStatut !== StatutPrestation::NON_EFFECTUE
            && $oldStatut !== null
        ) {
            $this->pendingNotifications[] = $entity;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->pendingNotifications)) {
            return;
        }

        // Clear the list BEFORE processing to prevent an infinite loop
        // if the notification flush triggers postFlush again.
        $toNotify = $this->pendingNotifications;
        $this->pendingNotifications = [];

        foreach ($toNotify as $prestation) {
            $this->notificationService->notifyPrestationNonEffectuee($prestation);
        }
    }
}
