<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendBoostEndingReminderMessage;
use App\Repository\BoostRepository;
use App\Service\EmailService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Envoie un rappel J-3 avant la fin d'un boost actif (Lot N7) - patron identique a
 * SendRenewalReminderHandler (Lot 3), notification in-app systematique (respecte la
 * preference notifyOnBoostEndingSoon) + email toujours transactionnel.
 */
#[AsMessageHandler]
final readonly class SendBoostEndingReminderHandler
{
    private const REMINDER_DAYS_BEFORE = 3;

    public function __construct(
        private BoostRepository $boostRepository,
        private EmailService $emailService,
        private NotificationService $notificationService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(SendBoostEndingReminderMessage $message): void
    {
        $reminderThreshold = (new \DateTime())->modify(sprintf('+%d days', self::REMINDER_DAYS_BEFORE));

        $boostIds = array_map(
            static fn ($boost) => $boost->getId(),
            $this->boostRepository->findNeedingEndingReminder($reminderThreshold)
        );
        $totalCount = count($boostIds);

        if ($totalCount === 0) {
            $this->logger->info('Aucun rappel de fin de boost à envoyer');
            return;
        }

        $this->logger->info("Envoi de {$totalCount} rappel(s) de fin de boost");

        $processed = 0;
        $errors = 0;
        $batchSize = $message->batchSize ?? 100;
        $batches = array_chunk($boostIds, $batchSize);

        foreach ($batches as $batchIndex => $batchIds) {
            foreach ($batchIds as $boostId) {
                try {
                    $boost = $this->boostRepository->find($boostId);
                    if ($boost === null) {
                        continue;
                    }

                    $user = $boost->getUser();

                    $this->notificationService->createNotification(
                        $user,
                        'boost_ending_soon',
                        'Votre boost arrive à échéance',
                        sprintf(
                            'Votre boost se termine le %s. Renouvelez-le pour garder votre visibilité.',
                            $boost->getEndAt()?->format('d/m/Y') ?? '-'
                        ),
                        ['boostId' => $boost->getId()]
                    );

                    $this->emailService->sendBoostEndingReminderEmail($user, $boost);

                    $boost->setBoostEndingReminderSentAt(new \DateTime());
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Erreur envoi rappel de fin de boost', [
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

        $this->logger->info('Envoi des rappels de fin de boost terminé', [
            'processed' => $processed,
            'errors' => $errors,
            'total' => $totalCount
        ]);
    }
}
