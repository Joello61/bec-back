<?php

declare(strict_types=1);

namespace App\RemoteEvent;

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
        private LoggerInterface $logger,
    ) {}

    public function consume(RemoteEvent $event): void
    {
        $object = $event->getPayload()['data']['object'] ?? null;

        if (!is_array($object)) {
            return;
        }

        match ($event->getName()) {
            'checkout.session.completed' => $this->subscriptionService->handleCheckoutCompleted($object),
            'invoice.paid' => $this->subscriptionService->handleInvoicePaid($object),
            'invoice.payment_failed' => $this->subscriptionService->handleInvoicePaymentFailed($object),
            'customer.subscription.updated' => $this->subscriptionService->handleSubscriptionUpdated($object),
            'customer.subscription.deleted' => $this->subscriptionService->handleSubscriptionDeleted($object),
            default => $this->logger->debug('Événement Stripe ignoré', ['type' => $event->getName()]),
        };
    }
}
