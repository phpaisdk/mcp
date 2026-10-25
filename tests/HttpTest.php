<?php

declare(strict_types=1);

use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Server\HttpHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

function httpHandler(?array $origins = null): HttpHandler
{
    $factory = new Psr17Factory();

    return new HttpHandler(demoServer(), $factory, $factory, $origins);
}

/** @param array<string, string> $headers */
function post(HttpHandler $handler, array $message, array $headers = [], string $uri = 'https://mcp.test/mcp'): Psr\Http\Message\ResponseInterface
{
    $headers += [
        'Content-Type' => 'application/json',
        'MCP-Protocol-Version' => Protocol::VERSION,
        'Mcp-Method' => $message['method'] ?? '',
    ];

    return $handler->handle(new ServerRequest('POST', $uri, $headers, json_encode($message)));
}

it('serves a request as JSON', function () {
    $response = post(httpHandler(), rpc('tools/list'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and(json_decode((string) $response->getBody(), true)['result']['tools'])->toHaveCount(3);
});

it('requires the MCP-Protocol-Version header', function () {
    $message = rpc('tools/list');
    $response = httpHandler()->handle(new ServerRequest('POST', 'https://mcp.test/mcp', ['Mcp-Method' => 'tools/list'], json_encode($message)));

    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode((string) $response->getBody(), true)['error']['code'])->toBe(Protocol::HEADER_MISMATCH);
});

it('rejects a protocol version header that disagrees with the body', function () {
    $response = post(httpHandler(), rpc('tools/list'), ['MCP-Protocol-Version' => '2025-11-25']);

    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode((string) $response->getBody(), true)['error']['code'])->toBe(Protocol::HEADER_MISMATCH);
});

it('rejects an Mcp-Method that disagrees with the body', function () {
    $response = post(httpHandler(), rpc('tools/list'), ['Mcp-Method' => 'tools/call']);

    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode((string) $response->getBody(), true)['error']['code'])->toBe(Protocol::HEADER_MISMATCH);
});

it('requires Mcp-Name to match the tool and decodes base64 sentinel values', function () {
    $call = rpc('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Ada']]);

    expect(post(httpHandler(), $call)->getStatusCode())->toBe(400)
        ->and(post(httpHandler(), $call, ['Mcp-Name' => 'other'])->getStatusCode())->toBe(400)
        ->and(post(httpHandler(), $call, ['Mcp-Name' => 'greet'])->getStatusCode())->toBe(200)
        ->and(post(httpHandler(), $call, ['Mcp-Name' => '=?base64?' . base64_encode('greet') . '?='])->getStatusCode())->toBe(200);
});

it('returns 202 for notifications and 405 for GET and DELETE', function () {
    $notification = ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => []];

    expect(post(httpHandler(), $notification)->getStatusCode())->toBe(202);

    foreach (['GET', 'DELETE'] as $method) {
        $response = httpHandler()->handle(new ServerRequest($method, 'https://mcp.test/mcp'));
        expect($response->getStatusCode())->toBe(405)->and($response->getHeaderLine('Allow'))->toBe('POST');
    }
});

it('rejects invalid JSON and batches', function () {
    $parse = httpHandler()->handle(new ServerRequest('POST', 'https://mcp.test/mcp', [], '{nope'));
    expect($parse->getStatusCode())->toBe(400)
        ->and(json_decode((string) $parse->getBody(), true)['error']['code'])->toBe(Protocol::PARSE_ERROR);

    $batch = httpHandler()->handle(new ServerRequest('POST', 'https://mcp.test/mcp', [], json_encode([rpc('tools/list')])));
    expect($batch->getStatusCode())->toBe(400);
});

it('validates Origin: same host by default, configured list otherwise', function () {
    expect(post(httpHandler(), rpc('tools/list'), ['Origin' => 'https://evil.test'])->getStatusCode())->toBe(403)
        ->and(post(httpHandler(), rpc('tools/list'), ['Origin' => 'https://mcp.test'])->getStatusCode())->toBe(200)
        ->and(post(httpHandler(['https://app.test']), rpc('tools/list'), ['Origin' => 'https://app.test'])->getStatusCode())->toBe(200)
        ->and(post(httpHandler(['https://app.test']), rpc('tools/list'), ['Origin' => 'https://mcp.test'])->getStatusCode())->toBe(403)
        ->and(post(httpHandler(['*']), rpc('tools/list'), ['Origin' => 'https://anything.test'])->getStatusCode())->toBe(200);
});

it('maps protocol errors to the documented HTTP statuses', function () {
    $unsupported = rpc('tools/list', withMeta: false);
    $unsupported['params']['_meta'] = json_decode(json_encode(meta('1900-01-01')), true);

    expect(post(httpHandler(), $unsupported, ['MCP-Protocol-Version' => '1900-01-01'])->getStatusCode())->toBe(400)
        ->and(post(httpHandler(), rpc('nope/nope'))->getStatusCode())->toBe(404);
});
