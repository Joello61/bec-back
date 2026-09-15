<?php

declare(strict_types=1);

namespace App\RemoteEvent;

use App\Service\Admin\RefundService;
use App\Service\BoostService;
use App\Service\SubscriptionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

#[AsRemoteEventConsumer('stripe')]
final readonly class StripeWebhookConsumer implements ConsumerInterface
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private BoostService $boostService,
        private RefundService $refundService,
        private LoggerInterface $logger,
    ) {}

    public function consume(RemoteEvent $event): void
    {
        $object = $event->getPayload()['data']['object'] ?? null;

        if (!is_array($object)) {
            return;
        }

        match ($event->getName()) {
            // checkout.session.completed est emis pour les deux modes de Checkout Session
            // (abonnement ET boost, monetisation Lot 2) - "mode" est le discriminant natif
            // Stripe, jamais besoin d'inventer un prefixe sur client_reference_id.
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($object),
            'invoice.paid' => $this->subscriptionService->handleInvoicePaid($object),
            'invoice.payment_failed' => $this->subscriptionService->handleInvoicePaymentFailed($object),
            'customer.subscription.updated' => $this->subscriptionService->handleSubscriptionUpdated($object),
            'customer.subscription.deleted' => $this->subscriptionService->handleSubscriptionDeleted($object),
            // Réconciliation d'un remboursement déclenché hors du flux admin (Partie C
            // point 5, plan-complements-monetisation-cobage.md) - le prestataire a déjà
            // remboursé, on reflète seulement l'état. Pas d'équivalent côté Notch Pay,
            // cf. NotchPayWebhookConsumer.
            'charge.refunded' => $this->handleChargeRefunded($object),
            default => $this->logger->debug('Événement Stripe ignoré', ['type' => $event->getName()]),
        };
    }

    /**
     * @param array<string, mixed> $charge
     */
    private function handleChargeRefunded(array $charge): void
    {
        $paymentIntentId = $charge['payment_intent'] ?? null;

        if (!is_string($paymentIntentId)) {
            $this->logger->warning('charge.refunded sans payment_intent exploitable', [
                'charge_id' => $charge['id'] ?? null,
            ]);
            return;
        }

        $this->refundService->reconcileExternalRefund('stripe', $paymentIntentId);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleCheckoutSessionCompleted(array $session): void
    {
        match ($session['mode'] ?? null) {
            'subscription' => $this->subscriptionService->handleCheckoutCompleted($session),
            'payment' => $this->boostService->handleCheckoutCompleted($session),
            default => $this->logger->warning('checkout.session.completed avec un mode inattendu', [
                'mode' => $session['mode'] ?? null,
            ]),
        };
    }
}
