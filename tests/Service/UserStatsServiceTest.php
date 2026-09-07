<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Demande;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\AvisRepository;
use App\Repository\DemandeRepository;
use App\Repository\MessageRepository;
use App\Repository\NotificationRepository;
use App\Repository\VoyageRepository;
use App\Service\UserStatsService;
use App\Service\VisibilityService;
use App\Tests\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 7 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : ce service
 * delegue entierement au repository (count/findBy, tous mockables) et a VisibilityService
 * (deja teste en Phase 4) pour les portes de visibilite - la valeur testee ici est
 * l'assemblage correct du tableau de bord et le respect des 3 portes de visibilite
 * (stats/email/telephone).
 */
class UserStatsServiceTest extends TestCase
{
    use EntityIdTrait;

    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private DemandeRepository&\PHPUnit\Framework\MockObject\MockObject $demandeRepository;
    private AvisRepository&\PHPUnit\Framework\MockObject\MockObject $avisRepository;
    private NotificationRepository&\PHPUnit\Framework\MockObject\MockObject $notificationRepository;
    private MessageRepository&\PHPUnit\Framework\MockObject\MockObject $messageRepository;
    private VisibilityService&\PHPUnit\Framework\MockObject\MockObject $visibilityService;
    private UserStatsService $service;

    protected function setUp(): void
    {
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);
        $this->avisRepository = $this->createMock(AvisRepository::class);
        $this->notificationRepository = $this->createMock(NotificationRepository::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->visibilityService = $this->createMock(VisibilityService::class);

        $this->service = new UserStatsService(
            $this->voyageRepository,
            $this->demandeRepository,
            $this->avisRepository,
            $this->notificationRepository,
            $this->messageRepository,
            $this->visibilityService,
        );

        $this->avisRepository->method('getStatsByUser')->willReturn([
            'total' => 3,
            'average' => 4.3,
            'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 2],
        ]);
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '-' . uniqid() . '@example.test');
        $user->setNom('Nom');
        $user->setPrenom('Prenom');
        $user->setPassword('irrelevant');
        $user->setCreatedAtValue();
        $this->setEntityId($user, $id);

        return $user;
    }

    private function voyage(): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $voyage->setDateArrivee(new \DateTime('+6 days'));
        $voyage->setPoidsDisponible('20');
        $voyage->setStatut('actif');
        $this->setEntityId($voyage, 1);

        return $voyage;
    }

    private function demande(): Demande
    {
        $demande = new Demande();
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        $demande->setPoidsEstime('5');
        $demande->setStatut('en_recherche');
        $this->setEntityId($demande, 1);

        return $demande;
    }

    private function notification(User $user): Notification
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType('info');
        $notification->setTitre('Titre');
        $notification->setMessage('Message');
        $notification->setCreatedAtValue();
        $this->setEntityId($notification, 1);

        return $notification;
    }

    private function message(User $expediteur): Message
    {
        $message = new Message();
        $message->setExpediteur($expediteur);
        $message->setContenu('Bonjour');
        $message->setCreatedAtValue();
        $this->setEntityId($message, 1);

        return $message;
    }

    // ==================== getUserDashboard ====================

    public function testGetUserDashboardAssemblesAllSections(): void
    {
        $user = $this->user(1);
        $this->voyageRepository->method('count')->willReturn(2);
        $this->voyageRepository->method('findBy')->willReturn([$this->voyage()]);
        $this->demandeRepository->method('count')->willReturn(1);
        $this->demandeRepository->method('findBy')->willReturn([$this->demande()]);
        $this->notificationRepository->method('count')->willReturn(3);
        $this->notificationRepository->method('findBy')->willReturn([$this->notification($user)]);
        $this->messageRepository->method('count')->willReturn(4);
        $this->messageRepository->method('findBy')->willReturn([$this->message($user)]);

        $dashboard = $this->service->getUserDashboard($user);

        self::assertSame(2, $dashboard->summary['voyagesActifs']);
        self::assertSame(1, $dashboard->summary['demandesEnCours']);
        self::assertSame(3, $dashboard->summary['notificationsNonLues']);
        self::assertSame(4, $dashboard->summary['messagesNonLus']);
        self::assertCount(1, $dashboard->voyages['recents']);
        self::assertSame('Douala', $dashboard->voyages['recents'][0]['villeDepart']);
        self::assertCount(1, $dashboard->demandes['recentes']);
        self::assertCount(1, $dashboard->notifications['recentes']);
        self::assertCount(1, $dashboard->messages['recents']);
        self::assertSame(4.3, $dashboard->stats['noteMoyenne']);
        self::assertSame(3, $dashboard->stats['nombreAvis']);
    }

    public function testGetUserDashboardTruncatesALongMessageContent(): void
    {
        $user = $this->user(1);
        $longContent = str_repeat('a', 150);
        $message = $this->message($user);
        $message->setContenu($longContent);

        $this->voyageRepository->method('count')->willReturn(0);
        $this->voyageRepository->method('findBy')->willReturn([]);
        $this->demandeRepository->method('count')->willReturn(0);
        $this->demandeRepository->method('findBy')->willReturn([]);
        $this->notificationRepository->method('count')->willReturn(0);
        $this->notificationRepository->method('findBy')->willReturn([]);
        $this->messageRepository->method('count')->willReturn(0);
        $this->messageRepository->method('findBy')->willReturn([$message]);

        $dashboard = $this->service->getUserDashboard($user);

        self::assertSame(103, mb_strlen($dashboard->messages['recents'][0]['contenu']));
        self::assertStringEndsWith('...', $dashboard->messages['recents'][0]['contenu']);
    }

    // ==================== getUserPublicStats ====================

    public function testGetUserPublicStatsHidesStatsWhenNotVisible(): void
    {
        $owner = $this->user(1);
        $this->visibilityService->method('areStatsVisibleFor')->willReturn(false);
        $this->voyageRepository->expects(self::never())->method('count');

        $stats = $this->service->getUserPublicStats($owner, null);

        self::assertFalse($stats['visible']);
        self::assertArrayNotHasKey('voyagesEffectues', $stats);
    }

    public function testGetUserPublicStatsReturnsStatsWhenVisible(): void
    {
        $owner = $this->user(1);
        $this->visibilityService->method('areStatsVisibleFor')->willReturn(true);
        $this->voyageRepository->method('count')->willReturn(5);
        $this->demandeRepository->method('count')->willReturn(2);

        $stats = $this->service->getUserPublicStats($owner, null);

        self::assertTrue($stats['visible']);
        self::assertSame(5, $stats['voyagesEffectues']);
        self::assertSame(2, $stats['bagagesTransportes']);
        self::assertSame(4.3, $stats['noteMoyenne']);
    }

    // ==================== getVisibleProfileData ====================

    public function testGetVisibleProfileDataHidesEmailAndPhoneWhenNotVisible(): void
    {
        $owner = $this->user(1);
        $this->visibilityService->method('isEmailVisibleFor')->willReturn(false);
        $this->visibilityService->method('isPhoneVisibleFor')->willReturn(false);
        $this->visibilityService->method('areStatsVisibleFor')->willReturn(false);

        $data = $this->service->getVisibleProfileData($owner, null);

        self::assertArrayNotHasKey('email', $data);
        self::assertArrayNotHasKey('telephone', $data);
        self::assertFalse($data['stats']['visible']);
    }

    public function testGetVisibleProfileDataExposesEmailAndPhoneWhenVisible(): void
    {
        $owner = $this->user(1);
        $owner->setTelephone('+237600000000');
        $this->visibilityService->method('isEmailVisibleFor')->willReturn(true);
        $this->visibilityService->method('isPhoneVisibleFor')->willReturn(true);
        $this->visibilityService->method('areStatsVisibleFor')->willReturn(true);
        $this->voyageRepository->method('count')->willReturn(0);
        $this->demandeRepository->method('count')->willReturn(0);

        $data = $this->service->getVisibleProfileData($owner, $this->user(2));

        self::assertSame($owner->getEmail(), $data['email']);
        self::assertSame('+237600000000', $data['telephone']);
        self::assertTrue($data['stats']['visible']);
    }
}
