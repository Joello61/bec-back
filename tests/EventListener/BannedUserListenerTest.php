<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\User;
use App\EventListener\BannedUserListener;
use App\Tests\Support\EntityIdTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Phase 4b, Lot 13 (bec-docs/docs/plan-correction/plan-correction-cobage.md), revu en Phase
 * 7b-B (bug de timing corrige, voir BannedUserListener.php) : unitaire pur, mock
 * TokenStorageInterface - verifie la LOGIQUE du listener (routes autorisees, expiration de
 * bannissement temporaire) en isolation. Ne peut pas, par construction, detecter un probleme
 * d'ordonnancement reel entre listeners/evenements - c'est le role de
 * tests/Functional/BannedUserAccessTest.php (dispatch reel du kernel, sans mock du firewall).
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

    /**
     * Le controleur "original" retourne un marqueur distinct de toute reponse produite par le
     * listener (403) - permet de distinguer "controleur remplace" (bloque) de "controleur
     * inchange" (laisse passer) sans dependre d'une methode hasResponse()/getResponse(), qui
     * n'existe pas sur ControllerArgumentsEvent (contrairement a RequestEvent).
     */
    private function event(string $path, ?User $user, bool $subRequest = false): ControllerArgumentsEvent
    {
        $this->tokenStorage->method('getToken')->willReturn($user !== null ? $this->tokenFor($user) : null);

        $request = Request::create($path);
        $originalController = static fn () => new Response('original', 200);

        return new ControllerArgumentsEvent(
            $this->kernel,
            $originalController,
            [],
            $request,
            $subRequest ? HttpKernelInterface::SUB_REQUEST : HttpKernelInterface::MAIN_REQUEST
        );
    }

    private function resultingResponse(ControllerArgumentsEvent $event): Response
    {
        $controller = $event->getController();

        return $controller(...$event->getArguments());
    }

    // ==================== blocage ====================

    public function testBlocksABannedUserOnAnOrdinaryRoute(): void
    {
        $event = $this->event('/api/voyages', $this->bannedUser());

        $this->listener->__invoke($event);
        $response = $this->resultingResponse($event);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        self::assertSame('account_banned', $payload['error']);
        self::assertStringContainsString('Comportement inapproprie', $payload['message']);
    }

    public function testBlocksAUserWithAnActiveTemporaryBan(): void
    {
        $user = $this->bannedUser();
        $user->setBannedUntil(new \DateTime('+1 hour'));
        $event = $this->event('/api/voyages', $user);

        $this->listener->__invoke($event);

        self::assertSame(403, $this->resultingResponse($event)->getStatusCode());
    }

    // ==================== bannissement temporaire arrive a echeance ====================

    public function testAllowsAUserWhoseTemporaryBanHasExpired(): void
    {
        $user = $this->bannedUser();
        $user->setBannedUntil(new \DateTime('-1 minute'));
        $event = $this->event('/api/voyages', $user);

        $this->listener->__invoke($event);

        self::assertSame('original', $this->resultingResponse($event)->getContent());
    }

    // ==================== routes toujours autorisees ====================

    public function testAllowsABannedUserToLogout(): void
    {
        $event = $this->event('/api/logout', $this->bannedUser());

        $this->listener->__invoke($event);

        self::assertSame('original', $this->resultingResponse($event)->getContent());
    }

    public function testAllowsABannedUserToLogin(): void
    {
        $event = $this->event('/api/login', $this->bannedUser());

        $this->listener->__invoke($event);

        self::assertSame('original', $this->resultingResponse($event)->getContent());
    }

    public function testAllowsABannedUserToResetPassword(): void
    {
        $event = $this->event('/api/reset-password', $this->bannedUser());

        $this->listener->__invoke($event);

        self::assertSame('original', $this->resultingResponse($event)->getContent());
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

        self::assertSame('original', $this->resultingResponse($event)->getContent());
    }

    public function testAllowsAnAnonymousCaller(): void
    {
        $event = $this->event('/api/voyages', null);

        $this->listener->__invoke($event);

        self::assertSame('original', $this->resultingResponse($event)->getContent());
    }

    // ==================== sub-request ====================

    public function testIgnoresSubRequests(): void
    {
        $event = $this->event('/api/voyages', $this->bannedUser(), subRequest: true);

        $this->listener->__invoke($event);

        self::assertSame('original', $this->resultingResponse($event)->getContent());
    }
}
