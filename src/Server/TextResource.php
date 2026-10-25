<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

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
     */
    private function __construct(
        public readonly string $uri,
        public readonly string $name,
        string|callable $text,
        public readonly string $mimeType,
        public readonly ?string $title,
        public readonly ?string $description,
    ) {
        $this->reader = is_string($text) ? static fn(): string => $text : Closure::fromCallable($text);
    }

    /**
     * @param  string|callable(): string  $text
     */
    public static function text(
        string $uri,
        string $name,
        string|callable $text,
        string $mimeType = 'text/markdown',
        ?string $title = null,
        ?string $description = null,
    ): self {
        return new self($uri, $name, $text, $mimeType, $title, $description);
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
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return [
            'uri' => $this->uri,
            'mimeType' => $this->mimeType,
            'text' => ($this->reader)(),
        ];
    }
}
