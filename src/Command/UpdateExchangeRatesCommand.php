<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CurrencyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:currency:update-rates',
    description: 'Met à jour les taux de change depuis l\'API Exchange Rate',
)]
class UpdateExchangeRatesCommand extends Command
{
    public function __construct(
        private readonly CurrencyService $currencyService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Mise à jour des taux de change');

        try {
            $io->section('Récupération des taux depuis Exchange Rate API...');

            $this->currencyService->updateExchangeRates();

            $io->success('Taux de change mis à jour avec succès !');

            $io->info([
                'Les taux sont mis en cache pendant 24h',
                'Cela permet d\'économiser les appels API (1500 max/mois)',
            ]);

            // Afficher quelques taux à titre d'exemple
            $io->section('Exemples de taux actuels (base EUR = 1)');

            $examples = ['USD', 'XAF', 'CAD', 'GBP'];
            $rows = [];

            foreach ($examples as $code) {
                $currency = $this->currencyService->getCurrency($code);
                if ($currency) {
                    $rows[] = [
                        $code,
                        $currency->getName(),
                        $currency->getExchangeRate(),
                        $currency->getRateUpdatedAt()?->format('Y-m-d H:i:s') ?? 'Jamais',
                    ];
                }
            }

            $io->table(
                ['Code', 'Nom', 'Taux (EUR = 1)', 'Mis à jour'],
                $rows
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de la mise à jour : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
