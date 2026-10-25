<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Exceptions\LegacyServerException;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Support\HeaderEncoding;
use AiSdk\Utils\Stream\SseParser;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Streamable HTTP transport (2026-07-28) over any PSR-18 client.
 */
final class HttpTransport implements Transport
{
    /** @var (callable(array<string, mixed>): void)|null */
    private $onNotification;

    /**
     * @param  array<string, string>  $headers  Extra headers on every request (e.g. Authorization).
     * @param  (callable(array<string, mixed>): void)|null  $onNotification  Receives request-scoped notifications such as progress.
     */
    public function __construct(
        private readonly string $url,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $headers = [],
        ?callable $onNotification = null,
    ) {
        $this->onNotification = $onNotification;
    }

    public function send(array $message, array $paramHeaders = []): array
    {
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $method = (string) ($message['method'] ?? '');

        $request = $this->requestFactory->createRequest('POST', $this->url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withHeader('MCP-Protocol-Version', (string) ($meta[Protocol::META_PROTOCOL_VERSION] ?? Protocol::VERSION))
            ->withHeader('Mcp-Method', $method)
            ->withBody($this->streamFactory->createStream(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));

        $name = match ($method) {
            'tools/call', 'prompts/get' => $params['name'] ?? null,
            'resources/read' => $params['uri'] ?? null,
            default => null,
        };

        if (is_string($name)) {
            $request = $request->withHeader('Mcp-Name', HeaderEncoding::encode($name));
        }

        foreach ($paramHeaders as $headerName => $value) {
            $request = $request->withHeader('Mcp-Param-' . $headerName, HeaderEncoding::encode($value));
        }

        foreach ($this->headers as $headerName => $value) {
            $request = $request->withHeader($headerName, $value);
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new McpException('MCP HTTP transport error: ' . $e->getMessage(), ['url' => $this->url], $e);
        }

        return $this->parse($response, $message['id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(ResponseInterface $response, mixed $id): array
    {
        $status = $response->getStatusCode();
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'text/event-stream')) {
            return $this->fromEventStream($response, $id);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (is_array($decoded) && ($decoded['jsonrpc'] ?? null) === '2.0' && (isset($decoded['result']) || isset($decoded['error']))) {
            return $decoded;
        }

        if ($status === 401 || $status === 403) {
            throw new McpException(
                "The MCP server rejected the request ({$status}); authentication or authorization is required.",
                ['status' => $status, 'wwwAuthenticate' => $response->getHeaderLine('WWW-Authenticate')],
            );
        }

        if (in_array($status, [400, 404, 405], true)) {
            throw new LegacyServerException(
                "The server answered {$status} without a 2026-07-28 JSON-RPC error; it is probably a legacy (initialize-based) MCP server, which this client version does not support yet.",
                ['status' => $status],
            );
        }

        throw new McpException("Unexpected MCP HTTP response ({$status}).", ['status' => $status]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fromEventStream(ResponseInterface $response, mixed $id): array
    {
        foreach (SseParser::parseStream($response->getBody()) as $frame) {
            $decoded = json_decode($frame['data'], true);

            if (! is_array($decoded)) {
                continue;
            }

            if (($decoded['id'] ?? null) === $id && (isset($decoded['result']) || isset($decoded['error']))) {
                return $decoded;
            }

            if (isset($decoded['method']) && $this->onNotification !== null) {
                ($this->onNotification)($decoded);
            }
        }

        throw new McpException('The MCP event stream ended without a response.');
    }
}
