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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seule porte d'entree pour creer le tout premier administrateur d'une instance Cobage :
 * l'API (PATCH /api/admin/users/{id}/roles) exige deja d'etre ROLE_ADMIN pour modifier des
 * roles - probleme de l'oeuf et de la poule sur une base neuve. Patron verifie sur
 * factu_sentinel (CreatePlatformAdministratorCommand) : commande console uniquement, jamais
 * d'endpoint HTTP, meme pour un usage operationnel ponctuel.
 *
 * L'utilisateur doit deja exister (inscription normale via l'UI/API) - cette commande ne fait
 * que promouvoir un compte ROLE_USER existant, elle ne cree pas de compte.
 */
#[AsCommand(
    name: 'app:user:promote-admin',
    description: 'Promeut un utilisateur existant en administrateur (ROLE_ADMIN) - acces operationnel uniquement',
)]
class PromoteAdminCommand extends Command
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
        $this->addArgument('email', InputArgument::REQUIRED, "Email de l'utilisateur à promouvoir");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->userRepository->findByEmail($email);
        if (!$user) {
            $io->error(sprintf('Aucun utilisateur trouvé pour "%s". Le compte doit déjà exister (inscription normale via l\'UI/API).', $email));

            return Command::FAILURE;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $io->info(sprintf('"%s" est déjà administrateur, rien à faire.', $email));

            return Command::SUCCESS;
        }

        if (!$io->confirm(sprintf('Promouvoir "%s" en administrateur (ROLE_ADMIN) ?', $email), true)) {
            $io->warning('Opération annulée.');

            return Command::SUCCESS;
        }

        // Conserve les roles existants (ex. ROLE_MODERATOR) et deduplique - ROLE_USER est
        // deja reimplicite par User::getRoles(), inutile de le stocker explicitement.
        $newRoles = array_values(array_unique([
            ...array_diff($user->getRoles(), ['ROLE_USER']),
            'ROLE_ADMIN',
        ]));
        $user->setRoles($newRoles);
        $this->entityManager->flush();

        // Pas d'admin acteur distinct pour un bootstrap (par definition, aucun n'existe
        // encore) : l'utilisateur promu est journalise comme acteur de sa propre promotion,
        // avec le detail 'via' => 'cli' pour distinguer cette action d'une promotion via
        // l'UI admin (ModerationService::updateUserRoles).
        $this->auditLogService->logAdminAction(
            $user,
            'promote_admin_cli',
            'user',
            $user->getId(),
            ['email' => $email, 'newRoles' => $newRoles]
        );

        $io->success(sprintf('"%s" est maintenant administrateur.', $email));

        return Command::SUCCESS;
    }
}
