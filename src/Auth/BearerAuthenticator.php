<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Auth;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Support\Payload;
use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BearerAuthenticator
{
    /** @var Closure(string, ServerRequestInterface): (list<string>|null) */
    private readonly Closure $verifier;

    /**
     * The application verifies signature, issuer, audience, expiry and revocation.
     * Return granted scopes for a valid token, or null for an invalid token.
     *
     * @param callable(string, ServerRequestInterface): (list<string>|null) $verifier
     * @param list<string> $requiredScopes
     */
    public function __construct(
        callable $verifier,
        private readonly ResponseFactoryInterface $responses,
        private readonly string $metadataUrl,
        private readonly array $requiredScopes = [],
    ) {
        Url::validate($metadataUrl);
        Url::scopes($requiredScopes);
        $this->verifier = Closure::fromCallable($verifier);
    }

    public function __invoke(ServerRequestInterface $request): ?ResponseInterface
    {
        try {
            $token = BearerToken::extract($request);
        } catch (McpException) {
            return BearerChallenge::response($this->responses, $this->metadataUrl, 400, 'invalid_request');
        }
        if ($token === null) {
            return BearerChallenge::response($this->responses, $this->metadataUrl, scopes: $this->requiredScopes);
        }

        try {
            $scopes = Payload::invoke($this->verifier, $token, $request);
            if (! is_array($scopes)) {
                return BearerChallenge::response($this->responses, $this->metadataUrl, error: 'invalid_token');
            }
            Url::scopes($scopes);
        } catch (\Throwable) {
            return BearerChallenge::response($this->responses, $this->metadataUrl, error: 'invalid_token');
        }
        if (array_diff($this->requiredScopes, $scopes) !== []) {
            return BearerChallenge::response($this->responses, $this->metadataUrl, 403, 'insufficient_scope', $this->requiredScopes);
        }

        return null;
    }
}
