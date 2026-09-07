<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee de NotificationRepository - markAllAsRead (UPDATE en masse) ne doit
 * affecter que les notifications du bon utilisateur.
 */
class NotificationRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private NotificationRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(NotificationRepository::class);
    }

    private function notification(User $user, bool $lue = false): Notification
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType('info');
        $notification->setTitre('Titre');
        $notification->setMessage('Message');
        $notification->setLue($lue);
        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    // ==================== findUnreadByUser / countUnread ====================

    public function testFindUnreadByUserExcludesReadNotifications(): void
    {
        $user = $this->createUser('notifrepo-unread');
        $this->notification($user, lue: true);
        $unread = $this->notification($user, lue: false);

        $result = $this->repository->findUnreadByUser($user->getId());

        self::assertCount(1, $result);
        self::assertSame($unread->getId(), $result[0]->getId());
    }

    public function testCountUnreadMatchesFindUnreadByUser(): void
    {
        $user = $this->createUser('notifrepo-countunread');
        $this->notification($user, lue: false);
        $this->notification($user, lue: false);
        $this->notification($user, lue: true);

        self::assertSame(2, $this->repository->countUnread($user->getId()));
    }

    // ==================== markAllAsRead ====================

    public function testMarkAllAsReadOnlyAffectsTheGivenUser(): void
    {
        $user = $this->createUser('notifrepo-markall-user');
        $other = $this->createUser('notifrepo-markall-other');
        $ownNotif = $this->notification($user, lue: false);
        $otherNotif = $this->notification($other, lue: false);

        $this->repository->markAllAsRead($user->getId());
        $this->em->clear();

        $refreshedOwn = $this->em->getRepository(Notification::class)->find($ownNotif->getId());
        $refreshedOther = $this->em->getRepository(Notification::class)->find($otherNotif->getId());
        self::assertTrue($refreshedOwn->isLue());
        self::assertFalse($refreshedOther->isLue());
    }

    // ==================== deleteOldNotifications ====================

    public function testDeleteOldNotificationsOnlyRemovesReadOnes(): void
    {
        $user = $this->createUser('notifrepo-deleteold');
        $unread = $this->notification($user, lue: false);

        // Seules les notifications lues et anciennes sont supprimees - avec un seuil de
        // 0 jour, une notification non lue creee a l'instant doit malgre tout survivre.
        $this->repository->deleteOldNotifications(0);
        $this->em->clear();

        self::assertNotNull($this->em->getRepository(Notification::class)->find($unread->getId()));
    }
}
