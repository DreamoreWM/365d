<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/', name: 'app_notifications_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $notifications = $this->notificationRepository->findByUser($user, 50);

        // Mark all as read on view
        $this->notificationRepository->markAllReadForUser($user);

        return $this->render('notification/index.html.twig', [
            'notifications' => $notifications,
        ]);
    }

    #[Route('/{id}/read', name: 'app_notification_mark_read', methods: ['POST'])]
    public function markRead(Notification $notification): JsonResponse
    {
        if ($notification->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Interdit'], Response::HTTP_FORBIDDEN);
        }

        $notification->setIsRead(true);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/mark-all-read', name: 'app_notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        $this->notificationRepository->markAllReadForUser($this->getUser());

        return $this->json(['success' => true]);
    }
}
