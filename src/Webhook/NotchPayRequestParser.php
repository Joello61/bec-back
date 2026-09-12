<?php

declare(strict_types=1);

namespace App\Webhook;

use Psr\Log\LoggerInterface;
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
 * Signature verifiee via l'en-tete X-Notch-Signature (HMAC-SHA256 + hash_equals),
 * pattern documente par Notch Pay (developer.notchpay.co/get-started/webhooks) - aucun
 * helper de verification fourni par le SDK notchpay-php, a implementer manuellement.
 * Rate-limiting (payment_webhook) applique ici pour la meme raison que
 * StripeRequestParser (route geree nativement par symfony/webhook).
 */
final class NotchPayRequestParser extends AbstractRequestParser
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

        $signature = $request->headers->get('X-Notch-Signature');

        if ($signature === null) {
            throw new RejectWebhookException(400, 'En-tête X-Notch-Signature manquant.');
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            $this->logger->warning('Signature webhook Notch Pay invalide');
            throw new RejectWebhookException(400, 'Signature Notch Pay invalide.');
        }

        $body = json_decode($payload, true);

        if (!is_array($body)) {
            throw new RejectWebhookException(400, 'Corps de requête invalide.');
        }

        // Forme exacte de l'enveloppe (nom du champ "event" vs "type", presence ou non
        // d'une cle "data") non confirmee par sandbox reel (spike documentaire uniquement,
        // cf. mémoire) - extraction volontairement tolerante aux deux formes plausibles,
        // a revalider via un evenement de test envoye depuis le dashboard Notch Pay.
        $eventName = (string) ($body['event'] ?? $body['type'] ?? 'unknown');
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $eventId = (string) ($body['id'] ?? $data['id'] ?? $data['reference'] ?? uniqid('notchpay_', true));

        return new RemoteEvent($eventName, $eventId, $data);
    }
}
