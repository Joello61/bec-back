<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BoostOfferSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:boost-offers',
    description: 'Peuple la base de données avec le catalogue des offres de boost (7/15/30 jours)',
)]
class SeedBoostOffersCommand extends Command
{
    public function __construct(
        private readonly BoostOfferSeeder $boostOfferSeeder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'clear',
            null,
            InputOption::VALUE_NONE,
            'Supprime toutes les offres avant de les recréer'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Seeding des offres de boost');

        try {
            if ($input->getOption('clear')) {
                $io->warning('Mode CLEAR activé - Toutes les offres vont être supprimées !');

                if (!$io->confirm('Êtes-vous sûr de vouloir continuer ?', false)) {
                    $io->info('Opération annulée');
                    return Command::SUCCESS;
                }

                $this->boostOfferSeeder->clear();
                $io->success('Toutes les offres ont été supprimées');
            }

            $io->section('Insertion des offres...');
            $this->boostOfferSeeder->seed();

            $io->success('Offres de boost insérées avec succès !');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Erreur lors du seeding : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
