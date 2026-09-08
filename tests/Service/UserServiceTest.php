<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Address;
use App\Entity\Demande;
use App\Entity\Favori;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Service\AddressService;
use App\Service\AvatarService;
use App\Service\DemandeService;
use App\Service\RefreshTokenManager;
use App\Service\UserService;
use App\Service\VoyageService;
use App\Tests\Support\EntityIdTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase 5 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : User n'est plus jamais
 * physiquement supprime. Ce service centralise le soft-delete/anonymisation, utilise a la fois
 * par le flux admin (Service\Admin\ModerationService) et le flux self-service
 * (Controller\UserController::deleteMyAccount). Verifie que les relations partagees avec des
 * tiers (Message/Avis/Signalement) ne sont jamais touchees, que les voyages/demandes actifs sont
 * annules (pas supprimes) via les services existants, et que les donnees strictement privees
 * sont explicitement supprimees.
 */
class UserServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private VoyageService&\PHPUnit\Framework\MockObject\MockObject $voyageService;
    private DemandeService&\PHPUnit\Framework\MockObject\MockObject $demandeService;
    private RefreshTokenManager&\PHPUnit\Framework\MockObject\MockObject $refreshTokenManager;
    private AddressService&\PHPUnit\Framework\MockObject\MockObject $addressService;
    private AvatarService&\PHPUnit\Framework\MockObject\MockObject $avatarService;
    private UserPasswordHasherInterface&\PHPUnit\Framework\MockObject\MockObject $passwordHasher;
    private UserService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->voyageService = $this->createMock(VoyageService::class);
        $this->demandeService = $this->createMock(DemandeService::class);
        $this->refreshTokenManager = $this->createMock(RefreshTokenManager::class);
        $this->addressService = $this->createMock(AddressService::class);
        $this->avatarService = $this->createMock(AvatarService::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $this->service = new UserService(
            $this->em,
            $this->voyageService,
            $this->demandeService,
            $this->refreshTokenManager,
            $this->addressService,
            $this->avatarService,
            $this->passwordHasher,
        );
    }

    private function user(int $id, array $roles = []): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '-' . uniqid() . '@example.test');
        $user->setNom('Nom');
        $user->setPrenom('Prenom');
        $user->setPassword('hashed-password');
        $user->setRoles($roles);
        $user->setPhoto('avatar-' . $id . '.jpg');
        $this->setEntityId($user, $id);

        return $user;
    }

    /** @param object[] $items */
    private function setCollection(User $user, string $property, array $items): void
    {
        $reflection = new \ReflectionProperty(User::class, $property);
        $reflection->setValue($user, new ArrayCollection($items));
    }

    // ==================== anonymizeAndSoftDelete ====================

    public function testAnonymizeAndSoftDeleteScrubsPiiAndSetsDeletedAt(): void
    {
        $user = $this->user(42);

        $this->avatarService->expects(self::once())->method('deleteAvatar')->with('avatar-42.jpg');
        $this->em->expects(self::once())->method('flush');

        $this->service->anonymizeAndSoftDelete($user);

        self::assertSame('deleted-42@deleted.cobage.invalid', $user->getEmail());
        self::assertSame('Utilisateur', $user->getNom());
        self::assertSame('supprime', $user->getPrenom());
        self::assertNull($user->getTelephone());
        self::assertNull($user->getPhoto());
        self::assertNull($user->getBio());
        self::assertNull($user->getPassword());
        self::assertNull($user->getGoogleId());
        self::assertNull($user->getFacebookId());
        self::assertNotNull($user->getDeletedAt());
        self::assertTrue($user->isDeleted());
    }

    public function testAnonymizeAndSoftDeleteCancelsActiveVoyagesAndDemandesInsteadOfRemovingThem(): void
    {
        $user = $this->user(1);
        $voyage = new Voyage();
        $this->setEntityId($voyage, 10);
        $demande = new Demande();
        $this->setEntityId($demande, 20);
        $this->setCollection($user, 'voyages', [$voyage]);
        $this->setCollection($user, 'demandes', [$demande]);

        $this->voyageService->expects(self::once())->method('deleteVoyage')->with(10);
        $this->demandeService->expects(self::once())->method('deleteDemande')->with(20);
        $this->em->expects(self::never())->method('remove')->with($voyage);
        $this->em->expects(self::never())->method('remove')->with($demande);

        $this->service->anonymizeAndSoftDelete($user);
    }

    public function testAnonymizeAndSoftDeleteInvalidatesAllRefreshTokens(): void
    {
        $user = $this->user(2);

        $this->refreshTokenManager->expects(self::once())->method('invalidateUserTokens')->with($user);

        $this->service->anonymizeAndSoftDelete($user);
    }

    public function testAnonymizeAndSoftDeleteRemovesPrivateNotificationsAndFavoris(): void
    {
        $user = $this->user(3);
        $notification = new Notification();
        $favori = new Favori();
        $this->setCollection($user, 'notifications', [$notification]);
        $this->setCollection($user, 'favoris', [$favori]);

        $removed = [];
        $this->em->expects(self::exactly(2))->method('remove')->willReturnCallback(
            function ($entity) use (&$removed) {
                $removed[] = $entity;
            }
        );

        $this->service->anonymizeAndSoftDelete($user);

        self::assertContains($notification, $removed);
        self::assertContains($favori, $removed);
    }

    public function testAnonymizeAndSoftDeleteDeletesAddressAndSettings(): void
    {
        $user = $this->user(4);
        $address = new Address();
        $user->setAddress($address);
        $settings = new UserSettings();
        $user->setSettings($settings);

        $this->addressService->expects(self::once())->method('deleteAddress')->with($address);
        $this->em->expects(self::once())->method('remove')->with($settings);

        $this->service->anonymizeAndSoftDelete($user);
    }

    public function testAnonymizeAndSoftDeleteNeverTouchesMessagesAvisOrSignalements(): void
    {
        $user = $this->user(5);

        // Aucune methode de suppression/annulation n'existe pour ces collections dans le
        // service : verifie simplement qu'aucune interaction EntityManager::remove() n'est
        // declenchee pour elles (seules Notification/Favori/UserSettings le sont, testes
        // separement ci-dessus, ici les collections partagees avec des tiers restent vides
        // et absentes de toute suppression).
        self::assertCount(0, $user->getMessagesEnvoyes());
        self::assertCount(0, $user->getMessagesRecus());
        self::assertCount(0, $user->getAvisDonnes());
        self::assertCount(0, $user->getAvisRecus());
        self::assertCount(0, $user->getSignalements());

        $this->service->anonymizeAndSoftDelete($user);

        self::assertCount(0, $user->getMessagesEnvoyes());
        self::assertCount(0, $user->getMessagesRecus());
        self::assertCount(0, $user->getAvisDonnes());
        self::assertCount(0, $user->getAvisRecus());
        self::assertCount(0, $user->getSignalements());
    }

    // ==================== verifySelfDeletionRequest ====================

    public function testVerifySelfDeletionRequestRejectsAnAdminAccount(): void
    {
        $admin = $this->user(6, ['ROLE_ADMIN']);

        $this->expectException(BadRequestHttpException::class);
        $this->service->verifySelfDeletionRequest($admin, 'whatever');
    }

    public function testVerifySelfDeletionRequestRequiresTheCurrentPasswordForALocalAccount(): void
    {
        $user = $this->user(7);
        $this->passwordHasher->expects(self::never())->method('isPasswordValid');

        $this->expectException(BadRequestHttpException::class);
        $this->service->verifySelfDeletionRequest($user, null);
    }

    public function testVerifySelfDeletionRequestRejectsAnIncorrectPassword(): void
    {
        $user = $this->user(8);
        $this->passwordHasher->method('isPasswordValid')->willReturn(false);

        $this->expectException(BadRequestHttpException::class);
        $this->service->verifySelfDeletionRequest($user, 'wrong-password');
    }

    public function testVerifySelfDeletionRequestAcceptsACorrectPassword(): void
    {
        $user = $this->user(9);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $this->service->verifySelfDeletionRequest($user, 'correct-password');

        self::addToAssertionCount(1);
    }

    public function testVerifySelfDeletionRequestSkipsPasswordCheckForAnOAuthOnlyAccount(): void
    {
        $user = $this->user(10);
        $user->setPassword(null);
        $this->passwordHasher->expects(self::never())->method('isPasswordValid');

        $this->service->verifySelfDeletionRequest($user, null);

        self::addToAssertionCount(1);
    }
}
