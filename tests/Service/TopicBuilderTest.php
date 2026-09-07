<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\TopicBuilder;
use App\Tests\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 4 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : formatage de
 * chaines pures, mais forte dependance transversale (utilise par RealtimeNotifier et
 * MercureTokenService, tous deux deja testes en s'appuyant sur une instance reelle de
 * cette classe) - un bug ici casserait silencieusement tout le routing Mercure.
 */
class TopicBuilderTest extends TestCase
{
    use EntityIdTrait;

    public function testForUserBuildsAUserSpecificTopic(): void
    {
        $builder = new TopicBuilder('https://cobage.test');
        $user = new User();
        $user->setEmail('u@example.test');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, 42);

        self::assertSame('https://cobage.test/users/42', $builder->forUser($user));
    }

    public function testForGroupBuildsAGroupTopic(): void
    {
        $builder = new TopicBuilder('https://cobage.test');

        self::assertSame('https://cobage.test/groups/admin', $builder->forGroup('admin'));
    }

    public function testForGroupLowercasesTheGroupName(): void
    {
        $builder = new TopicBuilder('https://cobage.test');

        self::assertSame('https://cobage.test/groups/admin', $builder->forGroup('ADMIN'));
    }

    public function testForPublicBuildsThePublicTopic(): void
    {
        $builder = new TopicBuilder('https://cobage.test');

        self::assertSame('https://cobage.test/public', $builder->forPublic());
    }

    public function testForDemandesBuildsTheDemandesTopic(): void
    {
        $builder = new TopicBuilder('https://cobage.test');

        self::assertSame('https://cobage.test/topics/demandes', $builder->forDemandes());
    }

    public function testForVoyagesBuildsTheVoyagesTopic(): void
    {
        $builder = new TopicBuilder('https://cobage.test');

        self::assertSame('https://cobage.test/topics/voyages', $builder->forVoyages());
    }

    public function testForSystemBuildsTheSystemTopic(): void
    {
        $builder = new TopicBuilder('https://cobage.test');

        self::assertSame('https://cobage.test/system', $builder->forSystem());
    }

    public function testStripsATrailingSlashFromTheBaseDomain(): void
    {
        $builder = new TopicBuilder('https://cobage.test/');

        self::assertSame('https://cobage.test/public', $builder->forPublic(), 'jamais de double slash quel que soit le format du parametre de configuration');
    }
}
