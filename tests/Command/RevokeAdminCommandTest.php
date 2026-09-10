<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\AdminLogRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Symetrique de PromoteAdminCommand, pour un usage de derniers recours (incident de
 * securite) quand l'UI de gestion des roles n'est pas une option. A la difference de
 * ModerationService::updateUserRoles (proteges indirectement du dernier admin uniquement
 * parce qu'un admin ne peut pas modifier ses propres roles), cette commande contourne ce
 * garde-fou implicite - elle a donc sa propre protection explicite, testee ici.
 */
class RevokeAdminCommandTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $kernel = self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $application = new Application($kernel);
        $this->commandTester = new CommandTester($application->find('app:user:revoke-admin'));
    }

    private function admin(string $prefix): \App\Entity\User
    {
        $admin = $this->createUser($prefix);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    public function testFailsWhenUserDoesNotExist(): void
    {
        $this->commandTester->execute(['email' => 'inconnu-revoke@example.test']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
    }

    public function testIsANoopWhenUserIsNotAdmin(): void
    {
        $user = $this->createUser('revoke-noop');

        $this->commandTester->execute(['email' => $user->getEmail()]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testRefusesToRevokeTheLastAdminWithoutForce(): void
    {
        $onlyAdmin = $this->admin('revoke-last');

        $this->commandTester->execute(['email' => $onlyAdmin->getEmail()]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->em->refresh($onlyAdmin);
        self::assertContains('ROLE_ADMIN', $onlyAdmin->getRoles());
    }

    public function testRevokesTheLastAdminWithForce(): void
    {
        $onlyAdmin = $this->admin('revoke-last-force');
        $userId = $onlyAdmin->getId();

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['email' => $onlyAdmin->getEmail(), '--force' => true]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->em->refresh($onlyAdmin);
        self::assertNotContains('ROLE_ADMIN', $onlyAdmin->getRoles());

        $logs = static::getContainer()->get(AdminLogRepository::class)->findByTarget('user', $userId);
        self::assertNotEmpty($logs);
        self::assertSame('revoke_admin_cli', $logs[0]->getAction());
    }

    public function testRevokesWhenAnotherAdminRemains(): void
    {
        $this->admin('revoke-other');
        $target = $this->admin('revoke-target');

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['email' => $target->getEmail()]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->em->refresh($target);
        self::assertNotContains('ROLE_ADMIN', $target->getRoles());
    }
}
