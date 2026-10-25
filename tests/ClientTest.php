<?php

declare(strict_types=1);

use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Client\InMemoryTransport;
use AiSdk\Mcp\Client\McpTool;
use AiSdk\Mcp\Client\Transport;
use AiSdk\Mcp\Exceptions\InputRequiredException;
use AiSdk\Mcp\Exceptions\LegacyServerException;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Exceptions\ProtocolException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Server\HttpHandler;
use AiSdk\Mcp\Tests\Support\HandlerClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

function httpClient(?HandlerClient &$http = null): Client
{
    $factory = new Psr17Factory();
    $http = new HandlerClient(new HttpHandler(demoServer(), $factory, $factory));

    return Client::http('https://mcp.test/mcp', $http, $factory, $factory);
}

/** A transport that replays canned responses and records what it was sent. */
function scripted(array $responses): object
{
    return new class ($responses) implements Transport {
        public array $sent = [];

        public function __construct(private array $responses) {}

        public function send(array $message, array $paramHeaders = []): array
        {
            $this->sent[] = $message;
            $next = array_shift($this->responses);

            return ['jsonrpc' => '2.0', 'id' => $message['id']] + $next;
        }
    };
}

it('discovers a server over Streamable HTTP', function () {
    $discovery = httpClient()->discover();

    expect($discovery->supportedVersions)->toBe([Protocol::VERSION])
        ->and($discovery->supportsTools())->toBeTrue()
        ->and($discovery->serverInfo['name'])->toBe('demo')
        ->and($discovery->instructions)->toBe('Use greet to say hello.');
});

it('sends the required headers and per-request metadata', function () {
    $client = httpClient($http);
    $client->callTool('greet', ['name' => 'Ada']);

    $request = $http->requests[0];
    $body = json_decode((string) $request->getBody(), true);

    expect($request->getHeaderLine('MCP-Protocol-Version'))->toBe(Protocol::VERSION)
        ->and($request->getHeaderLine('Mcp-Method'))->toBe('tools/call')
        ->and($request->getHeaderLine('Mcp-Name'))->toBe('greet')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json, text/event-stream')
        ->and($body['params']['_meta'][Protocol::META_PROTOCOL_VERSION])->toBe(Protocol::VERSION)
        ->and($body['params']['_meta'])->toHaveKeys([Protocol::META_CLIENT_INFO, Protocol::META_CLIENT_CAPABILITIES])
        ->and($request->hasHeader('Mcp-Session-Id'))->toBeFalse();
});

it('calls tools and reads resources end to end', function () {
    $client = httpClient();

    expect($client->callTool('greet', ['name' => 'Ada'])->text())->toBe('Hello, Ada!')
        ->and($client->callTool('data')->structuredContent)->toBe(['answer' => 42])
        ->and($client->callTool('greet')->isError)->toBeTrue()
        ->and($client->listResources()[0]['uri'])->toBe('docs://intro')
        ->and($client->readResource('docs://intro')[0]['text'])->toBe('# Intro');
});

it('caches the tool list for the server ttl', function () {
    $client = httpClient($http);

    $client->listTools();
    $client->listTools();
    expect($http->requests)->toHaveCount(1);

    $client->listTools(refresh: true);
    expect($http->requests)->toHaveCount(2);
});

it('exposes remote tools as SDK tools that work with the tool loop contract', function () {
    $tools = httpClient()->tools(prefix: 'demo_');

    expect($tools)->toHaveCount(3)->and($tools[0])->toBeInstanceOf(McpTool::class);

    $greet = collect_tool($tools, 'demo_greet');
    expect($greet->description())->toBe('Greets a person')
        ->and($greet->inputSchemaForProvider()['required'])->toBe(['name'])
        ->and($greet->call(['name' => 'Ada']))->toBe('Hello, Ada!')
        ->and(collect_tool($tools, 'demo_data')->call([]))->toBe(['answer' => 42])
        ->and(collect_tool($tools, 'demo_greet')->call([]))->toStartWith('Error: ');
});

function collect_tool(array $tools, string $name): McpTool
{
    foreach ($tools as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("No tool {$name}");
}

it('works in process through the in-memory transport', function () {
    $client = new Client(new InMemoryTransport(demoServer()));

    expect($client->callTool('greet', ['name' => 'Grace'])->text())->toBe('Hello, Grace!');
});

it('surfaces protocol errors from the server', function () {
    expect(fn() => (new Client(new InMemoryTransport(demoServer())))->callTool('nope'))
        ->toThrow(ProtocolException::class, 'Unknown tool');
});

it('retries once with a mutually supported version after UnsupportedProtocolVersion', function () {
    $transport = scripted([
        ['error' => ['code' => Protocol::UNSUPPORTED_PROTOCOL_VERSION, 'message' => 'Unsupported protocol version', 'data' => ['supported' => ['2099-01-01', Protocol::VERSION], 'requested' => 'x']]],
        ['result' => ['resultType' => 'complete', 'supportedVersions' => [Protocol::VERSION], 'capabilities' => []]],
    ]);

    expect((new Client($transport))->discover()->supportedVersions)->toBe([Protocol::VERSION])
        ->and($transport->sent)->toHaveCount(2);
});

it('fails clearly when no protocol version is shared', function () {
    $transport = scripted([
        ['error' => ['code' => Protocol::UNSUPPORTED_PROTOCOL_VERSION, 'message' => 'Unsupported protocol version', 'data' => ['supported' => ['2025-11-25'], 'requested' => Protocol::VERSION]]],
    ]);

    expect(fn() => (new Client($transport))->discover())->toThrow(McpException::class, '2025-11-25');
});

it('completes multi round-trip requests, echoing requestState exactly', function () {
    $transport = scripted([
        ['result' => [
            'resultType' => 'input_required',
            'inputRequests' => ['login' => ['method' => 'elicitation/create', 'params' => ['message' => 'Username?', 'mode' => 'form']]],
            'requestState' => 'opaque-state==',
        ]],
        ['result' => ['resultType' => 'complete', 'content' => [['type' => 'text', 'text' => 'hi octocat']], 'isError' => false]],
    ]);

    $client = (new Client($transport))->onInputRequired(
        fn(string $key, string $method, array $params): array => ['action' => 'accept', 'content' => ['name' => 'octocat']],
    );

    expect($client->callTool('greet', ['name' => 'x'])->text())->toBe('hi octocat');

    $retry = $transport->sent[1];
    expect($retry['id'])->not->toBe($transport->sent[0]['id'])
        ->and($retry['params']['requestState'])->toBe('opaque-state==')
        ->and($retry['params']['inputResponses']['login']['content'])->toBe(['name' => 'octocat'])
        ->and($transport->sent[0]['params']['_meta'][Protocol::META_CLIENT_CAPABILITIES])->toHaveKey('elicitation');
});

it('does not send requestState when the server did not provide one', function () {
    $transport = scripted([
        ['result' => ['resultType' => 'input_required', 'inputRequests' => ['q' => ['method' => 'elicitation/create', 'params' => []]]]],
        ['result' => ['resultType' => 'complete', 'content' => []]],
    ]);

    (new Client($transport))->onInputRequired(fn(): array => ['action' => 'decline'])->callTool('x');

    expect($transport->sent[1]['params'])->not->toHaveKey('requestState');
});

it('raises InputRequiredException when no handler is registered or rounds run out', function () {
    $needs = ['result' => ['resultType' => 'input_required', 'inputRequests' => ['q' => ['method' => 'elicitation/create', 'params' => []]], 'requestState' => 's']];

    expect(fn() => (new Client(scripted([$needs])))->callTool('x'))->toThrow(InputRequiredException::class);

    $loop = scripted([$needs, $needs, $needs]);
    expect(fn() => (new Client($loop))->onInputRequired(fn(): array => ['action' => 'accept'], maxRounds: 2)->callTool('x'))
        ->toThrow(InputRequiredException::class, 'rounds');
});

it('rejects input_required on methods that cannot use it', function () {
    $transport = scripted([['result' => ['resultType' => 'input_required', 'inputRequests' => []]]]);

    expect(fn() => (new Client($transport))->listTools())->toThrow(McpException::class, 'invalid result type');
});

it('treats a missing resultType as complete for older servers', function () {
    $transport = scripted([['result' => ['tools' => [], 'ttlMs' => 0]]]);

    expect((new Client($transport))->listTools())->toBe([]);
});

it('detects a legacy server from an unrecognised 4xx body', function () {
    $factory = new Psr17Factory();
    $http = new class implements Psr\Http\Client\ClientInterface {
        public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface
        {
            return new Response(404, [], 'Not Found');
        }
    };

    expect(fn() => Client::http('https://old.test/mcp', $http, $factory, $factory)->discover())
        ->toThrow(LegacyServerException::class);
});

it('mirrors x-mcp-header parameters into Mcp-Param headers and drops invalid tools', function () {
    $transport = scripted([
        ['result' => ['resultType' => 'complete', 'tools' => [
            ['name' => 'sql', 'inputSchema' => ['type' => 'object', 'properties' => [
                'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'query' => ['type' => 'string'],
            ]]],
            ['name' => 'bad', 'inputSchema' => ['type' => 'object', 'properties' => [
                'rows' => ['type' => 'array', 'items' => ['type' => 'string', 'x-mcp-header' => 'Row']],
            ]]],
        ], 'ttlMs' => 1000, 'cacheScope' => 'public']],
        ['result' => ['resultType' => 'complete', 'content' => [], 'isError' => false]],
    ]);

    $captured = new class ($transport) implements Transport {
        public array $headers = [];

        public function __construct(private readonly object $inner) {}

        public function send(array $message, array $paramHeaders = []): array
        {
            $this->headers[] = $paramHeaders;

            return $this->inner->send($message, $paramHeaders);
        }
    };

    $client = new Client($captured);
    expect(array_column($client->listTools(), 'name'))->toBe(['sql']);

    $client->callTool('sql', ['region' => 'us-west1', 'query' => 'SELECT 1']);
    expect($captured->headers[1])->toBe(['Region' => 'us-west1']);
});
