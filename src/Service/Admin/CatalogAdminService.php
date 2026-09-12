<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\DTO\Admin\CreateBoostOfferDTO;
use App\DTO\Admin\CreateSubscriptionPlanDTO;
use App\DTO\Admin\UpdateBoostOfferDTO;
use App\DTO\Admin\UpdateSubscriptionPlanDTO;
use App\Entity\BoostOffer;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Repository\BoostOfferRepository;
use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CRUD admin du catalogue (abonnements, boosts) - Lot 5 de la monétisation. Jamais de
 * suppression physique : SubscriptionPlan/BoostOffer sont référencés par des
 * UserSubscription/Boost historiques (cf. ../../CLAUDE.md §8), la "suppression" est un
 * soft-delete (deletedAt + isActive=false).
 */
readonly class CatalogAdminService
{
    private const FREE_PLAN_CODE = 'free';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubscriptionPlanRepository $subscriptionPlanRepository,
        private BoostOfferRepository $boostOfferRepository,
        private AuditLogService $auditLogService,
    ) {}

    public function createSubscriptionPlan(CreateSubscriptionPlanDTO $dto, User $admin): SubscriptionPlan
    {
        if ($this->subscriptionPlanRepository->findByCode($dto->code) !== null) {
            throw new \InvalidArgumentException(sprintf('Un plan avec le code "%s" existe déjà', $dto->code));
        }

        $plan = new SubscriptionPlan();
        $plan->setCode($dto->code)
            ->setName($dto->name)
            ->setPriceAmountEur($dto->priceAmountEur)
            ->setPriceAmountXaf($dto->priceAmountXaf)
            ->setPriceAmountEurYearly($dto->priceAmountEurYearly)
            ->setPriceAmountXafYearly($dto->priceAmountXafYearly)
            ->setBillingPeriod($dto->billingPeriod)
            ->setMaxActiveVoyages($dto->maxActiveVoyages)
            ->setMaxActiveDemandes($dto->maxActiveDemandes)
            ->setHasBadge($dto->hasBadge)
            ->setHasViewStats($dto->hasViewStats)
            ->setIsFeatured($dto->isFeatured)
            ->setIsActive($dto->isActive)
            ->setSortOrder($dto->sortOrder)
            ->setStripePriceId($dto->stripePriceId)
            ->setStripePriceIdYearly($dto->stripePriceIdYearly);

        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        $this->auditLogService->logAdminAction($admin, 'create_subscription_plan', 'subscription_plan', $plan->getId(), [
            'code' => $plan->getCode(),
            'name' => $plan->getName(),
        ]);

        return $plan;
    }

    public function updateSubscriptionPlan(int $id, UpdateSubscriptionPlanDTO $dto, User $admin): SubscriptionPlan
    {
        $plan = $this->subscriptionPlanRepository->find($id);

        if ($plan === null) {
            throw new \InvalidArgumentException('Plan introuvable');
        }

        $plan->setName($dto->name)
            ->setPriceAmountEur($dto->priceAmountEur)
            ->setPriceAmountXaf($dto->priceAmountXaf)
            ->setPriceAmountEurYearly($dto->priceAmountEurYearly)
            ->setPriceAmountXafYearly($dto->priceAmountXafYearly)
            ->setBillingPeriod($dto->billingPeriod)
            ->setMaxActiveVoyages($dto->maxActiveVoyages)
            ->setMaxActiveDemandes($dto->maxActiveDemandes)
            ->setHasBadge($dto->hasBadge)
            ->setHasViewStats($dto->hasViewStats)
            ->setIsFeatured($dto->isFeatured)
            ->setIsActive($dto->isActive)
            ->setSortOrder($dto->sortOrder)
            ->setStripePriceId($dto->stripePriceId)
            ->setStripePriceIdYearly($dto->stripePriceIdYearly);

        $this->entityManager->flush();

        $this->auditLogService->logAdminAction($admin, 'update_subscription_plan', 'subscription_plan', $plan->getId(), [
            'code' => $plan->getCode(),
        ]);

        return $plan;
    }

    public function deleteSubscriptionPlan(int $id, User $admin): void
    {
        $plan = $this->subscriptionPlanRepository->find($id);

        if ($plan === null) {
            throw new \InvalidArgumentException('Plan introuvable');
        }

        if ($plan->getCode() === self::FREE_PLAN_CODE) {
            throw new \InvalidArgumentException('Le plan gratuit ne peut pas être supprimé - il sert de palier par défaut au quota freemium');
        }

        $plan->setDeletedAt(new \DateTime())->setIsActive(false);
        $this->entityManager->flush();

        $this->auditLogService->logAdminAction($admin, 'delete_subscription_plan', 'subscription_plan', $plan->getId(), [
            'code' => $plan->getCode(),
        ]);
    }

    public function createBoostOffer(CreateBoostOfferDTO $dto, User $admin): BoostOffer
    {
        $offer = new BoostOffer();
        $offer->setName($dto->name)
            ->setDurationDays($dto->durationDays)
            ->setPriceAmountEur($dto->priceAmountEur)
            ->setPriceAmountXaf($dto->priceAmountXaf)
            ->setIsFeatured($dto->isFeatured)
            ->setIsActive($dto->isActive)
            ->setSortOrder($dto->sortOrder);

        $this->entityManager->persist($offer);
        $this->entityManager->flush();

        $this->auditLogService->logAdminAction($admin, 'create_boost_offer', 'boost_offer', $offer->getId(), [
            'name' => $offer->getName(),
        ]);

        return $offer;
    }

    public function updateBoostOffer(int $id, UpdateBoostOfferDTO $dto, User $admin): BoostOffer
    {
        $offer = $this->boostOfferRepository->find($id);

        if ($offer === null) {
            throw new \InvalidArgumentException('Offre de boost introuvable');
        }

        $offer->setName($dto->name)
            ->setDurationDays($dto->durationDays)
            ->setPriceAmountEur($dto->priceAmountEur)
            ->setPriceAmountXaf($dto->priceAmountXaf)
            ->setIsFeatured($dto->isFeatured)
            ->setIsActive($dto->isActive)
            ->setSortOrder($dto->sortOrder);

        $this->entityManager->flush();

        $this->auditLogService->logAdminAction($admin, 'update_boost_offer', 'boost_offer', $offer->getId(), [
            'name' => $offer->getName(),
        ]);

        return $offer;
    }

    public function deleteBoostOffer(int $id, User $admin): void
    {
        $offer = $this->boostOfferRepository->find($id);

        if ($offer === null) {
            throw new \InvalidArgumentException('Offre de boost introuvable');
        }

        $offer->setDeletedAt(new \DateTime())->setIsActive(false);
        $this->entityManager->flush();

        $this->auditLogService->logAdminAction($admin, 'delete_boost_offer', 'boost_offer', $offer->getId(), [
            'name' => $offer->getName(),
        ]);
    }
}
