<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\Admin\ModerationService;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression dediee (Phase 7b-B, decouverte en ecrivant e2e/admin.spec.ts, bec-frontend) :
 * BannedUserListener (priority: 10) s'executait AVANT le firewall Symfony
 * (Firewall::onKernelRequest, priority: 8) - le token n'etait donc jamais authentifie au
 * moment ou le listener le lisait, le rendant silencieusement inoffensif sur toute requete
 * reelle. BannedUserListenerTest (unitaire, TokenStorageInterface mocke directement) ne
 * pouvait pas detecter ce probleme d'ORDRE de deux listeners reels - seul un vrai dispatch
 * du kernel (WebTestCase, pas de mock du firewall) le peut. Corrige en abaissant la priorite
 * a 7 (sous 8).
 */
class BannedUserAccessTest extends WebTestCase
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

    private function admin(string $prefix): User
    {
        $admin = $this->createUser($prefix);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    public function testABannedUserIsBlockedOnTheVeryNextAuthenticatedRequest(): void
    {
        $user = $this->createUser('banaccess-blocked');
        $admin = $this->admin('banaccess-blocked-admin');
        $this->authenticateAs($user);

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();

        static::getContainer()->get(ModerationService::class)->banUser($user, $admin, 'test regression listener');
        self::assertTrue($user->isBanned(), 'sanity: banUser() devrait deja marquer isBanned=true en memoire');
        $reloaded = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded->isBanned(), 'sanity: isBanned=true devrait etre persiste en base');

        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('account_banned', $payload['error']);
    }

    public function testABannedUserCanStillLogout(): void
    {
        $user = $this->createUser('banaccess-logout');
        $admin = $this->admin('banaccess-logout-admin');
        static::getContainer()->get(ModerationService::class)->banUser($user, $admin, 'test regression listener');
        $this->authenticateAs($user);

        $this->client->request('POST', '/api/logout');

        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testANonBannedUserIsNeverBlocked(): void
    {
        $user = $this->createUser('banaccess-nonbanned');
        $this->authenticateAs($user);

        $this->client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
    }
}
