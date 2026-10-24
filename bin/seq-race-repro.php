#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reproduces the THE_STREAM.md §2.7(b) seq race across real OS processes.
 *
 *   php bin/seq-race-repro.php <deferred|immediate> [workers]
 *
 * Each worker does what a turn does: read `MAX(seq)`, pick the next number,
 * insert it, commit. `deferred` is how SQLite begins a transaction by default
 * and how the app ran before SqliteWriteLockConnection; `immediate` is what the
 * app does now.
 *
 * Kept in the repository because the doc cites it, and because the numbers it
 * prints are the evidence for choosing a connection-level fix over the
 * retry-at-flush the design doc originally proposed. PHPUnit cannot demonstrate
 * this — the bug needs separate OS processes, and it needs them to lose.
 *
 * Exits 0 when every worker committed and the stream is contiguous from 1.
 */
const SCHEMA = [
    'CREATE TABLE stream_event (id INTEGER PRIMARY KEY AUTOINCREMENT, '
    .'being_id INTEGER NOT NULL, seq INTEGER NOT NULL, content TEXT)',
    'CREATE UNIQUE INDEX UNIQ_STREAM_EVENT ON stream_event (being_id, seq)',
];

/**
 * The child half. Not a separate file so the two halves cannot drift apart.
 *
 * @internal
 */
function child(string $file, string $mode, int $index): int
{
    $pdo = new PDO('sqlite:'.$file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');

    // Jittered start, so the workers overlap rather than queue politely.
    usleep(random_int(0, 20_000));

    try {
        $pdo->exec('immediate' === $mode ? 'BEGIN IMMEDIATE' : 'BEGIN');

        $max = $pdo->query('SELECT MAX(seq) FROM stream_event WHERE being_id = 1')->fetchColumn();
        $next = (null === $max ? 0 : (int) $max) + 1;

        // The window between deciding the number and using it. A real turn
        // spends this time compiling a prompt.
        usleep(random_int(0, 15_000));

        $pdo->prepare('INSERT INTO stream_event (being_id, seq, content) VALUES (1, ?, ?)')
            ->execute([$next, 'worker-'.$index]);

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $stderr = fwrite(STDOUT, $e::class.': '.$e->getMessage()."\n");

        return 1;
    }

    return 0;
}

// Dispatch to the child before any parent work runs.
if ('--child' === ($argv[1] ?? null)) {
    exit(child((string) $argv[2], (string) $argv[3], (int) $argv[4]));
}

/* @var list<string> $argv */
[$script, $mode, $workers] = $argv + [null, null, '5'];

if (!\in_array($mode, ['deferred', 'immediate'], true)) {
    fwrite(STDERR, "usage: {$script} <deferred|immediate> [workers]\n");
    exit(2);
}

$workerCount = max(1, (int) $workers);
$file = tempnam(sys_get_temp_dir(), 'seq-race-').'.db';

$open = static function (string $path): PDO {
    $pdo = new PDO('sqlite:'.$path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');

    return $pdo;
};

// Parent: schema only.
$setup = $open($file);

foreach (SCHEMA as $statement) {
    $setup->exec($statement);
}

$setup = null;

// One child process per worker — proc_open, not PHPUnit parallelism, so each
// really is a separate process with its own PDO handle on the file.
$handles = [];

for ($i = 0; $i < $workerCount; ++$i) {
    $command = sprintf(
        '%s %s --child %s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        escapeshellarg($file),
        escapeshellarg($mode),
        escapeshellarg((string) $i),
    );

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!\is_resource($process)) {
        fwrite(STDERR, "could not start worker {$i}\n");
        exit(2);
    }

    $handles[$i] = [$process, $pipes];
}

$succeeded = 0;
$errors = [];

foreach ($handles as [$process, $pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (0 === proc_close($process)) {
        ++$succeeded;
        continue;
    }

    $errors[] = trim((string) $stdout) ?: 'worker exited non-zero';
}

$verify = $open($file);
$seqs = array_map(
    intval(...),
    $verify->query('SELECT seq FROM stream_event ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN),
);
$verify = null;
$rows = \count($seqs);

$contiguous = [] !== $seqs && $seqs === range(1, $rows);
$failed = $workerCount - $succeeded;

printf(
    "mode=%-9s workers=%d  committed=%d lost=%d  rows=%d  contiguous=%s\n",
    $mode,
    $workerCount,
    $succeeded,
    $failed,
    $rows,
    $contiguous ? 'yes' : 'NO',
);

foreach (array_slice(array_unique($errors), 0, 4) as $error) {
    printf("    %s\n", preg_replace('/\s+/', ' ', mb_substr($error, 0, 140)));
}

// Clean up the database and its WAL companions.
foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}

exit(0 === $failed && $contiguous ? 0 : 1);
