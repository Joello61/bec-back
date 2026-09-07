<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\RealtimeNotifier;
use App\Service\TopicBuilder;
use App\Tests\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Phase 4b, Lot 4 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : fondation
 * Mercure utilisee par ~15 autres services, toujours mockee ailleurs (jamais exercee "en
 * vrai") - seul ce test valide son comportement reel.
 *
 * Constat trouve en ecrivant ce test, non corrige ici (hors perimetre approuve, risque
 * cross-repo sur le contrat Mercure deja consomme par bec-frontend) : publish() appelle
 * `new Update($topic, $json, $private, $eventType)` - le 4e parametre positionnel du
 * constructeur Update est `$id` (evenement SSE), pas `$type` comme le nom $eventType le
 * laisse penser. $eventType atterrit donc dans Update::getId(), jamais dans getType()
 * (toujours null). Teste tel quel, signale dans plan-correction-cobage.md pour decision
 * ulterieure plutot que corrige silencieusement.
 */
class RealtimeNotifierTest extends TestCase
{
    use EntityIdTrait;

    private HubInterface&\PHPUnit\Framework\MockObject\MockObject $hub;
    private RealtimeNotifier $notifier;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->notifier = new RealtimeNotifier($this->hub, new TopicBuilder('https://cobage.test'));
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '@example.test');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, $id);

        return $user;
    }

    public function testPublishToUserTargetsThePrivateUserTopic(): void
    {
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishToUser($this->user(42), ['foo' => 'bar'], 'SOME_EVENT');

        self::assertSame(['https://cobage.test/users/42'], $captured->getTopics());
        self::assertTrue($captured->isPrivate());
    }

    public function testPublishToGroupTargetsThePrivateGroupTopic(): void
    {
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishToGroup('admin', ['x' => 1], 'SOME_EVENT');

        self::assertSame(['https://cobage.test/groups/admin'], $captured->getTopics());
        self::assertTrue($captured->isPrivate());
    }

    public function testPublishPublicTargetsThePublicTopicAndIsNotPrivate(): void
    {
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishPublic(['x' => 1], 'SOME_EVENT');

        self::assertSame(['https://cobage.test/public'], $captured->getTopics());
        self::assertFalse($captured->isPrivate());
    }

    public function testPublishDemandesTargetsTheDemandesTopic(): void
    {
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishDemandes(['x' => 1], 'SOME_EVENT');

        self::assertSame(['https://cobage.test/topics/demandes'], $captured->getTopics());
    }

    public function testPublishVoyagesTargetsTheVoyagesTopic(): void
    {
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishVoyages(['x' => 1], 'SOME_EVENT');

        self::assertSame(['https://cobage.test/topics/voyages'], $captured->getTopics());
    }

    public function testPayloadStructureIncludesEventTypeTimestampAndData(): void
    {
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishToUser($this->user(1), ['foo' => 'bar'], 'PROPOSITION_CREATED');
        $payload = json_decode($captured->getData(), true);

        self::assertSame('PROPOSITION_CREATED', $payload['eventType']);
        self::assertSame(['foo' => 'bar'], $payload['data']);
        self::assertArrayHasKey('timestamp', $payload);
    }

    public function testEventTypeLandsInUpdateIdNotUpdateType(): void
    {
        // Documente le constat du bug de mapping d'argument decrit dans le docblock de
        // la classe - comportement reel actuel, pas l'intention du nom $eventType.
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishToUser($this->user(1), [], 'PROPOSITION_CREATED');

        self::assertSame('PROPOSITION_CREATED', $captured->getId());
        self::assertNull($captured->getType());
    }

    public function testPublicChannelsEnrichThePayloadWithServerTime(): void
    {
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishPublic(['x' => 1], 'SOME_EVENT');
        $payload = json_decode($captured->getData(), true);

        self::assertArrayHasKey('serverTime', $payload['data']);
    }

    public function testUserAndGroupChannelsDoNotEnrichThePayload(): void
    {
        $captured = null;
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured = $update;

            return 'id-1';
        });

        $this->notifier->publishToUser($this->user(1), ['x' => 1], 'SOME_EVENT');
        $payload = json_decode($captured->getData(), true);

        self::assertArrayNotHasKey('serverTime', $payload['data'], 'asymetrie documentee : seuls publishPublic/publishDemandes/publishVoyages enrichissent le payload');
    }

    public function testThrowsJsonExceptionWhenDataIsNotSerializable(): void
    {
        $this->expectException(\JsonException::class);

        // NAN n'est jamais serialisable en JSON (JSON_THROW_ON_ERROR le fait echouer).
        $this->notifier->publishToUser($this->user(1), ['bad' => \NAN], 'SOME_EVENT');
    }
}
