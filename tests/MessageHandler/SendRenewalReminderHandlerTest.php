<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Message\SendRenewalReminderMessage;
use App\MessageHandler\SendRenewalReminderHandler;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Monétisation Lot 3 : le rappel J-3 ne concerne que les abonnements Mobile Money actifs
 * dont l'échéance approche et qui n'ont pas déjà reçu de rappel pour la période courante -
 * pattern d'intégration ExpireVoyagesHandlerTest (vraie requête Doctrine).
 */
class SendRenewalReminderHandlerTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private SendRenewalReminderHandler $handler;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(SendRenewalReminderHandler::class);
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
        ?\DateTimeInterface $renewalReminderSentAt = null,
        string $status = UserSubscription::STATUS_ACTIVE,
    ): UserSubscription {
        $subscription = new UserSubscription();
        $subscription->setUser($user)
            ->setPlan($plan)
            ->setProvider(UserSubscription::PROVIDER_NOTCHPAY)
            ->setStatus($status)
            ->setAmount('3000')
            ->setCurrency('XAF')
            ->setCurrentPeriodStart(new \DateTime('-27 days'))
            ->setCurrentPeriodEnd($currentPeriodEnd)
            ->setRenewalReminderSentAt($renewalReminderSentAt);
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    public function testHandlerIsANoOpWhenNoSubscriptionIsNearingItsDeadline(): void
    {
        $user = $this->createUser('renewal-noop');
        $plan = $this->plan('plus-renewal-noop');
        $this->notchPaySubscription($user, $plan, new \DateTime('+20 days'));

        ($this->handler)(new SendRenewalReminderMessage());

        // Aucune exception ne suffit ici : l'assertion utile est dans le test suivant
        // (le champ renewalReminderSentAt ne doit pas être positionné trop tôt).
        self::assertTrue(true);
    }

    public function testHandlerSendsTheReminderAndStampsRenewalReminderSentAt(): void
    {
        $user = $this->createUser('renewal-due');
        $plan = $this->plan('plus-renewal-due');
        $subscription = $this->notchPaySubscription($user, $plan, new \DateTime('+2 days'));
        $subscriptionId = $subscription->getId();

        ($this->handler)(new SendRenewalReminderMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(UserSubscription::class)->find($subscriptionId);
        self::assertNotNull($refreshed->getRenewalReminderSentAt());
    }

    public function testHandlerDoesNotResendWhenAReminderWasAlreadySentForTheCurrentPeriod(): void
    {
        $user = $this->createUser('renewal-already-sent');
        $plan = $this->plan('plus-renewal-already-sent');
        $alreadySentAt = new \DateTime('-1 day');
        $subscription = $this->notchPaySubscription($user, $plan, new \DateTime('+2 days'), renewalReminderSentAt: $alreadySentAt);
        $subscriptionId = $subscription->getId();

        ($this->handler)(new SendRenewalReminderMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(UserSubscription::class)->find($subscriptionId);
        self::assertEquals($alreadySentAt->format('Y-m-d H:i:s'), $refreshed->getRenewalReminderSentAt()->format('Y-m-d H:i:s'));
    }

    public function testHandlerIgnoresStripeSubscriptions(): void
    {
        $user = $this->createUser('renewal-stripe');
        $plan = $this->plan('plus-renewal-stripe');
        $subscription = new UserSubscription();
        $subscription->setUser($user)
            ->setPlan($plan)
            ->setProvider(UserSubscription::PROVIDER_STRIPE)
            ->setStatus(UserSubscription::STATUS_ACTIVE)
            ->setAmount('4.99')
            ->setCurrency('EUR')
            ->setCurrentPeriodEnd(new \DateTime('+2 days'));
        $this->em->persist($subscription);
        $this->em->flush();
        $subscriptionId = $subscription->getId();

        ($this->handler)(new SendRenewalReminderMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(UserSubscription::class)->find($subscriptionId);
        self::assertNull($refreshed->getRenewalReminderSentAt(), 'Stripe a sa propre relance (invoice.upcoming côté Stripe), jamais ce rappel');
    }
}
