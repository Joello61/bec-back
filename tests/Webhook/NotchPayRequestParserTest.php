<?php

declare(strict_types=1);

namespace App\Tests\Webhook;

use App\Webhook\NotchPayRequestParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Monétisation Lot 3 : signature Notch Pay (X-Notch-Signature, HMAC-SHA256 + hash_equals)
 * valide/invalide, obligatoire avant tout accès au corps brut de la requête (raw body).
 */
class NotchPayRequestParserTest extends TestCase
{
    private const SECRET = 'notch_test_secret';

    private function acceptedLimiterFactory(): RateLimiterFactoryInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn(
            new RateLimit(119, new \DateTimeImmutable(), true, 120)
        );

        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    private function signedRequest(string $payload, string $secret = self::SECRET): Request
    {
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhook/notchpay', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $request->headers->set('X-Notch-Signature', $signature);

        return $request;
    }

    public function testValidSignatureProducesARemoteEventMatchingTheNotchPayEventTypeAndReference(): void
    {
        $payload = json_encode([
            'event' => 'payment.complete',
            'data' => ['id' => 'pay_123', 'reference' => 'ref_abc', 'amount' => 5000, 'currency' => 'XAF'],
        ], \JSON_THROW_ON_ERROR);

        $parser = new NotchPayRequestParser($this->acceptedLimiterFactory(), new NullLogger());

        $event = $parser->parse($this->signedRequest($payload), self::SECRET);

        self::assertSame('payment.complete', $event->getName());
        self::assertSame('pay_123', $event->getId());
        self::assertSame('ref_abc', $event->getPayload()['reference']);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $payload = json_encode(['event' => 'payment.complete', 'data' => ['id' => 'pay_123']], \JSON_THROW_ON_ERROR);
        $parser = new NotchPayRequestParser($this->acceptedLimiterFactory(), new NullLogger());

        // Signé avec un secret différent de celui attendu par le parser.
        $request = $this->signedRequest($payload, secret: 'un_autre_secret');

        $this->expectException(RejectWebhookException::class);

        $parser->parse($request, self::SECRET);
    }

    public function testMissingSignatureHeaderIsRejected(): void
    {
        $payload = json_encode(['event' => 'payment.complete', 'data' => ['id' => 'pay_123']], \JSON_THROW_ON_ERROR);
        $request = Request::create('/webhook/notchpay', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $parser = new NotchPayRequestParser($this->acceptedLimiterFactory(), new NullLogger());

        $this->expectException(RejectWebhookException::class);

        $parser->parse($request, self::SECRET);
    }

    public function testRequestIsRejectedWhenTheWebhookRateLimitIsExceeded(): void
    {
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn(new RateLimit(0, new \DateTimeImmutable(), false, 120));
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        $payload = json_encode(['event' => 'payment.complete', 'data' => ['id' => 'pay_123']], \JSON_THROW_ON_ERROR);
        $parser = new NotchPayRequestParser($factory, new NullLogger());

        $this->expectException(RejectWebhookException::class);

        $parser->parse($this->signedRequest($payload), self::SECRET);
    }
}
