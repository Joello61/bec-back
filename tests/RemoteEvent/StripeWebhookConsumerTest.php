<?php

declare(strict_types=1);

namespace App\Tests\RemoteEvent;

use App\RemoteEvent\StripeWebhookConsumer;
use App\Service\Admin\RefundService;
use App\Service\BoostService;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Aucun test ne couvrait jusqu'ici StripeWebhookConsumer::consume() (le routage par
 * nom d'evenement) - seuls les RequestParser (construction du RemoteEvent depuis la
 * requete HTTP brute) etaient testes. Cible ici uniquement le routage vers
 * RefundService::reconcileExternalRefund() (Partie C point 5), pas les autres
 * branches deja couvertes indirectement via SubscriptionService/BoostService.
 */
class StripeWebhookConsumerTest extends TestCase
{
    private SubscriptionService&\PHPUnit\Framework\MockObject\MockObject $subscriptionService;
    private BoostService&\PHPUnit\Framework\MockObject\MockObject $boostService;
    private RefundService&\PHPUnit\Framework\MockObject\MockObject $refundService;
    private StripeWebhookConsumer $consumer;

    protected function setUp(): void
    {
        $this->subscriptionService = $this->createMock(SubscriptionService::class);
        $this->boostService = $this->createMock(BoostService::class);
        $this->refundService = $this->createMock(RefundService::class);

        $this->consumer = new StripeWebhookConsumer(
            $this->subscriptionService,
            $this->boostService,
            $this->refundService,
            new NullLogger(),
        );
    }

    public function testChargeRefundedReconciliesByPaymentIntent(): void
    {
        $event = new RemoteEvent('charge.refunded', 'evt_1', [
            'data' => [
                'object' => [
                    'id' => 'ch_123',
                    'payment_intent' => 'pi_456',
                ],
            ],
        ]);

        $this->refundService->expects($this->once())
            ->method('reconcileExternalRefund')
            ->with('stripe', 'pi_456');

        $this->consumer->consume($event);
    }

    public function testChargeRefundedWithoutPaymentIntentIsIgnored(): void
    {
        $event = new RemoteEvent('charge.refunded', 'evt_2', [
            'data' => [
                'object' => [
                    'id' => 'ch_123',
                    'payment_intent' => null,
                ],
            ],
        ]);

        $this->refundService->expects($this->never())->method('reconcileExternalRefund');

        $this->consumer->consume($event);
    }

    public function testUnknownEventIsIgnored(): void
    {
        $event = new RemoteEvent('customer.updated', 'evt_3', [
            'data' => ['object' => ['id' => 'cus_1']],
        ]);

        $this->refundService->expects($this->never())->method('reconcileExternalRefund');

        $this->consumer->consume($event);
    }
}
