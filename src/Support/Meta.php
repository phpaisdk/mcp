<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Support;

use AiSdk\Mcp\Exceptions\ProtocolException;
use AiSdk\Mcp\Protocol;

/**
 * Validated per-request metadata carried in params._meta.
 */
final class Meta
{
    /**
     * @param  array<string, mixed>  $clientCapabilities
     * @param  array<string, mixed>|null  $clientInfo
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly string $protocolVersion,
        public readonly array $clientCapabilities,
        public readonly ?array $clientInfo,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public static function fromParams(array $params): self
    {
        $meta = $params['_meta'] ?? null;

        if (! is_array($meta)) {
            throw ProtocolException::invalidParams('Missing required _meta.', 400);
        }

        $version = $meta[Protocol::META_PROTOCOL_VERSION] ?? null;
        if (! is_string($version) || $version === '') {
            throw ProtocolException::invalidParams('Missing required _meta field ' . Protocol::META_PROTOCOL_VERSION . '.', 400);
        }

        if (! array_key_exists(Protocol::META_CLIENT_CAPABILITIES, $meta) || ! is_array($meta[Protocol::META_CLIENT_CAPABILITIES])) {
            throw ProtocolException::invalidParams('Missing required _meta field ' . Protocol::META_CLIENT_CAPABILITIES . '.', 400);
        }

        $info = $meta[Protocol::META_CLIENT_INFO] ?? null;

        return new self($version, $meta[Protocol::META_CLIENT_CAPABILITIES], is_array($info) ? $info : null, $meta);
    }

    public function supportsElicitation(): bool
    {
        return array_key_exists('elicitation', $this->clientCapabilities);
    }
}
