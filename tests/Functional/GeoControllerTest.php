<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\City;
use App\Entity\Country;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 10 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : integration
 * HTTP de GeoController - security.yaml ne place que /api/geo/cities/top100 et
 * /api/geo/cities/search-global en PUBLIC_ACCESS ; les autres routes (countries, cities,
 * cities/search, continent/{pays}) restent sous la regle globale IS_AUTHENTICATED_FULLY -
 * verifie empiriquement, meme constat que pour CurrencyController dans ce lot.
 */
class GeoControllerTest extends WebTestCase
{
    use UserFactoryTrait;
    use JwtAuthenticationTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private JWTTokenManagerInterface $jwtManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
    }

    private function country(string $code, string $nameFr, string $continent = 'EU'): Country
    {
        $country = new Country();
        $country->setCode($code);
        $country->setName($nameFr);
        $country->setNameFr($nameFr);
        $country->setContinent($continent);
        $this->em->persist($country);
        $this->em->flush();

        return $country;
    }

    private function city(Country $country, string $name, int $population = 1000): City
    {
        $city = new City();
        $city->setCountry($country);
        $city->setName($name);
        $city->setPopulation($population);
        $this->em->persist($city);
        $this->em->flush();

        return $city;
    }

    // ==================== countries ====================

    public function testCountriesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/geo/countries');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCountriesReturnsThePersistedCountries(): void
    {
        $this->country('FR', 'France');
        $this->authenticateAs($this->createUser('geo-countries'));

        $this->client->request('GET', '/api/geo/countries');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload);
    }

    // ==================== cities ====================

    public function testCitiesRequiresACountryParameter(): void
    {
        $this->authenticateAs($this->createUser('geo-cities-noparam'));

        $this->client->request('GET', '/api/geo/cities');

        self::assertResponseStatusCodeSame(400);
    }

    public function testCitiesReturns404ForAnUnknownCountry(): void
    {
        $this->authenticateAs($this->createUser('geo-cities-unknown'));

        $this->client->request('GET', '/api/geo/cities?country=Narnia');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCitiesReturnsCitiesForAKnownCountry(): void
    {
        $country = $this->country('F1', 'France-Unique-Cities');
        $this->city($country, 'Paris');
        $this->authenticateAs($this->createUser('geo-cities-ok'));

        $this->client->request('GET', '/api/geo/cities?country=France-Unique-Cities');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload);
    }

    // ==================== cities/search ====================

    public function testSearchCitiesRequiresACountryParameter(): void
    {
        $this->authenticateAs($this->createUser('geo-search-nocountry'));

        $this->client->request('GET', '/api/geo/cities/search?q=nan');

        self::assertResponseStatusCodeSame(400);
    }

    public function testSearchCitiesRejectsATooShortQuery(): void
    {
        $this->authenticateAs($this->createUser('geo-search-shortq'));

        $this->client->request('GET', '/api/geo/cities/search?country=France&q=n');

        self::assertResponseStatusCodeSame(400);
    }

    // ==================== cities/top100 (public) ====================

    public function testTopCitiesGlobalIsPublic(): void
    {
        $this->client->request('GET', '/api/geo/cities/top100');

        self::assertResponseIsSuccessful();
    }

    // ==================== cities/search-global (public) ====================

    public function testSearchCitiesGlobalIsPublic(): void
    {
        $this->client->request('GET', '/api/geo/cities/search-global?q=par');

        self::assertResponseIsSuccessful();
    }

    public function testSearchCitiesGlobalRejectsATooShortQuery(): void
    {
        $this->client->request('GET', '/api/geo/cities/search-global?q=p');

        self::assertResponseStatusCodeSame(400);
    }

    // ==================== continent ====================

    public function testGetContinentByPaysReturns404ForAnUnknownCountry(): void
    {
        $this->authenticateAs($this->createUser('geo-continent-unknown'));

        $this->client->request('GET', '/api/geo/continent/Narnia');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetContinentByPaysSucceeds(): void
    {
        $this->country('J1', 'Japon-Unique-Continent', 'AS');
        $this->authenticateAs($this->createUser('geo-continent-ok'));

        $this->client->request('GET', '/api/geo/continent/Japon-Unique-Continent');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('AS', $payload['continent']);
    }
}
