<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateSignalementDTO;
use App\Entity\Signalement;
use App\Entity\User;
use App\Repository\DemandeRepository;
use App\Repository\MessageRepository;
use App\Repository\SignalementRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;
use App\Service\SignalementService;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/signalements', name: 'api_signalement_')]
#[OA\Tag(name: 'Signalements')]
class SignalementController extends AbstractController
{
    public function __construct(
        private readonly SignalementService $signalementService,
    ) {}

    #[Route('/me', name: 'me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/signalements/me',
        summary: 'Liste des signalements (Admin)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10))]
    #[OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Liste paginée')]
    public function me(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $statut = $request->query->get('statut');

        /* @var User $currentUser*/
        $currentUser = $this->getUser();

        $result = $this->signalementService->getUserSignalements($currentUser, $page, $limit, $statut);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['signalement:list']]);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        path: '/api/signalements',
        summary: 'Liste des signalements (Admin)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10))]
    #[OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Liste paginée')]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $statut = $request->query->get('statut');

        $result = $this->signalementService->getAllSignalements($page, $limit, $statut);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['signalement:list']]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/signalements',
        summary: 'Créer un signalement',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CreateSignalementDTO::class))
        )
    )]
    #[OA\Response(response: 201, description: 'Signalement créé')]
    public function create(
        #[MapRequestPayload] CreateSignalementDTO $dto
    ): JsonResponse {
        if (!$dto->voyageId && !$dto->demandeId && !$dto->messageId && !$dto->utilisateurSignaleId) {
            return $this->json(
                ['message' => 'Vous devez signaler soit un voyage, soit une demande, soit un message'],
                Response::HTTP_BAD_REQUEST
            );
        }

        /* @var User $currentUser*/
        $currentUser = $this->getUser();

        $this->denyAccessUnlessGranted('SIGNALEMENT_CREATE');

        $signalement = $this->signalementService->createSignalement($dto, $currentUser);

        return $this->json($signalement, Response::HTTP_CREATED, [], ['groups' => ['signalement:read']]);
    }

    #[Route('/{id}/traiter', name: 'process', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Patch(
        path: '/api/signalements/{id}/traiter',
        summary: 'Traiter un signalement (Admin)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'statut', type: 'string', enum: ['traite', 'rejete']),
                    new OA\Property(property: 'reponseAdmin', type: 'string')
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Signalement traité')]
    public function process(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $statut = $data['statut'] ?? null;
        $reponseAdmin = $data['reponseAdmin'] ?? null;

        if (!$statut || !in_array($statut, ['traite', 'rejete'], true)) {
            throw new BadRequestHttpException('Statut invalide. Valeurs possibles : traite ou rejete.');
        }

        $signalement = $this->signalementService->processSignalement($id, $statut, $reponseAdmin);

        return $this->json(
            $signalement,
            Response::HTTP_OK,
            [],
            ['groups' => ['signalement:read']]
        );
    }

    #[Route('/pending-count', name: 'pending_count', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        path: '/api/signalements/pending-count',
        summary: 'Nombre de signalements en attente(Admin)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Nombre',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'count', type: 'integer', example: 12)
            ]
        )
    )]
    public function pendingCount(): JsonResponse
    {
        $count = $this->signalementService->countPending();

        return $this->json(['count' => $count]);
    }
}
