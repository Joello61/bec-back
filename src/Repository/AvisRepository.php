<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Avis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AvisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Avis::class);
    }

    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.auteur', 'auteur')
            ->leftJoin('a.voyage', 'v')
            ->addSelect('auteur', 'v')
            ->where('a.cible = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByVoyage(int $voyageId): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.auteur', 'auteur')
            ->leftJoin('a.cible', 'cible')
            ->addSelect('auteur', 'cible')
            ->where('a.voyage = :voyageId')
            ->setParameter('voyageId', $voyageId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getAverageNote(int $userId): ?float
    {
        $result = $this->createQueryBuilder('a')
            ->select('AVG(a.note) as moyenne')
            ->where('a.cible = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['moyenne'] ? (float) $result['moyenne'] : null;
    }

    public function countByUser(int $userId): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.cible = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByAuteurAndCible(int $auteurId, int $cibleId): ?Avis
    {
        return $this->createQueryBuilder('a')
            ->where('a.auteur = :auteurId')
            ->andWhere('a.cible = :cibleId')
            ->setParameter('auteurId', $auteurId)
            ->setParameter('cibleId', $cibleId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getStatsByUser(int $userId): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.note, COUNT(a.id) as count')
            ->where('a.cible = :userId')
            ->setParameter('userId', $userId)
            ->groupBy('a.note')
            ->orderBy('a.note', 'DESC');

        $results = $qb->getQuery()->getResult();

        $stats = [
            'total' => 0,
            'average' => 0,
            'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        ];

        $sum = 0;
        foreach ($results as $result) {
            $note = (int) $result['note'];
            $count = (int) $result['count'];
            $stats['distribution'][$note] = $count;
            $stats['total'] += $count;
            $sum += $note * $count;
        }

        if ($stats['total'] > 0) {
            $stats['average'] = round($sum / $stats['total'], 1);
        }

        return $stats;
    }
}
