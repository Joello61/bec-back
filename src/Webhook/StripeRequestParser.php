<?php

declare(strict_types=1);

namespace App\Webhook;

use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Rate-limiting (payment_webhook) appliqué ici, avant la vérification de signature :
 * la route webhook est gérée nativement par symfony/webhook (pas de controller
 * applicatif où appliquer le pattern habituel type ContactController).
 */
final class StripeRequestParser extends AbstractRequestParser
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $paymentWebhookLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new IsJsonRequestMatcher(),
            new MethodRequestMatcher('POST'),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): RemoteEvent
    {
        $limiter = $this->paymentWebhookLimiter->create($request->getClientIp());

        if (false === $limiter->consume(1)->isAccepted()) {
            throw new RejectWebhookException(429, 'Trop de requêtes webhook.');
        }

        $signature = $request->headers->get('Stripe-Signature');

        if ($signature === null) {
            throw new RejectWebhookException(400, 'En-tête Stripe-Signature manquant.');
        }

        try {
            $event = \Stripe\Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            $this->logger->warning('Signature webhook Stripe invalide', ['error' => $e->getMessage()]);
            throw new RejectWebhookException(400, 'Signature Stripe invalide.');
        }

        return new RemoteEvent($event->type, $event->id, $event->toArray());
    }
}
