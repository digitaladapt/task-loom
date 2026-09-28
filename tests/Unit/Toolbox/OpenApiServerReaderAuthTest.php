<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Toolbox\CredentialResolutionException;
use App\Toolbox\OpenApiServerReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `cred_var` is protocol-agnostic (SPEC §7): a secured OpenAPI server guards
 * its spec endpoint exactly as a secured MCP server guards its endpoint. This
 * pins the resolved Authorization header on the spec fetch, and the
 * fail-closed behaviour when the named env var is missing.
 */
final class OpenApiServerReaderAuthTest extends TestCase
{
    private const ENV_NAME = 'TASKLOOM_TEST_OPENAPI_TOKEN';

    #[\Override]
    protected function tearDown(): void
    {
        unset($_ENV[self::ENV_NAME], $_SERVER[self::ENV_NAME]);
        putenv(self::ENV_NAME);

        parent::tearDown();
    }

    private function setEnv(string $value): void
    {
        $_ENV[self::ENV_NAME] = $value;
        $_SERVER[self::ENV_NAME] = $value;
        putenv(self::ENV_NAME.'='.$value);
    }

    public function testSpecFetchCarriesResolvedAuthorizationHeader(): void
    {
        $this->setEnv('openapi-token-9z');

        /** @var list<array{headers: list<string>}> $requests */
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$requests): MockResponse {
            $requests[] = ['headers' => $options['normalized_headers']['authorization'] ?? []];

            return new MockResponse(json_encode([
                'openapi' => '3.0.0',
                'paths' => [
                    '/ping' => [
                        'get' => ['operationId' => 'ping', 'responses' => ['200' => ['description' => 'ok']]],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $reader = new OpenApiServerReader($http);
        $server = new McpServer('secured-api', 'https://api.example.com/openapi.json', ServerProtocol::OpenApi, self::ENV_NAME);

        $tools = $reader->read($server);

        self::assertSame('ping', $tools[0]->name);
        self::assertContains('Authorization: Bearer openapi-token-9z', $requests[0]['headers']);
    }

    public function testNoCredVarSendsNoAuthorizationHeader(): void
    {
        /** @var list<array{headers: list<string>}> $requests */
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$requests): MockResponse {
            $requests[] = ['headers' => $options['normalized_headers']['authorization'] ?? []];

            return new MockResponse('{"paths":{}}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        $reader = new OpenApiServerReader($http);
        $server = new McpServer('open-api', 'https://api.example.com/openapi.json', ServerProtocol::OpenApi);

        $reader->read($server);

        self::assertSame([], $requests[0]['headers']);
    }

    public function testMissingEnvVarFailsBeforeAnyRequest(): void
    {
        $called = false;
        $http = new MockHttpClient(function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('{"paths":{}}');
        });

        $reader = new OpenApiServerReader($http);
        $server = new McpServer('secured-api', 'https://api.example.com/openapi.json', ServerProtocol::OpenApi, self::ENV_NAME);

        try {
            $reader->read($server);
            self::fail('Expected a CredentialResolutionException.');
        } catch (CredentialResolutionException $e) {
            self::assertStringContainsString(self::ENV_NAME, $e->getMessage());
        }

        self::assertFalse($called, 'no request may be sent when the credential is unresolvable');
    }
}
