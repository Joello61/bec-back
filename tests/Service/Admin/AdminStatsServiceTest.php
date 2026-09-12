<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Repository\AvisRepository;
use App\Repository\ConversationRepository;
use App\Repository\DemandeRepository;
use App\Repository\MessageRepository;
use App\Repository\SignalementRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;
use App\Service\Admin\AdminStatsService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 7 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : calculateTrend()
 * et getFrenchDayName() sont les seules methodes testables en unitaire pur (le reste
 * utilise createQueryBuilder directement sur les repositories, non mockable proprement -
 * couvert par AdminStatsServiceIntegrationTest, DB reelle). Toutes deux privees, invoquees
 * par reflexion : logique pure, sans effet de bord, aucune raison de passer par
 * getActivityStats() (qui declenche des requetes DB) juste pour les exercer.
 */
class AdminStatsServiceTest extends TestCase
{
    private AdminStatsService $service;

    protected function setUp(): void
    {
        $this->service = new AdminStatsService(
            $this->createMock(UserRepository::class),
            $this->createMock(VoyageRepository::class),
            $this->createMock(DemandeRepository::class),
            $this->createMock(SignalementRepository::class),
            $this->createMock(AvisRepository::class),
            $this->createMock(MessageRepository::class),
            $this->createMock(ConversationRepository::class),
            $this->createMock(TransactionRepository::class),
        );
    }

    private function calculateTrend(array $data): array
    {
        $method = new \ReflectionMethod(AdminStatsService::class, 'calculateTrend');

        return $method->invoke($this->service, $data);
    }

    private function days(array $inscriptionsPerDay): array
    {
        return array_map(fn (int $count) => ['inscriptions' => $count], $inscriptionsPerDay);
    }

    public function testCalculateTrendDetectsAnIncrease(): void
    {
        // premiere moitie (jours 0-2) = 10, seconde moitie (jours 4-6) = 20 -> +100%
        $trend = $this->calculateTrend($this->days([2, 3, 5, 0, 7, 6, 7]));

        self::assertSame('hausse', $trend['direction']);
    }

    public function testCalculateTrendDetectsADecrease(): void
    {
        $trend = $this->calculateTrend($this->days([10, 10, 10, 0, 1, 1, 1]));

        self::assertSame('baisse', $trend['direction']);
    }

    public function testCalculateTrendIsStableWithinTenPercent(): void
    {
        // 30 -> 32 = +6.7%, sous le seuil de 10%
        $trend = $this->calculateTrend($this->days([10, 10, 10, 0, 10, 11, 11]));

        self::assertSame('stable', $trend['direction']);
    }

    public function testCalculateTrendReportsAHundredPercentIncreaseFromZero(): void
    {
        $trend = $this->calculateTrend($this->days([0, 0, 0, 0, 3, 2, 1]));

        self::assertSame('hausse', $trend['direction']);
        self::assertSame(100, $trend['percentage']);
    }

    public function testCalculateTrendIsStableWhenBothHalvesAreZero(): void
    {
        $trend = $this->calculateTrend($this->days([0, 0, 0, 0, 0, 0, 0]));

        self::assertSame('stable', $trend['direction']);
        self::assertSame(0, $trend['percentage']);
    }

    public function testCalculateTrendPercentageIsAlwaysPositive(): void
    {
        $trend = $this->calculateTrend($this->days([10, 10, 10, 0, 1, 1, 1]));

        self::assertGreaterThanOrEqual(0, $trend['percentage'], 'la baisse doit etre exprimee en valeur absolue');
    }

    public function testGetFrenchDayNameMapsKnownDayNumbers(): void
    {
        $method = new \ReflectionMethod(AdminStatsService::class, 'getFrenchDayName');

        self::assertSame('Dimanche', $method->invoke($this->service, '0'));
        self::assertSame('Samedi', $method->invoke($this->service, '6'));
    }

    public function testGetFrenchDayNameFallsBackForAnUnknownNumber(): void
    {
        $method = new \ReflectionMethod(AdminStatsService::class, 'getFrenchDayName');

        self::assertSame('Inconnu', $method->invoke($this->service, '9'));
    }
}
