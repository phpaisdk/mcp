<?php

declare(strict_types=1);

use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Server\HttpHandler;
use AiSdk\Mcp\Server\Server;
use AiSdk\Mcp\Server\StdioServer;
use AiSdk\Mcp\Tests\Support\HandlerClient;
use AiSdk\Tool;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

function legacyInitialize(string $version): array
{
    return rpc('initialize', [
        'protocolVersion' => $version,
        'capabilities' => new stdClass(),
        'clientInfo' => ['name' => 'legacy-test', 'version' => '1.0.0'],
    ], withMeta: false);
}

function legacyPost(HttpHandler $handler, array $message, array $headers = []): Psr\Http\Message\ResponseInterface
{
    return $handler->handle(new ServerRequest('POST', 'https://mcp.test/mcp', $headers, json_encode($message)));
}

it('serves the existing legacy HTTP client without sessions', function (string $version) {
    $factory = new Psr17Factory();
    $http = new HandlerClient(new HttpHandler(demoServer(), $factory, $factory));
    $client = Client::legacyHttp('https://mcp.test/mcp', $http, $factory, $factory, version: $version);

    expect($client->listTools())->toHaveCount(3)
        ->and($client->callTool('greet', ['name' => 'Ada'])->text())->toBe('Hello, Ada!')
        ->and($client->readResource('docs://intro')[0]['text'])->toBe('# Intro')
        ->and($client->request('ping'))->toBe([]);

    expect(array_map(fn($request) => json_decode((string) $request->getBody(), true)['method'], $http->requests))
        ->toBe(['initialize', 'notifications/initialized', 'tools/list', 'tools/call', 'resources/read', 'ping']);
    foreach ($http->requests as $request) {
        expect($request->hasHeader('Mcp-Session-Id'))->toBeFalse()
            ->and($request->hasHeader('Mcp-Method'))->toBeFalse()
            ->and($request->getHeaderLine('MCP-Protocol-Version'))->toBe($version);
    }
})->with(Protocol::LEGACY_VERSIONS);

it('negotiates legacy initialization and advertises the actual registry', function (string $version) {
    $factory = new Psr17Factory();
    $handler = new HttpHandler(demoServer(), $factory, $factory);
    $response = legacyPost($handler, legacyInitialize($version));
    $result = json_decode((string) $response->getBody(), true)['result'];

    expect($response->getStatusCode())->toBe(200)
        ->and($response->hasHeader('Mcp-Session-Id'))->toBeFalse()
        ->and($result)->toBe([
            'protocolVersion' => $version,
            'capabilities' => ['tools' => [], 'resources' => []],
            'serverInfo' => ['name' => 'demo', 'version' => '1.2.3'],
            'instructions' => 'Use greet to say hello.',
        ]);

    $fallback = legacyPost($handler, legacyInitialize('2099-01-01'));
    expect(json_decode((string) $fallback->getBody(), true)['result']['protocolVersion'])->toBe('2025-11-25');

    $empty = new HttpHandler(Server::make('empty'), $factory, $factory);
    $result = json_decode((string) legacyPost($empty, legacyInitialize($version))->getBody());
    expect($result->result->capabilities)->toBeInstanceOf(stdClass::class)
        ->and(isset($result->result->instructions))->toBeFalse();
})->with(Protocol::LEGACY_VERSIONS);

it('serves legacy and modern HTTP requests independently on the same instance', function () {
    $factory = new Psr17Factory();
    $handler = new HttpHandler(demoServer(), $factory, $factory);
    legacyPost($handler, legacyInitialize('2025-03-26'));

    // An old client may omit the version header. Supplied session IDs are ignored.
    $old = legacyPost($handler, rpc('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Old']], withMeta: false), ['Mcp-Session-Id' => 'unknown-session']);
    $modern = post($handler, rpc('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'New']]), ['Mcp-Name' => 'greet']);

    $oldResult = json_decode((string) $old->getBody(), true)['result'];
    $newResult = json_decode((string) $modern->getBody(), true)['result'];
    expect($oldResult['content'][0]['text'])->toBe('Hello, Old!')
        ->and($oldResult)->not->toHaveKey('resultType')
        ->and($newResult['content'][0]['text'])->toBe('Hello, New!')
        ->and($newResult['resultType'])->toBe('complete')
        ->and($newResult['_meta'][Protocol::META_SERVER_INFO]['name'])->toBe('demo');

    // A different worker can answer the next request without initializing.
    $fresh = new HttpHandler(demoServer(), $factory, $factory);
    expect(legacyPost($fresh, rpc('resources/list', withMeta: false), ['MCP-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => 'another-unknown'])->getStatusCode())->toBe(200);
});

it('rejects unsupported legacy headers including on notifications', function (string $method) {
    $factory = new Psr17Factory();
    $handler = new HttpHandler(demoServer(), $factory, $factory);
    $message = $method === 'initialize' ? legacyInitialize('2025-11-25') : rpc($method, withMeta: false);
    if ($method === 'notifications/initialized') {
        unset($message['id']);
    }
    $response = legacyPost($handler, $message, ['MCP-Protocol-Version' => '1900-01-01']);
    $error = json_decode((string) $response->getBody(), true)['error'];

    expect($response->getStatusCode())->toBe(400)
        ->and($error['code'])->toBe(Protocol::INVALID_PARAMS)
        ->and($error['data'])->toBe(['supported' => Protocol::LEGACY_VERSIONS, 'requested' => '1900-01-01']);
})->with(['initialize', 'tools/list', 'notifications/initialized']);

it('does not downgrade modern metadata with a legacy header', function () {
    $factory = new Psr17Factory();
    $handler = new HttpHandler(demoServer(), $factory, $factory);
    $response = legacyPost($handler, rpc('tools/list'), ['MCP-Protocol-Version' => '2025-11-25']);
    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode((string) $response->getBody(), true)['error']['code'])->toBe(Protocol::HEADER_MISMATCH);

    $response = legacyPost($handler, rpc('tools/list', withMeta: false), ['MCP-Protocol-Version' => Protocol::VERSION, 'Mcp-Method' => 'tools/list']);
    expect($response->getStatusCode())->toBe(400);

    $response = legacyPost($handler, legacyInitialize('2025-11-25'), ['MCP-Protocol-Version' => Protocol::VERSION, 'Mcp-Method' => 'initialize']);
    expect($response->getStatusCode())->toBe(400);
});

it('validates initialization fields without warnings or handler execution', function (array $params) {
    $factory = new Psr17Factory();
    $handler = new HttpHandler(demoServer(), $factory, $factory);
    $response = legacyPost($handler, rpc('initialize', $params, withMeta: false));
    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode((string) $response->getBody(), true)['error']['code'])->toBe(Protocol::INVALID_PARAMS);
})->with([
    [[]],
    [['protocolVersion' => []]],
    [['protocolVersion' => '2025-11-25', 'capabilities' => 'bad', 'clientInfo' => []]],
    [['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => [], 'version' => '1']]],
]);

it('validates legacy notification params and never invokes tools from notifications', function () {
    $calls = 0;
    $server = Server::make('counter')->tool(Tool::make('count', 'Count')->run(function () use (&$calls) {
        return ++$calls;
    }));
    $factory = new Psr17Factory();
    $handler = new HttpHandler($server, $factory, $factory);
    foreach ([null, 'bad', 42, ['bad']] as $params) {
        $response = legacyPost($handler, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => $params]);
        expect($response->getStatusCode())->toBe(400)->and((string) $response->getBody())->toBe('');
    }
    $response = legacyPost($handler, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    expect($response->getStatusCode())->toBe(202)->and((string) $response->getBody())->toBe('');

    legacyPost($handler, ['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => 'count']]);
    expect($calls)->toBe(0);

    $response = legacyPost($handler, ['jsonrpc' => '2.0', 'id' => null, 'method' => 'tools/list']);
    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode((string) $response->getBody(), true)['error']['code'])->toBe(Protocol::INVALID_REQUEST);
});

it('serves both eras over newline-delimited stdio without retaining negotiation state', function (string $version) {
    $input = fopen('php://memory', 'w+');
    $output = fopen('php://memory', 'w+');
    $messages = [
        legacyInitialize($version),
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        rpc('ping', id: 2, withMeta: false),
        rpc('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Legacy']], id: 3, withMeta: false),
        rpc('resources/read', ['uri' => 'docs://intro'], id: 4, withMeta: false),
        rpc('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Modern']], id: 5),
        rpc('ping', id: 6, withMeta: false),
    ];
    foreach ($messages as $message) {
        fwrite($input, json_encode($message) . "\n");
    }
    rewind($input);
    (new StdioServer(demoServer()))->run($input, $output);
    rewind($output);
    $lines = array_map(fn($line) => json_decode($line, true), array_filter(explode("\n", stream_get_contents($output))));
    fclose($input);
    fclose($output);

    expect($lines)->toHaveCount(6)
        ->and(array_column($lines, 'id'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($lines[0]['result']['protocolVersion'])->toBe($version)
        ->and($lines[1]['result'])->toBe([])
        ->and($lines[2]['result']['content'][0]['text'])->toBe('Hello, Legacy!')
        ->and($lines[3]['result']['contents'][0]['text'])->toBe('# Intro')
        ->and($lines[4]['result']['resultType'])->toBe('complete')
        ->and($lines[5]['result'])->toBe([]);
})->with(Protocol::LEGACY_VERSIONS);

it('speaks legacy MCP to an actual stdio subprocess', function () {
    $transport = new AiSdk\Mcp\Client\LegacyTransport(new AiSdk\Mcp\Client\StdioTransport([PHP_BINARY, __DIR__ . '/fixtures/stdio-server.php'], timeoutSeconds: 10));
    $client = new Client($transport);
    expect($client->callTool('greet', ['name' => 'Ada'])->text())->toBe('Hello, Ada!')
        ->and($client->request('ping'))->toBe([]);
});

it('raises an SDK exception for an unsupported legacy client revision', function () {
    $factory = new Psr17Factory();
    expect(fn() => Client::legacyHttp('https://mcp.test/mcp', new HandlerClient(new HttpHandler(demoServer(), $factory, $factory)), $factory, $factory, version: '1900-01-01'))
        ->toThrow(McpException::class);
});
