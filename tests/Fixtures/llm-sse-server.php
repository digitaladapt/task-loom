<?php

declare(strict_types=1);

/*
 * A stand-in LLM endpoint for tests: a PHP built-in server router that
 * speaks the OpenAI-compatible streaming dialect (and the non-streamed
 * one on /plain) without a model behind it.
 *
 * Modes, selected by a path SEGMENT anywhere in the URL (the client always
 * posts to <base>/v1/chat/completions, so tests prefix the base URL: a
 * base of `http://host/looping` selects the looping mode):
 *   (none)        — SSE, 5ms gaps, 120 chunks, then [DONE]
 *   /slow         — SSE, 400ms gaps, 20 chunks (idle windows land between)
 *   /silent       — SSE, 2 chunks, then 30s of nothing (silence window)
 *   /looping      — SSE, one sentence repeated at 1ms (loop detector)
 *   /endless      — SSE, 5ms gaps, 30000 chunks (cancel/abort target)
 *   /plain        — application/json, one non-streamed completion
 *   /http-401     — 401 with an OpenAI-shaped error body
 *
 * The fixture records whether the client aborted mid-generation into
 * LLM_FIXTURE_MARKER_DIR (default: the system temp dir). That marker is
 * opportunistic — the PHP built-in server only checks connection_aborted()
 * between output writes, so a client close that lands during a sleep may
 * not surface here. The authoritative disconnect proof is at the OS level
 * in the tests (ESTABLISHED connections on /proc/net/tcp go to zero).
 */

$markerDir = getenv('LLM_FIXTURE_MARKER_DIR') ?: sys_get_temp_dir();
$log = static function (string $name, string $detail) use ($markerDir): void {
    @file_put_contents($markerDir.'/llm_fixture_'.$name, $detail);
};

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);

if ('/plain' === $path) {
    header('Content-Type: application/json');
    echo json_encode([
        'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'plain pong']]],
        'usage' => ['total_tokens' => 7],
    ]);

    exit;
}

if ('/http-401' === $path) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'Invalid API Key']]);

    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: close');
@ob_end_flush();

$emit = static function (string $payload): void {
    echo 'data: '.$payload."\n\n";
    flush();
};

$mode = 'fast';
$gap = 5_000;
$limit = 120;

if (str_contains($path, '/slow')) {
    $mode = 'slow';
    $gap = 400_000;
    $limit = 20;
} elseif (str_contains($path, '/silent')) {
    $mode = 'silent';
    $gap = 50_000;
    $limit = 8;
} elseif (str_contains($path, '/looping')) {
    $mode = 'looping';
    $gap = 1_000;
    $limit = 30_000;
} elseif (str_contains($path, '/endless')) {
    $mode = 'endless';
    $gap = 5_000;
    $limit = 30_000;
}

for ($i = 0; $i < $limit; ++$i) {
    if ('silent' === $mode && 2 === $i) {
        sleep(30);
    }

    $text = 'looping' === $mode ? 'I will keep thinking about this. ' : 'c'.$i.' ';

    $emit(json_encode([
        'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => null]],
    ], \JSON_UNESCAPED_SLASHES));

    usleep($gap);

    if (connection_aborted() || 0 !== connection_status()) {
        $log('abort', "aborted after {$i} chunks");

        exit;
    }
}

$emit('[DONE]');
$log('complete', 'completed '.$limit.' chunks');
