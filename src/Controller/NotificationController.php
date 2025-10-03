<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\NotificationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/notifications', name: 'api_notification_')]
#[OA\Tag(name: 'Notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/notifications',
        summary: 'Liste des notifications',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des notifications')]
    public function list(): JsonResponse
    {
        $notifications = $this->notificationService->getUserNotifications($this->getUser()->getId());

        return $this->json($notifications, Response::HTTP_OK, [], ['groups' => ['notification:list']]);
    }

    #[Route('/unread', name: 'unread', methods: ['GET'])]
    #[OA\Get(
        path: '/api/notifications/unread',
        summary: 'Notifications non lues',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des notifications non lues')]
    public function unread(): JsonResponse
    {
        $notifications = $this->notificationService->getUnreadNotifications($this->getUser()->getId());

        return $this->json($notifications, Response::HTTP_OK, [], ['groups' => ['notification:list']]);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    #[OA\Get(
        path: '/api/notifications/unread-count',
        summary: 'Nombre de notifications non lues',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Nombre',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'count', type: 'integer', example: 3)
            ]
        )
    )]
    public function unreadCount(): JsonResponse
    {
        $count = $this->notificationService->countUnread($this->getUser()->getId());

        return $this->json(['count' => $count]);
    }

    #[Route('/{id}/mark-read', name: 'mark_read', methods: ['POST'])]
    #[OA\Post(
        path: '/api/notifications/{id}/mark-read',
        summary: 'Marquer comme lue',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Notification marquée comme lue')]
    public function markAsRead(int $id): JsonResponse
    {
        $this->notificationService->markAsRead($id);

        return $this->json(['message' => 'Notification marquée comme lue']);
    }

    #[Route('/mark-all-read', name: 'mark_all_read', methods: ['POST'])]
    #[OA\Post(
        path: '/api/notifications/mark-all-read',
        summary: 'Marquer toutes comme lues',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Toutes les notifications marquées comme lues')]
    public function markAllAsRead(): JsonResponse
    {
        $this->notificationService->markAllAsRead($this->getUser()->getId());

        return $this->json(['message' => 'Toutes les notifications ont été marquées comme lues']);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/notifications/{id}',
        summary: 'Supprimer une notification',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 204, description: 'Notification supprimée')]
    public function delete(int $id): JsonResponse
    {
        $this->notificationService->deleteNotification($id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
