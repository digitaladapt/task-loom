<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\Server\TaskServerFactory;
use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface as PsrRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The MCP server role over Streamable HTTP (SPEC §11).
 *
 * `POST /mcp` takes one JSON-RPC message (or batch) and answers with the
 * matching response. Sessions are real and persist across requests, so a client
 * must `initialize` before its first `tools/call`.
 *
 * Gated writes (SPEC §4.3) are unaffected by being served from the app rather
 * than a standalone process: the gate lives in TaskCrud, below this layer. What
 * does change is reachability — this route sits inside the app's security
 * configuration, so it is admin-guarded like every other route (see
 * `config/packages/security.yaml`, whose final rule is `^/ → ROLE_ADMIN`).
 * External agents therefore authenticate with the admin credentials.
 *
 * The split with the SDK follows the line it draws: this controller does HTTP
 * (Symfony → PSR-7 → Symfony), and the SDK does protocol (parsing, dispatch,
 * validation, session bookkeeping). The controller holds no JSON-RPC knowledge
 * — no id handling, no error-code table, no media-type negotiation.
 */
final class McpController
{
    public function __construct(
        private readonly TaskServerFactory $factory,
        private readonly HttpFactory $httpFactory = new HttpFactory(),
    ) {
    }

    #[Route('/mcp', name: 'app_mcp', methods: ['POST', 'GET', 'DELETE', 'OPTIONS'])]
    public function __invoke(Request $request): Response
    {
        if ('OPTIONS' === $request->getMethod()) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $psrResponse = $this->factory->build()->run(
            new StreamableHttpTransport(
                $this->toPsr7($request),
                // The SDK's default edge stack includes DNS-rebinding
                // protection allowlisted to localhost only, which would reject
                // every request in a real deployment. Host validation belongs
                // to the reverse proxy; the app has no CORS surface to protect.
                middleware: [],
            ),
        );

        return $this->toSymfony($psrResponse);
    }

    /**
     * Symfony request → PSR-7.
     *
     * Built from the request's own parts rather than through
     * `symfony/psr-http-message-bridge`, which is not installed and would add a
     * dependency for a conversion of one method's length.
     */
    private function toPsr7(Request $request): PsrRequestInterface
    {
        // `server->all()` already carries Host among the CGI variables, and
        // Guzzle's factory derives the Host header from the URI, so passing
        // both would send it twice — which the SDK's header handling rejects as
        // a repeated header rather than a duplicate value.
        $serverParams = $request->server->all();
        unset($serverParams['HTTP_HOST']);

        $psr = $this->httpFactory->createServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $serverParams,
        );

        foreach ($request->headers->all() as $name => $values) {
            if ('host' === strtolower($name)) {
                continue;
            }

            foreach ($values as $value) {
                $psr = $psr->withAddedHeader($name, $value);
            }
        }

        $body = $request->getContent();
        if ('' !== $body) {
            $psr = $psr->withBody($this->httpFactory->createStream($body));
        }

        return $psr;
    }

    /**
     * PSR-7 response → Symfony response.
     *
     * Streamed bodies are wrapped rather than read, so an SSE response is
     * forwarded as it is produced instead of buffered until the handler
     * finishes.
     */
    private function toSymfony(PsrResponseInterface $response): Response
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $status = $response->getStatusCode();
        $body = $response->getBody();

        if (!$body->isSeekable() && !$response->hasHeader('Content-Length')) {
            return new StreamedResponse(
                static function () use ($body): void {
                    while (!$body->eof()) {
                        echo $body->read(8192);

                        if (\function_exists('flush')) {
                            flush();
                        }
                    }
                },
                $status,
                $headers,
            );
        }

        // Rewind if we can: a PSR-7 stream may have been read to determine its
        // length upstream.
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return new Response((string) $body, $status, $headers);
    }
}
