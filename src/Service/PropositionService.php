<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreatePropositionDTO;
use App\DTO\RespondPropositionDTO;
use App\Entity\Proposition;
use App\Entity\User;
use App\Repository\DemandeRepository;
use App\Repository\PropositionRepository;
use App\Repository\VoyageRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Constant\EventType;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class PropositionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropositionRepository $propositionRepository,
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository,
        private NotificationService $notificationService,
        private CurrencyService $currencyService,
        private LoggerInterface $logger,
        private RealtimeNotifier $notifier,
    ) {}

    /**
     * Créer une proposition pour un voyage
     */
    public function createProposition(int $voyageId, CreatePropositionDTO $dto, User $client): Proposition
    {
        // Vérifier que le voyage existe
        $voyage = $this->voyageRepository->find($voyageId);
        if (!$voyage) {
            throw new NotFoundHttpException('Voyage non trouvé');
        }

        // Vérifier que la demande existe
        $demande = $this->demandeRepository->find($dto->demandeId);
        if (!$demande) {
            throw new NotFoundHttpException('Demande non trouvée');
        }

        // Vérifier que le client est bien le propriétaire de la demande
        if ($demande->getClient() !== $client) {
            throw new BadRequestHttpException('Vous ne pouvez faire une proposition qu\'avec votre propre demande');
        }

        // Vérifier que le client ne propose pas sur son propre voyage
        if ($voyage->getVoyageur() === $client) {
            throw new BadRequestHttpException('Vous ne pouvez pas faire de proposition sur votre propre voyage');
        }

        // Vérifier que le voyage est actif
        if ($voyage->getStatut() !== 'actif') {
            throw new BadRequestHttpException('Ce voyage n\'est plus actif');
        }

        // Vérifier que la demande est en recherche
        if ($demande->getStatut() !== 'en_recherche') {
            throw new BadRequestHttpException('Cette demande n\'est plus en recherche');
        }

        // Vérifier qu'une proposition n'existe pas déjà
        $existingProposition = $this->propositionRepository->existsByVoyageAndDemande($voyageId, $dto->demandeId);
        if ($existingProposition) {
            throw new BadRequestHttpException('Vous avez déjà fait une proposition pour ce voyage');
        }

        if (($voyage->getPoidsDisponible() - $demande->getPoidsEstime()) <= 0) {
            throw new BadRequestHttpException('Le voyage n\'a plus de place disponible');
        }

        // ==================== DEVISE DE LA DEMANDE ====================
        // La proposition utilise TOUJOURS la devise de la demande du client
        $currency = $demande->getCurrency();

        // Créer la proposition
        $proposition = new Proposition();
        $proposition->setVoyage($voyage)
            ->setDemande($demande)
            ->setClient($client)
            ->setVoyageur($voyage->getVoyageur())
            ->setPrixParKilo((string) $dto->prixParKilo)
            ->setCommissionProposeePourUnBagage((string) $dto->commissionProposeePourUnBagage)
            ->setCurrency($currency) // ⬅️ Toujours la devise de la demande
            ->setMessage($dto->message)
            ->setStatut('en_attente');

        $this->entityManager->persist($proposition);
        $this->entityManager->flush();

        // Notifier le voyageur
        $this->notificationService->createNotification(
            $voyage->getVoyageur(),
            'new_proposition',
            'Nouvelle proposition reçue',
            sprintf(
                '%s %s a fait une proposition pour votre voyage %s vers %s',
                $client->getPrenom(),
                $client->getNom(),
                $voyage->getVilleDepart(),
                $voyage->getVilleArrivee()
            ),
            [
                'propositionId' => $proposition->getId(),
                'voyageId' => $voyage->getId()
            ]
        );

        try {
            // 1. Notifie le voyageur (mise à jour de l’interface)
            $this->notifier->publishToUser(
                $voyage->getVoyageur(),
                [
                    'title' => 'Nouvelle proposition reçue',
                    'message' => sprintf(
                        'Vous avez reçu une nouvelle proposition pour votre voyage N°%d.',
                        $voyageId
                    ),
                    'propositionId' => $proposition->getId(),
                    'voyageId' => $voyageId,
                ],
                EventType::PROPOSITION_CREATED
            );

            // 2. Notifie le client (synchronisation multi-appareils)
            $this->notifier->publishToUser(
                $client,
                [
                    'title' => 'Proposition envoyée',
                    'message' => sprintf(
                        'Votre proposition pour le voyage N°%d a été envoyée avec succès.',
                        $voyageId
                    ),
                    'propositionId' => $proposition->getId(),
                    'voyageId' => $voyageId,
                ],
                EventType::PROPOSITION_CREATED
            );

            // 3. Notifie les administrateurs (nouvelle proposition)
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Nouvelle proposition créée',
                    'message' => sprintf(
                        'Une nouvelle proposition (ID: %d) a été créée par l’utilisateur N°%d pour le voyageur N°%d.',
                        $proposition->getId(),
                        $client->getId(),
                        $voyage->getVoyageur()->getId()
                    ),
                    'propositionId' => $proposition->getId(),
                    'clientId' => $client->getId(),
                    'voyageurId' => $voyage->getVoyageur()->getId(),
                ],
                EventType::PROPOSITION_CREATED
            );

            // 4. Notifie les administrateurs de mettre à jour les statistiques
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Mise à jour des statistiques',
                    'message' => 'Une nouvelle proposition a été créée. Les statistiques doivent être actualisées.',
                ],
                EventType::ADMIN_STATS_UPDATED
            );

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de PROPOSITION_CREATED', [
                'proposition_id' => $proposition->getId(),
                'error' => $e->getMessage(),
            ]);
        }


        return $proposition;
    }

    /**
     * Répondre à une proposition (accepter/refuser)
     */
    public function respondToProposition(int $propositionId, RespondPropositionDTO $dto, User $voyageur): Proposition
    {
        /** @var Proposition|null $proposition */
        $proposition = $this->propositionRepository->find($propositionId);

        if (!$proposition) {
            throw new NotFoundHttpException('Proposition non trouvée');
        }

        // Vérifier que c'est bien le voyageur concerné
        if ($proposition->getVoyageur() !== $voyageur) {
            throw new BadRequestHttpException('Vous n\'êtes pas autorisé à répondre à cette proposition');
        }

        // Vérifier que la proposition est en attente
        if ($proposition->getStatut() !== 'en_attente') {
            throw new BadRequestHttpException('Cette proposition a déjà reçu une réponse');
        }

        $voyage = $proposition->getVoyage();
        $demande = $proposition->getDemande();
        $client = $proposition->getClient();

        if ($dto->action === 'accepter') {
            // Accepter la proposition actuelle
            $proposition->setStatut('acceptee');
            $proposition->setReponduAt(new \DateTimeImmutable());

            // Conversion DECIMAL → float
            $poidsDisponible = (float) $voyage->getPoidsDisponibleRestant();
            $poidsDemande = (float) $demande->getPoidsEstime();

            // Calcul et prévention des valeurs négatives
            $newVoyagePoids = max(0, $poidsDisponible - $poidsDemande);
            $voyage->setPoidsDisponibleRestant(number_format($newVoyagePoids, 2, '.', ''));

            // Marquer le voyage comme complet si plus de place
            if ($newVoyagePoids == 0.0) {
                $voyage->setStatut('complete');
            }

            // Marquer la demande comme satisfaite
            $demande->setStatut('voyageur_trouve');

            // Annuler toutes les autres propositions de cette même demande
            foreach ($demande->getPropositions() as $autreProposition) {
                if ($autreProposition->getId() !== $proposition->getId() && $autreProposition->getStatut() === 'en_attente') {
                    $autreProposition->setStatut('annulee');
                    $autreProposition->setReponduAt(new \DateTimeImmutable());

                    // Notifier le voyageur concerné
                    $this->notificationService->createNotification(
                        $autreProposition->getVoyageur(),
                        'proposition_annulee',
                        'Proposition annulée',
                        sprintf(
                            'La demande de %s %s a déjà trouvé un voyageur pour le voyage %s vers %s. Votre proposition a été automatiquement annulée.',
                            $demande->getClient()->getPrenom(),
                            $demande->getClient()->getNom(),
                            $autreProposition->getVoyage()->getVilleDepart(),
                            $autreProposition->getVoyage()->getVilleArrivee()
                        ),
                        [
                            'propositionId' => $autreProposition->getId(),
                            'demandeId' => $demande->getId(),
                        ]
                    );

                    try {
                        $this->notifier->publishToUser(
                            $autreProposition->getVoyageur(),
                            [
                                'title' => 'Proposition annulée',
                                'message' => sprintf(
                                    'Votre proposition N°%d liée à la demande N°%d a été annulée.',
                                    $autreProposition->getId(),
                                    $demande->getId()
                                ),
                                'propositionId' => $autreProposition->getId(),
                                'demandeId' => $demande->getId(),
                            ],
                            EventType::PROPOSITION_CANCELLED
                        );
                    } catch (\JsonException $e) {
                        $this->logger->error('Échec de la publication de PROPOSITION_CANCELLED', [
                            'proposition_id' => $autreProposition->getId(),
                            'error' => $e->getMessage(),
                        ]);
                    }

                }
            }

            // Notifier le client dont la proposition a été acceptée
            $this->notificationService->createNotification(
                $proposition->getClient(),
                'proposition_acceptee',
                'Proposition acceptée',
                sprintf(
                    '%s %s a accepté votre proposition pour le voyage %s vers %s.',
                    $voyageur->getPrenom(),
                    $voyageur->getNom(),
                    $voyage->getVilleDepart(),
                    $voyage->getVilleArrivee()
                ),
                [
                    'propositionId' => $proposition->getId(),
                    'voyageId' => $voyage->getId(),
                ]
            );

            try {
                // 1. Notifie le client (mise à jour de l’interface)
                $this->notifier->publishToUser(
                    $client,
                    [
                        'title' => 'Proposition acceptée',
                        'message' => sprintf(
                            'Votre proposition N°%d a été acceptée par le voyageur.',
                            $propositionId
                        ),
                        'propositionId' => $propositionId,
                        'statut' => 'acceptee',
                    ],
                    EventType::PROPOSITION_ACCEPTED
                );

                // 2. Notifie le voyageur (synchronisation de l’interface)
                $this->notifier->publishToUser(
                    $voyageur,
                    [
                        'title' => 'Proposition acceptée',
                        'message' => sprintf(
                            'Vous avez accepté la proposition N°%d.',
                            $propositionId
                        ),
                        'propositionId' => $propositionId,
                        'statut' => 'acceptee',
                    ],
                    EventType::PROPOSITION_ACCEPTED
                );

                // 3. Notifie les administrateurs (mise à jour des statistiques)
                $this->notifier->publishToGroup(
                    'admin',
                    [
                        'title' => 'Proposition acceptée',
                        'message' => sprintf(
                            'La proposition N°%d a été acceptée. Les statistiques doivent être actualisées.',
                            $propositionId
                        ),
                    ],
                    EventType::ADMIN_STATS_UPDATED
                );

                // 4. Notifie le topic du Voyage (mise à jour du poids/statut)
                $this->notifier->publishVoyages(
                    [
                        'title' => 'Voyage mis à jour',
                        'message' => sprintf(
                            'Le voyage N°%d a été mis à jour suite à l’acceptation d’une proposition.',
                            $voyage->getId()
                        ),
                        'voyageId' => $voyage->getId(),
                        'poidsRestant' => $voyage->getPoidsDisponibleRestant(),
                        'statut' => $voyage->getStatut(),
                    ],
                    EventType::VOYAGE_UPDATED
                );

                // 2️⃣ Notifie le flux global des demandes (par cohérence des statuts)
                $this->notifier->publishDemandes(
                    [
                        'title' => 'Demande mise à jour',
                        'message' => sprintf(
                            'Le statut de la demande N°%d a changé suite à l’acceptation d’une proposition.',
                            $demande->getId()
                        ),
                        'demandeId' => $demande->getId(),
                        'statut' => $demande->getStatut(),
                    ],
                    EventType::DEMANDE_STATUT_UPDATED
                );

            } catch (\JsonException $e) {
                $this->logger->error('Échec de la publication de PROPOSITION_ACCEPTED (batch)', [
                    'proposition_id' => $propositionId,
                    'error' => $e->getMessage(),
                ]);
            }


        } else {
            // Refus
            $proposition->setStatut('refusee');
            $proposition->setMessageRefus($dto->messageRefus);
            $proposition->setReponduAt(new \DateTimeImmutable());

            // Notifier le client
            $this->notificationService->createNotification(
                $proposition->getClient(),
                'proposition_refusee',
                'Proposition refusée',
                sprintf(
                    '%s %s a refusé votre proposition pour le voyage %s vers %s.',
                    $voyageur->getPrenom(),
                    $voyageur->getNom(),
                    $voyage->getVilleDepart(),
                    $voyage->getVilleArrivee()
                ),
                [
                    'propositionId' => $proposition->getId(),
                    'voyageId' => $voyage->getId(),
                ]
            );

            try {
                // Notifier le client (Mise à jour UI)
                $this->notifier->publishToUser(
                    $client,
                    [
                        'message' => 'Votre proposition a été refusée',
                        'propositionId' => $propositionId,
                        'statut' => 'refusee'
                    ],
                    EventType::PROPOSITION_REJECTED
                );

                // Notifier l'acteur (le voyageur) pour synchro UI
                $this->notifier->publishToUser(
                    $voyageur,
                    [
                        'title' => 'Proposition refusée',
                        'message' => sprintf(
                            'Vous avez refusé la proposition N°%d.',
                            $propositionId
                        ),
                        'propositionId' => $propositionId,
                        'statut' => 'refusee',
                    ],
                    EventType::PROPOSITION_REJECTED
                );
            } catch (\JsonException $e) {
                $this->logger->error('Failed to publish PROPOSITION_REJECTED', [
                    'proposition_id' => $propositionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        return $proposition;
    }

    /**
     * Récupérer les propositions pour un voyage
     */
    public function getPropositionsByVoyage(int $voyageId): array
    {
        return $this->propositionRepository->findByVoyage($voyageId);
    }

    /**
     * Récupérer les propositions acceptées pour un voyage
     */
    public function getAcceptedPropositionsByVoyage(int $voyageId): array
    {
        return $this->propositionRepository->findAcceptedByVoyage($voyageId);
    }

    /**
     * Récupérer les propositions faites par un client
     */
    public function getPropositionsByClient(int $clientId): array
    {
        return $this->propositionRepository->findByClient($clientId);
    }

    /**
     * Récupérer les propositions reçues par un voyageur
     */
    public function getPropositionsByVoyageur(int $voyageurId): array
    {
        return $this->propositionRepository->findByVoyageur($voyageurId);
    }

    /**
     * Compter les propositions en attente pour un voyageur
     */
    public function countPendingByVoyageur(int $voyageurId): int
    {
        return $this->propositionRepository->countPendingByVoyageur($voyageurId);
    }

    /**
     * Convertir les montants d'une proposition dans une devise cible
     */
    public function convertPropositionAmounts(Proposition $proposition, string $targetCurrency): array
    {
        $result = [
            'originalCurrency' => $proposition->getCurrency(),
            'targetCurrency' => $targetCurrency,
        ];

        $result['prixParKilo'] = $this->currencyService->convert(
            (float) $proposition->getPrixParKilo(),
            $proposition->getCurrency(),
            $targetCurrency
        );
        $result['prixParKiloFormatted'] = $this->currencyService->formatAmount(
            $result['prixParKilo'],
            $targetCurrency
        );

        $result['commission'] = $this->currencyService->convert(
            (float) $proposition->getCommissionProposeePourUnBagage(),
            $proposition->getCurrency(),
            $targetCurrency
        );
        $result['commissionFormatted'] = $this->currencyService->formatAmount(
            $result['commission'],
            $targetCurrency
        );

        return $result;
    }

    /**
     * Obtenir le récapitulatif d'une proposition avec conversion
     * Utile pour afficher au voyageur les montants dans sa devise
     */
    public function getPropositionSummaryWithConversion(Proposition $proposition, User $viewer): array
    {
        $viewerCurrency = $viewer->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        $summary = [
            'id' => $proposition->getId(),
            'statut' => $proposition->getStatut(),
            'message' => $proposition->getMessage(),
            'messageRefus' => $proposition->getMessageRefus(),
            'createdAt' => $proposition->getCreatedAt(),

            // ==================== CLIENT ====================
            'client' => [
                'id' => $proposition->getClient()->getId(),
                'nom' => $proposition->getClient()->getNom(),
                'prenom' => $proposition->getClient()->getPrenom(),
            ],

            // ==================== VOYAGEUR ====================
            'voyageur' => [
                'id' => $proposition->getVoyage()->getVoyageur()->getId(),
                'nom' => $proposition->getVoyage()->getVoyageur()->getNom(),
                'prenom' => $proposition->getVoyage()->getVoyageur()->getPrenom(),
            ],

            // ==================== DEMANDE ====================
            'demande' => [
                'id' => $proposition->getDemande()->getId(),
                'villeDepart' => $proposition->getDemande()->getVilleDepart(),
                'villeArrivee' => $proposition->getDemande()->getVilleArrivee(),
                'poidsEstime' => $proposition->getDemande()->getPoidsEstime()
            ],

            // ==================== VOYAGE ====================
            'voyage' => [
                'id' => $proposition->getVoyage()->getId(),
                'villeDepart' => $proposition->getVoyage()->getVilleDepart(),
                'villeArrivee' => $proposition->getVoyage()->getVilleArrivee(),
                'dateDepart' => $proposition->getVoyage()->getDateDepart(),
            ],

            // ==================== MONTANTS À PLAT (compatible CurrencyDisplay) ====================
            'prixParKilo' => (float) $proposition->getPrixParKilo(),
            'commissionProposeePourUnBagage' => (float) $proposition->getCommissionProposeePourUnBagage(),
            'currency' => $proposition->getCurrency(),
            'viewerCurrency' => $viewerCurrency,
        ];

        // ==================== CONVERSION SI NÉCESSAIRE ====================
        if ($proposition->getCurrency() !== $viewerCurrency) {
            $converted = $this->convertPropositionAmounts($proposition, $viewerCurrency);
            $summary['converted'] = [
                'originalCurrency' => $proposition->getCurrency(),
                'targetCurrency' => $viewerCurrency,
                'prixParKilo' => $converted['prixParKilo'],
                'prixParKiloFormatted' => $converted['prixParKiloFormatted'],
                'commission' => $converted['commission'],
                'commissionFormatted' => $converted['commissionFormatted'],
            ];
        }

        return $summary;
    }
}
