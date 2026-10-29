<?php

declare(strict_types=1);

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Mcp\Client\Client;
use AiSdk\Mcp\Client\HttpTransport;
use AiSdk\Mcp\Client\StdioTransport;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Server\HttpHandler;
use AiSdk\Mcp\Server\Server;
use AiSdk\Tool;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('refuses to expose tools that need approval', function () {
    $tool = Tool::make('delete_all', 'Dangerous')->needsApproval()->run(fn() => 'gone');

    expect(fn() => Server::make('demo')->tool($tool))->toThrow(InvalidArgumentException::class);
});

it('bounds a request body that declares no size', function () {
    $factory = new Psr17Factory();
    $handler = new HttpHandler(demoServer(), $factory, $factory, maxBodyBytes: 100);
    $body = new class (str_repeat('x', 5000)) implements Psr\Http\Message\StreamInterface {
        private int $position = 0;

        public function __construct(private readonly string $data) {}
        public function __toString(): string
        {
            return $this->data;
        }
        public function close(): void {}
        public function detach()
        {
            return null;
        }
        public function getSize(): ?int
        {
            return null;
        }
        public function tell(): int
        {
            return $this->position;
        }
        public function eof(): bool
        {
            return $this->position >= strlen($this->data);
        }
        public function isSeekable(): bool
        {
            return false;
        }
        public function seek(int $offset, int $whence = SEEK_SET): void {}
        public function rewind(): void {}
        public function isWritable(): bool
        {
            return false;
        }
        public function write(string $string): int
        {
            return 0;
        }
        public function isReadable(): bool
        {
            return true;
        }
        public function read(int $length): string
        {
            $chunk = substr($this->data, $this->position, $length);
            $this->position += strlen($chunk);

            return $chunk;
        }
        public function getContents(): string
        {
            return $this->read(PHP_INT_MAX);
        }
        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };

    $response = $handler->handle((new ServerRequest('POST', 'https://x.test/mcp'))->withBody($body));

    expect($response->getStatusCode())->toBe(413);
});

it('does not wait for a response to a stdio notification', function () {
    $transport = new StdioTransport([PHP_BINARY, __DIR__ . '/fixtures/stdio-server.php'], timeoutSeconds: 5);

    $started = microtime(true);
    $result = $transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled']);
    $transport->close();

    expect($result)->toBe([])->and(microtime(true) - $started)->toBeLessThan(3.0);
});

it('closes a stdio server that streams an unbounded line', function () {
    $client = new Client(new StdioTransport([PHP_BINARY, '-r', 'fgets(STDIN); echo str_repeat("a", 200000);'], timeoutSeconds: 10, maxLineBytes: 1000));

    expect(fn() => $client->discover())->toThrow(McpException::class, 'byte limit');
});

it('reassembles server-sent events split inside a CRLF pair', function () {
    $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]);
    $parts = ["data: {$payload}\r", "\n\r", "\n"];
    $stream = new class ($parts) implements Psr\Http\Message\StreamInterface {
        public function __construct(private array $parts) {}
        public function __toString(): string
        {
            return implode('', $this->parts);
        }
        public function close(): void {}
        public function detach()
        {
            return null;
        }
        public function getSize(): ?int
        {
            return null;
        }
        public function tell(): int
        {
            return 0;
        }
        public function eof(): bool
        {
            return $this->parts === [];
        }
        public function isSeekable(): bool
        {
            return false;
        }
        public function seek(int $offset, int $whence = SEEK_SET): void {}
        public function rewind(): void {}
        public function isWritable(): bool
        {
            return false;
        }
        public function write(string $string): int
        {
            return 0;
        }
        public function isReadable(): bool
        {
            return true;
        }
        public function read(int $length): string
        {
            return (string) array_shift($this->parts);
        }
        public function getContents(): string
        {
            return $this->__toString();
        }
        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };
    $http = new class (new Response(200, ['Content-Type' => 'text/event-stream'], $stream)) implements ClientInterface {
        public function __construct(private readonly ResponseInterface $response) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return $this->response;
        }
    };
    $factory = new Psr17Factory();
    $transport = new HttpTransport('https://x.test/mcp', $http, $factory, $factory);

    expect($transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['result'])->toBe(['ok' => true]);
});
