<?php

declare(strict_types=1);

/*
 * Streamable HTTP MCP server that REQUIRES an Authorization header (PHP
 * built-in server router).
 *
 * Every request must carry `Authorization: Bearer <EXPECTED_MCP_TOKEN>`;
 * anything else is answered 401 without a JSON-RPC body — the shape a real
 * secured MCP server returns, and the shape task-loom's catalog sync used to
 * die on before credentials were wired through.
 *
 * GET any path → 405 (like real Streamable HTTP servers without SSE).
 * POST → JSON-RPC, same messages as streamable-mcp-server.php.
 *
 * The expected token is read from the server process's EXPECTED_MCP_TOKEN
 * environment variable (set by the test when it spawns this process) — never
 * hardcoded, so the test can prove a *specific* resolved value reaches the
 * wire.
 */

$expected = getenv('EXPECTED_MCP_TOKEN') ?: '';

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ('' === $expected || 'Bearer '.$expected !== trim((string) $authorization)) {
    http_response_code(401);
    header('Content-Type: application/json');
    header('WWW-Authenticate: Bearer');
    echo json_encode(['error' => 'unauthorized']);

    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ('GET' === $method) {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'method not allowed']);

    exit;
}

if ('POST' !== $method) {
    http_response_code(405);

    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!\is_array($body)) {
    http_response_code(400);

    exit;
}

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
};

switch ($body['method'] ?? '') {
    case 'initialize':
        $respond([
            'jsonrpc' => '2.0',
            'id' => $body['id'],
            'result' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => []],
                'serverInfo' => ['name' => 'authed-shuttle', 'version' => '1.0.0'],
                'instructions' => 'Secured test server.',
            ],
        ]);

        exit;

    case 'notifications/initialized':
        http_response_code(202);

        exit;

    case 'tools/list':
        $respond([
            'jsonrpc' => '2.0',
            'id' => $body['id'],
            'result' => [
                'tools' => [
                    [
                        'name' => 'secret_tool',
                        'description' => 'Only visible with a valid credential.',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
                    ],
                ],
            ],
        ]);

        exit;

    case 'tools/call':
        // Echoes back the arguments, so a test can prove the call reached the
        // server authenticated and carried its payload.
        $respond([
            'jsonrpc' => '2.0',
            'id' => $body['id'],
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => 'authed:'.json_encode($body['params']['arguments'] ?? [], JSON_UNESCAPED_SLASHES)],
                ],
                'isError' => false,
            ],
        ]);

        exit;

    default:
        $respond([
            'jsonrpc' => '2.0',
            'id' => $body['id'] ?? null,
            'error' => ['code' => -32601, 'message' => 'Method not found'],
        ]);

        exit;
}
