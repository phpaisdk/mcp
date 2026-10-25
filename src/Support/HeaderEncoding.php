<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Support;

/**
 * Value encoding for Mcp-Name and Mcp-Param-* headers (base64 sentinel form).
 */
final class HeaderEncoding
{
    private const string PREFIX = '=?base64?';
    private const string SUFFIX = '?=';

    private function __construct() {}

    public static function encode(string $value): string
    {
        if (self::needsEncoding($value)) {
            return self::PREFIX . base64_encode($value) . self::SUFFIX;
        }

        return $value;
    }

    public static function decode(string $header): ?string
    {
        if (! self::isSentinel($header)) {
            return self::isSafe($header) ? $header : null;
        }

        $encoded = substr($header, strlen(self::PREFIX), -strlen(self::SUFFIX));
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }

    private static function needsEncoding(string $value): bool
    {
        return ! self::isSafe($value) || self::isSentinel($value);
    }

    private static function isSentinel(string $value): bool
    {
        return str_starts_with($value, self::PREFIX) && str_ends_with($value, self::SUFFIX)
            && strlen($value) >= strlen(self::PREFIX) + strlen(self::SUFFIX);
    }

    /** Visible ASCII plus inner spaces/tabs, with no leading or trailing whitespace. */
    private static function isSafe(string $value): bool
    {
        return $value === trim($value, " \t")
            && preg_match('/^[\x20-\x7E\t]*$/', $value) === 1;
    }
}
