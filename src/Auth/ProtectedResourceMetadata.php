<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Auth;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Support\Payload;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** RFC 9728 resource-server metadata; this does not implement an authorization server. */
final class ProtectedResourceMetadata
{
    /**
     * @param list<string> $authorizationServers
     * @param list<string> $scopesSupported
     * @param list<string> $bearerMethodsSupported
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $authorizationServers,
        public readonly array $scopesSupported = [],
        public readonly array $bearerMethodsSupported = ['header'],
    ) {
        Url::validate($resource);
        if (! array_is_list($authorizationServers) || $authorizationServers === [] || count($authorizationServers) > 16) {
            throw new McpException('MCP metadata requires one to sixteen authorization servers.');
        }
        foreach ($authorizationServers as $url) {
            if (! is_string($url)) {
                throw new McpException('Authorization server identifiers must be URLs.');
            }
            Url::validate($url);
        }
        Url::scopes($scopesSupported);
        if (! array_is_list($bearerMethodsSupported) || count($bearerMethodsSupported) > 3) {
            throw new McpException('Invalid bearer methods.');
        }
        foreach ($bearerMethodsSupported as $method) {
            if (! in_array($method, ['header', 'body', 'query'], true)) {
                throw new McpException('Invalid bearer method.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'resource' => $this->resource,
            'authorization_servers' => $this->authorizationServers,
            'scopes_supported' => $this->scopesSupported,
            'bearer_methods_supported' => $this->bearerMethodsSupported,
        ], static fn(mixed $v): bool => $v !== []);
    }

    public function metadataUrl(): string
    {
        $parts = Url::validate($this->resource);
        $path = $parts['path'] ?? '';

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . '/.well-known/oauth-protected-resource' . ($path === '/' ? '' : $path);
    }

    public function response(ResponseFactoryInterface $responses, StreamFactoryInterface $streams): ResponseInterface
    {
        Payload::check($this->toArray(), 65_536);

        return $responses->createResponse(200)->withHeader('Content-Type', 'application/json')
            ->withBody($streams->createStream(json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }
}
