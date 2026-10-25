<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Server\Server;

/**
 * Connects a Client directly to a Server in the same process. Useful for tests
 * and for exposing in-process tools through the MCP tool bridge.
 */
final class InMemoryTransport implements Transport
{
    public function __construct(private readonly Server $server) {}

    public function send(array $message, array $paramHeaders = []): array
    {
        // Round-trip through JSON so the server sees exactly what a wire would carry.
        $decoded = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $reply = $this->server->handle($decoded);

        if ($reply === null) {
            throw new McpException('The server returned no response to a request.');
        }

        return json_decode(json_encode($reply, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
