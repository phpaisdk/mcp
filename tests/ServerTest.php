<?php

declare(strict_types=1);

use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Server\Server;

it('answers server/discover with versions, capabilities, identity and cache hints', function () {
    $reply = demoServer()->process(rpc('server/discover'));

    expect($reply->status)->toBe(200)
        ->and($reply->body['result']['resultType'])->toBe('complete')
        ->and($reply->body['result']['supportedVersions'])->toBe([Protocol::VERSION])
        ->and($reply->body['result']['capabilities'])->toHaveKeys(['tools', 'resources'])
        ->and($reply->body['result']['instructions'])->toBe('Use greet to say hello.')
        ->and($reply->body['result']['ttlMs'])->toBe(60000)
        ->and($reply->body['result']['cacheScope'])->toBe('public')
        ->and($reply->body['result']['_meta'][Protocol::META_SERVER_INFO])->toBe(['name' => 'demo', 'version' => '1.2.3']);
});

it('lists tools in deterministic order with schemas and annotations', function () {
    $result = demoServer()->handle(rpc('tools/list'))['result'];

    expect(array_column($result['tools'], 'name'))->toBe(['data', 'explode', 'greet'])
        ->and($result['tools'][2]['inputSchema']['required'])->toBe(['name'])
        ->and($result['tools'][2]['annotations'])->toBe(['readOnlyHint' => true])
        ->and($result)->toHaveKeys(['ttlMs', 'cacheScope']);
});

it('calls a tool and returns text content', function () {
    $result = demoServer()->handle(rpc('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Ada']]))['result'];

    expect($result['content'])->toBe([['type' => 'text', 'text' => 'Hello, Ada!']])
        ->and($result['isError'])->toBeFalse();
});

it('returns structured content with a text fallback', function () {
    $result = demoServer()->handle(rpc('tools/call', ['name' => 'data']))['result'];

    expect($result['structuredContent'])->toBe(['answer' => 42])
        ->and($result['content'][0]['text'])->toBe('{"answer":42}');
});

it('reports invalid tool input as an execution error the model can read', function () {
    $result = demoServer()->handle(rpc('tools/call', ['name' => 'greet', 'arguments' => []]))['result'];

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('name');
});

it('hides raw handler exception messages unless explicitly exposed', function () {
    $hidden = demoServer()->handle(rpc('tools/call', ['name' => 'explode']))['result'];
    expect($hidden['isError'])->toBeTrue()
        ->and($hidden['content'][0]['text'])->not->toContain('secret')
        ->and($hidden['content'][0]['text'])->toContain('explode');

    $exposed = demoServer()->exposeToolErrors()->handle(rpc('tools/call', ['name' => 'explode']))['result'];
    expect($exposed['content'][0]['text'])->toContain('secret database password');
});

it('returns a protocol error for unknown tools', function () {
    $reply = demoServer()->process(rpc('tools/call', ['name' => 'nope']));

    expect($reply->body['error']['code'])->toBe(Protocol::INVALID_PARAMS)
        ->and($reply->body['error']['message'])->toContain('Unknown tool');
});

it('lists and reads resources', function () {
    $server = demoServer();

    expect($server->handle(rpc('resources/list'))['result']['resources'])->toBe([
        ['uri' => 'docs://intro', 'name' => 'intro', 'description' => 'Introduction', 'mimeType' => 'text/markdown'],
    ]);

    $read = $server->handle(rpc('resources/read', ['uri' => 'docs://intro']))['result'];
    expect($read['contents'][0])->toBe(['uri' => 'docs://intro', 'mimeType' => 'text/markdown', 'text' => '# Intro'])
        ->and($read)->toHaveKeys(['ttlMs', 'cacheScope']);

    expect($server->handle(rpc('resources/read', ['uri' => 'docs://missing']))['error']['code'])->toBe(Protocol::INVALID_PARAMS);
});

it('rejects requests without the required _meta fields with 400', function () {
    $reply = demoServer()->process(rpc('tools/list', withMeta: false));
    expect($reply->status)->toBe(400)->and($reply->body['error']['code'])->toBe(Protocol::INVALID_PARAMS);

    $noCaps = rpc('tools/list', withMeta: false);
    $noCaps['params']['_meta'] = [Protocol::META_PROTOCOL_VERSION => Protocol::VERSION];
    expect(demoServer()->process($noCaps)->status)->toBe(400);
});

it('rejects unsupported versions and lists the supported ones', function () {
    $message = rpc('tools/list', withMeta: false);
    $message['params']['_meta'] = json_decode(json_encode(meta('1900-01-01')), true);
    $reply = demoServer()->process($message);

    expect($reply->status)->toBe(400)
        ->and($reply->body['error'])->toBe([
            'code' => Protocol::UNSUPPORTED_PROTOCOL_VERSION,
            'message' => 'Unsupported protocol version',
            'data' => ['supported' => [Protocol::VERSION], 'requested' => '1900-01-01'],
        ]);
});

it('explains that initialize is unsupported and names the supported versions', function () {
    $reply = demoServer()->process(rpc('initialize', ['protocolVersion' => '2025-11-25'], withMeta: false));

    expect($reply->body['error']['message'])->toContain(Protocol::VERSION);
});

it('returns method not found for unknown and removed methods', function () {
    foreach (['ping', 'logging/setLevel', 'nope/nope'] as $method) {
        $reply = demoServer()->process(rpc($method));
        expect($reply->status)->toBe(404)->and($reply->body['error']['code'])->toBe(Protocol::METHOD_NOT_FOUND);
    }
});

it('accepts notifications without responding', function () {
    $reply = demoServer()->process(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]]);

    expect($reply->body)->toBeNull()->and($reply->status)->toBe(202);
});

it('validates tool names at registration', function () {
    expect(fn() => Server::make('x')->tool(\AiSdk\Tool::make('bad name', 'x')))->toThrow(\AiSdk\Exceptions\InvalidArgumentException::class);
});
