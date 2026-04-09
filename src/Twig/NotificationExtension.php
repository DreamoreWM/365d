<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class NotificationExtension extends AbstractExtension
{
    public function __construct(private NotificationRepository $notificationRepository) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', $this->getUnreadCount(...)),
        ];
    }

    public function getUnreadCount(?UserInterface $user): int
    {
        if (!$user instanceof User) {
            return 0;
        }

        return $this->notificationRepository->countUnreadByUser($user);
    }
}
