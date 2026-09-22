<?php

declare(strict_types=1);

/*
 * Minimal Streamable HTTP MCP server for tests (PHP built-in server router).
 *
 * GET any path → 405 (like real Streamable HTTP servers without SSE).
 * POST /mcp → JSON responses for initialize / tools/list; 202 for the
 * `notifications/initialized` notification.
 * POST /sse-mcp → same, but responses use text/event-stream framing.
 */

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

$sse = str_contains($_SERVER['REQUEST_URI'] ?? '', '/sse-mcp');

$respond = static function (array $payload, int $status = 200) use ($sse): void {
    http_response_code($status);
    if ($sse) {
        header('Content-Type: text/event-stream');
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
};

switch ($body['method'] ?? '') {
    case 'initialize':
        $respond([
            'jsonrpc' => '2.0',
            'id' => $body['id'],
            'result' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => []],
                'serverInfo' => ['name' => 'test-shuttle', 'version' => '1.0.0'],
                'instructions' => 'Test server.',
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
                        'name' => 'echo',
                        'description' => 'Echo the message back.',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) ['message' => ['type' => 'string']], 'required' => ['message']],
                    ],
                ],
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
