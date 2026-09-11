<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use App\Repository\CountryRepository;
use App\Repository\CurrencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class CurrencyService
{
    // Mapping pays -> devise (codes ISO 3166-1 alpha-2 -> ISO 4217)
    private const COUNTRY_CURRENCY_MAP = [
        // Zone Euro
        'FR' => 'EUR', 'DE' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'PT' => 'EUR',
        'BE' => 'EUR', 'NL' => 'EUR', 'LU' => 'EUR', 'AT' => 'EUR', 'IE' => 'EUR',
        'GR' => 'EUR', 'FI' => 'EUR', 'EE' => 'EUR', 'LV' => 'EUR', 'LT' => 'EUR',
        'SK' => 'EUR', 'SI' => 'EUR', 'CY' => 'EUR', 'MT' => 'EUR',

        // Zone Franc CFA (Afrique Centrale - CEMAC)
        'CM' => 'XAF', 'CF' => 'XAF', 'TD' => 'XAF', 'CG' => 'XAF', 'GA' => 'XAF', 'GQ' => 'XAF',

        // Zone Franc CFA (Afrique Ouest - UEMOA)
        'BJ' => 'XOF', 'BF' => 'XOF', 'CI' => 'XOF', 'GW' => 'XOF', 'ML' => 'XOF',
        'NE' => 'XOF', 'SN' => 'XOF', 'TG' => 'XOF',

        // Amérique du Nord
        'US' => 'USD', 'CA' => 'CAD', 'MX' => 'MXN',

        // Royaume-Uni et Commonwealth
        'GB' => 'GBP', 'AU' => 'AUD', 'NZ' => 'NZD',

        // Suisse
        'CH' => 'CHF',

        // Autres devises africaines
        'NG' => 'NGN', 'GH' => 'GHS', 'KE' => 'KES', 'TZ' => 'TZS',
        'UG' => 'UGX', 'RW' => 'RWF', 'ZA' => 'ZAR', 'MA' => 'MAD',
        'DZ' => 'DZD', 'TN' => 'TND', 'EG' => 'EGP', 'ET' => 'ETB',
    ];

    public function __construct(
        private CurrencyRepository $currencyRepository,
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $exchangeRateApiKey,
        private CountryRepository $countryRepository,
        private EntityManagerInterface $entityManager,
        private string $defaultCurrency = 'EUR',
    ) {}

    /**
     * Détecte automatiquement la devise selon le pays
     * @return array<string, mixed>
     */
    public function getCurrencyAndLangByCountry(string $countryName): array
    {
        // Essayer de trouver le code pays (simpliste, peut être amélioré)
        $countryCodeAndLang = $this->getCountryCodeAndLanguages($countryName);
        $countryCode = $countryCodeAndLang['code'] ?? null;
        $countryLang = $this->normalizeLanguageCode($countryCodeAndLang['languages'] ?? null);

        if ($countryCode && isset(self::COUNTRY_CURRENCY_MAP[$countryCode])) {
            return ['currency' => self::COUNTRY_CURRENCY_MAP[$countryCode], 'languages' => $countryLang];
        }

        // Recherche en base de données
        $currency = $this->currencyRepository->findByCountry($countryCode ?? $countryName);

        if ($currency) {
            return ['currency'=>$currency->getCode(), 'languages' => $countryLang];
        }

        // Par défaut : EUR (devise de référence)
        $this->logger->warning('Devise non trouvée pour le pays', [
            'country' => $countryName,
            'countryCode' => $countryCode
        ]);

        return ['currency' => $this->defaultCurrency, 'languages' => $countryLang];
    }

    /**
     * Normalise une valeur de langue brute GeoNames (ex. "en-CM,fr-CM", format
     * locale-PAYS séparé par des virgules pour un pays multilingue) vers l'un des
     * deux seuls codes acceptés par UserSettings.langue ('fr'/'en', cf. UpdateSettingsDTO
     * et le schéma Zod frontend correspondant). Repli sur 'fr' si la langue détectée
     * n'est ni 'fr' ni 'en' - choix produit assumé (positionnement Cameroun/francophone
     * de Cobage), pas une valeur technique par défaut.
     */
    private function normalizeLanguageCode(?string $rawLanguages): string
    {
        if (!$rawLanguages) {
            return 'fr';
        }

        $firstLocale = explode(',', $rawLanguages)[0];
        $languageCode = strtolower(explode('-', $firstLocale)[0]);

        return in_array($languageCode, ['fr', 'en'], true) ? $languageCode : 'fr';
    }

    /**
     * Convertit un montant d'une devise vers une autre
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        // Si même devise, pas de conversion
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $fromRate = $this->getExchangeRate($fromCurrency);
        $toRate = $this->getExchangeRate($toCurrency);

        if ($fromRate === null || $toRate === null) {
            $this->logger->error('Taux de change introuvable', [
                'from' => $fromCurrency,
                'to' => $toCurrency
            ]);
            return $amount; // Retourner le montant original en cas d'erreur
        }

        // Conversion : montant -> EUR -> devise cible
        // Exemple: 1000 XAF -> EUR -> USD
        $amountInEur = $amount / (float) $fromRate;
        $convertedAmount = $amountInEur * (float) $toRate;

        return round($convertedAmount, 2);
    }

    /**
     * Formate un montant selon la devise
     */
    public function formatAmount(float $amount, string $currencyCode): string
    {
        $currency = $this->currencyRepository->findByCode($currencyCode);

        if (!$currency) {
            return number_format($amount, 2, ',', ' ') . ' ' . $currencyCode;
        }

        return $currency->formatAmount($amount);
    }

    /**
     * Récupère le taux de change d'une devise (avec cache 24h)
     */
    public function getExchangeRate(string $currencyCode): ?string
    {
        $currency = $this->currencyRepository->findByCode($currencyCode);

        if (!$currency) {
            return null;
        }

        // Si le taux est récent (< 24h), le retourner
        if ($currency->isExchangeRateFresh()) {
            return $currency->getExchangeRate();
        }

        // Sinon, mettre à jour depuis l'API
        $this->updateExchangeRates();

        // Recharger la devise
        $currency = $this->currencyRepository->findByCode($currencyCode);
        return $currency?->getExchangeRate();
    }

    /**
     * Met à jour les taux de change depuis l'API Exchange Rate
     * Utilise le cache pour éviter de dépasser les 1500 requêtes/mois
     */
    public function updateExchangeRates(): void
    {
        try {
            // Cache de 24h = 30 requêtes/mois maximum (au lieu de 1500)
            $rates = $this->cache->get('exchange_rates', function (ItemInterface $item) {
                $item->expiresAfter(86400); // 24 heures

                $this->logger->info('Récupération des taux de change depuis l\'API');

                // API Exchange Rate (base = EUR)
                $response = $this->httpClient->request('GET',
                    'https://v6.exchangerate-api.com/v6/' . $this->exchangeRateApiKey . '/latest/EUR'
                );

                $data = $response->toArray();

                if ($data['result'] !== 'success') {
                    throw new \Exception('Erreur API Exchange Rate: ' . ($data['error-type'] ?? 'unknown'));
                }

                return $data['conversion_rates'];
            });

            // Mettre à jour les devises en base
            $currencies = $this->currencyRepository->findAllActive();
            $now = new \DateTime();

            foreach ($currencies as $currency) {
                $code = $currency->getCode();

                // EUR est la devise de référence (taux = 1)
                if ($code === 'EUR') {
                    $currency->setExchangeRate('1.000000');
                    $currency->setRateUpdatedAt($now);
                    continue;
                }

                // Mettre à jour le taux si disponible
                if (isset($rates[$code])) {
                    $currency->setExchangeRate((string) $rates[$code]);
                    $currency->setRateUpdatedAt($now);
                }
            }

            $this->entityManager->flush();

            $this->logger->info('Taux de change mis à jour avec succès', [
                'count' => count($currencies)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la mise à jour des taux de change', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Récupère toutes les devises actives
     * @return \App\Entity\Currency[]
     */
    public function getAllActiveCurrencies(): array
    {
        return $this->currencyRepository->findAllActive();
    }

    /**
     * Récupère les devises les plus utilisées
     * @return \App\Entity\Currency[]
     */
    public function getMostUsedCurrencies(int $limit = 5): array
    {
        return $this->currencyRepository->findMostUsed($limit);
    }

    /**
     * Vérifie si une devise est supportée
     */
    public function isSupported(string $currencyCode): bool
    {
        return $this->currencyRepository->existsAndActive($currencyCode);
    }

    /**
     * Récupère la devise par défaut de l'application
     */
    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    /**
     * Obtient l'objet Currency complet
     */
    public function getCurrency(string $code): ?Currency
    {
        return $this->currencyRepository->findByCode($code);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCountryCodeAndLanguages(string $countryName): ?array
    {
        return $this->countryRepository->findCodeAndLangByPays($countryName);
    }


    /**
     * Crée une nouvelle devise (admin)
     * @param list<string> $countries
     */
    public function createCurrency(
        string $code,
        string $name,
        string $symbol,
        int $decimals = 2,
        array $countries = []
    ): Currency {
        $currency = new Currency();
        $currency->setCode($code)
            ->setName($name)
            ->setSymbol($symbol)
            ->setDecimals($decimals)
            ->setCountries($countries)
            ->setIsActive(true);

        $this->currencyRepository->save($currency);

        $this->logger->info('Nouvelle devise créée', [
            'code' => $code,
            'name' => $name
        ]);

        return $currency;
    }

    /**
     * Met à jour une devise existante (admin)
     * @param list<string>|null $countries
     */
    public function updateCurrency(
        string $code,
        ?string $name = null,
        ?string $symbol = null,
        ?int $decimals = null,
        ?array $countries = null,
        ?bool $isActive = null
    ): ?Currency {
        $currency = $this->currencyRepository->findByCode($code);

        if (!$currency) {
            return null;
        }

        if ($name !== null) {
            $currency->setName($name);
        }
        if ($symbol !== null) {
            $currency->setSymbol($symbol);
        }
        if ($decimals !== null) {
            $currency->setDecimals($decimals);
        }
        if ($countries !== null) {
            $currency->setCountries($countries);
        }
        if ($isActive !== null) {
            $currency->setIsActive($isActive);
        }

        $this->currencyRepository->save($currency);

        $this->logger->info('Devise mise à jour', [
            'code' => $code
        ]);

        return $currency;
    }

    /**
     * Obtient les informations de conversion pour affichage
     * @return array<string, mixed>
     */
    public function getConversionInfo(
        float $amount,
        string $fromCurrency,
        string $toCurrency
    ): array {
        $convertedAmount = $this->convert($amount, $fromCurrency, $toCurrency);
        $fromCurrencyObj = $this->getCurrency($fromCurrency);
        $toCurrencyObj = $this->getCurrency($toCurrency);

        return [
            'originalAmount' => $amount,
            'originalCurrency' => $fromCurrency,
            'originalFormatted' => $fromCurrencyObj?->formatAmount($amount) ?? "$amount $fromCurrency",
            'convertedAmount' => $convertedAmount,
            'convertedCurrency' => $toCurrency,
            'convertedFormatted' => $toCurrencyObj?->formatAmount($convertedAmount) ?? "$convertedAmount $toCurrency",
            'exchangeRate' => $this->getExchangeRate($toCurrency),
        ];
    }
}
