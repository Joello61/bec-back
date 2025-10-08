<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Favori;
use App\Entity\User;
use App\Repository\DemandeRepository;
use App\Repository\FavoriRepository;
use App\Repository\VoyageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

readonly class FavoriService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FavoriRepository $favoriRepository,
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository
    ) {}

    public function addVoyageToFavoris(User $user, int $voyageId): Favori
    {
        $voyage = $this->voyageRepository->find($voyageId);

        if (!$voyage) {
            throw new NotFoundHttpException('Voyage non trouvé');
        }

        // Vérifier si déjà en favori
        $existing = $this->favoriRepository->findByUserAndVoyage($user->getId(), $voyageId);
        if ($existing) {
            throw new BadRequestHttpException('Ce voyage est déjà dans vos favoris');
        }

        $favori = new Favori();
        $favori->setUser($user)
            ->setVoyage($voyage);

        $this->entityManager->persist($favori);
        $this->entityManager->flush();

        return $favori;
    }

    public function addDemandeToFavoris(User $user, int $demandeId): Favori
    {
        $demande = $this->demandeRepository->find($demandeId);

        if (!$demande) {
            throw new NotFoundHttpException('Demande non trouvée');
        }

        // Vérifier si déjà en favori
        $existing = $this->favoriRepository->findByUserAndDemande($user->getId(), $demandeId);
        if ($existing) {
            throw new BadRequestHttpException('Cette demande est déjà dans vos favoris');
        }

        $favori = new Favori();
        $favori->setUser($user)
            ->setDemande($demande);

        $this->entityManager->persist($favori);
        $this->entityManager->flush();

        return $favori;
    }

    public function removeFromFavoris(int $entityId, string $type, User $user): void
    {

        if ($type === 'voyage') {
            $favori = $this->favoriRepository->findByUserAndVoyage($user->getId(), $entityId);
        } elseif ($type === 'demande') {
            $favori = $this->favoriRepository->findByUserAndDemande($user->getId(), $entityId);
        } else {
            throw new BadRequestHttpException('Type invalide. Utilisez "voyage" ou "demande"');
        }

        if (!$favori) {
            throw new NotFoundHttpException('Favori non trouvé');
        }

        if ($favori->getUser() !== $user) {
            throw new AccessDeniedException('Vous ne pouvez pas supprimer ce favori');
        }

        $this->entityManager->remove($favori);
        $this->entityManager->flush();
    }

    public function getUserFavoris(int $userId): array
    {
        return $this->favoriRepository->findByUser($userId);
    }

    public function getUserFavorisVoyages(int $userId): array
    {
        return $this->favoriRepository->findVoyagesByUser($userId);
    }

    public function getUserFavorisDemandes(int $userId): array
    {
        return $this->favoriRepository->findDemandesByUser($userId);
    }
}
