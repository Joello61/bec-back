<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : repository
 * de securite - findValidToken doit rejeter un token deja utilise ou expire (pas
 * seulement verifier qu'il existe), coeur du flux de reset de mot de passe.
 */
class PasswordResetTokenRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private PasswordResetTokenRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(PasswordResetTokenRepository::class);
    }

    private function token(
        User $user,
        string $token,
        \DateTimeInterface $expiresAt,
        bool $used = false,
        ?\DateTimeInterface $usedAt = null
    ): PasswordResetToken {
        $entity = new PasswordResetToken();
        $entity->setUser($user);
        $entity->setToken($token);
        $entity->setExpiresAt($expiresAt);
        $entity->setUsed($used);
        if ($usedAt !== null) {
            $entity->setUsedAt($usedAt);
        }
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    // ==================== findValidToken ====================

    public function testFindValidTokenReturnsNullForAnUnknownToken(): void
    {
        self::assertNull($this->repository->findValidToken('unknown-token'));
    }

    public function testFindValidTokenRejectsAnExpiredToken(): void
    {
        $user = $this->createUser('pwdreset-expired');
        $this->token($user, 'token-expired-unique', new \DateTime('-1 hour'));

        self::assertNull($this->repository->findValidToken('token-expired-unique'));
    }

    public function testFindValidTokenRejectsAnAlreadyUsedToken(): void
    {
        $user = $this->createUser('pwdreset-used');
        $this->token($user, 'token-used-unique', new \DateTime('+1 hour'), used: true, usedAt: new \DateTime());

        self::assertNull($this->repository->findValidToken('token-used-unique'));
    }

    public function testFindValidTokenAcceptsAFreshUnusedToken(): void
    {
        $user = $this->createUser('pwdreset-valid');
        $this->token($user, 'token-valid-unique', new \DateTime('+1 hour'));

        $found = $this->repository->findValidToken('token-valid-unique');

        self::assertNotNull($found);
        self::assertSame($user->getId(), $found->getUser()->getId());
    }

    // ==================== deleteExpiredTokens ====================

    public function testDeleteExpiredTokensOnlyRemovesExpiredOnes(): void
    {
        $user = $this->createUser('pwdreset-delete-expired');
        $this->token($user, 'token-del-expired-unique', new \DateTime('-1 hour'));
        $this->token($user, 'token-del-valid-unique', new \DateTime('+1 hour'));

        $deleted = $this->repository->deleteExpiredTokens();

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertNotNull($this->repository->findValidToken('token-del-valid-unique'));
    }

    // ==================== deleteUsedTokens ====================

    public function testDeleteUsedTokensOnlyRemovesTokensUsedOverAWeekAgo(): void
    {
        $user = $this->createUser('pwdreset-delete-used');
        $old = $this->token($user, 'token-del-old-used-unique', new \DateTime('+1 hour'), used: true, usedAt: new \DateTime('-2 weeks'));
        $recent = $this->token($user, 'token-del-recent-used-unique', new \DateTime('+1 hour'), used: true, usedAt: new \DateTime('-1 hour'));

        $oldId = $old->getId();
        $recentId = $recent->getId();

        $this->repository->deleteUsedTokens();

        // deleteUsedTokens() est un DELETE DQL en masse : il ne passe pas par l'UnitOfWork
        // et ne retire donc pas $old de l'identity map - sans ce clear(), find() renverrait
        // encore l'objet PHP en memoire au lieu d'interroger la base.
        $this->em->clear();

        self::assertNull($this->em->getRepository(PasswordResetToken::class)->find($oldId));
        self::assertNotNull($this->em->getRepository(PasswordResetToken::class)->find($recentId));
    }

    // ==================== deleteOldTokensForUser ====================

    public function testDeleteOldTokensForUserRemovesOnlyThatUsersTokens(): void
    {
        $target = $this->createUser('pwdreset-deleteforuser-target');
        $other = $this->createUser('pwdreset-deleteforuser-other');
        $this->token($target, 'token-target-unique', new \DateTime('+1 hour'));
        $this->token($other, 'token-other-unique', new \DateTime('+1 hour'));

        $this->repository->deleteOldTokensForUser($target);

        self::assertNull($this->repository->findValidToken('token-target-unique'));
        self::assertNotNull($this->repository->findValidToken('token-other-unique'));
    }

    // ==================== countRecentTokensForUser ====================

    public function testCountRecentTokensForUserOnlyCountsWithinTheWindow(): void
    {
        $user = $this->createUser('pwdreset-countrecent');
        $this->token($user, 'token-recent1-unique', new \DateTime('+1 hour'));
        $this->token($user, 'token-recent2-unique', new \DateTime('+1 hour'));

        $count = $this->repository->countRecentTokensForUser($user, 60);

        self::assertSame(2, $count);
    }
}
