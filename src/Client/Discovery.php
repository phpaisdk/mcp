<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Protocol;

/**
 * The result of server/discover.
 */
final class Discovery
{
    /**
     * @param  list<string>  $supportedVersions
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>|null  $serverInfo
     */
    public function __construct(
        public readonly array $supportedVersions,
        public readonly array $capabilities,
        public readonly ?array $serverInfo,
        public readonly ?string $instructions,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromResult(array $result): self
    {
        $versions = $result['supportedVersions'] ?? [];
        $capabilities = $result['capabilities'] ?? [];
        $info = $result['_meta'][Protocol::META_SERVER_INFO] ?? null;

        return new self(
            is_array($versions) ? array_values(array_filter($versions, is_string(...))) : [],
            is_array($capabilities) ? $capabilities : [],
            is_array($info) ? $info : null,
            is_string($result['instructions'] ?? null) ? $result['instructions'] : null,
        );
    }

    public function supportsTools(): bool
    {
        return array_key_exists('tools', $this->capabilities);
    }

    public function supportsResources(): bool
    {
        return array_key_exists('resources', $this->capabilities);
    }
}
