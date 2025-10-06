<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ConversationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/conversations', name: 'api_conversation_')]
#[OA\Tag(name: 'Conversations')]
#[IsGranted('ROLE_USER')]
class ConversationController extends AbstractController
{
    public function __construct(
        private readonly ConversationService $conversationService
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/conversations',
        summary: 'Liste des conversations de l\'utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Liste des conversations',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'participant1', type: 'object'),
                    new OA\Property(property: 'participant2', type: 'object'),
                    new OA\Property(property: 'dernierMessage', type: 'object'),
                    new OA\Property(property: 'messagesNonLus', type: 'integer'),
                    new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time')
                ]
            )
        )
    )]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $conversations = $this->conversationService->getConversationsList($user);

        return $this->json($conversations, Response::HTTP_OK, [], ['groups' => ['conversation:list']]);
    }

    #[Route('/with/{userId}', name: 'with_user', methods: ['GET'])]
    #[OA\Get(
        path: '/api/conversations/with/{userId}',
        summary: 'Récupère ou crée une conversation avec un utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'userId',
        description: 'ID de l\'utilisateur',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Conversation trouvée ou créée')]
    #[OA\Response(response: 400, description: 'Impossible de créer une conversation avec soi-même')]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function withUser(int $userId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $conversation = $this->conversationService->getOrCreateConversationWithUser($userId, $user);

        return $this->json($conversation, Response::HTTP_OK, [], ['groups' => ['conversation:read']]);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    #[OA\Get(
        path: '/api/conversations/unread-count',
        summary: 'Nombre total de messages non lus',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Nombre de messages non lus',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'count', type: 'integer', example: 5)
            ]
        )
    )]
    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $count = $this->conversationService->countUnreadMessages($user);

        return $this->json(['count' => $count]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/conversations/{id}',
        summary: 'Détails d\'une conversation avec ses messages',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'ID de la conversation',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Conversation trouvée')]
    #[OA\Response(response: 403, description: 'Accès refusé')]
    #[OA\Response(response: 404, description: 'Conversation non trouvée')]
    public function show(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $conversation = $this->conversationService->getConversation($id, $user);

        return $this->json($conversation, Response::HTTP_OK, [], ['groups' => ['conversation:read']]);
    }

    #[Route('/{id}/mark-read', name: 'mark_read', methods: ['POST'])]
    #[OA\Post(
        path: '/api/conversations/{id}/mark-read',
        summary: 'Marquer tous les messages d\'une conversation comme lus',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'ID de la conversation',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Messages marqués comme lus',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'count', type: 'integer')
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Accès refusé')]
    #[OA\Response(response: 404, description: 'Conversation non trouvée')]
    public function markAsRead(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $count = $this->conversationService->markConversationAsRead($id, $user);

        return $this->json([
            'message' => 'Messages marqués comme lus',
            'count' => $count
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/conversations/{id}',
        summary: 'Supprimer une conversation',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'ID de la conversation',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 204, description: 'Conversation supprimée')]
    #[OA\Response(response: 403, description: 'Accès refusé')]
    #[OA\Response(response: 404, description: 'Conversation non trouvée')]
    public function delete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->conversationService->deleteConversation($id, $user);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
