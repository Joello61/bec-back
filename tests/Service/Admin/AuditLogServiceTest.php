<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\AdminLog;
use App\Entity\User;
use App\Repository\AdminLogRepository;
use App\Service\Admin\AuditLogService;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Phase 4b, Lot 7 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : les methodes
 * s'appuyant sur createQueryBuilder() directement sur l'EntityManager (getMostActiveAdmins,
 * getMostFrequentActions, getMostModeratedTargets, hasRecentAction) ne sont pas mockables
 * proprement avec de simples stubs PHPUnit - non couvertes ici, meme choix que pour
 * AdminStatsService::getGlobalStats et consorts.
 */
class AuditLogServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private AdminLogRepository&\PHPUnit\Framework\MockObject\MockObject $adminLogRepository;
    private RequestStack $requestStack;
    private AuditLogService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->adminLogRepository = $this->createMock(AdminLogRepository::class);
        $this->requestStack = new RequestStack();

        $this->service = new AuditLogService($this->em, $this->adminLogRepository, $this->requestStack);
    }

    private function admin(int $id, string $email = 'admin@example.test'): User
    {
        $admin = new User();
        $admin->setEmail($email);
        $admin->setNom('Admin');
        $admin->setPrenom('Test');
        $admin->setPassword('irrelevant');
        $this->setEntityId($admin, $id);

        return $admin;
    }

    private function log(User $admin, string $action, string $targetType, int $targetId): AdminLog
    {
        $log = new AdminLog();
        $log->setAdmin($admin)
            ->setAction($action)
            ->setTargetType($targetType)
            ->setTargetId($targetId);
        $log->setCreatedAtValue();

        return $log;
    }

    // ==================== logAdminAction ====================

    public function testLogAdminActionPersistsAndFlushes(): void
    {
        $admin = $this->admin(1);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(AdminLog::class));
        $this->em->expects(self::once())->method('flush');

        $log = $this->service->logAdminAction($admin, 'ban_user', 'user', 42, ['reason' => 'spam']);

        self::assertSame($admin, $log->getAdmin());
        self::assertSame('ban_user', $log->getAction());
        self::assertSame('user', $log->getTargetType());
        self::assertSame(42, $log->getTargetId());
        self::assertSame(['reason' => 'spam'], $log->getDetails());
    }

    public function testLogAdminActionCapturesIpAndUserAgentWhenARequestExists(): void
    {
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->headers->set('User-Agent', 'PHPUnit');
        $this->requestStack->push($request);

        $log = $this->service->logAdminAction($this->admin(1), 'ban_user', 'user', 42);

        self::assertSame('203.0.113.7', $log->getIpAddress());
        self::assertSame('PHPUnit', $log->getUserAgent());
    }

    public function testLogAdminActionLeavesIpAndUserAgentNullWithoutARequest(): void
    {
        $log = $this->service->logAdminAction($this->admin(1), 'ban_user', 'user', 42);

        self::assertNull($log->getIpAddress());
        self::assertNull($log->getUserAgent());
    }

    // ==================== délégations simples au repository ====================

    public function testGetRecentLogsDelegatesToTheRepository(): void
    {
        $this->adminLogRepository->expects(self::once())
            ->method('findRecentPaginated')
            ->with(2, 25)
            ->willReturn(['data' => [], 'total' => 0]);

        self::assertSame(['data' => [], 'total' => 0], $this->service->getRecentLogs(2, 25));
    }

    public function testGetLogsByAdminDelegatesToTheRepository(): void
    {
        $admin = $this->admin(1);
        $this->adminLogRepository->expects(self::once())
            ->method('findByAdmin')
            ->with($admin, 20)
            ->willReturn([]);

        self::assertSame([], $this->service->getLogsByAdmin($admin, 20));
    }

    public function testGetLogsByTargetTypeDelegatesToTheRepository(): void
    {
        $this->adminLogRepository->expects(self::once())
            ->method('findByTargetType')
            ->with('voyage', 20)
            ->willReturn([]);

        self::assertSame([], $this->service->getLogsByTargetType('voyage', 20));
    }

    public function testGetLogsByActionDelegatesToTheRepository(): void
    {
        $this->adminLogRepository->expects(self::once())
            ->method('findByAction')
            ->with('ban_user', 20)
            ->willReturn([]);

        self::assertSame([], $this->service->getLogsByAction('ban_user', 20));
    }

    public function testGetTargetHistoryDelegatesToTheRepository(): void
    {
        $this->adminLogRepository->expects(self::once())
            ->method('findByTarget')
            ->with('user', 42)
            ->willReturn([]);

        self::assertSame([], $this->service->getTargetHistory('user', 42));
    }

    public function testSearchLogsDelegatesToTheRepository(): void
    {
        $filters = ['action' => 'ban_user'];
        $this->adminLogRepository->expects(self::once())
            ->method('search')
            ->with($filters, 1, 50)
            ->willReturn(['data' => [], 'total' => 0]);

        self::assertSame(['data' => [], 'total' => 0], $this->service->searchLogs($filters));
    }

    public function testCountAdminActionsDelegatesToTheRepository(): void
    {
        $admin = $this->admin(1);
        $this->adminLogRepository->expects(self::once())
            ->method('countByAdmin')
            ->with($admin)
            ->willReturn(12);

        self::assertSame(12, $this->service->countAdminActions($admin));
    }

    public function testGetActionStatsDelegatesToTheRepository(): void
    {
        $start = new \DateTime('2026-01-01');
        $end = new \DateTime('2026-01-31');
        $this->adminLogRepository->expects(self::once())
            ->method('getActionStats')
            ->with($start, $end)
            ->willReturn(['ban_user' => 3]);

        self::assertSame(['ban_user' => 3], $this->service->getActionStats($start, $end));
    }

    public function testGetTodayStatsQueriesFromMidnightToMidnight(): void
    {
        $this->adminLogRepository->expects(self::once())
            ->method('getActionStats')
            ->with(
                self::callback(fn (\DateTime $d) => $d->format('H:i:s') === '00:00:00' && $d->format('Y-m-d') === (new \DateTime())->format('Y-m-d')),
                self::callback(fn (\DateTime $d) => $d->format('H:i:s') === '00:00:00'),
            )
            ->willReturn([]);

        $this->service->getTodayStats();
    }

    // ==================== getLogsByDate ====================

    public function testGetLogsByDateSearchesTheFullDayRange(): void
    {
        $date = new \DateTime('2026-03-14');

        $this->adminLogRepository->expects(self::once())
            ->method('search')
            ->with(
                [
                    'dateFrom' => '2026-03-14 00:00:00',
                    'dateTo' => '2026-03-14 23:59:59',
                ],
                1,
                1000
            )
            ->willReturn(['data' => [], 'total' => 0]);

        $this->service->getLogsByDate($date);
    }

    // ==================== exportLogsToCSV ====================

    public function testExportLogsToCSVBuildsAHeaderAndOneRowPerLog(): void
    {
        $admin = $this->admin(1, 'admin@cobage.test');
        $log = $this->log($admin, 'ban_user', 'user', 42);
        $this->setEntityId($log, 7);
        $log->setDetails(['reason' => 'spam']);
        $this->adminLogRepository->method('search')->willReturn(['data' => [$log], 'total' => 1]);

        $csv = $this->service->exportLogsToCSV();

        self::assertStringStartsWith("ID;Admin;Action;Type Cible;ID Cible;Date;IP;Details\n", $csv);
        self::assertStringContainsString('7;admin@cobage.test;ban_user;user;42;', $csv);
        self::assertStringContainsString('N/A', $csv, 'IP absente doit etre affichee comme N/A');
    }

    // ==================== cleanOldLogs ====================

    public function testCleanOldLogsDelegatesToTheRepositoryWithTheCutoffDate(): void
    {
        $this->adminLogRepository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(fn (\DateTime $d) => $d < new \DateTime('-360 days')))
            ->willReturn(5);

        self::assertSame(5, $this->service->cleanOldLogs());
    }

    // ==================== getDailySummary ====================

    public function testGetDailySummaryAggregatesByActionAdminAndTarget(): void
    {
        $admin1 = $this->admin(1, 'admin1@example.test');
        $admin2 = $this->admin(2, 'admin2@example.test');
        $logs = [
            $this->log($admin1, 'ban_user', 'user', 1),
            $this->log($admin1, 'ban_user', 'user', 2),
            $this->log($admin2, 'delete_voyage', 'voyage', 3),
        ];
        $this->adminLogRepository->method('search')->willReturn(['data' => $logs, 'total' => 3]);

        $summary = $this->service->getDailySummary(new \DateTime('2026-03-14'));

        self::assertSame(3, $summary['totalActions']);
        self::assertSame(['ban_user' => 2, 'delete_voyage' => 1], $summary['actionsByType']);
        self::assertSame(['admin1@example.test' => 2, 'admin2@example.test' => 1], $summary['actionsByAdmin']);
        self::assertSame(['user' => 2, 'voyage' => 1], $summary['targetsByType']);
    }

    // ==================== getAdminActivity ====================

    public function testGetAdminActivityAggregatesAndFindsTheMostActiveDay(): void
    {
        $admin = $this->admin(1, 'admin@example.test');
        $logToday1 = $this->log($admin, 'ban_user', 'user', 1);
        $logToday2 = $this->log($admin, 'ban_user', 'user', 2);
        $logYesterday = $this->log($admin, 'delete_voyage', 'voyage', 3);
        $this->setLogCreatedAt($logYesterday, new \DateTime('-1 day'));
        $this->adminLogRepository->method('search')->willReturn([
            'data' => [$logToday1, $logToday2, $logYesterday],
            'total' => 3,
        ]);

        $activity = $this->service->getAdminActivity($admin, new \DateTime('-2 days'), new \DateTime());

        self::assertSame(3, $activity['totalActions']);
        self::assertSame(['ban_user' => 2, 'delete_voyage' => 1], $activity['actionBreakdown']);
        self::assertSame(['user' => 2, 'voyage' => 1], $activity['targetBreakdown']);
        self::assertNotNull($activity['mostActiveDay']);
        self::assertSame(2, $activity['mostActiveDay']['count']);
        self::assertSame((new \DateTime())->format('Y-m-d'), $activity['mostActiveDay']['date']);
    }

    private function setLogCreatedAt(AdminLog $log, \DateTime $date): void
    {
        $property = new \ReflectionProperty($log, 'createdAt');
        $property->setValue($log, $date);
    }

    public function testGetAdminActivityHasNoMostActiveDayWhenThereAreNoLogs(): void
    {
        $admin = $this->admin(1);
        $this->adminLogRepository->method('search')->willReturn(['data' => [], 'total' => 0]);

        $activity = $this->service->getAdminActivity($admin, new \DateTime('-1 day'), new \DateTime());

        self::assertNull($activity['mostActiveDay']);
        self::assertSame(0, $activity['totalActions']);
    }
}
