<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Exceptions;

use AiSdk\Mcp\Protocol;

/**
 * A JSON-RPC level error. On the server it is serialized to an error response;
 * on the client it is raised from an error response received from the peer.
 */
final class ProtocolException extends McpException
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  int  $httpStatus  Status used by HTTP bindings when this error is returned.
     */
    public function __construct(
        string $message,
        int $code,
        public readonly ?array $data = null,
        public readonly int $httpStatus = 200,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, ['errorCode' => (string) $code], $previous, $code);
    }

    public static function parseError(string $detail = 'Parse error'): self
    {
        return new self($detail, Protocol::PARSE_ERROR, httpStatus: 400);
    }

    public static function invalidRequest(string $detail): self
    {
        return new self($detail, Protocol::INVALID_REQUEST, httpStatus: 400);
    }

    public static function methodNotFound(string $method): self
    {
        return new self("Method not found: {$method}", Protocol::METHOD_NOT_FOUND, httpStatus: 404);
    }

    public static function invalidParams(string $detail, int $httpStatus = 200): self
    {
        return new self($detail, Protocol::INVALID_PARAMS, httpStatus: $httpStatus);
    }

    public static function internal(string $detail = 'Internal error'): self
    {
        return new self($detail, Protocol::INTERNAL_ERROR, httpStatus: 500);
    }

    public static function headerMismatch(string $detail): self
    {
        return new self($detail, Protocol::HEADER_MISMATCH, httpStatus: 400);
    }

    /**
     * @param  list<string>  $supported
     */
    public static function unsupportedVersion(string $requested, array $supported): self
    {
        return new self(
            'Unsupported protocol version',
            Protocol::UNSUPPORTED_PROTOCOL_VERSION,
            ['supported' => $supported, 'requested' => $requested],
            400,
        );
    }

    public function isUnsupportedVersion(): bool
    {
        return $this->getCode() === Protocol::UNSUPPORTED_PROTOCOL_VERSION;
    }

    /**
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        $supported = $this->data['supported'] ?? [];

        return is_array($supported) ? array_values(array_filter($supported, is_string(...))) : [];
    }

    /**
     * @return array{code: int, message: string, data?: array<string, mixed>}
     */
    public function toError(): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];

        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
