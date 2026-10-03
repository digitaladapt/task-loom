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
        self::assertSame(1, $this->countLines('SCHEDULER up'), 'one scheduler daemon, SPEC §14');
        self::assertSame(1, $this->countLines('WEB up', 'web.log'), 'the web process runs alongside the workers');
    }

    public function testServeNoWebRunsTheFleetWithoutTheWebProcess(): void
    {
        $process = $this->runScript(['serve', '--no-web'], [
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '2',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '1',
        ]);

        try {
            $this->waitFor(fn (): bool => 2 === $this->countLines('LLM WORKER up'));
            usleep(300_000);
        } finally {
            $this->stopServe($process);
        }

        self::assertSame(0, $this->countLines('WEB up', 'web.log'), '--no-web must not start the web process');
        self::assertSame(2, $this->countLines('LLM WORKER up'), 'the workers are the point of this mode');
        self::assertSame(1, $this->countLines('TOOLS WORKER up'));
        self::assertStringContainsString('no web', $process->getErrorOutput());
    }

    public function testServeNoWebRefusesToSuperviseNothing(): void
    {
        // The mode exists to run workers. With no workers and no scheduler it
        // would hold the container open doing exactly nothing, which is the
        // kind of quiet no-op that looks like success from the outside.
        $process = $this->runServeToCompletion([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '0',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
            'TASKLOOM_SCHEDULER_ENABLED' => '0',
        ], ['serve', '--no-web']);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('would supervise nothing', $process->getErrorOutput());
        self::assertSame(0, $this->countLines('WEB up', 'web.log'));
    }

    public function testBootSweepRunsBeforeAnyWorkerStarts(): void
    {
        // Order is the whole correctness argument (SPEC §6.2): the reap is only
        // sound while no worker exists, and the requeue must precede the spawn
        // or it dispatches onto a lane nobody has reached yet.
        $process = $this->startServe(['TASKLOOM_LLM_MAX_CONCURRENCY' => '1']);

        try {
            $this->waitFor(fn (): bool => 1 === $this->countLines('LLM WORKER up'));
        } finally {
            $this->stopServe($process);
        }

        $log = $this->readPhpLog();
        self::assertStringContainsString('app:run:requeue --startup', $log);

        $sweep = strpos($log, 'app:run:requeue --startup');
        $firstWorker = strpos($log, 'messenger:consume llm');
        self::assertNotFalse($sweep);
        self::assertNotFalse($firstWorker, 'the fleet must start at all');
        self::assertLessThan($firstWorker, $sweep, 'the boot sweep must complete before the first worker consumes');
    }

    public function testAServeWithNoLocalLlmWorkerDoesNotSweepOnBoot(): void
    {
        // THE EXTERNAL-CONSUMER HOLE (SPEC §6.2's own second topology).
        //
        // `serve` is the worker fleet's supervisor, so it is marked a fleet
        // owner whether or not it runs any llm worker — the flag means "I start
        // workers", and a host running only tools workers starts some. But the
        // sweep's soundness argument is narrower than the flag it is gated on.
        // It is "no worker in this process group can be mid-turn, therefore any
        // claim in the table is a dead predecessor's" — and that is only true
        // of a lane this process actually consumes. With
        // TASKLOOM_LLM_MAX_CONCURRENCY=0 the llm lane is consumed by a peer this
        // process cannot see, and at the default bound (any age) the sweep
        // clears that peer's claims, re-dispatches its runs, and resets
        // lock_version — after which the peer's committed turn is discarded as
        // Stale and the work is simply done twice, live side effects and all.
        //
        // The single container's stop-time trouble (a turn that outlived the
        // shutdown window) is repaired by the *successor*, which does run an llm
        // worker and does sweep. The skipping case is the one that needs it said
        // out loud.
        $process = $this->startServe([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '0',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '1',
        ]);

        try {
            $this->waitFor(fn (): bool => 1 === $this->countLines('TOOLS WORKER up'));
        } finally {
            $this->stopServe($process);
        }

        self::assertStringContainsString(
            '[fleet-owner=1]',
            $this->readPhpLog(),
            'the process is still an owner — it supervises the tools worker — so the gate must be what declines the sweep',
        );
        self::assertStringNotContainsString(
            'app:run:requeue --startup',
            $this->readPhpLog(),
            'a fleet that consumes no llm turns cannot know the llm lane is idle, so it must not reap claims a peer may be holding',
        );
        self::assertStringContainsString(
            'boot sweep skipped',
            $process->getErrorOutput(),
            'a silently skipped sweep is indistinguishable from a sweep with nothing to do',
        );
    }

    public function testAWorkerFleetWithNoToolsWorkerDoesNotSweepOnBoot(): void
    {
        // The same hole on the other lane, and the reason the rule is stated as
        // "every lane this fleet might steal from" rather than "the llm lane".
        // A claim is held for one LLM request *or* one set of tool calls, so a
        // fleet running llm workers but no tools worker can still clear a
        // peer's tool claim — and a stolen tool turn is the worse of the two,
        // because tool calls are where the side effects are.
        $process = $this->startServe([
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '0',
        ]);

        try {
            $this->waitFor(fn (): bool => 1 === $this->countLines('LLM WORKER up'));
        } finally {
            $this->stopServe($process);
        }

        self::assertStringNotContainsString(
            'app:run:requeue --startup',
            $this->readPhpLog(),
            'a fleet that consumes no tool turns cannot know the tools lane is idle',
        );
        self::assertStringContainsString('boot sweep skipped', $process->getErrorOutput());
    }

    public function testTheFleetOwningProcessGroupIsMarkedAsSuch(): void
    {
        // This is the authority the sweep is gated on: set for the process
        // that starts workers, and handed to the children too.
        $process = $this->startServe(['TASKLOOM_LLM_MAX_CONCURRENCY' => '1']);

        try {
            $this->waitFor(fn (): bool => 1 === $this->countLines('LLM WORKER up'));
        } finally {
            $this->stopServe($process);
        }

        self::assertStringContainsString('app:run:requeue --startup --no-interaction [fleet-owner=1]', $this->readPhpLog());
    }

    public function testTheFleetGetsAFreshIdentityOnEveryStart(): void
    {
        // Attribution only, and deliberately so. The identity is NOT what
        // decides the sweep — a first cut used it as a permission check and
        // declined every claim in the table, because every restart is a new
        // identity and so every leftover claim is "another fleet" (SPEC §6.2).
        // The sweep decides on age instead. What a fresh identity buys is the
        // ability to attribute a claim to a particular start of the container
        // after the fact, which is why a compose-supplied value would be wrong:
        // one label shared by every service and every restart explains nothing
        // about who held what.
        $first = $this->readFleetIdFromServe();
        $second = $this->readFleetIdFromServe();

        self::assertNotNull($first, 'a fleet-owning process must identify itself');
        self::assertNotNull($second);
        self::assertNotSame(
            $first,
            $second,
            'two starts must be distinguishable, or a claim cannot be attributed to the run that left it',
        );
    }

    public function testAOneShotContainerHasNoFleetIdentity(): void
    {
        // No identity means no claim can be attributed to this process, so
        // nothing is ever swept on its say-so. The belt to the owner gate's
        // braces.
        $process = $this->runScript(
            ['php', 'bin/console', 'app:run:requeue', '--startup'],
            [],
            wait: true,
        );

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('[fleet-id=unset]', $this->readPhpLog());
    }

    /**
     * Start a real `serve`, read the identity it exported, then stop it.
     *
     * The wait is on *new* output, and it has to be. These tests share one
     * sandbox, so the previous start's lines are still in php.log: a
     * count-based wait ("is there an LLM WORKER up yet?") is satisfied
     * instantly by the corpse of the last run, and the new process is stopped
     * before it has done anything at all. That is a bug in the helper, not in
     * the entrypoint — the same trap the production code avoids by keying on
     * identity rather than an absolute condition.
     */
    private function readFleetIdFromServe(): ?string
    {
        $path = $this->sandbox.'/php.log';
        $before = is_file($path) ? (string) file_get_contents($path) : '';
        $beforeLength = \strlen($before);

        $process = $this->runScript(['serve'], ['TASKLOOM_LLM_MAX_CONCURRENCY' => '1']);

        try {
            $this->waitFor(fn (): bool => null !== $this->fleetIdAfter($path, $beforeLength), 15.0);
        } finally {
            $this->stopServe($process);
        }

        return $this->fleetIdAfter($path, $beforeLength);
    }

    public function testTheFleetIdentityIsWrittenWhereAForeignProcessCanReadIt(): void
    {
        // The web UI shares the container's process tree but NOT its
        // environment (the entrypoint exports the id before spawning children,
        // and the UI is a child — but a request is not the fleet, and in a
        // split deployment there is no shared environment at all). So the
        // identity is published to a file in the data directory, which is the
        // only thing a non-child can reliably read.
        //
        // This test also pins an ORDERING bug that produced a working-looking
        // feature: the file is written inside the serve path's fleet block,
        // which runs BEFORE the boot phases — so deriving the data directory
        // alongside them left DATA_DIR empty at the moment of the write, the
        // `<if [ -n "$DATA_DIR" ]>` guard silently skipped it, and nothing was
        // ever published. Best-effort writes need a test that asserts the
        // effort actually succeeded, or "best-effort" becomes "never".
        $dataDir = $this->sandbox.'/data';
        $logOffset = is_file($this->sandbox.'/php.log') ? \strlen((string) file_get_contents($this->sandbox.'/php.log')) : 0;

        // A fresh container start must publish a *fresh* identity, so an older
        // file from a previous test would hide a regression. (This is the same
        // trap that made readFleetIdFromServe() lie: a condition already
        // satisfied by the previous run's corpse.)
        if (is_file($dataDir.'/fleet-id')) {
            unlink($dataDir.'/fleet-id');
        }

        // No TASKLOOM_DATA_DIR: the point is that it is DERIVED, and derived
        // correctly. DATABASE_URL deliberately carries the Symfony placeholder
        // literally — that is what the deployment files contain — so this also
        // pins the bug where the placeholder was treated as ordinary path text
        // and produced a literal `%kernel.project_dir%` directory instead of
        // the data volume.
        $process = $this->runScript(['serve'], [
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'DATABASE_URL' => 'sqlite:///%kernel.project_dir%/data/taskloom.db',
        ]);

        try {
            $this->waitFor(fn (): bool => is_file($dataDir.'/fleet-id'));
        } finally {
            $this->stopServe($process);
        }

        self::assertFileExists($dataDir.'/fleet-id', 'the fleet identity must be published for processes that did not inherit the environment');
        self::assertDirectoryDoesNotExist(
            $this->sandbox.'/%kernel.project_dir%',
            'the project-dir placeholder must be resolved, not used as a literal path',
        );

        $written = (string) file_get_contents($dataDir.'/fleet-id');
        self::assertNotSame('', $written);
        self::assertSame(1, preg_match('/^\d+-\d+-\d+$/', $written), "unexpected identity shape: '$written'");

        // And it matches what the children were given — the published identity
        // is the fleet's, not a second one invented for the file's benefit.
        $fromLog = $this->fleetIdAfter($this->sandbox.'/php.log', $logOffset);
        self::assertNotNull($fromLog, 'the children should carry the identity too');
        self::assertSame($fromLog, $written);
    }

    /**
     * The fleet id in php.log beyond $offset, or null while there is none yet.
     */
    private function fleetIdAfter(string $path, int $offset): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $added = substr((string) file_get_contents($path), $offset);

        return 1 === preg_match('/fleet-id=([^\]\s]+)/', $added, $matches) ? $matches[1] : null;
    }

    public function testAOneShotContainerIsNotMarkedAsTheFleetOwner(): void
    {
        // The compose `migrate` service, or any console command. It starts no
        // workers, so it has no authority over another process's claims — and
        // the flag must be absent from its environment, not merely unused.
        $process = $this->runScript(
            ['php', 'bin/console', 'app:run:requeue', '--startup'],
            [],
            wait: true,
        );

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('[fleet-owner=unset] [fleet-id=unset]', $this->readPhpLog());
        self::assertSame(0, $this->countLines('LLM WORKER up'), 'a one-shot container starts no workers');
    }

    public function testSchedulerDaemonIsDisabledByTheKnob(): void
    {
        $process = $this->startServe([
            'TASKLOOM_SCHEDULER_ENABLED' => '0',
        ]);

        try {
            usleep(300_000);
        } finally {
            $this->stopServe($process);
        }

        self::assertSame(0, $this->countLines('SCHEDULER up'), 'the knob keeps the daemon out of the fleet');
        self::assertStringContainsString('scheduler disabled', $process->getErrorOutput());
        self::assertSame(1, $this->countLines('LLM WORKER up'), 'the rest of the fleet still starts');
    }

    public function testSchedulerIsRestartedWhenItExits(): void
    {
        $process = $this->startServe([
            'STUB_SCHEDULER_CRASH_AFTER' => '0.3',
        ]);

        try {
            $this->waitFor(fn (): bool => $this->countLines('SCHEDULER up') >= 2, 15.0);
        } finally {
            $this->stopServe($process);
        }

        self::assertGreaterThanOrEqual(2, $this->countLines('SCHEDULER up'), 'the scheduler daemon is supervised like any worker');
    }

    public function testSchedulerCarriesTheConfiguredInterval(): void
    {
        $process = $this->startServe([
            'TASKLOOM_SCHEDULE_INTERVAL' => '90',
        ]);

        try {
            $this->waitFor(fn (): bool => $this->countLines('SCHEDULER up') >= 1);
        } finally {
            $this->stopServe($process);
        }

        self::assertStringContainsString('app:schedule:run --interval=90', $this->readPhpLog());
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
        self::assertSame(1, $this->countLines('SCHEDULER got TERM'), 'the scheduler daemon is asked to stop too');
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
        yield 'scheduler toggle is 0 or 1' => ['TASKLOOM_SCHEDULER_ENABLED', 'yes', '0 or 1'];
        yield 'zero schedule interval' => ['TASKLOOM_SCHEDULE_INTERVAL', '0', '>='];
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
        // A typo in a flag must not be ignored: silently keeping the web
        // process, or silently dropping it, is the quiet wrongness the rest of
        // this script refuses.
        $process = $this->runScript(['serve', 'something'], [], wait: true);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('serve takes no arguments but --no-web', $process->getErrorOutput());
        self::assertSame(0, $this->countLines('LLM WORKER up'), 'nothing starts on a refused argument');
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
    /**
     * @param array<string, string> $env
     * @param list<string>          $arguments
     */
    private function runServeToCompletion(array $env, array $arguments = ['serve']): Process
    {
        $process = $this->runScript($arguments, $env);
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
            //
            // Both lanes default to a worker on purpose: the boot sweep only
            // runs for a fleet that consumes every lane the claim protocol
            // spans (see the entrypoint's boot-recovery block), so a harness
            // that defaulted a lane to 0 would make "the sweep ran" a claim
            // about a topology the sweep declines — which is exactly how two
            // tests came to encode a fleet with no tools worker reaping the
            // tools lane. Tests that mean to model a partial fleet say so.
            'TASKLOOM_LLM_MAX_CONCURRENCY' => '1',
            'TASKLOOM_TOOL_MAX_CONCURRENCY' => '1',
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
            # The fleet-owner flag is recorded on every invocation: whether a
            # process is entitled to reap claims is part of the entrypoint's
            # contract, and the only place it is observable is the child env.
            echo "PHP: $* [fleet-owner=${TASKLOOM_FLEET_OWNER:-unset}] [fleet-id=${TASKLOOM_FLEET_ID:-unset}]" >> "$SANDBOX/php.log"

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
                *"app:run:requeue"*) echo "requeued"; exit 0 ;;
                *"messenger:consume llm"*)
                    echo "LLM WORKER up" >> "$SANDBOX/worker.log"
                    trap 'echo "LLM WORKER got TERM" >> "$SANDBOX/worker.log"; exit 0' TERM
                    if [ -n "${STUB_LLM_CRASH_AFTER:-}" ]; then sleep "$STUB_LLM_CRASH_AFTER"; exit 9; fi
                    while :; do sleep 0.2; done ;;
                *"messenger:consume tools"*)
                    echo "TOOLS WORKER up" >> "$SANDBOX/worker.log"
                    trap 'echo "TOOLS WORKER got TERM" >> "$SANDBOX/worker.log"; exit 0' TERM
                    while :; do sleep 0.2; done ;;
                *"app:schedule:run"*)
                    echo "SCHEDULER up" >> "$SANDBOX/worker.log"
                    trap 'echo "SCHEDULER got TERM" >> "$SANDBOX/worker.log"; exit 0' TERM
                    if [ -n "${STUB_SCHEDULER_CRASH_AFTER:-}" ]; then sleep "$STUB_SCHEDULER_CRASH_AFTER"; exit 9; fi
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
