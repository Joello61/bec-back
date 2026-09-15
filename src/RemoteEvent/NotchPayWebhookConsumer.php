<?php

declare(strict_types=1);

namespace App\RemoteEvent;

use App\Service\BoostService;
use App\Service\SubscriptionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Contrairement a Stripe (session.mode distingue nativement abonnement/boost), Notch
 * Pay n'a qu'un seul type de paiement : on tente la resolution cote abonnement puis
 * cote boost (chaque service ignore silencieusement une reference qu'il ne reconnait
 * pas, cf. findSubscriptionFromClientReference/findBoostFromClientReference).
 *
 * Aucune reconciliation de remboursement externe ici (contrairement a
 * StripeWebhookConsumer::handleChargeRefunded()) : verifie contre la documentation
 * officielle Notch Pay (developer.notchpay.co/get-started/webhooks et
 * /api-reference/webhooks) le 2026-09-15, aucun evenement de remboursement n'y est
 * documente (seulement payment.created/complete/failed/canceled/expired) - absence
 * confirmee, pas un oubli. A revoir si Notch Pay documente un jour un tel evenement
 * (Partie C point 5, plan-complements-monetisation-cobage.md).
 */
#[AsRemoteEventConsumer('notchpay')]
final readonly class NotchPayWebhookConsumer implements ConsumerInterface
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private BoostService $boostService,
        private LoggerInterface $logger,
    ) {}

    public function consume(RemoteEvent $event): void
    {
        $payment = $event->getPayload();

        match ($event->getName()) {
            'payment.complete' => $this->handleCompleted($payment),
            'payment.failed' => $this->subscriptionService->handleNotchPayPaymentFailed($payment),
            default => $this->logger->debug('Événement Notch Pay ignoré', ['type' => $event->getName()]),
        };
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function handleCompleted(array $payment): void
    {
        $this->subscriptionService->handleNotchPayPaymentCompleted($payment);
        $this->boostService->handleNotchPayPaymentCompleted($payment);
    }
}
