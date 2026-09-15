<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Boost;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Point d'idempotence central des webhooks de paiement. UNIQUE(provider,
 * provider_payment_id) en base est la garantie ultime (retries at-least-once
 * documentes cote Stripe et Notch Pay) - la verification prealable en lecture est une
 * optimisation, pas la seule protection.
 */
readonly class PaymentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRepository $transactionRepository,
        private InvoiceService $invoiceService,
        private EmailService $emailService,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $rawPayload
     */
    public function findOrCreateFromProviderEvent(
        string $provider,
        string $providerPaymentId,
        User $user,
        ?UserSubscription $subscription,
        string $type,
        string $paymentMethodFamily,
        string $amount,
        string $currency,
        string $status,
        array $rawPayload,
        ?Boost $boost = null,
        ?string $providerChargeId = null,
    ): Transaction {
        $existing = $this->transactionRepository->findByProviderPaymentId($provider, $providerPaymentId);

        if ($existing !== null) {
            $this->logger->info('Transaction deja traitee (idempotence webhook)', [
                'provider' => $provider,
                'providerPaymentId' => $providerPaymentId,
            ]);

            return $existing;
        }

        try {
            $transaction = $this->entityManager->wrapInTransaction(
                function () use (
                    $provider,
                    $providerPaymentId,
                    $user,
                    $subscription,
                    $boost,
                    $type,
                    $paymentMethodFamily,
                    $amount,
                    $currency,
                    $status,
                    $rawPayload,
                    $providerChargeId,
                ): Transaction {
                    $transaction = new Transaction();
                    $transaction->setProvider($provider)
                        ->setProviderPaymentId($providerPaymentId)
                        ->setProviderChargeId($providerChargeId)
                        ->setUser($user)
                        ->setSubscription($subscription)
                        ->setBoost($boost)
                        ->setType($type)
                        ->setPaymentMethodFamily($paymentMethodFamily)
                        ->setAmount($amount)
                        ->setCurrency($currency)
                        ->setStatus($status)
                        ->setRawPayload($rawPayload);

                    $this->entityManager->persist($transaction);
                    $this->entityManager->flush();

                    return $transaction;
                }
            );
        } catch (UniqueConstraintViolationException) {
            // Course concurrente avec un autre retry du meme webhook : la ligne existe
            // desormais, on la relit plutot que de propager l'erreur - jamais de nouvel
            // envoi d'email ici, ce n'est pas une creation reelle.
            $existing = $this->transactionRepository->findByProviderPaymentId($provider, $providerPaymentId);

            if ($existing === null) {
                throw new \RuntimeException(
                    "Transaction introuvable après conflit d'unicité ($provider, $providerPaymentId)"
                );
            }

            return $existing;
        }

        // Email de facture (Lot N5) - uniquement sur une creation reelle (jamais sur les
        // deux retours anticipes ci-dessus, qui correspondent a un retry idempotent) et
        // uniquement si le paiement a reussi. Toujours apres le retour de
        // wrapInTransaction : un echec d'envoi ne doit jamais faire echouer/annuler
        // l'ecriture en base, deja commitee a ce stade.
        if ($status === Transaction::STATUS_SUCCEEDED) {
            $this->sendPaymentReceiptEmailSafely($transaction);
        }

        return $transaction;
    }

    private function sendPaymentReceiptEmailSafely(Transaction $transaction): void
    {
        try {
            $pdfContent = $this->invoiceService->getContent($transaction);
            $this->emailService->sendPaymentReceiptEmail($transaction->getUser(), $transaction, $pdfContent);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'envoi de la facture par email', [
                'transaction_id' => $transaction->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
