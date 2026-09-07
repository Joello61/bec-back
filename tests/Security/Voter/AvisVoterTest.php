<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Avis;
use App\Security\Voter\AvisVoter;
use App\Tests\Support\InMemoryUserTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * un cas grant et un cas deny explicite par ressource, pour couvrir l'IDOR sur AvisVoter.
 */
class AvisVoterTest extends TestCase
{
    use InMemoryUserTrait;
    use MockTokenTrait;

    private AvisVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new AvisVoter();
    }

    public function testCreateGrantedWhenProfileComplete(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $result = $this->voter->vote($this->tokenFor($user), null, [AvisVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCreateDeniedWhenProfileIncomplete(): void
    {
        $user = $this->makeUser([], profileComplete: false);

        $result = $this->voter->vote($this->tokenFor($user), null, [AvisVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testViewGrantedToAnyoneSinceAvisArePublic(): void
    {
        $auteur = $this->makeUser();
        $stranger = $this->makeUser();
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($this->makeUser());

        $result = $this->voter->vote($this->tokenFor($stranger), $avis, [AvisVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testEditGrantedToAuthor(): void
    {
        $auteur = $this->makeUser();
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($this->makeUser());

        $result = $this->voter->vote($this->tokenFor($auteur), $avis, [AvisVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testEditDeniedToNonAuthor(): void
    {
        $auteur = $this->makeUser();
        $stranger = $this->makeUser();
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($this->makeUser());

        $result = $this->voter->vote($this->tokenFor($stranger), $avis, [AvisVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'seul l\'auteur de l\'avis peut le modifier (IDOR)');
    }

    public function testDeleteGrantedToAuthor(): void
    {
        $auteur = $this->makeUser();
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($this->makeUser());

        $result = $this->voter->vote($this->tokenFor($auteur), $avis, [AvisVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDeleteDeniedToTheRatedUser(): void
    {
        $auteur = $this->makeUser();
        $cible = $this->makeUser();
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($cible);

        $result = $this->voter->vote($this->tokenFor($cible), $avis, [AvisVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'la personne notee ne peut pas supprimer un avis qui la concerne, seul son auteur le peut (sinon elle pourrait effacer une mauvaise note)');
    }

    public function testDeleteGrantedToAdminForModeration(): void
    {
        $auteur = $this->makeUser();
        $admin = $this->makeUser(['ROLE_ADMIN']);
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($this->makeUser());

        $result = $this->voter->vote($this->tokenFor($admin), $avis, [AvisVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}
