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
     */
    public function __construct(
        private readonly Server $server,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?array $allowedOrigins = null,
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

        $message = json_decode((string) $request->getBody(), true);

        if (! is_array($message) || array_is_list($message)) {
            $error = $message === null && json_last_error() !== JSON_ERROR_NONE
                ? ProtocolException::parseError()
                : ProtocolException::invalidRequest('The body must be a single JSON-RPC message.');

            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => $error->toError()], $error->httpStatus);
        }

        // Notifications are accepted without header validation.
        if (! array_key_exists('id', $message)) {
            return $this->json(null, 202);
        }

        $id = is_int($message['id']) || is_string($message['id']) ? $message['id'] : null;

        try {
            $this->validateHeaders($request, $message);
        } catch (ProtocolException $e) {
            return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $e->toError()], $e->httpStatus);
        }

        $reply = $this->server->process($message);

        return $this->json($reply->body, $reply->body === null ? 202 : $reply->status);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function validateHeaders(ServerRequestInterface $request, array $message): void
    {
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $versionHeader = $request->getHeaderLine('MCP-Protocol-Version');
        if ($versionHeader === '') {
            throw ProtocolException::headerMismatch('Missing required MCP-Protocol-Version header.');
        }

        $metaVersion = is_array($params['_meta'] ?? null) ? ($params['_meta'][Protocol::META_PROTOCOL_VERSION] ?? null) : null;
        if (is_string($metaVersion) && $metaVersion !== $versionHeader) {
            throw ProtocolException::headerMismatch("MCP-Protocol-Version header '{$versionHeader}' does not match _meta protocol version '{$metaVersion}'.");
        }

        if (! is_string($method)) {
            return;
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
            return;
        }

        $expected = $params[$nameField] ?? null;
        $header = HeaderEncoding::decode($request->getHeaderLine('Mcp-Name'));

        if (! is_string($expected) || $header === null || $header !== $expected) {
            throw ProtocolException::headerMismatch("Mcp-Name header is missing or does not match params.{$nameField}.");
        }
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
