<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Protocol;

/** Opt-in adapter for initialize-based MCP; never retries a tool call. */
final class LegacyTransport implements Transport
{
    private bool $initialized = false;

    public function __construct(private readonly Transport $transport, private readonly string $version = '2025-11-25')
    {
        if (! in_array($version, Protocol::LEGACY_VERSIONS, true)) {
            throw new McpException('Unsupported legacy MCP version.');
        }
    }

    public function send(array $message, array $paramHeaders = []): array
    {
        if (! $this->initialized) {
            $response = $this->transport->send([
                'jsonrpc' => '2.0', 'id' => 'aisdk-initialize', 'method' => 'initialize',
                'params' => ['protocolVersion' => $this->version, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'aisdk-mcp', 'version' => '0.10.0']],
            ]);
            if (($response['result']['protocolVersion'] ?? null) !== $this->version) {
                throw new McpException('Legacy MCP initialization failed or returned a different protocol version. Configure the peer version explicitly.');
            }
            $this->transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
            $this->initialized = true;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        unset($params['_meta']);
        $message['params'] = $params === [] ? new \stdClass() : $params;

        return $this->transport->send($message, $paramHeaders);
    }
}
