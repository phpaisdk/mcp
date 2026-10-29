<?php

declare(strict_types=1);

use AiSdk\Mcp\Server\Prompt;
use AiSdk\Mcp\Server\ResourceTemplate;
use AiSdk\Mcp\Server\Server;
use AiSdk\Mcp\Server\TextResource;
use AiSdk\Mcp\Server\ToolOutput;
use AiSdk\Tool;

// Names and test content follow the official conformance everything-server fixture.
$image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
$audio = 'UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAAB9AAACABAAZGF0YQIAAAA=';
$embedded = ['type' => 'resource', 'resource' => ['uri' => 'test://embedded-resource', 'mimeType' => 'text/plain', 'text' => 'This is an embedded resource content.']];

return Server::make('aisdk-conformance', '1.0.0')
    ->tool(Tool::make('test_simple_text', 'Tests simple text content response')->run(fn() => 'This is a simple text response for testing.'))
    ->tool(Tool::make('test_image_content', 'Tests image content response')->run(fn() => ToolOutput::image($image)))
    ->tool(Tool::make('test_audio_content', 'Tests audio content response')->run(fn() => ToolOutput::audio($audio)))
    ->tool(Tool::make('test_embedded_resource', 'Tests embedded resource content response')->run(fn() => ToolOutput::blocks([$embedded])))
    ->tool(Tool::make('test_multiple_content_types', 'Tests mixed content')->run(fn() => ToolOutput::blocks([
        ['type' => 'text', 'text' => 'Multiple content types test:'],
        ['type' => 'image', 'data' => $image, 'mimeType' => 'image/png'], $embedded,
    ])))
    ->tool(Tool::make('test_error_handling', 'Reports an execution error')->run(fn() => ToolOutput::error('This tool intentionally returns an error for testing.')))
    ->resource(TextResource::text('test://static-text', 'static-text', 'This is the content of the static text resource.', 'text/plain', title: 'Static Text Resource'))
    ->resourceTemplate(new ResourceTemplate('test://template/{id}/data', 'template', fn(array $vars): string => json_encode([
        'id' => $vars['id'], 'templateTest' => true, 'data' => 'Data for ID: ' . $vars['id'],
    ], JSON_THROW_ON_ERROR), mimeType: 'application/json'))
    ->prompt(new Prompt('test_simple_prompt', fn(): array => [
        ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'This is a simple prompt for testing.']],
    ], description: 'A simple prompt without arguments'))
    ->prompt(new Prompt('test_prompt_with_arguments', fn(array $args): array => [
        ['role' => 'user', 'content' => ['type' => 'text', 'text' => "Prompt with arguments: arg1='{$args['arg1']}', arg2='{$args['arg2']}'"]],
    ], arguments: [['name' => 'arg1', 'required' => true], ['name' => 'arg2', 'required' => true]]));
