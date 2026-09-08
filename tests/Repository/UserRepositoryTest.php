<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\UserRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : regression sur
 * findByRole (meme bug LIKE-sur-JSON que findAllPaginatedAdmin, corrige au Lot 9 - corrige
 * ici selon le meme patron) et couverture de countByRole.
 */
class UserRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }

    public function testFindByRoleReturnsOnlyMatchingUsers(): void
    {
        $moderator = $this->createUser('repo-user-role-mod');
        $moderator->setRoles(['ROLE_MODERATOR']);
        $regular = $this->createUser('repo-user-role-regular');
        $this->em->flush();

        $result = $this->userRepository->findByRole('ROLE_MODERATOR');

        $ids = array_map(fn ($u) => $u->getId(), $result);
        self::assertContains($moderator->getId(), $ids);
        self::assertNotContains($regular->getId(), $ids);
    }

    public function testFindByRoleReturnsEmptyArrayWhenNoneMatch(): void
    {
        $result = $this->userRepository->findByRole('ROLE_INEXISTANT');

        self::assertSame([], $result);
    }

    public function testCountByRoleCountsAllUsersWithTheRole(): void
    {
        $admin1 = $this->createUser('repo-user-countrole-admin1');
        $admin1->setRoles(['ROLE_ADMIN']);
        $admin2 = $this->createUser('repo-user-countrole-admin2');
        $admin2->setRoles(['ROLE_ADMIN']);
        $this->createUser('repo-user-countrole-regular');
        $this->em->flush();

        $count = $this->userRepository->countByRole('ROLE_ADMIN');

        self::assertGreaterThanOrEqual(2, $count);
    }

    // ==================== exclusion des comptes soft-deleted (Phase 5) ====================

    public function testFindPaginatedExcludesASoftDeletedUser(): void
    {
        $active = $this->createUser('repo-user-paginated-active');
        $deleted = $this->createUser('repo-user-paginated-deleted');
        $deleted->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $result = $this->userRepository->findPaginated(1, 100);

        $ids = array_map(fn ($u) => $u->getId(), $result['data']);
        self::assertContains($active->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testSearchExcludesASoftDeletedUser(): void
    {
        $prefix = 'repo-user-search-' . uniqid();
        $active = $this->createUser($prefix . '-active');
        $deleted = $this->createUser($prefix . '-deleted');
        $deleted->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $result = $this->userRepository->search($prefix);

        $ids = array_map(fn ($u) => $u->getId(), $result);
        self::assertContains($active->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }
}
