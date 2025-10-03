<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Demande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DemandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Demande::class);
    }

    public function findPaginated(int $page = 1, int $limit = 10, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->addSelect('u')
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

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
        } else {
            $qb->andWhere('d.statut = :statut')
                ->setParameter('statut', 'en_recherche');
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
            ->where('d.statut = :statut')
            ->setParameter('statut', 'en_recherche')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findMatchingVoyage(string $villeDepart, string $villeArrivee, ?\DateTime $dateDepart = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.statut = :statut')
            ->andWhere('d.villeDepart LIKE :villeDepart')
            ->andWhere('d.villeArrivee LIKE :villeArrivee')
            ->setParameter('statut', 'en_recherche')
            ->setParameter('villeDepart', '%' . $villeDepart . '%')
            ->setParameter('villeArrivee', '%' . $villeArrivee . '%');

        if ($dateDepart) {
            $qb->andWhere('(d.dateLimite IS NULL OR d.dateLimite >= :dateDepart)')
                ->setParameter('dateDepart', $dateDepart);
        }

        return $qb->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}
