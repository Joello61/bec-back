<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Repository\VoyageRepository;
use App\Security\Voter\VoyageVoter;
use App\Service\SubscriptionService;
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
    private SubscriptionService $subscriptionService;
    private VoyageRepository $voyageRepository;

    protected function setUp(): void
    {
        $this->subscriptionService = $this->createMock(SubscriptionService::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);

        // Par défaut (sauf surcharge explicite dans un test) : plan illimité, aucun
        // voyage actif - ne doit jamais bloquer les tests existants sur le quota.
        $this->subscriptionService->method('getEffectivePlan')->willReturn($this->planWithQuota(null));
        $this->voyageRepository->method('countActiveByUser')->willReturn(0);

        $this->voter = new VoyageVoter($this->visibilityService(), $this->subscriptionService, $this->voyageRepository);
    }

    private function visibilityService(): VisibilityService
    {
        return new VisibilityService();
    }

    private function planWithQuota(?int $maxActiveVoyages): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('test')->setName('Test')->setMaxActiveVoyages($maxActiveVoyages);

        return $plan;
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

    public function testCreateDeniedWhenFreemiumQuotaReached(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('getEffectivePlan')->willReturn($this->planWithQuota(3));
        $voyageRepository = $this->createMock(VoyageRepository::class);
        $voyageRepository->method('countActiveByUser')->willReturn(3);
        $voter = new VoyageVoter($this->visibilityService(), $subscriptionService, $voyageRepository);

        $result = $voter->vote($this->tokenFor($user), null, [VoyageVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'le quota freemium (3 voyages actifs) doit bloquer une nouvelle creation');
    }

    public function testCreateGrantedWhenPlanHasUnlimitedQuotaEvenAboveFreeThreshold(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('getEffectivePlan')->willReturn($this->planWithQuota(null));
        $voyageRepository = $this->createMock(VoyageRepository::class);
        $voyageRepository->method('countActiveByUser')->willReturn(10);
        $voter = new VoyageVoter($this->visibilityService(), $subscriptionService, $voyageRepository);

        $result = $voter->vote($this->tokenFor($user), null, [VoyageVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result, 'un plan payant sans limite (max=null) ne doit jamais bloquer la creation');
    }
}
