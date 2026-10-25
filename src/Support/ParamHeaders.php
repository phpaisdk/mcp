<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Support;

/**
 * Implements the x-mcp-header schema extension: validating tool definitions
 * and extracting the argument values that must be mirrored into HTTP headers.
 */
final class ParamHeaders
{
    private function __construct() {}

    /**
     * Header name => property path for every annotated parameter, or null when
     * the definition violates the extension's constraints (the tool must then
     * be excluded by HTTP clients).
     *
     * @param  array<string, mixed>  $inputSchema
     * @return array<string, list<string>>|null
     */
    public static function annotations(array $inputSchema): ?array
    {
        $found = [];

        if (! self::walk($inputSchema, [], true, $found)) {
            return null;
        }

        return $found;
    }

    /**
     * @param  array<string, list<string>>  $annotations
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>
     */
    public static function extract(array $annotations, array $arguments): array
    {
        $headers = [];

        foreach ($annotations as $headerName => $path) {
            $value = $arguments;

            foreach ($path as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    continue 2;
                }

                $value = $value[$segment];
            }

            $string = match (true) {
                is_string($value) => $value,
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value) => (string) $value,
                default => null,
            };

            if ($string !== null) {
                $headers[$headerName] = $string;
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $path
     * @param  array<string, list<string>>  $found
     */
    private static function walk(array $schema, array $path, bool $reachable, array &$found): bool
    {
        if (array_key_exists('x-mcp-header', $schema)) {
            if (! $reachable || $path === [] || ! self::register($schema, $path, $found)) {
                return false;
            }
        }

        foreach ($schema as $keyword => $child) {
            if (! is_array($child)) {
                continue;
            }

            if ($keyword === 'properties' && $reachable) {
                foreach ($child as $property => $propertySchema) {
                    if (is_array($propertySchema) && ! self::walk($propertySchema, [...$path, (string) $property], true, $found)) {
                        return false;
                    }
                }

                continue;
            }

            if (! self::walk($child, $path, false, $found)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $path
     * @param  array<string, list<string>>  $found
     */
    private static function register(array $schema, array $path, array &$found): bool
    {
        $name = $schema['x-mcp-header'];

        if (! is_string($name) || preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/", $name) !== 1) {
            return false;
        }

        if (! in_array($schema['type'] ?? null, ['string', 'integer', 'boolean'], true)) {
            return false;
        }

        foreach (array_keys($found) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return false;
            }
        }

        $found[$name] = $path;

        return true;
    }
}
