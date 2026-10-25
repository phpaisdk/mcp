<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Exceptions;

/**
 * The peer answers like a pre-2026-07-28 (initialize-based) server, which this
 * client version does not speak yet.
 */
final class LegacyServerException extends McpException {}
