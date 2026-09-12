<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SubscriptionPlan;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\UserSubscriptionRepository;
use App\Service\Payment\CheckoutSessionResult;
use App\Service\Payment\PaymentProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class SubscriptionService
{
    private const FREE_PLAN_CODE = 'free';

    public const PAYMENT_METHOD_CARD = 'card';
    public const PAYMENT_METHOD_MOBILE_MONEY = 'mobile_money';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubscriptionPlanRepository $subscriptionPlanRepository,
        private UserSubscriptionRepository $userSubscriptionRepository,
        #[AutowireLocator('app.payment_provider', indexAttribute: 'key')]
        private ContainerInterface $paymentProviders,
        private PaymentService $paymentService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Resout le provider a partir de la famille de paiement choisie au checkout.
     */
    private function resolveProvider(string $paymentMethod): PaymentProviderInterface
    {
        if (!$this->paymentProviders->has($paymentMethod)) {
            throw new BadRequestHttpException('Moyen de paiement invalide');
        }

        /** @var PaymentProviderInterface $provider */
        $provider = $this->paymentProviders->get($paymentMethod);

        return $provider;
    }

    private function providerNameFor(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            self::PAYMENT_METHOD_MOBILE_MONEY => UserSubscription::PROVIDER_NOTCHPAY,
            default => UserSubscription::PROVIDER_STRIPE,
        };
    }

    /**
     * Famille de paiement ('card'/'mobile_money') a partir du provider stocke - utilise
     * pour resoudre a nouveau le bon provider lors d'une resiliation (le paiement
     * d'origine n'est pas rejoue a ce moment-la).
     */
    private function paymentMethodFor(UserSubscription $subscription): string
    {
        return $subscription->getProvider() === UserSubscription::PROVIDER_NOTCHPAY
            ? self::PAYMENT_METHOD_MOBILE_MONEY
            : self::PAYMENT_METHOD_CARD;
    }

    public function getActiveSubscription(User $user): ?UserSubscription
    {
        return $this->userSubscriptionRepository->findActiveForUser($user);
    }

    /**
     * Plan effectif de l'utilisateur : celui de son abonnement actif, sinon le plan
     * gratuit par defaut. Point d'extension utilise par VoyageVoter/DemandeVoter pour
     * le quota freemium.
     */
    public function getEffectivePlan(User $user): SubscriptionPlan
    {
        $activeSubscription = $this->getActiveSubscription($user);

        if ($activeSubscription !== null) {
            return $activeSubscription->getPlan();
        }

        $freePlan = $this->subscriptionPlanRepository->findByCode(self::FREE_PLAN_CODE);

        if ($freePlan === null) {
            throw new \RuntimeException('Plan gratuit introuvable - la base n\'a pas été seedée (app:seed:subscription-plans)');
        }

        return $freePlan;
    }

    public function checkout(User $user, string $planCode, string $paymentMethod, string $successUrl, string $cancelUrl): CheckoutSessionResult
    {
        $plan = $this->subscriptionPlanRepository->findByCode($planCode);

        if ($plan === null || !$plan->isActive()) {
            throw new NotFoundHttpException('Plan d\'abonnement introuvable');
        }

        if ($plan->getCode() === self::FREE_PLAN_CODE) {
            throw new BadRequestHttpException('Le plan gratuit ne nécessite pas de paiement');
        }

        if ($this->getActiveSubscription($user) !== null) {
            throw new BadRequestHttpException('Un abonnement actif existe déjà - résiliez-le avant d\'en souscrire un nouveau');
        }

        $provider = $this->resolveProvider($paymentMethod);
        $providerName = $this->providerNameFor($paymentMethod);
        $isMobileMoney = $paymentMethod === self::PAYMENT_METHOD_MOBILE_MONEY;
        $amount = $isMobileMoney ? $plan->getPriceAmountXaf() : $plan->getPriceAmountEur();
        $currency = $isMobileMoney ? 'XAF' : 'EUR';

        if ($amount === null) {
            throw new BadRequestHttpException(sprintf(
                'Le plan "%s" n\'a pas de tarif configuré pour ce moyen de paiement',
                $plan->getCode()
            ));
        }

        $subscription = new UserSubscription();
        $subscription->setUser($user)
            ->setPlan($plan)
            ->setStatus(UserSubscription::STATUS_INCOMPLETE)
            ->setProvider($providerName)
            ->setAmount($amount)
            ->setCurrency($currency)
            ->setWithdrawalWaiverConsentedAt(new \DateTime());

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $existingProviderCustomerId = $this->userSubscriptionRepository->findLatestProviderCustomerId(
            $user,
            $providerName
        );

        $result = $provider->createCheckoutSession(
            $user,
            $plan,
            (string) $subscription->getId(),
            $existingProviderCustomerId,
            $successUrl,
            $cancelUrl,
        );

        if ($result->providerCustomerId !== null) {
            $subscription->setProviderCustomerId($result->providerCustomerId);
            $this->entityManager->flush();
        }

        return $result;
    }

    public function cancelSubscription(User $user): void
    {
        $subscription = $this->getActiveSubscription($user);

        if ($subscription === null) {
            throw new NotFoundHttpException('Aucun abonnement actif à résilier');
        }

        $this->resolveProvider($this->paymentMethodFor($subscription))->cancelSubscription($subscription);

        $subscription->setCancelAtPeriodEnd(true);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $session Objet Stripe Checkout Session (event.data.object)
     */
    public function handleCheckoutCompleted(array $session): void
    {
        $subscription = $this->findSubscriptionFromClientReference($session['client_reference_id'] ?? null);

        if ($subscription === null) {
            return;
        }

        $providerSubscriptionId = $session['subscription'] ?? null;
        $providerCustomerId = $session['customer'] ?? null;

        if (is_string($providerSubscriptionId)) {
            $subscription->setProviderSubscriptionId($providerSubscriptionId);
        }

        if (is_string($providerCustomerId)) {
            $subscription->setProviderCustomerId($providerCustomerId);
        }

        $subscription->setStatus(UserSubscription::STATUS_ACTIVE);
        $this->entityManager->flush();

        $this->logger->info('Abonnement activé via checkout.session.completed', [
            'userSubscriptionId' => $subscription->getId(),
        ]);
    }

    /**
     * @param array<string, mixed> $invoice Objet Stripe Invoice (event.data.object)
     */
    public function handleInvoicePaid(array $invoice): void
    {
        $providerSubscriptionId = $invoice['subscription'] ?? null;

        if (!is_string($providerSubscriptionId)) {
            return;
        }

        $subscription = $this->userSubscriptionRepository->findByProviderSubscriptionId(
            UserSubscription::PROVIDER_STRIPE,
            $providerSubscriptionId
        );

        if ($subscription === null) {
            $this->logger->warning('invoice.paid reçu pour un abonnement inconnu', [
                'providerSubscriptionId' => $providerSubscriptionId,
            ]);
            return;
        }

        $period = $invoice['lines']['data'][0]['period'] ?? null;

        if (is_array($period)) {
            if (isset($period['start'])) {
                $subscription->setCurrentPeriodStart((new \DateTime())->setTimestamp((int) $period['start']));
            }
            if (isset($period['end'])) {
                $subscription->setCurrentPeriodEnd((new \DateTime())->setTimestamp((int) $period['end']));
            }
        }

        $subscription->setStatus(UserSubscription::STATUS_ACTIVE);
        $this->entityManager->flush();

        $type = ($invoice['billing_reason'] ?? null) === 'subscription_create'
            ? Transaction::TYPE_SUBSCRIPTION_INITIAL
            : Transaction::TYPE_SUBSCRIPTION_RENEWAL;

        $this->paymentService->findOrCreateFromProviderEvent(
            provider: UserSubscription::PROVIDER_STRIPE,
            providerPaymentId: (string) $invoice['id'],
            user: $subscription->getUser(),
            subscription: $subscription,
            type: $type,
            paymentMethodFamily: Transaction::METHOD_FAMILY_CARD,
            amount: number_format((float) ($invoice['amount_paid'] ?? 0) / 100, 2, '.', ''),
            currency: strtoupper((string) ($invoice['currency'] ?? 'eur')),
            status: Transaction::STATUS_SUCCEEDED,
            rawPayload: ['stripe_event' => 'invoice.paid', 'invoice_id' => $invoice['id'] ?? null, 'billing_reason' => $invoice['billing_reason'] ?? null],
        );
    }

    /**
     * @param array<string, mixed> $invoice Objet Stripe Invoice (event.data.object)
     */
    public function handleInvoicePaymentFailed(array $invoice): void
    {
        $providerSubscriptionId = $invoice['subscription'] ?? null;

        if (!is_string($providerSubscriptionId)) {
            return;
        }

        $subscription = $this->userSubscriptionRepository->findByProviderSubscriptionId(
            UserSubscription::PROVIDER_STRIPE,
            $providerSubscriptionId
        );

        if ($subscription === null) {
            return;
        }

        $subscription->setStatus(UserSubscription::STATUS_PAST_DUE);
        $this->entityManager->flush();

        $this->paymentService->findOrCreateFromProviderEvent(
            provider: UserSubscription::PROVIDER_STRIPE,
            providerPaymentId: (string) $invoice['id'],
            user: $subscription->getUser(),
            subscription: $subscription,
            type: Transaction::TYPE_SUBSCRIPTION_RENEWAL,
            paymentMethodFamily: Transaction::METHOD_FAMILY_CARD,
            amount: number_format((float) ($invoice['amount_due'] ?? 0) / 100, 2, '.', ''),
            currency: strtoupper((string) ($invoice['currency'] ?? 'eur')),
            status: Transaction::STATUS_FAILED,
            rawPayload: ['stripe_event' => 'invoice.payment_failed', 'invoice_id' => $invoice['id'] ?? null],
        );
    }

    /**
     * @param array<string, mixed> $stripeSubscription Objet Stripe Subscription (event.data.object)
     */
    public function handleSubscriptionUpdated(array $stripeSubscription): void
    {
        $subscription = $this->userSubscriptionRepository->findByProviderSubscriptionId(
            UserSubscription::PROVIDER_STRIPE,
            (string) ($stripeSubscription['id'] ?? '')
        );

        if ($subscription === null) {
            return;
        }

        $subscription->setStatus($this->mapStripeStatus((string) ($stripeSubscription['status'] ?? '')));
        $subscription->setCancelAtPeriodEnd((bool) ($stripeSubscription['cancel_at_period_end'] ?? false));

        if (isset($stripeSubscription['current_period_start'])) {
            $subscription->setCurrentPeriodStart((new \DateTime())->setTimestamp((int) $stripeSubscription['current_period_start']));
        }
        if (isset($stripeSubscription['current_period_end'])) {
            $subscription->setCurrentPeriodEnd((new \DateTime())->setTimestamp((int) $stripeSubscription['current_period_end']));
        }

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $stripeSubscription Objet Stripe Subscription (event.data.object)
     */
    public function handleSubscriptionDeleted(array $stripeSubscription): void
    {
        $subscription = $this->userSubscriptionRepository->findByProviderSubscriptionId(
            UserSubscription::PROVIDER_STRIPE,
            (string) ($stripeSubscription['id'] ?? '')
        );

        if ($subscription === null) {
            return;
        }

        $subscription->setStatus(UserSubscription::STATUS_CANCELED);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payment Objet Payment Notch Pay (payment.complete)
     */
    public function handleNotchPayPaymentCompleted(array $payment): void
    {
        $subscription = $this->findSubscriptionFromClientReference($payment['reference'] ?? null);

        if ($subscription === null) {
            return;
        }

        $now = new \DateTime();
        $isFirstActivation = $subscription->getStatus() !== UserSubscription::STATUS_ACTIVE;

        $subscription->setStatus(UserSubscription::STATUS_ACTIVE)
            ->setCurrentPeriodStart($now)
            ->setCurrentPeriodEnd((clone $now)->modify('+1 month'));
        $this->entityManager->flush();

        $this->paymentService->findOrCreateFromProviderEvent(
            provider: UserSubscription::PROVIDER_NOTCHPAY,
            providerPaymentId: (string) ($payment['id'] ?? $payment['reference'] ?? ''),
            user: $subscription->getUser(),
            subscription: $subscription,
            type: $isFirstActivation ? Transaction::TYPE_SUBSCRIPTION_INITIAL : Transaction::TYPE_SUBSCRIPTION_RENEWAL,
            paymentMethodFamily: Transaction::METHOD_FAMILY_MOBILE_MONEY,
            amount: number_format((float) ($payment['amount'] ?? 0), 2, '.', ''),
            currency: strtoupper((string) ($payment['currency'] ?? 'xaf')),
            status: Transaction::STATUS_SUCCEEDED,
            rawPayload: ['notchpay_event' => 'payment.complete', 'payment_reference' => $payment['reference'] ?? null],
        );

        $this->logger->info('Abonnement Mobile Money activé/renouvelé via payment.complete', [
            'userSubscriptionId' => $subscription->getId(),
        ]);
    }

    /**
     * @param array<string, mixed> $payment Objet Payment Notch Pay (payment.failed)
     */
    public function handleNotchPayPaymentFailed(array $payment): void
    {
        $subscription = $this->findSubscriptionFromClientReference($payment['reference'] ?? null);

        if ($subscription === null) {
            return;
        }

        $subscription->setStatus(UserSubscription::STATUS_PAST_DUE);
        $this->entityManager->flush();

        $this->paymentService->findOrCreateFromProviderEvent(
            provider: UserSubscription::PROVIDER_NOTCHPAY,
            providerPaymentId: (string) ($payment['id'] ?? $payment['reference'] ?? ''),
            user: $subscription->getUser(),
            subscription: $subscription,
            type: Transaction::TYPE_SUBSCRIPTION_RENEWAL,
            paymentMethodFamily: Transaction::METHOD_FAMILY_MOBILE_MONEY,
            amount: number_format((float) ($payment['amount'] ?? 0), 2, '.', ''),
            currency: strtoupper((string) ($payment['currency'] ?? 'xaf')),
            status: Transaction::STATUS_FAILED,
            rawPayload: ['notchpay_event' => 'payment.failed', 'payment_reference' => $payment['reference'] ?? null],
        );
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active', 'trialing' => UserSubscription::STATUS_ACTIVE,
            'past_due', 'unpaid' => UserSubscription::STATUS_PAST_DUE,
            'canceled', 'incomplete_expired' => UserSubscription::STATUS_CANCELED,
            'incomplete' => UserSubscription::STATUS_INCOMPLETE,
            default => UserSubscription::STATUS_EXPIRED,
        };
    }

    private function findSubscriptionFromClientReference(mixed $clientReferenceId): ?UserSubscription
    {
        if (!is_string($clientReferenceId) || !ctype_digit($clientReferenceId)) {
            return null;
        }

        return $this->userSubscriptionRepository->find((int) $clientReferenceId);
    }
}
