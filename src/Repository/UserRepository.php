<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPaginated(int $page = 1, int $limit = 10): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.settings', 's')
            ->addSelect('s')
            // Filtrer uniquement les utilisateurs visibles dans les résultats de recherche
            ->where('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true)
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $users = $qb->getQuery()->getResult();

        // Compter le total avec le même filtre
        $countQb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->leftJoin('u.settings', 's')
            ->where('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true);

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    /**
     * Recherche d'utilisateurs avec filtrage par settings
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.settings', 's')
            ->addSelect('s')
            ->where('u.nom LIKE :query OR u.prenom LIKE :query OR u.email LIKE :query')
            // Filtrer uniquement les utilisateurs visibles dans les résultats de recherche
            ->andWhere('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('query', '%' . $query . '%')
            ->setParameter('visible', true)
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    public function findVerifiedUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.emailVerifie = :verified')
            ->setParameter('verified', true)
            ->getQuery()
            ->getResult();
    }
}
