<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 9 (bec-docs/docs/plan-correction/plan-correction-cobage.md), constat #2 du
 * plan : AdminUserController::list ne plafonnait jamais `limit` (meme faille que celle
 * corrigee en Phase 2 sur UserController) - corrige dans ce lot avec min(limit, 50), meme
 * plafond que UserController::list. Le detail des garde-fous de ModerationService
 * (auto-bannissement, admin protege) est deja verifie unitairement (Lot 1).
 */
class AdminUserControllerTest extends WebTestCase
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

    private function admin(string $prefix = 'admin-user-admin'): User
    {
        $admin = $this->createUser($prefix);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    // ==================== auth requise ====================

    public function testListRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('admin-user-list-nonadmin'));

        $this->client->request('GET', '/api/admin/users');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== list : regression bug #2 ====================

    public function testListCapsTheLimitAtFifty(): void
    {
        // Regression du constat #2 : avant correction, `limit=1000` etait transmis
        // tel quel au repository sans plafond.
        $this->authenticateAs($this->admin('admin-user-list-cap'));

        $this->client->request('GET', '/api/admin/users?limit=1000');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(50, $payload['pagination']['limit']);
    }

    public function testListRespectsALimitBelowTheCap(): void
    {
        $this->authenticateAs($this->admin('admin-user-list-nocap'));

        $this->client->request('GET', '/api/admin/users?limit=5');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(5, $payload['pagination']['limit']);
    }

    public function testListFiltersByRole(): void
    {
        $moderator = $this->createUser('admin-user-list-moderator');
        $moderator->setRoles(['ROLE_MODERATOR']);
        $this->em->flush();
        $this->authenticateAs($this->admin('admin-user-list-filter'));

        $this->client->request('GET', '/api/admin/users?role=ROLE_MODERATOR');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload['data']);
        foreach ($payload['data'] as $user) {
            self::assertContains('ROLE_MODERATOR', $user['roles']);
        }
    }

    // ==================== show ====================

    public function testShowReturns404ForAnUnknownUser(): void
    {
        $this->authenticateAs($this->admin('admin-user-show-404'));

        $this->client->request('GET', '/api/admin/users/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowSucceeds(): void
    {
        $target = $this->createUser('admin-user-show-ok');
        $this->authenticateAs($this->admin('admin-user-show-ok-admin'));

        $this->client->request('GET', '/api/admin/users/' . $target->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($target->getId(), $payload['id']);
    }

    // ==================== ban ====================

    public function testBanUserReturns404ForAnUnknownUser(): void
    {
        $this->authenticateAs($this->admin('admin-user-ban-404'));

        $this->client->request(
            'POST',
            '/api/admin/users/999999/ban',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'Comportement inapproprie'])
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testBanUserRejectsAnInvalidPayload(): void
    {
        $target = $this->createUser('admin-user-ban-invalid-target');
        $this->authenticateAs($this->admin('admin-user-ban-invalid-admin'));

        $this->client->request(
            'POST',
            '/api/admin/users/' . $target->getId() . '/ban',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'trop'])
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testBanUserRejectsSelfBan(): void
    {
        $admin = $this->admin('admin-user-ban-self');
        $this->authenticateAs($admin);

        $this->client->request(
            'POST',
            '/api/admin/users/' . $admin->getId() . '/ban',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'Un admin ne peut pas se bannir lui-meme'])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testBanUserSucceeds(): void
    {
        $target = $this->createUser('admin-user-ban-ok');
        $this->authenticateAs($this->admin('admin-user-ban-ok-admin'));

        $this->client->request(
            'POST',
            '/api/admin/users/' . $target->getId() . '/ban',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'Comportement inapproprie et repete'])
        );

        self::assertResponseIsSuccessful();
        $this->em->refresh($target);
        self::assertTrue($target->isBanned());
    }

    // ==================== unban ====================

    public function testUnbanUserRejectsANonBannedUser(): void
    {
        $target = $this->createUser('admin-user-unban-notbanned');
        $this->authenticateAs($this->admin('admin-user-unban-notbanned-admin'));

        $this->client->request('POST', '/api/admin/users/' . $target->getId() . '/unban');

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnbanUserSucceeds(): void
    {
        $target = $this->createUser('admin-user-unban-ok');
        $banningAdmin = $this->admin('admin-user-unban-ok-banner');
        $target->ban($banningAdmin, 'Raison de test suffisamment longue');
        $this->em->flush();
        $this->authenticateAs($this->admin('admin-user-unban-ok-admin'));

        $this->client->request('POST', '/api/admin/users/' . $target->getId() . '/unban');

        self::assertResponseIsSuccessful();
        $this->em->refresh($target);
        self::assertFalse($target->isBanned());
    }

    // ==================== updateRoles ====================

    public function testUpdateRolesRejectsSelfModification(): void
    {
        $admin = $this->admin('admin-user-roles-self');
        $this->authenticateAs($admin);

        $this->client->request(
            'PATCH',
            '/api/admin/users/' . $admin->getId() . '/roles',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['roles' => ['ROLE_USER', 'ROLE_MODERATOR']])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testUpdateRolesRejectsAnInvalidRole(): void
    {
        $target = $this->createUser('admin-user-roles-invalid-target');
        $this->authenticateAs($this->admin('admin-user-roles-invalid-admin'));

        $this->client->request(
            'PATCH',
            '/api/admin/users/' . $target->getId() . '/roles',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['roles' => ['ROLE_SUPERUSER']])
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateRolesSucceedsAndAlwaysIncludesRoleUser(): void
    {
        $target = $this->createUser('admin-user-roles-ok');
        $this->authenticateAs($this->admin('admin-user-roles-ok-admin'));

        $this->client->request(
            'PATCH',
            '/api/admin/users/' . $target->getId() . '/roles',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['roles' => ['ROLE_MODERATOR']])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertContains('ROLE_USER', $payload['newRoles']);
        self::assertContains('ROLE_MODERATOR', $payload['newRoles']);
    }

    // ==================== delete ====================

    public function testDeleteUserRejectsSelfDeletion(): void
    {
        $admin = $this->admin('admin-user-delete-self');
        $this->authenticateAs($admin);

        $this->client->request(
            'DELETE',
            '/api/admin/users/' . $admin->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'test'])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteUserSucceeds(): void
    {
        $target = $this->createUser('admin-user-delete-ok');
        $targetId = $target->getId();
        $targetOriginalEmail = $target->getEmail();
        $tiers = $this->createUser('admin-user-delete-ok-tiers');

        // Un message envoye par la cible a un tiers doit rester lisible apres suppression
        // (audit Backend-Qualite #2 : l'historique de conversation d'un tiers ne doit pas
        // disparaitre avec le compte de son interlocuteur).
        $conversation = new Conversation();
        $conversation->setParticipant1($target);
        $conversation->setParticipant2($tiers);
        $this->em->persist($conversation);

        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($target);
        $message->setDestinataire($tiers);
        $message->setContenu('Bonjour, ceci est un message de test');
        $this->em->persist($message);
        $this->em->flush();
        $messageId = $message->getId();

        $this->authenticateAs($this->admin('admin-user-delete-ok-admin'));

        $this->client->request(
            'DELETE',
            '/api/admin/users/' . $targetId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'Suppression RGPD demandee'])
        );

        self::assertResponseIsSuccessful();

        $this->em->clear();

        $anonymized = $this->em->getRepository(User::class)->find($targetId);
        self::assertNotNull($anonymized, 'Le compte ne doit pas etre physiquement supprime');
        self::assertNotSame($targetOriginalEmail, $anonymized->getEmail());
        self::assertNotNull($anonymized->getDeletedAt());
        self::assertNull($anonymized->getPassword());

        $persistedMessage = $this->em->getRepository(Message::class)->find($messageId);
        self::assertNotNull($persistedMessage, 'Le message reste visible pour le tiers destinataire');
        self::assertSame('Bonjour, ceci est un message de test', $persistedMessage->getContenu());
    }

    // ==================== activity / admin-logs ====================

    public function testGetUserActivityReturns404ForAnUnknownUser(): void
    {
        $this->authenticateAs($this->admin('admin-user-activity-404'));

        $this->client->request('GET', '/api/admin/users/999999/activity');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetUserActivitySucceeds(): void
    {
        $target = $this->createUser('admin-user-activity-ok');
        $this->authenticateAs($this->admin('admin-user-activity-ok-admin'));

        $this->client->request('GET', '/api/admin/users/' . $target->getId() . '/activity');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(0, $payload['voyages']['total']);
    }

    // ==================== search ====================

    public function testSearchRejectsAQueryThatIsTooShort(): void
    {
        $this->authenticateAs($this->admin('admin-user-search-short'));

        $this->client->request('GET', '/api/admin/users/search?q=a');

        self::assertResponseStatusCodeSame(400);
    }

    public function testSearchFindsMatchingUsers(): void
    {
        $target = $this->createUser('admin-user-search-target-unique');
        $this->authenticateAs($this->admin('admin-user-search-ok-admin'));

        $this->client->request('GET', '/api/admin/users/search?q=admin-user-search-target-unique');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload);
    }
}
