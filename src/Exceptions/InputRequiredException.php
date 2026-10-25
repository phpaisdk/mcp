<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Exceptions;

/**
 * The server asked for input (elicitation, sampling or roots) and no input
 * handler was configured on the client, or the round-trip budget ran out.
 */
final class InputRequiredException extends McpException
{
    /**
     * @param  array<string, array<string, mixed>>  $inputRequests
     */
    public function __construct(
        string $message,
        public readonly array $inputRequests = [],
        public readonly ?string $requestState = null,
    ) {
        parent::__construct($message, ['inputKeys' => implode(',', array_keys($inputRequests))]);
    }
}
