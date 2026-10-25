<?php

declare(strict_types=1);

use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Server\StdioServer;

it('speaks newline-delimited JSON-RPC to a real subprocess', function () {
    $client = Client::stdio([PHP_BINARY, __DIR__ . '/fixtures/stdio-server.php'], timeoutSeconds: 20);

    expect($client->discover()->serverInfo['name'])->toBe('demo')
        ->and($client->callTool('greet', ['name' => 'Ada'])->text())->toBe('Hello, Ada!')
        ->and($client->callTool('greet', ['name' => 'Grace'])->text())->toBe('Hello, Grace!');
});

it('answers lines directly and ignores notifications', function () {
    $stdio = new StdioServer(demoServer());

    expect($stdio->respond('{"jsonrpc":"2.0","method":"notifications/cancelled"}'))->toBeNull();

    $decoded = json_decode($stdio->respond('not json'), true);
    expect($decoded['error']['code'])->toBe(\AiSdk\Mcp\Protocol::PARSE_ERROR);
});
