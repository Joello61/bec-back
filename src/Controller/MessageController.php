<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\SendMessageDTO;
use App\Entity\User;
use App\Repository\MessageRepository;
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
        private readonly MessageService $messageService,
        private readonly MessageRepository $messageRepository,
    ) {
    }

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
    #[OA\Response(response: 404, description: 'Destinataire non trouvé')]
    public function send(
        #[MapRequestPayload] SendMessageDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $this->denyAccessUnlessGranted('MESSAGE_SEND');

        $message = $this->messageService->sendMessage($dto, $user);

        return $this->json($message, Response::HTTP_CREATED, [], ['groups' => ['message:read']]);
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
        /** @var User $user */
        $user = $this->getUser();
        $count = $this->messageService->countUnread($user->getId());

        return $this->json(['count' => $count]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Delete(
        path: '/api/messages/{id}',
        summary: 'Supprimer un message',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'ID du message',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 204, description: 'Message supprimé')]
    #[OA\Response(response: 403, description: 'Accès refusé')]
    #[OA\Response(response: 404, description: 'Message non trouvé')]
    public function delete(int $id): JsonResponse
    {

        $message = $this->messageRepository->find($id);

        if (!$message) {
            throw $this->createNotFoundException('Message non trouvé');
        }

        //Vérifier avec le Voter
        $this->denyAccessUnlessGranted('MESSAGE_DELETE', $message);

        /** @var User $user */
        $user = $this->getUser();
        $this->messageService->deleteMessage($id, $user);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
