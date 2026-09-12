<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\UserSubscription;
use App\Message\ExpireNotchPaySubscriptionsMessage;
use App\Repository\UserSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Bascule active->expired les abonnements Mobile Money dont le delai de grace (periode
 * + N jours) est depasse sans nouveau paiement recu - Notch Pay n'a pas de recurrence
 * native (Lot 3), rien d'equivalent aux evenements Stripe invoice.* pour detecter un
 * non-renouvellement autrement qu'en surveillant nous-memes currentPeriodEnd.
 */
#[AsMessageHandler]
final readonly class ExpireNotchPaySubscriptionsHandler
{
    private const GRACE_PERIOD_DAYS = 3;

    public function __construct(
        private UserSubscriptionRepository $userSubscriptionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ExpireNotchPaySubscriptionsMessage $message): void
    {
        $cutoff = (new \DateTime())->modify(sprintf('-%d days', self::GRACE_PERIOD_DAYS));

        $subscriptionIds = array_map(
            static fn ($subscription) => $subscription->getId(),
            $this->userSubscriptionRepository->findNotchPayPastGracePeriod($cutoff)
        );
        $totalCount = count($subscriptionIds);

        if ($totalCount === 0) {
            $this->logger->info('Aucun abonnement Mobile Money à expirer');
            return;
        }

        $this->logger->info("Expiration de {$totalCount} abonnement(s) Mobile Money");

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

                    $subscription->setStatus(UserSubscription::STATUS_EXPIRED);
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Erreur expiration abonnement Mobile Money', [
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

        $this->logger->info('Expiration des abonnements Mobile Money terminée', [
            'processed' => $processed,
            'errors' => $errors,
            'total' => $totalCount
        ]);
    }
}
