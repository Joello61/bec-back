<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Boost;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\BoostOfferRepository;
use App\Repository\BoostRepository;
use App\Repository\DemandeRepository;
use App\Repository\UserSubscriptionRepository;
use App\Repository\VoyageRepository;
use App\Service\Payment\CheckoutSessionResult;
use App\Service\Payment\PaymentProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class BoostService
{
    public const TARGET_VOYAGE = 'voyage';
    public const TARGET_DEMANDE = 'demande';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BoostOfferRepository $boostOfferRepository,
        private BoostRepository $boostRepository,
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository,
        // Reutilise le meme Customer Stripe qu'un eventuel abonnement (evite de dupliquer
        // un client Stripe pour le meme utilisateur) - pas de champ dedie sur Boost.
        private UserSubscriptionRepository $userSubscriptionRepository,
        #[AutowireLocator('app.payment_provider', indexAttribute: 'key')]
        private ContainerInterface $paymentProviders,
        private PaymentService $paymentService,
        private LoggerInterface $logger,
    ) {}

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
        return $paymentMethod === SubscriptionService::PAYMENT_METHOD_MOBILE_MONEY
            ? UserSubscription::PROVIDER_NOTCHPAY
            : UserSubscription::PROVIDER_STRIPE;
    }

    public function checkout(
        User $user,
        string $targetType,
        int $targetId,
        int $offerId,
        string $paymentMethod,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult {
        $offer = $this->boostOfferRepository->findById($offerId);

        if ($offer === null || !$offer->isActive()) {
            throw new NotFoundHttpException('Offre de boost introuvable');
        }

        $provider = $this->resolveProvider($paymentMethod);
        $providerName = $this->providerNameFor($paymentMethod);
        $isMobileMoney = $paymentMethod === SubscriptionService::PAYMENT_METHOD_MOBILE_MONEY;
        $amount = $isMobileMoney ? $offer->getPriceAmountXaf() : $offer->getPriceAmountEur();
        $currency = $isMobileMoney ? 'XAF' : 'EUR';

        if ($amount === null) {
            throw new BadRequestHttpException("Cette offre n'a pas de tarif configuré pour ce moyen de paiement");
        }

        $boost = new Boost();
        $boost->setUser($user)
            ->setOffer($offer)
            ->setStatus(Boost::STATUS_PENDING)
            ->setAmount($amount)
            ->setCurrency($currency)
            ->setWithdrawalWaiverConsentedAt(new \DateTime());

        $targetLabel = match ($targetType) {
            self::TARGET_VOYAGE => $this->attachVoyage($boost, $targetId),
            self::TARGET_DEMANDE => $this->attachDemande($boost, $targetId),
            default => throw new BadRequestHttpException('Type de cible invalide (voyage ou demande attendu)'),
        };

        $this->entityManager->persist($boost);
        $this->entityManager->flush();

        $existingProviderCustomerId = $this->userSubscriptionRepository->findLatestProviderCustomerId($user, $providerName);

        return $provider->createOneTimeCheckoutSession(
            $user,
            sprintf('Boost de visibilité (%s) - %s', $offer->getName(), $targetLabel),
            $amount,
            $currency,
            (string) $boost->getId(),
            $existingProviderCustomerId,
            $successUrl,
            $cancelUrl,
        );
    }

    private function attachVoyage(Boost $boost, int $voyageId): string
    {
        $voyage = $this->voyageRepository->find($voyageId);

        if ($voyage === null) {
            throw new NotFoundHttpException('Voyage introuvable');
        }

        $boost->setVoyage($voyage);

        return sprintf('%s → %s', $voyage->getVilleDepart(), $voyage->getVilleArrivee());
    }

    private function attachDemande(Boost $boost, int $demandeId): string
    {
        $demande = $this->demandeRepository->find($demandeId);

        if ($demande === null) {
            throw new NotFoundHttpException('Demande introuvable');
        }

        $boost->setDemande($demande);

        return sprintf('%s → %s', $demande->getVilleDepart(), $demande->getVilleArrivee());
    }

    /**
     * @param array<string, mixed> $session Objet Stripe Checkout Session (event.data.object, mode=payment)
     */
    public function handleCheckoutCompleted(array $session): void
    {
        $boost = $this->findBoostFromClientReference($session['client_reference_id'] ?? null);

        if ($boost === null) {
            return;
        }

        $offer = $boost->getOffer();
        $now = new \DateTime();
        $boost->setStartAt($now);
        $boost->setEndAt((clone $now)->modify(sprintf('+%d days', $offer->getDurationDays())));
        $boost->setStatus(Boost::STATUS_ACTIVE);
        $this->entityManager->flush();

        $this->logger->info('Boost activé via checkout.session.completed', ['boostId' => $boost->getId()]);

        $paymentIntentId = $session['payment_intent'] ?? null;

        if (!is_string($paymentIntentId)) {
            $this->logger->warning('checkout.session.completed (boost) sans payment_intent', ['boostId' => $boost->getId()]);
            return;
        }

        $this->paymentService->findOrCreateFromProviderEvent(
            provider: 'stripe',
            providerPaymentId: $paymentIntentId,
            user: $boost->getUser(),
            subscription: null,
            type: Transaction::TYPE_BOOST,
            paymentMethodFamily: Transaction::METHOD_FAMILY_CARD,
            amount: $boost->getAmount(),
            currency: $boost->getCurrency(),
            status: Transaction::STATUS_SUCCEEDED,
            rawPayload: ['stripe_event' => 'checkout.session.completed', 'session_id' => $session['id'] ?? null],
            boost: $boost,
        );
    }

    /**
     * @param array<string, mixed> $payment Objet Payment Notch Pay (payment.complete)
     */
    public function handleNotchPayPaymentCompleted(array $payment): void
    {
        $boost = $this->findBoostFromClientReference($payment['reference'] ?? null);

        if ($boost === null) {
            return;
        }

        $offer = $boost->getOffer();
        $now = new \DateTime();
        $boost->setStartAt($now);
        $boost->setEndAt((clone $now)->modify(sprintf('+%d days', $offer->getDurationDays())));
        $boost->setStatus(Boost::STATUS_ACTIVE);
        $this->entityManager->flush();

        $this->logger->info('Boost activé via payment.complete (Notch Pay)', ['boostId' => $boost->getId()]);

        $this->paymentService->findOrCreateFromProviderEvent(
            provider: UserSubscription::PROVIDER_NOTCHPAY,
            providerPaymentId: (string) ($payment['id'] ?? $payment['reference'] ?? ''),
            user: $boost->getUser(),
            subscription: null,
            type: Transaction::TYPE_BOOST,
            paymentMethodFamily: Transaction::METHOD_FAMILY_MOBILE_MONEY,
            amount: $boost->getAmount(),
            currency: $boost->getCurrency(),
            status: Transaction::STATUS_SUCCEEDED,
            rawPayload: ['notchpay_event' => 'payment.complete', 'payment_reference' => $payment['reference'] ?? null],
            boost: $boost,
        );
    }

    private function findBoostFromClientReference(mixed $clientReferenceId): ?Boost
    {
        if (!is_string($clientReferenceId) || !ctype_digit($clientReferenceId)) {
            return null;
        }

        return $this->boostRepository->find((int) $clientReferenceId);
    }
}
