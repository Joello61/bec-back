<?php

declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Entity\SubscriptionPlan;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Service\Payment\StripePaymentProvider;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\InvoiceService;
use Stripe\Service\RefundService;
use Stripe\StripeClient;

/**
 * Lot 6.1 (monétisation) : résolution du payment_intent à rembourser. Invoice::$payment_intent
 * n'existe plus depuis la version d'API Stripe 2025-03-31 (vérifié sur docs.stripe.com/changelog) -
 * remplacé par Invoice::$payments (liste d'InvoicePayment), dont l'entrée is_default=true porte
 * le payment_intent réel. Un boost (paiement one-time) porte directement le payment_intent, sans
 * passer par une Invoice.
 */
class StripePaymentProviderTest extends TestCase
{
    private StripeClient&\PHPUnit\Framework\MockObject\MockObject $stripeClient;
    private RefundService&\PHPUnit\Framework\MockObject\MockObject $refundService;
    private StripePaymentProvider $provider;

    protected function setUp(): void
    {
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->refundService = $this->createMock(RefundService::class);

        $this->provider = new StripePaymentProvider($this->stripeClient);
    }

    private function transaction(string $type, string $providerPaymentId): Transaction
    {
        $transaction = new Transaction();
        $transaction->setType($type)
            ->setProvider('stripe')
            ->setProviderPaymentId($providerPaymentId)
            ->setPaymentMethodFamily(Transaction::METHOD_FAMILY_CARD)
            ->setAmount('10.00')
            ->setCurrency('EUR')
            ->setStatus(Transaction::STATUS_SUCCEEDED);

        return $transaction;
    }

    public function testRefundTransactionRefundsDirectlyForABoost(): void
    {
        $transaction = $this->transaction(Transaction::TYPE_BOOST, 'pi_boost_123');

        $this->stripeClient->method('__get')->willReturnMap([
            ['refunds', $this->refundService],
        ]);

        $this->refundService->expects($this->once())
            ->method('create')
            ->with(['payment_intent' => 'pi_boost_123']);

        $this->provider->refundTransaction($transaction);
    }

    public function testRefundTransactionResolvesTheDefaultInvoicePaymentIntentForASubscription(): void
    {
        $transaction = $this->transaction(Transaction::TYPE_SUBSCRIPTION_INITIAL, 'in_sub_123');

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoice = Invoice::constructFrom([
            'id' => 'in_sub_123',
            'payments' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'inpay_1',
                        'object' => 'invoice_payment',
                        'is_default' => false,
                        'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_ignored'],
                    ],
                    [
                        'id' => 'inpay_2',
                        'object' => 'invoice_payment',
                        'is_default' => true,
                        'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_sub_456'],
                    ],
                ],
            ],
        ]);
        $invoiceService->method('retrieve')->with('in_sub_123')->willReturn($invoice);

        $this->stripeClient->method('__get')->willReturnMap([
            ['invoices', $invoiceService],
            ['refunds', $this->refundService],
        ]);

        $this->refundService->expects($this->once())
            ->method('create')
            ->with(['payment_intent' => 'pi_sub_456']);

        $this->provider->refundTransaction($transaction);
    }

    private function plan(): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('plus')->setName('Plus')->setStripePriceId('price_monthly')->setStripePriceIdYearly('price_yearly');

        return $plan;
    }

    // ==================== createCheckoutSession (Lot 6.3) ====================

    public function testCreateCheckoutSessionUsesTheMonthlyPriceByDefault(): void
    {
        $sessionService = $this->createMock(SessionService::class);
        $checkoutFactory = $this->createMock(CheckoutServiceFactory::class);
        $checkoutFactory->method('__get')->willReturnMap([['sessions', $sessionService]]);
        $this->stripeClient->method('__get')->willReturnMap([['checkout', $checkoutFactory]]);

        $sessionService->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $params) => $params['line_items'][0]['price'] === 'price_monthly'))
            ->willReturn(Session::constructFrom(['url' => 'https://checkout.stripe.com/monthly', 'customer' => null]));

        $result = $this->provider->createCheckoutSession(
            new User(),
            $this->plan(),
            UserSubscription::BILLING_PERIOD_MONTHLY,
            '1',
            null,
            'https://ok',
            'https://ko',
        );

        self::assertSame('https://checkout.stripe.com/monthly', $result->checkoutUrl);
    }

    public function testCreateCheckoutSessionUsesTheYearlyPriceWhenRequested(): void
    {
        $sessionService = $this->createMock(SessionService::class);
        $checkoutFactory = $this->createMock(CheckoutServiceFactory::class);
        $checkoutFactory->method('__get')->willReturnMap([['sessions', $sessionService]]);
        $this->stripeClient->method('__get')->willReturnMap([['checkout', $checkoutFactory]]);

        $sessionService->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $params) => $params['line_items'][0]['price'] === 'price_yearly'))
            ->willReturn(Session::constructFrom(['url' => 'https://checkout.stripe.com/yearly', 'customer' => null]));

        $result = $this->provider->createCheckoutSession(
            new User(),
            $this->plan(),
            UserSubscription::BILLING_PERIOD_YEARLY,
            '1',
            null,
            'https://ok',
            'https://ko',
        );

        self::assertSame('https://checkout.stripe.com/yearly', $result->checkoutUrl);
    }

    public function testCreateCheckoutSessionThrowsWhenTheYearlyPriceIsNotConfigured(): void
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('plus')->setName('Plus')->setStripePriceId('price_monthly');

        $this->expectException(\RuntimeException::class);

        $this->provider->createCheckoutSession(
            new User(),
            $plan,
            UserSubscription::BILLING_PERIOD_YEARLY,
            '1',
            null,
            'https://ok',
            'https://ko',
        );
    }

    public function testRefundTransactionThrowsWhenTheInvoiceHasNoDefaultPayment(): void
    {
        $transaction = $this->transaction(Transaction::TYPE_SUBSCRIPTION_RENEWAL, 'in_sub_789');

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoice = Invoice::constructFrom([
            'id' => 'in_sub_789',
            'payments' => ['object' => 'list', 'data' => []],
        ]);
        $invoiceService->method('retrieve')->willReturn($invoice);

        $this->stripeClient->method('__get')->willReturnMap([
            ['invoices', $invoiceService],
        ]);

        $this->expectException(\RuntimeException::class);

        $this->provider->refundTransaction($transaction);
    }
}
