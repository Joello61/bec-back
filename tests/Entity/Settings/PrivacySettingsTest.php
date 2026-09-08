<?php

declare(strict_types=1);

namespace App\Tests\Entity\Settings;

use App\Entity\Settings\PrivacySettings;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * decoupage de UserSettings en embeddables - canReceiveMessageFrom()/isProfileVisibleFor()
 * portaient deja cette logique avant l'extraction (auparavant testees uniquement via
 * SettingsControllerTest/VisibilityService*Test), jamais isolement sur cette seule classe.
 */
class PrivacySettingsTest extends TestCase
{
    private function verifiedUser(): User
    {
        $user = new User();
        $user->setEmail('verified-' . uniqid() . '@example.test');
        $user->setPassword('irrelevant');
        $user->setEmailVerifie(true);
        $user->setTelephoneVerifie(true);

        return $user;
    }

    private function unverifiedUser(): User
    {
        $user = new User();
        $user->setEmail('unverified-' . uniqid() . '@example.test');
        $user->setPassword('irrelevant');

        return $user;
    }

    // ==================== canReceiveMessageFrom ====================

    public function testCanReceiveMessageFromAllowsEveryoneByDefault(): void
    {
        $privacy = new PrivacySettings();

        self::assertTrue($privacy->canReceiveMessageFrom($this->unverifiedUser()));
    }

    public function testCanReceiveMessageFromRefusesEveryoneWhenSetToNoOne(): void
    {
        $privacy = (new PrivacySettings())->setMessagePermission('no_one');

        self::assertFalse($privacy->canReceiveMessageFrom($this->verifiedUser()));
    }

    public function testCanReceiveMessageFromRequiresVerificationWhenSetToVerifiedOnly(): void
    {
        $privacy = (new PrivacySettings())->setMessagePermission('verified_only');

        self::assertTrue($privacy->canReceiveMessageFrom($this->verifiedUser()));
        self::assertFalse($privacy->canReceiveMessageFrom($this->unverifiedUser()));
    }

    // ==================== isProfileVisibleFor ====================

    public function testIsProfileVisibleForIsTrueForAnyoneWhenPublic(): void
    {
        $privacy = new PrivacySettings();

        self::assertTrue($privacy->isProfileVisibleFor(null));
        self::assertTrue($privacy->isProfileVisibleFor($this->unverifiedUser()));
    }

    public function testIsProfileVisibleForRefusesAnonymousViewerWhenNotPublic(): void
    {
        $privacy = (new PrivacySettings())->setProfileVisibility('verified_only');

        self::assertFalse($privacy->isProfileVisibleFor(null));
    }

    public function testIsProfileVisibleForRequiresVerificationWhenVerifiedOnly(): void
    {
        $privacy = (new PrivacySettings())->setProfileVisibility('verified_only');

        self::assertTrue($privacy->isProfileVisibleFor($this->verifiedUser()));
        self::assertFalse($privacy->isProfileVisibleFor($this->unverifiedUser()));
    }

    public function testIsProfileVisibleForIsAlwaysFalseWhenPrivate(): void
    {
        $privacy = (new PrivacySettings())->setProfileVisibility('private');

        self::assertFalse($privacy->isProfileVisibleFor($this->verifiedUser()));
        self::assertFalse($privacy->isProfileVisibleFor(null));
    }
}
