<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Health/readiness split (§8.4): /health touches no dependencies, /ready may.
 * Both must return JSON with a status key.
 */
final class HealthControllerTest extends WebTestCase
{
    public function testHealthIsLiveAndDependencyFree(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertJson($client->getResponse()->getContent());
        self::assertSame(['status' => 'ok'], json_decode($client->getResponse()->getContent(), true));
    }

    public function testReadyReportsReadiness(): void
    {
        $client = static::createClient();

        $client->request('GET', '/ready');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ready'], json_decode($client->getResponse()->getContent(), true));
    }
}
