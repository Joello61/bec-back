<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Demande;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DemandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Demande::class);
    }

    public function findPaginated(int $page = 1, int $limit = 10, array $filters = [], ?User $excludeUser = null): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->where('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($excludeUser && !in_array('ROLE_ADMIN', $excludeUser->getRoles(), true)) {
            $qb->andWhere('d.client != :excludedUser')
                ->setParameter('excludedUser', $excludeUser);
        }

        if (!empty($filters['villeDepart'])) {
            $qb->andWhere('d.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }

        if (!empty($filters['villeArrivee'])) {
            $qb->andWhere('d.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }

        if (!empty($filters['dateLimite'])) {
            $qb->andWhere('d.dateLimite <= :today')
                ->setParameter('today', new \DateTime('today'));
        }

        if (!empty($filters['statut'])) {
            $qb->andWhere('d.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        } else {
            $qb->andWhere('d.statut = :statut')
                ->setParameter('statut', 'en_recherche');
        }

        $demandes = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            // ==================== MÊME FILTRE POUR LE COUNT ====================
            ->where('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true);

        if ($excludeUser && !in_array('ROLE_ADMIN', $excludeUser->getRoles(), true)) {
            $countQb->andWhere('d.client != :excludedUser')
                ->setParameter('excludedUser', $excludeUser);
        }

        if (!empty($filters['villeDepart'])) {
            $countQb->andWhere('d.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }
        if (!empty($filters['villeArrivee'])) {
            $countQb->andWhere('d.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }

        if (!empty($filters['dateLimite'])) {
            $countQb->andWhere('d.dateLimite <= :today')
                ->setParameter('today', new \DateTime('today'));
        }

        if (!empty($filters['statut'])) {
            $countQb->andWhere('d.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        } else {
            $countQb->andWhere('d.statut = :statut')
                ->setParameter('statut', 'en_recherche');
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $demandes,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function findByClient(int $clientId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.client = :clientId')
            ->setParameter('clientId', $clientId)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findEnRecherche(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->where('d.statut = :statut')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->andWhere('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('statut', 'en_recherche')
            ->setParameter('visible', true)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findMatchingVoyage(string $villeDepart, string $villeArrivee, ?\DateTimeInterface $dateDepart = null, ?int $excludeUserId = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->where('d.statut = :statut')
            ->andWhere('d.villeDepart LIKE :villeDepart')
            ->andWhere('d.villeArrivee LIKE :villeArrivee')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->andWhere('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('statut', 'en_recherche')
            ->setParameter('villeDepart', '%' . $villeDepart . '%')
            ->setParameter('villeArrivee', '%' . $villeArrivee . '%')
            ->setParameter('visible', true);

        if ($excludeUserId !== null) {
            $qb->andWhere('u.id != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId);
        }

        if ($dateDepart) {
            $qb->andWhere('(d.dateLimite IS NULL OR d.dateLimite >= :dateDepart)')
                ->setParameter('dateDepart', $dateDepart);
        }

        return $qb->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Liste TOUTES les demandes (pour admin) sans filtre de visibilité
     */
    public function findAllPaginatedAdmin(int $page, int $limit, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // Pas de filtre showInSearchResults pour admin

        if (!empty($filters['villeDepart'])) {
            $qb->andWhere('d.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }

        if (!empty($filters['villeArrivee'])) {
            $qb->andWhere('d.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }

        if (!empty($filters['statut'])) {
            $qb->andWhere('d.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        }

        $demandes = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)');

        if (!empty($filters['villeDepart'])) {
            $countQb->andWhere('d.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }
        if (!empty($filters['villeArrivee'])) {
            $countQb->andWhere('d.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }
        if (!empty($filters['statut'])) {
            $countQb->andWhere('d.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $demandes,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * Compte les demandes par statut
     */
    public function countByStatut(string $statut): int
    {
        return $this->count(['statut' => $statut]);
    }

    /**
     * Compte les demandes créées entre deux dates
     */
    public function countCreatedBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findExpiredDemandes(\DateTimeInterface $today): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.dateLimite < :today')
            ->andWhere('d.statut = :statut')
            ->setParameter('today', $today)
            ->setParameter('statut', 'en_recherche')
            ->getQuery()
            ->getResult();
    }
}
