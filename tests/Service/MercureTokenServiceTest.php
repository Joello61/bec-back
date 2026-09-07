<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\MercureTokenService;
use App\Service\TopicBuilder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 1 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : securite des
 * souscriptions temps reel Mercure - filtrage rôles->groupes special (un simple ROLE_USER
 * ne doit jamais generer de topic de groupe), jamais teste jusqu'ici.
 */
class MercureTokenServiceTest extends TestCase
{
    private const SECRET = 'test-mercure-subscriber-secret-key-for-phpunit';

    private MercureTokenService $service;

    protected function setUp(): void
    {
        $this->service = new MercureTokenService(self::SECRET, new TopicBuilder('https://cobage.test'));
    }

    /** @return string[] */
    private function subscribedTopics(string $jwt): array
    {
        $decoded = JWT::decode($jwt, new Key(self::SECRET, 'HS256'));

        return $decoded->mercure->subscribe;
    }

    private function makeUser(array $roles): User
    {
        $user = new User();
        $user->setEmail('mercure-' . uniqid() . '@example.test');
        $user->setPassword('irrelevant');
        $user->setRoles($roles);

        return $user;
    }

    public function testAnonymousUserOnlySubscribesToPublicTopic(): void
    {
        $topics = $this->subscribedTopics($this->service->generate(null));

        self::assertSame(['https://cobage.test/public'], $topics);
    }

    public function testRegularUserSubscribesToPublicAndOwnTopicButNoGroup(): void
    {
        $user = $this->makeUser([]);

        $topics = $this->subscribedTopics($this->service->generate($user));

        self::assertContains('https://cobage.test/public', $topics);
        self::assertContains(sprintf('https://cobage.test/users/%d', $user->getId()), $topics);
        self::assertCount(2, $topics, 'un ROLE_USER seul ne doit jamais generer de topic de groupe (admin/moderator)');
    }

    public function testAdminSubscribesToTheAdminGroupTopic(): void
    {
        $admin = $this->makeUser(['ROLE_ADMIN']);

        $topics = $this->subscribedTopics($this->service->generate($admin));

        self::assertContains('https://cobage.test/groups/admin', $topics);
    }

    public function testModeratorSubscribesToTheModeratorGroupTopic(): void
    {
        $moderator = $this->makeUser(['ROLE_MODERATOR']);

        $topics = $this->subscribedTopics($this->service->generate($moderator));

        self::assertContains('https://cobage.test/groups/moderator', $topics);
    }

    public function testUserWithBothSpecialRolesSubscribesToBothGroupTopics(): void
    {
        $user = $this->makeUser(['ROLE_ADMIN', 'ROLE_MODERATOR']);

        $topics = $this->subscribedTopics($this->service->generate($user));

        self::assertContains('https://cobage.test/groups/admin', $topics);
        self::assertContains('https://cobage.test/groups/moderator', $topics);
    }

    public function testExtraTopicsAreMergedAndDeduplicated(): void
    {
        $topics = $this->subscribedTopics(
            $this->service->generate(null, ['https://cobage.test/public', 'https://cobage.test/topics/voyages'])
        );

        self::assertSame(
            ['https://cobage.test/public', 'https://cobage.test/topics/voyages'],
            array_values($topics),
            'le topic public duplique par extraTopics ne doit apparaitre qu\'une seule fois'
        );
    }

    public function testTokenHasNoPublishClaimAndExpiresInOneHour(): void
    {
        $before = time();
        $decoded = JWT::decode($this->service->generate(null), new Key(self::SECRET, 'HS256'));

        self::assertSame([], $decoded->mercure->publish, 'un jeton de souscription Mercure ne doit jamais porter de droit de publication');
        self::assertGreaterThanOrEqual($before + 3600, $decoded->exp);
        self::assertLessThanOrEqual($before + 3600 + 5, $decoded->exp, 'marge de 5s pour l\'execution du test');
    }
}
