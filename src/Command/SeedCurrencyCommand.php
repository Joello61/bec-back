<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CurrencySeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:currency',
    description: 'Peuple la base de données avec les devises supportées',
)]
class SeedCurrencyCommand extends Command
{
    public function __construct(
        private readonly CurrencySeeder $currencySeeder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'clear',
            null,
            InputOption::VALUE_NONE,
            'Supprime toutes les devises avant de les recréer'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🌍 Seeding des devises');

        try {
            // Option pour tout supprimer d'abord
            if ($input->getOption('clear')) {
                $io->warning('Mode CLEAR activé - Toutes les devises vont être supprimées !');

                if (!$io->confirm('Êtes-vous sûr de vouloir continuer ?', false)) {
                    $io->info('Opération annulée');
                    return Command::SUCCESS;
                }

                $this->currencySeeder->clear();
                $io->success('Toutes les devises ont été supprimées');
            }

            // Seeding
            $io->section('Insertion des devises...');
            $this->currencySeeder->seed();

            $io->success('✅ Devises insérées avec succès !');

            $io->info([
                'Devises principales : EUR, USD, CAD, GBP, CHF',
                'Franc CFA : XAF (CEMAC), XOF (UEMOA)',
                'Devises africaines : NGN, GHS, KES, ZAR, MAD, DZD, TND, EGP',
                'Autres : AUD, NZD, MXN',
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors du seeding : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
