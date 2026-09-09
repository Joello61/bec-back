<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase D1 (bec-docs/docs/deploiement/deploiement-cobage.md) : /api/metrics est scrapé par
 * Prometheus, jamais un utilisateur applicatif - protégé par un jeton dédié comparé via
 * hash_equals(), jamais le firewall JWT (config/packages/security.yaml, firewall `metrics`
 * dédié en `security: false`).
 */
class GetMetricsControllerTest extends WebTestCase
{
    public function testRejectsRequestWithoutAuthorizationHeader(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/metrics');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testRejectsRequestWithWrongToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/metrics', server: [
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ]);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAcceptsRequestWithCorrectToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/metrics', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$_ENV['METRICS_SCRAPE_TOKEN'],
        ]);

        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('# HELP', (string) $response->getContent());
    }
}
