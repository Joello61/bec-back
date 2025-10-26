<?php

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RefreshTokenRepository extends ServiceEntityRepository
{

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    /**
     * Trouve tous les tokens non expirés (simplification pour la validation).
     * Attention : Peut être lourd si beaucoup de tokens.
     */
    public function findAllRecent(): array
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime tous les tokens qui ont expiré.
     * @return int Le nombre de tokens supprimés.
     */
    public function deleteExpiredTokens(): int
    {
        return $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute(); // Utiliser execute() pour un DELETE
    }

    /**
     * Trouve un token non expiré par son sélecteur.
     */
    public function findOneNonExpiredBySelector(string $selector): ?RefreshToken
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.selector = :selector')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('selector', $selector)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

}
