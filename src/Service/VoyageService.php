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

readonly class VoyageService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VoyageRepository $voyageRepository,
        private NotificationService $notificationService,
        private MatchingService $matchingService,
        private CurrencyService $currencyService
    ) {}

    public function getPaginatedVoyages(int $page, int $limit, array $filters = []): array
    {
        return $this->voyageRepository->findPaginated($page, $limit, $filters);
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

        return $voyage;
    }

    public function updateStatut(int $id, string $statut): Voyage
    {
        $voyage = $this->getVoyage($id);
        $voyage->setStatut($statut);
        $this->entityManager->flush();

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
            }
        }

        // 4️⃣ Persister les changements
        $this->entityManager->flush();
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
