<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findByUser(int $userId, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findUnreadByUser(int $userId): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :userId')
            ->andWhere('n.lue = :lue')
            ->setParameter('userId', $userId)
            ->setParameter('lue', false)
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countUnread(int $userId): int
    {
        return $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :userId')
            ->andWhere('n.lue = :lue')
            ->setParameter('userId', $userId)
            ->setParameter('lue', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAllAsRead(int $userId): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.lue', ':lue')
            ->where('n.user = :userId')
            ->setParameter('lue', true)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    public function deleteOldNotifications(int $days = 30): void
    {
        $date = new \DateTime();
        $date->modify("-{$days} days");

        $this->createQueryBuilder('n')
            ->delete()
            ->where('n.createdAt < :date')
            ->andWhere('n.lue = :lue')
            ->setParameter('date', $date)
            ->setParameter('lue', true)
            ->getQuery()
            ->execute();
    }
}
