<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdminLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminLog::class);
    }

    /**
     * Récupère les logs récents avec pagination
     */
    public function findRecentPaginated(int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.admin', 'a')
            ->addSelect('a')
            ->orderBy('l.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $logs = $qb->getQuery()->getResult();

        $total = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'data' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    /**
     * Récupère les logs récents (simple)
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.admin', 'a')
            ->addSelect('a')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les logs d'un admin spécifique
     */
    public function findByAdmin(User $admin, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.admin = :admin')
            ->setParameter('admin', $admin)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les logs par type de cible (user, voyage, demande, etc.)
     */
    public function findByTargetType(string $targetType, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.admin', 'a')
            ->addSelect('a')
            ->where('l.targetType = :targetType')
            ->setParameter('targetType', $targetType)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les logs par action spécifique
     */
    public function findByAction(string $action, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.admin', 'a')
            ->addSelect('a')
            ->where('l.action = :action')
            ->setParameter('action', $action)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère tous les logs concernant une cible spécifique
     */
    public function findByTarget(string $targetType, int $targetId): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.admin', 'a')
            ->addSelect('a')
            ->where('l.targetType = :targetType')
            ->andWhere('l.targetId = :targetId')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche dans les logs avec filtres
     */
    public function search(array $filters = [], int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.admin', 'a')
            ->addSelect('a')
            ->orderBy('l.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if (!empty($filters['action'])) {
            $qb->andWhere('l.action = :action')
                ->setParameter('action', $filters['action']);
        }

        if (!empty($filters['targetType'])) {
            $qb->andWhere('l.targetType = :targetType')
                ->setParameter('targetType', $filters['targetType']);
        }

        if (!empty($filters['adminId'])) {
            $qb->andWhere('l.admin = :adminId')
                ->setParameter('adminId', $filters['adminId']);
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('l.createdAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere('l.createdAt <= :dateTo')
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
        }

        $logs = $qb->getQuery()->getResult();

        // Compter avec les mêmes filtres
        $countQb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)');

        if (!empty($filters['action'])) {
            $countQb->andWhere('l.action = :action')
                ->setParameter('action', $filters['action']);
        }

        if (!empty($filters['targetType'])) {
            $countQb->andWhere('l.targetType = :targetType')
                ->setParameter('targetType', $filters['targetType']);
        }

        if (!empty($filters['adminId'])) {
            $countQb->andWhere('l.admin = :adminId')
                ->setParameter('adminId', $filters['adminId']);
        }

        if (!empty($filters['dateFrom'])) {
            $countQb->andWhere('l.createdAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (!empty($filters['dateTo'])) {
            $countQb->andWhere('l.createdAt <= :dateTo')
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    /**
     * Compte le nombre d'actions par admin
     */
    public function countByAdmin(User $admin): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.admin = :admin')
            ->setParameter('admin', $admin)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère les statistiques d'actions sur une période
     */
    public function getActionStats(\DateTime $startDate, \DateTime $endDate): array
    {
        $results = $this->createQueryBuilder('l')
            ->select('l.action, COUNT(l.id) as count')
            ->where('l.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('l.action')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $result) {
            $stats[$result['action']] = (int) $result['count'];
        }

        return $stats;
    }

    /**
     * Supprime les anciens logs (nettoyage automatique)
     */
    public function deleteOlderThan(\DateTime $date): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}
