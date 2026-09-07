<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Message;
use App\Security\Voter\MessageVoter;
use App\Tests\Support\InMemoryUserTrait;
use App\Tests\Support\MockTokenTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * un cas grant et un cas deny explicite par ressource, pour couvrir l'IDOR sur MessageVoter.
 */
class MessageVoterTest extends TestCase
{
    use InMemoryUserTrait;
    use MockTokenTrait;

    private MessageVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new MessageVoter();
    }

    public function testSendGrantedWhenProfileComplete(): void
    {
        $user = $this->makeUser([], profileComplete: true);

        $result = $this->voter->vote($this->tokenFor($user), null, [MessageVoter::SEND]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testSendDeniedWhenProfileIncomplete(): void
    {
        $user = $this->makeUser([], profileComplete: false);

        $result = $this->voter->vote($this->tokenFor($user), null, [MessageVoter::SEND]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testSendGrantedToAdminEvenWithIncompleteProfile(): void
    {
        $admin = $this->makeUser(['ROLE_ADMIN'], profileComplete: false);

        $result = $this->voter->vote($this->tokenFor($admin), null, [MessageVoter::SEND]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewGrantedToSender(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();
        $message = new Message();
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);

        $result = $this->voter->vote($this->tokenFor($sender), $message, [MessageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewGrantedToRecipient(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();
        $message = new Message();
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);

        $result = $this->voter->vote($this->tokenFor($recipient), $message, [MessageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testViewDeniedToThirdParty(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();
        $thirdParty = $this->makeUser();
        $message = new Message();
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);

        $result = $this->voter->vote($this->tokenFor($thirdParty), $message, [MessageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'un tiers etranger a la conversation ne doit jamais pouvoir lire un message prive (IDOR)');
    }

    public function testViewGrantedToAdminForModeration(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();
        $admin = $this->makeUser(['ROLE_ADMIN']);
        $message = new Message();
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);

        $result = $this->voter->vote($this->tokenFor($admin), $message, [MessageVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDeleteGrantedToSender(): void
    {
        $sender = $this->makeUser();
        $message = new Message();
        $message->setExpediteur($sender);
        $message->setDestinataire($this->makeUser());

        $result = $this->voter->vote($this->tokenFor($sender), $message, [MessageVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDeleteDeniedToRecipient(): void
    {
        $sender = $this->makeUser();
        $recipient = $this->makeUser();
        $message = new Message();
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);

        $result = $this->voter->vote($this->tokenFor($recipient), $message, [MessageVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, 'le destinataire ne peut pas supprimer un message qu\'il n\'a pas envoye');
    }
}
