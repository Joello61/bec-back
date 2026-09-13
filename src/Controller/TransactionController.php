<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\TransactionRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/transactions', name: 'api_transactions_')]
#[OA\Tag(name: 'Transactions')]
class TransactionController extends AbstractController
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
    ) {}

    /**
     * Historique des propres transactions de l'utilisateur (Lot N3, plan-complements-
     * monetisation-cobage.md) - jamais le groupe admin:transaction:list (expose le champ
     * user, hors de propos pour un utilisateur qui consulte ses propres paiements).
     */
    #[Route('/me', name: 'me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/transactions/me',
        summary: 'Liste paginée des propres transactions de l\'utilisateur courant',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: 'Liste paginée des transactions de l\'utilisateur')]
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = $request->query->getInt('page', 1);
        $limit = min($request->query->getInt('limit', 20), 50);

        $result = $this->transactionRepository->findAllPaginatedForUser($user, $page, $limit);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['transaction:read']]);
    }
}
