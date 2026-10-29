<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidToolInputException;
use AiSdk\Exceptions\SchemaValidationException;
use AiSdk\Mcp\Exceptions\ProtocolException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Support\Meta;
use AiSdk\Mcp\Support\Payload;
use AiSdk\Schema;
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

    /** @var array<string, Prompt> */
    private array $prompts = [];

    /** @var array<string, ResourceTemplate> */
    private array $templates = [];

    /** @var array<string, Schema> */
    private array $outputSchemas = [];

    /** @var array<string, array<string, mixed>> */
    private array $toolMetadata = [];

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
     * @param  list<array<string, mixed>>  $icons
     * @param  array<string, mixed>  $meta
     */
    public function tool(Tool $tool, array $annotations = [], ?Schema $outputSchema = null, array $icons = [], array $meta = []): self
    {
        $name = $tool->name();

        if (preg_match(self::TOOL_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("MCP tool names must be 1-128 characters of A-Z, a-z, 0-9, _, - or . (got [{$name}]).");
        }

        if ($tool->requiresApproval()) {
            throw new InvalidArgumentException("Tool [{$name}] requires approval, which an MCP server cannot collect. Register it only after removing needsApproval(), or gate it in your own handler.");
        }

        Payload::icons($icons);
        Payload::check([$annotations, $meta, $outputSchema?->jsonSchema()]);
        $this->tools[$name] = $tool;
        unset($this->toolAnnotations[$name], $this->outputSchemas[$name]);
        $this->toolMetadata[$name] = array_filter(['icons' => $icons === [] ? null : $icons,
            '_meta' => $meta === [] ? null : (object) $meta], static fn(mixed $v): bool => $v !== null);
        if ($outputSchema !== null) {
            $this->outputSchemas[$name] = $outputSchema;
        }

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

    public function prompt(Prompt $prompt): self
    {
        $this->prompts[$prompt->name] = $prompt;

        return $this;
    }

    public function resourceTemplate(ResourceTemplate $template): self
    {
        $this->templates[$template->uriTemplate] = $template;

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
     * @param  string|null  $protocolVersion  Transport-selected era; null requires modern metadata except for initialize.
     */
    public function process(array $message, ?string $protocolVersion = null): Reply
    {
        $isRequest = array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if ($isRequest && ! is_int($id) && ! is_string($id)) {
            return $this->error(null, ProtocolException::invalidRequest('Request id must be a string or integer.'));
        }

        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method) || $method === '') {
            return $this->error($isRequest ? $id : null, ProtocolException::invalidRequest('Not a valid JSON-RPC 2.0 request.'));
        }

        $params = $message['params'] ?? [];
        if ((array_key_exists('params', $message) && $message['params'] === null)
            || ! is_array($params) || ($params !== [] && array_is_list($params))) {
            return $isRequest
                ? $this->error($id, ProtocolException::invalidParams('params must be an object.', 400))
                : new Reply(null, 400);
        }

        if (! $isRequest) {
            // Never execute a method sent as a notification. Validate its envelope
            // even though JSON-RPC notifications must not receive a response.
            return new Reply(null, 202);
        }

        $legacy = $protocolVersion !== null
            ? in_array($protocolVersion, Protocol::LEGACY_VERSIONS, true)
            : ($method === 'initialize' && ! self::hasProtocolMeta($params));

        try {
            $result = $this->dispatch($method, $params, (string) $id, $legacy);
            Payload::check($result);
        } catch (ProtocolException $e) {
            return $this->error($id, $e);
        } catch (Throwable) {
            return $this->error($id, ProtocolException::internal());
        }

        if (! $legacy) {
            $result['resultType'] ??= Protocol::RESULT_COMPLETE;
            $result['_meta'][Protocol::META_SERVER_INFO] = ['name' => $this->name, 'version' => $this->version];
        } else {
            unset($result['ttlMs'], $result['cacheScope']);
        }

        return new Reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result]);
    }

    /** @param array<string, mixed> $params */
    public static function hasProtocolMeta(array $params): bool
    {
        $meta = $params['_meta'] ?? null;

        return is_array($meta) && (array_key_exists(Protocol::META_PROTOCOL_VERSION, $meta)
            || array_key_exists(Protocol::META_CLIENT_CAPABILITIES, $meta));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function dispatch(string $method, array $params, string $requestId, bool $legacy): array
    {
        if ($legacy && $method === 'initialize') {
            return $this->initialize($params);
        }

        if (! $legacy) {
            $meta = Meta::fromParams($params);

            if (! in_array($meta->protocolVersion, Protocol::SUPPORTED_VERSIONS, true)) {
                throw ProtocolException::unsupportedVersion($meta->protocolVersion, Protocol::SUPPORTED_VERSIONS);
            }
        }

        if ($legacy && $method === 'ping') {
            return [];
        }

        return match ($method) {
            'server/discover' => $this->discover(),
            'tools/list' => $this->cacheable(['tools' => $this->listTools()]),
            'tools/call' => $this->callTool($params, $requestId),
            'resources/list' => $this->cacheable(['resources' => $this->listResources()]),
            'resources/templates/list' => $this->cacheable(['resourceTemplates' => $this->listTemplates()]),
            'resources/read' => $this->readResource($params),
            'prompts/list' => $this->cacheable(['prompts' => $this->listPrompts()]),
            'prompts/get' => $this->getPrompt($params),
            'completion/complete' => $this->complete($params),
            default => throw ProtocolException::methodNotFound($method),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(): array
    {
        $result = [
            'supportedVersions' => Protocol::SUPPORTED_VERSIONS,
            'capabilities' => $this->capabilities(),
        ];

        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }

        return $this->cacheable($result);
    }

    /** @return object */
    private function capabilities(): object
    {
        $capabilities = [];

        if ($this->tools !== []) {
            $capabilities['tools'] = ['listChanged' => false];
        }

        if ($this->resources !== [] || $this->templates !== []) {
            $capabilities['resources'] = ['listChanged' => false, 'subscribe' => false];
        }

        if ($this->prompts !== []) {
            $capabilities['prompts'] = ['listChanged' => false];
        }
        foreach ([...$this->prompts, ...$this->templates] as $entry) {
            if ($entry->hasCompletions()) {
                $capabilities['completions'] = new \stdClass();
                break;
            }
        }

        return (object) $capabilities;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $version = $params['protocolVersion'] ?? null;
        $capabilities = $params['capabilities'] ?? null;
        $info = $params['clientInfo'] ?? null;

        if (! is_string($version) || $version === '' || strlen($version) > 128
            || ! is_array($capabilities) || ($capabilities !== [] && array_is_list($capabilities))
            || ! is_array($info) || ! is_string($info['name'] ?? null) || ! is_string($info['version'] ?? null)) {
            throw ProtocolException::invalidParams('initialize requires protocolVersion, capabilities and clientInfo.', 400);
        }

        $result = [
            'protocolVersion' => in_array($version, Protocol::LEGACY_VERSIONS, true) ? $version : Protocol::LEGACY_VERSIONS[0],
            'capabilities' => $this->capabilities(),
            'serverInfo' => ['name' => $this->name, 'version' => $this->version],
        ];

        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }

        return $result;
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

            if (isset($this->outputSchemas[$name])) {
                $definition['outputSchema'] = $this->outputSchemas[$name]->jsonSchema();
            }

            return $definition + ($this->toolMetadata[$name] ?? []);
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
            if (isset($this->outputSchemas[$name])) {
                $output = $output->validateOutput($this->outputSchemas[$name]);
            }
            $output->toResult();
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

        if (isset($this->resources[$uri])) {
            return $this->cacheable(['contents' => [$this->resources[$uri]->read()]]);
        }
        foreach ($this->templates as $template) {
            $content = $template->read($uri);
            if ($content !== null) {
                return $this->cacheable(['contents' => [$content]]);
            }
        }

        throw ProtocolException::invalidParams('Resource not found.');
    }

    /** @return list<array<string, mixed>> */
    private function listTemplates(): array
    {
        $templates = $this->templates;
        ksort($templates, SORT_STRING);

        return array_values(array_map(fn(ResourceTemplate $template): array => $template->definition(), $templates));
    }

    /** @return list<array<string, mixed>> */
    private function listPrompts(): array
    {
        $prompts = $this->prompts;
        ksort($prompts, SORT_STRING);

        return array_values(array_map(fn(Prompt $prompt): array => $prompt->definition(), $prompts));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getPrompt(array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (! is_string($name) || ! isset($this->prompts[$name]) || ! is_array($arguments)
            || ($arguments !== [] && array_is_list($arguments))) {
            throw ProtocolException::invalidParams('Unknown prompt or invalid arguments.');
        }

        return $this->cacheable($this->prompts[$name]->get($arguments));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function complete(array $params): array
    {
        $ref = $params['ref'] ?? null;
        $argument = $params['argument'] ?? null;
        $context = $params['context'] ?? [];
        if (! is_array($ref) || ! is_array($argument) || ! is_string($argument['name'] ?? null)
            || ! is_string($argument['value'] ?? null) || ! is_array($context)) {
            throw ProtocolException::invalidParams('Invalid completion request.');
        }
        $context = $context['arguments'] ?? [];
        if (! is_array($context) || ($context !== [] && array_is_list($context))) {
            throw ProtocolException::invalidParams('Invalid completion context.');
        }
        foreach ($context as $value) {
            if (! is_string($value)) {
                throw ProtocolException::invalidParams('Completion context values must be strings.');
            }
        }
        $entry = match ($ref['type'] ?? null) {
            'ref/prompt' => is_string($ref['name'] ?? null) ? ($this->prompts[$ref['name']] ?? null) : null,
            'ref/resource' => is_string($ref['uri'] ?? null) ? ($this->templates[$ref['uri']] ?? null) : null,
            default => null,
        };
        if ($entry === null) {
            throw ProtocolException::invalidParams('Unknown completion reference.');
        }
        $values = $entry->complete($argument['name'], $argument['value'], $context);

        return ['completion' => ['values' => array_slice($values, 0, 100), 'total' => count($values), 'hasMore' => count($values) > 100]];
    }

    private function error(string|int|null $id, ProtocolException $e): Reply
    {
        return new Reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => $e->toError()], $e->httpStatus);
    }
}
