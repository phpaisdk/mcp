<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Support\Payload;
use AiSdk\Schema;
use AiSdk\Support\Json;
use AiSdk\Support\SchemaValidator;

/**
 * Explicit tool result. Tool handlers may return plain PHP values (strings,
 * arrays, scalars) and let the server convert them, or return a ToolOutput to
 * control content blocks and the error flag.
 */
final class ToolOutput
{
    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public readonly array $content,
        public readonly mixed $structured = null,
        public readonly bool $hasStructured = false,
        public readonly bool $isError = false,
        public readonly array $meta = [],
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
        Payload::content($content);

        return new self($content, isError: $isError);
    }

    public static function image(string $data, string $mimeType = 'image/png'): self
    {
        return self::blocks([['type' => 'image', 'data' => $data, 'mimeType' => $mimeType]]);
    }

    public static function audio(string $data, string $mimeType = 'audio/wav'): self
    {
        return self::blocks([['type' => 'audio', 'data' => $data, 'mimeType' => $mimeType]]);
    }

    public static function resourceLink(TextResource $resource): self
    {
        return self::blocks([['type' => 'resource_link'] + $resource->definition()]);
    }

    public function withStructured(mixed $value): self
    {
        Payload::check($value);

        return new self($this->content, $value, true, $this->isError, $this->meta);
    }

    public function validateOutput(Schema $schema): self
    {
        if ($this->isError) {
            return $this;
        }
        if (! $this->hasStructured) {
            throw new McpException('Tool output is missing structured content.');
        }
        Payload::check($this->structured);
        SchemaValidator::validate($schema, $this->structured);

        return $this->withStructured(self::wireValue($schema, $this->structured));
    }

    private static function wireValue(Schema $schema, mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new McpException('Tool output schema is too deeply nested.');
        }
        if ($value === null) {
            return null;
        }
        if ($schema->type() === Schema::TYPE_OBJECT && is_array($value)) {
            foreach ($schema->properties() as $name => $property) {
                if (array_key_exists($name, $value)) {
                    $value[$name] = self::wireValue($property, $value[$name], $depth + 1);
                }
            }

            return $value === [] ? new \stdClass() : $value;
        }
        if ($schema->type() === Schema::TYPE_ARRAY && is_array($value) && ($items = $schema->items()) !== null) {
            return array_map(static fn(mixed $item): mixed => self::wireValue($items, $item, $depth + 1), $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $meta */
    public function withMeta(array $meta): self
    {
        Payload::check($meta);

        return new self($this->content, $this->structured, $this->hasStructured, $this->isError, $meta);
    }

    /** @param array<string, mixed> $annotations */
    public function annotations(array $annotations): self
    {
        Payload::annotations($annotations);
        $content = array_map(static fn(array $block): array => ['annotations' => (object) $annotations] + $block, $this->content);

        return new self($content, $this->structured, $this->hasStructured, $this->isError, $this->meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResult(): array
    {
        Payload::content($this->content);
        $result = ['content' => $this->content];
        if ($this->meta !== []) {
            $result['_meta'] = $this->meta;
        }

        if ($this->hasStructured) {
            $result['structuredContent'] = $this->structured;
        }

        $result['isError'] = $this->isError;

        Payload::check($result);

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
