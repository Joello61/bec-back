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
use App\Constant\EventType;
use Psr\Log\LoggerInterface;

readonly class AvisService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AvisRepository $avisRepository,
        private UserRepository $userRepository,
        private VoyageRepository $voyageRepository,
        private NotificationService $notificationService,
        private RealtimeNotifier $notifier,
        private LoggerInterface $logger
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

        try {
            // 1. Notifie la personne qui reçoit l'avis
            $this->notifier->publishToUser(
                $avis->getCible(),
                [
                    'title' => 'Nouvel avis reçu',
                    'message' => sprintf(
                        'Vous avez reçu un nouvel avis de %s %s avec une note de %d.',
                        $avis->getAuteur()?->getNom(),
                        $avis->getAuteur()?->getPrenom(),
                        $avis->getNote()
                    ),
                    'avisId' => $avis->getId(),
                    'note' => $avis->getNote(),
                    'auteur' => $avis->getAuteur()?->getNom() . ' ' . $avis->getAuteur()?->getPrenom(),
                ],
                EventType::AVIS_CREATED
            );

            // 2. Notifie les administrateurs
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Nouvel avis ajouté',
                    'message' => sprintf(
                        'Un nouvel avis (ID: %d) a été créé par %s %s pour l’utilisateur %s %s avec une note de %d.',
                        $avis->getId(),
                        $avis->getAuteur()?->getNom(),
                        $avis->getAuteur()?->getPrenom(),
                        $avis->getCible()?->getNom(),
                        $avis->getCible()?->getPrenom(),
                        $avis->getNote()
                    ),
                    'avisId' => $avis->getId(),
                    'note' => $avis->getNote(),
                    'auteurId' => $auteur->getId(),
                    'cibleId' => $cible->getId(),
                ],
                EventType::AVIS_CREATED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de AVIS_CREATED', [
                'avis_id' => $avis->getId(),
                'error' => $e->getMessage(),
            ]);
        }


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
        /** @var Avis $avis */
        $avis = $this->avisRepository->find($id);

        if (!$avis) {
            throw new NotFoundHttpException('Avis non trouvé');
        }

        $avis->setNote($dto->note)
            ->setCommentaire($dto->commentaire);

        $this->entityManager->flush();

        try {
            // 1. Notifie la personne concernée
            $this->notifier->publishToUser(
                $avis->getCible(),
                [
                    'title' => 'Avis mis à jour',
                    'message' => sprintf(
                        'Un avis que vous avez reçu a été mis à jour. Nouvelle note : %d.',
                        $avis->getNote()
                    ),
                    'avisId' => $avis->getId(),
                    'note' => $avis->getNote(),
                ],
                EventType::AVIS_UPDATED
            );

            // 2. Notifie les administrateurs
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Avis modifié',
                    'message' => sprintf(
                        'L’avis (ID: %d) a été modifié. Nouvelle note : %d.',
                        $avis->getId(),
                        $avis->getNote()
                    ),
                    'avisId' => $avis->getId(),
                    'note' => $avis->getNote(),
                ],
                EventType::AVIS_UPDATED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de AVIS_UPDATED', [
                'avis_id' => $avis->getId(),
                'error' => $e->getMessage(),
            ]);
        }


        return $avis;
    }

    public function deleteAvis(int $id): void
    {
        /** @var Avis $avis */
        $avis = $this->avisRepository->find($id);

        if (!$avis) {
            throw new NotFoundHttpException('Avis non trouvé');
        }

        $avisId = $avis->getId();
        $cible = $avis->getCible();

        $this->entityManager->remove($avis);
        $this->entityManager->flush();

        try {
            // 1. Notifie la personne concernée
            $this->notifier->publishToUser(
                $cible,
                [
                    'title' => 'Avis supprimé',
                    'message' => 'Un avis que vous aviez reçu a été supprimé.',
                    'avisId' => $avisId,
                ],
                EventType::AVIS_DELETED
            );

            // 2. Notifie les administrateurs
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Avis supprimé',
                    'message' => sprintf('L’avis (ID: %d) a été supprimé.', $avisId),
                    'avisId' => $avisId,
                ],
                EventType::AVIS_DELETED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de AVIS_DELETED', [
                'avis_id' => $avisId,
                'error' => $e->getMessage(),
            ]);
        }

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
