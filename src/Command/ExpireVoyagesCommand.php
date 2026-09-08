<?php

declare(strict_types=1);

namespace App\Command;

use App\Constant\EventType;
use App\Repository\VoyageRepository;
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
    name: 'app:expire-voyages',
    description: 'Expire automatiquement les voyages dont la date de départ est dépassée'
)]
class ExpireVoyagesCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly VoyageRepository $voyageRepository,
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
            'Nombre de voyages à traiter par lot',
            self::BATCH_SIZE
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Expiration automatique des voyages');
        $io->text('Recherche des voyages à expirer...');

        $today = new \DateTime();
        $today->setTime(0, 0, 0); // Début de journée

        try {
            // Récupérer les voyages expirés
            $expiredVoyages = $this->voyageRepository->findExpiredVoyages($today);
            $totalCount = count($expiredVoyages);

            if ($totalCount === 0) {
                $io->success('Aucun voyage à expirer.');
                return Command::SUCCESS;
            }

            $io->text("{$totalCount} voyage(s) à expirer");
            $io->newLine();

            // Traitement par lots
            $processed = 0;
            $errors = 0;
            $batches = array_chunk($expiredVoyages, $batchSize);

            $io->progressStart($totalCount);

            foreach ($batches as $batchIndex => $batch) {
                foreach ($batch as $voyage) {
                    try {
                        $voyage->setStatut('expire');
                        $this->entityManager->persist($voyage);
                        $processed++;
                        $io->progressAdvance();
                    } catch (\Exception $e) {
                        $errors++;
                        $this->logger->error('Erreur lors de l\'expiration du voyage', [
                            'voyage_id' => $voyage->getId(),
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Flush après chaque lot
                $this->entityManager->flush();

                foreach ($batch as $voyage) {
                    try {
                        $this->notifier->publishVoyages(
                            [
                                'title' => 'Voyage expiré',
                                'message' => sprintf(
                                    'La voyage N°%d est arrivé à échéance et a été automatiquement marqué comme expiré.',
                                    $voyage->getId()
                                ),
                                'voyageId' => $voyage->getId(),
                                'statut' => 'expire',
                            ],
                            EventType::VOYAGE_EXPIRED
                        );

                        $this->notifier->publishToUser(
                            $voyage->getVoyageur(),
                            [
                                'title' => 'Votre demande a expiré',
                                'message' => sprintf(
                                    'Votre voyage N°%d est arrivé à échéance et a été marqué comme expiré.',
                                    $voyage->getId()
                                ),
                                'voyageId' => $voyage->getId(),
                                'statut' => 'expiree',
                            ],
                            EventType::VOYAGE_EXPIRED
                        );
                    } catch (\JsonException $e) {
                        $errors++;
                        $this->logger->error('Erreur lors de la notification Mercure (expire voyage)', [
                            'voyage_id' => $voyage->getId(),
                            'error' => $e->getMessage()
                        ]);
                    }
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
                            'titre'=> "Statistiques mis à jour",
                            'message' => "{$processed} voyages expirés, rafraîchir les stats"
                        ],
                        EventType::ADMIN_STATS_UPDATED
                    );
                } catch (\JsonException $e) {
                    $this->logger->error('Erreur lors de la notification Mercure (admin stats voyage)', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Résumé
            $io->success([
                "{$processed} voyage(s) expiré(s)",
                $errors > 0 ? "{$errors} erreur(s)" : '0 erreur',
            ]);

            $this->logger->info('Expiration des voyages terminée', [
                'processed' => $processed,
                'errors' => $errors,
                'total' => $totalCount
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de l\'expiration des voyages: ' . $e->getMessage());
            $this->logger->critical('Échec de l\'expiration des voyages', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}
