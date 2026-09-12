<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Message\ExpireBansMessage;
use App\Message\ExpireBoostsMessage;
use App\Message\ExpireDemandesMessage;
use App\Message\ExpireVoyagesMessage;
use App\Scheduler\ExpirationScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Phase 4b, Lot 12 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : verifie que
 * les cron d'expiration sont bien enregistres, avec la bonne expression et le bon
 * message associe - une erreur ici (mauvaise expression, message manquant) ne serait
 * jamais detectee autrement qu'en observant l'absence d'expiration en production.
 */
class ExpirationScheduleProviderTest extends TestCase
{
    private function messagesFor(RecurringMessage $recurringMessage): array
    {
        $context = new MessageContext(
            'expiration',
            $recurringMessage->getId(),
            $recurringMessage->getTrigger(),
            new \DateTimeImmutable()
        );

        return iterator_to_array($recurringMessage->getMessages($context));
    }

    public function testScheduleRegistersExactlyFourRecurringMessages(): void
    {
        $schedule = (new ExpirationScheduleProvider())->getSchedule();

        self::assertCount(4, $schedule->getRecurringMessages());
    }

    public function testVoyagesExpirationRunsDailyAtTwoAm(): void
    {
        $schedule = (new ExpirationScheduleProvider())->getSchedule();
        $recurringMessages = $schedule->getRecurringMessages();

        $voyageEntry = current(array_filter(
            $recurringMessages,
            fn (RecurringMessage $rm) => $this->messagesFor($rm)[0] instanceof ExpireVoyagesMessage
        ));

        self::assertNotFalse($voyageEntry, 'aucun RecurringMessage ne porte ExpireVoyagesMessage');
        self::assertStringContainsString('0 2 * * *', (string) $voyageEntry->getTrigger());
    }

    public function testDemandesExpirationRunsDailyAtTwoThirtyAm(): void
    {
        $schedule = (new ExpirationScheduleProvider())->getSchedule();
        $recurringMessages = $schedule->getRecurringMessages();

        $demandeEntry = current(array_filter(
            $recurringMessages,
            fn (RecurringMessage $rm) => $this->messagesFor($rm)[0] instanceof ExpireDemandesMessage
        ));

        self::assertNotFalse($demandeEntry, 'aucun RecurringMessage ne porte ExpireDemandesMessage');
        self::assertStringContainsString('30 2 * * *', (string) $demandeEntry->getTrigger());
    }

    public function testBansExpirationRunsHourly(): void
    {
        $schedule = (new ExpirationScheduleProvider())->getSchedule();
        $recurringMessages = $schedule->getRecurringMessages();

        $banEntry = current(array_filter(
            $recurringMessages,
            fn (RecurringMessage $rm) => $this->messagesFor($rm)[0] instanceof ExpireBansMessage
        ));

        self::assertNotFalse($banEntry, 'aucun RecurringMessage ne porte ExpireBansMessage');
        self::assertStringContainsString('every 1 hour', (string) $banEntry->getTrigger());
    }

    public function testBoostsExpirationRunsDailyAtThreeAm(): void
    {
        $schedule = (new ExpirationScheduleProvider())->getSchedule();
        $recurringMessages = $schedule->getRecurringMessages();

        $boostEntry = current(array_filter(
            $recurringMessages,
            fn (RecurringMessage $rm) => $this->messagesFor($rm)[0] instanceof ExpireBoostsMessage
        ));

        self::assertNotFalse($boostEntry, 'aucun RecurringMessage ne porte ExpireBoostsMessage');
        self::assertStringContainsString('0 3 * * *', (string) $boostEntry->getTrigger());
    }
}
