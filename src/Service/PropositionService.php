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
        $proposition = $this->propositionRepository->find($propositionId);

        if (!$proposition) {
            throw new NotFoundHttpException('Proposition non trouvée');
        }

        // Vérifier que c'est bien le voyageur qui répond
        if ($proposition->getVoyageur() !== $voyageur) {
            throw new BadRequestHttpException('Vous n\'êtes pas autorisé à répondre à cette proposition');
        }

        // Vérifier que la proposition est en attente
        if ($proposition->getStatut() !== 'en_attente') {
            throw new BadRequestHttpException('Cette proposition a déjà reçu une réponse');
        }

        if ($dto->action === 'accepter') {
            $proposition->setStatut('acceptee');
            $proposition->setReponduAt(new \DateTime());

            // Notifier le client
            $this->notificationService->createNotification(
                $proposition->getClient(),
                'proposition_acceptee',
                'Proposition acceptée',
                sprintf(
                    '%s %s a accepté votre proposition pour le voyage %s vers %s',
                    $voyageur->getPrenom(),
                    $voyageur->getNom(),
                    $proposition->getVoyage()->getVilleDepart(),
                    $proposition->getVoyage()->getVilleArrivee()
                ),
                [
                    'propositionId' => $proposition->getId(),
                    'voyageId' => $proposition->getVoyage()->getId()
                ]
            );
        } else {
            $proposition->setStatut('refusee');
            $proposition->setMessageRefus($dto->messageRefus);
            $proposition->setReponduAt(new \DateTime());

            // Notifier le client
            $this->notificationService->createNotification(
                $proposition->getClient(),
                'proposition_refusee',
                'Proposition refusée',
                sprintf(
                    '%s %s a refusé votre proposition pour le voyage %s vers %s',
                    $voyageur->getPrenom(),
                    $voyageur->getNom(),
                    $proposition->getVoyage()->getVilleDepart(),
                    $proposition->getVoyage()->getVilleArrivee()
                ),
                [
                    'propositionId' => $proposition->getId(),
                    'voyageId' => $proposition->getVoyage()->getId()
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
        $viewerCurrency = $viewer->getSettings()?->getDevise() ?? $this->currencyService->getDefaultCurrency();

        $summary = [
            'id' => $proposition->getId(),
            'statut' => $proposition->getStatut(),
            'message' => $proposition->getMessage(),
            'createdAt' => $proposition->getCreatedAt(),
            'client' => [
                'id' => $proposition->getClient()->getId(),
                'nom' => $proposition->getClient()->getNom(),
                'prenom' => $proposition->getClient()->getPrenom(),
            ],
            'demande' => [
                'id' => $proposition->getDemande()->getId(),
                'villeDepart' => $proposition->getDemande()->getVilleDepart(),
                'villeArrivee' => $proposition->getDemande()->getVilleArrivee(),
            ],
            'voyage' => [
                'id' => $proposition->getVoyage()->getId(),
                'villeDepart' => $proposition->getVoyage()->getVilleDepart(),
                'villeArrivee' => $proposition->getVoyage()->getVilleArrivee(),
            ],
            'montants' => [
                'original' => [
                    'currency' => $proposition->getCurrency(),
                    'prixParKilo' => (float) $proposition->getPrixParKilo(),
                    'commission' => (float) $proposition->getCommissionProposeePourUnBagage(),
                ],
            ],
        ];

        // Ajouter la conversion si la devise du viewer est différente
        if ($proposition->getCurrency() !== $viewerCurrency) {
            $converted = $this->convertPropositionAmounts($proposition, $viewerCurrency);
            $summary['montants']['converted'] = [
                'currency' => $viewerCurrency,
                'prixParKilo' => $converted['prixParKilo'],
                'prixParKiloFormatted' => $converted['prixParKiloFormatted'],
                'commission' => $converted['commission'],
                'commissionFormatted' => $converted['commissionFormatted'],
            ];
        }

        return $summary;
    }
}
