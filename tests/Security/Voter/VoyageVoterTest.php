<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Security\Voter\VoyageVoter;
use App\Service\VisibilityService;
use App\Tests\Support\InMemoryUserTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * un cas grant et un cas deny explicite par ressource, pour couvrir l'IDOR sur VoyageVoter.
 */
class VoyageVoterTest extends TestCase
{
    use InMemoryUserTrait;
    use MockTokenTrait;

    private VoyageVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new VoyageVoter(new VisibilityService());
    }

    private function voyage(User $voyageur, string $statut = 'actif'): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setStatut($statut);

        return $voyage;
    }

    public function testEditGrantedToOwner(): void
    {
        $owner = $this->makeUser();
        $voyage = $this->voyage($owner);

        $result = $this->voter->vote($this->tokenFor($owner), $voyage, [VoyageVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testEditDeniedToThirdParty(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $voyage = $this->voyage($owner);

        $result = $this->voter->vote($this->tokenFor($thirdParty), $voyage, [VoyageVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un tiers non-proprietaire ne doit jamais pouvoir modifier le voyage d\'un autre (IDOR)');
    }

    public function testEditGrantedToAdminEvenIfNotOwner(): void
    {
        $owner = $this->makeUser();
        $admin = $this->makeUser(['ROLE_ADMIN']);
        $voyage = $this->voyage($owner);

        $result = $this->voter->vote($this->tokenFor($admin), $voyage, [VoyageVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDeleteDeniedToThirdParty(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $voyage = $this->voyage($owner);

        $result = $this->voter->vote($this->tokenFor($thirdParty), $voyage, [VoyageVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewDeniedForUnauthenticatedToken(): void
    {
        $owner = $this->makeUser();
        $voyage = $this->voyage($owner);

        $result = $this->voter->vote($this->tokenFor(null), $voyage, [VoyageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewGrantedToOwnerEvenIfNotActive(): void
    {
        $owner = $this->makeUser();
        $voyage = $this->voyage($owner, 'termine');

        $result = $this->voter->vote($this->tokenFor($owner), $voyage, [VoyageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewDeniedToThirdPartyWhenNotActive(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $voyage = $this->voyage($owner, 'termine');

        $result = $this->voter->vote($this->tokenFor($thirdParty), $voyage, [VoyageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewDeniedToThirdPartyWhenOwnerHidesFromSearch(): void
    {
        $owner = $this->makeUser();
        $settings = new UserSettings();
        $settings->setUser($owner);
        $settings->setShowInSearchResults(false);
        $owner->setSettings($settings);
        $thirdParty = $this->makeUser();
        $voyage = $this->voyage($owner, 'actif');

        $result = $this->voter->vote($this->tokenFor($thirdParty), $voyage, [VoyageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un voyageur qui se retire des resultats de recherche ne doit pas etre contournable via un ID de voyage direct');
    }

    public function testViewGrantedToThirdPartyWhenActiveAndVisible(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $voyage = $this->voyage($owner, 'actif');

        $result = $this->voter->vote($this->tokenFor($thirdParty), $voyage, [VoyageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCreateGrantedWhenProfileComplete(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $result = $this->voter->vote($this->tokenFor($user), null, [VoyageVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCreateDeniedWhenProfileIncomplete(): void
    {
        $user = $this->makeUser([], profileComplete: false);

        $result = $this->voter->vote($this->tokenFor($user), null, [VoyageVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testCreateGrantedToAdminEvenWithIncompleteProfile(): void
    {
        $admin = $this->makeUser(['ROLE_ADMIN'], profileComplete: false);

        $result = $this->voter->vote($this->tokenFor($admin), null, [VoyageVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}
