<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ExpireDemandesMessage;
use App\Repository\DemandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExpireDemandesHandler
{
    public function __construct(
        private DemandeRepository $demandeRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ExpireDemandesMessage $message): void
    {
        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        $expiredDemandes = $this->demandeRepository->findExpiredDemandes($today);
        $totalCount = count($expiredDemandes);

        if ($totalCount === 0) {
            $this->logger->info('Aucune demande à expirer');
            return;
        }

        $this->logger->info("Expiration de {$totalCount} demande(s)");

        $processed = 0;
        $errors = 0;
        $batchSize = $message->batchSize ?? 100;
        $batches = array_chunk($expiredDemandes, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $demande) {
                try {
                    $demande->setStatut('expiree');
                    $this->entityManager->persist($demande);
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Erreur expiration demande', [
                        'demande_id' => $demande->getId(),
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

        $this->logger->info('Expiration demandes terminée', [
            'processed' => $processed,
            'errors' => $errors,
            'total' => $totalCount
        ]);
    }
}
