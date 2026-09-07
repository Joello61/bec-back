<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\User;
use App\Entity\Voyage;
use App\Message\ExpireVoyagesMessage;
use App\MessageHandler\ExpireVoyagesHandler;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 12 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : le handler
 * depend d'une vraie requete Doctrine (VoyageRepository::findExpiredVoyages) + d'un flush/
 * clear() par lot - meme patron d'integration que MatchingServiceIntegrationTest (Phase 4),
 * appel direct du handler (pas de transport Messenger reel necessaire).
 */
class ExpireVoyagesHandlerTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private ExpireVoyagesHandler $handler;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(ExpireVoyagesHandler::class);
    }

    private function voyage(User $voyageur, string $dateDepart, string $statut = 'actif'): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala-Expire-Unique');
        $voyage->setVilleArrivee('Paris-Expire-Unique');
        $voyage->setDateDepart(new \DateTime($dateDepart));
        $voyage->setDateArrivee((new \DateTime($dateDepart))->modify('+1 day'));
        $voyage->setPoidsDisponible('20');
        $voyage->setPoidsDisponibleRestant('20');
        $voyage->setStatut($statut);
        $this->em->persist($voyage);
        $this->em->flush();

        return $voyage;
    }

    // ==================== no-op ====================

    public function testHandlerIsANoOpWhenNothingIsExpired(): void
    {
        $voyageur = $this->createUser('expirevoyage-noop');
        $this->voyage($voyageur, '+5 days');

        ($this->handler)(new ExpireVoyagesMessage());

        // Aucune exception, et le voyage futur reste actif.
        $voyages = $this->em->getRepository(Voyage::class)->findBy(['statut' => 'actif']);
        self::assertNotEmpty($voyages);
    }

    // ==================== expiration simple ====================

    public function testHandlerExpiresAPastActiveVoyage(): void
    {
        $voyageur = $this->createUser('expirevoyage-simple');
        $voyage = $this->voyage($voyageur, '-3 days');
        $voyageId = $voyage->getId();

        ($this->handler)(new ExpireVoyagesMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(Voyage::class)->find($voyageId);
        self::assertSame('expire', $refreshed->getStatut());
    }

    public function testHandlerDoesNotTouchAnAlreadyNonActiveVoyage(): void
    {
        $voyageur = $this->createUser('expirevoyage-nontouch');
        $voyage = $this->voyage($voyageur, '-3 days', statut: 'termine');
        $voyageId = $voyage->getId();

        ($this->handler)(new ExpireVoyagesMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(Voyage::class)->find($voyageId);
        self::assertSame('termine', $refreshed->getStatut());
    }

    // ==================== batching ====================

    public function testHandlerExpiresEveryVoyageAcrossMultipleBatches(): void
    {
        // batchSize=1 force 3 lots distincts pour 3 voyages a expirer - verifie que le
        // flush()+clear() effectue apres CHAQUE lot dans la boucle du handler ne casse
        // pas le traitement des lots suivants (les entites du lot 2/3 ont ete chargees
        // par le meme findExpiredVoyages() initial, avant le premier clear()).
        $voyageur = $this->createUser('expirevoyage-batch');
        $v1 = $this->voyage($voyageur, '-3 days');
        $v2 = $this->voyage($voyageur, '-4 days');
        $v3 = $this->voyage($voyageur, '-5 days');
        $ids = [$v1->getId(), $v2->getId(), $v3->getId()];

        ($this->handler)(new ExpireVoyagesMessage(batchSize: 1));
        $this->em->clear();

        foreach ($ids as $id) {
            $refreshed = $this->em->getRepository(Voyage::class)->find($id);
            self::assertSame('expire', $refreshed->getStatut(), "voyage $id devrait etre expire");
        }
    }
}
