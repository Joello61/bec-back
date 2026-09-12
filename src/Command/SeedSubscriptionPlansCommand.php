<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SubscriptionPlanSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:subscription-plans',
    description: 'Peuple la base de données avec le catalogue d\'abonnements (Free/Plus/Pro)',
)]
class SeedSubscriptionPlansCommand extends Command
{
    public function __construct(
        private readonly SubscriptionPlanSeeder $subscriptionPlanSeeder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'clear',
            null,
            InputOption::VALUE_NONE,
            'Supprime tous les plans avant de les recréer'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Seeding des plans d\'abonnement');

        try {
            if ($input->getOption('clear')) {
                $io->warning('Mode CLEAR activé - Tous les plans vont être supprimés !');

                if (!$io->confirm('Êtes-vous sûr de vouloir continuer ?', false)) {
                    $io->info('Opération annulée');
                    return Command::SUCCESS;
                }

                $this->subscriptionPlanSeeder->clear();
                $io->success('Tous les plans ont été supprimés');
            }

            $io->section('Insertion des plans...');
            $this->subscriptionPlanSeeder->seed();

            $io->success('Plans d\'abonnement insérés avec succès !');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Erreur lors du seeding : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
