<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 14 (bec-docs/docs/plan-correction/plan-correction-cobage.md), optionnel :
 * unitaire pur de User::ban/unban - deja exercees indirectement via ModerationServiceTest
 * (Lot 1), jamais testees isolement sur l'entite elle-meme.
 */
class UserTest extends TestCase
{
    private function user(string $prefix): User
    {
        $user = new User();
        $user->setEmail($prefix . '-' . uniqid() . '@example.test');
        $user->setPassword('irrelevant');

        return $user;
    }

    public function testBanSetsAllBanFields(): void
    {
        $user = $this->user('user-ban');
        $admin = $this->user('user-ban-admin');

        $user->ban($admin, 'Comportement inapproprie');

        self::assertTrue($user->isBanned());
        self::assertNotNull($user->getBannedAt());
        self::assertSame('Comportement inapproprie', $user->getBanReason());
        self::assertSame($admin, $user->getBannedBy());
    }

    public function testUnbanClearsAllBanFields(): void
    {
        $user = $this->user('user-unban');
        $admin = $this->user('user-unban-admin');
        $user->ban($admin, 'Raison');

        $user->unban();

        self::assertFalse($user->isBanned());
        self::assertNull($user->getBannedAt());
        self::assertNull($user->getBanReason());
        self::assertNull($user->getBannedBy());
    }

    public function testSetIsBannedFalseAlsoClearsBanMetadata(): void
    {
        $user = $this->user('user-setisbanned');
        $admin = $this->user('user-setisbanned-admin');
        $user->ban($admin, 'Raison');

        $user->setIsBanned(false);

        self::assertFalse($user->isBanned());
        self::assertNull($user->getBannedAt());
        self::assertNull($user->getBanReason());
        self::assertNull($user->getBannedBy());
    }
}
