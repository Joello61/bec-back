<?php

declare(strict_types=1);

namespace App\Tests\Entity\Settings;

use App\Entity\Settings\RgpdConsent;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * decoupage de UserSettings en embeddables - setCookiesConsent() porte un effet de bord
 * (horodatage automatique du premier consentement) jamais teste isolement auparavant.
 */
class RgpdConsentTest extends TestCase
{
    public function testSetCookiesConsentStampsConsentDateOnFirstAcceptance(): void
    {
        $consent = new RgpdConsent();
        self::assertNull($consent->getConsentDate());

        $consent->setCookiesConsent(true);

        self::assertTrue($consent->isCookiesConsent());
        self::assertNotNull($consent->getConsentDate());
    }

    public function testSetCookiesConsentDoesNotOverwriteAnExistingConsentDate(): void
    {
        $consent = new RgpdConsent();
        $consent->setCookiesConsent(true);
        $firstConsentDate = $consent->getConsentDate();

        $consent->setCookiesConsent(false);
        $consent->setCookiesConsent(true);

        self::assertSame($firstConsentDate, $consent->getConsentDate());
    }

    public function testSetCookiesConsentToFalseDoesNotSetAConsentDate(): void
    {
        $consent = new RgpdConsent();

        $consent->setCookiesConsent(false);

        self::assertNull($consent->getConsentDate());
    }
}
