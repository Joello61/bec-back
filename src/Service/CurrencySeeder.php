<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service pour peupler la table des devises avec les données initiales
 */
readonly class CurrencySeeder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CurrencyRepository $currencyRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Peuple la base de données avec les devises supportées
     */
    public function seed(): void
    {
        $this->logger->info('Début du seeding des devises');

        $currencies = $this->getCurrenciesData();
        $count = 0;

        foreach ($currencies as $data) {
            // Vérifier si la devise existe déjà
            $existing = $this->currencyRepository->findByCode($data['code']);

            if ($existing) {
                $this->logger->info('Devise déjà existante, ignorée', ['code' => $data['code']]);
                continue;
            }

            // Créer la devise
            $currency = new Currency();
            $currency->setCode($data['code'])
                ->setName($data['name'])
                ->setSymbol($data['symbol'])
                ->setDecimals($data['decimals'])
                ->setCountries($data['countries'])
                ->setIsActive($data['is_active']);

            $this->entityManager->persist($currency);
            $count++;

            $this->logger->info('Devise créée', [
                'code' => $data['code'],
                'name' => $data['name']
            ]);
        }

        $this->entityManager->flush();

        $this->logger->info('Seeding des devises terminé', [
            'count' => $count
        ]);
    }

    /**
     * Retourne les données des devises à insérer
     */
    private function getCurrenciesData(): array
    {
        return [
            // ==================== DEVISES PRINCIPALES ====================
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'countries' => ['FR', 'DE', 'IT', 'ES', 'PT', 'BE', 'NL', 'LU', 'AT', 'IE', 'GR', 'FI', 'EE', 'LV', 'LT', 'SK', 'SI', 'CY', 'MT'],
                'is_active' => true,
            ],
            [
                'code' => 'USD',
                'name' => 'Dollar américain',
                'symbol' => '$',
                'decimals' => 2,
                'countries' => ['US'],
                'is_active' => true,
            ],
            [
                'code' => 'CAD',
                'name' => 'Dollar canadien',
                'symbol' => 'CAD',
                'decimals' => 2,
                'countries' => ['CA'],
                'is_active' => true,
            ],
            [
                'code' => 'GBP',
                'name' => 'Livre sterling',
                'symbol' => '£',
                'decimals' => 2,
                'countries' => ['GB'],
                'is_active' => true,
            ],
            [
                'code' => 'CHF',
                'name' => 'Franc suisse',
                'symbol' => 'CHF',
                'decimals' => 2,
                'countries' => ['CH'],
                'is_active' => true,
            ],

            // ==================== FRANC CFA (AFRIQUE CENTRALE - CEMAC) ====================
            [
                'code' => 'XAF',
                'name' => 'Franc CFA (BEAC)',
                'symbol' => 'FCFA',
                'decimals' => 0, // Pas de centimes
                'countries' => ['CM', 'CF', 'TD', 'CG', 'GA', 'GQ'],
                'is_active' => true,
            ],

            // ==================== FRANC CFA (AFRIQUE OUEST - UEMOA) ====================
            [
                'code' => 'XOF',
                'name' => 'Franc CFA (BCEAO)',
                'symbol' => 'FCFA',
                'decimals' => 0, // Pas de centimes
                'countries' => ['BJ', 'BF', 'CI', 'GW', 'ML', 'NE', 'SN', 'TG'],
                'is_active' => true,
            ],

            // ==================== AUTRES DEVISES AFRICAINES ====================
            [
                'code' => 'NGN',
                'name' => 'Naira nigérian',
                'symbol' => '₦',
                'decimals' => 2,
                'countries' => ['NG'],
                'is_active' => true,
            ],
            [
                'code' => 'GHS',
                'name' => 'Cedi ghanéen',
                'symbol' => 'GH₵',
                'decimals' => 2,
                'countries' => ['GH'],
                'is_active' => true,
            ],
            [
                'code' => 'KES',
                'name' => 'Shilling kényan',
                'symbol' => 'KSh',
                'decimals' => 2,
                'countries' => ['KE'],
                'is_active' => true,
            ],
            [
                'code' => 'ZAR',
                'name' => 'Rand sud-africain',
                'symbol' => 'R',
                'decimals' => 2,
                'countries' => ['ZA'],
                'is_active' => true,
            ],
            [
                'code' => 'MAD',
                'name' => 'Dirham marocain',
                'symbol' => 'MAD',
                'decimals' => 2,
                'countries' => ['MA'],
                'is_active' => true,
            ],
            [
                'code' => 'DZD',
                'name' => 'Dinar algérien',
                'symbol' => 'DA',
                'decimals' => 2,
                'countries' => ['DZ'],
                'is_active' => true,
            ],
            [
                'code' => 'TND',
                'name' => 'Dinar tunisien',
                'symbol' => 'TND',
                'decimals' => 3,
                'countries' => ['TN'],
                'is_active' => true,
            ],
            [
                'code' => 'EGP',
                'name' => 'Livre égyptienne',
                'symbol' => 'E£',
                'decimals' => 2,
                'countries' => ['EG'],
                'is_active' => true,
            ],

            // ==================== AUTRES DEVISES POPULAIRES ====================
            [
                'code' => 'AUD',
                'name' => 'Dollar australien',
                'symbol' => 'AUD',
                'decimals' => 2,
                'countries' => ['AU'],
                'is_active' => true,
            ],
            [
                'code' => 'NZD',
                'name' => 'Dollar néo-zélandais',
                'symbol' => 'NZD',
                'decimals' => 2,
                'countries' => ['NZ'],
                'is_active' => true,
            ],
            [
                'code' => 'MXN',
                'name' => 'Peso mexicain',
                'symbol' => 'MXN',
                'decimals' => 2,
                'countries' => ['MX'],
                'is_active' => true,
            ],
        ];
    }

    /**
     * Supprime toutes les devises (attention !)
     */
    public function clear(): void
    {
        $this->logger->warning('Suppression de toutes les devises');

        $currencies = $this->currencyRepository->findAll();

        foreach ($currencies as $currency) {
            $this->entityManager->remove($currency);
        }

        $this->entityManager->flush();

        $this->logger->info('Toutes les devises ont été supprimées');
    }
}
