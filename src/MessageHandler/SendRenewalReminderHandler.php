<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendRenewalReminderMessage;
use App\Repository\UserSubscriptionRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Envoie un rappel J-3 avant l'échéance des abonnements Mobile Money (Notch Pay n'a pas
 * de récurrence native, Lot 3) - pattern ExpireVoyagesHandler (rechargement par id
 * après clear(), Phase 12).
 */
#[AsMessageHandler]
final readonly class SendRenewalReminderHandler
{
    private const REMINDER_DAYS_BEFORE = 3;

    public function __construct(
        private UserSubscriptionRepository $userSubscriptionRepository,
        private EmailService $emailService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(SendRenewalReminderMessage $message): void
    {
        $reminderThreshold = (new \DateTime())->modify(sprintf('+%d days', self::REMINDER_DAYS_BEFORE));

        $subscriptionIds = array_map(
            static fn ($subscription) => $subscription->getId(),
            $this->userSubscriptionRepository->findNeedingRenewalReminder($reminderThreshold)
        );
        $totalCount = count($subscriptionIds);

        if ($totalCount === 0) {
            $this->logger->info('Aucun rappel de renouvellement à envoyer');
            return;
        }

        $this->logger->info("Envoi de {$totalCount} rappel(s) de renouvellement");

        $processed = 0;
        $errors = 0;
        $batchSize = $message->batchSize ?? 100;
        $batches = array_chunk($subscriptionIds, $batchSize);

        foreach ($batches as $batchIndex => $batchIds) {
            foreach ($batchIds as $subscriptionId) {
                try {
                    $subscription = $this->userSubscriptionRepository->find($subscriptionId);
                    if ($subscription === null) {
                        continue;
                    }

                    $this->emailService->sendRenewalReminderEmail($subscription->getUser(), $subscription);
                    $subscription->setRenewalReminderSentAt(new \DateTime());
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Erreur envoi rappel de renouvellement', [
                        'subscription_id' => $subscriptionId,
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

        $this->logger->info('Envoi des rappels de renouvellement terminé', [
            'processed' => $processed,
            'errors' => $errors,
            'total' => $totalCount
        ]);
    }
}
