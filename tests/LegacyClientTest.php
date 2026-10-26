<?php

declare(strict_types=1);

use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Exceptions\McpException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('initializes once and carries the session only in explicit legacy mode', function () {
    $http = new class implements ClientInterface {
        public array $requests = [];
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;
            $body = json_decode((string) $request->getBody(), true);
            $method = $body['method'];
            if ($method === 'notifications/initialized') {
                return new Response(202);
            }
            $result = $method === 'initialize' ? ['protocolVersion' => '2025-11-25'] : ['tools' => []];

            return new Response(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'session-1'], json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => $result]));
        }
    };
    $factory = new Psr17Factory();
    $client = Client::legacyHttp('https://example.test/mcp', $http, $factory, $factory);
    $client->listTools();
    $client->listTools(true);
    expect($http->requests)->toHaveCount(4)
        ->and($http->requests[0]->hasHeader('Mcp-Session-Id'))->toBeFalse()
        ->and($http->requests[2]->getHeaderLine('Mcp-Session-Id'))->toBe('session-1')
        ->and($http->requests[2]->getHeaderLine('MCP-Protocol-Version'))->toBe('2025-11-25')
        ->and((string) $http->requests[2]->getBody())->not->toContain('_meta');
});

it('rejects a mismatched JSON RPC id', function () {
    $http = new class implements ClientInterface {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":"wrong","result":{}}');
        }
    };
    $factory = new Psr17Factory();
    expect(fn() => Client::http('https://example.test', $http, $factory, $factory)->listTools())->toThrow(McpException::class);
});
