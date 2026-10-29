<?php

declare(strict_types=1);

use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Server\Server;
use AiSdk\Mcp\Server\TextResource;
use AiSdk\Schema;
use AiSdk\Tool;

function meta(string $version = Protocol::VERSION): array
{
    return [
        Protocol::META_PROTOCOL_VERSION => $version,
        Protocol::META_CLIENT_CAPABILITIES => new stdClass(),
    ];
}

function rpc(string $method, array $params = [], int|string $id = 1, bool $withMeta = true): array
{
    if ($withMeta) {
        $params['_meta'] = ($params['_meta'] ?? []) + meta();
    }

    // Round-trip through JSON, exactly as a transport would deliver it.
    return json_decode(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]), true);
}

function demoServer(): Server
{
    $greet = Tool::make('greet', 'Greets a person')
        ->input(Schema::object('greet_input', properties: [Schema::string('name', 'Who to greet')->required()]))
        ->run(fn(array $input): string => "Hello, {$input['name']}!");

    $explode = Tool::make('explode', 'Always fails')->run(function (): never {
        throw new RuntimeException('secret database password');
    });

    $data = Tool::make('data', 'Structured output')->run(fn(): array => ['answer' => 42]);

    return Server::make('demo', '1.2.3')
        ->instructions('Use greet to say hello.')
        ->tool($greet, ['readOnlyHint' => true])
        ->tool($explode)
        ->tool($data)
        ->resource(TextResource::text('docs://intro', 'intro', '# Intro', description: 'Introduction'));
}

function legacyInitialize(string $version): array
{
    return rpc('initialize', [
        'protocolVersion' => $version,
        'capabilities' => new stdClass(),
        'clientInfo' => ['name' => 'legacy-test', 'version' => '1.0.0'],
    ], withMeta: false);
}
