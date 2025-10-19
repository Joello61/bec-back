<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use App\Repository\CountryRepository;
use App\Repository\CurrencyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class CurrencyService
{
    // Mapping pays → devise (codes ISO 3166-1 alpha-2 → ISO 4217)
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
        private string $defaultCurrency = 'EUR',
        private string $defaultLanguages = 'fr-FR'
    ) {}

    /**
     * Détecte automatiquement la devise selon le pays
     */
    public function getCurrencyAndLangByCountry(string $countryName): array
    {
        // Essayer de trouver le code pays (simpliste, peut être amélioré)
        $countryCodeAndLang = $this->getCountryCodeAndLanguages($countryName);
        $countryCode = $countryCodeAndLang['code'] ?? null;
        $countryLang = $countryCodeAndLang['languages'] ?? null;

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

        return ['currency' => $this->defaultCurrency, 'languages' => $this->defaultLanguages];
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

        // Conversion : montant → EUR → devise cible
        // Exemple: 1000 XAF → EUR → USD
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

            $this->currencyRepository->getEntityManager()->flush();

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
     */
    public function getAllActiveCurrencies(): array
    {
        return $this->currencyRepository->findAllActive();
    }

    /**
     * Récupère les devises les plus utilisées
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

    public function getCountryCodeAndLanguages(string $countryName): ?array
    {
        return $this->countryRepository->findCodeAndLangByPays($countryName);
    }

    /**
     * Convertit le nom de pays en code ISO (simpliste)
     * À améliorer avec une vraie API de géolocalisation si nécessaire
     */
    private function getCountryCode(string $countryName): ?string
    {
        // Mapping des noms de pays courants vers codes ISO
        $countryNameMap = [
            // Français
            'France' => 'FR',
            'Cameroun' => 'CM',
            'Cameroon' => 'CM',
            'Canada' => 'CA',
            'États-Unis' => 'US',
            'Etats-Unis' => 'US',
            'États Unis' => 'US',
            'USA' => 'US',
            'Belgique' => 'BE',
            'Suisse' => 'CH',
            'Luxembourg' => 'LU',
            'Allemagne' => 'DE',
            'Italie' => 'IT',
            'Espagne' => 'ES',
            'Portugal' => 'PT',
            'Pays-Bas' => 'NL',
            'Royaume-Uni' => 'GB',
            'Angleterre' => 'GB',
            'Gabon' => 'GA',
            'Congo' => 'CG',
            'Tchad' => 'TD',
            'Centrafrique' => 'CF',
            'Guinée Équatoriale' => 'GQ',
            'Sénégal' => 'SN',
            'Côte d\'Ivoire' => 'CI',
            'Mali' => 'ML',
            'Burkina Faso' => 'BF',
            'Niger' => 'NE',
            'Togo' => 'TG',
            'Bénin' => 'BJ',
            'Maroc' => 'MA',
            'Algérie' => 'DZ',
            'Tunisie' => 'TN',
            'Nigeria' => 'NG',
            'Kenya' => 'KE',
            'Afrique du Sud' => 'ZA',

            // Anglais
            'Germany' => 'DE',
            'Italy' => 'IT',
            'Spain' => 'ES',
            'Switzerland' => 'CH',
            'Belgium' => 'BE',
            'Netherlands' => 'NL',
            'United Kingdom' => 'GB',
            'United States' => 'US',
            'South Africa' => 'ZA',
            'Morocco' => 'MA',
            'Algeria' => 'DZ',
            'Tunisia' => 'TN',
        ];

        $normalizedName = trim($countryName);

        // Recherche exacte
        if (isset($countryNameMap[$normalizedName])) {
            return $countryNameMap[$normalizedName];
        }

        // Recherche insensible à la casse
        $lowerName = strtolower($normalizedName);
        foreach ($countryNameMap as $name => $code) {
            if (strtolower($name) === $lowerName) {
                return $code;
            }
        }

        // Si le nom fait 2 caractères, on suppose que c'est déjà un code
        if (strlen($normalizedName) === 2) {
            return strtoupper($normalizedName);
        }

        return null;
    }

    /**
     * Crée une nouvelle devise (admin)
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
