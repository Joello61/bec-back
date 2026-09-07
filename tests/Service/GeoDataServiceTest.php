<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\City;
use App\Entity\Country;
use App\Repository\CityRepository;
use App\Repository\CountryRepository;
use App\Service\GeoDataService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Phase 4b, Lot 5 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : orchestration
 * cache + repository, peu de logique metier propre hors des garde-fous "requete < 2
 * caracteres" (evite les recherches trop larges, coherent avec CLAUDE.md section 4 sur les
 * LIKE non indexables) et la normalisation des codes pays.
 */
class GeoDataServiceTest extends TestCase
{
    private CountryRepository&\PHPUnit\Framework\MockObject\MockObject $countryRepository;
    private CityRepository&\PHPUnit\Framework\MockObject\MockObject $cityRepository;
    private GeoDataService $service;

    protected function setUp(): void
    {
        $this->countryRepository = $this->createMock(CountryRepository::class);
        $this->cityRepository = $this->createMock(CityRepository::class);
        $this->service = new GeoDataService($this->countryRepository, $this->cityRepository, new ArrayAdapter());
    }

    private function country(string $code, string $name, ?string $nameFr = null, ?string $continent = 'Africa'): Country
    {
        $country = new Country();
        $country->setCode($code);
        $country->setName($name);
        $country->setNameFr($nameFr);
        $country->setContinent($continent);

        return $country;
    }

    private function city(string $name, ?Country $country = null, ?int $population = 1000): City
    {
        $city = new City();
        $city->setName($name);
        $city->setPopulation($population);
        if ($country) {
            $city->setCountry($country);
        }

        return $city;
    }

    // ==================== getAllCountries ====================

    public function testGetAllCountriesMapsToValueLabelContinent(): void
    {
        $this->countryRepository->method('findAllSorted')->willReturn([$this->country('CM', 'Cameroon', 'Cameroun', 'Africa')]);

        $result = $this->service->getAllCountries();

        self::assertSame(['value' => 'CM', 'label' => 'Cameroun', 'continent' => 'Africa'], $result[0]);
    }

    public function testGetAllCountriesFallsBackToEnglishNameWithoutFrenchName(): void
    {
        $this->countryRepository->method('findAllSorted')->willReturn([$this->country('US', 'United States', null)]);

        $result = $this->service->getAllCountries();

        self::assertSame('United States', $result[0]['label']);
    }

    public function testGetAllCountriesOnlyQueriesTheRepositoryOnce(): void
    {
        $this->countryRepository->expects(self::once())->method('findAllSorted')->willReturn([]);

        $this->service->getAllCountries();
        $this->service->getAllCountries();
    }

    // ==================== getCountryCodeByNameFr ====================

    public function testGetCountryCodeByNameFrReturnsTheCode(): void
    {
        $this->countryRepository->method('findOneBy')->with(['nameFr' => 'Cameroun'])->willReturn($this->country('CM', 'Cameroon', 'Cameroun'));

        self::assertSame('CM', $this->service->getCountryCodeByNameFr('Cameroun'));
    }

    public function testGetCountryCodeByNameFrReturnsNullWhenNotFound(): void
    {
        $this->countryRepository->method('findOneBy')->willReturn(null);

        self::assertNull($this->service->getCountryCodeByNameFr('Atlantide'));
    }

    // ==================== getCitiesByCountryCode ====================

    public function testGetCitiesByCountryCodeNormalizesTheCountryCode(): void
    {
        $this->cityRepository->expects(self::once())->method('findTopCitiesByCountryCode')->with('CM', 100)->willReturn([$this->city('Douala')]);

        $result = $this->service->getCitiesByCountryCode(' cm ');

        self::assertSame('Douala', $result[0]['value']);
    }

    // ==================== searchCitiesByCountryCode ====================

    public function testSearchCitiesByCountryCodeReturnsEmptyForATooShortQuery(): void
    {
        $this->cityRepository->expects(self::never())->method('searchByCountryCodeAndName');

        self::assertSame([], $this->service->searchCitiesByCountryCode('CM', 'a'));
    }

    public function testSearchCitiesByCountryCodeSearchesForAValidQuery(): void
    {
        $this->cityRepository->method('searchByCountryCodeAndName')->with('CM', 'dou', 50)->willReturn([$this->city('Douala')]);

        $result = $this->service->searchCitiesByCountryCode('cm', 'dou');

        self::assertCount(1, $result);
    }

    // ==================== countryExistsByCode / countryExists ====================

    public function testCountryExistsByCodeNormalizesTheCode(): void
    {
        $this->countryRepository->method('existsByCode')->with('CM')->willReturn(true);

        self::assertTrue($this->service->countryExistsByCode(' cm '));
    }

    public function testCountryExistsDelegatesByFrenchName(): void
    {
        $this->countryRepository->method('existsByNameFr')->with('Cameroun')->willReturn(true);

        self::assertTrue($this->service->countryExists('Cameroun'));
    }

    // ==================== getTopCitiesGlobal ====================

    public function testGetTopCitiesGlobalIncludesCountryInformation(): void
    {
        $country = $this->country('CM', 'Cameroon', 'Cameroun');
        $this->cityRepository->method('findTopCitiesGlobal')->willReturn([$this->city('Douala', $country)]);

        $result = $this->service->getTopCitiesGlobal();

        self::assertSame('Cameroun', $result[0]['country']);
        self::assertSame('CM', $result[0]['countryCode']);
    }

    // ==================== searchCitiesGlobal ====================

    public function testSearchCitiesGlobalReturnsEmptyForATooShortQuery(): void
    {
        $this->cityRepository->expects(self::never())->method('searchGlobal');

        self::assertSame([], $this->service->searchCitiesGlobal('a'));
    }

    public function testSearchCitiesGlobalSearchesForAValidQuery(): void
    {
        $country = $this->country('CM', 'Cameroon', 'Cameroun');
        $this->cityRepository->method('searchGlobal')->with('dou', 50)->willReturn([$this->city('Douala', $country)]);

        $result = $this->service->searchCitiesGlobal('dou');

        self::assertCount(1, $result);
    }

    // ==================== getContinentByPays / getTimeZoneByCityAndPays ====================

    public function testGetContinentByPaysDelegatesToTheRepository(): void
    {
        $this->countryRepository->method('findContinentByPays')->with('Cameroun')->willReturn('Africa');

        self::assertSame('Africa', $this->service->getContinentByPays('Cameroun'));
    }

    public function testGetTimeZoneByCityAndPaysDelegatesToTheRepository(): void
    {
        $this->cityRepository->method('findTimeZoneByCityAndPays')->with('Douala', 1)->willReturn('Africa/Douala');

        self::assertSame('Africa/Douala', $this->service->getTimeZoneByCityAndPays('Douala', 1));
    }
}
