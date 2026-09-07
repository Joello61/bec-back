<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Currency;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 14 (bec-docs/docs/plan-correction/plan-correction-cobage.md), optionnel :
 * unitaire pur des methodes utilitaires de Currency - isExchangeRateFresh (seuil 24h,
 * deja centrale dans CurrencyService, Lot 5), formatAmount (0 vs N decimales),
 * isUsedInCountry.
 */
class CurrencyTest extends TestCase
{
    private function currency(int $decimals = 2, string $symbol = '€'): Currency
    {
        $currency = new Currency();
        $currency->setCode('EUR');
        $currency->setName('Euro');
        $currency->setSymbol($symbol);
        $currency->setDecimals($decimals);
        $currency->setExchangeRate('1');
        $currency->setCountries(['FR', 'DE']);

        return $currency;
    }

    // ==================== isExchangeRateFresh : seuil 24h ====================

    public function testIsExchangeRateFreshIsFalseWhenNeverUpdated(): void
    {
        $currency = $this->currency();

        self::assertFalse($currency->isExchangeRateFresh());
    }

    public function testIsExchangeRateFreshIsFalseWhenOlderThanTwentyFourHours(): void
    {
        $currency = $this->currency();
        $currency->setRateUpdatedAt(new \DateTime('-25 hours'));

        self::assertFalse($currency->isExchangeRateFresh());
    }

    public function testIsExchangeRateFreshIsTrueWithinTwentyFourHours(): void
    {
        $currency = $this->currency();
        $currency->setRateUpdatedAt(new \DateTime('-1 hour'));

        self::assertTrue($currency->isExchangeRateFresh());
    }

    // ==================== formatAmount ====================

    public function testFormatAmountUsesZeroDecimalsWhenConfigured(): void
    {
        $currency = $this->currency(decimals: 0, symbol: 'FCFA');

        self::assertSame('1 235 FCFA', $currency->formatAmount(1234.56));
    }

    public function testFormatAmountUsesConfiguredDecimalsAndFrenchSeparators(): void
    {
        $currency = $this->currency(decimals: 2, symbol: '€');

        self::assertSame('1 234,56 €', $currency->formatAmount(1234.56));
    }

    // ==================== isUsedInCountry ====================

    public function testIsUsedInCountryIsCaseInsensitive(): void
    {
        $currency = $this->currency();

        self::assertTrue($currency->isUsedInCountry('fr'));
    }

    public function testIsUsedInCountryIsFalseForAnUnlistedCountry(): void
    {
        $currency = $this->currency();

        self::assertFalse($currency->isUsedInCountry('US'));
    }
}
