<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Auth;

use AiSdk\Mcp\Exceptions\McpException;

/** Syntax validation only. No discovery, resolution or network requests. */
final class Url
{
    /** @return array{scheme: string, host: string, port?: int, path?: string} */
    public static function validate(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\"]|%(?:0[0-9a-f]|1[0-9a-f]|20|7f)/i', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new McpException('Invalid OAuth resource URL.');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            throw new McpException('OAuth resource URLs cannot contain credentials, queries or fragments.');
        }
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $loopback)) {
            throw new McpException('OAuth resource URLs require HTTPS except on localhost.');
        }
        $ip = trim($host, '[]');
        if ($host === '' || (filter_var($ip, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw new McpException('Invalid OAuth resource host.');
        }

        return $parts;
    }

    /**
     * @param array<mixed> $scopes
     * @phpstan-assert list<string> $scopes
     */
    public static function scopes(array $scopes): void
    {
        if (! array_is_list($scopes) || count($scopes) > 128) {
            throw new McpException('OAuth scopes must be a bounded list.');
        }
        foreach ($scopes as $scope) {
            if (! is_string($scope) || strlen($scope) > 128 || preg_match('/^[\x21\x23-\x5b\x5d-\x7e]+$/D', $scope) !== 1) {
                throw new McpException('Invalid OAuth scope.');
            }
        }
    }
}
