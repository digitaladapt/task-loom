<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\ErrorClass;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\Toolbox\Transport\StreamableTransportFactory;
use Opis\JsonSchema\Validator;
use PhpMcp\Client\Client;
use PhpMcp\Client\ClientBuilder;
use PhpMcp\Client\ClientConfig;
use PhpMcp\Client\Enum\TransportType;
use PhpMcp\Client\Exception\RequestException;
use PhpMcp\Client\JsonRpc\Results\CallToolResult;
use PhpMcp\Client\Model\Capabilities as ClientCapabilities;
use PhpMcp\Client\Model\Content\TextContent;
use PhpMcp\Client\ServerConfig;

/**
 * Executes one tool call: schema validation, then dispatch over MCP
 * Streamable HTTP (validate-before-dispatch, SPEC §5.1). OpenAPI-protocol
 * tools are not executable in v1 — a classified server_error, never a
 * silent path.
 *
 * Per-call clients: a fresh SDK client per call avoids loop ownership
 * issues with the SDK's ReactPHP internals; Streamable HTTP has no
 * persistent connection anyway (each send() is its own POST).
 */
final class ToolExecutor implements ToolExecutorInterface
{
    private const string CLIENT_VERSION = '1.0.0';

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
     * @return list<string>
     */
    private function flattenValidationError(\Opis\JsonSchema\Errors\ValidationError $error): array
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
            $client = $this->buildClient($server->getUrl(), $server->getName());
            $result = $client->callTool($tool->getName(), $arguments);
        } catch (RequestException $e) {
            throw new ToolExecutionException('Tool call rejected: '.$e->getMessage(), ErrorClass::ServerError);
        } catch (\Throwable $e) {
            throw new ToolExecutionException('Tool call failed: '.$e->getMessage(), ErrorClass::ServerError);
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

    private function buildClient(string $url, string $serverName): Client
    {
        $config = new ServerConfig(
            name: $serverName,
            transport: TransportType::Http,
            url: $url,
            timeout: $this->timeoutSeconds ?? 60.0,
        );

        $client = ClientBuilder::make()
            ->withClientInfo('task-loom', self::CLIENT_VERSION)
            ->withServerConfig($config)
            ->withTransportFactory(new StreamableTransportFactory(
                new ClientConfig('task-loom', self::CLIENT_VERSION, ClientCapabilities::forClient()),
            ))
            ->build();

        $client->initialize();

        return $client;
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
