<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\SubscriptionPlan;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use NotchPay\NotchPay;
use NotchPay\Payment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Notch Pay (Mobile Money Cameroun, Lot 3). Vérifié par recherche documentaire (spike
 * 2026-09-12, developer.notchpay.co) : aucune API de récurrence/abonnement, aucune
 * charge silencieuse possible (chaque paiement, y compris un renouvellement, exige la
 * confirmation active du client sur son téléphone). createCheckoutSession() et
 * createOneTimeCheckoutSession() sont donc strictement équivalents côté Notch Pay -
 * "démarrer un abonnement" n'existe pas, seul un paiement à l'acte existe.
 */
readonly class NotchPayPaymentProvider implements PaymentProviderInterface
{
    private const API_BASE = 'https://api.notchpay.co';

    public function __construct(
        private string $notchPaySecretKey,
        private string $notchPayPrivateKey,
        private HttpClientInterface $httpClient,
    ) {
        NotchPay::setApiKey($this->notchPaySecretKey);
    }

    public function createCheckoutSession(
        User $user,
        SubscriptionPlan $plan,
        string $clientReferenceId,
        ?string $existingProviderCustomerId,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult {
        if ($plan->getPriceAmountXaf() === null) {
            throw new \RuntimeException(sprintf('Le plan "%s" n\'a pas de tarif Mobile Money (XAF) configuré', $plan->getCode()));
        }

        return $this->initializePayment($user, $plan->getName(), $plan->getPriceAmountXaf(), $clientReferenceId, $successUrl);
    }

    public function cancelSubscription(UserSubscription $subscription): void
    {
        // No-op : Notch Pay n'a jamais de ressource recurrente a annuler cote provider
        // (aucune API de subscription/plan existante) - seul l'etat local
        // (cancelAtPeriodEnd) compte, ce qui revient a arreter les rappels de
        // renouvellement (SendRenewalReminderHandler).
    }

    public function createOneTimeCheckoutSession(
        User $user,
        string $productName,
        string $amount,
        string $currency,
        string $clientReferenceId,
        ?string $existingProviderCustomerId,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult {
        return $this->initializePayment($user, $productName, $amount, $clientReferenceId, $successUrl);
    }

    private function initializePayment(User $user, string $description, string $amount, string $reference, string $callbackUrl): CheckoutSessionResult
    {
        // "amount" est l'unite XAF pleine, pas des centimes : confirme par la doc
        // officielle (developer.notchpay.co/sdks/laravel), qui libelle explicitement
        // `'amount' => 5000` comme "Pay 5,000 XAF" - a revalider en sandbox reel avant
        // un premier paiement de production (aucun compte sandbox disponible pour ce lot).
        $params = [
            'amount' => (int) round((float) $amount),
            'currency' => 'XAF',
            'email' => $user->getEmail(),
            'reference' => $reference,
            'description' => $description,
            'callback' => $callbackUrl,
        ];

        $payment = Payment::initialize($params);

        // Payment::initialize() est type array|object par le SDK mais renvoie toujours
        // un objet avec le return_type par defaut ('obj') - jamais surchage ici.
        if (!is_object($payment) || !isset($payment->authorization_url)) {
            throw new \RuntimeException('Réponse Notch Pay inattendue : authorization_url manquant');
        }

        return new CheckoutSessionResult(
            checkoutUrl: $payment->authorization_url,
            providerCustomerId: null,
        );
    }

    /**
     * Remboursement total (Lot 6.1). Aucune classe Refund dans le SDK notchpay-php v2.0
     * installé (vérifié dans vendor/) - appel HTTP direct, conforme à la doc officielle
     * (developer.notchpay.co/accept-payments/refunds) : endpoint POST /refunds, headers
     * Authorization (clé secrète déjà utilisée pour Payment::initialize) + X-Grant (clé
     * privée distincte - non disponible sans compte sandbox réel, cf. NOTCHPAY_PRIVATE_KEY).
     * Montant omis du corps = remboursement total (jamais partiel, cf. interface).
     */
    public function refundTransaction(Transaction $transaction): void
    {
        $response = $this->httpClient->request('POST', self::API_BASE . '/refunds', [
            'headers' => [
                'Authorization' => $this->notchPaySecretKey,
                'X-Grant' => $this->notchPayPrivateKey,
            ],
            'json' => ['payment' => $transaction->getProviderPaymentId()],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Échec du remboursement Notch Pay (HTTP %d) pour le paiement "%s"',
                $statusCode,
                $transaction->getProviderPaymentId()
            ));
        }
    }
}
