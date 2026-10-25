<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Tests\Support;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-18 client that dispatches straight into a PSR-15 handler, so client and
 * server can be tested end to end without a network.
 */
final class HandlerClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly RequestHandlerInterface $handler) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->handler->handle(new ServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->getHeaders(),
            (string) $request->getBody(),
        ));
    }
}
