<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\Admin\CreateSubscriptionPlanDTO;
use App\DTO\Admin\UpdateSubscriptionPlanDTO;
use App\Entity\User;
use App\Repository\SubscriptionPlanRepository;
use App\Service\Admin\CatalogAdminService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/subscription-plans', name: 'api_admin_subscription_plans_')]
#[OA\Tag(name: 'Admin - Catalogue')]
#[IsGranted('ROLE_ADMIN')]
class AdminSubscriptionPlanController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionPlanRepository $subscriptionPlanRepository,
        private readonly CatalogAdminService $catalogAdminService,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(path: '/api/admin/subscription-plans', summary: 'Catalogue complet des plans (admin)', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Liste des plans')]
    public function list(): JsonResponse
    {
        $plans = $this->subscriptionPlanRepository->findAllForAdmin();

        return $this->json($plans, Response::HTTP_OK, [], ['groups' => ['admin:subscription_plan:read']]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/admin/subscription-plans',
        summary: 'Créer un plan d\'abonnement',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateSubscriptionPlanDTO::class)))
    )]
    #[OA\Response(response: 201, description: 'Plan créé')]
    #[OA\Response(response: 400, description: 'Code déjà utilisé')]
    public function create(#[MapRequestPayload] CreateSubscriptionPlanDTO $dto): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $plan = $this->catalogAdminService->createSubscriptionPlan($dto, $admin);

            return $this->json($plan, Response::HTTP_CREATED, [], ['groups' => ['admin:subscription_plan:read']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[OA\Put(
        path: '/api/admin/subscription-plans/{id}',
        summary: 'Modifier un plan d\'abonnement',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateSubscriptionPlanDTO::class)))
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Plan mis à jour')]
    #[OA\Response(response: 400, description: 'Plan introuvable')]
    public function update(int $id, #[MapRequestPayload] UpdateSubscriptionPlanDTO $dto): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $plan = $this->catalogAdminService->updateSubscriptionPlan($id, $dto, $admin);

            return $this->json($plan, Response::HTTP_OK, [], ['groups' => ['admin:subscription_plan:read']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/admin/subscription-plans/{id}',
        summary: 'Supprimer un plan d\'abonnement (soft-delete)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Plan supprimé')]
    #[OA\Response(response: 400, description: 'Plan introuvable ou suppression refusée (plan gratuit)')]
    public function delete(int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $this->catalogAdminService->deleteSubscriptionPlan($id, $admin);

            return $this->json(['success' => true, 'message' => 'Plan supprimé avec succès'], Response::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
