<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Address;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * Trouve l'adresse d'un utilisateur
     */
    public function findByUser(User $user): ?Address
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Compte les adresses modifiables (>6 mois depuis dernière modification)
     */
    public function countModifiable(): int
    {
        $sixMonthsAgo = new \DateTime('-6 months');

        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.lastModifiedAt IS NULL OR a.lastModifiedAt <= :sixMonthsAgo')
            ->setParameter('sixMonthsAgo', $sixMonthsAgo)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Trouve les adresses créées entre deux dates
     */
    public function findCreatedBetween(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les adresses par ville
     */
    public function findByVille(string $ville): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.ville LIKE :ville')
            ->setParameter('ville', '%' . $ville . '%')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les adresses par pays
     */
    public function findByPays(string $pays): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.pays LIKE :pays')
            ->setParameter('pays', '%' . $pays . '%')
            ->getQuery()
            ->getResult();
    }
}
