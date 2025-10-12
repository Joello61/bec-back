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
            ->leftJoin('u.address', 'a')
            ->addSelect('a')
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
            ->leftJoin('u.address', 'a')
            ->addSelect('s', 'a')
            ->where('s.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true)
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $users = $qb->getQuery()->getResult();

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

    public function search(string $query): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.settings', 's')
            ->leftJoin('u.address', 'a')
            ->addSelect('s', 'a')
            ->where('u.nom LIKE :query OR u.prenom LIKE :query OR u.email LIKE :query')
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
            ->leftJoin('u.address', 'a')
            ->addSelect('a')
            ->where('u.emailVerifie = :verified')
            ->setParameter('verified', true)
            ->getQuery()
            ->getResult();
    }

    public function countByRole(string $role): int
    {
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

    public function findAllPaginatedAdmin(int $page, int $limit, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.settings', 's')
            ->leftJoin('u.address', 'a')
            ->addSelect('s', 'a')
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

    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.address', 'a')
            ->addSelect('a')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%' . $role . '%')
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countBanned(): int
    {
        return $this->count(['isBanned' => true]);
    }

    public function findBanned(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.address', 'a')
            ->addSelect('a')
            ->where('u.isBanned = :banned')
            ->setParameter('banned', true)
            ->orderBy('u.bannedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

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

    /**
     * Trouve un utilisateur avec son adresse chargée
     */
    public function findOneWithAddress(int $id): ?User
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.address', 'a')
            ->addSelect('a')
            ->where('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve les utilisateurs par ville via leur adresse
     */
    public function findByVille(string $ville): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.address', 'a')
            ->addSelect('a')
            ->where('a.ville LIKE :ville')
            ->setParameter('ville', '%' . $ville . '%')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les utilisateurs par pays via leur adresse
     */
    public function findByPays(string $pays): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.address', 'a')
            ->addSelect('a')
            ->where('a.pays LIKE :pays')
            ->setParameter('pays', '%' . $pays . '%')
            ->getQuery()
            ->getResult();
    }
}
