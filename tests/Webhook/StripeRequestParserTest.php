<?php

declare(strict_types=1);

namespace App\Tests\Webhook;

use App\Webhook\StripeRequestParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Monétisation Lot 1 : signature Stripe valide/invalide, obligatoire avant tout accès
 * au corps brut de la requête (raw body) - cf. ../../CLAUDE.md §2 sur les webhooks.
 */
class StripeRequestParserTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

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

    private function signedRequest(string $payload, string $secret = self::SECRET, ?int $timestamp = null): Request
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        $request = Request::create('/webhook/stripe', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $request->headers->set('Stripe-Signature', "t={$timestamp},v1={$signature}");

        return $request;
    }

    public function testValidSignatureProducesARemoteEventMatchingTheStripeEventTypeAndId(): void
    {
        $payload = json_encode([
            'id' => 'evt_123',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_123']],
        ], \JSON_THROW_ON_ERROR);

        $parser = new StripeRequestParser($this->acceptedLimiterFactory(), new NullLogger());

        $event = $parser->parse($this->signedRequest($payload), self::SECRET);

        self::assertSame('checkout.session.completed', $event->getName());
        self::assertSame('evt_123', $event->getId());
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $payload = json_encode(['id' => 'evt_123', 'type' => 'checkout.session.completed', 'data' => ['object' => []]], \JSON_THROW_ON_ERROR);
        $parser = new StripeRequestParser($this->acceptedLimiterFactory(), new NullLogger());

        // Signée avec un secret différent de celui attendu par le parser.
        $request = $this->signedRequest($payload, secret: 'whsec_un_autre_secret');

        $this->expectException(RejectWebhookException::class);

        $parser->parse($request, self::SECRET);
    }

    public function testMissingSignatureHeaderIsRejected(): void
    {
        $payload = json_encode(['id' => 'evt_123', 'type' => 'checkout.session.completed', 'data' => ['object' => []]], \JSON_THROW_ON_ERROR);
        $request = Request::create('/webhook/stripe', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $parser = new StripeRequestParser($this->acceptedLimiterFactory(), new NullLogger());

        $this->expectException(RejectWebhookException::class);

        $parser->parse($request, self::SECRET);
    }

    public function testRequestIsRejectedWhenTheWebhookRateLimitIsExceeded(): void
    {
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn(new RateLimit(0, new \DateTimeImmutable(), false, 120));
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        $payload = json_encode(['id' => 'evt_123', 'type' => 'checkout.session.completed', 'data' => ['object' => []]], \JSON_THROW_ON_ERROR);
        $parser = new StripeRequestParser($factory, new NullLogger());

        $this->expectException(RejectWebhookException::class);

        $parser->parse($this->signedRequest($payload), self::SECRET);
    }
}
