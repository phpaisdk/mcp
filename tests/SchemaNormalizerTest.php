<?php

declare(strict_types=1);

use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Support\SchemaNormalizer;

it('inlines local references and merges simple allOf without modifying remote definitions', function () {
    $schema = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema', '$id' => 'https://untrusted.test/schema',
        '$defs' => ['Person' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'format' => 'invented']], 'required' => ['name']]],
        'allOf' => [
            ['$ref' => '#/$defs/Person'],
            ['type' => 'object', 'properties' => ['age' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]]], 'required' => ['age']],
        ],
    ];
    $result = SchemaNormalizer::normalize($schema);
    expect($result['properties'])->toHaveKeys(['name', 'age'])
        ->and($result['required'])->toBe(['name', 'age'])
        ->and($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['age']['anyOf'])->toBe([['type' => 'integer'], ['type' => 'null']])
        ->and($result['properties']['name'])->toBe(['type' => 'string'])
        ->and($result)->not->toHaveKeys(['$defs', '$schema', '$id', 'allOf'])
        ->and($schema['$defs']['Person']['properties']['name']['format'])->toBe('invented');
});

it('preserves enums required fields date-time and explicit additionalProperties', function () {
    $result = SchemaNormalizer::normalize(['type' => 'object', 'additionalProperties' => true, 'required' => ['kind'], 'properties' => [
        'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
        'date' => ['type' => 'string', 'format' => 'date-time'],
        'free' => [],
    ]]);
    expect($result['additionalProperties'])->toBeTrue()
        ->and($result['properties']['date']['format'])->toBe('date-time')
        ->and($result['properties']['kind']['enum'])->toBe(['a', 'b'])
        ->and($result['properties']['free'])->toBeInstanceOf(stdClass::class)
        ->and(SchemaNormalizer::normalize([])['properties'])->toBeInstanceOf(stdClass::class);
});

it('resolves escaped JSON Pointer segments', function () {
    $result = SchemaNormalizer::normalize(['type' => 'object', '$defs' => ['a/b~c' => ['type' => 'string']], 'properties' => ['v' => ['$ref' => '#/$defs/a~1b~0c']]]);
    expect($result['properties']['v'])->toBe(['type' => 'string']);
});

it('rejects hostile schemas with typed errors', function (array $schema) {
    expect(fn() => SchemaNormalizer::normalize($schema))->toThrow(McpException::class);
})->with([
    [['$ref' => '#']],
    [['type' => 'object', '$defs' => ['a' => ['$ref' => '#/$defs/b'], 'b' => ['$ref' => '#/$defs/a']], 'properties' => ['v' => ['$ref' => '#/$defs/a']]]],
    [['$ref' => 'https://127.0.0.1/private']],
    [['$ref' => '#/$defs/missing']],
    [['description' => str_repeat('x', 1_048_576)]],
    [['type' => ['bad']]],
    [['required' => [['bad']]]],
    [['properties' => ['x' => false]]],
    [['allOf' => [['type' => 'object'], ['type' => 'string']]]],
    [['allOf' => [['properties' => ['v' => ['type' => 'string']]], ['properties' => ['v' => ['type' => 'number']]]]]],
]);

it('bounds deep nesting repeated reference expansion and expanded byte size', function () {
    $nested = ['type' => 'string'];
    for ($i = 0; $i < 100; $i++) {
        $nested = ['type' => 'object', 'properties' => ['child' => $nested]];
    }
    expect(fn() => SchemaNormalizer::normalize($nested))->toThrow(McpException::class, 'budget');

    $wide = ['type' => 'object', '$defs' => ['s' => ['type' => 'string']], 'properties' => []];
    for ($i = 0; $i < 129; $i++) {
        $wide['properties']['p' . $i] = ['$ref' => '#/$defs/s'];
    }
    expect(fn() => SchemaNormalizer::normalize($wide))->toThrow(McpException::class, 'budget');

    $large = ['type' => 'object', '$defs' => ['s' => ['type' => 'string', 'description' => str_repeat('x', 100_000)]], 'properties' => [
        'a' => ['$ref' => '#/$defs/s'], 'b' => ['$ref' => '#/$defs/s'], 'c' => ['$ref' => '#/$defs/s'],
    ]];
    expect(fn() => SchemaNormalizer::normalize($large))->toThrow(McpException::class, 'byte budget');
});

it('normalizes remote model tool schemas without calling the server tool', function () {
    $transport = scripted([['result' => ['tools' => [[
        'name' => 'remote', 'inputSchema' => ['type' => 'object', '$defs' => ['v' => ['type' => 'string']], 'properties' => ['value' => ['$ref' => '#/$defs/v']]],
    ]]]]]);
    $tool = (new Client($transport))->tools()[0];
    expect($tool->inputSchemaForProvider()['properties']['value'])->toBe(['type' => 'string'])
        ->and($transport->sent)->toHaveCount(1);
});
