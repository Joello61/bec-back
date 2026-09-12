<?php

declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Entity\Transaction;
use App\Service\Payment\StripePaymentProvider;
use PHPUnit\Framework\TestCase;
use Stripe\Invoice;
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
