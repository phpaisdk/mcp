<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Auth;

use AiSdk\Mcp\Exceptions\McpException;
use Psr\Http\Message\ServerRequestInterface;

final class BearerToken
{
    /** Only the Authorization header is accepted; body/query credentials are ignored. */
    public static function extract(ServerRequestInterface $request): ?string
    {
        $headers = $request->getHeader('Authorization');
        if ($headers === []) {
            return null;
        }
        if (count($headers) !== 1 || strlen($headers[0]) > 8192) {
            throw new McpException('Malformed Authorization header.');
        }
        $header = $headers[0];
        if (preg_match('/^Bearer +([A-Za-z0-9\-._~+\/]+=*)$/iD', $header, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/^Bearer(?:\s|$)/i', $header) === 1 || str_contains($header, ',')) {
            throw new McpException('Malformed bearer credentials.');
        }

        return null;
    }
}
