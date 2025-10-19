<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Voyage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class VoyageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Voyage::class);
    }

    public function findPaginated(int $page = 1, int $limit = 10, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->where('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true)
            ->orderBy('v.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if (!empty($filters['villeDepart'])) {
            $qb->andWhere('v.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }

        if (!empty($filters['villeArrivee'])) {
            $qb->andWhere('v.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }

        if (!empty($filters['dateDepart'])) {
            $qb->andWhere('v.dateDepart >= :dateDepart')
                ->setParameter('dateDepart', new \DateTime($filters['dateDepart']));
        }

        if (!empty($filters['statut'])) {
            $qb->andWhere('v.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        } else {
            $qb->andWhere('v.statut = :statut')
                ->setParameter('statut', 'actif');
        }

        $voyages = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            // ==================== MÊME FILTRE POUR LE COUNT ====================
            ->where('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true);

        if (!empty($filters['villeDepart'])) {
            $countQb->andWhere('v.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }
        if (!empty($filters['villeArrivee'])) {
            $countQb->andWhere('v.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }
        if (!empty($filters['dateDepart'])) {
            $countQb->andWhere('v.dateDepart >= :dateDepart')
                ->setParameter('dateDepart', new \DateTime($filters['dateDepart']));
        }
        if (!empty($filters['statut'])) {
            $countQb->andWhere('v.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        } else {
            $countQb->andWhere('v.statut = :statut')
                ->setParameter('statut', 'actif');
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $voyages,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function findByVoyageur(int $voyageurId): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.voyageur = :voyageurId')
            ->setParameter('voyageurId', $voyageurId)
            ->orderBy('v.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActifs(): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->where('v.statut = :statut')
            ->andWhere('v.dateDepart >= :today')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->andWhere('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('statut', 'actif')
            ->setParameter('today', new \DateTime())
            ->setParameter('visible', true)
            ->orderBy('v.dateDepart', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findMatchingDemande(string $villeDepart, string $villeArrivee, ?\DateTimeInterface $dateDepart = null, ?int $excludeUserId = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->where('v.statut = :statut')
            ->andWhere('v.villeDepart LIKE :villeDepart')
            ->andWhere('v.villeArrivee LIKE :villeArrivee')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->andWhere('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('statut', 'actif')
            ->setParameter('villeDepart', '%' . $villeDepart . '%')
            ->setParameter('villeArrivee', '%' . $villeArrivee . '%')
            ->setParameter('visible', true);

        if ($excludeUserId !== null) {
            $qb->andWhere('u.id != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId);
        }

        if ($dateDepart) {
            $qb->andWhere('v.dateDepart >= :dateDepart')
                ->setParameter('dateDepart', $dateDepart);
        }

        return $qb->orderBy('v.dateDepart', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Liste TOUS les voyages (pour admin) sans filtre de visibilité
     */
    public function findAllPaginatedAdmin(int $page, int $limit, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->orderBy('v.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // Pas de filtre showInSearchResults pour admin

        if (!empty($filters['villeDepart'])) {
            $qb->andWhere('v.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }

        if (!empty($filters['villeArrivee'])) {
            $qb->andWhere('v.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }

        if (!empty($filters['dateDepart'])) {
            $qb->andWhere('v.dateDepart >= :dateDepart')
                ->setParameter('dateDepart', new \DateTime($filters['dateDepart']));
        }

        if (!empty($filters['statut'])) {
            $qb->andWhere('v.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        }

        $voyages = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)');

        if (!empty($filters['villeDepart'])) {
            $countQb->andWhere('v.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }
        if (!empty($filters['villeArrivee'])) {
            $countQb->andWhere('v.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }
        if (!empty($filters['dateDepart'])) {
            $countQb->andWhere('v.dateDepart >= :dateDepart')
                ->setParameter('dateDepart', new \DateTime($filters['dateDepart']));
        }
        if (!empty($filters['statut'])) {
            $countQb->andWhere('v.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $voyages,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * Compte les voyages par statut
     */
    public function countByStatut(string $statut): int
    {
        return $this->count(['statut' => $statut]);
    }

    /**
     * Compte les voyages créés entre deux dates
     */
    public function countCreatedBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findExpiredVoyages(\DateTimeInterface $today): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.dateDepart < :today')
            ->andWhere('v.statut = :statut')
            ->setParameter('today', $today)
            ->setParameter('statut', 'actif')
            ->getQuery()
            ->getResult();
    }
}
