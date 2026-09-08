<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\AdminLog;
use App\Entity\User;
use App\Service\Admin\AuditLogService;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 6 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * getMostActiveAdmins() s'appuie sur une vraie requete GROUP BY suivie d'un chargement des
 * admins concernes - non mockable proprement (voir AuditLogServiceTest), et c'est justement
 * la ou un N+1 avait ete introduit (un find() par ligne du GROUP BY au lieu d'un chargement
 * batche). Integration avec une vraie base, comme MatchingServiceIntegrationTest.
 */
class AuditLogServiceIntegrationTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private AuditLogService $auditLogService;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->auditLogService = static::getContainer()->get(AuditLogService::class);
    }

    private function log(User $admin, string $action, string $targetType, int $targetId): void
    {
        $log = new AdminLog();
        $log->setAdmin($admin)
            ->setAction($action)
            ->setTargetType($targetType)
            ->setTargetId($targetId);
        $this->em->persist($log);
    }

    public function testGetMostActiveAdminsReturnsEachDistinctAdminWithItsActionCount(): void
    {
        $admin1 = $this->createUser('audit-active-admin-1');
        $admin2 = $this->createUser('audit-active-admin-2');

        $this->log($admin1, 'ban_user', 'user', 1);
        $this->log($admin1, 'ban_user', 'user', 2);
        $this->log($admin1, 'delete_voyage', 'voyage', 3);
        $this->log($admin2, 'ban_user', 'user', 4);
        $this->em->flush();

        $result = $this->auditLogService->getMostActiveAdmins(10);

        $byAdminId = [];
        foreach ($result as $row) {
            $byAdminId[$row['admin']['id']] = $row;
        }

        self::assertArrayHasKey($admin1->getId(), $byAdminId);
        self::assertArrayHasKey($admin2->getId(), $byAdminId);
        self::assertSame(3, $byAdminId[$admin1->getId()]['actionCount']);
        self::assertSame(1, $byAdminId[$admin2->getId()]['actionCount']);
        self::assertSame($admin1->getEmail(), $byAdminId[$admin1->getId()]['admin']['email']);
        self::assertSame($admin2->getEmail(), $byAdminId[$admin2->getId()]['admin']['email']);
    }

    public function testGetMostActiveAdminsRespectsTheLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $admin = $this->createUser('audit-active-limit-' . $i);
            $this->log($admin, 'ban_user', 'user', $i);
        }
        $this->em->flush();

        $result = $this->auditLogService->getMostActiveAdmins(2);

        self::assertCount(2, $result);
    }
}
