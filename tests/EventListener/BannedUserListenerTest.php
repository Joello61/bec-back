<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\User;
use App\EventListener\BannedUserListener;
use App\Tests\Support\EntityIdTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Phase 4b, Lot 13 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : unitaire pur,
 * mock TokenStorageInterface - verifie que le listener bloque bien un utilisateur banni,
 * sauf sur /logout et les routes publiques d'authentification, et laisse passer un
 * utilisateur non banni ou un appelant anonyme.
 */
class BannedUserListenerTest extends TestCase
{
    use MockTokenTrait;
    use EntityIdTrait;

    private TokenStorageInterface&\PHPUnit\Framework\MockObject\MockObject $tokenStorage;
    private HttpKernelInterface&\PHPUnit\Framework\MockObject\MockObject $kernel;
    private BannedUserListener $listener;

    protected function setUp(): void
    {
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
        $this->listener = new BannedUserListener($this->tokenStorage);
    }

    private function bannedUser(): User
    {
        $user = new User();
        $user->setEmail('banned-' . uniqid() . '@example.test');
        $user->setNom('Nom');
        $user->setPrenom('Prenom');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, 1);
        $admin = new User();
        $admin->setEmail('admin-' . uniqid() . '@example.test');
        $admin->setPassword('irrelevant');
        $this->setEntityId($admin, 2);
        $user->ban($admin, 'Comportement inapproprie');

        return $user;
    }

    private function event(string $path, ?User $user, bool $subRequest = false): RequestEvent
    {
        $this->tokenStorage->method('getToken')->willReturn($user !== null ? $this->tokenFor($user) : null);

        $request = Request::create($path);

        return new RequestEvent(
            $this->kernel,
            $request,
            $subRequest ? HttpKernelInterface::SUB_REQUEST : HttpKernelInterface::MAIN_REQUEST
        );
    }

    // ==================== blocage ====================

    public function testBlocksABannedUserOnAnOrdinaryRoute(): void
    {
        $event = $this->event('/api/voyages', $this->bannedUser());

        $this->listener->__invoke($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
        $payload = json_decode($event->getResponse()->getContent(), true);
        self::assertSame('account_banned', $payload['error']);
        self::assertStringContainsString('Comportement inapproprie', $payload['message']);
    }

    public function testBlocksAUserWithAnActiveTemporaryBan(): void
    {
        $user = $this->bannedUser();
        $user->setBannedUntil(new \DateTime('+1 hour'));
        $event = $this->event('/api/voyages', $user);

        $this->listener->__invoke($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
    }

    // ==================== bannissement temporaire arrive a echeance ====================

    public function testAllowsAUserWhoseTemporaryBanHasExpired(): void
    {
        $user = $this->bannedUser();
        $user->setBannedUntil(new \DateTime('-1 minute'));
        $event = $this->event('/api/voyages', $user);

        $this->listener->__invoke($event);

        self::assertFalse($event->hasResponse());
    }

    // ==================== routes toujours autorisees ====================

    public function testAllowsABannedUserToLogout(): void
    {
        $event = $this->event('/api/logout', $this->bannedUser());

        $this->listener->__invoke($event);

        self::assertFalse($event->hasResponse());
    }

    public function testAllowsABannedUserToLogin(): void
    {
        $event = $this->event('/api/login', $this->bannedUser());

        $this->listener->__invoke($event);

        self::assertFalse($event->hasResponse());
    }

    public function testAllowsABannedUserToResetPassword(): void
    {
        $event = $this->event('/api/reset-password', $this->bannedUser());

        $this->listener->__invoke($event);

        self::assertFalse($event->hasResponse());
    }

    // ==================== cas passants ====================

    public function testAllowsANonBannedUser(): void
    {
        $user = new User();
        $user->setEmail('active-' . uniqid() . '@example.test');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, 3);
        $event = $this->event('/api/voyages', $user);

        $this->listener->__invoke($event);

        self::assertFalse($event->hasResponse());
    }

    public function testAllowsAnAnonymousCaller(): void
    {
        $event = $this->event('/api/voyages', null);

        $this->listener->__invoke($event);

        self::assertFalse($event->hasResponse());
    }

    // ==================== sub-request ====================

    public function testIgnoresSubRequests(): void
    {
        $event = $this->event('/api/voyages', $this->bannedUser(), subRequest: true);

        $this->listener->__invoke($event);

        self::assertFalse($event->hasResponse());
    }
}
