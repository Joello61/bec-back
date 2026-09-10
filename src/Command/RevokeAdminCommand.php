<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Admin\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symetrique de PromoteAdminCommand : revocation ROLE_ADMIN en dernier recours (ex. incident
 * de securite, acces admin compromis) quand l'UI de gestion des roles n'est pas une option
 * (compte verrouille, etc.). Contrairement a ModerationService::updateUserRoles (utilise par
 * l'UI, protege indirectement le dernier admin uniquement parce qu'un admin ne peut pas
 * modifier ses propres roles), cette commande contourne ce garde-fou implicite - elle a donc
 * sa propre protection explicite anti-dernier-admin.
 */
#[AsCommand(
    name: 'app:user:revoke-admin',
    description: 'Retire le role ROLE_ADMIN a un utilisateur - acces operationnel uniquement',
)]
class RevokeAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogService $auditLogService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, "Email de l'administrateur à rétrograder");
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Autorise à retirer le dernier administrateur restant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $force = (bool) $input->getOption('force');

        $user = $this->userRepository->findByEmail($email);
        if (!$user) {
            $io->error(sprintf('Aucun utilisateur trouvé pour "%s".', $email));

            return Command::FAILURE;
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $io->info(sprintf('"%s" n\'est pas administrateur, rien à faire.', $email));

            return Command::SUCCESS;
        }

        if (!$force && $this->userRepository->countByRole('ROLE_ADMIN') <= 1) {
            $io->error(sprintf(
                '"%s" est le dernier administrateur du système - relancez avec --force si c\'est réellement voulu.',
                $email
            ));

            return Command::FAILURE;
        }

        if (!$io->confirm(sprintf('Retirer le rôle administrateur à "%s" ?', $email), false)) {
            $io->warning('Opération annulée.');

            return Command::SUCCESS;
        }

        $newRoles = array_values(array_diff($user->getRoles(), ['ROLE_ADMIN', 'ROLE_USER']));
        $user->setRoles($newRoles);
        $this->entityManager->flush();

        $this->auditLogService->logAdminAction(
            $user,
            'revoke_admin_cli',
            'user',
            $user->getId(),
            ['email' => $email, 'newRoles' => $newRoles, 'forced' => $force]
        );

        $io->success(sprintf('"%s" n\'est plus administrateur.', $email));

        return Command::SUCCESS;
    }
}
