<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 10 (bec-docs/docs/plan-correction/plan-correction-cobage.md).
 */
class HealthControllerTest extends WebTestCase
{
    public function testHealthCheckIsPublicAndReturnsOk(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame('OK', $client->getResponse()->getContent());
    }
}
