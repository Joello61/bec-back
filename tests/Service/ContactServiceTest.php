<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CreateContactDTO;
use App\Entity\Contact;
use App\Repository\ContactRepository;
use App\Service\ContactService;
use App\Service\RealtimeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Phase 4b, Lot 6 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : wrapper CRUD
 * quasi pur, peu de logique metier propre - seule la gestion de l'id absent sur delete
 * merite un test dedie.
 */
class ContactServiceTest extends TestCase
{
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private ContactRepository&\PHPUnit\Framework\MockObject\MockObject $contactRepository;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private ContactService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new ContactService($this->em, $this->contactRepository, $this->notifier, new NullLogger());
    }

    public function testCreateContactPersistsWithTheProvidedFields(): void
    {
        $dto = new CreateContactDTO();
        $dto->nom = 'Alice';
        $dto->email = 'alice@example.test';
        $dto->sujet = 'Question';
        $dto->message = 'Bonjour, jai une question.';
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Contact::class));
        $this->em->expects(self::once())->method('flush');

        $contact = $this->service->createContact($dto);

        self::assertSame('Alice', $contact->getNom());
        self::assertSame('alice@example.test', $contact->getEmail());
    }

    public function testGetContactDelegatesToTheRepository(): void
    {
        $contact = new Contact();
        $this->contactRepository->method('find')->with(1)->willReturn($contact);

        self::assertSame($contact, $this->service->getContact(1));
    }

    public function testGetAllContactsDelegatesSortedByMostRecent(): void
    {
        $this->contactRepository->expects(self::once())->method('findBy')->with([], ['createdAt' => 'DESC'])->willReturn([]);

        self::assertSame([], $this->service->getAllContacts());
    }

    public function testDeleteContactThrowsWhenNotFound(): void
    {
        $this->contactRepository->method('find')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->deleteContact(999);
    }

    public function testDeleteContactRemovesIt(): void
    {
        $contact = new Contact();
        $contact->setNom('Alice');
        $this->contactRepository->method('find')->willReturn($contact);
        $this->em->expects(self::once())->method('remove')->with($contact);
        $this->em->expects(self::once())->method('flush');

        $this->service->deleteContact(1);
    }
}
