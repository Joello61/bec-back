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

        $expiredVoyageIds = array_map(
            static fn ($voyage) => $voyage->getId(),
            $this->voyageRepository->findExpiredVoyages($today)
        );
        $totalCount = count($expiredVoyageIds);

        if ($totalCount === 0) {
            $this->logger->info('Aucun voyage à expirer');
            return;
        }

        $this->logger->info("Expiration de {$totalCount} voyage(s)");

        $processed = 0;
        $errors = 0;
        $batchSize = $message->batchSize ?? 100;
        $batches = array_chunk($expiredVoyageIds, $batchSize);

        foreach ($batches as $batchIndex => $batchIds) {
            foreach ($batchIds as $voyageId) {
                try {
                    // Recharge l'entite depuis l'EntityManager courant : apres le clear()
                    // du lot precedent, une entite issue du findExpiredVoyages() initial
                    // est detachee. La persist() d'une entite detachee (avec un id deja
                    // existant) est traitee par Doctrine comme un nouvel enregistrement,
                    // ce qui echoue via son association voyageur non configuree en
                    // cascade persist - reproduit et confirme par test (Lot 12).
                    $voyage = $this->voyageRepository->find($voyageId);
                    if ($voyage === null) {
                        continue;
                    }

                    $voyage->setStatut('expire');
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Erreur expiration voyage', [
                        'voyage_id' => $voyageId,
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

        $this->logger->info('Expiration voyages terminée', [
            'processed' => $processed,
            'errors' => $errors,
            'total' => $totalCount
        ]);
    }
}
