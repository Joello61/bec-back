<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Demande;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Security\Voter\DemandeVoter;
use App\Service\VisibilityService;
use App\Tests\Support\InMemoryUserTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * un cas grant et un cas deny explicite par ressource, pour couvrir l'IDOR sur DemandeVoter.
 * Symétrique de VoyageVoterTest.
 */
class DemandeVoterTest extends TestCase
{
    use InMemoryUserTrait;
    use MockTokenTrait;

    private DemandeVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new DemandeVoter(new VisibilityService());
    }

    private function demande(User $client, string $statut = 'en_recherche'): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setStatut($statut);

        return $demande;
    }

    public function testEditGrantedToOwner(): void
    {
        $owner = $this->makeUser();
        $demande = $this->demande($owner);

        $result = $this->voter->vote($this->tokenFor($owner), $demande, [DemandeVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testEditDeniedToThirdParty(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $demande = $this->demande($owner);

        $result = $this->voter->vote($this->tokenFor($thirdParty), $demande, [DemandeVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un tiers non-proprietaire ne doit jamais pouvoir modifier la demande d\'un autre (IDOR)');
    }

    public function testEditGrantedToAdminEvenIfNotOwner(): void
    {
        $owner = $this->makeUser();
        $admin = $this->makeUser(['ROLE_ADMIN']);
        $demande = $this->demande($owner);

        $result = $this->voter->vote($this->tokenFor($admin), $demande, [DemandeVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDeleteDeniedToThirdParty(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $demande = $this->demande($owner);

        $result = $this->voter->vote($this->tokenFor($thirdParty), $demande, [DemandeVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewDeniedForUnauthenticatedToken(): void
    {
        $owner = $this->makeUser();
        $demande = $this->demande($owner);

        $result = $this->voter->vote($this->tokenFor(null), $demande, [DemandeVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewGrantedToOwnerEvenIfNotSearching(): void
    {
        $owner = $this->makeUser();
        $demande = $this->demande($owner, 'satisfaite');

        $result = $this->voter->vote($this->tokenFor($owner), $demande, [DemandeVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewDeniedToThirdPartyWhenNotSearching(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $demande = $this->demande($owner, 'satisfaite');

        $result = $this->voter->vote($this->tokenFor($thirdParty), $demande, [DemandeVoter::VIEW]);

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
        $demande = $this->demande($owner, 'en_recherche');

        $result = $this->voter->vote($this->tokenFor($thirdParty), $demande, [DemandeVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un client qui se retire des resultats de recherche ne doit pas etre contournable via un ID de demande direct');
    }

    public function testViewGrantedToThirdPartyWhenSearchingAndVisible(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $demande = $this->demande($owner, 'en_recherche');

        $result = $this->voter->vote($this->tokenFor($thirdParty), $demande, [DemandeVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCreateGrantedWhenProfileComplete(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $result = $this->voter->vote($this->tokenFor($user), null, [DemandeVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCreateDeniedWhenProfileIncomplete(): void
    {
        $user = $this->makeUser([], profileComplete: false);

        $result = $this->voter->vote($this->tokenFor($user), null, [DemandeVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testCreateGrantedToAdminEvenWithIncompleteProfile(): void
    {
        $admin = $this->makeUser(['ROLE_ADMIN'], profileComplete: false);

        $result = $this->voter->vote($this->tokenFor($admin), null, [DemandeVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}
