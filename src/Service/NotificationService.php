<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Prestation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function notifyPrestationNonEffectuee(Prestation $prestation): void
    {
        $employe = $prestation->getEmploye();
        if (!$employe) {
            return;
        }

        $date = $prestation->getDatePrestation()?->format('d/m/Y') ?? 'date inconnue';
        $description = $prestation->getDescription() ?? '';
        $excerpt = mb_strlen($description) > 80 ? mb_substr($description, 0, 80) . '…' : $description;

        $notification = new Notification();
        $notification->setUser($employe);
        $notification->setType('prestation_annulee');
        $notification->setMessage(sprintf(
            'Votre prestation du %s a été annulée%s.',
            $date,
            $excerpt ? ' : ' . $excerpt : ''
        ));
        $notification->setData([
            'prestation_id' => $prestation->getId(),
            'date'          => $date,
        ]);

        $this->em->persist($notification);
        $this->em->flush();
    }

    public function notify(User $user, string $type, string $message, ?array $data = null): void
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setMessage($message);
        $notification->setData($data);

        $this->em->persist($notification);
        $this->em->flush();
    }
}
