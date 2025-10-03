<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Signalement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SignalementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Signalement::class);
    }

    public function findPaginated(int $page = 1, int $limit = 10, ?string $statut = null): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.signaleur', 'u')
            ->leftJoin('s.voyage', 'v')
            ->leftJoin('s.demande', 'd')
            ->addSelect('u', 'v', 'd')
            ->orderBy('s.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($statut) {
            $qb->andWhere('s.statut = :statut')
                ->setParameter('statut', $statut);
        }

        $signalements = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)');

        if ($statut) {
            $countQb->andWhere('s.statut = :statut')
                ->setParameter('statut', $statut);
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $signalements,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function findByStatut(string $statut): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.signaleur', 'u')
            ->leftJoin('s.voyage', 'v')
            ->leftJoin('s.demande', 'd')
            ->addSelect('u', 'v', 'd')
            ->where('s.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByVoyage(int $voyageId): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.signaleur', 'u')
            ->addSelect('u')
            ->where('s.voyage = :voyageId')
            ->setParameter('voyageId', $voyageId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByDemande(int $demandeId): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.signaleur', 'u')
            ->addSelect('u')
            ->where('s.demande = :demandeId')
            ->setParameter('demandeId', $demandeId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countEnAttente(): int
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.statut = :statut')
            ->setParameter('statut', 'en_attente')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findBySignaleur(int $signaleurId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.signaleur = :signaleurId')
            ->setParameter('signaleurId', $signaleurId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
