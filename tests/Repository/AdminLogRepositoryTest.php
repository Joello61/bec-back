<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AdminLog;
use App\Entity\User;
use App\Repository\AdminLogRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee des methodes non triviales d'AdminLogRepository (pagination, filtres
 * combines, agregation) - le reste (delegations simples) est deja couvert via
 * AuditLogServiceTest (Lot 7, avec repository mocke).
 */
class AdminLogRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private AdminLogRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(AdminLogRepository::class);
    }

    private function log(User $admin, string $action, string $targetType = 'user', int $targetId = 1): AdminLog
    {
        $log = new AdminLog();
        $log->setAdmin($admin)
            ->setAction($action)
            ->setTargetType($targetType)
            ->setTargetId($targetId);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    // ==================== findRecentPaginated ====================

    public function testFindRecentPaginatedComputesTheRightPageCount(): void
    {
        $admin = $this->createUser('adminlog-paginated-admin');
        for ($i = 0; $i < 5; $i++) {
            $this->log($admin, 'action_' . $i);
        }

        $result = $this->repository->findRecentPaginated(1, 2);

        self::assertCount(2, $result['data']);
        self::assertGreaterThanOrEqual(5, $result['pagination']['total']);
        self::assertGreaterThanOrEqual(3, $result['pagination']['pages']);
    }

    public function testFindRecentPaginatedOrdersByCreatedAtDescending(): void
    {
        // createdAt est fixe au PrePersist (pas de setter public) : deux logs crees dans
        // la meme seconde peuvent avoir un ordre de depart indetermine entre eux - on
        // verifie donc une propriete plus robuste (suite non-croissante) plutot que la
        // position relative de deux entrees precises.
        $admin = $this->createUser('adminlog-order-admin');
        $this->log($admin, 'first_action');
        $this->log($admin, 'second_action');

        $result = $this->repository->findRecentPaginated(1, 50);

        $timestamps = array_map(fn (AdminLog $l) => $l->getCreatedAt()->getTimestamp(), $result['data']);
        $sorted = $timestamps;
        rsort($sorted);
        self::assertSame($sorted, $timestamps);
    }

    // ==================== search ====================

    public function testSearchCombinesMultipleFilters(): void
    {
        $admin1 = $this->createUser('adminlog-search-admin1');
        $admin2 = $this->createUser('adminlog-search-admin2');
        $this->log($admin1, 'ban_user', targetType: 'user');
        $this->log($admin1, 'delete_voyage', targetType: 'voyage');
        $this->log($admin2, 'ban_user', targetType: 'user');

        $result = $this->repository->search(['action' => 'ban_user', 'adminId' => $admin1->getId()], 1, 50);

        self::assertCount(1, $result['data']);
        self::assertSame('ban_user', $result['data'][0]->getAction());
        self::assertSame($admin1->getId(), $result['data'][0]->getAdmin()->getId());
    }

    public function testSearchFiltersByDateRange(): void
    {
        $admin = $this->createUser('adminlog-search-date-admin');
        $this->log($admin, 'in_range_action');

        $result = $this->repository->search([
            'dateFrom' => (new \DateTime('-1 hour'))->format('Y-m-d H:i:s'),
            'dateTo' => (new \DateTime('+1 hour'))->format('Y-m-d H:i:s'),
        ], 1, 50);

        self::assertNotEmpty($result['data']);

        $emptyResult = $this->repository->search([
            'dateFrom' => (new \DateTime('-2 days'))->format('Y-m-d H:i:s'),
            'dateTo' => (new \DateTime('-1 day'))->format('Y-m-d H:i:s'),
        ], 1, 50);

        self::assertSame(0, $emptyResult['pagination']['total']);
    }

    // ==================== getActionStats ====================

    public function testGetActionStatsGroupsAndCountsByAction(): void
    {
        $admin = $this->createUser('adminlog-stats-admin');
        $this->log($admin, 'ban_user');
        $this->log($admin, 'ban_user');
        $this->log($admin, 'delete_voyage');

        $stats = $this->repository->getActionStats(new \DateTime('-1 hour'), new \DateTime('+1 hour'));

        self::assertSame(2, $stats['ban_user']);
        self::assertSame(1, $stats['delete_voyage']);
    }

    public function testGetActionStatsExcludesActionsOutsideTheRange(): void
    {
        $admin = $this->createUser('adminlog-stats-outside-admin');
        $this->log($admin, 'outside_range_action');

        $stats = $this->repository->getActionStats(new \DateTime('-2 days'), new \DateTime('-1 day'));

        self::assertArrayNotHasKey('outside_range_action', $stats);
    }

    // ==================== deleteOlderThan ====================

    public function testDeleteOlderThanOnlyRemovesLogsBeforeTheDate(): void
    {
        $admin = $this->createUser('adminlog-deleteolder-admin');
        $recent = $this->log($admin, 'recent_action');

        // On ne peut pas forcer createdAt (PrePersist) via un setter public - on verifie
        // simplement qu'un seuil dans le futur proche ne supprime pas un log qui vient
        // d'etre cree a l'instant.
        $deleted = $this->repository->deleteOlderThan(new \DateTime('-1 minute'));
        $this->em->clear();

        self::assertSame(0, $deleted);
        self::assertNotNull($this->em->getRepository(AdminLog::class)->find($recent->getId()));
    }
}
