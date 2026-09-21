<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RequestIdTest extends WebTestCase
{
    public function testGeneratedRequestIdIsEchoedInResponseHeader(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();

        $requestId = $client->getResponse()->headers->get('X-Request-Id');

        self::assertNotNull($requestId, 'X-Request-Id header should be set on every response.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $requestId);
    }

    public function testUpstreamRequestIdIsAdoptedAndEchoed(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health', server: ['HTTP_X_REQUEST_ID' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

        self::assertResponseIsSuccessful();

        self::assertSame(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            $client->getResponse()->headers->get('X-Request-Id'),
            'A well-formed upstream X-Request-Id should be adopted and echoed.',
        );
    }

    public function testMalformedUpstreamIdIsReplacedByGeneratedOne(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health', server: ['HTTP_X_REQUEST_ID' => 'not-an-id-^%$']);

        self::assertResponseIsSuccessful();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            $client->getResponse()->headers->get('X-Request-Id'),
            'A malformed upstream ID must be replaced with a generated one.',
        );
    }
}
