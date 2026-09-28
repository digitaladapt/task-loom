<?php

declare(strict_types=1);

namespace App\Tests\Container;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The container entrypoint (docker/entrypoint.sh) is the single-container
 * runtime: `serve` supervises the web process plus the worker fleet, and the
 * other commands are one-shot execs. Its behaviour cannot be exercised by
 * booting a kernel — it is a shell contract — so this suite drives the real
 * script with stub `php`/`frankenphp` executables on PATH, exactly the shape
 * the container sees.
 *
 * What is pinned here is the part that silently rots: which processes the
 * fleet contains, what happens when one dies, and how a stop propagates.
 * (Docker itself is not required; the script is the unit under test.)
 */
final class EntrypointSupervisorTest extends TestCase
{
    private string $sandbox = '';
    private string $bin = '';

    private const int TIMEOUT = 30;

    #[\Override]
    protected function setUp(): void
    {
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('The entrypoint supervisor test needs pcntl/posix (POSIX process control).');
        }

        $this->sandbox = sys_get_temp_dir().'/taskloom-entrypoint-'.bin2hex(random_bytes(6));
        $this->bin = $this->sandbox.'/bin';
        mkdir($this->bin, 0700, true);

        $this->writeStubs();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->killStrays();
        $this->removeDirectory($this->sandbox);
    }

    public function testServeStartsWebPlusTheConfiguredWorkerFleet(): void
    {
        $process = $this->startServe([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '3',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '2',
        ]);

        try {
            $this->waitFor(fn (): bool => 3 === $this->countLines('LLM WORKER up'));
            // Give the remaining spawns a moment to appear.
            usleep(300_000);
        } finally {
            $this->stopServe($process);
        }

        self::assertSame(3, $this->countLines('LLM WORKER up'), 'one llm worker per TASKLOOM_LLM_MAX_CONCURRENCY');
        self::assertSame(2, $this->countLines('TOOLS WORKER up'), 'one tools worker per TASKLOOM_TOOL_MAX_CONCURRENCY');
        self::assertSame(1, $this->countLines('WEB up', 'web.log'), 'the web process runs alongside the workers');
    }

    public function testWorkerOptionsCarryTheRecycleLimits(): void
    {
        $process = $this->startServe([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
            'TASKLOOM_WORKER_TIME_LIMIT' => '120',
            'TASKLOOM_WORKER_MEMORY_LIMIT' => '64M',
        ]);

        try {
            $this->waitFor(fn (): bool => $this->countLines('LLM WORKER up') >= 1);
        } finally {
            $this->stopServe($process);
        }

        self::assertStringContainsString('messenger:consume llm', $this->readPhpLog());
        self::assertStringContainsString('--time-limit=120', $this->readPhpLog());
        self::assertStringContainsString('--memory-limit=64M', $this->readPhpLog());
    }

    public function testCrashedWorkerIsRestarted(): void
    {
        $process = $this->startServe([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
            'STUB_LLM_CRASH_AFTER' => '0.3',
        ]);

        try {
            // The worker crashes after 0.3s; the supervisor backs off, then restarts.
            $this->waitFor(fn (): bool => $this->countLines('LLM WORKER up') >= 2, 15.0);
            $starts = $this->countLines('LLM WORKER up');
        } finally {
            $this->stopServe($process);
        }

        self::assertGreaterThanOrEqual(2, $starts, 'a crashed worker is restarted, not abandoned');
    }

    public function testWebExitStopsTheFleetAndPropagatesItsExitCode(): void
    {
        $process = $this->startServe([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '1',
            'STUB_WEB_EXIT_AFTER' => '0.5',
            'STUB_WEB_RC' => '7',
        ]);

        $process->wait();

        self::assertSame(7, $process->getExitCode(), 'the container reports the web process exit code');
        self::assertSame(1, $this->countLines('LLM WORKER got TERM'), 'workers are stopped when the web process dies');
        self::assertSame(1, $this->countLines('TOOLS WORKER got TERM'), 'workers are stopped when the web process dies');
    }

    public function testTermStopsTheWholeFleetGracefully(): void
    {
        $process = $this->startServe([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '2',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '1',
        ]);

        $this->waitFor(fn (): bool => 2 === $this->countLines('LLM WORKER up') && 1 === $this->countLines('TOOLS WORKER up'));

        $process->signal(\SIGTERM);
        $process->wait();

        self::assertSame(0, $process->getExitCode(), 'a graceful stop exits 0');
        self::assertSame(2, $this->countLines('LLM WORKER got TERM'), 'every llm worker is asked to stop');
        self::assertSame(1, $this->countLines('TOOLS WORKER got TERM'), 'the tools worker is asked to stop');
        self::assertSame(1, $this->countLines('WEB got TERM', 'web.log'), 'the web process is asked to stop');
    }

    public function testShutdownEscalatesWhenAChildIgnoresTerm(): void
    {
        $process = $this->startServe([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '0',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
            'TASKLOOM_SHUTDOWN_TIMEOUT' => '2',
            'STUB_WEB_WEDGE' => '1', // ignores TERM
        ]);

        $this->waitFor(fn (): bool => 1 === $this->countLines('WEB up', 'web.log'));

        $start = microtime(true);
        $process->signal(\SIGTERM);
        $process->wait();
        $elapsed = microtime(true) - $start;

        self::assertStringContainsString('killing them', $process->getErrorOutput(), 'the escalation is reported');
        self::assertLessThan(20.0, $elapsed, 'a wedged child cannot hold the container open indefinitely');
        self::assertFalse($process->isRunning());
    }

    public function testSchemaGateRefusesToStartTheFleetOnAPendingMigration(): void
    {
        $process = $this->runServeToCompletion([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
            'STUB_SCHEMA_RC' => '1',
        ]);

        self::assertSame(2, $process->getExitCode(), 'a boot gate failure is a hard failure');
        self::assertStringContainsString('doctrine:migrations:migrate', $process->getErrorOutput(), 'the diagnosis names the exact command to run');
        self::assertSame(0, $this->countLines('LLM WORKER up'), 'no worker starts against an out-of-date schema');
    }

    public function testMigrateOnBootRunsMigrationsBeforeTheFleet(): void
    {
        $process = $this->startServe([
            'TASKLOOM_MIGRATE_ON_BOOT' => '1',
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '0',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
        ]);

        try {
            $this->waitFor(fn (): bool => str_contains($this->readPhpLog(), 'migrations:migrate'));
        } finally {
            $this->stopServe($process);
        }

        self::assertStringContainsString('migrations:migrate', $this->readPhpLog());
    }

    public function testEnvContractFailureStopsBootWithTheMissingVariableNamed(): void
    {
        $process = $this->runServeToCompletion([
            'STUB_LINT_FAILS_WITH' => 'TASKLOOM_LLM_BASE_URL',
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
        ]);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('TASKLOOM_LLM_BASE_URL', $process->getErrorOutput());
        self::assertStringContainsString('.env.example', $process->getErrorOutput(), 'the operator is pointed at the documented variable list');
        self::assertSame(0, $this->countLines('LLM WORKER up'), 'nothing starts with an incomplete environment');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidKnobs(): iterable
    {
        yield 'non-numeric concurrency' => ['TASKLOOM_LLM_MAX_CONCURRENCY', 'abc', 'whole number'];
        yield 'zero workers is allowed but -1 is not' => ['TASKLOOM_TOOL_MAX_CONCURRENCY', '-1', 'whole number'];
        yield 'zero shutdown window is allowed, negatives are not' => ['TASKLOOM_SHUTDOWN_TIMEOUT', '-5', 'whole number'];
        yield 'silly memory limit' => ['TASKLOOM_WORKER_MEMORY_LIMIT', 'lots', 'look like'];
        yield 'zero time limit' => ['TASKLOOM_WORKER_TIME_LIMIT', '0', '>='];
    }

    #[DataProvider('invalidKnobs')]
    public function testInvalidKnobFailsFastAndNamesTheVariable(string $name, string $value, string $hint): void
    {
        $process = $this->runServeToCompletion([$name => $value]);

        self::assertSame(2, $process->getExitCode(), 'a bad knob is a configuration error, not a crash loop');
        self::assertStringContainsString($name, $process->getErrorOutput());
        self::assertStringContainsString($hint, $process->getErrorOutput());
    }

    public function testUnknownServeArgumentIsRefused(): void
    {
        $process = $this->runScript(['serve', 'something'], [], wait: true);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('serve takes no arguments', $process->getErrorOutput());
    }

    public function testAnyOtherCommandIsExecutedAsAOneShot(): void
    {
        // No `serve`: the entrypoint must exec the command, not supervise anything.
        $process = $this->runScript(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], [], wait: true);

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('migrations:migrate', $this->readPhpLog());
        self::assertSame(0, $this->countLines('WEB up', 'web.log'), 'a one-shot container starts no web process');
        self::assertSame(0, $this->countLines('LLM WORKER up'), 'a one-shot container starts no workers');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Start `serve` and wait until the fleet is up or the boot gates have run.
     *
     * @param array<string, string> $env
     */
    private function startServe(array $env): Process
    {
        $process = $this->runScript(['serve'], $env);
        $this->waitFor(fn (): bool => 1 === $this->countLines('WEB up', 'web.log'), 10.0);

        return $process;
    }

    /**
     * Run a boot gate that must terminate on its own (schema/env/knob failures).
     *
     * @param array<string, string> $env
     */
    private function runServeToCompletion(array $env): Process
    {
        $process = $this->runScript(['serve'], $env);
        $process->wait();

        return $process;
    }

    private function stopServe(Process $process): void
    {
        if ($process->isRunning()) {
            $process->signal(\SIGTERM);
            $process->wait();
        }
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     * @param bool                  $wait      block until the command exits (one-shot commands)
     */
    private function runScript(array $arguments, array $env, bool $wait = false): Process
    {
        $environment = array_merge([
            'PATH' => $this->bin.':/usr/local/bin:/usr/bin:/bin',
            'HOME' => $this->sandbox,
            'SANDBOX' => $this->sandbox,
            'APP_ENV' => 'prod',
            'TASKLOOM_PROJECT_DIR' => $this->sandbox,
            // The stubs answer every console command the entrypoint asks; these
            // are only here so a stray real lookup could not reach the network.
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
        ], $env);

        $process = new Process(
            [trim((string) shell_exec('command -v bash')), \dirname(__DIR__, 2).'/docker/entrypoint.sh', ...$arguments],
            $this->sandbox,
            $environment,
            null,
            self::TIMEOUT,
        );
        $wait ? $process->run() : $process->start();

        return $process;
    }

    /**
     * `frankenphp` and `php` are stubs: the entrypoint's contract is about
     * process lifecycle, not about what those binaries do.
     */
    private function writeStubs(): void
    {
        $this->write($this->bin.'/php', <<<'SH'
            #!/usr/bin/env bash
            echo "PHP: $*" >> "$SANDBOX/php.log"

            case "$*" in
                *"cache:warmup"*) echo "warmed"; exit 0 ;;
                *"lint:container"*)
                    if [ -n "${STUB_LINT_FAILS_WITH:-}" ]; then
                        echo "Environment variable not found: \"${STUB_LINT_FAILS_WITH}\"." >&2
                        exit 1
                    fi
                    echo "linted"; exit 0 ;;
                *"migrations:up-to-date"*)
                    if [ "${STUB_SCHEMA_RC:-0}" = "1" ]; then
                        echo "[ERROR] Out-of-date! 5 migrations are available to execute."
                    fi
                    exit "${STUB_SCHEMA_RC:-0}" ;;
                *"migrations:migrate"*) echo "migrated"; exit 0 ;;
                *"catalog:sync"*) echo "synced"; exit 0 ;;
                *"messenger:consume llm"*)
                    echo "LLM WORKER up" >> "$SANDBOX/worker.log"
                    trap 'echo "LLM WORKER got TERM" >> "$SANDBOX/worker.log"; exit 0' TERM
                    if [ -n "${STUB_LLM_CRASH_AFTER:-}" ]; then sleep "$STUB_LLM_CRASH_AFTER"; exit 9; fi
                    while :; do sleep 0.2; done ;;
                *"messenger:consume tools"*)
                    echo "TOOLS WORKER up" >> "$SANDBOX/worker.log"
                    trap 'echo "TOOLS WORKER got TERM" >> "$SANDBOX/worker.log"; exit 0' TERM
                    while :; do sleep 0.2; done ;;
                *) echo "unexpected php call: $*" >&2; exit 3 ;;
            esac
            SH);

        $this->write($this->bin.'/frankenphp', <<<'SH'
            #!/usr/bin/env bash
            echo "WEB up" >> "$SANDBOX/web.log"

            if [ -n "${STUB_WEB_WEDGE:-}" ]; then
                trap '' TERM
            else
                trap 'echo "WEB got TERM" >> "$SANDBOX/web.log"; exit 0' TERM
            fi

            if [ -n "${STUB_WEB_EXIT_AFTER:-}" ]; then
                sleep "$STUB_WEB_EXIT_AFTER"
                echo "WEB self-exit" >> "$SANDBOX/web.log"
                exit "${STUB_WEB_RC:-3}"
            fi

            while :; do sleep 0.2; done
            SH);
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($path, $contents."\n");
        chmod($path, 0700);
    }

    private function waitFor(callable $condition, float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(50_000);
        }

        self::fail('Timed out waiting for the entrypoint to reach the expected state.');
    }

    private function countLines(string $needle, string $file = 'worker.log'): int
    {
        $path = $this->sandbox.'/'.$file;
        if (!is_file($path)) {
            return 0;
        }

        return substr_count((string) file_get_contents($path), $needle);
    }

    private function readPhpLog(): string
    {
        return (string) @file_get_contents($this->sandbox.'/php.log');
    }

    /**
     * Any process still running from this sandbox would leak into later tests
     * (and into CI runs that share a machine).
     */
    private function killStrays(): void
    {
        if (!\function_exists('exec')) {
            return;
        }

        exec("pkill -f '{$this->sandbox}' 2>/dev/null");
    }

    private function removeDirectory(string $directory): void
    {
        if ('' === $directory || !is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
