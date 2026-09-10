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
 * Seule porte d'entree pour creer le premier admin d'une instance Cobage (bec-docs, Phase
 * 7b-B) - aucun endpoint API ne peut le faire (PATCH /api/admin/users/{id}/roles exige deja
 * ROLE_ADMIN). Verifie ici le comportement de la commande elle-meme ; les garde-fous
 * metier partages avec le flux UI (roles invalides, etc.) restent testes dans
 * ModerationServiceTest.
 */
class PromoteAdminCommandTest extends KernelTestCase
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
        $this->commandTester = new CommandTester($application->find('app:user:promote-admin'));
    }

    public function testFailsWhenUserDoesNotExist(): void
    {
        $this->commandTester->execute(['email' => 'inconnu-promote@example.test']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
    }

    public function testIsANoopWhenAlreadyAdmin(): void
    {
        $user = $this->createUser('promote-noop');
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        $this->commandTester->execute(['email' => $user->getEmail()]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testPromotesAnExistingUserAndLogsTheAction(): void
    {
        $user = $this->createUser('promote-success');
        $userId = $user->getId();

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['email' => $user->getEmail()]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

        $this->em->refresh($user);
        self::assertContains('ROLE_ADMIN', $user->getRoles());

        $logs = static::getContainer()->get(AdminLogRepository::class)->findByTarget('user', $userId);
        self::assertNotEmpty($logs);
        self::assertSame('promote_admin_cli', $logs[0]->getAction());
        self::assertSame($userId, $logs[0]->getAdmin()->getId());
    }

    public function testDoesNothingWhenConfirmationIsDeclined(): void
    {
        $user = $this->createUser('promote-declined');

        $this->commandTester->setInputs(['no']);
        $this->commandTester->execute(['email' => $user->getEmail()]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

        $this->em->refresh($user);
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
    }
}
