<?php

declare(strict_types=1);

use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Client\HttpTransport;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Server\HttpHandler;
use AiSdk\Mcp\Server\StdioServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('does not pass the parent environment to a stdio server process by default', function () {
    putenv('AISDK_MCP_TEST_SECRET=super-secret-value');

    try {
        $client = Client::stdio([PHP_BINARY, __DIR__ . '/fixtures/env-server.php'], env: ['AISDK_MCP_EXTRA' => 'passed'], timeoutSeconds: 20);

        expect($client->callTool('read_env')->text())->toBe('')
            ->and($client->callTool('read_extra')->text())->toBe('passed');
    } finally {
        putenv('AISDK_MCP_TEST_SECRET');
    }
});

it('passes the parent environment only when explicitly requested', function () {
    putenv('AISDK_MCP_TEST_SECRET=visible-on-request');

    try {
        $client = Client::stdio([PHP_BINARY, __DIR__ . '/fixtures/env-server.php'], timeoutSeconds: 20, inheritEnvironment: true);

        expect($client->callTool('read_env')->text())->toBe('visible-on-request');
    } finally {
        putenv('AISDK_MCP_TEST_SECRET');
    }
});

function fixedClient(ResponseInterface $response): ClientInterface
{
    return new class ($response) implements ClientInterface {
        public function __construct(private readonly ResponseInterface $response) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return $this->response;
        }
    };
}

it('rejects JSON responses larger than the configured limit', function () {
    $factory = new Psr17Factory();
    $big = str_repeat('x', 5000);
    $http = fixedClient(new Response(200, ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['pad' => $big]])));

    $transport = new HttpTransport('https://x.test/mcp', $http, $factory, $factory, maxResponseBytes: 1000);

    expect(fn() => (new Client($transport))->discover())->toThrow(McpException::class, 'byte limit');
});

it('rejects event streams larger than the configured limit', function () {
    $factory = new Psr17Factory();
    $stream = str_repeat("data: " . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => []]) . "\n\n", 200);
    $http = fixedClient(new Response(200, ['Content-Type' => 'text/event-stream'], $stream));

    $transport = new HttpTransport('https://x.test/mcp', $http, $factory, $factory, maxResponseBytes: 2000);

    expect(fn() => (new Client($transport))->discover())->toThrow(McpException::class, 'byte limit');
});

it('reads a normal event stream response', function () {
    $factory = new Psr17Factory();
    $result = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete', 'supportedVersions' => [Protocol::VERSION], 'capabilities' => new stdClass()]];
    $body = "data: " . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => []]) . "\r\n\r\ndata: " . json_encode($result) . "\r\n\r\n";
    $http = fixedClient(new Response(200, ['Content-Type' => 'text/event-stream'], $body));

    $seen = [];
    $transport = new HttpTransport('https://x.test/mcp', $http, $factory, $factory, onNotification: function (array $n) use (&$seen): void {
        $seen[] = $n['method'];
    });

    expect((new Client($transport))->discover()->supportedVersions)->toBe([Protocol::VERSION])
        ->and($seen)->toBe(['notifications/progress']);
});

it('rejects request bodies larger than the server limit with 413', function () {
    $factory = new Psr17Factory();
    $handler = new HttpHandler(demoServer(), $factory, $factory, maxBodyBytes: 200);

    $message = rpc('tools/call', ['name' => 'greet', 'arguments' => ['name' => str_repeat('a', 500)]]);
    $response = $handler->handle(new ServerRequest('POST', 'https://mcp.test/mcp', ['MCP-Protocol-Version' => Protocol::VERSION, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'greet'], json_encode($message)));

    expect($response->getStatusCode())->toBe(413);
});

it('lets an authenticator reject requests before any processing', function () {
    $factory = new Psr17Factory();
    $calls = 0;
    $handler = new HttpHandler(
        demoServer(),
        $factory,
        $factory,
        authenticator: function ($request) use (&$calls) {
            $calls++;

            return $request->getHeaderLine('Authorization') === 'Bearer good'
                ? null
                : (new Response(401))->withHeader('WWW-Authenticate', 'Bearer');
        },
    );

    $message = rpc('tools/list');
    $headers = ['MCP-Protocol-Version' => Protocol::VERSION, 'Mcp-Method' => 'tools/list'];

    $denied = $handler->handle(new ServerRequest('POST', 'https://mcp.test/mcp', $headers, json_encode($message)));
    $allowed = $handler->handle(new ServerRequest('POST', 'https://mcp.test/mcp', $headers + ['Authorization' => 'Bearer good'], json_encode($message)));

    expect($denied->getStatusCode())->toBe(401)
        ->and($denied->getHeaderLine('WWW-Authenticate'))->toBe('Bearer')
        ->and($allowed->getStatusCode())->toBe(200)
        ->and($calls)->toBe(2);
});

it('answers oversized stdio messages with an error instead of buffering them', function () {
    $input = fopen('php://memory', 'w+');
    fwrite($input, str_repeat('a', 5000) . "\n" . json_encode(rpc('server/discover')) . "\n");
    rewind($input);
    $output = fopen('php://memory', 'w+');

    (new StdioServer(demoServer(), maxLineBytes: 1000))->run($input, $output);
    rewind($output);
    $lines = array_map(fn(string $line) => json_decode($line, true), array_filter(explode("\n", (string) stream_get_contents($output))));

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['error']['code'])->toBe(Protocol::INVALID_REQUEST)
        ->and($lines[1]['result']['supportedVersions'])->toBe([Protocol::VERSION]);
});
