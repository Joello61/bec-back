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

        $expiredDemandeIds = array_map(
            static fn ($demande) => $demande->getId(),
            $this->demandeRepository->findExpiredDemandes($today)
        );
        $totalCount = count($expiredDemandeIds);

        if ($totalCount === 0) {
            $this->logger->info('Aucune demande à expirer');
            return;
        }

        $this->logger->info("Expiration de {$totalCount} demande(s)");

        $processed = 0;
        $errors = 0;
        $batchSize = $message->batchSize ?? 100;
        $batches = array_chunk($expiredDemandeIds, $batchSize);

        foreach ($batches as $batchIndex => $batchIds) {
            foreach ($batchIds as $demandeId) {
                try {
                    // Recharge l'entite depuis l'EntityManager courant : apres le clear()
                    // du lot precedent, une entite issue du findExpiredDemandes() initial
                    // est detachee. La persist() d'une entite detachee (avec un id deja
                    // existant) est traitee par Doctrine comme un nouvel enregistrement,
                    // ce qui echoue via son association client non configuree en
                    // cascade persist - reproduit et confirme par test (Lot 12), meme
                    // bug que ExpireVoyagesHandler.
                    $demande = $this->demandeRepository->find($demandeId);
                    if ($demande === null) {
                        continue;
                    }

                    $demande->setStatut('expiree');
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Erreur expiration demande', [
                        'demande_id' => $demandeId,
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

        $this->logger->info('Expiration demandes terminée', [
            'processed' => $processed,
            'errors' => $errors,
            'total' => $totalCount
        ]);
    }
}
