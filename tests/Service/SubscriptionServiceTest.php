<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\UserSubscriptionRepository;
use App\Service\Payment\CheckoutSessionResult;
use App\Service\Payment\PaymentProviderInterface;
use App\Service\PaymentService;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Monétisation Lot 1 : transitions d'état de l'abonnement et calcul du plan effectif
 * (point d'extension du quota freemium consommé par VoyageVoter/DemandeVoter).
 */
class SubscriptionServiceTest extends TestCase
{
    private SubscriptionPlanRepository&\PHPUnit\Framework\MockObject\MockObject $planRepository;
    private UserSubscriptionRepository&\PHPUnit\Framework\MockObject\MockObject $subscriptionRepository;
    private PaymentProviderInterface&\PHPUnit\Framework\MockObject\MockObject $paymentProvider;
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        $this->planRepository = $this->createMock(SubscriptionPlanRepository::class);
        $this->subscriptionRepository = $this->createMock(UserSubscriptionRepository::class);
        $this->paymentProvider = $this->createMock(PaymentProviderInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $paymentService = $this->createMock(PaymentService::class);

        $paymentProviders = $this->createMock(ContainerInterface::class);
        $paymentProviders->method('has')->willReturn(true);
        $paymentProviders->method('get')->willReturn($this->paymentProvider);

        $this->service = new SubscriptionService(
            $this->em,
            $this->planRepository,
            $this->subscriptionRepository,
            $paymentProviders,
            $paymentService,
            new NullLogger(),
        );
    }

    private function plan(string $code, ?string $priceEur = null, ?string $stripePriceId = 'price_123'): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setCode($code)->setName(ucfirst($code))->setPriceAmountEur($priceEur)->setStripePriceId($stripePriceId)->setIsActive(true);

        return $plan;
    }

    public function testGetEffectivePlanReturnsActiveSubscriptionPlanWhenOneExists(): void
    {
        $user = new User();
        $plusPlan = $this->plan('plus', '4.99');
        $subscription = (new UserSubscription())->setPlan($plusPlan);
        $this->subscriptionRepository->method('findActiveForUser')->willReturn($subscription);

        $result = $this->service->getEffectivePlan($user);

        self::assertSame($plusPlan, $result);
    }

    public function testGetEffectivePlanFallsBackToFreePlanWhenNoActiveSubscription(): void
    {
        $user = new User();
        $freePlan = $this->plan('free');
        $this->subscriptionRepository->method('findActiveForUser')->willReturn(null);
        $this->planRepository->method('findByCode')->with('free')->willReturn($freePlan);

        $result = $this->service->getEffectivePlan($user);

        self::assertSame($freePlan, $result);
    }

    public function testCheckoutRejectsAnUnknownPlanCode(): void
    {
        $user = new User();
        $this->planRepository->method('findByCode')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->service->checkout($user, 'inexistant', 'card', 'https://ok', 'https://ko');
    }

    public function testCheckoutRejectsTheFreePlan(): void
    {
        $user = new User();
        $this->planRepository->method('findByCode')->willReturn($this->plan('free', null, null));

        $this->expectException(BadRequestHttpException::class);

        $this->service->checkout($user, 'free', 'card', 'https://ok', 'https://ko');
    }

    public function testCheckoutPropagatesProviderConfigurationFailure(): void
    {
        // Depuis le Lot 3, la validation de configuration (ex. plan sans Price Stripe)
        // est déplacée dans chaque provider (StripePaymentProvider) - le service se
        // contente de propager l'exception.
        $user = new User();
        $this->planRepository->method('findByCode')->willReturn($this->plan('plus', '4.99', null));
        $this->subscriptionRepository->method('findActiveForUser')->willReturn(null);
        $this->paymentProvider->method('createCheckoutSession')->willThrowException(
            new \RuntimeException('Le plan "plus" n\'a pas de Price Stripe configuré')
        );

        $this->expectException(\RuntimeException::class);

        $this->service->checkout($user, 'plus', 'card', 'https://ok', 'https://ko');
    }

    public function testCheckoutRejectsWhenAnActiveSubscriptionAlreadyExists(): void
    {
        $user = new User();
        $this->planRepository->method('findByCode')->willReturn($this->plan('plus', '4.99'));
        $this->subscriptionRepository->method('findActiveForUser')->willReturn(new UserSubscription());

        $this->expectException(BadRequestHttpException::class);

        $this->service->checkout($user, 'plus', 'card', 'https://ok', 'https://ko');
    }

    public function testCheckoutCreatesAnIncompleteSubscriptionWithAmountFrozenFromThePlanAndDelegatesToTheProvider(): void
    {
        $user = new User();
        $plan = $this->plan('plus', '4.99');
        $this->planRepository->method('findByCode')->willReturn($plan);
        $this->subscriptionRepository->method('findActiveForUser')->willReturn(null);
        $this->subscriptionRepository->method('findLatestProviderCustomerId')->willReturn(null);

        $capturedSubscription = null;
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$capturedSubscription) {
            if ($entity instanceof UserSubscription) {
                $capturedSubscription = $entity;
            }
        });

        $this->paymentProvider->expects(self::once())
            ->method('createCheckoutSession')
            ->willReturn(new CheckoutSessionResult('https://checkout.stripe.com/session/xyz'));

        $result = $this->service->checkout($user, 'plus', 'card', 'https://ok', 'https://ko');

        self::assertSame('https://checkout.stripe.com/session/xyz', $result->checkoutUrl);
        self::assertNotNull($capturedSubscription);
        self::assertSame(UserSubscription::STATUS_INCOMPLETE, $capturedSubscription->getStatus());
        self::assertSame('4.99', $capturedSubscription->getAmount(), 'le montant doit etre fige depuis le plan au moment du checkout');
        self::assertSame('EUR', $capturedSubscription->getCurrency());
        self::assertNotNull($capturedSubscription->getWithdrawalWaiverConsentedAt());
    }

    public function testCancelSubscriptionThrowsWhenNoActiveSubscriptionExists(): void
    {
        $user = new User();
        $this->subscriptionRepository->method('findActiveForUser')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->service->cancelSubscription($user);
    }

    public function testCancelSubscriptionDelegatesToProviderAndSetsCancelAtPeriodEnd(): void
    {
        $user = new User();
        $subscription = new UserSubscription();
        $this->subscriptionRepository->method('findActiveForUser')->willReturn($subscription);

        $this->paymentProvider->expects(self::once())->method('cancelSubscription')->with($subscription);
        $this->em->expects(self::once())->method('flush');

        $this->service->cancelSubscription($user);

        self::assertTrue($subscription->isCancelAtPeriodEnd());
    }
}
