<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Signalement;
use App\Security\Voter\SignalementVoter;
use App\Tests\Support\InMemoryUserTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * un cas grant et un cas deny explicite par ressource, pour couvrir l'IDOR sur SignalementVoter.
 */
class SignalementVoterTest extends TestCase
{
    use InMemoryUserTrait;
    use MockTokenTrait;

    private SignalementVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new SignalementVoter();
    }

    public function testCreateGrantedWhenProfileComplete(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $result = $this->voter->vote($this->tokenFor($user), null, [SignalementVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCreateDeniedWhenProfileIncomplete(): void
    {
        $user = $this->makeUser([], profileComplete: false);

        $result = $this->voter->vote($this->tokenFor($user), null, [SignalementVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewGrantedToReporter(): void
    {
        $signaleur = $this->makeUser();
        $signalement = new Signalement();
        $signalement->setSignaleur($signaleur);

        $result = $this->voter->vote($this->tokenFor($signaleur), $signalement, [SignalementVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewDeniedToThirdParty(): void
    {
        $signaleur = $this->makeUser();
        $stranger = $this->makeUser();
        $signalement = new Signalement();
        $signalement->setSignaleur($signaleur);

        $result = $this->voter->vote($this->tokenFor($stranger), $signalement, [SignalementVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un signalement doit rester confidentiel entre le signaleur et la moderation (IDOR)');
    }

    public function testViewGrantedToModerator(): void
    {
        $signaleur = $this->makeUser();
        $moderator = $this->makeUser(['ROLE_MODERATOR']);
        $signalement = new Signalement();
        $signalement->setSignaleur($signaleur);

        $result = $this->voter->vote($this->tokenFor($moderator), $signalement, [SignalementVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testProcessDeniedToRegularUser(): void
    {
        $user = $this->makeUser();

        $result = $this->voter->vote($this->tokenFor($user), new Signalement(), [SignalementVoter::PROCESS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un utilisateur standard ne doit jamais pouvoir traiter un signalement, meme le sien');
    }

    public function testProcessGrantedToModerator(): void
    {
        $moderator = $this->makeUser(['ROLE_MODERATOR']);

        $result = $this->voter->vote($this->tokenFor($moderator), new Signalement(), [SignalementVoter::PROCESS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testProcessGrantedToAdmin(): void
    {
        $admin = $this->makeUser(['ROLE_ADMIN']);

        $result = $this->voter->vote($this->tokenFor($admin), new Signalement(), [SignalementVoter::PROCESS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}
