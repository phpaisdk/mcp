<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Mcp\Exceptions\ProtocolException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Support\HeaderEncoding;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Streamable HTTP binding (2026-07-28) as a PSR-15 request handler.
 *
 * Mount it on a single route that accepts POST. It performs the transport
 * validation the spec requires (Origin, header/body consistency) and delegates
 * everything else to the stateless Server.
 */
final class HttpHandler implements RequestHandlerInterface
{
    /**
     * @param  list<string>|null  $allowedOrigins  Exact origins (scheme://host[:port]) that may call
     *                                             from a browser; "*" allows any. Null allows only an
     *                                             Origin whose host equals the request Host.
     * @param  int  $maxBodyBytes  Largest accepted request body; larger requests get 413.
     * @param  (\Closure(ServerRequestInterface): ?ResponseInterface)|null  $authenticator  Return a response (for example 401 with
     *                                             WWW-Authenticate) to reject the request, or null to continue.
     */
    public function __construct(
        private readonly Server $server,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?array $allowedOrigins = null,
        private readonly int $maxBodyBytes = 1_048_576,
        private readonly ?\Closure $authenticator = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->originAllowed($request)) {
            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => Protocol::INVALID_REQUEST, 'message' => 'Origin not allowed.']], 403);
        }

        // Pre-2026 session mechanics (GET stream, DELETE) are intentionally unsupported.
        if ($request->getMethod() !== 'POST') {
            return $this->responseFactory->createResponse(405)->withHeader('Allow', 'POST');
        }

        if ($this->authenticator !== null) {
            $rejection = ($this->authenticator)($request);

            if ($rejection instanceof ResponseInterface) {
                return $rejection;
            }
        }

        $size = $request->getBody()->getSize();

        if (($size !== null && $size > $this->maxBodyBytes) || (int) $request->getHeaderLine('Content-Length') > $this->maxBodyBytes) {
            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => Protocol::INVALID_REQUEST, 'message' => 'Request body too large.']], 413);
        }

        // Read at most one byte past the limit so a body without a declared size stays bounded.
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->read($this->maxBodyBytes + 1);

        if (strlen($raw) > $this->maxBodyBytes) {
            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => Protocol::INVALID_REQUEST, 'message' => 'Request body too large.']], 413);
        }

        $message = json_decode($raw, true);

        if (! is_array($message) || array_is_list($message)) {
            $error = $message === null && json_last_error() !== JSON_ERROR_NONE
                ? ProtocolException::parseError()
                : ProtocolException::invalidRequest('The body must be a single JSON-RPC message.');

            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => $error->toError()], $error->httpStatus);
        }

        $rawId = $message['id'] ?? null;
        $id = is_int($rawId) || is_string($rawId) ? $rawId : null;

        try {
            $version = $this->validateHeaders($request, $message);
        } catch (ProtocolException $e) {
            return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $e->toError()], $e->httpStatus);
        }

        $reply = $this->server->process($message, $version);

        return $this->json($reply->body, $reply->status);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function validateHeaders(ServerRequestInterface $request, array $message): string
    {
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $versionHeader = $request->getHeaderLine('MCP-Protocol-Version');
        $modern = Server::hasProtocolMeta($params) || $versionHeader === Protocol::VERSION
            || ($method !== 'initialize' && $request->hasHeader('Mcp-Method') && $versionHeader === '');

        if (! $modern) {
            if ($versionHeader !== '' && ! in_array($versionHeader, Protocol::LEGACY_VERSIONS, true)) {
                throw new ProtocolException(
                    'Unsupported protocol version',
                    Protocol::INVALID_PARAMS,
                    ['supported' => Protocol::LEGACY_VERSIONS, 'requested' => substr($versionHeader, 0, 128)],
                    400,
                );
            }

            return $versionHeader === '' ? '2025-03-26' : $versionHeader;
        }

        // Modern notification headers are not defined by the binding. Still
        // reject an explicitly unsupported version on any incoming message.
        if ($versionHeader !== '' && ! in_array($versionHeader, [...Protocol::SUPPORTED_VERSIONS, ...Protocol::LEGACY_VERSIONS], true)) {
            throw ProtocolException::unsupportedVersion(substr($versionHeader, 0, 128), Protocol::SUPPORTED_VERSIONS);
        }

        if (! array_key_exists('id', $message)) {
            return Protocol::VERSION;
        }

        if ($versionHeader === '') {
            throw ProtocolException::headerMismatch('Missing required MCP-Protocol-Version header.');
        }

        $metaVersion = is_array($params['_meta'] ?? null) ? ($params['_meta'][Protocol::META_PROTOCOL_VERSION] ?? null) : null;
        if (is_string($metaVersion) && $metaVersion !== $versionHeader) {
            throw ProtocolException::headerMismatch('MCP-Protocol-Version header does not match _meta protocol version.');
        }

        if (! is_string($method)) {
            return Protocol::VERSION;
        }

        if ($request->getHeaderLine('Mcp-Method') !== $method) {
            throw ProtocolException::headerMismatch('Mcp-Method header is missing or does not match the request method.');
        }

        $nameField = match ($method) {
            'tools/call', 'prompts/get' => 'name',
            'resources/read' => 'uri',
            default => null,
        };

        if ($nameField === null) {
            return Protocol::VERSION;
        }

        $expected = $params[$nameField] ?? null;
        $header = HeaderEncoding::decode($request->getHeaderLine('Mcp-Name'));

        if (! is_string($expected) || $header === null || $header !== $expected) {
            throw ProtocolException::headerMismatch("Mcp-Name header is missing or does not match params.{$nameField}.");
        }

        return Protocol::VERSION;
    }

    private function originAllowed(ServerRequestInterface $request): bool
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '') {
            return true;
        }

        if ($this->allowedOrigins !== null) {
            return in_array('*', $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true);
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = $request->getUri()->getHost();

        return is_string($originHost) && strcasecmp($originHost, $requestHost) === 0;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function json(?array $body, int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)->withHeader('Cache-Control', 'no-store');

        if ($body === null) {
            return $response;
        }

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
