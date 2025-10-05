<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateAvisDTO;
use App\Entity\Avis;
use App\Entity\User;
use App\Repository\AvisRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class AvisService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AvisRepository $avisRepository,
        private UserRepository $userRepository,
        private VoyageRepository $voyageRepository,
        private NotificationService $notificationService
    ) {}

    public function createAvis(CreateAvisDTO $dto, User $auteur): Avis
    {
        $cible = $this->userRepository->find($dto->cibleId);
        if (!$cible) {
            throw new NotFoundHttpException('Utilisateur cible non trouvé');
        }

        // On ne peut pas laisser un avis à soi-même
        if ($cible === $auteur) {
            throw new BadRequestHttpException('Vous ne pouvez pas vous laisser un avis à vous-même');
        }

        $voyage = null;
        if ($dto->voyageId) {
            $voyage = $this->voyageRepository->find($dto->voyageId);
            if (!$voyage) {
                throw new NotFoundHttpException('Voyage non trouvé');
            }
        }

        // Vérifier si un avis existe déjà entre ces deux utilisateurs
        $existingAvis = $this->avisRepository->findByAuteurAndCible($auteur->getId(), $cible->getId());
        if ($existingAvis) {
            throw new BadRequestHttpException('Vous avez déjà laissé un avis à cet utilisateur');
        }

        $avis = new Avis();
        $avis->setAuteur($auteur)
            ->setCible($cible)
            ->setVoyage($voyage)
            ->setNote($dto->note)
            ->setCommentaire($dto->commentaire);

        $this->entityManager->persist($avis);
        $this->entityManager->flush();

        // Notifier l'utilisateur (si ses préférences le permettent)
        $this->notificationService->createNotification(
            $cible,
            'new_avis',
            'Nouvel avis reçu',
            sprintf(
                '%s %s vous a laissé un avis de %d/5',
                $auteur->getPrenom(),
                $auteur->getNom(),
                $dto->note
            ),
            ['avisId' => $avis->getId()]
        );

        return $avis;
    }

    public function updateAvis(int $id, CreateAvisDTO $dto): Avis
    {
        $avis = $this->avisRepository->find($id);

        if (!$avis) {
            throw new NotFoundHttpException('Avis non trouvé');
        }

        $avis->setNote($dto->note)
            ->setCommentaire($dto->commentaire);

        $this->entityManager->flush();

        return $avis;
    }

    public function deleteAvis(int $id): void
    {
        $avis = $this->avisRepository->find($id);

        if (!$avis) {
            throw new NotFoundHttpException('Avis non trouvé');
        }

        $this->entityManager->remove($avis);
        $this->entityManager->flush();
    }

    public function getAvisByUser(int $userId): array
    {
        return $this->avisRepository->findByUser($userId);
    }

    public function getStatsByUser(int $userId): array
    {
        return $this->avisRepository->getStatsByUser($userId);
    }

    public function getAvisByVoyage(int $voyageId): array
    {
        return $this->avisRepository->findByVoyage($voyageId);
    }
}
