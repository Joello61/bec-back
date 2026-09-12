<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Boost;
use App\Message\ExpireBoostsMessage;
use App\Repository\BoostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Bascule active->expired pour la coherence du reporting (le tri des listings reste
 * base sur endAt > NOW(), independant de ce sweep quotidien) - monetisation Lot 2.
 */
#[AsMessageHandler]
final readonly class ExpireBoostsHandler
{
    public function __construct(
        private BoostRepository $boostRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ExpireBoostsMessage $message): void
    {
        $now = new \DateTime();

        $expiredBoostIds = array_map(
            static fn ($boost) => $boost->getId(),
            $this->boostRepository->findExpiredActiveBoosts($now)
        );
        $totalCount = count($expiredBoostIds);

        if ($totalCount === 0) {
            $this->logger->info('Aucun boost à expirer');
            return;
        }

        $this->logger->info("Expiration de {$totalCount} boost(s)");

        $processed = 0;
        $errors = 0;
        $batchSize = $message->batchSize ?? 100;
        $batches = array_chunk($expiredBoostIds, $batchSize);

        foreach ($batches as $batchIndex => $batchIds) {
            foreach ($batchIds as $boostId) {
                try {
                    // Recharge depuis l'EntityManager courant : apres le clear() du lot
                    // precedent, une entite issue de findExpiredActiveBoosts() initial est
                    // detachee (meme piege que ExpireVoyagesHandler, Phase 12).
                    $boost = $this->boostRepository->find($boostId);
                    if ($boost === null) {
                        continue;
                    }

                    $boost->setStatus(Boost::STATUS_EXPIRED);
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Erreur expiration boost', [
                        'boost_id' => $boostId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();

            $this->logger->info("Lot {$batchIndex} traité", [
                'batch_size' => count($batchIds),
                'total_processed' => $processed
            ]);
        }

        $this->logger->info('Expiration boosts terminée', [
            'processed' => $processed,
            'errors' => $errors,
            'total' => $totalCount
        ]);
    }
}
