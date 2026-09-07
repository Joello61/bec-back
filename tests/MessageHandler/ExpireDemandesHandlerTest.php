<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Demande;
use App\Entity\User;
use App\Message\ExpireDemandesMessage;
use App\MessageHandler\ExpireDemandesHandler;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 12 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : meme patron
 * que ExpireVoyagesHandlerTest - integration KernelTestCase, appel direct du handler.
 */
class ExpireDemandesHandlerTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private ExpireDemandesHandler $handler;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(ExpireDemandesHandler::class);
    }

    private function demande(User $client, string $dateLimite, string $statut = 'en_recherche'): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala-Expire-Unique');
        $demande->setVilleArrivee('Paris-Expire-Unique');
        $demande->setPoidsEstime('5');
        $demande->setDescription('Demande de test expiration');
        $demande->setDateLimite(new \DateTime($dateLimite));
        $demande->setStatut($statut);
        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }

    // ==================== no-op ====================

    public function testHandlerIsANoOpWhenNothingIsExpired(): void
    {
        $client = $this->createUser('expiredemande-noop');
        $this->demande($client, '+5 days');

        ($this->handler)(new ExpireDemandesMessage());

        $demandes = $this->em->getRepository(Demande::class)->findBy(['statut' => 'en_recherche']);
        self::assertNotEmpty($demandes);
    }

    // ==================== expiration simple ====================

    public function testHandlerExpiresAPastSearchingDemande(): void
    {
        $client = $this->createUser('expiredemande-simple');
        $demande = $this->demande($client, '-3 days');
        $demandeId = $demande->getId();

        ($this->handler)(new ExpireDemandesMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(Demande::class)->find($demandeId);
        self::assertSame('expiree', $refreshed->getStatut());
    }

    public function testHandlerDoesNotTouchADemandeAlreadyMatched(): void
    {
        $client = $this->createUser('expiredemande-nontouch');
        $demande = $this->demande($client, '-3 days', statut: 'voyageur_trouve');
        $demandeId = $demande->getId();

        ($this->handler)(new ExpireDemandesMessage());
        $this->em->clear();

        $refreshed = $this->em->getRepository(Demande::class)->find($demandeId);
        self::assertSame('voyageur_trouve', $refreshed->getStatut());
    }

    // ==================== batching : regression du bug de detachement ====================

    public function testHandlerExpiresEveryDemandeAcrossMultipleBatches(): void
    {
        $client = $this->createUser('expiredemande-batch');
        $d1 = $this->demande($client, '-3 days');
        $d2 = $this->demande($client, '-4 days');
        $d3 = $this->demande($client, '-5 days');
        $ids = [$d1->getId(), $d2->getId(), $d3->getId()];

        ($this->handler)(new ExpireDemandesMessage(batchSize: 1));
        $this->em->clear();

        foreach ($ids as $id) {
            $refreshed = $this->em->getRepository(Demande::class)->find($id);
            self::assertSame('expiree', $refreshed->getStatut(), "demande $id devrait etre expiree");
        }
    }
}
