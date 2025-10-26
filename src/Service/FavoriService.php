<?php

declare(strict_types=1);

namespace App\Service;

use App\Constant\EventType;
use App\Entity\Favori;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Repository\FavoriRepository;
use App\Repository\VoyageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

readonly class FavoriService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FavoriRepository $favoriRepository,
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository,
        private RealtimeNotifier $notifier,
        private LoggerInterface $logger,
    ) {}

    public function addVoyageToFavoris(User $user, int $voyageId): Favori
    {
        /* @var Voyage $voyage*/
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

        try {
            $this->notifier->publishToUser(
                $user,
                [
                    'title' => 'Voyage ajouté aux favoris',
                    'message' => sprintf(
                        'Le voyage N°%d a été ajouté à vos favoris.',
                        $voyageId
                    ),
                    'voyageId' => $voyageId,
                    'favoriId' => $favori->getId(),
                ],
                EventType::VOYAGE_FAVORITED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de VOYAGE_FAVORITED', [
                'user_id' => $user->getId(),
                'voyage_id' => $voyageId,
                'error' => $e->getMessage(),
            ]);
        }


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

        try {
            $this->notifier->publishToUser(
                $user,
                [
                    'title' => 'Demande ajoutée aux favoris',
                    'message' => sprintf(
                        'La demande N°%d a été ajoutée à vos favoris.',
                        $demandeId
                    ),
                    'demandeId' => $demandeId,
                    'favoriId' => $favori->getId(),
                ],
                EventType::DEMANDE_FAVORITED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de DEMANDE_FAVORITED', [
                'user_id' => $user->getId(),
                'demande_id' => $demandeId,
                'error' => $e->getMessage(),
            ]);
        }


        return $favori;
    }

    public function removeFromFavoris(int $entityId, string $type, User $user): void
    {
        $payload = [];
        $eventType = '';
        $title = '';
        $message = '';

        if ($type === 'voyage') {
            $favori = $this->favoriRepository->findByUserAndVoyage($user->getId(), $entityId);
            $eventType = EventType::VOYAGE_UNFAVORITED;
            $payload = ['voyageId' => $entityId];
            $title = 'Voyage retiré des favoris';
            $message = sprintf('Le voyage #%d a été retiré de vos favoris.', $entityId);
        } elseif ($type === 'demande') {
            $favori = $this->favoriRepository->findByUserAndDemande($user->getId(), $entityId);
            $eventType = EventType::DEMANDE_UNFAVORITED;
            $payload = ['demandeId' => $entityId];
            $title = 'Demande retirée des favoris';
            $message = sprintf('La demande #%d a été retirée de vos favoris.', $entityId);
        } else {
            throw new BadRequestHttpException('Type invalide. Utilisez "voyage" ou "demande".');
        }

        if (!$favori) {
            throw new NotFoundHttpException('Favori non trouvé.');
        }

        if ($favori->getUser() !== $user) {
            throw new AccessDeniedException('Vous ne pouvez pas supprimer ce favori.');
        }

        $payload['favoriId'] = $favori->getId();
        $payload['title'] = $title;
        $payload['message'] = $message;

        $this->entityManager->remove($favori);
        $this->entityManager->flush();

        try {
            $this->notifier->publishToUser(
                $user,
                $payload,
                $eventType
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de l’événement de suppression de favori', [
                'user_id' => $user->getId(),
                'entity_id' => $entityId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
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
