<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateVoyageDTO;
use App\DTO\UpdateVoyageDTO;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\VoyageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Constant\EventType;
use Psr\Log\LoggerInterface;

readonly class VoyageService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VoyageRepository $voyageRepository,
        private NotificationService $notificationService,
        private MatchingService $matchingService,
        private CurrencyService $currencyService,
        private LoggerInterface $logger,
        private RealtimeNotifier $notifier,
    ) {}

    public function getPaginatedVoyages(int $page, int $limit, array $filters = [], ?User $excludeUser = null): array
    {
        return $this->voyageRepository->findPaginated($page, $limit, $filters, $excludeUser);
    }

    public function getPublicPaginatedVoyages(int $page, int $limit, array $filters = []): array
    {
        return $this->voyageRepository->findPublicPaginated($page, $limit, $filters);

    }

    public function getVoyage(int $id): Voyage
    {
        $voyage = $this->voyageRepository->find($id);

        if (!$voyage) {
            throw new NotFoundHttpException('Voyage non trouvé');
        }

        return $voyage;
    }

    public function createVoyage(CreateVoyageDTO $dto, User $user): Voyage
    {
        // ==================== DEVISE DEPUIS SETTINGS UNIQUEMENT ====================
        $currency = $user->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        // Valider que la devise existe (normalement toujours le cas)
        if (!$this->currencyService->isSupported($currency)) {
            throw new BadRequestHttpException("La devise '{$currency}' n'est pas supportée");
        }

        $voyage = new Voyage();
        $voyage->setVoyageur($user)
            ->setVilleDepart($dto->villeDepart)
            ->setVilleArrivee($dto->villeArrivee)
            ->setDateDepart($dto->dateDepart)
            ->setDateArrivee($dto->dateArrivee)
            ->setPoidsDisponible((string) $dto->poidsDisponible)
            ->setPoidsDisponibleRestant((string) $dto->poidsDisponible)
            ->setPrixParKilo($dto->prixParKilo ? (string) $dto->prixParKilo : null)
            ->setCommissionProposeePourUnBagage($dto->commissionProposeePourUnBagage ? (string) $dto->commissionProposeePourUnBagage : null)
            ->setCurrency($currency)
            ->setDescription($dto->description)
            ->setStatut('actif');

        $this->entityManager->persist($voyage);
        $this->entityManager->flush();

        try {
            // 1. Notifie le public (par exemple pour un fil d’actualités ou une carte en temps réel)
            $this->notifier->publishPublic(
                [
                    'title' => 'Nouveau voyage publié',
                    'message' => sprintf(
                        'Un nouveau voyage a été ajouté de %s à %s (départ prévu le %s).',
                        $voyage->getVilleDepart(),
                        $voyage->getVilleArrivee(),
                        $voyage->getDateDepart()?->format('Y-m-d') ?? 'date non précisée'
                    ),
                    'voyageId' => $voyage->getId(),
                    'villeDepart' => $voyage->getVilleDepart(),
                    'villeArrivee' => $voyage->getVilleArrivee(),
                    'dateDepart' => $voyage->getDateDepart()?->format('Y-m-d'),
                    'createdBy' => $user->getId(),
                ],
                EventType::VOYAGE_CREATED
            );

            // 2. Notifie les administrateurs (création d’un nouveau voyage)
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Nouveau voyage créé',
                    'message' => sprintf(
                        'Un nouveau voyage (ID: N°%d) a été créé par le voyageur N°%d.',
                        $voyage->getId(),
                        $user->getId()
                    ),
                    'voyageId' => $voyage->getId(),
                    'voyageurId' => $user->getId(),
                ],
                EventType::VOYAGE_CREATED
            );

            // 3. Notifie les administrateurs de mettre à jour les statistiques
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Mise à jour des statistiques',
                    'message' => 'Un nouveau voyage a été publié. Les statistiques doivent être actualisées.',
                ],
                EventType::ADMIN_STATS_UPDATED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de VOYAGE_CREATED', [
                'voyage_id' => $voyage->getId(),
                'error' => $e->getMessage(),
            ]);
        }


        // Notifier les demandes matchées
        $this->notificationService->notifyMatchingDemandes($voyage);

        return $voyage;
    }

    public function updateVoyage(int $id, UpdateVoyageDTO $dto): Voyage
    {
        $voyage = $this->getVoyage($id);

        // === Mise à jour des champs simples ===
        if ($dto->villeDepart !== null) {
            $voyage->setVilleDepart($dto->villeDepart);
        }
        if ($dto->villeArrivee !== null) {
            $voyage->setVilleArrivee($dto->villeArrivee);
        }
        if ($dto->dateDepart !== null) {
            $voyage->setDateDepart($dto->dateDepart);
        }
        if ($dto->dateArrivee !== null) {
            $voyage->setDateArrivee($dto->dateArrivee);
        }
        if ($dto->prixParKilo !== null) {
            $voyage->setPrixParKilo((string) $dto->prixParKilo);
        }
        if ($dto->commissionProposeePourUnBagage !== null) {
            $voyage->setCommissionProposeePourUnBagage((string) $dto->commissionProposeePourUnBagage);
        }
        if ($dto->description !== null) {
            $voyage->setDescription($dto->description);
        }

        // === Gestion du poids disponible ===
        if ($dto->poidsDisponible !== null) {
            $nouveauPoidsTotal = (float) $dto->poidsDisponible;

            // Calcul du poids déjà réservé (propositions acceptées)
            $poidsDejaReserve = array_sum(array_map(
                fn($p) => (float) $p->getDemande()->getPoidsEstime(),
                $voyage->getPropositions()
                    ->filter(fn($p) => $p->getStatut() === 'acceptee')
                    ->toArray()
            ));

            // Vérification logique
            if ($nouveauPoidsTotal < $poidsDejaReserve) {
                throw new BadRequestHttpException(sprintf(
                    "Impossible de définir un poids disponible de %.2f kg : %.2f kg sont déjà réservés par des propositions acceptées.",
                    $nouveauPoidsTotal,
                    $poidsDejaReserve
                ));
            }

            // Mise à jour cohérente
            $voyage->setPoidsDisponible((string) $nouveauPoidsTotal);
            $voyage->setPoidsDisponibleRestant((string) ($nouveauPoidsTotal - $poidsDejaReserve));
        }

        // === Sauvegarde ===
        $this->entityManager->flush();

        try {
            // Notifie le topic du voyage
            $this->notifier->publishVoyages(
                [
                    'title' => 'Voyage mis à jour',
                    'message' => sprintf(
                        'Les détails du voyage N°%d ont été mis à jour. Poids disponible : %s kg, poids restant : %s kg.',
                        $voyage->getId(),
                        $voyage->getPoidsDisponible(),
                        $voyage->getPoidsDisponibleRestant()
                    ),
                    'voyageId' => $voyage->getId(),
                    'poidsDisponible' => $voyage->getPoidsDisponible(),
                    'poidsDisponibleRestant' => $voyage->getPoidsDisponibleRestant(),
                ],
                EventType::VOYAGE_UPDATED
            );

            // 2️⃣ Notification directe au voyageur (propriétaire du voyage)
            $this->notifier->publishToUser(
                $voyage->getVoyageur(),
                [
                    'title' => 'Votre voyage a été mis à jour',
                    'message' => sprintf(
                        'Le voyage N°%d a bien été modifié. Poids restant : %s kg.',
                        $voyage->getId(),
                        $voyage->getPoidsDisponibleRestant()
                    ),
                    'voyageId' => $voyage->getId(),
                    'poidsDisponibleRestant' => $voyage->getPoidsDisponibleRestant(),
                ],
                EventType::VOYAGE_UPDATED
            );

            // 3️⃣ Optionnel : notification aux admins (pour stats/dashboard)
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Mise à jour de voyage',
                    'message' => sprintf(
                        'Le voyage N°%d a été modifié par %s %s.',
                        $voyage->getId(),
                        $voyage->getVoyageur()->getNom(),
                        $voyage->getVoyageur()->getPrenom()
                    ),
                ],
                EventType::ADMIN_STATS_UPDATED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de VOYAGE_UPDATED', [
                'voyage_id' => $voyage->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $voyage;
    }

    public function updateStatut(int $id, string $statut): Voyage
    {
        $voyage = $this->getVoyage($id);
        $voyage->setStatut($statut);
        $this->entityManager->flush();

        $eventType = match ($statut) {
            'complet' => EventType::VOYAGE_COMPLETED,
            'annule'  => EventType::VOYAGE_CANCELLED,
            'expire'  => EventType::VOYAGE_EXPIRED,
            default   => EventType::VOYAGE_UPDATED,
        };

        try {
            $title = '';
            $message = '';

            switch ($statut) {
                case 'complet':
                    $title = 'Voyage complété';
                    $message = sprintf(
                        'Le voyage N°%d a été marqué comme complété.',
                        $voyage->getId()
                    );
                    break;

                case 'annule':
                    $title = 'Voyage annulé';
                    $message = sprintf(
                        'Le voyage N°%d a été annulé par le voyageur ou un administrateur.',
                        $voyage->getId()
                    );
                    break;

                case 'expire':
                    $title = 'Voyage expiré';
                    $message = sprintf(
                        'Le voyage N°%d est arrivé à expiration et a été automatiquement clôturé.',
                        $voyage->getId()
                    );
                    break;

                default:
                    $title = 'Voyage mis à jour';
                    $message = sprintf(
                        'Le statut du voyage N°%d a été mis à jour : %s.',
                        $voyage->getId(),
                        ucfirst($statut)
                    );
                    break;
            }

            // 1️⃣ Publication globale (flux voyages)
            $this->notifier->publishVoyages(
                [
                    'title' => $title,
                    'message' => $message,
                    'voyageId' => $voyage->getId(),
                    'statut' => $statut,
                ],
                $eventType
            );

            // 2️⃣ Notification directe au voyageur
            $this->notifier->publishToUser(
                $voyage->getVoyageur(),
                [
                    'title' => $title,
                    'message' => $message,
                    'voyageId' => $voyage->getId(),
                    'statut' => $statut,
                ],
                $eventType
            );

            // 3️⃣ Notification groupe admin (pour rafraîchir stats ou logs)
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Mise à jour du statut d’un voyage',
                    'message' => sprintf(
                        'Le voyage #%d a changé de statut : %s.',
                        $voyage->getId(),
                        $statut
                    ),
                    'voyageId' => $voyage->getId(),
                    'statut' => $statut,
                ],
                EventType::ADMIN_STATS_UPDATED
            );

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de la mise à jour du statut du voyage', [
                'voyage_id' => $voyage->getId(),
                'statut' => $statut,
                'error' => $e->getMessage(),
            ]);
        }



        return $voyage;
    }

    public function deleteVoyage(int $id): void
    {
        $voyage = $this->getVoyage($id);

        // Ne rien faire si déjà annulé ou expiré
        if (in_array($voyage->getStatut(), ['annule', 'expire'], true)) {
            return;
        }

        // Mettre à jour le statut principal
        $voyage->setStatut('annule');
        $voyage->setUpdatedAt();

        // Parcourir toutes les propositions liées à ce voyage
        foreach ($voyage->getPropositions() as $proposition) {
            $demande = $proposition->getDemande();

            // Annuler la proposition si elle ne l'est pas déjà
            if ($proposition->getStatut() !== 'annulee') {
                $proposition->setStatut('annulee');
                $proposition->setReponduAt(new \DateTimeImmutable());
            }

            // Si la demande est encore active, on la remet en recherche
            if ($demande && !in_array($demande->getStatut(), ['annulee', 'expiree'], true)) {
                $demande->setStatut('en_recherche');
                $demande->setUpdatedAt(new \DateTimeImmutable());
                $client = $demande->getClient();

                // Notifier le client concerné
                $this->notificationService->createNotification(
                    $demande->getClient(),
                    'voyage_annule',
                    'Voyage annulé',
                    sprintf(
                        "Le voyage %s vers %s auquel vous aviez soumis une proposition a été annulé.
Votre demande est de nouveau en recherche d’un voyageur.",
                        $voyage->getVilleDepart(),
                        $voyage->getVilleArrivee()
                    ),
                    [
                        'voyageId' => $voyage->getId(),
                        'demandeId' => $demande->getId(),
                        'propositionId' => $proposition->getId(),
                    ]
                );

                try {
                    // 1️⃣ Notifie le client que sa proposition est annulée
                    $this->notifier->publishToUser(
                        $client,
                        [
                            'title' => 'Proposition annulée',
                            'message' => sprintf(
                                'Votre proposition N°%d liée au voyage N°%d a été annulée suite à la suppression du voyage.',
                                $proposition->getId(),
                                $voyage->getId()
                            ),
                            'propositionId' => $proposition->getId(),
                            'voyageId' => $voyage->getId(),
                        ],
                        EventType::PROPOSITION_CANCELLED
                    );

                    // 2️⃣ Notifie le flux global des demandes (topic public `/topics/demandes`)
                    $this->notifier->publishDemandes(
                        [
                            'title' => 'Demande mise à jour',
                            'message' => sprintf(
                                'Le statut de la demande N°%d a été réinitialisé à "en recherche" suite à l’annulation du voyage.',
                                $demande->getId()
                            ),
                            'demandeId' => $demande->getId(),
                            'statut' => 'en_recherche',
                        ],
                        EventType::DEMANDE_STATUT_UPDATED
                    );

                    // 3️⃣ (Optionnel) Notifie les administrateurs pour les stats
                    $this->notifier->publishToGroup(
                        'admin',
                        [
                            'title' => 'Proposition annulée suite à suppression de voyage',
                            'message' => sprintf(
                                'La proposition #%d et la demande #%d ont été impactées par la suppression du voyage #%d.',
                                $proposition->getId(),
                                $demande->getId(),
                                $voyage->getId()
                            ),
                        ],
                        EventType::ADMIN_STATS_UPDATED
                    );

                } catch (\JsonException $e) {
                    $this->logger->error('Échec de la publication de l’annulation côté client lors de la suppression du voyage', [
                        'demande_id' => $demande->getId(),
                        'voyage_id' => $voyage->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }


            }
        }

        // Persister les changements
        $this->entityManager->flush();

        try {
            // 1️⃣ Notifie le flux global des voyages (topic public)
            $this->notifier->publishVoyages(
                [
                    'title' => 'Voyage annulé',
                    'message' => sprintf(
                        'Le voyage N°%d a été annulé par le voyageur ou un administrateur.',
                        $voyage->getId()
                    ),
                    'voyageId' => $voyage->getId(),
                    'statut' => 'annule',
                ],
                EventType::VOYAGE_CANCELLED
            );

            // 2️⃣ Notifie le voyageur concerné
            $this->notifier->publishToUser(
                $voyage->getVoyageur(),
                [
                    'title' => 'Votre voyage a été annulé',
                    'message' => sprintf(
                        'Votre voyage N°%d a été marqué comme annulé.',
                        $voyage->getId()
                    ),
                    'voyageId' => $voyage->getId(),
                    'statut' => 'annule',
                ],
                EventType::VOYAGE_CANCELLED
            );

            // 3️⃣ Notifie les administrateurs pour rafraîchir les statistiques
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Mise à jour des statistiques',
                    'message' => sprintf(
                        'Le voyage N°%d a été annulé. Les statistiques doivent être actualisées.',
                        $voyage->getId()
                    ),
                    'voyageId' => $voyage->getId(),
                    'statut' => 'annule',
                ],
                EventType::ADMIN_STATS_UPDATED
            );

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de VOYAGE_CANCELLED (global)', [
                'voyage_id' => $voyage->getId(),
                'error' => $e->getMessage(),
            ]);
        }

    }

    public function getVoyagesByUser(int $userId): array
    {
        return $this->voyageRepository->findByVoyageur($userId);
    }

    /**
     * Trouver les demandes correspondantes à un voyage (avec scoring)
     */
    public function findMatchingDemandes(int $voyageId, ?User $viewer = null): array
    {
        $voyage = $this->getVoyage($voyageId);
        return $this->matchingService->findMatchingDemandes($voyage, $viewer);
    }

    /**
     * Convertir les montants d'un voyage dans une devise cible
     */
    public function convertVoyageAmounts(Voyage $voyage, string $targetCurrency): array
    {
        $result = [
            'originalCurrency' => $voyage->getCurrency(),
            'targetCurrency' => $targetCurrency,
        ];

        if ($voyage->getPrixParKilo()) {
            $result['prixParKilo'] = $this->currencyService->convert(
                (float) $voyage->getPrixParKilo(),
                $voyage->getCurrency(),
                $targetCurrency
            );
            $result['prixParKiloFormatted'] = $this->currencyService->formatAmount(
                $result['prixParKilo'],
                $targetCurrency
            );
        }

        if ($voyage->getCommissionProposeePourUnBagage()) {
            $result['commission'] = $this->currencyService->convert(
                (float) $voyage->getCommissionProposeePourUnBagage(),
                $voyage->getCurrency(),
                $targetCurrency
            );
            $result['commissionFormatted'] = $this->currencyService->formatAmount(
                $result['commission'],
                $targetCurrency
            );
        }

        return $result;
    }
}
