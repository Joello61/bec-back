<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateSignalementDTO;
use App\Entity\Signalement;
use App\Entity\User;
use App\Repository\{SignalementRepository, VoyageRepository, DemandeRepository, MessageRepository, UserRepository};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Constant\EventType;
use Psr\Log\LoggerInterface;

readonly class SignalementService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SignalementRepository $signalementRepository,
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository,
        private MessageRepository $messageRepository,
        private UserRepository $userRepository,
        private RealtimeNotifier $notifier,
        private LoggerInterface $logger
    ) {}

    public function createSignalement(CreateSignalementDTO $dto, User $signaleur): Signalement
    {
        if (!$dto->voyageId && !$dto->demandeId && !$dto->messageId && !$dto->utilisateurSignaleId) {
            throw new BadRequestHttpException('Vous devez signaler un voyage, une demande, un message ou un utilisateur.');
        }

        $voyage = $dto->voyageId ? $this->voyageRepository->find($dto->voyageId) : null;
        $demande = $dto->demandeId ? $this->demandeRepository->find($dto->demandeId) : null;
        $message = $dto->messageId ? $this->messageRepository->find($dto->messageId) : null;
        $utilisateurSignale = $dto->utilisateurSignaleId ? $this->userRepository->find($dto->utilisateurSignaleId) : null;

        if ($utilisateurSignale && $utilisateurSignale->getId() === $signaleur->getId()) {
            throw new BadRequestHttpException('Vous ne pouvez pas vous signaler vous-même.');
        }

        $signalement = (new Signalement())
            ->setSignaleur($signaleur)
            ->setVoyage($voyage)
            ->setDemande($demande)
            ->setMessage($message)
            ->setUtilisateurSignale($utilisateurSignale)
            ->setMotif($dto->motif)
            ->setDescription($dto->description)
            ->setStatut('en_attente');

        $this->entityManager->persist($signalement);
        $this->entityManager->flush();

        // Notifier admin
        try {
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Nouveau signalement reçu',
                    'message' => sprintf(
                        'Un nouveau signalement (ID: %d) a été créé par %s %s.',
                        $signalement->getId(),
                        $signaleur->getPrenom(),
                        $signaleur->getNom()
                    ),
                    'signalementId' => $signalement->getId(),
                ],
                EventType::SIGNALEMENT_CREATED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication SIGNALEMENT_CREATED', [
                'signalement_id' => $signalement->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $signalement;
    }

    public function processSignalement(int $id, string $statut, ?string $reponseAdmin): Signalement
    {
        $signalement = $this->signalementRepository->find($id);
        if (!$signalement) {
            throw new NotFoundHttpException('Signalement non trouvé');
        }

        if (!in_array($statut, ['traite', 'rejete'], true)) {
            throw new BadRequestHttpException('Statut invalide');
        }

        $signalement->setStatut($statut)->setReponseAdmin($reponseAdmin);
        $this->entityManager->flush();

        try {
            $eventType = $statut === 'traite' ? EventType::SIGNALEMENT_HANDLED : EventType::SIGNALEMENT_REJECTED;

            $this->notifier->publishToUser(
                $signalement->getSignaleur(),
                [
                    'title' => 'Signalement mis à jour',
                    'message' => sprintf(
                        'Votre signalement #%d a été %s.',
                        $signalement->getId(),
                        $statut
                    ),
                    'signalementId' => $signalement->getId(),
                    'statut' => $statut,
                ],
                $eventType
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication SIGNALEMENT_HANDLED/REJECTED', [
                'signalement_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        return $signalement;
    }

    public function getUserSignalements(User $user, int $page, int $limit, ?string $statut): array
    {
        return $this->signalementRepository->findUserSignalementPaginated($user, $page, $limit, $statut);
    }

    public function getAllSignalements(int $page, int $limit, ?string $statut): array
    {
        return $this->signalementRepository->findPaginated($page, $limit, $statut);
    }

    public function countPending(): int
    {
        return $this->signalementRepository->countEnAttente();
    }
}
