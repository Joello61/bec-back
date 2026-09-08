<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\City;
use App\Entity\Country;
use App\Repository\CityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : regression
 * des corrections deja appliquees une fois et documentees en commentaire dans le code
 * (recherche insensible a la casse via LOWER(), marquees "CORRECTION" dans
 * CityRepository) - jamais verifiees par un test jusqu'ici.
 */
class CityRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CityRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(CityRepository::class);
    }

    private function country(string $code, string $nameFr): Country
    {
        $country = new Country();
        $country->setCode($code);
        $country->setName($nameFr);
        $country->setNameFr($nameFr);
        $this->em->persist($country);
        $this->em->flush();

        return $country;
    }

    private function city(Country $country, string $name, int $population = 1000, ?string $admin1Code = null): City
    {
        $city = new City();
        $city->setCountry($country);
        $city->setName($name);
        $city->setPopulation($population);
        if ($admin1Code !== null) {
            $city->setAdmin1Code($admin1Code);
        }
        $this->em->persist($city);
        $this->em->flush();

        return $city;
    }

    // ==================== searchByCountryAndName : regression case-insensitive ====================

    public function testSearchByCountryAndNameIsCaseInsensitive(): void
    {
        $country = $this->country('C1', 'Pays-Search-Unique');
        $this->city($country, 'Nantes');

        $result = $this->repository->searchByCountryAndName($country, 'NAN');

        self::assertNotEmpty($result);
        self::assertSame('Nantes', $result[0]->getName());
    }

    public function testSearchByCountryAndNameOrdersByPopulationDescending(): void
    {
        $country = $this->country('C2', 'Pays-Order-Unique');
        $this->city($country, 'Nancy-Petite', population: 100);
        $this->city($country, 'Nancy-Grande', population: 500000);

        $result = $this->repository->searchByCountryAndName($country, 'nan');

        self::assertSame('Nancy-Grande', $result[0]->getName());
    }

    // ==================== searchByCountryCodeAndName : regression case-insensitive ====================

    public function testSearchByCountryCodeAndNameIsCaseInsensitiveOnBothSides(): void
    {
        $country = $this->country('C3', 'Pays-Code-Unique');
        $this->city($country, 'Marseille');

        $result = $this->repository->searchByCountryCodeAndName('c3', 'MAR');

        self::assertNotEmpty($result);
        self::assertSame('Marseille', $result[0]->getName());
    }

    // ==================== findByNameAndCountry : regression case-insensitive ====================

    public function testFindByNameAndCountryIsCaseInsensitive(): void
    {
        $country = $this->country('C4', 'Pays-Exact-Unique');
        $this->city($country, 'Lyon');

        $found = $this->repository->findByNameAndCountry('LYON', $country);

        self::assertNotNull($found);
        self::assertSame('Lyon', $found->getName());
    }

    public function testFindByNameAndCountryReturnsNullWhenNoMatch(): void
    {
        $country = $this->country('C5', 'Pays-NoMatch-Unique');

        self::assertNull($this->repository->findByNameAndCountry('Atlantide', $country));
    }

    // ==================== findTimeZoneByCityAndPays : regression doublons GeoNames ====================

    public function testFindTimeZoneByCityAndPaysDoesNotCrashWhenTheCityNameIsDuplicatedInTheSameCountry(): void
    {
        // Regression : les donnees GeoNames importees contiennent de vrais doublons
        // (nom, pays) - constate en session sur "Douala"/Cameroun (2 entrees distinctes).
        // getOneOrNullResult() sans setMaxResults(1) levait NonUniqueResultException.
        $country = $this->country('C6', 'Pays-Doublon-Unique');
        $first = $this->city($country, 'VilleDupliquee');
        $first->setTimezone('Africa/Douala');
        $second = $this->city($country, 'VilleDupliquee');
        $second->setTimezone('Africa/Douala');
        $this->em->flush();

        $timezone = $this->repository->findTimeZoneByCityAndPays('VilleDupliquee', $country->getId());

        self::assertSame('Africa/Douala', $timezone);
    }

    public function testFindTimeZoneByCityAndPaysReturnsNullWhenNoMatch(): void
    {
        $country = $this->country('C7', 'Pays-SansVille-Unique');

        self::assertNull($this->repository->findTimeZoneByCityAndPays('VilleInexistante', $country->getId()));
    }

    // ==================== searchGlobal : regression case-insensitive ====================

    public function testSearchGlobalIsCaseInsensitiveAcrossCountries(): void
    {
        $country = $this->country('C6', 'Pays-Global-Unique');
        $this->city($country, 'Toulouse');

        $result = $this->repository->searchGlobal('TOU');

        $names = array_map(fn (City $c) => $c->getName(), $result);
        self::assertContains('Toulouse', $names);
    }

    // ==================== countByCountry ====================

    public function testCountByCountryCountsOnlyThatCountrysCities(): void
    {
        $country1 = $this->country('C7', 'Pays-Count1-Unique');
        $country2 = $this->country('C8', 'Pays-Count2-Unique');
        $this->city($country1, 'Ville1');
        $this->city($country1, 'Ville2');
        $this->city($country2, 'Ville3');

        self::assertSame(2, $this->repository->countByCountry($country1));
    }

    // ==================== findByCountryAndRegion ====================

    public function testFindByCountryAndRegionFiltersByAdmin1Code(): void
    {
        $country = $this->country('C9', 'Pays-Region-Unique');
        $this->city($country, 'Ville-Region-A', admin1Code: 'REG-A');
        $this->city($country, 'Ville-Region-B', admin1Code: 'REG-B');

        $result = $this->repository->findByCountryAndRegion($country, 'REG-A');

        self::assertCount(1, $result);
        self::assertSame('Ville-Region-A', $result[0]->getName());
    }
}
