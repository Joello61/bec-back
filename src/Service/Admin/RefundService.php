<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Boost;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\TransactionRepository;
use App\Service\Payment\PaymentProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Remboursement admin (Lot 6.1) - toujours total, jamais partiel (cf. CGU art. 10.5).
 * Déclenché uniquement par un admin depuis /api/admin/transactions/{id}/refund, jamais
 * en self-service : la décision reste "au cas par cas, manuellement" (CGU), seule
 * l'exécution technique côté prestataire est automatisée une fois la décision prise.
 */
readonly class RefundService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRepository $transactionRepository,
        #[AutowireLocator('app.payment_provider', indexAttribute: 'key')]
        private ContainerInterface $paymentProviders,
        private AuditLogService $auditLogService,
    ) {}

    public function refund(int $transactionId, User $admin, ?string $reason = null): Transaction
    {
        $transaction = $this->transactionRepository->find($transactionId);

        if ($transaction === null) {
            throw new \InvalidArgumentException('Transaction introuvable');
        }

        if ($transaction->getStatus() !== Transaction::STATUS_SUCCEEDED) {
            throw new \InvalidArgumentException(sprintf(
                'Seule une transaction "%s" peut être remboursée (statut actuel : "%s")',
                Transaction::STATUS_SUCCEEDED,
                $transaction->getStatus()
            ));
        }

        $provider = $this->resolveProvider($transaction->getPaymentMethodFamily());
        $provider->refundTransaction($transaction);

        $transaction->setStatus(Transaction::STATUS_REFUNDED);
        $transaction->setRefundedAt(new \DateTimeImmutable());

        $subscription = $transaction->getSubscription();
        if ($subscription !== null) {
            $subscription->setStatus(UserSubscription::STATUS_CANCELED);
        }

        $boost = $transaction->getBoost();
        if ($boost !== null) {
            $boost->setStatus(Boost::STATUS_EXPIRED);
            $boost->setEndAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();

        $this->auditLogService->logAdminAction($admin, 'refund_transaction', 'transaction', $transactionId, [
            'reason' => $reason,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'type' => $transaction->getType(),
        ]);

        return $transaction;
    }

    private function resolveProvider(string $paymentMethodFamily): PaymentProviderInterface
    {
        if (!$this->paymentProviders->has($paymentMethodFamily)) {
            throw new \InvalidArgumentException('Moyen de paiement invalide');
        }

        /** @var PaymentProviderInterface $provider */
        $provider = $this->paymentProviders->get($paymentMethodFamily);

        return $provider;
    }
}
