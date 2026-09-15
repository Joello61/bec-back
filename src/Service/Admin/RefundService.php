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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Remboursement admin (Lot 6.1) - toujours total, jamais partiel (cf. CGU art. 10.5).
 * Déclenché uniquement par un admin depuis /api/admin/transactions/{id}/refund, jamais
 * en self-service : la décision reste "au cas par cas, manuellement" (CGU), seule
 * l'exécution technique côté prestataire est automatisée une fois la décision prise.
 *
 * reconcileExternalRefund() (Partie C point 5, plan-complements-monetisation-cobage.md)
 * couvre le cas symétrique : un remboursement déclenché directement depuis le
 * dashboard Stripe/Notch Pay, jamais initié par cette classe - le prestataire a déjà
 * remboursé, il ne reste qu'à refléter l'état en base (jamais de second appel
 * PaymentProviderInterface::refundTransaction()).
 */
readonly class RefundService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRepository $transactionRepository,
        #[AutowireLocator('app.payment_provider', indexAttribute: 'key')]
        private ContainerInterface $paymentProviders,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
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

        $this->markRefundedWithSideEffects($transaction);
        $this->entityManager->flush();

        $this->auditLogService->logAdminAction($admin, 'refund_transaction', 'transaction', $transactionId, [
            'reason' => $reason,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'type' => $transaction->getType(),
        ]);

        return $transaction;
    }

    /**
     * Réconciliation d'un remboursement déjà exécuté côté prestataire (webhook Stripe
     * charge.refunded pour l'instant - aucun événement de remboursement n'existe dans
     * l'API webhook Notch Pay documentée au 2026-09-15, cf. NotchPayWebhookConsumer).
     * Recherche d'abord par providerChargeId (le payload ne porte que le payment_intent,
     * jamais l'id stocké comme providerPaymentId pour un abonnement) puis, à défaut,
     * par providerPaymentId (couvre un boost, où les deux valeurs sont identiques).
     * Idempotent : ne lève jamais d'exception, un webhook peut être rejoué à volonté.
     */
    public function reconcileExternalRefund(string $provider, string $providerChargeOrPaymentId): ?Transaction
    {
        $transaction = $this->transactionRepository->findByProviderChargeId($provider, $providerChargeOrPaymentId)
            ?? $this->transactionRepository->findByProviderPaymentId($provider, $providerChargeOrPaymentId);

        if ($transaction === null) {
            $this->logger->warning('Remboursement externe reçu pour une transaction introuvable', [
                'provider' => $provider,
                'providerChargeOrPaymentId' => $providerChargeOrPaymentId,
            ]);
            return null;
        }

        if ($transaction->getStatus() === Transaction::STATUS_REFUNDED) {
            $this->logger->debug('Remboursement externe déjà réconcilié (rejeu idempotent)', [
                'transaction_id' => $transaction->getId(),
            ]);
            return $transaction;
        }

        if ($transaction->getStatus() !== Transaction::STATUS_SUCCEEDED) {
            $this->logger->warning('Remboursement externe reçu pour une transaction dans un état inattendu', [
                'transaction_id' => $transaction->getId(),
                'status' => $transaction->getStatus(),
            ]);
            return $transaction;
        }

        $this->markRefundedWithSideEffects($transaction);
        $this->entityManager->flush();

        $this->logger->info('Remboursement externe réconcilié', [
            'transaction_id' => $transaction->getId(),
            'provider' => $provider,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
        ]);

        return $transaction;
    }

    private function markRefundedWithSideEffects(Transaction $transaction): void
    {
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
