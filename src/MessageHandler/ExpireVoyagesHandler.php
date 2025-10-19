<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ExpireVoyagesMessage;
use App\Repository\VoyageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExpireVoyagesHandler
{
    public function __construct(
        private VoyageRepository $voyageRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ExpireVoyagesMessage $message): void
    {
        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        $expiredVoyages = $this->voyageRepository->findExpiredVoyages($today);
        $totalCount = count($expiredVoyages);

        if ($totalCount === 0) {
            $this->logger->info('Aucun voyage à expirer');
            return;
        }

        $this->logger->info("Expiration de {$totalCount} voyage(s)");

        $processed = 0;
        $errors = 0;
        $batchSize = $message->batchSize ?? 100;
        $batches = array_chunk($expiredVoyages, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $voyage) {
                try {
                    $voyage->setStatut('expire');
                    $this->entityManager->persist($voyage);
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Erreur expiration voyage', [
                        'voyage_id' => $voyage->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();

            $this->logger->info("Lot {$batchIndex} traité", [
                'batch_size' => count($batch),
                'total_processed' => $processed
            ]);
        }

        $this->logger->info('Expiration voyages terminée', [
            'processed' => $processed,
            'errors' => $errors,
            'total' => $totalCount
        ]);
    }
}
