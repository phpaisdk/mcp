<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

/**
 * Carries one JSON-RPC request to a server and returns its response.
 *
 * Implementations deliver exactly one message per call; the protocol is
 * stateless, so a transport never needs to remember earlier calls.
 */
interface Transport
{
    /**
     * @param  array<string, mixed>  $message  A JSON-RPC request.
     * @param  array<string, string>  $paramHeaders  Mcp-Param-* values (HTTP bindings only).
     * @return array<string, mixed> The decoded JSON-RPC response (result or error).
     */
    public function send(array $message, array $paramHeaders = []): array;
}
