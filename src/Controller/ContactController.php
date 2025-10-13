<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateContactDTO;
use App\Service\ContactService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/contacts', name: 'api_contact_')]
#[OA\Tag(name: 'Contact')]
class ContactController extends AbstractController
{
    public function __construct(
        private readonly ContactService $contactService,
        private readonly RateLimiterFactory $contactLimiter,
    ) {}

    #[Route('/send', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/contacts',
        description: 'Permet à n\'importe qui d\'envoyer un message de contact. Rate limité à 5 messages par heure par IP.',
        summary: 'Envoyer un message de contact (PUBLIC)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CreateContactDTO::class))
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Message envoyé avec succès',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Message envoyé avec succès'),
                new OA\Property(property: 'id', type: 'integer', example: 1),
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Données invalides',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Validation error'),
            ]
        )
    )]
    #[OA\Response(
        response: 429,
        description: 'Trop de requêtes - Rate limit dépassé',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Trop de messages. Réessayez plus tard.'),
            ]
        )
    )]
    public function create(
        #[MapRequestPayload] CreateContactDTO $dto,
        Request $request
    ): JsonResponse {
        // Rate limiting : 5 messages par heure par IP
        $limiter = $this->contactLimiter->create($request->getClientIp());

        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->json(
                ['message' => 'Trop de messages. Réessayez plus tard.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $contact = $this->contactService->createContact($dto);

        return $this->json([
            'message' => 'Message envoyé avec succès',
            'id' => $contact->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        path: '/api/contacts',
        description: 'Récupère la liste complète des messages de contact. Accessible uniquement aux administrateurs.',
        summary: 'Liste de tous les contacts',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Liste des contacts',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 1),
                    new OA\Property(property: 'nom', type: 'string', example: 'Jean Dupont'),
                    new OA\Property(property: 'email', type: 'string', example: 'jean.dupont@example.com'),
                    new OA\Property(property: 'sujet', type: 'string', example: 'Question sur un voyage'),
                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', example: '2025-10-13T10:30:00+00:00'),
                ]
            )
        )
    )]
    #[OA\Response(response: 401, description: 'Non authentifié')]
    #[OA\Response(response: 403, description: 'Accès refusé - ROLE_ADMIN requis')]
    public function list(): JsonResponse
    {
        $contacts = $this->contactService->getAllContacts();

        return $this->json($contacts, Response::HTTP_OK, [], [
            'groups' => ['contact:list']
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        path: '/api/contacts/{id}',
        description: 'Récupère tous les détails d\'un message de contact spécifique. Accessible uniquement aux administrateurs.',
        summary: 'Détails d\'un contact',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'ID du contact',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Response(
        response: 200,
        description: 'Détails du contact',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'nom', type: 'string', example: 'Jean Dupont'),
                new OA\Property(property: 'email', type: 'string', example: 'jean.dupont@example.com'),
                new OA\Property(property: 'sujet', type: 'string', example: 'Question sur un voyage'),
                new OA\Property(property: 'message', type: 'string', example: 'Bonjour, j\'aimerais savoir comment...'),
                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', example: '2025-10-13T10:30:00+00:00'),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Contact non trouvé',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Contact non trouvé'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Non authentifié')]
    #[OA\Response(response: 403, description: 'Accès refusé - ROLE_ADMIN requis')]
    public function show(int $id): JsonResponse
    {
        $contact = $this->contactService->getContact($id);

        if (!$contact) {
            return $this->json(
                ['message' => 'Contact non trouvé'],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json($contact, Response::HTTP_OK, [], [
            'groups' => ['contact:read']
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Delete(
        path: '/api/contacts/{id}',
        description: 'Supprime définitivement un message de contact. Accessible uniquement aux administrateurs.',
        summary: 'Supprimer un contact',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'ID du contact à supprimer',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Response(response: 204, description: 'Contact supprimé avec succès')]
    #[OA\Response(
        response: 404,
        description: 'Contact non trouvé',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Contact non trouvé'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Non authentifié')]
    #[OA\Response(response: 403, description: 'Accès refusé - ROLE_ADMIN requis')]
    public function delete(int $id): JsonResponse
    {
        try {
            $this->contactService->deleteContact($id);
            return $this->json(null, Response::HTTP_NO_CONTENT);
        } catch (\InvalidArgumentException $e) {
            return $this->json(
                ['message' => $e->getMessage()],
                Response::HTTP_NOT_FOUND
            );
        }
    }
}
