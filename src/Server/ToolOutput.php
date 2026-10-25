<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Support\Json;

/**
 * Explicit tool result. Tool handlers may return plain PHP values (strings,
 * arrays, scalars) and let the server convert them, or return a ToolOutput to
 * control content blocks and the error flag.
 */
final class ToolOutput
{
    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function __construct(
        public readonly array $content,
        public readonly mixed $structured = null,
        public readonly bool $hasStructured = false,
        public readonly bool $isError = false,
    ) {}

    public static function text(string $text): self
    {
        return new self([['type' => 'text', 'text' => $text]]);
    }

    /**
     * A structured JSON value plus its serialized text block (for older clients).
     */
    public static function structured(mixed $value, ?string $text = null): self
    {
        return new self(
            [['type' => 'text', 'text' => $text ?? Json::encode($value)]],
            $value,
            true,
        );
    }

    /**
     * An execution error the model can read and recover from.
     */
    public static function error(string $message): self
    {
        return new self([['type' => 'text', 'text' => $message]], isError: true);
    }

    /**
     * @param  list<array<string, mixed>>  $content  Raw MCP content blocks.
     */
    public static function blocks(array $content, bool $isError = false): self
    {
        return new self($content, isError: $isError);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResult(): array
    {
        $result = ['content' => $this->content];

        if ($this->hasStructured) {
            $result['structuredContent'] = $this->structured;
        }

        $result['isError'] = $this->isError;

        return $result;
    }

    /**
     * Convert whatever a tool handler returned into a ToolOutput.
     */
    public static function from(mixed $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value === null => new self([]),
            is_string($value) => self::text($value),
            is_bool($value) => self::text($value ? 'true' : 'false'),
            is_int($value), is_float($value) => self::text((string) $value),
            default => self::structured($value instanceof \JsonSerializable ? $value->jsonSerialize() : $value),
        };
    }
}
