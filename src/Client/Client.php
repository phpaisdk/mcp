<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Exceptions\InputRequiredException;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Exceptions\ProtocolException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Support\ParamHeaders;
use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Stateless MCP client (revision 2026-07-28).
 *
 * There is no handshake: each call carries its own protocol version and
 * capabilities. The client caches list results for the server's ttlMs and
 * transparently handles Multi Round-Trip Requests.
 */
final class Client
{
    private string $version = Protocol::VERSION;
    private int $nextId = 1;

    /** @var array{definitions: list<array<string, mixed>>, expires: float}|null */
    private ?array $toolCache = null;

    /** @var (Closure(string, string, array<string, mixed>): array<string, mixed>)|null */
    private ?Closure $inputHandler = null;

    private int $maxInputRounds = 5;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $name = 'aisdk-mcp',
        private readonly string $clientVersion = '1.0.0',
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  (callable(array<string, mixed>): void)|null  $onNotification
     */
    public static function http(
        string $url,
        ClientInterface $http,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        array $headers = [],
        ?callable $onNotification = null,
    ): self {
        return new self(new HttpTransport($url, $http, $requestFactory, $streamFactory, $headers, $onNotification));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env
     */
    public static function stdio(array $command, ?string $cwd = null, ?array $env = null, float $timeoutSeconds = 60.0): self
    {
        return new self(new StdioTransport($command, $cwd, $env, $timeoutSeconds));
    }

    /**
     * Answer elicitation requests a server makes while processing a call.
     * The handler receives (inputKey, method, params) and returns the result
     * object for that request, e.g. ['action' => 'accept', 'content' => [...]].
     *
     * @param  callable(string, string, array<string, mixed>): array<string, mixed>  $handler
     */
    public function onInputRequired(callable $handler, int $maxRounds = 5): self
    {
        $this->inputHandler = Closure::fromCallable($handler);
        $this->maxInputRounds = max(1, $maxRounds);

        return $this;
    }

    public function discover(): Discovery
    {
        return Discovery::fromResult($this->request('server/discover'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTools(bool $refresh = false): array
    {
        if (! $refresh && $this->toolCache !== null && $this->toolCache['expires'] > microtime(true)) {
            return $this->toolCache['definitions'];
        }

        $definitions = [];
        $cursor = null;
        $ttlMs = 0;

        do {
            $result = $this->request('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);

            foreach (is_array($result['tools'] ?? null) ? $result['tools'] : [] as $definition) {
                if (is_array($definition) && is_string($definition['name'] ?? null) && $this->headerAnnotationsValid($definition)) {
                    $definitions[] = $definition;
                }
            }

            $ttlMs = is_int($result['ttlMs'] ?? null) ? $result['ttlMs'] : 0;
            $cursor = is_string($result['nextCursor'] ?? null) ? $result['nextCursor'] : null;
        } while ($cursor !== null);

        $this->toolCache = ['definitions' => $definitions, 'expires' => microtime(true) + $ttlMs / 1000];

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        $params = ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments];

        return CallToolResult::fromResult($this->request('tools/call', $params, $this->paramHeadersFor($name, $arguments)));
    }

    /**
     * Remote tools as SDK tools for Generate::text()->tools().
     *
     * @param  string  $prefix  Prepended to every tool name to avoid collisions between servers.
     * @param  list<string>|null  $only  Remote tool names to include; null includes all.
     * @return list<McpTool>
     */
    public function tools(string $prefix = '', ?array $only = null): array
    {
        $tools = [];

        foreach ($this->listTools() as $definition) {
            if ($only !== null && ! in_array($definition['name'], $only, true)) {
                continue;
            }

            $tools[] = new McpTool($this, $definition, $prefix . $definition['name']);
        }

        return $tools;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listResources(): array
    {
        $result = $this->request('resources/list');

        return array_values(array_filter(is_array($result['resources'] ?? null) ? $result['resources'] : [], is_array(...)));
    }

    /**
     * @return list<array<string, mixed>> The resource contents (text or blob entries).
     */
    public function readResource(string $uri): array
    {
        $result = $this->request('resources/read', ['uri' => $uri]);

        return array_values(array_filter(is_array($result['contents'] ?? null) ? $result['contents'] : [], is_array(...)));
    }

    /**
     * Send a request, handling version negotiation and multi round-trips.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $paramHeaders
     * @return array<string, mixed> The complete result.
     */
    public function request(string $method, array $params = [], array $paramHeaders = []): array
    {
        $inputResponses = null;
        $requestState = null;

        for ($round = 1; ; $round++) {
            $attempt = $params;

            if ($inputResponses !== null) {
                $attempt['inputResponses'] = $inputResponses;
            }

            if ($requestState !== null) {
                $attempt['requestState'] = $requestState;
            }

            $result = $this->send($method, $attempt, $paramHeaders);
            $type = $result['resultType'] ?? Protocol::RESULT_COMPLETE;

            if ($type === Protocol::RESULT_COMPLETE) {
                return $result;
            }

            if ($type !== Protocol::RESULT_INPUT_REQUIRED || ! in_array($method, Protocol::MRTR_METHODS, true)) {
                throw new McpException("The server returned an invalid result type [{$this->describe($type)}] for {$method}.");
            }

            $requests = is_array($result['inputRequests'] ?? null) ? $result['inputRequests'] : [];
            $requestState = is_string($result['requestState'] ?? null) ? $result['requestState'] : null;

            if ($requests !== [] && $this->inputHandler === null) {
                throw new InputRequiredException(
                    "The server needs input to complete {$method} but no handler was registered with onInputRequired().",
                    $requests,
                    $requestState,
                );
            }

            if ($round >= $this->maxInputRounds) {
                throw new InputRequiredException("The server kept asking for input after {$round} rounds.", $requests, $requestState);
            }

            $inputResponses = null;

            $handler = $this->inputHandler;

            if ($requests !== [] && $handler !== null) {
                $inputResponses = [];

                foreach ($requests as $key => $request) {
                    $inputResponses[(string) $key] = $handler(
                        (string) $key,
                        (string) ($request['method'] ?? ''),
                        is_array($request['params'] ?? null) ? $request['params'] : [],
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $paramHeaders
     * @return array<string, mixed>
     */
    private function send(string $method, array $params, array $paramHeaders): array
    {
        for ($negotiated = false; ; $negotiated = true) {
            $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
            $meta[Protocol::META_PROTOCOL_VERSION] = $this->version;
            $meta[Protocol::META_CLIENT_INFO] = ['name' => $this->name, 'version' => $this->clientVersion];
            $meta[Protocol::META_CLIENT_CAPABILITIES] = $this->capabilities();

            $message = [
                'jsonrpc' => '2.0',
                'id' => $this->nextId++,
                'method' => $method,
                'params' => ['_meta' => $meta] + $params,
            ];

            $response = $this->transport->send($message, $paramHeaders);

            if (isset($response['error']) && is_array($response['error'])) {
                $error = new ProtocolException(
                    (string) ($response['error']['message'] ?? 'MCP error'),
                    (int) ($response['error']['code'] ?? Protocol::INTERNAL_ERROR),
                    is_array($response['error']['data'] ?? null) ? $response['error']['data'] : null,
                );

                if ($error->isUnsupportedVersion() && ! $negotiated) {
                    $mutual = array_values(array_intersect(Protocol::SUPPORTED_VERSIONS, $error->supportedVersions()));

                    if ($mutual === []) {
                        throw new McpException(
                            'No mutually supported MCP protocol version. Server supports: ' . implode(', ', $error->supportedVersions()) . '; this client supports: ' . implode(', ', Protocol::SUPPORTED_VERSIONS) . '.',
                            [],
                            $error,
                        );
                    }

                    $this->version = $mutual[0];

                    continue;
                }

                throw $error;
            }

            $result = $response['result'] ?? null;

            if (! is_array($result)) {
                throw new McpException("The server returned a malformed response to {$method}.");
            }

            return $result;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilities(): array
    {
        return $this->inputHandler === null ? [] : ['elicitation' => new \stdClass()];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function headerAnnotationsValid(array $definition): bool
    {
        $schema = $definition['inputSchema'] ?? [];

        return ! is_array($schema) || ParamHeaders::annotations($schema) !== null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>
     */
    private function paramHeadersFor(string $tool, array $arguments): array
    {
        foreach ($this->toolCache['definitions'] ?? [] as $definition) {
            if ($definition['name'] === $tool && is_array($definition['inputSchema'] ?? null)) {
                return ParamHeaders::extract(ParamHeaders::annotations($definition['inputSchema']) ?? [], $arguments);
            }
        }

        return [];
    }

    private function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
