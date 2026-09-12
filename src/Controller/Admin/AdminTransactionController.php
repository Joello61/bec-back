<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\Admin\RefundTransactionDTO;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Service\Admin\RefundService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/transactions', name: 'api_admin_transactions_')]
#[OA\Tag(name: 'Admin - Transactions')]
#[IsGranted('ROLE_ADMIN')]
class AdminTransactionController extends AbstractController
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly RefundService $refundService,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(path: '/api/admin/transactions', summary: 'Liste paginée des transactions (admin)', security: [['cookieAuth' => []]])]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'provider', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Liste paginée des transactions')]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = min($request->query->getInt('limit', 20), 50);

        $filters = array_filter([
            'status' => $request->query->get('status'),
            'type' => $request->query->get('type'),
            'provider' => $request->query->get('provider'),
        ], fn ($value) => $value !== null);

        $result = $this->transactionRepository->findAllPaginatedAdmin($page, $limit, $filters);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['admin:transaction:list']]);
    }

    #[Route('/{id}/refund', name: 'refund', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/admin/transactions/{id}/refund',
        summary: 'Rembourser une transaction (total, jamais partiel)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: new Model(type: RefundTransactionDTO::class)))
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Transaction remboursée')]
    #[OA\Response(response: 400, description: 'Transaction introuvable ou non remboursable')]
    public function refund(int $id, #[MapRequestPayload] RefundTransactionDTO $dto): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $transaction = $this->refundService->refund($id, $admin, $dto->reason);

            return $this->json($transaction, Response::HTTP_OK, [], ['groups' => ['admin:transaction:list']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
