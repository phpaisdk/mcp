<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

/**
 * Outcome of processing one JSON-RPC message: the response body (null for
 * notifications) and the HTTP status a Streamable HTTP binding should use.
 */
final class Reply
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        public readonly ?array $body,
        public readonly int $status = 200,
    ) {}
}
