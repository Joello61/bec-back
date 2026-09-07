<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use App\Service\CurrencySeeder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Phase 4b, Lot 5 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : script de seed
 * avec donnees statiques - seule l'idempotence (ne jamais dupliquer une devise deja
 * presente) est une vraie regle metier a verrouiller.
 */
class CurrencySeederTest extends TestCase
{
    private CurrencyRepository&\PHPUnit\Framework\MockObject\MockObject $currencyRepository;
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private CurrencySeeder $seeder;

    protected function setUp(): void
    {
        $this->currencyRepository = $this->createMock(CurrencyRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->seeder = new CurrencySeeder($this->em, $this->currencyRepository, new NullLogger());
    }

    public function testSeedPersistsEveryCurrencyOnAnEmptyDatabase(): void
    {
        $this->currencyRepository->method('findByCode')->willReturn(null);
        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });
        $this->em->expects(self::once())->method('flush');

        $this->seeder->seed();

        self::assertNotEmpty($persisted);
        self::assertContainsOnlyInstancesOf(Currency::class, $persisted);
    }

    public function testSeedIsIdempotentWhenCurrenciesAlreadyExist(): void
    {
        $this->currencyRepository->method('findByCode')->willReturn(new Currency());
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $this->seeder->seed();
    }

    public function testClearRemovesEveryExistingCurrency(): void
    {
        $a = new Currency();
        $b = new Currency();
        $this->currencyRepository->method('findAll')->willReturn([$a, $b]);
        $this->em->expects(self::exactly(2))->method('remove');
        $this->em->expects(self::once())->method('flush');

        $this->seeder->clear();
    }

    public function testClearIsANoopOnAnEmptyDatabase(): void
    {
        $this->currencyRepository->method('findAll')->willReturn([]);
        $this->em->expects(self::never())->method('remove');
        $this->em->expects(self::once())->method('flush');

        $this->seeder->clear();
    }
}
