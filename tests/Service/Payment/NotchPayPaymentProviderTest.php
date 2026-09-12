<?php

declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Entity\SubscriptionPlan;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Service\Payment\NotchPayPaymentProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Lot 6.1 (monétisation) : remboursement Notch Pay, appel HTTP brut (aucune classe Refund
 * dans le SDK notchpay-php v2.0 installé, cf. developer.notchpay.co/accept-payments/refunds).
 * Non testable en conditions réelles (aucun compte sandbox ni clé privée X-Grant disponibles) -
 * ce test vérifie uniquement la construction de la requête et la gestion des statuts HTTP.
 */
class NotchPayPaymentProviderTest extends TestCase
{
    private function transaction(): Transaction
    {
        $transaction = new Transaction();
        $transaction->setType(Transaction::TYPE_BOOST)
            ->setProvider('notchpay')
            ->setProviderPaymentId('trx.notchpay_123')
            ->setPaymentMethodFamily(Transaction::METHOD_FAMILY_MOBILE_MONEY)
            ->setAmount('2000')
            ->setCurrency('XAF')
            ->setStatus(Transaction::STATUS_SUCCEEDED);

        return $transaction;
    }

    public function testRefundTransactionSendsTheExpectedRequest(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.notchpay.co/refunds',
                $this->callback(function (array $options) {
                    return $options['headers']['Authorization'] === 'sb.secret'
                        && $options['headers']['X-Grant'] === 'private_key'
                        && $options['json'] === ['payment' => 'trx.notchpay_123'];
                })
            )
            ->willReturn($response);

        $provider = new NotchPayPaymentProvider('sb.secret', 'private_key', $httpClient);

        $provider->refundTransaction($this->transaction());
    }

    /**
     * Lot 6.3 : seule la garde "prix non configure" (levee avant tout appel SDK) est
     * testable ici - le chemin nominal appelle Payment::initialize() (SDK statique,
     * requete reseau reelle), non mockable via l'injection de dependances, meme
     * limite deja documentee pour le reste du checkout Notch Pay.
     */
    public function testCreateCheckoutSessionThrowsWhenTheYearlyPriceIsNotConfigured(): void
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('plus')->setName('Plus')->setPriceAmountXaf('3000');

        $provider = new NotchPayPaymentProvider('sb.secret', 'private_key', $this->createMock(HttpClientInterface::class));

        $this->expectException(\RuntimeException::class);

        $provider->createCheckoutSession(
            new User(),
            $plan,
            UserSubscription::BILLING_PERIOD_YEARLY,
            '1',
            null,
            'https://ok',
            'https://ko',
        );
    }

    public function testRefundTransactionThrowsOnANonSuccessStatusCode(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $provider = new NotchPayPaymentProvider('sb.secret', 'private_key', $httpClient);

        $this->expectException(\RuntimeException::class);

        $provider->refundTransaction($this->transaction());
    }
}
