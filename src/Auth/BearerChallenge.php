<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Auth;

use AiSdk\Mcp\Exceptions\McpException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class BearerChallenge
{
    /** @param list<string> $scopes */
    public static function header(string $metadataUrl, ?string $error = null, array $scopes = []): string
    {
        Url::validate($metadataUrl);
        Url::scopes($scopes);
        if ($error !== null && ! in_array($error, ['invalid_token', 'insufficient_scope', 'invalid_request'], true)) {
            throw new McpException('Invalid bearer challenge error.');
        }
        $header = 'Bearer resource_metadata="' . $metadataUrl . '"';
        if ($error !== null) {
            $header .= ', error="' . $error . '"';
        }
        if ($scopes !== []) {
            $header .= ', scope="' . implode(' ', array_unique($scopes)) . '"';
        }

        return $header;
    }

    /** @param list<string> $scopes */
    public static function response(ResponseFactoryInterface $responses, string $metadataUrl, int $status = 401, ?string $error = null, array $scopes = []): ResponseInterface
    {
        if (! in_array($status, [400, 401, 403], true)) {
            throw new McpException('Invalid bearer challenge status.');
        }

        return $responses->createResponse($status)->withHeader('Cache-Control', 'no-store')
            ->withHeader('WWW-Authenticate', self::header($metadataUrl, $error ?? ($status === 403 ? 'insufficient_scope' : null), $scopes));
    }
}
