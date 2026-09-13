<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Security\Voter\TransactionVoter;
use App\Service\InvoiceService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/transactions', name: 'api_transactions_')]
#[OA\Tag(name: 'Transactions')]
class TransactionController extends AbstractController
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly InvoiceService $invoiceService,
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

    /**
     * Téléchargement de la facture PDF d'une transaction (Lot N4) - générée à la volée
     * au premier appel si elle n'existe pas encore (transaction déjà réussie mais
     * facture pas encore émise), jamais régénérée ensuite (InvoiceService::ensureGenerated).
     */
    #[Route('/{id}/invoice', name: 'invoice', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/transactions/{id}/invoice',
        summary: 'Télécharge la facture PDF d\'une transaction',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Fichier PDF de la facture')]
    #[OA\Response(response: 403, description: 'La transaction n\'appartient pas à l\'appelant')]
    #[OA\Response(response: 404, description: 'Transaction introuvable')]
    public function invoice(int $id): Response
    {
        $transaction = $this->transactionRepository->find($id);

        if ($transaction === null) {
            throw new NotFoundHttpException('Transaction introuvable');
        }

        $this->denyAccessUnlessGranted(TransactionVoter::DOWNLOAD_INVOICE, $transaction);

        $content = $this->invoiceService->getContent($transaction);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                'attachment; filename="%s.pdf"',
                $transaction->getInvoiceNumber() ?? 'facture'
            ),
        ]);
    }
}
