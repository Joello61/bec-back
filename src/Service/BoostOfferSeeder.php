<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BoostOffer;
use App\Repository\BoostOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Peuple le catalogue des offres de boost (7/15/30 jours). Contrairement à
 * SubscriptionPlanSeeder (Lot 1), aucun Price Stripe à référencer : le paiement
 * one-time utilise price_data inline (cf. StripePaymentProvider::createOneTimeCheckoutSession).
 */
readonly class BoostOfferSeeder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BoostOfferRepository $boostOfferRepository,
        private LoggerInterface $logger,
    ) {}

    public function seed(): void
    {
        $this->logger->info('Début du seeding des offres de boost');

        $count = 0;

        foreach ($this->getOffersData() as $data) {
            $existing = $this->boostOfferRepository->findOneBy(['name' => $data['name']]);

            if ($existing) {
                $this->logger->info('Offre de boost déjà existante, ignorée', ['name' => $data['name']]);
                continue;
            }

            $offer = new BoostOffer();
            $offer->setName($data['name'])
                ->setDurationDays($data['duration_days'])
                ->setPriceAmountEur($data['price_amount_eur'])
                ->setPriceAmountXaf($data['price_amount_xaf'])
                ->setSortOrder($data['sort_order']);

            $this->entityManager->persist($offer);
            $count++;

            $this->logger->info('Offre de boost créée', ['name' => $data['name']]);
        }

        $this->entityManager->flush();

        $this->logger->info('Seeding des offres de boost terminé', ['count' => $count]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getOffersData(): array
    {
        return [
            ['name' => '7 jours', 'duration_days' => 7, 'price_amount_eur' => '2.99', 'price_amount_xaf' => '2000', 'sort_order' => 0],
            ['name' => '15 jours', 'duration_days' => 15, 'price_amount_eur' => '4.99', 'price_amount_xaf' => '3000', 'sort_order' => 1],
            ['name' => '30 jours', 'duration_days' => 30, 'price_amount_eur' => '7.99', 'price_amount_xaf' => '5000', 'sort_order' => 2],
        ];
    }

    public function clear(): void
    {
        $this->logger->warning('Suppression de toutes les offres de boost');

        foreach ($this->boostOfferRepository->findAll() as $offer) {
            $this->entityManager->remove($offer);
        }

        $this->entityManager->flush();

        $this->logger->info('Toutes les offres de boost ont été supprimées');
    }
}
