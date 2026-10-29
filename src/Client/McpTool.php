<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Support\SchemaNormalizer;
use AiSdk\Tool;
use AiSdk\ToolExecutionContext;

/**
 * A remote MCP tool exposed as an SDK Tool, so it can be passed to
 * Generate::text()->tools() and works with every provider.
 *
 * Arguments are validated by the MCP server against its own inputSchema.
 */
final class McpTool extends Tool
{
    /** @var array<string, mixed>|null */
    private ?array $normalizedSchema = null;

    /**
     * @param  array<string, mixed>  $definition  A tools/list entry.
     */
    public function __construct(
        private readonly Client $client,
        private readonly array $definition,
        private readonly string $exposedName,
    ) {
        $description = $definition['description'] ?? $definition['title'] ?? '';
        if (! is_string($description) || ! is_string($definition['name'] ?? null)) {
            throw new McpException('Malformed remote MCP tool definition.');
        }
        parent::__construct($exposedName, $description);
    }

    public function name(): string
    {
        return $this->exposedName;
    }

    public function description(): string
    {
        return (string) ($this->definition['description'] ?? $this->definition['title'] ?? '');
    }

    public function remoteName(): string
    {
        return (string) $this->definition['name'];
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchemaForProvider(): array
    {
        $schema = $this->definition['inputSchema'] ?? null;

        if ($schema !== null && ! is_array($schema)) {
            throw new McpException('Remote MCP tool inputSchema must be an object.');
        }

        return $this->normalizedSchema ??= SchemaNormalizer::normalize($schema ?? []);
    }

    public function handler(): callable
    {
        return fn(array $arguments): mixed => $this->client->callTool($this->remoteName(), $arguments)->forModel();
    }

    public function call(array $args, ?ToolExecutionContext $context = null): mixed
    {
        return $this->client->callTool($this->remoteName(), $args)->forModel();
    }
}
