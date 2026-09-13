<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Boost;
use App\Entity\BoostOffer;
use App\Entity\User;
use App\Entity\Voyage;
use App\Message\SendBoostEndingReminderMessage;
use App\MessageHandler\SendBoostEndingReminderHandler;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot N7 monétisation : le rappel J-3 ne concerne que les boosts actifs dont l'échéance
 * approche et qui n'ont pas déjà reçu de rappel - symétrique de
 * SendRenewalReminderHandlerTest (Lot 3), même pattern d'intégration (vraie requête
 * Doctrine, DAMA rollback la transaction à chaque test).
 */
class SendBoostEndingReminderHandlerTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private SendBoostEndingReminderHandler $handler;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(SendBoostEndingReminderHandler::class);
    }

    private function voyage(User $voyageur, string $suffix): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala-N7-' . $suffix);
        $voyage->setVilleArrivee('Paris-N7-' . $suffix);
        $voyage->setDateDepart(new \DateTime('+10 days'));
        $voyage->setDateArrivee(new \DateTime('+11 days'));
        $voyage->setPoidsDisponible('10.00');
        $voyage->setPoidsDisponibleRestant('10.00');
        $voyage->setStatut('actif');
        $this->em->persist($voyage);

        return $voyage;
    }

    private function offer(): BoostOffer
    {
        $offer = new BoostOffer();
        $offer->setName('Test')->setDurationDays(7)->setPriceAmountEur('2.99');
        $this->em->persist($offer);

        return $offer;
    }

    private function boost(
        User $user,
        Voyage $voyage,
        BoostOffer $offer,
        \DateTimeInterface $endAt,
        ?\DateTimeInterface $boostEndingReminderSentAt = null,
        string $status = Boost::STATUS_ACTIVE,
    ): Boost {
        $boost = new Boost();
        $boost->setUser($user)
            ->setVoyage($voyage)
            ->setOffer($offer)
            ->setStatus($status)
            ->setStartAt(new \DateTime('-1 day'))
            ->setEndAt($endAt)
            ->setAmount('2.99')
            ->setCurrency('EUR')
            ->setBoostEndingReminderSentAt($boostEndingReminderSentAt);
        $this->em->persist($boost);
        $this->em->flush();

        return $boost;
    }

    public function testHandlerIsANoOpWhenNoBoostIsNearingItsDeadline(): void
    {
        $user = $this->createUser('boost-reminder-noop');
        $voyage = $this->voyage($user, 'noop');
        $offer = $this->offer();
        $this->boost($user, $voyage, $offer, new \DateTime('+20 days'));

        ($this->handler)(new SendBoostEndingReminderMessage());

        self::assertTrue(true);
    }

    public function testHandlerSendsTheReminderAndStampsBoostEndingReminderSentAt(): void
    {
        $user = $this->createUser('boost-reminder-due');
        $voyage = $this->voyage($user, 'due');
        $offer = $this->offer();
        $boost = $this->boost($user, $voyage, $offer, new \DateTime('+2 days'));
        $boostId = $boost->getId();

        ($this->handler)(new SendBoostEndingReminderMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(Boost::class)->find($boostId);
        self::assertNotNull($refreshed->getBoostEndingReminderSentAt());
    }

    public function testHandlerDoesNotResendWhenAReminderWasAlreadySent(): void
    {
        $user = $this->createUser('boost-reminder-already-sent');
        $voyage = $this->voyage($user, 'already-sent');
        $offer = $this->offer();
        $alreadySentAt = new \DateTime('-1 day');
        $boost = $this->boost($user, $voyage, $offer, new \DateTime('+2 days'), boostEndingReminderSentAt: $alreadySentAt);
        $boostId = $boost->getId();

        ($this->handler)(new SendBoostEndingReminderMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(Boost::class)->find($boostId);
        self::assertEquals($alreadySentAt->format('Y-m-d H:i:s'), $refreshed->getBoostEndingReminderSentAt()->format('Y-m-d H:i:s'));
    }

    public function testHandlerIgnoresBoostsThatAreNotActive(): void
    {
        $user = $this->createUser('boost-reminder-pending');
        $voyage = $this->voyage($user, 'pending');
        $offer = $this->offer();
        $boost = $this->boost($user, $voyage, $offer, new \DateTime('+2 days'), status: Boost::STATUS_PENDING);
        $boostId = $boost->getId();

        ($this->handler)(new SendBoostEndingReminderMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(Boost::class)->find($boostId);
        self::assertNull($refreshed->getBoostEndingReminderSentAt(), 'un boost pas encore actif ne doit jamais recevoir de rappel de fin');
    }
}
