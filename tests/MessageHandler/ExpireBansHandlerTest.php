<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\User;
use App\Message\ExpireBansMessage;
use App\MessageHandler\ExpireBansHandler;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Meme patron d'integration que ExpireVoyagesHandlerTest/ExpireDemandesHandlerTest : le
 * handler depend d'une vraie requete Doctrine (UserRepository::findExpiredBans), appel
 * direct du handler (pas de transport Messenger reel necessaire).
 */
class ExpireBansHandlerTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private ExpireBansHandler $handler;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(ExpireBansHandler::class);
    }

    private function bannedUser(string $prefix, ?string $bannedUntil): User
    {
        $admin = $this->createUser($prefix . '-admin');
        $user = $this->createUser($prefix . '-target');
        $user->ban($admin, 'raison', $bannedUntil !== null ? new \DateTime($bannedUntil) : null);
        $this->em->flush();

        return $user;
    }

    public function testHandlerIsANoopWhenNothingIsExpired(): void
    {
        $this->bannedUser('expireban-noop', '+1 hour');

        ($this->handler)(new ExpireBansMessage());

        $users = $this->em->getRepository(User::class)->findBy(['isBanned' => true]);
        self::assertNotEmpty($users);
    }

    public function testHandlerLiftsAnExpiredTemporaryBan(): void
    {
        $user = $this->bannedUser('expireban-simple', '-1 hour');
        $userId = $user->getId();

        ($this->handler)(new ExpireBansMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(User::class)->find($userId);
        self::assertFalse($refreshed->isBanned());
    }

    public function testHandlerDoesNotTouchAPermanentBan(): void
    {
        $user = $this->bannedUser('expireban-permanent', null);
        $userId = $user->getId();

        ($this->handler)(new ExpireBansMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(User::class)->find($userId);
        self::assertTrue($refreshed->isBanned());
    }
}
