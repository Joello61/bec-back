<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VerificationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class VerificationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VerificationCode::class);
    }

    public function findValidCodeForEmail(string $email, string $code): ?VerificationCode
    {
        return $this->createQueryBuilder('v')
            ->where('v.email = :email')
            ->andWhere('v.code = :code')
            ->andWhere('v.type = :type')
            ->andWhere('v.used = false')
            ->andWhere('v.expiresAt > :now')
            ->setParameter('email', $email)
            ->setParameter('code', $code)
            ->setParameter('type', 'email')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findValidCodeForPhone(string $phone, string $code): ?VerificationCode
    {
        return $this->createQueryBuilder('v')
            ->where('v.phone = :phone')
            ->andWhere('v.code = :code')
            ->andWhere('v.type = :type')
            ->andWhere('v.used = false')
            ->andWhere('v.expiresAt > :now')
            ->setParameter('phone', $phone)
            ->setParameter('code', $code)
            ->setParameter('type', 'phone')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteExpiredCodes(): int
    {
        return $this->createQueryBuilder('v')
            ->delete()
            ->where('v.expiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }

    public function deleteUsedCodes(): int
    {
        return $this->createQueryBuilder('v')
            ->delete()
            ->where('v.used = true')
            ->andWhere('v.usedAt < :oneWeekAgo')
            ->setParameter('oneWeekAgo', new \DateTime('-1 week'))
            ->getQuery()
            ->execute();
    }

    public function deleteOldCodesForEmail(string $email): int
    {
        return $this->createQueryBuilder('v')
            ->delete()
            ->where('v.email = :email')
            ->andWhere('v.type = :type')
            ->setParameter('email', $email)
            ->setParameter('type', 'email')
            ->getQuery()
            ->execute();
    }

    public function deleteOldCodesForPhone(string $phone): int
    {
        return $this->createQueryBuilder('v')
            ->delete()
            ->where('v.phone = :phone')
            ->andWhere('v.type = :type')
            ->setParameter('phone', $phone)
            ->setParameter('type', 'phone')
            ->getQuery()
            ->execute();
    }
}
