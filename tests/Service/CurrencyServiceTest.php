<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Currency;
use App\Repository\CountryRepository;
use App\Repository\CurrencyRepository;
use App\Service\CurrencyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Phase 4b, Lot 5 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : le service le
 * plus riche en calculs du lot - conversion croisee via EUR pivot, cache 24h, mapping
 * pays->devise en 2 niveaux, et validation du fix de updateExchangeRates() (commit
 * precedent : la persistance echouait silencieusement a chaque appel jusqu'ici).
 */
class CurrencyServiceTest extends TestCase
{
    private CurrencyRepository&\PHPUnit\Framework\MockObject\MockObject $currencyRepository;
    private CountryRepository&\PHPUnit\Framework\MockObject\MockObject $countryRepository;
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;

    private function makeService(?MockHttpClient $httpClient = null): CurrencyService
    {
        return new CurrencyService(
            $this->currencyRepository,
            $httpClient ?? new MockHttpClient(),
            new ArrayAdapter(),
            new NullLogger(),
            'fake-api-key',
            $this->countryRepository,
            $this->em,
            'EUR',
        );
    }

    protected function setUp(): void
    {
        $this->currencyRepository = $this->createMock(CurrencyRepository::class);
        $this->countryRepository = $this->createMock(CountryRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    private function currency(string $code, string $rate = '1.000000', bool $fresh = true): Currency
    {
        $currency = new Currency();
        $currency->setCode($code);
        $currency->setName($code);
        $currency->setSymbol($code);
        $currency->setExchangeRate($rate);
        $currency->setRateUpdatedAt($fresh ? new \DateTime() : new \DateTime('-2 days'));

        return $currency;
    }

    // ==================== getCurrencyAndLangByCountry ====================

    public function testGetCurrencyAndLangByCountryUsesTheConstantMapFirst(): void
    {
        $this->countryRepository->method('findCodeAndLangByPays')->willReturn(['code' => 'CM', 'languages' => 'fr-FR']);
        $service = $this->makeService();

        $result = $service->getCurrencyAndLangByCountry('Cameroun');

        self::assertSame('XAF', $result['currency']);
    }

    public function testGetCurrencyAndLangByCountryFallsBackToDatabase(): void
    {
        $this->countryRepository->method('findCodeAndLangByPays')->willReturn(['code' => 'ZZ', 'languages' => 'en']);
        $this->currencyRepository->method('findByCountry')->willReturn($this->currency('ABC'));
        $service = $this->makeService();

        $result = $service->getCurrencyAndLangByCountry('Pays Inconnu');

        self::assertSame('ABC', $result['currency']);
    }

    public function testGetCurrencyAndLangByCountryFallsBackToTheUltimateDefault(): void
    {
        $this->countryRepository->method('findCodeAndLangByPays')->willReturn(null);
        $this->currencyRepository->method('findByCountry')->willReturn(null);
        $service = $this->makeService();

        $result = $service->getCurrencyAndLangByCountry('Atlantide');

        self::assertSame('EUR', $result['currency']);
        self::assertSame('fr', $result['languages']);
    }

    /**
     * Bug de production : un pays multilingue (format GeoNames brut, ex. "en-CM,fr-CM"
     * pour le Cameroun) était jusqu'ici écrit tel quel dans UserSettings.langue, qui
     * n'accepte que 'fr'/'en' - cassait la sauvegarde du formulaire Préférences pour
     * tout utilisateur d'un tel pays (cf. plan-correction-cobage.md, Phase 13/Lot B1).
     */
    public function testGetCurrencyAndLangByCountryNormalizesMultiLanguageRawValue(): void
    {
        $this->countryRepository->method('findCodeAndLangByPays')->willReturn(['code' => 'CM', 'languages' => 'en-CM,fr-CM']);
        $service = $this->makeService();

        $result = $service->getCurrencyAndLangByCountry('Cameroun');

        self::assertSame('en', $result['languages']);
    }

    public function testGetCurrencyAndLangByCountryFallsBackToFrenchForAnUnsupportedLanguage(): void
    {
        $this->countryRepository->method('findCodeAndLangByPays')->willReturn(['code' => 'ZZ', 'languages' => 'de-DE']);
        $this->currencyRepository->method('findByCountry')->willReturn($this->currency('ABC'));
        $service = $this->makeService();

        $result = $service->getCurrencyAndLangByCountry('Pays Inconnu');

        self::assertSame('fr', $result['languages']);
    }

    public function testGetCurrencyAndLangByCountryKeepsAnAlreadyNormalizedLanguage(): void
    {
        $this->countryRepository->method('findCodeAndLangByPays')->willReturn(['code' => 'ZZ', 'languages' => 'en']);
        $this->currencyRepository->method('findByCountry')->willReturn($this->currency('ABC'));
        $service = $this->makeService();

        $result = $service->getCurrencyAndLangByCountry('Pays Inconnu');

        self::assertSame('en', $result['languages']);
    }

    // ==================== convert ====================

    public function testConvertReturnsTheSameAmountForTheSameCurrency(): void
    {
        $this->currencyRepository->expects(self::never())->method('findByCode');
        $service = $this->makeService();

        self::assertSame(100.0, $service->convert(100.0, 'XAF', 'XAF'));
    }

    public function testConvertCrossesRatesThroughEur(): void
    {
        $this->currencyRepository->method('findByCode')->willReturnMap([
            ['XAF', $this->currency('XAF', '655.957000')],
            ['USD', $this->currency('USD', '1.100000')],
        ]);
        $service = $this->makeService();

        // 1000 XAF -> EUR (1000/655.957) -> USD (*1.1)
        $result = $service->convert(1000.0, 'XAF', 'USD');

        self::assertEqualsWithDelta(1.68, $result, 0.01);
    }

    public function testConvertReturnsOriginalAmountWhenFromRateIsMissing(): void
    {
        $this->currencyRepository->method('findByCode')->willReturn(null);
        $service = $this->makeService();

        self::assertSame(100.0, $service->convert(100.0, 'XAF', 'USD'));
    }

    // ==================== formatAmount ====================

    public function testFormatAmountDelegatesToTheCurrencyEntity(): void
    {
        $currency = $this->currency('XAF');
        $this->currencyRepository->method('findByCode')->willReturn($currency);
        $service = $this->makeService();

        self::assertSame($currency->formatAmount(100.0), $service->formatAmount(100.0, 'XAF'));
    }

    public function testFormatAmountFallsBackToGenericFormattingForAnUnknownCurrency(): void
    {
        $this->currencyRepository->method('findByCode')->willReturn(null);
        $service = $this->makeService();

        self::assertSame('100,00 ZZZ', $service->formatAmount(100.0, 'ZZZ'));
    }

    // ==================== getExchangeRate ====================

    public function testGetExchangeRateReturnsTheFreshRateDirectly(): void
    {
        $currency = $this->currency('XAF', '655.957000', fresh: true);
        $this->currencyRepository->method('findByCode')->willReturn($currency);
        $httpClient = new MockHttpClient(function () {
            self::fail('un taux frais ne doit jamais declencher un appel API');
        });
        $service = $this->makeService($httpClient);

        self::assertSame('655.957000', $service->getExchangeRate('XAF'));
    }

    public function testGetExchangeRateReturnsNullForAnUnknownCurrency(): void
    {
        $this->currencyRepository->method('findByCode')->willReturn(null);
        $service = $this->makeService();

        self::assertNull($service->getExchangeRate('ZZZ'));
    }

    // ==================== updateExchangeRates ====================

    public function testUpdateExchangeRatesForcesEurToOneAndFlushes(): void
    {
        $eur = $this->currency('EUR', '0.000000', fresh: false);
        $xaf = $this->currency('XAF', '0.000000', fresh: false);
        $this->currencyRepository->method('findAllActive')->willReturn([$eur, $xaf]);
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'result' => 'success',
            'conversion_rates' => ['XAF' => 655.957, 'USD' => 1.08],
        ])));
        $this->em->expects(self::once())->method('flush');
        $service = $this->makeService($httpClient);

        $service->updateExchangeRates();

        self::assertSame('1.000000', $eur->getExchangeRate());
        self::assertSame('655.957', $xaf->getExchangeRate());
    }

    public function testUpdateExchangeRatesLeavesACurrencyUntouchedWhenAbsentFromTheApiResponse(): void
    {
        $ghost = $this->currency('GHOST', '42.000000', fresh: false);
        $this->currencyRepository->method('findAllActive')->willReturn([$ghost]);
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'result' => 'success',
            'conversion_rates' => ['XAF' => 655.957],
        ])));
        $service = $this->makeService($httpClient);

        $service->updateExchangeRates();

        self::assertSame('42.000000', $ghost->getExchangeRate());
    }

    public function testUpdateExchangeRatesFailsSilentlyWhenTheApiReturnsAnError(): void
    {
        $this->currencyRepository->method('findAllActive')->willReturn([]);
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'result' => 'error',
            'error-type' => 'invalid-key',
        ])));
        $this->em->expects(self::never())->method('flush');
        $service = $this->makeService($httpClient);

        // ne doit lever aucune exception - capturee et loggee en interne
        $service->updateExchangeRates();
        self::assertTrue(true);
    }

    // ==================== getConversionInfo ====================

    public function testGetConversionInfoCombinesConversionAndFormatting(): void
    {
        $xaf = $this->currency('XAF', '655.957000');
        $usd = $this->currency('USD', '1.100000');
        $this->currencyRepository->method('findByCode')->willReturnMap([
            ['XAF', $xaf],
            ['USD', $usd],
        ]);
        $service = $this->makeService();

        $result = $service->getConversionInfo(1000.0, 'XAF', 'USD');

        self::assertSame(1000.0, $result['originalAmount']);
        self::assertSame('XAF', $result['originalCurrency']);
        self::assertSame('USD', $result['convertedCurrency']);
        self::assertArrayHasKey('convertedAmount', $result);
    }

    // ==================== createCurrency / updateCurrency ====================

    public function testCreateCurrencyPersistsViaTheRepository(): void
    {
        $this->currencyRepository->expects(self::once())->method('save')->with(self::isInstanceOf(Currency::class));
        $service = $this->makeService();

        $currency = $service->createCurrency('GBP', 'Livre Sterling', '£');

        self::assertSame('GBP', $currency->getCode());
        self::assertTrue($currency->isActive());
    }

    public function testUpdateCurrencyReturnsNullWhenNotFound(): void
    {
        $this->currencyRepository->method('findByCode')->willReturn(null);
        $service = $this->makeService();

        self::assertNull($service->updateCurrency('ZZZ', name: 'X'));
    }

    public function testUpdateCurrencyOnlyChangesProvidedFields(): void
    {
        $currency = $this->currency('XAF');
        $currency->setName('Ancien nom');
        $currency->setSymbol('Ancien symbole');
        $this->currencyRepository->method('findByCode')->willReturn($currency);
        $this->currencyRepository->expects(self::once())->method('save');
        $service = $this->makeService();

        $result = $service->updateCurrency('XAF', name: 'Nouveau nom');

        self::assertSame('Nouveau nom', $result->getName());
        self::assertSame('Ancien symbole', $result->getSymbol(), 'un champ non fourni ne doit jamais etre ecrase');
    }

    // ==================== delegations simples ====================

    public function testIsSupportedDelegatesToTheRepository(): void
    {
        $this->currencyRepository->method('existsAndActive')->with('XAF')->willReturn(true);
        $service = $this->makeService();

        self::assertTrue($service->isSupported('XAF'));
    }

    public function testGetDefaultCurrencyReturnsTheConfiguredValue(): void
    {
        self::assertSame('EUR', $this->makeService()->getDefaultCurrency());
    }

    public function testGetAllActiveCurrenciesDelegatesToTheRepository(): void
    {
        $this->currencyRepository->method('findAllActive')->willReturn(['a', 'b']);

        self::assertSame(['a', 'b'], $this->makeService()->getAllActiveCurrencies());
    }
}
