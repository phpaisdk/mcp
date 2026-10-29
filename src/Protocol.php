<?php

declare(strict_types=1);

namespace AiSdk\Mcp;

/**
 * Wire constants for MCP revision 2026-07-28 (stateless).
 */
final class Protocol
{
    public const string VERSION = '2026-07-28';

    /** Versions this package can speak, newest first. */
    public const array SUPPORTED_VERSIONS = [self::VERSION];

    /** Initialize-based revisions accepted by the server and opt-in clients. */
    public const array LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public const string META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const string META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
    public const string META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const string META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';
    public const string META_LOG_LEVEL = 'io.modelcontextprotocol/logLevel';

    public const string RESULT_COMPLETE = 'complete';
    public const string RESULT_INPUT_REQUIRED = 'input_required';

    public const int PARSE_ERROR = -32700;
    public const int INVALID_REQUEST = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS = -32602;
    public const int INTERNAL_ERROR = -32603;
    public const int HEADER_MISMATCH = -32020;
    public const int MISSING_REQUIRED_CLIENT_CAPABILITY = -32021;
    public const int UNSUPPORTED_PROTOCOL_VERSION = -32022;

    /** Methods that may answer with an InputRequiredResult (MRTR). */
    public const array MRTR_METHODS = ['tools/call', 'resources/read', 'prompts/get'];

    private function __construct() {}
}
