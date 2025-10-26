<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateDemandeDTO;
use App\DTO\UpdateDemandeDTO;
use App\Entity\Demande;
use App\Entity\User;
use App\Repository\DemandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Constant\EventType;
use Psr\Log\LoggerInterface;

readonly class DemandeService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DemandeRepository $demandeRepository,
        private NotificationService $notificationService,
        private MatchingService $matchingService,
        private CurrencyService $currencyService,
        private RealtimeNotifier $notifier,
        private LoggerInterface $logger,
    ) {}

    public function getPaginatedDemandes(int $page, int $limit, array $filters = [], ?User $excludeUser = null): array
    {
        return $this->demandeRepository->findPaginated($page, $limit, $filters, $excludeUser);
    }

    public function getDemande(int $id): Demande
    {
        $demande = $this->demandeRepository->find($id);

        if (!$demande) {
            throw new NotFoundHttpException('Demande non trouvée');
        }

        return $demande;
    }

    public function createDemande(CreateDemandeDTO $dto, User $user): Demande
    {
        // ==================== DEVISE DEPUIS SETTINGS UNIQUEMENT ====================
        $currency = $user->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        // Valider que la devise existe (normalement toujours le cas)
        if (!$this->currencyService->isSupported($currency)) {
            throw new BadRequestHttpException("La devise '{$currency}' n'est pas supportée");
        }

        $demande = new Demande();
        $demande->setClient($user)
            ->setVilleDepart($dto->villeDepart)
            ->setVilleArrivee($dto->villeArrivee)
            ->setDateLimite($dto->dateLimite)
            ->setPoidsEstime((string) $dto->poidsEstime)
            ->setPrixParKilo($dto->prixParKilo ? (string) $dto->prixParKilo : null)
            ->setCommissionProposeePourUnBagage($dto->commissionProposeePourUnBagage ? (string) $dto->commissionProposeePourUnBagage : null)
            ->setCurrency($currency) // ⬅️ Toujours depuis settings
            ->setDescription($dto->description)
            ->setStatut('en_recherche');

        $this->entityManager->persist($demande);
        $this->entityManager->flush();

        try {
            // 1. Notifie le public (ex : pour un fil d’activité ou une carte en temps réel)
            $this->notifier->publishPublic(
                [
                    'title' => 'Nouvelle demande publiée',
                    'message' => sprintf(
                        'Une nouvelle demande de transport a été ajoutée de %s à %s (date limite : %s).',
                        $demande->getVilleDepart(),
                        $demande->getVilleArrivee(),
                        $demande->getDateLimite()?->format('Y-m-d') ?? 'non précisée'
                    ),
                    'demandeId' => $demande->getId(),
                    'villeDepart' => $demande->getVilleDepart(),
                    'villeArrivee' => $demande->getVilleArrivee(),
                    'dateLimite' => $demande->getDateLimite()?->format('Y-m-d'),
                    'createdBy' => $user->getId(),
                ],
                EventType::DEMANDE_CREATED
            );

            // 2. Notifie les administrateurs
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Nouvelle demande créée',
                    'message' => sprintf(
                        'Une nouvelle demande (ID: %d) a été créée par l’utilisateur #%d.',
                        $demande->getId(),
                        $user->getId()
                    ),
                    'demandeId' => $demande->getId(),
                    'clientId' => $user->getId(),
                ],
                EventType::DEMANDE_CREATED
            );

            // 3. Notifie les administrateurs de mettre à jour les statistiques
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Mise à jour des statistiques',
                    'message' => 'Une nouvelle demande a été publiée. Les statistiques doivent être actualisées.'
                ],
                EventType::ADMIN_STATS_UPDATED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de DEMANDE_CREATED', [
                'demande_id' => $demande->getId(),
                'error' => $e->getMessage(),
            ]);
        }


        // Notifier les voyages matchés
        $this->notificationService->notifyMatchingVoyages($demande);

        return $demande;
    }

    public function updateDemande(int $id, UpdateDemandeDTO $dto): Demande
    {
        $demande = $this->getDemande($id);

        if ($dto->villeDepart !== null) {
            $demande->setVilleDepart($dto->villeDepart);
        }
        if ($dto->villeArrivee !== null) {
            $demande->setVilleArrivee($dto->villeArrivee);
        }
        if ($dto->dateLimite !== null) {
            $demande->setDateLimite($dto->dateLimite);
        }
        if ($dto->poidsEstime !== null) {
            $demande->setPoidsEstime((string) $dto->poidsEstime);
        }
        if ($dto->prixParKilo !== null) {
            $demande->setPrixParKilo((string) $dto->prixParKilo);
        }
        if ($dto->commissionProposeePourUnBagage !== null) {
            $demande->setCommissionProposeePourUnBagage((string) $dto->commissionProposeePourUnBagage);
        }
        if ($dto->description !== null) {
            $demande->setDescription($dto->description);
        }

        // ⬅️ PAS de modification de devise possible

        $this->entityManager->flush();

        try {
            $this->notifier->publishDemandes(
                [
                    'title' => 'Demande mise à jour',
                    'message' => sprintf(
                        "Les détails de la demande N°%d de %s vers %s (limite : %s) ont été modifiés.",
                        $demande->getId(),
                        $demande->getVilleDepart(),
                        $demande->getVilleArrivee(),
                        $demande->getDateLimite()?->format('Y-m-d')
                    ),
                    'demandeId' => $demande->getId(),
                    'userId' => $demande->getClient()->getId(), // utile pour filtrer côté front
                ],
                EventType::DEMANDE_UPDATED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de DEMANDE_UPDATED', [
                'demande_id' => $demande->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $demande;
    }

    public function updateStatut(int $id, string $statut): Demande
    {
        $demande = $this->getDemande($id);
        $demande->setStatut($statut);
        $this->entityManager->flush();

        try {
            // 1️⃣ Notifier le flux global (utile pour le live feed, stats, etc.)
            $this->notifier->publishDemandes(
                [
                    'title' => 'Statut de demande mis à jour',
                    'message' => sprintf(
                        'Le statut de la demande N°%d est maintenant "%s".',
                        $demande->getId(),
                        ucfirst($statut)
                    ),
                    'demandeId' => $demande->getId(),
                    'statut' => $statut,
                    'userId' => $demande->getClient()->getId(),
                ],
                EventType::DEMANDE_STATUT_UPDATED
            );

            // 2️⃣ Notifier l’auteur de la demande (si tu veux une alerte directe)
            $this->notifier->publishToUser(
                $demande->getClient(),
                [
                    'title' => 'Votre demande a changé de statut',
                    'message' => sprintf(
                        'Le statut de votre demande N°%d est maintenant "%s".',
                        $demande->getId(),
                        ucfirst($statut)
                    ),
                    'demandeId' => $demande->getId(),
                    'statut' => $statut,
                ],
                EventType::DEMANDE_STATUT_UPDATED
            );

            // 3️⃣ Notifier les administrateurs (rafraîchissement des stats)
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Demande mise à jour',
                    'message' => sprintf('Le statut de la demande N°%d a été modifié (%s).', $demande->getId(), $statut),
                ],
                EventType::ADMIN_STATS_UPDATED
            );

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de DEMANDE_STATUT_UPDATED', [
                'demande_id' => $demande->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $demande;
    }

    public function deleteDemande(int $id): void
    {
        $demande = $this->getDemande($id);
        $demandeId = $demande->getId();

        // Éviter de retraiter une demande déjà annulée ou expirée
        if (in_array($demande->getStatut(), ['annulee', 'expiree'], true)) {
            return;
        }

        // Mettre à jour le statut principal
        $demande->setStatut('annulee');
        $demande->setUpdatedAt();

        // Parcourir toutes les propositions liées
        foreach ($demande->getPropositions() as $proposition) {
            $voyage = $proposition->getVoyage();
            $statut = $proposition->getStatut();

            // Si la proposition était acceptée → libérer le poids du voyage
            if ($statut === 'acceptee' && $voyage) {
                $poidsDisponible = (float) $voyage->getPoidsDisponibleRestant();
                $poidsDemande = (float) $demande->getPoidsEstime();
                $nouveauPoidsRestant = max(0, $poidsDisponible + $poidsDemande);

                // Doctrine attend une string pour DECIMAL
                $voyage->setPoidsDisponibleRestant(number_format($nouveauPoidsRestant, 2, '.', ''));

                // Si le voyage était complet mais a maintenant de la place
                if ($voyage->getStatut() === 'complete' && $nouveauPoidsRestant > 0) {
                    $voyage->setStatut('actif');
                }
            }

            // Annuler la proposition si elle ne l'est pas déjà
            if ($statut !== 'annulee') {
                $proposition->setStatut('annulee');
                $proposition->setReponduAt(new \DateTimeImmutable());
            }

            // Notifier les voyageurs concernés (en attente ou acceptée)
            if (in_array($statut, ['en_attente', 'acceptee'], true) && $voyage) {
                $voyageur = $voyage->getVoyageur();

                $this->notificationService->createNotification(
                    $voyageur,
                    'demande_annulee',
                    'Demande annulée',
                    sprintf(
                        "La demande de %s %s pour le voyage %s vers %s a été annulée.",
                        $demande->getClient()->getPrenom(),
                        $demande->getClient()->getNom(),
                        $voyage->getVilleDepart(),
                        $voyage->getVilleArrivee()
                    ),
                    [
                        'demandeId' => $demande->getId(),
                        'voyageId' => $voyage->getId(),
                        'propositionId' => $proposition->getId(),
                    ]
                );

                try {
                    $this->notifier->publishToUser(
                        $voyageur,
                        [
                            'title' => 'Proposition annulée',
                            'message' => sprintf(
                                'La demande N°%d à laquelle vous aviez fait une proposition a été annulée. Votre proposition associée est donc également annulée.',
                                $demandeId
                            ),
                            'demandeId' => $demandeId,
                            'propositionId' => $proposition->getId(),
                        ],
                        EventType::PROPOSITION_CANCELLED
                    );
                } catch (\JsonException $e) {
                    $this->logger->error('Échec de la publication de PROPOSITION_CANCELLED à l’utilisateur', [
                        'user_id' => $voyageur->getId(),
                        'demande_id' => $demandeId,
                        'error' => $e->getMessage(),
                    ]);
                }

            }
        }

        // Sauvegarder toutes les modifications
        $this->entityManager->flush();

        try {
            // 1 Diffusion publique (feed global des demandes)
            $this->notifier->publishDemandes(
                [
                    'title' => 'Demande annulée',
                    'message' => sprintf(
                        'La demande N°%d a été annulée par son auteur ou par un administrateur.',
                        $demandeId
                    ),
                    'demandeId' => $demandeId,
                ],
                EventType::DEMANDE_CANCELLED
            );

            // 2 Notifie directement l’auteur (si besoin)
            if (isset($demande) && $demande->getClient()) {
                $this->notifier->publishToUser(
                    $demande->getClient(),
                    [
                        'title' => 'Votre demande a été annulée',
                        'message' => sprintf(
                            'Votre demande N°%d a été annulée.',
                            $demandeId,
                        ),
                        'demandeId' => $demandeId,
                    ],
                    EventType::DEMANDE_CANCELLED
                );
            }

            // 3️⃣ Notifie les administrateurs (rafraîchir les stats)
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Statistiques mises à jour',
                    'message' => sprintf('La demande N°%d a été annulée. Les statistiques doivent être rafraîchies.', $demandeId),
                ],
                EventType::ADMIN_STATS_UPDATED
            );

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de DEMANDE_CANCELLED', [
                'demande_id' => $demandeId,
                'error' => $e->getMessage(),
            ]);
        }


    }

    public function getDemandesByUser(int $userId): array
    {
        return $this->demandeRepository->findByClient($userId);
    }

    /**
     * Trouver les voyages correspondants à une demande (avec scoring)
     */
    public function findMatchingVoyages(int $demandeId, ?User $viewer = null): array
    {
        $demande = $this->getDemande($demandeId);
        $matching = $this->matchingService->findBestMatchesVoyages($demande, $viewer);

        if (!empty($matching)) {
            try {
                // 1. Notifie le client (propriétaire de la demande)
                $this->notifier->publishToUser(
                    $demande->getClient(),
                    [
                        'title' => 'Nouvelles correspondances trouvées',
                        'message' => sprintf(
                            '%d voyage(s) correspondant(s) à votre demande N°%d ont été trouvés.',
                            count($matching),
                            $demande->getId()
                        ),
                        'demandeId' => $demande->getId(),
                        'matchCount' => count($matching),
                    ],
                    EventType::DEMANDE_MATCHED
                );

                // 2. Notifie le visiteur (si ce n’est pas le propriétaire)
                if ($viewer && $viewer->getId() !== $demande->getClient()->getId()) {
                    $this->notifier->publishToUser(
                        $viewer,
                        [
                            'title' => 'Résultats de correspondance',
                            'message' => sprintf(
                                '%d voyage(s) correspondant(s) à la demande N°%d ont été trouvés.',
                                count($matching),
                                $demande->getId()
                            ),
                            'demandeId' => $demande->getId(),
                            'matchCount' => count($matching),
                        ],
                        EventType::DEMANDE_MATCHED
                    );
                }
            } catch (\JsonException $e) {
                $this->logger->error('Échec de la publication de DEMANDE_MATCHED', [
                    'demande_id' => $demande->getId(),
                    'error' => $e->getMessage(),
                ]);
            }

        }

        return $matching;
    }

    /**
     * Convertir les montants d'une demande dans une devise cible
     */
    public function convertDemandeAmounts(Demande $demande, string $targetCurrency): array
    {
        $result = [
            'originalCurrency' => $demande->getCurrency(),
            'targetCurrency' => $targetCurrency,
        ];

        if ($demande->getPrixParKilo()) {
            $result['prixParKilo'] = $this->currencyService->convert(
                (float) $demande->getPrixParKilo(),
                $demande->getCurrency(),
                $targetCurrency
            );
            $result['prixParKiloFormatted'] = $this->currencyService->formatAmount(
                $result['prixParKilo'],
                $targetCurrency
            );
        }

        if ($demande->getCommissionProposeePourUnBagage()) {
            $result['commission'] = $this->currencyService->convert(
                (float) $demande->getCommissionProposeePourUnBagage(),
                $demande->getCurrency(),
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
