<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4b, Lot 14 (bec-docs/docs/plan-correction/plan-correction-cobage.md), optionnel :
 * unitaire pur des methodes utilitaires de Conversation - hasParticipant/getOtherParticipant
 * (comparaison d'identite d'objet, pas d'id - suffisant en unitaire pur sans DB),
 * countMessagesNonLusPour (filtre destinataire + non lu).
 */
class ConversationTest extends TestCase
{
    private function user(string $prefix): User
    {
        $user = new User();
        $user->setEmail($prefix . '-' . uniqid() . '@example.test');
        $user->setPassword('irrelevant');

        return $user;
    }

    private function conversation(User $a, User $b): Conversation
    {
        $conversation = new Conversation();
        $conversation->setParticipant1($a);
        $conversation->setParticipant2($b);

        return $conversation;
    }

    private function message(Conversation $conversation, User $destinataire, bool $lu = false): Message
    {
        $message = new Message();
        $message->setDestinataire($destinataire);
        $message->setContenu('Bonjour');
        $message->setLu($lu);
        $conversation->addMessage($message);

        return $message;
    }

    // ==================== hasParticipant ====================

    public function testHasParticipantIsTrueForBothParticipants(): void
    {
        $a = $this->user('conv-hasparticipant-a');
        $b = $this->user('conv-hasparticipant-b');
        $conversation = $this->conversation($a, $b);

        self::assertTrue($conversation->hasParticipant($a));
        self::assertTrue($conversation->hasParticipant($b));
    }

    public function testHasParticipantIsFalseForAStranger(): void
    {
        $a = $this->user('conv-stranger-a');
        $b = $this->user('conv-stranger-b');
        $stranger = $this->user('conv-stranger-c');
        $conversation = $this->conversation($a, $b);

        self::assertFalse($conversation->hasParticipant($stranger));
    }

    // ==================== getOtherParticipant ====================

    public function testGetOtherParticipantReturnsTheCorrespondingUser(): void
    {
        $a = $this->user('conv-other-a');
        $b = $this->user('conv-other-b');
        $conversation = $this->conversation($a, $b);

        self::assertSame($b, $conversation->getOtherParticipant($a));
        self::assertSame($a, $conversation->getOtherParticipant($b));
    }

    public function testGetOtherParticipantReturnsNullForAStranger(): void
    {
        $a = $this->user('conv-othernull-a');
        $b = $this->user('conv-othernull-b');
        $stranger = $this->user('conv-othernull-c');
        $conversation = $this->conversation($a, $b);

        self::assertNull($conversation->getOtherParticipant($stranger));
    }

    // ==================== countMessagesNonLusPour ====================

    public function testCountMessagesNonLusPourOnlyCountsUnreadAddressedToTheUser(): void
    {
        $a = $this->user('conv-unread-a');
        $b = $this->user('conv-unread-b');
        $conversation = $this->conversation($a, $b);
        $this->message($conversation, $a, lu: false);
        $this->message($conversation, $a, lu: true);
        $this->message($conversation, $b, lu: false);

        self::assertSame(1, $conversation->countMessagesNonLusPour($a));
    }

    public function testCountMessagesNonLusPourIsZeroWithoutMessages(): void
    {
        $a = $this->user('conv-nomsg-a');
        $b = $this->user('conv-nomsg-b');
        $conversation = $this->conversation($a, $b);

        self::assertSame(0, $conversation->countMessagesNonLusPour($a));
    }
}
