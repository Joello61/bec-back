<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateContactDTO;
use App\Entity\Contact;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class ContactService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactRepository $contactRepository,
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

        $this->entityManager->remove($contact);
        $this->entityManager->flush();
    }
}
