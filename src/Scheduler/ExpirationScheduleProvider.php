<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\ExpireDemandesMessage;
use App\Message\ExpireVoyagesMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('expiration')]
class ExpirationScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            // ==================== EXPIRATION DES VOYAGES ====================
            // Tous les jours à 2h du matin
            ->add(
                RecurringMessage::cron(
                    '0 2 * * *',
                    new ExpireVoyagesMessage()
                )
            )

            // ==================== EXPIRATION DES DEMANDES ====================
            // Tous les jours à 2h30 du matin
            ->add(
                RecurringMessage::cron(
                    '30 2 * * *',
                    new ExpireDemandesMessage()
                )
            );
    }
}
