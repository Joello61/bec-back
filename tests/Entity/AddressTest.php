<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Address;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 14 (bec-docs/docs/plan-correction/plan-correction-cobage.md), optionnel :
 * unitaire pur des methodes utilitaires d'Address - canBeModified encode la regle des
 * 6 mois (deja centrale dans AddressService, Lot 6 ; deja verifiee cote requete dans
 * AddressRepositoryTest, Lot 11), isValid/getAddressType discriminent les deux formats
 * (africain via quartier, diaspora via adresseLigne1+codePostal).
 */
class AddressTest extends TestCase
{
    private function address(): Address
    {
        $address = new Address();
        $address->setPays('Cameroun');
        $address->setVille('Douala');

        return $address;
    }

    // ==================== canBeModified : regle des 6 mois ====================

    public function testCanBeModifiedIsTrueWhenNeverModified(): void
    {
        $address = $this->address();

        self::assertTrue($address->canBeModified());
    }

    public function testCanBeModifiedIsFalseWithinSixMonths(): void
    {
        $address = $this->address();
        $address->setLastModifiedAt(new \DateTime('-1 month'));

        self::assertFalse($address->canBeModified());
    }

    public function testCanBeModifiedIsTrueAfterSixMonths(): void
    {
        $address = $this->address();
        $address->setLastModifiedAt(new \DateTime('-7 months'));

        self::assertTrue($address->canBeModified());
    }

    // ==================== getAddressType ====================

    public function testGetAddressTypeReturnsAfricanWhenQuartierIsSet(): void
    {
        $address = $this->address();
        $address->setQuartier('Bonapriso');

        self::assertSame('african', $address->getAddressType());
    }

    public function testGetAddressTypeReturnsPostalWhenLigne1AndCodePostalAreSet(): void
    {
        $address = $this->address();
        $address->setAdresseLigne1('12 rue de la Paix');
        $address->setCodePostal('75000');

        self::assertSame('postal', $address->getAddressType());
    }

    public function testGetAddressTypeReturnsNullWhenNeitherFormatIsComplete(): void
    {
        $address = $this->address();

        self::assertNull($address->getAddressType());
    }

    public function testGetAddressTypePrefersAfricanWhenBothFormatsArePresent(): void
    {
        $address = $this->address();
        $address->setQuartier('Bonapriso');
        $address->setAdresseLigne1('12 rue de la Paix');
        $address->setCodePostal('75000');

        self::assertSame('african', $address->getAddressType());
    }

    // ==================== isValid ====================

    public function testIsValidIsFalseWithoutPaysOrVille(): void
    {
        $address = new Address();
        $address->setQuartier('Bonapriso');

        self::assertFalse($address->isValid());
    }

    public function testIsValidIsTrueForACompleteAfricanFormat(): void
    {
        $address = $this->address();
        $address->setQuartier('Bonapriso');

        self::assertTrue($address->isValid());
    }

    public function testIsValidIsTrueForACompletePostalFormat(): void
    {
        $address = $this->address();
        $address->setAdresseLigne1('12 rue de la Paix');
        $address->setCodePostal('75000');

        self::assertTrue($address->isValid());
    }

    public function testIsValidIsFalseForAnIncompletePostalFormat(): void
    {
        $address = $this->address();
        $address->setAdresseLigne1('12 rue de la Paix');
        // codePostal manquant

        self::assertFalse($address->isValid());
    }

    // ==================== markAsModified ====================

    public function testMarkAsModifiedSetsLastModifiedAtToNow(): void
    {
        $address = $this->address();

        $address->markAsModified();

        self::assertNotNull($address->getLastModifiedAt());
        self::assertFalse($address->canBeModified());
    }
}
