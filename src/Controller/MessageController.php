<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\SendMessageDTO;
use App\Service\MessageService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/messages', name: 'api_message_')]
#[OA\Tag(name: 'Messages')]
#[IsGranted('ROLE_USER')]
class MessageController extends AbstractController
{
    public function __construct(
        private readonly MessageService $messageService
    ) {}

    #[Route('', name: 'send', methods: ['POST'])]
    #[OA\Post(
        path: '/api/messages',
        summary: 'Envoyer un message',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: SendMessageDTO::class))
        )
    )]
    #[OA\Response(response: 201, description: 'Message envoyé')]
    #[OA\Response(response: 400, description: 'Données invalides')]
    public function send(
        #[MapRequestPayload] SendMessageDTO $dto
    ): JsonResponse {
        $message = $this->messageService->sendMessage($dto, $this->getUser());

        return $this->json($message, Response::HTTP_CREATED, [], ['groups' => ['message:read']]);
    }

    #[Route('/conversations', name: 'conversations', methods: ['GET'])]
    #[OA\Get(
        path: '/api/messages/conversations',
        summary: 'Liste des conversations',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des conversations')]
    public function conversations(): JsonResponse
    {
        $conversations = $this->messageService->getConversationsList($this->getUser()->getId());

        return $this->json($conversations);
    }

    #[Route('/conversation/{userId}', name: 'conversation', methods: ['GET'])]
    #[OA\Get(
        path: '/api/messages/conversation/{userId}',
        summary: 'Conversation avec un utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'userId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Messages de la conversation')]
    public function conversation(int $userId): JsonResponse
    {
        $messages = $this->messageService->getConversation($this->getUser()->getId(), $userId);

        return $this->json($messages, Response::HTTP_OK, [], ['groups' => ['message:read']]);
    }

    #[Route('/conversation/{userId}/mark-read', name: 'mark_read', methods: ['POST'])]
    #[OA\Post(
        path: '/api/messages/conversation/{userId}/mark-read',
        summary: 'Marquer les messages comme lus',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Messages marqués comme lus')]
    public function markAsRead(int $userId): JsonResponse
    {
        $this->messageService->markAsRead($this->getUser()->getId(), $userId);

        return $this->json(['message' => 'Messages marqués comme lus']);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    #[OA\Get(
        path: '/api/messages/unread-count',
        summary: 'Nombre de messages non lus',
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
        $count = $this->messageService->countUnread($this->getUser()->getId());

        return $this->json(['count' => $count]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('MESSAGE_DELETE', subject: 'id')]
    #[OA\Delete(
        path: '/api/messages/{id}',
        summary: 'Supprimer un message',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 204, description: 'Message supprimé')]
    public function delete(int $id): JsonResponse
    {
        $this->messageService->deleteMessage($id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
