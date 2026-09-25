<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\ErrorClass;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Executes one tool call: schema validation, then dispatch over MCP
 * Streamable HTTP (validate-before-dispatch, SPEC §5.1). OpenAPI-protocol
 * tools are not executable in v1 — a classified server_error, never a
 * silent path.
 *
 * Per-call clients: a fresh SDK client per call keeps no state between calls,
 * and Streamable HTTP has no persistent connection to reuse anyway (each send()
 * is its own POST). The official client is PSR-18 underneath, so unlike the
 * fork there is no event loop to own or leak.
 */
final class ToolExecutor implements ToolExecutorInterface
{
    private const CLIENT_NAME = 'task-loom';
    private const CLIENT_VERSION = '1.0.0';

    public function __construct(
        private readonly ?int $timeoutSeconds = null,
    ) {
    }

    /**
     * Validate arguments against the tool's JSON Schema (validate-before-dispatch, SPEC §5.1).
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string> validation errors; empty = valid
     */
    #[\Override]
    public function validate(Tool $tool, array $arguments): array
    {
        $schema = $tool->getSchema();

        if ([] === $schema) {
            return []; // no schema recorded — nothing to validate against
        }

        $validator = new Validator();
        $result = $validator->validate($this->toObject($arguments), $this->toObject($schema));

        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        $error = $result->error();
        if (null !== $error) {
            foreach ($this->flattenValidationError($error) as $sub) {
                $errors[] = $sub;
            }
        }

        return $errors;
    }

    /**
     * Execute a tool call against its server (MCP protocol only in v1).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the tool result as a JSON-able array
     *
     * @throws ToolExecutionException classified for the ledger
     */
    #[\Override]
    public function execute(Tool $tool, array $arguments): array
    {
        $server = $tool->getServer();

        if (ServerProtocol::Mcp !== $server->getProtocol()) {
            throw new ToolExecutionException(\sprintf('Tool "%s" is on an OpenAPI server; OpenAPI tool execution is not supported in v1.', $tool->getName()), ErrorClass::ServerError);
        }

        $start = microtime(true);
        $client = null;

        try {
            $client = $this->buildClient($server->getUrl());
            $result = $client->callTool($tool->getName(), $arguments);
        } catch (\Throwable $e) {
            // Every failure is classified a server error: whether the endpoint
            // is unreachable, rejects the call, or returns a malformed result,
            // the run engine's remedy is the same (record it, do not retry
            // blindly, surface it). The distinction that matters to the caller
            // is `isError` on the *result*, which is returned, not thrown.
            throw new ToolExecutionException('Tool call failed: '.$this->describe($e), ErrorClass::ServerError);
        } finally {
            if (null !== $client) {
                try {
                    $client->disconnect();
                } catch (\Throwable) {
                    // disconnect failures are never fatal for the run
                }
            }
        }

        return $this->resultToArray($result, $tool, $start);
    }

    /**
     * @return list<string>
     */
    private function flattenValidationError(ValidationError $error): array
    {
        $errors = [$error->message()];

        foreach ($error->subErrors() as $sub) {
            foreach ($this->flattenValidationError($sub) as $msg) {
                $errors[] = $msg;
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed> the tool result as a JSON-able array
     */
    private function resultToArray(CallToolResult $result, Tool $tool, float $start): array
    {
        $texts = [];
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $texts[] = $content->text;
            }
        }

        return [
            'tool' => $tool->getName(),
            'content' => implode("\n", $texts),
            'isError' => $result->isError,
            'durationMs' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    private function buildClient(string $url): Client
    {
        $timeout = $this->timeoutSeconds ?? 60;

        $client = Client::builder()
            ->setClientInfo(self::CLIENT_NAME, self::CLIENT_VERSION)
            ->setInitTimeout($timeout)
            ->setRequestTimeout($timeout)
            // A tool call is not necessarily idempotent, so retrying the
            // handshake is fine but retrying the call is not — and the SDK only
            // ever retries the handshake. Keep the default low anyway: a run
            // that is going to fail should fail promptly.
            ->setMaxRetries(1)
            ->build();

        // Streamable HTTP only (SPEC §11). A tool call needs no session of its
        // own: connect() performs the handshake, callTool() carries it, and
        // disconnect() closes it — all within this method.
        $client->connect(new HttpTransport(endpoint: $url));

        return $client;
    }

    /**
     * A message suitable for logging and the UI, without a stack trace.
     *
     * The instructions to ServerReader apply here too: reader/executor
     * exceptions carry no credential values.
     */
    private function describe(\Throwable $e): string
    {
        return $e::class.': '.$e->getMessage();
    }

    /**
     * Convert an associative array to stdClass for opis validation
     * (opis expects objects at the document root).
     *
     * @param array<string, mixed> $data
     */
    private function toObject(array $data): \stdClass
    {
        return json_decode((string) json_encode($data)) ?: new \stdClass();
    }
}
