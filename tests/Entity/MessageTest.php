<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Message;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 14 (bec-docs/docs/plan-correction/plan-correction-cobage.md), optionnel :
 * unitaire pur de Message::setLu - fixe luAt a la premiere lecture, ne l'ecrase pas
 * a chaque appel ulterieur.
 */
class MessageTest extends TestCase
{
    public function testSetLuTrueSetsLuAtWhenNotAlreadySet(): void
    {
        $message = new Message();

        $message->setLu(true);

        self::assertTrue($message->isLu());
        self::assertNotNull($message->getLuAt());
    }

    public function testSetLuTrueDoesNotOverwriteAnExistingLuAt(): void
    {
        $message = new Message();
        $firstLuAt = new \DateTime('-1 day');
        $message->setLuAt($firstLuAt);

        $message->setLu(true);

        self::assertSame($firstLuAt, $message->getLuAt());
    }

    public function testSetLuFalseDoesNotSetLuAt(): void
    {
        $message = new Message();

        $message->setLu(false);

        self::assertFalse($message->isLu());
        self::assertNull($message->getLuAt());
    }
}
