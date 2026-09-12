<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Message\ExpireNotchPaySubscriptionsMessage;
use App\MessageHandler\ExpireNotchPaySubscriptionsHandler;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Monétisation Lot 3 : un abonnement Mobile Money actif dont le délai de grâce (période +
 * 3 jours) est dépassé sans nouveau paiement bascule en expired - Notch Pay n'a pas
 * d'équivalent aux événements Stripe invoice.* pour détecter ce cas autrement.
 */
class ExpireNotchPaySubscriptionsHandlerTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private ExpireNotchPaySubscriptionsHandler $handler;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(ExpireNotchPaySubscriptionsHandler::class);
    }

    private function plan(string $code): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setCode($code)->setName(ucfirst($code))->setPriceAmountXaf('3000');
        $this->em->persist($plan);

        return $plan;
    }

    private function notchPaySubscription(
        User $user,
        SubscriptionPlan $plan,
        \DateTimeInterface $currentPeriodEnd,
        string $status = UserSubscription::STATUS_ACTIVE,
    ): UserSubscription {
        $subscription = new UserSubscription();
        $subscription->setUser($user)
            ->setPlan($plan)
            ->setProvider(UserSubscription::PROVIDER_NOTCHPAY)
            ->setStatus($status)
            ->setAmount('3000')
            ->setCurrency('XAF')
            ->setCurrentPeriodStart((clone $currentPeriodEnd)->modify('-1 month'))
            ->setCurrentPeriodEnd($currentPeriodEnd);
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    public function testHandlerIsANoOpWhenNothingIsPastTheGracePeriod(): void
    {
        $user = $this->createUser('notchexpire-noop');
        $plan = $this->plan('plus-notchexpire-noop');
        $this->notchPaySubscription($user, $plan, new \DateTime('-1 day'));

        ($this->handler)(new ExpireNotchPaySubscriptionsMessage());

        self::assertTrue(true);
    }

    public function testHandlerExpiresASubscriptionPastTheGracePeriod(): void
    {
        $user = $this->createUser('notchexpire-due');
        $plan = $this->plan('plus-notchexpire-due');
        $subscription = $this->notchPaySubscription($user, $plan, new \DateTime('-5 days'));
        $subscriptionId = $subscription->getId();

        ($this->handler)(new ExpireNotchPaySubscriptionsMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(UserSubscription::class)->find($subscriptionId);
        self::assertSame(UserSubscription::STATUS_EXPIRED, $refreshed->getStatus());
    }

    public function testHandlerDoesNotExpireASubscriptionStillWithinTheGracePeriod(): void
    {
        $user = $this->createUser('notchexpire-grace');
        $plan = $this->plan('plus-notchexpire-grace');
        $subscription = $this->notchPaySubscription($user, $plan, new \DateTime('-1 day'));
        $subscriptionId = $subscription->getId();

        ($this->handler)(new ExpireNotchPaySubscriptionsMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(UserSubscription::class)->find($subscriptionId);
        self::assertSame(UserSubscription::STATUS_ACTIVE, $refreshed->getStatus());
    }

    public function testHandlerIgnoresStripeSubscriptions(): void
    {
        $user = $this->createUser('notchexpire-stripe');
        $plan = $this->plan('plus-notchexpire-stripe');
        $subscription = new UserSubscription();
        $subscription->setUser($user)
            ->setPlan($plan)
            ->setProvider(UserSubscription::PROVIDER_STRIPE)
            ->setStatus(UserSubscription::STATUS_ACTIVE)
            ->setAmount('4.99')
            ->setCurrency('EUR')
            ->setCurrentPeriodEnd(new \DateTime('-10 days'));
        $this->em->persist($subscription);
        $this->em->flush();
        $subscriptionId = $subscription->getId();

        ($this->handler)(new ExpireNotchPaySubscriptionsMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(UserSubscription::class)->find($subscriptionId);
        self::assertSame(UserSubscription::STATUS_ACTIVE, $refreshed->getStatus(), 'Stripe gere son propre cycle de vie via les webhooks invoice.*, jamais ce sweep');
    }
}
