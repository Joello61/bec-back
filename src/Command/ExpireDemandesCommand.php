<?php

declare(strict_types=1);

namespace App\Command;

use App\Constant\EventType;
use App\Repository\DemandeRepository;
use App\Service\RealtimeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:expire-demandes',
    description: 'Expire automatiquement les demandes dont la date limite est dépassée'
)]
class ExpireDemandesCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly DemandeRepository $demandeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RealtimeNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'batch-size',
            'b',
            InputOption::VALUE_OPTIONAL,
            'Nombre de demandes à traiter par lot',
            self::BATCH_SIZE
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Expiration automatique des demandes');
        $io->text('Recherche des demandes à expirer...');

        $today = new \DateTime();
        $today->setTime(0, 0, 0); // Début de journée

        try {
            // Récupérer les demandes expirées
            $expiredDemandes = $this->demandeRepository->findExpiredDemandes($today);
            $totalCount = count($expiredDemandes);

            if ($totalCount === 0) {
                $io->success('Aucune demande à expirer.');
                return Command::SUCCESS;
            }

            $io->text("{$totalCount} demande(s) à expirer");
            $io->newLine();

            // Traitement par lots
            $processed = 0;
            $errors = 0;
            $batches = array_chunk($expiredDemandes, $batchSize);

            $io->progressStart($totalCount);

            foreach ($batches as $batchIndex => $batch) {
                foreach ($batch as $demande) {
                    try {
                        $demande->setStatut('expiree');
                        $this->entityManager->persist($demande);
                        $processed++;
                        $io->progressAdvance();
                    } catch (\Exception $e) {
                        $errors++;
                        $this->logger->error('Erreur lors de l\'expiration de la demande', [
                            'demande_id' => $demande->getId(),
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Flush après chaque lot
                $this->entityManager->flush();

                foreach ( $batch as $demande ) {
                    $this->notifier->publishDemandes(
                        [
                            'title' => 'Demande expirée',
                            'message' => sprintf(
                                'La demande N°%d est arrivée à échéance et a été automatiquement marquée comme expirée.',
                                $demande->getId()
                            ),
                            'demandeId' => $demande->getId(),
                            'statut' => 'expiree',
                        ],
                        EventType::DEMANDE_EXPIRED
                    );

                    // 2⃣ Notifie le propriétaire de la demande
                    $this->notifier->publishToUser(
                        $demande->getClient(),
                        [
                            'title' => 'Votre demande a expiré',
                            'message' => sprintf(
                                'Votre demande N°%d est arrivée à échéance et a été clôturée automatiquement.',
                                $demande->getId()
                            ),
                            'demandeId' => $demande->getId(),
                            'statut' => 'expiree',
                        ],
                        EventType::DEMANDE_EXPIRED
                    );
                }

                $this->entityManager->clear(); // Libérer la mémoire

                $this->logger->info("Lot {$batchIndex} traité", [
                    'batch_size' => count($batch),
                    'total_processed' => $processed
                ]);
            }

            $io->progressFinish();
            $io->newLine();

            if ($processed > 0) {
                try {
                    $this->notifier->publishToGroup(
                        'admin',
                        [
                            'titre' => 'Statistiques mis à jour',
                            'message' => "{$processed} demandes expirées, rafraîchir les stats",
                        ],
                        EventType::ADMIN_STATS_UPDATED
                    );
                } catch (\JsonException $e) {
                    $this->logger->error('Erreur lors de la notification Mercure (admin stats)', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Résumé
            $io->success([
                "{$processed} demande(s) expirée(s)",
                $errors > 0 ? "{$errors} erreur(s)" : '0 erreur',
            ]);

            $this->logger->info('Expiration des demandes terminée', [
                'processed' => $processed,
                'errors' => $errors,
                'total' => $totalCount
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de l\'expiration des demandes: ' . $e->getMessage());
            $this->logger->critical('Échec de l\'expiration des demandes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}
