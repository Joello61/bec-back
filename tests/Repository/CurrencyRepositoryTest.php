<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee de CurrencyRepository, notamment regression des deux fonctions DQL
 * MySQL non portables corrigees au Lot 10 (findByCountry via JSON_CONTAINS,
 * findMostUsed via FIELD()).
 */
class CurrencyRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CurrencyRepository $currencyRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->currencyRepository = static::getContainer()->get(CurrencyRepository::class);
    }

    private function currency(
        string $code,
        string $name,
        bool $active = true,
        array $countries = [],
        ?\DateTimeInterface $rateUpdatedAt = null
    ): Currency {
        $currency = new Currency();
        $currency->setCode($code);
        $currency->setName($name);
        $currency->setSymbol($code);
        $currency->setExchangeRate('1');
        $currency->setIsActive($active);
        $currency->setCountries($countries);
        if ($rateUpdatedAt !== null) {
            $currency->setRateUpdatedAt($rateUpdatedAt);
        }
        $this->em->persist($currency);
        $this->em->flush();

        return $currency;
    }

    // ==================== findByCode ====================

    public function testFindByCodeIsCaseInsensitive(): void
    {
        $this->currency('EUR', 'Euro');

        $found = $this->currencyRepository->findByCode('eur');

        self::assertNotNull($found);
        self::assertSame('EUR', $found->getCode());
    }

    // ==================== findAllActive ====================

    public function testFindAllActiveExcludesInactiveCurrencies(): void
    {
        $this->currency('E1', 'Active-Currency-Unique', active: true);
        $this->currency('I1', 'Inactive-Currency-Unique', active: false);

        $result = $this->currencyRepository->findAllActive();

        $names = array_map(fn (Currency $c) => $c->getName(), $result);
        self::assertContains('Active-Currency-Unique', $names);
        self::assertNotContains('Inactive-Currency-Unique', $names);
    }

    // ==================== findByCountry : regression JSON_CONTAINS (Lot 10) ====================

    public function testFindByCountryFindsTheCurrencyContainingTheCountryCode(): void
    {
        $this->currency('C1', 'Currency-With-Countries', countries: ['FR', 'DE']);

        $found = $this->currencyRepository->findByCountry('FR');

        self::assertNotNull($found);
        self::assertSame('C1', $found->getCode());
    }

    public function testFindByCountryReturnsNullWhenNoCurrencyMatches(): void
    {
        $found = $this->currencyRepository->findByCountry('ZZ');

        self::assertNull($found);
    }

    public function testFindByCountryDoesNotMatchAPartialCode(): void
    {
        // 'FR' ne doit pas matcher un pays 'FRX' - verifie que le LIKE cible bien
        // l'element JSON complet (entre guillemets), pas une sous-chaine.
        $this->currency('C2', 'Currency-Partial-Unique', countries: ['FRX']);

        $found = $this->currencyRepository->findByCountry('FR');

        self::assertNull($found);
    }

    // ==================== findWithStaleExchangeRates ====================

    public function testFindWithStaleExchangeRatesExcludesEur(): void
    {
        $this->currency('EUR', 'Euro', rateUpdatedAt: new \DateTime('-48 hours'));

        $result = $this->currencyRepository->findWithStaleExchangeRates();

        $codes = array_map(fn (Currency $c) => $c->getCode(), $result);
        self::assertNotContains('EUR', $codes);
    }

    public function testFindWithStaleExchangeRatesReturnsOldRatesOnly(): void
    {
        $stale = $this->currency('S1', 'Stale-Currency-Unique', rateUpdatedAt: new \DateTime('-48 hours'));
        $fresh = $this->currency('F2', 'Fresh-Currency-Unique', rateUpdatedAt: new \DateTime('-1 hour'));

        $result = $this->currencyRepository->findWithStaleExchangeRates();

        $codes = array_map(fn (Currency $c) => $c->getCode(), $result);
        self::assertContains($stale->getCode(), $codes);
        self::assertNotContains($fresh->getCode(), $codes);
    }

    // ==================== findMostUsed : regression FIELD() (Lot 10) ====================

    public function testFindMostUsedOrdersByThePriorityListNotAlphabetically(): void
    {
        // Ordre de priorite fixe attendu : EUR, USD, XAF, CAD, GBP - GBP cree en premier
        // pour verifier que l'ordre suit bien la liste, pas l'ordre de creation/alphabetique.
        $this->currency('GBP', 'Livre');
        $this->currency('EUR', 'Euro');
        $this->currency('USD', 'Dollar');

        $result = $this->currencyRepository->findMostUsed(10);

        $codes = array_map(fn (Currency $c) => $c->getCode(), $result);
        self::assertSame(['EUR', 'USD', 'GBP'], $codes);
    }

    public function testFindMostUsedRespectsTheLimit(): void
    {
        $this->currency('EUR', 'Euro');
        $this->currency('USD', 'Dollar');
        $this->currency('XAF', 'Franc CFA');

        $result = $this->currencyRepository->findMostUsed(2);

        self::assertCount(2, $result);
    }

    // ==================== existsAndActive ====================

    public function testExistsAndActiveIsFalseForAnInactiveCurrency(): void
    {
        $this->currency('X1', 'Inactive-Exists-Unique', active: false);

        self::assertFalse($this->currencyRepository->existsAndActive('X1'));
    }

    public function testExistsAndActiveIsTrueForAnActiveCurrency(): void
    {
        $this->currency('X2', 'Active-Exists-Unique', active: true);

        self::assertTrue($this->currencyRepository->existsAndActive('X2'));
    }
}
