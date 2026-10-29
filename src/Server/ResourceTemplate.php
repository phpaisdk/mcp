<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Support\Payload;
use Closure;

/** RFC 6570 levels 1 and 2, scalar variables only. Values remain untrusted input. */
final class ResourceTemplate
{
    private readonly string $pattern;

    /** @var list<string> */
    private array $variables = [];

    /** @var Closure(array<string, string>, string): string */
    private readonly Closure $reader;

    /** @var array<string, Closure(string, array<string, string>): list<string>> */
    private array $completers = [];

    /**
     * @param callable(array<string, string>, string): string $reader
     * @param array<string, mixed> $annotations
     * @param list<array<string, mixed>> $icons
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $uriTemplate,
        public readonly string $name,
        callable $reader,
        public readonly string $mimeType = 'text/markdown',
        public readonly ?string $description = null,
        public readonly array $annotations = [],
        public readonly array $icons = [],
        public readonly array $meta = [],
    ) {
        if ($uriTemplate === '' || strlen($uriTemplate) > 4096 || $name === '') {
            throw new McpException('Invalid MCP resource template.');
        }
        $parts = preg_split('/(\{[^{}]*\})/', $uriTemplate, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '';
        foreach ($parts === false ? [] : $parts as $part) {
            if (! str_starts_with($part, '{')) {
                if (strpbrk($part, '{}') !== false) {
                    throw new McpException('Unbalanced URI template expression.');
                }
                $pattern .= preg_quote($part, '~');
                continue;
            }
            if (preg_match('/^\{([+#]?)((?:[A-Za-z0-9_.]|%[0-9A-Fa-f]{2})+(?:,(?:[A-Za-z0-9_.]|%[0-9A-Fa-f]{2})+)*)\}$/D', $part, $matches) !== 1) {
                throw new McpException('Only RFC 6570 level 1-2 scalar URI templates are supported.');
            }
            $operator = $matches[1];
            $names = explode(',', $matches[2]);
            $this->variables = [...$this->variables, ...$names];
            if (count($this->variables) > 32) {
                throw new McpException('Too many URI template variables.');
            }
            $characters = $operator === '' ? '[A-Za-z0-9_.\\~\-]' : "[A-Za-z0-9_.\\~\\-:/?#\\[\\]@!$&'()*+,;=]";
            $capture = '((?:' . $characters . '|%[0-9A-Fa-f]{2})*?)';
            $pattern .= ($operator === '#' ? '\\#' : '') . implode(',', array_fill(0, count($names), $capture));
        }
        $this->pattern = '~(*LIMIT_MATCH=10000)^' . $pattern . '$~D';
        $this->reader = Closure::fromCallable($reader);
        Payload::annotations($annotations);
        Payload::icons($icons);
        Payload::check($this->definition());
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return array_filter(['uriTemplate' => $this->uriTemplate, 'name' => $this->name, 'mimeType' => $this->mimeType,
            'description' => $this->description, 'annotations' => $this->annotations === [] ? null : (object) $this->annotations,
            'icons' => $this->icons === [] ? null : $this->icons, '_meta' => $this->meta === [] ? null : (object) $this->meta], static fn(mixed $v): bool => $v !== null);
    }

    /** @return array<string, string>|null */
    public function match(string $uri): ?array
    {
        if (strlen($uri) > 8192 || preg_match($this->pattern, $uri, $matches) !== 1) {
            return null;
        }
        $values = [];
        foreach ($this->variables as $index => $name) {
            $value = rawurldecode($matches[$index + 1]);
            if (isset($values[$name]) && $values[$name] !== $value) {
                return null;
            }
            $values[$name] = $value;
        }

        return $values;
    }

    /** @return array<string, mixed>|null */
    public function read(string $uri): ?array
    {
        $values = $this->match($uri);
        if ($values === null) {
            return null;
        }
        $text = Payload::invoke($this->reader, $values, $uri);
        if (! is_string($text)) {
            throw new McpException('A resource template reader must return text.');
        }
        Payload::check($text);

        return ['uri' => $uri, 'mimeType' => $this->mimeType, 'text' => $text];
    }

    /** @param callable(string, array<string, string>): list<string> $callback */
    public function onComplete(string $variable, callable $callback): self
    {
        if (! in_array($variable, $this->variables, true)) {
            throw new McpException('Unknown resource template variable.');
        }
        $this->completers[$variable] = Closure::fromCallable($callback);

        return $this;
    }

    public function hasCompletions(): bool
    {
        return $this->completers !== [];
    }

    /**
     * @param array<string, string> $context
     * @return list<string>
     */
    public function complete(string $argument, string $value, array $context): array
    {
        $result = isset($this->completers[$argument]) ? Payload::invoke($this->completers[$argument], $value, $context) : [];

        return Payload::strings($result);
    }
}
