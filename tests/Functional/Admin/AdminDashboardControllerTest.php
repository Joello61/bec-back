<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminLog;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 9 (bec-docs/docs/plan-correction/plan-correction-cobage.md), constat #5 du
 * plan : AdminDashboardController::logsByAdmin ignorait completement $adminId et utilisait
 * l'admin connecte a la place (TODO explicite dans le code avant correction). Corrige dans
 * ce lot : charge l'admin cible via UserRepository, 404 si absent.
 */
class AdminDashboardControllerTest extends WebTestCase
{
    use UserFactoryTrait;
    use JwtAuthenticationTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private JWTTokenManagerInterface $jwtManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
    }

    private function admin(string $prefix = 'admin-dash-admin'): User
    {
        $admin = $this->createUser($prefix);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    private function log(User $admin, string $action = 'ban_user'): AdminLog
    {
        $log = new AdminLog();
        $log->setAdmin($admin)
            ->setAction($action)
            ->setTargetType('user')
            ->setTargetId(1);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    // ==================== auth requise ====================

    public function testDashboardRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('admin-dash-nonadmin'));

        $this->client->request('GET', '/api/admin/dashboard');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== dashboard & stats ====================

    public function testDashboardReturnsGlobalStats(): void
    {
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/admin/dashboard');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('users', $payload);
    }

    public function testUsersStatsSucceeds(): void
    {
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/admin/stats/users?days=7');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('global', $payload);
        self::assertArrayHasKey('detailed', $payload);
        self::assertArrayHasKey('authProviders', $payload);
    }

    public function testVoyagesStatsSucceeds(): void
    {
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/admin/stats/voyages');

        self::assertResponseIsSuccessful();
    }

    public function testSignalementsStatsSucceeds(): void
    {
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/admin/stats/signalements');

        self::assertResponseIsSuccessful();
    }

    public function testEngagementStatsSucceeds(): void
    {
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/admin/stats/engagement');

        self::assertResponseIsSuccessful();
    }

    // ==================== logs ====================

    public function testLogsFiltersByAction(): void
    {
        $admin = $this->admin('admin-dash-logs-admin');
        $this->log($admin, 'ban_user');
        $this->log($admin, 'delete_voyage');
        $this->authenticateAs($admin);

        $this->client->request('GET', '/api/admin/logs?action=ban_user');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        foreach ($payload['data'] as $log) {
            self::assertSame('ban_user', $log['action']);
        }
    }

    // ==================== logsByAdmin : regression bug #5 ====================

    public function testLogsByAdminReturns404ForAnUnknownAdmin(): void
    {
        $this->authenticateAs($this->admin('admin-dash-logsbyadmin-404'));

        $this->client->request('GET', '/api/admin/logs/admin/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLogsByAdminReturnsTheTargetedAdminsLogsNotTheCallers(): void
    {
        // Regression exacte du constat #5 : avant correction, cette route ignorait
        // $adminId et retournait toujours les logs de l'admin CONNECTE (le TODO
        // le confirmait). Ici, le caller (b) demande les logs de admin (a) : la
        // reponse doit contenir le log de (a), pas celui de (b).
        $targetAdmin = $this->admin('admin-dash-logsbyadmin-target');
        $callerAdmin = $this->admin('admin-dash-logsbyadmin-caller');
        $this->log($targetAdmin, 'ban_user');
        $this->log($callerAdmin, 'delete_voyage');
        $this->authenticateAs($callerAdmin);

        $this->client->request('GET', '/api/admin/logs/admin/' . $targetAdmin->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload);
        foreach ($payload as $log) {
            self::assertSame($targetAdmin->getId(), $log['admin']['id']);
        }
    }

    // ==================== logsStats ====================

    public function testLogsStatsSucceedsForEachPeriod(): void
    {
        $this->authenticateAs($this->admin('admin-dash-logsstats'));

        foreach (['today', 'week', 'month'] as $period) {
            $this->client->request('GET', '/api/admin/logs/stats?period=' . $period);
            self::assertResponseIsSuccessful();
            $payload = json_decode($this->client->getResponse()->getContent(), true);
            self::assertSame($period, $payload['period']);
        }
    }

    // ==================== exportLogs ====================

    public function testExportLogsReturnsACsvFile(): void
    {
        $admin = $this->admin('admin-dash-export');
        $this->log($admin);
        $this->authenticateAs($admin);

        $this->client->request('GET', '/api/admin/logs/export');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('ID;Admin;Action', $this->client->getResponse()->getContent());
    }
}
