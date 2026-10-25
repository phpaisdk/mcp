<?php

declare(strict_types=1);

use AiSdk\Mcp\Support\HeaderEncoding;
use AiSdk\Mcp\Support\ParamHeaders;

it('encodes header values per the base64 sentinel rules', function (string $value, string $encoded) {
    expect(HeaderEncoding::encode($value))->toBe($encoded)
        ->and(HeaderEncoding::decode($encoded))->toBe($value);
})->with([
    ['us-west1', 'us-west1'],
    ['Hello, 世界', '=?base64?SGVsbG8sIOS4lueVjA==?='],
    [' padded ', '=?base64?IHBhZGRlZCA=?='],
    ["line1\nline2", '=?base64?bGluZTEKbGluZTI=?='],
    ['=?base64?literal?=', '=?base64?PT9iYXNlNjQ/bGl0ZXJhbD89?='],
]);

it('rejects unsafe plain header values on decode', function () {
    expect(HeaderEncoding::decode("bad\nvalue"))->toBeNull()
        ->and(HeaderEncoding::decode('=?base64?!!!?='))->toBeNull();
});

it('validates x-mcp-header annotations', function (array $schema, bool $valid) {
    expect(ParamHeaders::annotations($schema) !== null)->toBe($valid);
})->with([
    'plain property' => [['type' => 'object', 'properties' => ['r' => ['type' => 'string', 'x-mcp-header' => 'Region']]], true],
    'nested properties' => [['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'properties' => ['b' => ['type' => 'integer', 'x-mcp-header' => 'B']]]]], true],
    'number is not allowed' => [['type' => 'object', 'properties' => ['n' => ['type' => 'number', 'x-mcp-header' => 'N']]], false],
    'inside items' => [['type' => 'object', 'properties' => ['l' => ['type' => 'array', 'items' => ['type' => 'string', 'x-mcp-header' => 'L']]]], false],
    'inside anyOf' => [['type' => 'object', 'properties' => ['a' => ['anyOf' => [['type' => 'string', 'x-mcp-header' => 'A']]]]], false],
    'empty name' => [['type' => 'object', 'properties' => ['r' => ['type' => 'string', 'x-mcp-header' => '']]], false],
    'invalid token chars' => [['type' => 'object', 'properties' => ['r' => ['type' => 'string', 'x-mcp-header' => "Bad Name\n"]]], false],
    'case-insensitive duplicates' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'x-mcp-header' => 'Dup'], 'b' => ['type' => 'string', 'x-mcp-header' => 'dup']]], false],
]);

it('extracts annotated values and omits null or absent ones', function () {
    $annotations = ParamHeaders::annotations(['type' => 'object', 'properties' => [
        'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
        'count' => ['type' => 'integer', 'x-mcp-header' => 'Count'],
        'flag' => ['type' => 'boolean', 'x-mcp-header' => 'Flag'],
        'gone' => ['type' => 'string', 'x-mcp-header' => 'Gone'],
        'nil' => ['type' => 'string', 'x-mcp-header' => 'Nil'],
    ]]);

    expect(ParamHeaders::extract($annotations, ['region' => 'eu', 'count' => 7, 'flag' => false, 'nil' => null]))
        ->toBe(['Region' => 'eu', 'Count' => '7', 'Flag' => 'false']);
});
