<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateDemandeDTO;
use App\DTO\UpdateDemandeDTO;
use App\Entity\Demande;
use App\Entity\User;
use App\Repository\DemandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class DemandeService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DemandeRepository $demandeRepository,
        private NotificationService $notificationService,
        private MatchingService $matchingService
    ) {}

    public function getPaginatedDemandes(int $page, int $limit, array $filters = []): array
    {
        return $this->demandeRepository->findPaginated($page, $limit, $filters);
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
        $demande = new Demande();
        $demande->setClient($user)
            ->setVilleDepart($dto->villeDepart)
            ->setVilleArrivee($dto->villeArrivee)
            ->setDateLimite($dto->dateLimite)
            ->setPoidsEstime((string) $dto->poidsEstime)
            ->setPrixParKilo($dto->prixParKilo ? (string) $dto->prixParKilo : null)
            ->setCommissionProposeePourUnBagage($dto->commissionProposeePourUnBagage ? (string) $dto->commissionProposeePourUnBagage : null)
            ->setDescription($dto->description)
            ->setStatut('en_recherche');

        $this->entityManager->persist($demande);
        $this->entityManager->flush();

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

        $this->entityManager->flush();

        return $demande;
    }

    public function updateStatut(int $id, string $statut): Demande
    {
        $demande = $this->getDemande($id);
        $demande->setStatut($statut);
        $this->entityManager->flush();

        return $demande;
    }

    public function deleteDemande(int $id): void
    {
        $demande = $this->getDemande($id);
        $demande->setStatut('annulee');
        $this->entityManager->flush();
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
        return $this->matchingService->findBestMatchesVoyages($demande, $viewer);
    }
}
