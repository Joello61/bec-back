<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TwilioService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Client;

/**
 * Phase 4b, Lot 2 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : isValidPhoneNumber()
 * est une fonction pure (aucun mock necessaire), mais sendSMS()/sendVerificationCode()
 * n'etaient testables ni l'un ni l'autre avant l'injection du client Twilio (commit
 * precedent de ce lot) - le client expose messages via un accesseur magique __get(),
 * intercepte ici via un mock du client lui-meme.
 */
class TwilioServiceTest extends TestCase
{
    private Client&\PHPUnit\Framework\MockObject\MockObject $client;
    private MessageList&\PHPUnit\Framework\MockObject\MockObject $messageList;
    private TwilioService $service;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->messageList = $this->createMock(MessageList::class);
        $this->client->method('__get')->with('messages')->willReturn($this->messageList);

        $this->service = new TwilioService(new NullLogger(), '+237600000099', $this->client);
    }

    // ==================== isValidPhoneNumber (fonction pure) ====================

    public function testIsValidPhoneNumberAcceptsE164WithPlus(): void
    {
        self::assertTrue($this->service->isValidPhoneNumber('+237612345678'));
    }

    public function testIsValidPhoneNumberAcceptsE164WithoutPlus(): void
    {
        self::assertTrue($this->service->isValidPhoneNumber('237612345678'));
    }

    public function testIsValidPhoneNumberRejectsALeadingZero(): void
    {
        self::assertFalse($this->service->isValidPhoneNumber('+0612345678'), 'le premier chiffre significatif ne peut pas etre 0 en E.164');
    }

    public function testIsValidPhoneNumberRejectsTooManyDigits(): void
    {
        self::assertFalse($this->service->isValidPhoneNumber('+1234567890123456'), 'E.164 plafonne a 15 chiffres');
    }

    public function testIsValidPhoneNumberRejectsAnEmptyString(): void
    {
        self::assertFalse($this->service->isValidPhoneNumber(''));
    }

    public function testIsValidPhoneNumberRejectsNonNumericCharacters(): void
    {
        self::assertFalse($this->service->isValidPhoneNumber('+237-600-000-000'), 'espaces/tirets non autorises par le format E.164 strict');
    }

    public function testIsValidPhoneNumberRejectsLettersMixedIn(): void
    {
        self::assertFalse($this->service->isValidPhoneNumber('+237ABC45678'));
    }

    // ==================== sendSMS / sendVerificationCode ====================

    public function testSendSmsCallsTwilioWithTheConfiguredSenderNumber(): void
    {
        $this->messageList->expects(self::once())
            ->method('create')
            ->with('+237611111111', ['from' => '+237600000099', 'body' => 'Bonjour'])
            ->willReturn($this->createMock(MessageInstance::class));

        self::assertTrue($this->service->sendSMS('+237611111111', 'Bonjour'));
    }

    public function testSendSmsWrapsATwilioExceptionAsAGenericException(): void
    {
        $this->messageList->method('create')->willThrowException(new TwilioException('numero invalide', 21211));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/numero invalide/');
        $this->service->sendSMS('+237611111111', 'Bonjour');
    }

    public function testSendVerificationCodeEmbedsTheCodeInTheMessageBody(): void
    {
        $this->messageList->expects(self::once())
            ->method('create')
            ->with('+237611111111', self::callback(function (array $options) {
                return str_contains($options['body'], '123456');
            }))
            ->willReturn($this->createMock(MessageInstance::class));

        self::assertTrue($this->service->sendVerificationCode('+237611111111', '123456'));
    }
}
