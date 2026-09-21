<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    public function testSecurityHeadersAndCspAreSetOnResponses(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();

        $csp = $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);

        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
        self::assertSame('same-origin', $client->getResponse()->headers->get('Referrer-Policy'));
    }
}
