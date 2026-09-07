<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Demande;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;
use App\Service\NotificationService;
use App\Service\RealtimeNotifier;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Phase 4b, Lot 4 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : coeur des
 * preferences utilisateur (canReceiveNotification, dependance transverse a ~6 autres
 * services), et regression IDOR sur markAsRead/deleteNotification corrigee dans le
 * commit precedent (l'appartenance n'etait jamais verifiee).
 */
class NotificationServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private NotificationRepository&\PHPUnit\Framework\MockObject\MockObject $notificationRepository;
    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private DemandeRepository&\PHPUnit\Framework\MockObject\MockObject $demandeRepository;
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private NotificationService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        // Simule le PrePersist Doctrine (setCreatedAtValue()) qu'un vrai flush declencherait -
        // sans ca, createNotification() plante sur getCreatedAt()->format('c') avec createdAt
        // toujours null (l'EntityManager mocke ne declenche jamais le lifecycle callback reel).
        $this->em->method('persist')->willReturnCallback(function ($entity): void {
            if (method_exists($entity, 'setCreatedAtValue')) {
                $entity->setCreatedAtValue();
            }
        });
        $this->notificationRepository = $this->createMock(NotificationRepository::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new NotificationService(
            $this->em,
            $this->notificationRepository,
            $this->voyageRepository,
            $this->demandeRepository,
            $this->userRepository,
            $this->notifier,
            new NullLogger(),
        );
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '-' . uniqid() . '@example.test');
        $user->setNom('Nom');
        $user->setPrenom('Prenom');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, $id);

        return $user;
    }

    private function userWithSettings(int $id): array
    {
        $user = $this->user($id);
        $settings = new UserSettings();
        $settings->setUser($user);
        $user->setSettings($settings);

        return [$user, $settings];
    }

    // ==================== createNotification / canReceiveNotification ====================

    public function testCreateNotificationAllowsByDefaultWithoutSettings(): void
    {
        $user = $this->user(1);

        $result = $this->service->createNotification($user, 'new_message', 'Titre', 'Message');

        self::assertInstanceOf(Notification::class, $result);
    }

    public function testCreateNotificationRespectsNewMessagePreferenceDenied(): void
    {
        [$user, $settings] = $this->userWithSettings(1);
        $settings->setNotifyOnNewMessage(false);

        $result = $this->service->createNotification($user, 'new_message', 'Titre', 'Message');

        self::assertNull($result);
    }

    public function testCreateNotificationRespectsMatchingVoyagePreferenceDenied(): void
    {
        [$user, $settings] = $this->userWithSettings(1);
        $settings->setNotifyOnMatchingVoyage(false);

        self::assertNull($this->service->createNotification($user, 'matching_voyage', 'T', 'M'));
    }

    public function testCreateNotificationRespectsMatchingDemandePreferenceDenied(): void
    {
        [$user, $settings] = $this->userWithSettings(1);
        $settings->setNotifyOnMatchingDemande(false);

        self::assertNull($this->service->createNotification($user, 'matching_demande', 'T', 'M'));
    }

    public function testCreateNotificationRespectsNewAvisPreferenceDenied(): void
    {
        [$user, $settings] = $this->userWithSettings(1);
        $settings->setNotifyOnNewAvis(false);

        self::assertNull($this->service->createNotification($user, 'new_avis', 'T', 'M'));
    }

    public function testCreateNotificationRespectsFavoriUpdatePreferenceDenied(): void
    {
        [$user, $settings] = $this->userWithSettings(1);
        $settings->setNotifyOnFavoriUpdate(false);

        self::assertNull($this->service->createNotification($user, 'favori_update', 'T', 'M'));
    }

    public function testCreateNotificationAllowsAnUnknownSystemTypeRegardlessOfSettings(): void
    {
        [$user, $settings] = $this->userWithSettings(1);
        $settings->setNotifyOnNewMessage(false);
        $settings->setNotifyOnMatchingVoyage(false);
        $settings->setNotifyOnMatchingDemande(false);
        $settings->setNotifyOnNewAvis(false);
        $settings->setNotifyOnFavoriUpdate(false);

        $result = $this->service->createNotification($user, 'account_banned', 'T', 'M');

        self::assertInstanceOf(Notification::class, $result, 'un type systeme non liste (ex. account_banned) doit toujours passer, permissif par defaut');
    }

    // ==================== notifyMatchingDemandes / notifyMatchingVoyages / notifyNewMessage ====================

    public function testNotifyMatchingDemandesCreatesNotificationsForAllowedClients(): void
    {
        $voyageur = $this->user(1);
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        [$client] = $this->userWithSettings(2);
        $demande = new Demande();
        $demande->setClient($client);
        $this->demandeRepository->method('findMatchingVoyage')->willReturn([$demande]);
        $this->notifier->expects(self::once())->method('publishToUser');

        $this->service->notifyMatchingDemandes($voyage);
    }

    public function testNotifyMatchingDemandesSkipsClientsWhoOptedOut(): void
    {
        $voyageur = $this->user(1);
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        [$client, $settings] = $this->userWithSettings(2);
        $settings->setNotifyOnMatchingVoyage(false);
        $demande = new Demande();
        $demande->setClient($client);
        $this->demandeRepository->method('findMatchingVoyage')->willReturn([$demande]);
        $this->notifier->expects(self::never())->method('publishToUser');

        $this->service->notifyMatchingDemandes($voyage);
    }

    public function testNotifyMatchingVoyagesCreatesNotificationsForAllowedVoyageurs(): void
    {
        $client = $this->user(1);
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        [$voyageur] = $this->userWithSettings(2);
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $this->voyageRepository->method('findMatchingDemande')->willReturn([$voyage]);
        $this->notifier->expects(self::once())->method('publishToUser');

        $this->service->notifyMatchingVoyages($demande);
    }

    public function testNotifyMatchingVoyagesSkipsVoyageursWhoOptedOut(): void
    {
        $client = $this->user(1);
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        [$voyageur, $settings] = $this->userWithSettings(2);
        $settings->setNotifyOnMatchingDemande(false);
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $this->voyageRepository->method('findMatchingDemande')->willReturn([$voyage]);
        $this->notifier->expects(self::never())->method('publishToUser');

        $this->service->notifyMatchingVoyages($demande);
    }

    public function testNotifyNewMessageCreatesNotificationWhenAllowed(): void
    {
        $expediteur = $this->user(1);
        [$destinataire] = $this->userWithSettings(2);
        $message = new Message();
        $message->setExpediteur($expediteur);
        $message->setDestinataire($destinataire);
        $message->setContenu('salut');
        $this->notifier->expects(self::once())->method('publishToUser');

        $this->service->notifyNewMessage($message);
    }

    public function testNotifyNewMessageSkipsWhenRecipientOptedOut(): void
    {
        $expediteur = $this->user(1);
        [$destinataire, $settings] = $this->userWithSettings(2);
        $settings->setNotifyOnNewMessage(false);
        $message = new Message();
        $message->setExpediteur($expediteur);
        $message->setDestinataire($destinataire);
        $message->setContenu('salut');
        $this->notifier->expects(self::never())->method('publishToUser');

        $this->service->notifyNewMessage($message);
    }

    // ==================== markAsRead ====================

    public function testMarkAsReadThrowsWhenNotFound(): void
    {
        $this->notificationRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->markAsRead(999, $this->user(1));
    }

    public function testMarkAsReadRejectsANonOwningUser(): void
    {
        $owner = $this->user(1);
        $stranger = $this->user(2);
        $notification = new Notification();
        $notification->setUser($owner);
        $notification->setType('new_message');
        $notification->setTitre('T');
        $notification->setMessage('M');
        $this->notificationRepository->method('find')->willReturn($notification);

        $this->expectException(AccessDeniedException::class);
        $this->service->markAsRead(1, $stranger);
    }

    public function testMarkAsReadIsSilentForAnOrphanNotification(): void
    {
        $notification = new Notification();
        $notification->setType('new_message');
        $notification->setTitre('T');
        $notification->setMessage('M');
        $this->notificationRepository->method('find')->willReturn($notification);
        $this->em->expects(self::never())->method('flush');

        $this->service->markAsRead(1, $this->user(1));

        self::assertFalse($notification->isLue());
    }

    public function testMarkAsReadSucceedsForTheOwner(): void
    {
        $owner = $this->user(1);
        $notification = new Notification();
        $notification->setUser($owner);
        $notification->setType('new_message');
        $notification->setTitre('T');
        $notification->setMessage('M');
        $this->notificationRepository->method('find')->willReturn($notification);
        $this->em->expects(self::once())->method('flush');

        $this->service->markAsRead(1, $owner);

        self::assertTrue($notification->isLue());
    }

    // ==================== markAllAsRead ====================

    public function testMarkAllAsReadDelegatesAndNotifiesWhenUserExists(): void
    {
        $user = $this->user(1);
        $this->notificationRepository->expects(self::once())->method('markAllAsRead')->with(1);
        $this->userRepository->method('find')->willReturn($user);
        $this->notifier->expects(self::once())->method('publishToUser');

        $this->service->markAllAsRead(1);
    }

    public function testMarkAllAsReadStillDelegatesWhenUserIsMissing(): void
    {
        $this->notificationRepository->expects(self::once())->method('markAllAsRead')->with(999);
        $this->userRepository->method('find')->willReturn(null);
        $this->notifier->expects(self::never())->method('publishToUser');

        $this->service->markAllAsRead(999);
    }

    // ==================== deleteNotification ====================

    public function testDeleteNotificationThrowsWhenNotFound(): void
    {
        $this->notificationRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteNotification(999, $this->user(1));
    }

    public function testDeleteNotificationRejectsANonOwningUser(): void
    {
        $owner = $this->user(1);
        $stranger = $this->user(2);
        $notification = new Notification();
        $notification->setUser($owner);
        $notification->setType('new_message');
        $notification->setTitre('T');
        $notification->setMessage('M');
        $this->notificationRepository->method('find')->willReturn($notification);
        $this->em->expects(self::never())->method('remove');

        $this->expectException(AccessDeniedException::class);
        $this->service->deleteNotification(1, $stranger);
    }

    public function testDeleteNotificationSucceedsForTheOwner(): void
    {
        $owner = $this->user(1);
        $notification = new Notification();
        $notification->setUser($owner);
        $notification->setType('new_message');
        $notification->setTitre('T');
        $notification->setMessage('M');
        $this->notificationRepository->method('find')->willReturn($notification);
        $this->em->expects(self::once())->method('remove')->with($notification);
        $this->em->expects(self::once())->method('flush');

        $this->service->deleteNotification(1, $owner);
    }

    // ==================== notifyUserBanned ====================

    public function testNotifyUserBannedCreatesANotification(): void
    {
        $user = $this->user(1);
        $admin = $this->user(2);
        $this->notifier->expects(self::once())->method('publishToUser');

        $this->service->notifyUserBanned($user, $admin, 'spam');
    }
}
