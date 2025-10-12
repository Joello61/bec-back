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

    public function countByRole(string $role): int
    {
        // Récupérer tous les users et filtrer en PHP
        $qb = $this->createQueryBuilder('u')
            ->select('u.id, u.roles');

        $users = $qb->getQuery()->getResult();

        $count = 0;
        foreach ($users as $user) {
            if (in_array($role, $user['roles'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Liste TOUS les utilisateurs (pour admin) sans filtre de visibilité
     */
    public function findAllPaginatedAdmin(int $page, int $limit, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.settings', 's')
            ->addSelect('s')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if (isset($filters['banned'])) {
            $qb->andWhere('u.isBanned = :banned')
                ->setParameter('banned', $filters['banned']);
        }

        if (isset($filters['role'])) {
            $qb->andWhere('u.roles LIKE :role')
                ->setParameter('role', '%' . $filters['role'] . '%');
        }

        if (isset($filters['verified'])) {
            $qb->andWhere('u.emailVerifie = :verified')
                ->setParameter('verified', $filters['verified']);
        }

        $users = $qb->getQuery()->getResult();

        // Compter avec les mêmes filtres
        $countQb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)');

        if (isset($filters['banned'])) {
            $countQb->andWhere('u.isBanned = :banned')
                ->setParameter('banned', $filters['banned']);
        }

        if (isset($filters['role'])) {
            $countQb->andWhere('u.roles LIKE :role')
                ->setParameter('role', '%' . $filters['role'] . '%');
        }

        if (isset($filters['verified'])) {
            $countQb->andWhere('u.emailVerifie = :verified')
                ->setParameter('verified', $filters['verified']);
        }

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
     * Trouve les utilisateurs par rôle
     */
    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%' . $role . '%')
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les utilisateurs bannis
     */
    public function countBanned(): int
    {
        return $this->count(['isBanned' => true]);
    }

    /**
     * Trouve les utilisateurs bannis
     */
    public function findBanned(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.isBanned = :banned')
            ->setParameter('banned', true)
            ->orderBy('u.bannedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les nouvelles inscriptions entre deux dates
     */
    public function countCreatedBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
