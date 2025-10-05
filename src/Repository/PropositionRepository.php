<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Proposition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PropositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Proposition::class);
    }

    /**
     * Trouver toutes les propositions pour un voyage
     */
    public function findByVoyage(int $voyageId): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')
            ->leftJoin('p.demande', 'd')
            ->addSelect('c', 'd')
            ->where('p.voyage = :voyageId')
            ->setParameter('voyageId', $voyageId)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver toutes les propositions faites par un client
     */
    public function findByClient(int $clientId): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.voyage', 'v')
            ->leftJoin('p.voyageur', 'voyageur')
            ->addSelect('v', 'voyageur')
            ->where('p.client = :clientId')
            ->setParameter('clientId', $clientId)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver toutes les propositions reçues par un voyageur
     */
    public function findByVoyageur(int $voyageurId): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')
            ->leftJoin('p.demande', 'd')
            ->leftJoin('p.voyage', 'v')
            ->addSelect('c', 'd', 'v')
            ->where('p.voyageur = :voyageurId')
            ->setParameter('voyageurId', $voyageurId)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les propositions en attente pour un voyageur
     */
    public function findPendingByVoyageur(int $voyageurId): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')
            ->leftJoin('p.demande', 'd')
            ->leftJoin('p.voyage', 'v')
            ->addSelect('c', 'd', 'v')
            ->where('p.voyageur = :voyageurId')
            ->andWhere('p.statut = :statut')
            ->setParameter('voyageurId', $voyageurId)
            ->setParameter('statut', 'en_attente')
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compter les propositions en attente pour un voyageur
     */
    public function countPendingByVoyageur(int $voyageurId): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.voyageur = :voyageurId')
            ->andWhere('p.statut = :statut')
            ->setParameter('voyageurId', $voyageurId)
            ->setParameter('statut', 'en_attente')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifier si une proposition existe déjà pour cette combinaison voyage/demande
     */
    public function existsByVoyageAndDemande(int $voyageId, int $demandeId): ?Proposition
    {
        return $this->createQueryBuilder('p')
            ->where('p.voyage = :voyageId')
            ->andWhere('p.demande = :demandeId')
            ->setParameter('voyageId', $voyageId)
            ->setParameter('demandeId', $demandeId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouver les propositions acceptées pour un voyage
     */
    public function findAcceptedByVoyage(int $voyageId): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')
            ->leftJoin('p.demande', 'd')
            ->addSelect('c', 'd')
            ->where('p.voyage = :voyageId')
            ->andWhere('p.statut = :statut')
            ->setParameter('voyageId', $voyageId)
            ->setParameter('statut', 'acceptee')
            ->orderBy('p.reponduAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
