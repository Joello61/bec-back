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

        if ($dto->action === 'accepter') {
            // ✅ 1. Accepter la proposition actuelle
            $proposition->setStatut('acceptee');
            $proposition->setReponduAt(new \DateTimeImmutable());

            // ⚙️ Conversion DECIMAL → float
            $poidsDisponible = (float) $voyage->getPoidsDisponibleRestant();
            $poidsDemande = (float) $demande->getPoidsEstime();

            // 💡 Calcul et prévention des valeurs négatives
            $newVoyagePoids = max(0, $poidsDisponible - $poidsDemande);
            $voyage->setPoidsDisponibleRestant(number_format($newVoyagePoids, 2, '.', ''));

            // 🧩 Marquer le voyage comme complet si plus de place
            if ($newVoyagePoids == 0.0) {
                $voyage->setStatut('complete');
            }

            // ✅ 2. Marquer la demande comme satisfaite
            $demande->setStatut('voyageur_trouve');

            // 🔁 3. Annuler toutes les autres propositions de cette même demande
            foreach ($demande->getPropositions() as $autreProposition) {
                if ($autreProposition->getId() !== $proposition->getId() && $autreProposition->getStatut() === 'en_attente') {
                    $autreProposition->setStatut('annulee');
                    $autreProposition->setReponduAt(new \DateTimeImmutable());

                    // 🔔 Notifier le voyageur concerné
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
                }
            }

            // 🔔 4. Notifier le client dont la proposition a été acceptée
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

        } else {
            // ❌ Refus
            $proposition->setStatut('refusee');
            $proposition->setMessageRefus($dto->messageRefus);
            $proposition->setReponduAt(new \DateTimeImmutable());

            // 🔔 Notifier le client
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
