<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidToolInputException;
use AiSdk\Exceptions\SchemaValidationException;
use AiSdk\Mcp\Exceptions\ProtocolException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Support\Meta;
use AiSdk\Tool;
use AiSdk\ToolExecutionContext;
use Throwable;

/**
 * Stateless MCP server (revision 2026-07-28).
 *
 * The server holds only its registry. Every message is processed independently
 * from the metadata it carries, so any instance can answer any request. Bind it
 * to a transport with HttpHandler (PSR-15) or StdioServer.
 */
final class Server
{
    private const string TOOL_NAME_PATTERN = '/^[A-Za-z0-9_.\-]{1,128}$/';

    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var array<string, array<string, mixed>> */
    private array $toolAnnotations = [];

    /** @var array<string, TextResource> */
    private array $resources = [];

    private ?string $instructions = null;
    private int $ttlMs = 60_000;
    private string $cacheScope = 'public';
    private bool $exposeToolErrors = false;

    public function __construct(
        public readonly string $name,
        public readonly string $version,
    ) {}

    public static function make(string $name, string $version = '1.0.0'): self
    {
        return new self($name, $version);
    }

    public function instructions(string $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * Freshness hint attached to list/read/discover results.
     */
    public function cache(int $ttlMs, string $scope = 'public'): self
    {
        if ($ttlMs < 0 || ! in_array($scope, ['public', 'private'], true)) {
            throw new InvalidArgumentException('Cache ttlMs must be >= 0 and scope must be "public" or "private".');
        }

        $this->ttlMs = $ttlMs;
        $this->cacheScope = $scope;

        return $this;
    }

    /**
     * Return raw exception messages from failing tool handlers to the model.
     * Off by default: handler exceptions may carry internal details.
     */
    public function exposeToolErrors(bool $expose = true): self
    {
        $this->exposeToolErrors = $expose;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $annotations  e.g. ['readOnlyHint' => true]
     */
    public function tool(Tool $tool, array $annotations = []): self
    {
        $name = $tool->name();

        if (preg_match(self::TOOL_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("MCP tool names must be 1-128 characters of A-Z, a-z, 0-9, _, - or . (got [{$name}]).");
        }

        if ($tool->requiresApproval()) {
            throw new InvalidArgumentException("Tool [{$name}] requires approval, which an MCP server cannot collect. Register it only after removing needsApproval(), or gate it in your own handler.");
        }

        $this->tools[$name] = $tool;

        if ($annotations !== []) {
            $this->toolAnnotations[$name] = $annotations;
        }

        return $this;
    }

    public function resource(TextResource $resource): self
    {
        $this->resources[$resource->uri] = $resource;

        return $this;
    }

    /**
     * Process one decoded JSON-RPC message and return only the response body.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        return $this->process($message)->body;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function process(array $message): Reply
    {
        $isRequest = array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if (! $isRequest) {
            return new Reply(null, 202);
        }

        if (! is_int($id) && ! is_string($id)) {
            return $this->error(null, ProtocolException::invalidRequest('Request id must be a string or integer.'));
        }

        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return $this->error($id, ProtocolException::invalidRequest('Not a valid JSON-RPC 2.0 request.'));
        }

        $params = $message['params'] ?? [];
        if (! is_array($params)) {
            return $this->error($id, ProtocolException::invalidParams('params must be an object.'));
        }

        try {
            $result = $this->dispatch($method, $params, (string) $id);
        } catch (ProtocolException $e) {
            return $this->error($id, $e);
        } catch (Throwable) {
            return $this->error($id, ProtocolException::internal());
        }

        $result['resultType'] ??= Protocol::RESULT_COMPLETE;
        $result['_meta'][Protocol::META_SERVER_INFO] = ['name' => $this->name, 'version' => $this->version];

        return new Reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function dispatch(string $method, array $params, string $requestId): array
    {
        if ($method === 'initialize') {
            throw ProtocolException::invalidParams(
                'This server speaks only the stateless protocol; initialize is not supported. Supported versions: '
                . implode(', ', Protocol::SUPPORTED_VERSIONS) . '.',
                400,
            );
        }

        $meta = Meta::fromParams($params);

        if (! in_array($meta->protocolVersion, Protocol::SUPPORTED_VERSIONS, true)) {
            throw ProtocolException::unsupportedVersion($meta->protocolVersion, Protocol::SUPPORTED_VERSIONS);
        }

        return match ($method) {
            'server/discover' => $this->discover(),
            'tools/list' => $this->cacheable(['tools' => $this->listTools()]),
            'tools/call' => $this->callTool($params, $requestId),
            'resources/list' => $this->cacheable(['resources' => $this->listResources()]),
            'resources/templates/list' => $this->cacheable(['resourceTemplates' => []]),
            'resources/read' => $this->readResource($params),
            default => throw ProtocolException::methodNotFound($method),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(): array
    {
        $capabilities = [];

        if ($this->tools !== []) {
            $capabilities['tools'] = new \stdClass();
        }

        if ($this->resources !== []) {
            $capabilities['resources'] = new \stdClass();
        }

        $result = [
            'supportedVersions' => Protocol::SUPPORTED_VERSIONS,
            'capabilities' => (object) $capabilities,
        ];

        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }

        return $this->cacheable($result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function cacheable(array $result): array
    {
        return $result + ['ttlMs' => $this->ttlMs, 'cacheScope' => $this->cacheScope];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listTools(): array
    {
        $names = array_keys($this->tools);
        sort($names, SORT_STRING);

        return array_map(function (string $name): array {
            $tool = $this->tools[$name];
            $definition = [
                'name' => $name,
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchemaForProvider(),
            ];

            if (isset($this->toolAnnotations[$name])) {
                $definition['annotations'] = $this->toolAnnotations[$name];
            }

            return $definition;
        }, $names);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params, string $requestId): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw ProtocolException::invalidParams('tools/call requires a tool name.');
        }

        $tool = $this->tools[$name] ?? throw ProtocolException::invalidParams("Unknown tool: {$name}");

        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw ProtocolException::invalidParams('tools/call arguments must be an object.');
        }

        try {
            $value = $tool->call($arguments, new ToolExecutionContext($requestId, $name, $arguments));
            $output = ToolOutput::from($value);
        } catch (InvalidToolInputException|SchemaValidationException $e) {
            $output = ToolOutput::error($e->getMessage());
        } catch (Throwable $e) {
            $output = ToolOutput::error($this->exposeToolErrors ? $e->getMessage() : "Tool [{$name}] failed to execute.");
        }

        return $output->toResult();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listResources(): array
    {
        $uris = array_keys($this->resources);
        sort($uris, SORT_STRING);

        return array_map(fn(string $uri): array => $this->resources[$uri]->definition(), $uris);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function readResource(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || $uri === '') {
            throw ProtocolException::invalidParams('resources/read requires a uri.');
        }

        $resource = $this->resources[$uri] ?? throw ProtocolException::invalidParams("Resource not found: {$uri}");

        return $this->cacheable(['contents' => [$resource->read()]]);
    }

    private function error(string|int|null $id, ProtocolException $e): Reply
    {
        return new Reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => $e->toError()], $e->httpStatus);
    }
}
