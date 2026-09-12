<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\DTO\Admin\CreateBoostOfferDTO;
use App\DTO\Admin\CreateSubscriptionPlanDTO;
use App\DTO\Admin\UpdateBoostOfferDTO;
use App\DTO\Admin\UpdateSubscriptionPlanDTO;
use App\Entity\BoostOffer;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Repository\BoostOfferRepository;
use App\Repository\SubscriptionPlanRepository;
use App\Service\Admin\AuditLogService;
use App\Service\Admin\CatalogAdminService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Lot 5 (monétisation) : CRUD admin du catalogue - jamais de suppression physique
 * (SubscriptionPlan/BoostOffer référencés par des UserSubscription/Boost historiques),
 * garde spécifique sur le plan gratuit dont dépend le quota freemium par défaut.
 */
class CatalogAdminServiceTest extends TestCase
{
    private SubscriptionPlanRepository&\PHPUnit\Framework\MockObject\MockObject $planRepository;
    private BoostOfferRepository&\PHPUnit\Framework\MockObject\MockObject $offerRepository;
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private AuditLogService&\PHPUnit\Framework\MockObject\MockObject $auditLogService;
    private CatalogAdminService $service;

    protected function setUp(): void
    {
        $this->planRepository = $this->createMock(SubscriptionPlanRepository::class);
        $this->offerRepository = $this->createMock(BoostOfferRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);

        $this->service = new CatalogAdminService(
            $this->em,
            $this->planRepository,
            $this->offerRepository,
            $this->auditLogService,
        );
    }

    /**
     * Simule l'auto-increment assigné par un vrai EntityManager après flush() - avec un EM
     * mocké, persist()/flush() ne peuplent jamais l'id, alors que
     * CatalogAdminService::create*() lit $entity->getId() juste après pour l'audit log.
     */
    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function createPlanDto(string $code = 'plus'): CreateSubscriptionPlanDTO
    {
        $dto = new CreateSubscriptionPlanDTO();
        $dto->code = $code;
        $dto->name = 'Plus';
        $dto->priceAmountEur = '4.99';
        $dto->priceAmountXaf = '3000';

        return $dto;
    }

    private function updatePlanDto(): UpdateSubscriptionPlanDTO
    {
        $dto = new UpdateSubscriptionPlanDTO();
        $dto->name = 'Plus (renommé)';
        $dto->priceAmountEur = '5.99';

        return $dto;
    }

    // ==================== SubscriptionPlan ====================

    public function testCreateSubscriptionPlanRejectsADuplicateCode(): void
    {
        $this->planRepository->method('findByCode')->with('plus')->willReturn(new SubscriptionPlan());

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createSubscriptionPlan($this->createPlanDto(), new User());
    }

    public function testCreateSubscriptionPlanPersistsAndLogsTheAction(): void
    {
        $this->planRepository->method('findByCode')->willReturn(null);
        $this->em->expects(self::once())->method('persist')->with(self::callback(function (SubscriptionPlan $plan) {
            $this->setId($plan, 42);
            return true;
        }));
        $this->em->expects(self::once())->method('flush');
        $this->auditLogService->expects(self::once())
            ->method('logAdminAction')
            ->with(self::anything(), 'create_subscription_plan', 'subscription_plan', self::anything(), self::anything());

        $plan = $this->service->createSubscriptionPlan($this->createPlanDto(), new User());

        self::assertSame('plus', $plan->getCode());
        self::assertSame('4.99', $plan->getPriceAmountEur());
    }

    public function testUpdateSubscriptionPlanThrowsWhenPlanIsMissing(): void
    {
        $this->planRepository->method('find')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->updateSubscriptionPlan(999, $this->updatePlanDto(), new User());
    }

    public function testUpdateSubscriptionPlanAppliesChanges(): void
    {
        $plan = (new SubscriptionPlan())->setCode('plus')->setName('Plus')->setPriceAmountEur('4.99');
        $this->setId($plan, 1);
        $this->planRepository->method('find')->with(1)->willReturn($plan);
        $this->em->expects(self::once())->method('flush');

        $updated = $this->service->updateSubscriptionPlan(1, $this->updatePlanDto(), new User());

        self::assertSame('Plus (renommé)', $updated->getName());
        self::assertSame('5.99', $updated->getPriceAmountEur());
        self::assertSame('plus', $updated->getCode(), 'le code ne doit jamais changer via UpdateSubscriptionPlanDTO');
    }

    public function testDeleteSubscriptionPlanRefusesTheFreePlan(): void
    {
        $plan = (new SubscriptionPlan())->setCode('free')->setName('Free');
        $this->planRepository->method('find')->willReturn($plan);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->deleteSubscriptionPlan(1, new User());
    }

    public function testDeleteSubscriptionPlanSoftDeletesANonFreePlan(): void
    {
        $plan = (new SubscriptionPlan())->setCode('plus')->setName('Plus');
        $this->setId($plan, 1);
        $this->planRepository->method('find')->willReturn($plan);
        $this->em->expects(self::once())->method('flush');

        $this->service->deleteSubscriptionPlan(1, new User());

        self::assertNotNull($plan->getDeletedAt());
        self::assertFalse($plan->isActive());
    }

    // ==================== BoostOffer ====================

    public function testCreateBoostOfferPersistsAndLogsTheAction(): void
    {
        $dto = new CreateBoostOfferDTO();
        $dto->name = '7 jours';
        $dto->durationDays = 7;
        $dto->priceAmountEur = '2.99';

        $this->em->expects(self::once())->method('persist')->with(self::callback(function (BoostOffer $offer) {
            $this->setId($offer, 42);
            return true;
        }));
        $this->em->expects(self::once())->method('flush');
        $this->auditLogService->expects(self::once())
            ->method('logAdminAction')
            ->with(self::anything(), 'create_boost_offer', 'boost_offer', self::anything(), self::anything());

        $offer = $this->service->createBoostOffer($dto, new User());

        self::assertSame('7 jours', $offer->getName());
        self::assertSame(7, $offer->getDurationDays());
    }

    public function testUpdateBoostOfferThrowsWhenOfferIsMissing(): void
    {
        $this->offerRepository->method('find')->willReturn(null);

        $dto = new UpdateBoostOfferDTO();
        $dto->name = '15 jours';
        $dto->durationDays = 15;
        $dto->priceAmountEur = '4.99';

        $this->expectException(\InvalidArgumentException::class);

        $this->service->updateBoostOffer(999, $dto, new User());
    }

    public function testDeleteBoostOfferSoftDeletes(): void
    {
        $offer = (new BoostOffer())->setName('7 jours')->setDurationDays(7)->setPriceAmountEur('2.99');
        $this->setId($offer, 1);
        $this->offerRepository->method('find')->willReturn($offer);
        $this->em->expects(self::once())->method('flush');

        $this->service->deleteBoostOffer(1, new User());

        self::assertNotNull($offer->getDeletedAt());
        self::assertFalse($offer->isActive());
    }
}
