<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateVoyageDTO;
use App\DTO\UpdateVoyageDTO;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\VoyageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class VoyageService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VoyageRepository $voyageRepository,
        private NotificationService $notificationService
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
        $voyage = new Voyage();
        $voyage->setVoyageur($user)
            ->setVilleDepart($dto->villeDepart)
            ->setVilleArrivee($dto->villeArrivee)
            ->setDateDepart($dto->dateDepart)
            ->setDateArrivee($dto->dateArrivee)
            ->setPoidsDisponible((string) $dto->poidsDisponible)
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
        if ($dto->poidsDisponible !== null) {
            $voyage->setPoidsDisponible((string) $dto->poidsDisponible);
        }
        if ($dto->description !== null) {
            $voyage->setDescription($dto->description);
        }

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
        $voyage->setStatut('annule');
        $this->entityManager->flush();
    }

    public function getVoyagesByUser(int $userId): array
    {
        return $this->voyageRepository->findByVoyageur($userId);
    }
}
