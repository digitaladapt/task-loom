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
     * @return list<string>
     */
    private function requiredEnvVars(): array
    {
        $names = [];

        foreach (['yaml', 'php'] as $extension) {
            $files = glob(self::ROOT.'/config/packages/*.'.$extension) ?: [];
            $files = array_merge($files, glob(self::ROOT.'/config/packages/*/*.'.$extension) ?: []);

            foreach ($files as $file) {
                preg_match_all('/%env\((?:(?:[a-z_]+):)*([A-Z][A-Z0-9_]*)\)%/', (string) file_get_contents($file), $matches);
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
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

            // DATABASE_URL and MESSENGER_TRANSPORT_DSN are covered by defaults
            // in .env for local use, but the image disables dotenv: a container
            // deployment must pass them explicitly or boot fails.
            $missing = [];
            foreach ($this->requiredEnvVars() as $name) {
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
