<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : repository
 * de securite - findOneNonExpiredBySelector est le coeur du flux de rotation du refresh
 * token (JWTCookieAuthenticator/TokenController), ne doit jamais renvoyer un token expire.
 */
class RefreshTokenRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private RefreshTokenRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(RefreshTokenRepository::class);
    }

    private function refreshToken(User $user, string $selector, \DateTimeImmutable $expiresAt): RefreshToken
    {
        $token = new RefreshToken();
        $token->setUser($user);
        $token->setSelector($selector);
        $token->setValidatorHash('hash-' . $selector);
        $token->setExpiresAt($expiresAt);
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    // ==================== findOneNonExpiredBySelector ====================

    public function testFindOneNonExpiredBySelectorReturnsNullForAnUnknownSelector(): void
    {
        self::assertNull($this->repository->findOneNonExpiredBySelector('unknown-selector'));
    }

    public function testFindOneNonExpiredBySelectorRejectsAnExpiredToken(): void
    {
        $user = $this->createUser('refresh-expired');
        $this->refreshToken($user, 'selector-expired-unique', new \DateTimeImmutable('-1 hour'));

        self::assertNull($this->repository->findOneNonExpiredBySelector('selector-expired-unique'));
    }

    public function testFindOneNonExpiredBySelectorAcceptsAFreshToken(): void
    {
        $user = $this->createUser('refresh-valid');
        $this->refreshToken($user, 'selector-valid-unique', new \DateTimeImmutable('+1 hour'));

        $found = $this->repository->findOneNonExpiredBySelector('selector-valid-unique');

        self::assertNotNull($found);
        self::assertSame($user->getId(), $found->getUser()->getId());
    }

    // ==================== findAllRecent ====================

    public function testFindAllRecentExcludesExpiredTokens(): void
    {
        $user = $this->createUser('refresh-findrecent');
        $this->refreshToken($user, 'selector-recent-valid-unique', new \DateTimeImmutable('+1 hour'));
        $this->refreshToken($user, 'selector-recent-expired-unique', new \DateTimeImmutable('-1 hour'));

        $result = $this->repository->findAllRecent();

        $selectors = array_map(fn (RefreshToken $t) => $t->getSelector(), $result);
        self::assertContains('selector-recent-valid-unique', $selectors);
        self::assertNotContains('selector-recent-expired-unique', $selectors);
    }

    // ==================== deleteExpiredTokens ====================

    public function testDeleteExpiredTokensOnlyRemovesExpiredOnes(): void
    {
        $user = $this->createUser('refresh-deleteexpired');
        $valid = $this->refreshToken($user, 'selector-del-valid-unique', new \DateTimeImmutable('+1 hour'));
        $this->refreshToken($user, 'selector-del-expired-unique', new \DateTimeImmutable('-1 hour'));

        $deleted = $this->repository->deleteExpiredTokens();
        $this->em->clear();

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertNotNull($this->em->getRepository(RefreshToken::class)->find($valid->getId()));
    }
}
