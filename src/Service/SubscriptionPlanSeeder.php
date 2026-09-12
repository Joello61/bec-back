<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SubscriptionPlan;
use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Peuple le catalogue d'abonnements (Free/Plus/Pro). Les stripePriceId de Plus/Pro sont
 * lus depuis STRIPE_PRICE_ID_PLUS/STRIPE_PRICE_ID_PRO (stopgap tant que le CRUD admin
 * du catalogue, Lot 5, n'existe pas) - le checkout échoue proprement si absent.
 */
readonly class SubscriptionPlanSeeder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubscriptionPlanRepository $subscriptionPlanRepository,
        private LoggerInterface $logger,
        private string $stripePriceIdPlus = '',
        private string $stripePriceIdPro = '',
    ) {}

    public function seed(): void
    {
        $this->logger->info('Début du seeding des plans d\'abonnement');

        $count = 0;

        foreach ($this->getPlansData() as $data) {
            $existing = $this->subscriptionPlanRepository->findByCode($data['code']);

            if ($existing) {
                $this->logger->info('Plan déjà existant, ignoré', ['code' => $data['code']]);
                continue;
            }

            $plan = new SubscriptionPlan();
            $plan->setCode($data['code'])
                ->setName($data['name'])
                ->setPriceAmountEur($data['price_amount_eur'])
                ->setBillingPeriod('monthly')
                ->setMaxActiveVoyages($data['max_active_voyages'])
                ->setMaxActiveDemandes($data['max_active_demandes'])
                ->setHasBadge($data['has_badge'])
                ->setStripePriceId($data['stripe_price_id'])
                ->setSortOrder($data['sort_order']);

            $this->entityManager->persist($plan);
            $count++;

            $this->logger->info('Plan créé', ['code' => $data['code']]);
        }

        $this->entityManager->flush();

        $this->logger->info('Seeding des plans d\'abonnement terminé', ['count' => $count]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getPlansData(): array
    {
        return [
            [
                'code' => 'free',
                'name' => 'Free',
                'price_amount_eur' => null,
                'max_active_voyages' => 3,
                'max_active_demandes' => 3,
                'has_badge' => false,
                'stripe_price_id' => null,
                'sort_order' => 0,
            ],
            [
                'code' => 'plus',
                'name' => 'Plus',
                'price_amount_eur' => '4.99',
                'max_active_voyages' => null,
                'max_active_demandes' => null,
                'has_badge' => true,
                'stripe_price_id' => $this->stripePriceIdPlus ?: null,
                'sort_order' => 1,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'price_amount_eur' => '9.99',
                'max_active_voyages' => null,
                'max_active_demandes' => null,
                'has_badge' => true,
                'stripe_price_id' => $this->stripePriceIdPro ?: null,
                'sort_order' => 2,
            ],
        ];
    }

    public function clear(): void
    {
        $this->logger->warning('Suppression de tous les plans d\'abonnement');

        foreach ($this->subscriptionPlanRepository->findAll() as $plan) {
            $this->entityManager->remove($plan);
        }

        $this->entityManager->flush();

        $this->logger->info('Tous les plans d\'abonnement ont été supprimés');
    }
}
