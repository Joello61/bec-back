<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\AdminVoter;
use App\Tests\Support\InMemoryUserTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * un cas grant et un cas deny explicite par ressource, pour couvrir l'IDOR sur AdminVoter.
 */
class AdminVoterTest extends TestCase
{
    use InMemoryUserTrait;
    use MockTokenTrait;

    private AdminVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new AdminVoter();
    }

    /**
     * canBanSpecificUser/canEditRolesOfUser/canDeleteSpecificUser comparent des
     * getId() - des entités jamais persistées ont toutes un id null (id === null
     * pour toutes), ce qui rendrait "meme utilisateur" toujours vrai. On force un
     * id distinct par réflexion pour isoler ce comportement sans DB.
     */
    private function withId(User $user, int $id): User
    {
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }

    public function testViewDashboardGrantedToModerator(): void
    {
        $moderator = $this->makeUser(['ROLE_MODERATOR']);

        $result = $this->voter->vote($this->tokenFor($moderator), null, [AdminVoter::VIEW_DASHBOARD]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewDashboardDeniedToRegularUser(): void
    {
        $user = $this->makeUser();

        $result = $this->voter->vote($this->tokenFor($user), null, [AdminVoter::VIEW_DASHBOARD]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewLogsGrantedToAdmin(): void
    {
        $admin = $this->makeUser(['ROLE_ADMIN']);

        $result = $this->voter->vote($this->tokenFor($admin), null, [AdminVoter::VIEW_LOGS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewLogsDeniedToModerator(): void
    {
        $moderator = $this->makeUser(['ROLE_MODERATOR']);

        $result = $this->voter->vote($this->tokenFor($moderator), null, [AdminVoter::VIEW_LOGS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'les logs sont un privilege admin uniquement, un moderateur n\'y a pas droit');
    }

    public function testManageRolesDeniedToModerator(): void
    {
        $moderator = $this->makeUser(['ROLE_MODERATOR']);

        $result = $this->voter->vote($this->tokenFor($moderator), null, [AdminVoter::MANAGE_ROLES]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testDeleteContentGrantedToModerator(): void
    {
        $moderator = $this->makeUser(['ROLE_MODERATOR']);

        $result = $this->voter->vote($this->tokenFor($moderator), null, [AdminVoter::DELETE_CONTENT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testEverythingDeniedToBannedAdmin(): void
    {
        $bannedAdmin = $this->makeUser(['ROLE_ADMIN'], banned: true);

        $result = $this->voter->vote($this->tokenFor($bannedAdmin), null, [AdminVoter::VIEW_DASHBOARD]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un compte banni ne doit conserver aucun privilege admin, meme technique');
    }

    public function testCanBanSpecificUserGrantedForAdminTargetingRegularUser(): void
    {
        $admin = $this->withId($this->makeUser(['ROLE_ADMIN']), 1);
        $target = $this->withId($this->makeUser(), 2);

        self::assertTrue($this->voter->canBanSpecificUser($admin, $target));
    }

    public function testCanBanSpecificUserDeniedForSelfTargeting(): void
    {
        $admin = $this->withId($this->makeUser(['ROLE_ADMIN']), 1);

        self::assertFalse($this->voter->canBanSpecificUser($admin, $admin), 'un admin ne doit pas pouvoir se bannir lui-meme');
    }

    public function testCanBanSpecificUserDeniedWhenTargetIsAlsoAdmin(): void
    {
        $admin = $this->withId($this->makeUser(['ROLE_ADMIN']), 1);
        $otherAdmin = $this->withId($this->makeUser(['ROLE_ADMIN']), 2);

        self::assertFalse($this->voter->canBanSpecificUser($admin, $otherAdmin), 'un admin ne doit pas pouvoir bannir un autre admin');
    }

    public function testCanBanSpecificUserDeniedForNonAdminCaller(): void
    {
        $moderator = $this->withId($this->makeUser(['ROLE_MODERATOR']), 1);
        $target = $this->withId($this->makeUser(), 2);

        self::assertFalse($this->voter->canBanSpecificUser($moderator, $target), 'seul un admin peut bannir, pas un moderateur (IDOR sur une action reservee)');
    }

    public function testCanEditRolesOfUserDeniedForSelfTargeting(): void
    {
        $admin = $this->withId($this->makeUser(['ROLE_ADMIN']), 1);

        self::assertFalse($this->voter->canEditRolesOfUser($admin, $admin), 'un admin ne doit pas pouvoir modifier ses propres roles');
    }

    public function testCanDeleteSpecificUserDeniedWhenTargetIsAlsoAdmin(): void
    {
        $admin = $this->withId($this->makeUser(['ROLE_ADMIN']), 1);
        $otherAdmin = $this->withId($this->makeUser(['ROLE_ADMIN']), 2);

        self::assertFalse($this->voter->canDeleteSpecificUser($admin, $otherAdmin));
    }

    public function testCanDeleteSpecificUserGrantedForAdminTargetingRegularUser(): void
    {
        $admin = $this->withId($this->makeUser(['ROLE_ADMIN']), 1);
        $target = $this->withId($this->makeUser(), 2);

        self::assertTrue($this->voter->canDeleteSpecificUser($admin, $target));
    }
}
