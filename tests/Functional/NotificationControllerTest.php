<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 8 (bec-docs/docs/plan-correction/plan-correction-cobage.md), constat #3 du
 * plan : IDOR corrige au Lot 4 sur NotificationService::markAsRead/deleteNotification
 * (aucun controle d'appartenance avant correction). Ce test verifie explicitement, au niveau
 * HTTP, qu'un utilisateur ne peut ni marquer comme lue ni supprimer la notification d'un
 * autre utilisateur - la regression que la correction du Lot 4 empeche.
 */
class NotificationControllerTest extends WebTestCase
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

    private function notification(User $owner, bool $lue = false): Notification
    {
        $notification = new Notification();
        $notification->setUser($owner);
        $notification->setType('info');
        $notification->setTitre('Titre');
        $notification->setMessage('Message');
        $notification->setLue($lue);
        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    // ==================== auth requise ====================

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/notifications');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== list / unread / unread-count ====================

    public function testListReturnsOnlyTheCallersNotifications(): void
    {
        $owner = $this->createUser('notif-list-owner');
        $other = $this->createUser('notif-list-other');
        $this->notification($owner);
        $this->notification($other);
        $this->authenticateAs($owner);

        $this->client->request('GET', '/api/notifications');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload);
    }

    public function testUnreadReturnsOnlyUnreadNotifications(): void
    {
        $owner = $this->createUser('notif-unread-owner');
        $this->notification($owner, lue: true);
        $this->notification($owner, lue: false);
        $this->authenticateAs($owner);

        $this->client->request('GET', '/api/notifications/unread');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload);
    }

    public function testUnreadCountReturnsTheRightCount(): void
    {
        $owner = $this->createUser('notif-count-owner');
        $this->notification($owner, lue: true);
        $this->notification($owner, lue: false);
        $this->notification($owner, lue: false);
        $this->authenticateAs($owner);

        $this->client->request('GET', '/api/notifications/unread-count');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $payload['count']);
    }

    // ==================== markAsRead : IDOR ====================

    public function testMarkAsReadReturns404ForAnUnknownNotification(): void
    {
        $this->authenticateAs($this->createUser('notif-markread-404'));

        $this->client->request('POST', '/api/notifications/999999/mark-read');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMarkAsReadRejectsANonOwningUser(): void
    {
        $owner = $this->createUser('notif-markread-owner');
        $notification = $this->notification($owner);
        $stranger = $this->createUser('notif-markread-stranger');
        $this->authenticateAs($stranger);

        $this->client->request('POST', '/api/notifications/' . $notification->getId() . '/mark-read');

        self::assertResponseStatusCodeSame(403);
        $this->em->refresh($notification);
        self::assertFalse($notification->isLue(), 'la notification dun autre utilisateur ne doit pas etre modifiee');
    }

    public function testMarkAsReadSucceedsForTheOwner(): void
    {
        $owner = $this->createUser('notif-markread-ok');
        $notification = $this->notification($owner);
        $this->authenticateAs($owner);

        $this->client->request('POST', '/api/notifications/' . $notification->getId() . '/mark-read');

        self::assertResponseIsSuccessful();
        $this->em->refresh($notification);
        self::assertTrue($notification->isLue());
    }

    // ==================== markAllAsRead ====================

    public function testMarkAllAsReadOnlyAffectsTheCallersNotifications(): void
    {
        $owner = $this->createUser('notif-markall-owner');
        $other = $this->createUser('notif-markall-other');
        $ownNotif = $this->notification($owner);
        $otherNotif = $this->notification($other);
        $this->authenticateAs($owner);

        $this->client->request('POST', '/api/notifications/mark-all-read');

        self::assertResponseIsSuccessful();
        $this->em->refresh($ownNotif);
        $this->em->refresh($otherNotif);
        self::assertTrue($ownNotif->isLue());
        self::assertFalse($otherNotif->isLue());
    }

    // ==================== delete : IDOR ====================

    public function testDeleteReturns404ForAnUnknownNotification(): void
    {
        $this->authenticateAs($this->createUser('notif-delete-404'));

        $this->client->request('DELETE', '/api/notifications/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRejectsANonOwningUser(): void
    {
        $owner = $this->createUser('notif-delete-owner');
        $notification = $this->notification($owner);
        $notificationId = $notification->getId();
        $stranger = $this->createUser('notif-delete-stranger');
        $this->authenticateAs($stranger);

        $this->client->request('DELETE', '/api/notifications/' . $notificationId);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->getRepository(Notification::class)->find($notificationId), 'la notification dun autre utilisateur ne doit pas etre supprimee');
    }

    public function testDeleteSucceedsForTheOwner(): void
    {
        $owner = $this->createUser('notif-delete-ok');
        $notification = $this->notification($owner);
        $notificationId = $notification->getId();
        $this->authenticateAs($owner);

        $this->client->request('DELETE', '/api/notifications/' . $notificationId);

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->em->getRepository(Notification::class)->find($notificationId));
    }
}
