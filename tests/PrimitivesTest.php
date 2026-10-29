<?php

declare(strict_types=1);

use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Client\InMemoryTransport;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Server\HttpHandler;
use AiSdk\Mcp\Server\Prompt;
use AiSdk\Mcp\Server\ResourceTemplate;
use AiSdk\Mcp\Server\Server;
use AiSdk\Mcp\Server\TextResource;
use AiSdk\Mcp\Server\ToolOutput;
use AiSdk\Mcp\Tests\Support\HandlerClient;
use AiSdk\Schema;
use AiSdk\Tool;
use Nyholm\Psr7\Factory\Psr17Factory;

function primitivesServer(): Server
{
    $prompt = new Prompt('review', fn(array $args): array => [
        ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Review ' . $args['language']]],
    ], arguments: [['name' => 'language', 'description' => 'Source language', 'required' => true]], description: 'Review code');
    $prompt->onComplete('language', fn(string $value, array $context): array => $context === ['project' => 'sdk'] ? ['php', 'python'] : [$value]);
    $template = new ResourceTemplate('docs://{section}/{page}', 'docs', fn(array $vars, string $uri): string => $vars['section'] . ':' . $vars['page']);
    $template->onComplete('section', fn(): array => ['guide', 'reference']);

    return Server::make('primitives')->prompt($prompt)->resourceTemplate($template);
}

it('lists and renders prompts and resource templates through both HTTP eras', function (bool $legacy) {
    $factory = new Psr17Factory();
    $http = new HandlerClient(new HttpHandler(primitivesServer(), $factory, $factory));
    $client = $legacy ? Client::legacyHttp('https://mcp.test', $http, $factory, $factory) : Client::http('https://mcp.test', $http, $factory, $factory);

    expect($client->listPrompts()[0]['arguments'][0])->toBe(['name' => 'language', 'description' => 'Source language', 'required' => true])
        ->and($client->getPrompt('review', ['language' => 'PHP'])['messages'][0]['content']['text'])->toBe('Review PHP')
        ->and($client->listResourceTemplates()[0]['uriTemplate'])->toBe('docs://{section}/{page}')
        ->and($client->readResource('docs://guide/hello%20world')[0]['text'])->toBe('guide:hello world')
        ->and($client->complete(['type' => 'ref/prompt', 'name' => 'review'], 'language', 'p', ['project' => 'sdk']))
        ->toBe(['values' => ['php', 'python'], 'total' => 2, 'hasMore' => false])
        ->and($client->complete(['type' => 'ref/resource', 'uri' => 'docs://{section}/{page}'], 'section', 'g')['values'])->toBe(['guide', 'reference']);
})->with([false, true]);

it('advertises only registered capabilities in discovery and initialize', function () {
    $server = primitivesServer();
    $modern = json_decode(json_encode($server->handle(rpc('server/discover'))), true)['result']['capabilities'];
    $legacy = json_decode(json_encode($server->handle(legacyInitialize('2025-11-25'))), true)['result']['capabilities'];
    expect($modern)->toBe($legacy)
        ->toBe(['resources' => ['listChanged' => false, 'subscribe' => false], 'prompts' => ['listChanged' => false], 'completions' => []]);

    $server = Server::make('static')->prompt(new Prompt('plain', fn(): array => []));
    $caps = json_decode(json_encode($server->handle(rpc('server/discover'))), true)['result']['capabilities'];
    expect($caps)->toBe(['prompts' => ['listChanged' => false]]);
});

it('validates prompt arguments and completion shapes before invoking callbacks', function (string $method, array $params) {
    $reply = primitivesServer()->process(rpc($method, $params));
    expect($reply->body['error']['code'])->toBe(Protocol::INVALID_PARAMS);
})->with([
    ['prompts/get', ['name' => 'review']],
    ['prompts/get', ['name' => 'review', 'arguments' => ['language' => 5]]],
    ['prompts/get', ['name' => 'review', 'arguments' => ['language' => 'php', 'unknown' => 'x']]],
    ['prompts/get', ['name' => 'missing']],
    ['completion/complete', ['ref' => ['type' => 'bad'], 'argument' => ['name' => 'a', 'value' => 'b']]],
    ['completion/complete', ['ref' => ['type' => 'ref/prompt', 'name' => []], 'argument' => ['name' => 'a', 'value' => 'b']]],
    ['completion/complete', ['ref' => ['type' => 'ref/prompt', 'name' => 'review'], 'argument' => ['name' => 'language', 'value' => []]]],
    ['completion/complete', ['ref' => ['type' => 'ref/prompt', 'name' => 'review'], 'argument' => ['name' => 'language', 'value' => 'p'], 'context' => ['arguments' => ['x' => []]]]],
]);

it('bounds completion results to one hundred and reports more matches', function () {
    $prompt = (new Prompt('p', fn(): array => [], arguments: [['name' => 'x']]))->onComplete('x', fn(): array => array_fill(0, 101, 'value'));
    $client = new Client(new InMemoryTransport(Server::make('s')->prompt($prompt)));
    $result = $client->complete(['type' => 'ref/prompt', 'name' => 'p'], 'x', '');
    expect($result['values'])->toHaveCount(100)->and($result['hasMore'])->toBeTrue()->and($result['total'])->toBe(101);
});

it('matches RFC 6570 level 1 and 2 scalar variables', function (string $template, string $uri, ?array $expected) {
    $resource = new ResourceTemplate($template, 'test', fn(): string => 'ok');
    expect($resource->match($uri))->toBe($expected);
})->with([
    ['docs://{name}', 'docs://hello%20world', ['name' => 'hello world']],
    ['docs://{a,b}', 'docs://one,two', ['a' => 'one', 'b' => 'two']],
    ['docs://{+path}', 'docs://a/b?c=d', ['path' => 'a/b?c=d']],
    ['docs://base{#fragment}', 'docs://base#one/two', ['fragment' => 'one/two']],
    ['docs://{x}/{x}', 'docs://a/a', ['x' => 'a']],
    ['docs://{x}/{x}', 'docs://a/b', null],
    ['docs://{x}', 'docs://a/b', null],
    ['docs://{x}', 'docs://bad%zz', null],
]);

it('rejects unsupported and oversized templates at registration', function (string $template) {
    expect(fn() => new ResourceTemplate($template, 'x', fn(): string => 'x'))->toThrow(McpException::class);
})->with(['docs://{?query}', 'docs://{x*}', 'docs://{x:2}', 'docs://{broken', str_repeat('a', 4097)]);

it('supports rich tool content, metadata, annotations and definition icons', function () {
    $icons = [['src' => 'https://mcp.test/icon.svg', 'sizes' => ['any']]];
    $resource = TextResource::text('docs://intro', 'intro', 'Hello', annotations: ['audience' => ['assistant']], icons: $icons, meta: ['owner' => 'docs']);
    $server = Server::make('rich')->resource($resource)
        ->tool(Tool::make('image')->run(fn() => ToolOutput::image(base64_encode('PNG'))->annotations(['priority' => 0.8])->withMeta(['trace' => '123'])), icons: $icons, meta: ['display' => 'rich'])
        ->tool(Tool::make('audio')->run(fn() => ToolOutput::audio(base64_encode('WAV'))))
        ->tool(Tool::make('link')->run(fn() => ToolOutput::resourceLink($resource)))
        ->prompt(new Prompt('p', fn(): array => [['role' => 'assistant', 'content' => ['type' => 'audio', 'data' => base64_encode('a'), 'mimeType' => 'audio/wav']]], icons: $icons, meta: ['x' => 1]));
    $client = new Client(new InMemoryTransport($server));
    $result = $client->request('tools/call', ['name' => 'image']);
    expect($result['content'][0])->toBe(['annotations' => ['priority' => 0.8], 'type' => 'image', 'data' => base64_encode('PNG'), 'mimeType' => 'image/png'])
        ->and($result['_meta']['trace'])->toBe('123')
        ->and($client->callTool('audio')->content[0]['type'])->toBe('audio')
        ->and($client->callTool('link')->content[0]['annotations'])->toBe(['audience' => ['assistant']])
        ->and($client->listTools()[1]['icons'])->toBe($icons)
        ->and($client->listResources()[0]['icons'])->toBe($icons)
        ->and($client->listPrompts()[0]['icons'])->toBe($icons)
        ->and($client->getPrompt('p')['messages'][0]['role'])->toBe('assistant');
});

it('validates declared structured tool outputs and preserves explicit errors', function () {
    $schema = Schema::object('answer', properties: [Schema::integer('value')->required()]);
    $server = Server::make('schemas');
    foreach (['valid' => ['value' => 42], 'invalid' => ['value' => 'wrong'], 'missing' => 'text'] as $name => $output) {
        $server->tool(Tool::make($name)->run(fn() => $output), outputSchema: $schema);
    }
    $server->tool(Tool::make('reported_error')->run(fn() => ToolOutput::error('Try again')), outputSchema: $schema);
    $client = new Client(new InMemoryTransport($server));
    expect($client->listTools()[0]['outputSchema'])->toBe($schema->jsonSchema())
        ->and($client->callTool('valid')->structuredContent)->toBe(['value' => 42])
        ->and($client->callTool('invalid')->isError)->toBeTrue()
        ->and($client->callTool('missing')->isError)->toBeTrue()
        ->and($client->callTool('reported_error')->text())->toBe('Try again');
});

it('turns malformed or oversized content and callback failures into safe errors', function () {
    $server = Server::make('bad')
        ->prompt(new Prompt('bad', fn(): array => [['role' => 'system', 'content' => []]]))
        ->prompt(new Prompt('secret', function (): never {
            throw new RuntimeException('secret');
        }))
        ->tool(Tool::make('huge')->run(fn() => ToolOutput::text(str_repeat('x', 4_194_305))))
        ->tool(Tool::make('bad')->run(fn() => ToolOutput::blocks([['type' => 'image', 'data' => '!!!!', 'mimeType' => 'image/png']])));
    $client = new Client(new InMemoryTransport($server));
    expect($client->callTool('huge')->isError)->toBeTrue()
        ->and($client->callTool('bad')->isError)->toBeTrue();
    foreach (['bad', 'secret'] as $name) {
        $reply = $server->process(rpc('prompts/get', ['name' => $name]));
        expect($reply->body['error']['code'])->toBe(Protocol::INTERNAL_ERROR)
            ->and($reply->body['error']['message'])->not->toContain('secret');
    }
    expect(fn() => ToolOutput::text('x')->annotations(['audience' => [['bad']]]))->toThrow(McpException::class);
});

it('serializes empty schema objects as objects and supports mixed structured blocks', function () {
    $server = Server::make('empty')->tool(Tool::make('empty_output')->run(fn() => ToolOutput::image(base64_encode('x'))->withStructured([])), outputSchema: Schema::object('empty'));
    $reply = $server->handle(rpc('tools/call', ['name' => 'empty_output']));
    expect($reply['result']['structuredContent'])->toBeInstanceOf(stdClass::class)
        ->and($reply['result']['content'][0]['type'])->toBe('image');
});

it('rejects malformed remote content before model text rendering', function () {
    $result = ['content' => [['type' => 'text', 'text' => ['unsafe']]]];
    expect(fn() => AiSdk\Mcp\Client\CallToolResult::fromResult($result))->toThrow(McpException::class);
});

it('serves prompts, templates and completions through the stdio binding', function (bool $modern) {
    $stdio = new AiSdk\Mcp\Server\StdioServer(primitivesServer());
    foreach ([
        ['prompts/get', ['name' => 'review', 'arguments' => ['language' => 'PHP']], 'messages'],
        ['resources/read', ['uri' => 'docs://guide/intro'], 'contents'],
        ['completion/complete', ['ref' => ['type' => 'ref/prompt', 'name' => 'review'], 'argument' => ['name' => 'language', 'value' => 'p']], 'completion'],
    ] as [$method, $params, $field]) {
        $reply = json_decode($stdio->respond(json_encode(rpc($method, $params, withMeta: $modern))), true);
        expect($reply['result'])->toHaveKey($field);
    }
})->with([false, true]);
