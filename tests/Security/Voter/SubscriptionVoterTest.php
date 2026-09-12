<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\UserSubscription;
use App\Security\Voter\SubscriptionVoter;
use App\Tests\Support\InMemoryUserTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Monétisation Lot 1 : un cas grant et un cas deny, même patron que les autres Voters
 * (AvisVoter, VoyageVoter...).
 */
class SubscriptionVoterTest extends TestCase
{
    use InMemoryUserTrait;
    use MockTokenTrait;

    private SubscriptionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new SubscriptionVoter();
    }

    private function subscriptionFor(\App\Entity\User $user): UserSubscription
    {
        $subscription = new UserSubscription();
        $subscription->setUser($user);

        return $subscription;
    }

    public function testCancelGrantedToOwner(): void
    {
        $owner = $this->makeUser();
        $subscription = $this->subscriptionFor($owner);

        $result = $this->voter->vote($this->tokenFor($owner), $subscription, [SubscriptionVoter::CANCEL]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCancelDeniedToThirdParty(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $subscription = $this->subscriptionFor($owner);

        $result = $this->voter->vote($this->tokenFor($thirdParty), $subscription, [SubscriptionVoter::CANCEL]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un tiers ne doit jamais pouvoir resilier l\'abonnement d\'un autre utilisateur (IDOR)');
    }

    public function testCancelGrantedToAdminEvenIfNotOwner(): void
    {
        $owner = $this->makeUser();
        $admin = $this->makeUser(['ROLE_ADMIN']);
        $subscription = $this->subscriptionFor($owner);

        $result = $this->voter->vote($this->tokenFor($admin), $subscription, [SubscriptionVoter::CANCEL]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewDeniedForUnauthenticatedToken(): void
    {
        $owner = $this->makeUser();
        $subscription = $this->subscriptionFor($owner);

        $result = $this->voter->vote($this->tokenFor(null), $subscription, [SubscriptionVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}
