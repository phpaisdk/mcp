<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Exceptions\ProtocolException;
use AiSdk\Mcp\Support\Payload;
use Closure;

final class Prompt
{
    /** @var Closure(array<string, string>): list<array<string, mixed>> */
    private readonly Closure $renderer;

    /** @var array<string, Closure(string, array<string, string>): list<string>> */
    private array $completers = [];

    /**
     * @param callable(array<string, string>): list<array<string, mixed>> $renderer
     * @param list<array{name: string, description?: string, required?: bool}> $arguments
     * @param list<array<string, mixed>> $icons
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $name,
        callable $renderer,
        public readonly array $arguments = [],
        public readonly ?string $description = null,
        public readonly ?string $title = null,
        public readonly array $icons = [],
        public readonly array $meta = [],
    ) {
        if ($name === '' || strlen($name) > 128 || ! array_is_list($arguments) || count($arguments) > 100) {
            throw new McpException('Invalid MCP prompt definition.');
        }
        $names = [];
        foreach ($arguments as $argument) {
            if (! is_array($argument) || ! is_string($argument['name'] ?? null) || $argument['name'] === ''
                || isset($names[$argument['name']]) || (isset($argument['description']) && ! is_string($argument['description']))
                || (isset($argument['required']) && ! is_bool($argument['required']))) {
                throw new McpException('Invalid MCP prompt argument.');
            }
            $names[$argument['name']] = true;
        }
        $this->renderer = Closure::fromCallable($renderer);
        Payload::icons($icons);
        Payload::check($this->definition());
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return array_filter(['name' => $this->name, 'description' => $this->description, 'title' => $this->title,
            'arguments' => $this->arguments, 'icons' => $this->icons === [] ? null : $this->icons,
            '_meta' => $this->meta === [] ? null : (object) $this->meta], static fn(mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function get(array $arguments): array
    {
        foreach ($arguments as $name => $value) {
            if (! is_string($value) || ! in_array($name, array_column($this->arguments, 'name'), true)) {
                throw ProtocolException::invalidParams('Invalid prompt argument.');
            }
        }
        foreach ($this->arguments as $argument) {
            if (($argument['required'] ?? false) && ! array_key_exists($argument['name'], $arguments)) {
                throw ProtocolException::invalidParams('Missing required prompt argument.');
            }
        }
        $messages = Payload::invoke($this->renderer, $arguments);
        Payload::check($messages);
        if (! is_array($messages) || ! array_is_list($messages) || count($messages) > 1000) {
            throw new McpException('Prompt messages must be a bounded list.');
        }
        foreach ($messages as $message) {
            if (! is_array($message) || ! in_array($message['role'] ?? null, ['user', 'assistant'], true) || ! is_array($message['content'] ?? null)) {
                throw new McpException('Invalid prompt message.');
            }
            Payload::content([$message['content']]);
        }

        return array_filter(['messages' => $messages, 'description' => $this->description], static fn(mixed $v): bool => $v !== null);
    }

    /** @param callable(string, array<string, string>): list<string> $callback */
    public function onComplete(string $argument, callable $callback): self
    {
        if (! in_array($argument, array_column($this->arguments, 'name'), true)) {
            throw new McpException('Unknown prompt completion argument.');
        }
        $this->completers[$argument] = Closure::fromCallable($callback);

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
