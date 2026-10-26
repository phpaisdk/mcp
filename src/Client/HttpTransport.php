<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Exceptions\LegacyServerException;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Support\HeaderEncoding;
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
     * @param  int  $maxResponseBytes  Largest response (JSON body or whole event stream) accepted from a server.
     */
    public function __construct(
        private readonly string $url,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $headers = [],
        ?callable $onNotification = null,
        private readonly int $maxResponseBytes = 10_485_760,
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

        $decoded = json_decode($this->readBounded($response), true);

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
        foreach ($this->sseFrames($response) as $data) {
            $decoded = json_decode($data, true);

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

    /**
     * Read the body, refusing anything larger than the configured limit.
     */
    private function readBounded(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $size = $body->getSize();

        if ($size !== null && $size > $this->maxResponseBytes) {
            throw new McpException("The MCP server response ({$size} bytes) exceeds the {$this->maxResponseBytes} byte limit.");
        }

        $data = '';

        while (! $body->eof()) {
            $data .= $body->read(65536);

            if (strlen($data) > $this->maxResponseBytes) {
                throw new McpException("The MCP server response exceeds the {$this->maxResponseBytes} byte limit.");
            }
        }

        return $data;
    }

    /**
     * Yield the data payload of each SSE event, enforcing the byte budget over the whole stream.
     *
     * @return \Generator<int, string>
     */
    private function sseFrames(ResponseInterface $response): \Generator
    {
        $body = $response->getBody();
        $buffer = '';
        $total = 0;

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            $total += strlen($chunk);

            if ($total > $this->maxResponseBytes) {
                throw new McpException("The MCP event stream exceeds the {$this->maxResponseBytes} byte limit.");
            }

            $buffer .= str_replace("\r\n", "\n", $chunk);

            while (($end = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 2);
                $data = [];

                foreach (explode("\n", $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data[] = ltrim(substr($line, 5), ' ');
                    }
                }

                if ($data !== []) {
                    yield implode("\n", $data);
                }
            }
        }
    }
}
