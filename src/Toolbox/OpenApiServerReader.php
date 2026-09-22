<?php

declare(strict_types=1);

namespace App\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads tools from an OpenAPI (HTTP/JSON) server: fetches the spec, resolves
 * internal $refs, and turns every path+method operation into a tool.
 *
 * The tool's "arguments schema" is derived from the operation's parameters
 * (path, query, header). Request-body parameters are deliberately NOT part
 * of the schema in v1 — the OpenAPI bridge is aimed at read-style APIs
 * (weather, calendar) where GET/query params are the norm. POST bodies need
 * their own design pass (which the run engine's tool-call validation would
 * reject loudly anyway, so nothing silently misbehaves).
 *
 * Tool names: operationId when present (sanitized), else "method_path"
 * slugified. Names must be unique per server; duplicates are appended with
 * a numeric suffix.
 *
 * side_effect: OpenAPI has no purity marker, so non-GET methods are marked
 * side-effecting — the safe default for a remote call we cannot inspect.
 */
final readonly class OpenApiServerReader implements ServerReader
{
    private const int TIMEOUT = 30;

    private HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => self::TIMEOUT]);
    }

    #[\Override]
    public function read(McpServer $server): array
    {
        if (ServerProtocol::OpenApi !== $server->getProtocol()) {
            throw new \LogicException(\sprintf('OpenApiServerReader cannot read a %s server.', $server->getProtocol()->value));
        }

        $spec = $this->fetchSpec($server->getUrl());

        return $this->specToTools($spec);
    }

    /**
     * @return array<string, mixed> the decoded spec document
     */
    private function fetchSpec(string $url): array
    {
        $response = $this->httpClient->request('GET', $url);

        $status = $response->getStatusCode();
        if (200 !== $status) {
            throw new \RuntimeException(\sprintf('OpenAPI spec fetch returned HTTP %d.', $status));
        }

        // toArray() throws on non-JSON; a JSON array (not object) is invalid here.
        $spec = $response->toArray();
        if (array_is_list($spec)) {
            throw new \RuntimeException('OpenAPI spec did not decode to a JSON object.');
        }

        return $spec;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return list<DiscoveredTool>
     */
    public function specToTools(array $spec): array
    {
        $paths = $spec['paths'] ?? null;
        if (!\is_array($paths)) {
            throw new \RuntimeException('OpenAPI spec has no "paths" object.');
        }

        $tools = [];
        $usedNames = [];

        foreach ($paths as $path => $pathItem) {
            if (!\is_string($path) || !\is_array($pathItem)) {
                continue;
            }

            $pathItem = $this->resolveRefs($pathItem, $spec);

            foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch'] as $method) {
                $operation = $pathItem[$method] ?? null;
                if (!\is_array($operation)) {
                    continue;
                }

                $operation = $this->resolveRefs($operation, $spec);

                $name = $this->toolName($method, $path, $operation);
                $description = $this->operationDescription($operation, $method, $path);
                $schema = $this->parametersToSchema($operation, $spec);

                $usedNames[$name] = ($usedNames[$name] ?? 0) + 1;
                if ($usedNames[$name] > 1) {
                    $name .= '_'.($usedNames[$name] - 1);
                }

                $tools[] = new DiscoveredTool(
                    name: $name,
                    description: $description,
                    schema: $schema,
                );
            }
        }

        return $tools;
    }

    private const int MAX_REF_HOPS = 10;

    /**
     * Resolve in-document $ref pointers (e.g. "#/components/parameters/Foo").
     *
     * The depth guard counts $ref hops, not array nesting: a spec decoded
     * from JSON is a tree, so plain nesting cannot recurse infinitely —
     * only a $ref chain can (directly or transitively self-referential).
     * Counting nesting instead would reject perfectly valid specs whose
     * response schemas simply nest deeply.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private function resolveRefs(array $node, array $spec, int $refHops = 0): array
    {
        if ($refHops > self::MAX_REF_HOPS) {
            throw new \RuntimeException('OpenAPI $ref chain too deep (max 10).');
        }

        $out = [];
        foreach ($node as $key => $value) {
            if ('$ref' === $key && \is_string($value)) {
                $resolved = $this->resolveRef($value, $spec);
                if (\is_array($resolved)) {
                    // Merge the ref target over the sibling keys (sibling
                    // keys are rare; ref wins per OpenAPI 3 semantics).
                    unset($node['$ref']);
                    $out = array_merge($resolved, $node);

                    return $this->resolveRefs($out, $spec, $refHops + 1);
                }
                continue;
            }

            if (\is_array($value)) {
                $out[$key] = $this->resolveRefs($value, $spec, $refHops);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>|null
     */
    private function resolveRef(string $pointer, array $spec): ?array
    {
        if (!str_starts_with($pointer, '#/')) {
            return null; // external refs unsupported in v1
        }

        $node = $spec;
        foreach (explode('/', substr($pointer, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                throw new \RuntimeException(\sprintf('OpenAPI $ref "%s" does not resolve in this document.', $pointer));
            }
            $node = $node[$segment];
        }

        return \is_array($node) ? $node : null;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function toolName(string $method, string $path, array $operation): string
    {
        $raw = $operation['operationId'] ?? null;
        if (\is_string($raw) && '' !== $raw) {
            $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($raw)) ?? '';
            $name = trim($name, '_');
            if ('' !== $name) {
                return \strlen($name) > 128 ? substr($name, 0, 128) : $name;
            }
        }

        // Fall back to method + path: get /v1/weather/{city} → get_v1_weather_city
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $method.' '.$path) ?? '';
        $slug = trim($slug, '_');

        return \strlen($slug) > 128 ? substr($slug, 0, 128) : $slug;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function operationDescription(array $operation, string $method, string $path): string
    {
        $summary = $operation['summary'] ?? null;
        $desc = $operation['description'] ?? null;

        $parts = [];
        if (\is_string($summary) && '' !== $summary) {
            $parts[] = $summary;
        }
        if (\is_string($desc) && '' !== $desc) {
            $parts[] = $desc;
        }

        $text = implode('. ', $parts);

        return \sprintf('[%s %s] %s', strtoupper($method), $path, $text);
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private function parametersToSchema(array $operation, array $spec): array
    {
        $params = $operation['parameters'] ?? null;
        if (!\is_array($params)) {
            return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        }

        $properties = [];
        $required = [];

        foreach ($params as $param) {
            if (!\is_array($param)) {
                continue;
            }

            $name = $param['name'] ?? null;
            if (!\is_string($name) || '' === $name) {
                continue;
            }

            // Path/query/header only in v1 (see class docblock).
            $in = $param['in'] ?? 'query';
            if (!\in_array($in, ['path', 'query', 'header'], true)) {
                continue;
            }

            $schema = $param['schema'] ?? null;
            if (\is_array($schema)) {
                $schema = $this->resolveRefs($schema, $spec);
                $props = $this->schemaToProperties($schema);
                $properties[$name] = $props;
            } else {
                $properties[$name] = ['type' => 'string'];
            }

            $description = $param['description'] ?? null;
            if (\is_string($description) && '' !== $description) {
                $properties[$name]['description'] = $description;
            }

            if (($param['required'] ?? false) === true) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => empty($properties) ? new \stdClass() : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * Normalize an OpenAPI schema object into a JSON Schema property.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function schemaToProperties(array $schema): array
    {
        // Keep only keys valid for JSON Schema properties; drop OpenAPI-only
        // noise (example, examples, externalDocs, xml, discriminators...).
        $allowed = ['type', 'format', 'description', 'enum', 'minimum', 'maximum',
            'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength',
            'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems',
            'default', 'items', 'properties', 'required', 'additionalProperties'];

        $out = [];
        foreach ($schema as $key => $value) {
            if (\in_array($key, $allowed, true)) {
                $out[$key] = $value;
            }
        }

        if (!isset($out['type'])) {
            $out['type'] = 'string';
        }

        return $out;
    }
}
