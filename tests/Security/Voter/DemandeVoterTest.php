<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Demande;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Repository\DemandeRepository;
use App\Security\Voter\DemandeVoter;
use App\Service\SubscriptionService;
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
    private SubscriptionService $subscriptionService;
    private DemandeRepository $demandeRepository;

    protected function setUp(): void
    {
        $this->subscriptionService = $this->createMock(SubscriptionService::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);

        // Par défaut (sauf surcharge explicite dans un test) : plan illimité, aucune
        // demande active - ne doit jamais bloquer les tests existants sur le quota.
        $this->subscriptionService->method('getEffectivePlan')->willReturn($this->planWithQuota(null));
        $this->demandeRepository->method('countActiveByUser')->willReturn(0);

        $this->voter = new DemandeVoter(new VisibilityService(), $this->subscriptionService, $this->demandeRepository);
    }

    private function planWithQuota(?int $maxActiveDemandes): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('test')->setName('Test')->setMaxActiveDemandes($maxActiveDemandes);

        return $plan;
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

    public function testCreateDeniedWhenFreemiumQuotaReached(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('getEffectivePlan')->willReturn($this->planWithQuota(3));
        $demandeRepository = $this->createMock(DemandeRepository::class);
        $demandeRepository->method('countActiveByUser')->willReturn(3);
        $voter = new DemandeVoter(new VisibilityService(), $subscriptionService, $demandeRepository);

        $result = $voter->vote($this->tokenFor($user), null, [DemandeVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'le quota freemium (3 demandes actives) doit bloquer une nouvelle creation');
    }

    public function testCreateGrantedWhenPlanHasUnlimitedQuotaEvenAboveFreeThreshold(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('getEffectivePlan')->willReturn($this->planWithQuota(null));
        $demandeRepository = $this->createMock(DemandeRepository::class);
        $demandeRepository->method('countActiveByUser')->willReturn(10);
        $voter = new DemandeVoter(new VisibilityService(), $subscriptionService, $demandeRepository);

        $result = $voter->vote($this->tokenFor($user), null, [DemandeVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result, 'un plan payant sans limite (max=null) ne doit jamais bloquer la creation');
    }
}
