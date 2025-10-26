<?php

declare(strict_types=1);

namespace App\Service;

use App\Constant\EventType;
use App\DTO\CreateContactDTO;
use App\Entity\Contact;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

readonly class ContactService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactRepository $contactRepository,
        private RealtimeNotifier $notifier,
        private LoggerInterface $logger,
    ) {}

    public function createContact(CreateContactDTO $dto): Contact
    {
        $contact = new Contact();
        $contact->setNom($dto->nom);
        $contact->setEmail($dto->email);
        $contact->setSujet($dto->sujet);
        $contact->setMessage($dto->message);

        $this->entityManager->persist($contact);
        $this->entityManager->flush();

        try {
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Nouveau message de contact reçu',
                    'message' => sprintf(
                        'Un nouveau message a été envoyé via le formulaire de contact par %s (%s). Sujet : %s.',
                        $contact->getNom(),
                        $contact->getEmail(),
                        $contact->getSujet()
                    ),
                    'contactId' => $contact->getId(),
                    'nom' => $contact->getNom(),
                    'email' => $contact->getEmail(),
                    'sujet' => $contact->getSujet(),
                ],
                EventType::CONTACT_FORM_SUBMITTED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de CONTACT_FORM_SUBMITTED', [
                'contact_id' => $contact->getId(),
                'error' => $e->getMessage(),
            ]);
        }


        return $contact;
    }

    public function getContact(int $id): ?Contact
    {
        return $this->contactRepository->find($id);
    }

    public function getAllContacts(): array
    {
        return $this->contactRepository->findBy([], ['createdAt' => 'DESC']);
    }

    public function deleteContact(int $id): void
    {
        $contact = $this->contactRepository->find($id);

        if (!$contact) {
            throw new \InvalidArgumentException('Contact non trouvé');
        }

        $contactId = $contact->getId();
        $contactNom = $contact->getNom();

        $this->entityManager->remove($contact);
        $this->entityManager->flush();

        try {
            $this->notifier->publishToGroup(
                'admin',
                [
                    'title' => 'Message de contact supprimé',
                    'message' => sprintf(
                        'Le message de contact #%d envoyé par %s a été supprimé.',
                        $contactId,
                        $contactNom
                    ),
                    'contactId' => $contactId,
                    'nom' => $contactNom,
                ],
                EventType::CONTACT_MESSAGE_DELETED
            );
        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de CONTACT_MESSAGE_DELETED', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
        }

    }
}
