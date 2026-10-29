<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Mcp\Support\Payload;
use Closure;

/**
 * A readable resource. Content is produced lazily so listings stay cheap.
 */
final class TextResource
{
    /** @var Closure(): string */
    private readonly Closure $reader;

    /**
     * @param  string|callable(): string  $text
     * @param  array<string, mixed>  $annotations
     * @param  list<array<string, mixed>>  $icons
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public readonly string $uri,
        public readonly string $name,
        string|callable $text,
        public readonly string $mimeType,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly array $annotations,
        public readonly array $icons,
        public readonly array $meta,
    ) {
        Payload::annotations($annotations);
        Payload::icons($icons);
        $this->reader = is_string($text) ? static fn(): string => $text : Closure::fromCallable($text);
        Payload::check($this->definition());
    }

    /**
     * @param  string|callable(): string  $text
     * @param  array<string, mixed>  $annotations
     * @param  list<array<string, mixed>>  $icons
     * @param  array<string, mixed>  $meta
     */
    public static function text(
        string $uri,
        string $name,
        string|callable $text,
        string $mimeType = 'text/markdown',
        ?string $title = null,
        ?string $description = null,
        array $annotations = [],
        array $icons = [],
        array $meta = [],
    ): self {
        return new self($uri, $name, $text, $mimeType, $title, $description, $annotations, $icons, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return array_filter([
            'uri' => $this->uri,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
            'annotations' => $this->annotations === [] ? null : (object) $this->annotations,
            'icons' => $this->icons === [] ? null : $this->icons,
            '_meta' => $this->meta === [] ? null : (object) $this->meta,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $text = Payload::invoke($this->reader);
        if (! is_string($text)) {
            throw new \AiSdk\Mcp\Exceptions\McpException('A resource reader must return text.');
        }
        $result = [
            'uri' => $this->uri,
            'mimeType' => $this->mimeType,
            'text' => $text,
        ];
        if ($this->meta !== []) {
            $result['_meta'] = (object) $this->meta;
        }
        Payload::check($result);

        return $result;
    }
}
