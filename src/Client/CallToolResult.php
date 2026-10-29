<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Support\Payload;

/**
 * The outcome of tools/call.
 */
final class CallToolResult
{
    /**
     * @param  list<array<string, mixed>>  $content
     */
    public function __construct(
        public readonly array $content,
        public readonly mixed $structuredContent,
        public readonly bool $hasStructuredContent,
        public readonly bool $isError,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromResult(array $result): self
    {
        $content = $result['content'] ?? [];
        if (! is_array($content) || (isset($result['isError']) && ! is_bool($result['isError']))) {
            throw new McpException('Malformed tools/call response.');
        }
        Payload::content($content);
        Payload::check($result);

        return new self(
            $content,
            $result['structuredContent'] ?? null,
            array_key_exists('structuredContent', $result),
            ($result['isError'] ?? false) === true,
        );
    }

    /**
     * The text blocks joined together; non-text blocks are summarized.
     */
    public function text(): string
    {
        $parts = [];

        foreach ($this->content as $block) {
            $parts[] = match ($block['type'] ?? null) {
                'text' => (string) ($block['text'] ?? ''),
                'resource_link' => '[resource: ' . ($block['uri'] ?? '') . ']',
                'resource' => (string) ($block['resource']['text'] ?? '[embedded resource]'),
                'image', 'audio' => '[' . ($block['type'] ?? 'media') . ': ' . ($block['mimeType'] ?? 'unknown') . ']',
                default => '',
            };
        }

        return implode("\n", array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * What to hand back to a model: structured data when the tool provided it,
     * otherwise text. Execution errors are returned as text so the model can recover.
     */
    public function forModel(): mixed
    {
        if ($this->isError) {
            return 'Error: ' . ($this->text() !== '' ? $this->text() : 'the tool reported an error.');
        }

        return $this->hasStructuredContent ? $this->structuredContent : $this->text();
    }
}
