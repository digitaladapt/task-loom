<?php

declare(strict_types=1);

namespace App\Tests\Container;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The deployment contract, as tests.
 *
 * Every check here guards a failure mode this project has already hit or would
 * hit silently: a compose file that cannot boot (the image disables dotenv, so
 * a variable the app requires and the compose file does not pass is a hard
 * boot failure), a worker fleet that quietly shrinks to zero, or workers that
 * are SIGKILLed mid-turn because the stop window is shorter than the
 * entrypoint's graceful-shutdown window.
 *
 * These are file-level assertions rather than container runs: the point is to
 * catch drift in review, on a machine with no Docker daemon.
 */
final class DeploymentContractTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * Every variable the application resolves via %env(...)%, discovered from
     * config/ rather than hand-listed, so a new one cannot be forgotten in
     * the deployment files.
     *
     * Scans config/services.yaml as well as config/packages/: the service
     * wiring is where the engine, catalog and scheduler knobs live, and an
     * earlier version of this helper only looked in packages/ — so a variable
     * added to services.yaml (as TASKLOOM_TIMEZONE was) would not have been
     * flagged as missing from the compose files. Discovery must match where
     * config actually lives.
     *
     * Two kinds of variable are discovered, and they are held to different
     * standards by the caller:
     *
     *  - REQUIRED — no `default::` guard. The container will not boot without
     *    a value, so the compose file must supply one.
     *  - OPTIONAL — wrapped in `default::`/`bool:default::`. The app boots on
     *    its built-in default, but the compose file must still FORWARD the
     *    name: the image runs with dotenv disabled, so a variable the compose
     *    file does not pass cannot be set by the operator at all. An
     *    unforwarded knob is silently inert — configure TASKLOOM_UNITS in
     *    .env, see metric anyway — which is exactly the quiet-wrongness this
     *    contract exists to catch.
     *
     * Symfony's own variables (SYMFONY_*, TEST_TOKEN) are skipped: they are
     * framework/harness plumbing with deliberate runtime semantics, not
     * deployment knobs, and several are meaningful only in specific
     * environments.
     *
     * @return array{required: list<string>, optional: list<string>}
     */
    private function envContract(): array
    {
        $required = [];
        $optional = [];

        $directory = new \RecursiveDirectoryIterator(self::ROOT.'/config', \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($directory);

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if (!\in_array($file->getExtension(), ['yaml', 'php'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // The lazy prefix lets the capture start at the final name, so
            // `enum:App\Context\Units:TASKLOOM_UNITS` yields TASKLOOM_UNITS.
            preg_match_all('/%env\((?P<processors>[^)]*?)(?P<name>[A-Z][A-Z0-9_]*)\)%/', $contents, $matches, \PREG_SET_ORDER);

            foreach ($matches as $match) {
                $name = $match['name'];
                if (!str_starts_with($name, 'TASKLOOM_')) {
                    continue;
                }

                // A `default::` anywhere in the processor chain is the guard.
                if (str_contains($match['processors'], 'default::')) {
                    $optional[$name] = true;
                } else {
                    $required[$name] = true;
                }
            }
        }

        return ['required' => array_keys($required), 'optional' => array_keys($optional)];
    }

    public function testTheProdEntrypointVerifiesTheEnvContractBeforeBoot(): void
    {
        $entrypoint = (string) file_get_contents(self::ROOT.'/docker/entrypoint.sh');

        self::assertStringContainsString(
            'lint:container --resolve-env-vars',
            $entrypoint,
            'the entrypoint must fail fast on a missing variable, naming it, instead of letting a worker die mid-run',
        );
    }

    /**
     * Both compose files must carry the whole TASKLOOM_ env contract: every
     * required variable (or the container cannot boot) and every optional one
     * (or the operator cannot set it — the image disables dotenv, so an
     * unforwarded knob is silently inert).
     */
    public function testBothComposeFilesProvideTheFullEnvContract(): void
    {
        foreach (['/compose.yaml', '/docs/examples/compose.yaml'] as $file) {
            $parsed = Yaml::parseFile(self::ROOT.$file);
            self::assertIsArray($parsed, "$file must parse");
            self::assertArrayHasKey('services', $parsed, "$file must define services");

            // Collect every environment mapping in the file (services may share
            // a YAML-anchored map).
            $provided = [];
            foreach ($parsed['services'] as $service) {
                foreach (($service['environment'] ?? []) as $key => $value) {
                    $provided[$key] = $value;
                }
            }

            $contract = $this->envContract();

            $missing = [];
            foreach ([...$contract['required'], ...$contract['optional']] as $name) {
                if (!\array_key_exists($name, $provided)) {
                    $missing[] = $name;
                }
            }

            self::assertSame(
                [],
                $missing,
                \sprintf('%s must pass every variable the app resolves (image runs with dotenv disabled): %s', $file, implode(', ', $missing)),
            );
        }
    }

    /**
     * The contract is discovered, not hand-listed — so this test proves the
     * discovery actually works on the shapes config/ uses. A regex that
     * silently matched nothing would make the check above vacuous.
     */
    public function testTheEnvContractDiscoveryFindsTheKnownKnobs(): void
    {
        $contract = $this->envContract();

        self::assertContains('TASKLOOM_TIMEZONE', $contract['required'], 'a plain %env(...)% is required');
        self::assertContains('TASKLOOM_STEP_BUDGET', $contract['required'], 'a processed %env(int:...)% is required');

        self::assertContains('TASKLOOM_UNITS', $contract['optional'], 'a default::-guarded name is optional');
        self::assertContains('TASKLOOM_SYSTEM_PROMPT', $contract['optional']);
        self::assertContains('TASKLOOM_DEBUG_RAW_LLM', $contract['optional'], 'the raw-response dump is opt-in');

        self::assertNotContains('SYMFONY_IDE', $contract['required'] + $contract['optional'], 'framework plumbing is not a TASKLOOM_ knob');
        self::assertNotContains('TEST_TOKEN', $contract['required'] + $contract['optional']);
    }

    public function testSecretsUseTheFailFastForm(): void
    {
        foreach (['/compose.yaml', '/docs/examples/compose.yaml'] as $file) {
            $contents = (string) file_get_contents(self::ROOT.$file);

            self::assertStringContainsString('${APP_SECRET:?', $contents, "$file: APP_SECRET must use the \":?\" form (never a silently-empty default)");
            self::assertStringContainsString('${TASKLOOM_ADMIN_PASSWORD:?', $contents, "$file: the admin password must use the \":?\" form");
        }
    }

    public function testEachComposeFileDeploysTheSchemaBeforeTheApp(): void
    {
        foreach (['/compose.yaml', '/docs/examples/compose.yaml'] as $file) {
            $parsed = Yaml::parseFile(self::ROOT.$file);
            $services = $parsed['services'] ?? [];

            self::assertArrayHasKey('migrate', $services, "$file must ship the one-shot migrate service");

            $app = $services['taskloom'] ?? null;
            self::assertIsArray($app, "$file must define the app service");

            $condition = $app['depends_on']['migrate']['condition'] ?? null;
            self::assertSame(
                'service_completed_successfully',
                $condition,
                "$file: the app must start only after migrations succeed (§8.6)",
            );
        }
    }

    public function testTheAppStopWindowOutlastsTheGracefulShutdownWindow(): void
    {
        foreach (['/compose.yaml', '/docs/examples/compose.yaml'] as $file) {
            $parsed = Yaml::parseFile(self::ROOT.$file);
            $grace = $parsed['services']['taskloom']['stop_grace_period'] ?? null;

            self::assertIsString($grace, "$file must set stop_grace_period for the app service");

            // Accept the compose duration forms (60s, 1m, 1m30s) and compare to
            // the entrypoint's default escalation window.
            $seconds = $this->durationToSeconds($grace);
            self::assertGreaterThan(
                30,
                $seconds,
                "$file: stop_grace_period ($grace) must exceed the entrypoint's default TASKLOOM_SHUTDOWN_TIMEOUT (30s), or Docker SIGKILLs the fleet mid-turn",
            );
        }
    }

    public function testNoComposeFileScalesWorkersWithASeparateService(): void
    {
        foreach (['/compose.yaml', '/docs/examples/compose.yaml'] as $file) {
            $parsed = Yaml::parseFile(self::ROOT.$file);
            $services = array_keys($parsed['services'] ?? []);

            // The fleet is a runtime concern of the one container; a second
            // worker service would reintroduce the drift the entrypoint removes
            // (it would also double the number of llm workers).
            self::assertNotContains('worker-llm', $services, "$file must not define a separate llm worker service");
            self::assertNotContains('worker-tools', $services, "$file must not define a separate tools worker service");
        }
    }

    public function testTheDockerfileRunsTheSupervisorAsEntrypoint(): void
    {
        $dockerfile = (string) file_get_contents(self::ROOT.'/Dockerfile');

        self::assertStringContainsString('ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint"]', $dockerfile, 'tini is PID 1 and forwards signals to the supervisor');
        self::assertStringContainsString('CMD ["serve"]', $dockerfile, 'the default command runs the whole application from one container');
    }

    private function durationToSeconds(string $duration): int
    {
        preg_match('/^(?:(\d+)m)?(?:(\d+)s)?$/', trim($duration), $matches);

        $minutes = (int) ($matches[1] ?? 0);
        $seconds = (int) ($matches[2] ?? 0);

        return $minutes * 60 + $seconds;
    }
}
