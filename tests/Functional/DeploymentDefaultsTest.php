<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Context\ContextWindow;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The app must be able to answer a request with only the repository's own
 * configuration — no `compose.yaml`, no harness, no machine-local `.env.local`.
 *
 * This is a regression guard for a real production report. `compose.yaml`
 * defaults every tuning knob (`${TASKLOOM_STEP_BUDGET:-50}`), so "forgot to set
 * it" was harmless inside Docker and fatal everywhere else: every real page
 * returned
 *
 *   500  Environment variable not found: "TASKLOOM_MAX_INPUT_ARTIFACT_PCT"
 *
 * while `/health` stayed green, because services are built lazily and the health
 * route never touches the context window. The failure was invisible from the one
 * endpoint an operator checks first, and it only appeared when the chat feature
 * started constructing the window on the paths a human uses.
 *
 * A test cannot easily prove "this boots with an empty environment" (the test
 * kernel loads `.env.test`), so it asserts the property that makes it true: each
 * knob has an app-level default, and the service resolves to it when the
 * environment says nothing.
 */
final class DeploymentDefaultsTest extends WebTestCase
{
    /**
     * The knobs whose absence used to be fatal, with the value each must
     * default to. Deleting a parameter, or forgetting a new knob, fails here.
     */
    private const array DEFAULTS = [
        'TASKLOOM_CONTEXT_LIMIT' => 32768,
        'TASKLOOM_MAX_TOOL_OUTPUT_PCT' => 15,
        'TASKLOOM_MAX_INPUT_ARTIFACT_PCT' => 50,
        'TASKLOOM_WINDOW_TAIL_EXCHANGES' => 10,
        'TASKLOOM_SESSION_HOT' => 5,
        'TASKLOOM_SESSION_COLD' => 25,
        'TASKLOOM_SESSION_WRITE_MAX_CHARS' => 2000,
        'TASKLOOM_SESSION_MAX_MEMORY_PCT' => 10,
        'TASKLOOM_STEP_BUDGET' => 50,
        'TASKLOOM_TOOL_RETRIES' => 2,
        'TASKLOOM_CIRCUIT_BREAKER' => 3,
        'TASKLOOM_LLM_MODEL' => 'qwen3:14b',
        'TASKLOOM_LLM_TIMEOUT' => 300,
    ];

    public function testEveryTuningKnobHasADeploymentDefault(): void
    {
        $container = static::getContainer();

        foreach (self::DEFAULTS as $name => $expected) {
            self::assertTrue(
                $container->hasParameter($name),
                \sprintf('%s has no deployment default, so a deployment that omits it will 500.', $name),
            );
            self::assertSame(
                $expected,
                $container->getParameter($name),
                \sprintf('%s defaults to a different value than .env.example and compose.yaml state.', $name),
            );
        }
    }

    /**
     * And the default is what an unconfigured variable actually resolves to.
     *
     * `.env.test` deliberately pins several of these (so the suite exercises the
     * rendered prompt sections), so this asserts the *service* agrees with
     * whatever the environment resolved to — which is the property that holds
     * whether or not a value was supplied.
     */
    public function testTheContextWindowResolvesItsKnobsFromTheDeployment(): void
    {
        $container = static::getContainer();

        $window = $container->get('test.service_container')->get(ContextWindow::class);
        \assert($window instanceof ContextWindow);

        $reflection = new \ReflectionObject($window);

        $expected = [
            'contextLimitTokens' => (int) $container->getParameter('TASKLOOM_CONTEXT_LIMIT'),
            'maxToolOutputPct' => (float) $container->getParameter('TASKLOOM_MAX_TOOL_OUTPUT_PCT'),
            'maxInputArtifactPct' => (float) $container->getParameter('TASKLOOM_MAX_INPUT_ARTIFACT_PCT'),
            'windowTailExchanges' => (int) $container->getParameter('TASKLOOM_WINDOW_TAIL_EXCHANGES'),
            'maxSessionMemoryPct' => (float) $container->getParameter('TASKLOOM_SESSION_MAX_MEMORY_PCT'),
        ];

        foreach ($expected as $property => $value) {
            $actual = $reflection->getProperty($property)->getValue($window);

            // A pinned .env.test value wins over the default, and that is the
            // point: the default is a floor, not an override.
            self::assertSame(
                $value,
                $actual,
                \sprintf('ContextWindow::$%s should resolve to the deployment value (%s).', $property, $value),
            );
        }
    }

    /**
     * The knobs that must NOT have a default, so nobody "helpfully" adds one.
     *
     * A wrong timezone is a silent bug — an 08:00 schedule running at 08:00 UTC —
     * so its absence should be loud. The two credentials are the security
     * boundary and no default is the feature. `LLM_BASE_URL`'s compose fallback
     * is a Docker-only hostname.
     */
    public function testTheKnobsThatMustStayRequiredAreStillRequired(): void
    {
        $container = static::getContainer();

        foreach (['TASKLOOM_TIMEZONE', 'TASKLOOM_ADMIN_PASSWORD', 'TASKLOOM_MCP_API_KEY', 'TASKLOOM_LLM_BASE_URL'] as $name) {
            self::assertFalse(
                $container->hasParameter($name),
                \sprintf('%s must stay required — see config/services.yaml for why.', $name),
            );
        }
    }
}
