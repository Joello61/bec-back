<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findValidToken(string $token): ?PasswordResetToken
    {
        return $this->createQueryBuilder('p')
            ->where('p.token = :token')
            ->andWhere('p.used = false')
            ->andWhere('p.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteExpiredTokens(): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->where('p.expiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }

    public function deleteUsedTokens(): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->where('p.used = true')
            ->andWhere('p.usedAt < :oneWeekAgo')
            ->setParameter('oneWeekAgo', new \DateTime('-1 week'))
            ->getQuery()
            ->execute();
    }

    public function deleteOldTokensForUser(User $user): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function countRecentTokensForUser(User $user, int $minutes = 60): int
    {
        $since = new \DateTime("-{$minutes} minutes");

        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.user = :user')
            ->andWhere('p.createdAt > :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
