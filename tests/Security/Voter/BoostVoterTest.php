<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Boost;
use App\Entity\Demande;
use App\Entity\User;
use App\Entity\Voyage;
use App\Security\Voter\BoostVoter;
use App\Security\Voter\DemandeVoter;
use App\Security\Voter\VoyageVoter;
use App\Tests\Support\InMemoryUserTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Monétisation Lot 2 : CREATE délègue à VOYAGE_EDIT/DEMANDE_EDIT (pas de logique de
 * propriété dupliquée) - le cas critique est qu'un tiers ne puisse jamais booster le
 * voyage/la demande d'un autre (IDOR).
 */
class BoostVoterTest extends TestCase
{
    use InMemoryUserTrait;
    use MockTokenTrait;

    public function testCreateGrantedForVoyageWhenAuthorizationCheckerGrantsVoyageEdit(): void
    {
        $user = $this->makeUser();
        $voyage = new Voyage();
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->with(VoyageVoter::EDIT, $voyage)->willReturn(true);
        $voter = new BoostVoter($checker);

        $result = $voter->vote($this->tokenFor($user), $voyage, [BoostVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCreateDeniedForVoyageWhenAuthorizationCheckerDeniesVoyageEdit(): void
    {
        $thirdParty = $this->makeUser();
        $voyage = new Voyage();
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->with(VoyageVoter::EDIT, $voyage)->willReturn(false);
        $voter = new BoostVoter($checker);

        $result = $voter->vote($this->tokenFor($thirdParty), $voyage, [BoostVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un tiers ne doit jamais pouvoir booster le voyage d\'un autre (IDOR)');
    }

    public function testCreateGrantedForDemandeWhenAuthorizationCheckerGrantsDemandeEdit(): void
    {
        $user = $this->makeUser();
        $demande = new Demande();
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->with(DemandeVoter::EDIT, $demande)->willReturn(true);
        $voter = new BoostVoter($checker);

        $result = $voter->vote($this->tokenFor($user), $demande, [BoostVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCreateDeniedForDemandeWhenAuthorizationCheckerDeniesDemandeEdit(): void
    {
        $thirdParty = $this->makeUser();
        $demande = new Demande();
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->with(DemandeVoter::EDIT, $demande)->willReturn(false);
        $voter = new BoostVoter($checker);

        $result = $voter->vote($this->tokenFor($thirdParty), $demande, [BoostVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un tiers ne doit jamais pouvoir booster la demande d\'un autre (IDOR)');
    }

    public function testViewGrantedToOwner(): void
    {
        $owner = $this->makeUser();
        $boost = (new Boost())->setUser($owner);
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $voter = new BoostVoter($checker);

        $result = $voter->vote($this->tokenFor($owner), $boost, [BoostVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewDeniedToThirdParty(): void
    {
        $owner = $this->makeUser();
        $thirdParty = $this->makeUser();
        $boost = (new Boost())->setUser($owner);
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $voter = new BoostVoter($checker);

        $result = $voter->vote($this->tokenFor($thirdParty), $boost, [BoostVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un tiers ne doit jamais pouvoir consulter le boost d\'un autre (IDOR)');
    }

    public function testViewGrantedToAdminEvenIfNotOwner(): void
    {
        $owner = $this->makeUser();
        $admin = $this->makeUser(['ROLE_ADMIN']);
        $boost = (new Boost())->setUser($owner);
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $voter = new BoostVoter($checker);

        $result = $voter->vote($this->tokenFor($admin), $boost, [BoostVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}
