<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Support;

use AiSdk\Mcp\Exceptions\McpException;

/** Bounded, local-only adaptation for model tool schemas; remote validation remains authoritative. */
final class SchemaNormalizer
{
    private int $nodes = 0;
    private int $bytes = 0;
    private int $expansions = 0;
    private int $outputBytes = 0;

    /** @param array<string, mixed> $root */
    private function __construct(private readonly array $root) {}

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        $normalizer = new self($schema);
        $normalizer->preflight($schema);
        $normalizer->nodes = 0;
        $result = $normalizer->walk($schema, [], 0);
        $result['type'] ??= 'object';
        if ($result['type'] !== 'object') {
            throw new McpException('An MCP tool input schema must describe an object.');
        }
        $result['properties'] ??= new \stdClass();
        $result['additionalProperties'] ??= false;
        Payload::check($result, 262_144);

        return $result;
    }

    private function preflight(mixed $value, int $depth = 0): void
    {
        if ($depth > 32 || ++$this->nodes > 4096) {
            throw new McpException('Remote MCP schema exceeds the depth or node budget.');
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->bytes += strlen((string) $key) + 4;
                $this->preflight($child, $depth + 1);
            }
        } elseif (is_string($value)) {
            $this->bytes += strlen($value) + 2;
        } elseif (is_scalar($value) || $value === null) {
            $this->bytes += 16;
        } else {
            throw new McpException('Remote MCP schema is not JSON.');
        }
        if ($this->bytes > 262_144) {
            throw new McpException('Remote MCP schema exceeds the byte budget.');
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $active
     * @return array<string, mixed>
     */
    private function walk(array $schema, array $active, int $depth): array
    {
        if ($depth > 32 || ++$this->nodes > 4096) {
            throw new McpException('Remote MCP schema expansion exceeds the depth or node budget.');
        }
        if (array_key_exists('$ref', $schema)) {
            $ref = $schema['$ref'];
            if (! is_string($ref) || ($ref !== '#' && ! str_starts_with($ref, '#/'))) {
                throw new McpException('Only local JSON Pointer schema references are supported.');
            }
            $ref = rawurldecode($ref);
            if (in_array($ref, $active, true) || ++$this->expansions > 128) {
                throw new McpException('Remote MCP schema contains a reference cycle or exceeds the expansion budget.');
            }
            $target = $this->root;
            foreach ($ref === '#' ? [] : explode('/', substr($ref, 2)) as $part) {
                if (preg_match('/~(?![01])/', $part) === 1) {
                    throw new McpException('Invalid local JSON Pointer.');
                }
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                if (! is_array($target) || ! array_key_exists($part, $target)) {
                    throw new McpException('Unresolved local MCP schema reference.');
                }
                $target = $target[$part];
            }
            if (! is_array($target)) {
                throw new McpException('A schema reference must resolve to a schema object.');
            }
            unset($schema['$ref']);

            return $this->merge($this->walk($target, [...$active, $ref], $depth + 1), $this->walk($schema, $active, $depth + 1));
        }
        $result = [];
        foreach (['title', 'description', 'type'] as $key) {
            if (! array_key_exists($key, $schema)) {
                continue;
            }
            $value = $schema[$key];
            if ($key === 'type') {
                $types = is_array($value) && array_is_list($value) ? $value : [$value];
                foreach ($types as $type) {
                    if (! in_array($type, ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'], true)) {
                        throw new McpException('Invalid remote schema type.');
                    }
                }
                if ($types === []) {
                    throw new McpException('Empty remote schema type.');
                }
            } elseif (! is_string($value)) {
                throw new McpException('Invalid remote schema annotation.');
            }
            $result[$key] = $value;
            $this->charge($value);
        }
        if (array_key_exists('properties', $schema)) {
            $properties = $schema['properties'];
            if ($properties instanceof \stdClass) {
                $properties = get_object_vars($properties);
            }
            if (! is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                throw new McpException('Remote schema properties must be an object.');
            }
            $normalized = [];
            foreach ($properties as $name => $property) {
                if (! is_array($property)) {
                    throw new McpException('A remote property must be a schema object.');
                }
                $this->charge((string) $name);
                $child = $this->walk($property, $active, $depth + 1);
                $normalized[$name] = $child === [] ? new \stdClass() : $child;
            }
            $result['properties'] = $normalized === [] ? new \stdClass() : $normalized;
        }
        if (array_key_exists('required', $schema)) {
            if (! is_array($schema['required']) || ! array_is_list($schema['required'])) {
                throw new McpException('Invalid remote schema required fields.');
            }
            foreach ($schema['required'] as $name) {
                if (! is_string($name)) {
                    throw new McpException('Required field names must be strings.');
                }
            }
            $result['required'] = array_values(array_unique($schema['required']));
            $this->charge($result['required']);
        }
        if (array_key_exists('enum', $schema)) {
            if (! is_array($schema['enum']) || ! array_is_list($schema['enum']) || $schema['enum'] === []) {
                throw new McpException('Invalid remote schema enum.');
            }
            $result['enum'] = $schema['enum'];
            $this->charge($result['enum']);
        } elseif (array_key_exists('const', $schema)) {
            $result['enum'] = [$schema['const']];
            $this->charge($result['enum']);
        }
        foreach (['items', 'additionalProperties'] as $key) {
            if (! array_key_exists($key, $schema)) {
                continue;
            }
            $value = $schema[$key];
            if ($key === 'additionalProperties' && is_bool($value)) {
                $result[$key] = $value;
            } elseif (is_array($value)) {
                $child = $this->walk($value, $active, $depth + 1);
                $result[$key] = $child === [] ? new \stdClass() : $child;
            } else {
                throw new McpException('Invalid remote schema child.');
            }
        }
        if (isset($schema['format']) && is_string($schema['format']) && in_array($schema['format'], ['date-time'], true)) {
            $result['format'] = $schema['format'];
        }
        if (isset($schema['anyOf'])) {
            $result['anyOf'] = [];
            foreach ($this->branches($schema['anyOf']) as $branch) {
                $child = $this->walk($branch, $active, $depth + 1);
                $result['anyOf'][] = $child === [] ? new \stdClass() : $child;
            }
        }
        if (isset($schema['allOf'])) {
            foreach ($this->branches($schema['allOf']) as $branch) {
                $result = $this->merge($result, $this->walk($branch, $active, $depth + 1));
            }
        }
        if (in_array('object', (array) ($result['type'] ?? []), true) || isset($result['properties'])) {
            $result['properties'] ??= new \stdClass();
            $result['additionalProperties'] ??= false;
        }

        return $result;
    }

    private function charge(mixed $value): void
    {
        try {
            $this->outputBytes += strlen(json_encode($value, JSON_THROW_ON_ERROR, 64));
        } catch (\Throwable $e) {
            throw new McpException('Remote MCP schema is not valid JSON.', [], $e);
        }
        if ($this->outputBytes > 262_144) {
            throw new McpException('Expanded MCP schema exceeds the byte budget.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function branches(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new McpException('Schema composition must be a nonempty list.');
        }
        foreach ($value as $branch) {
            if (! is_array($branch)) {
                throw new McpException('Schema composition entries must be objects.');
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function merge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (! array_key_exists($key, $left)) {
                $left[$key] = $value;
            } elseif ($key === 'properties') {
                $properties = (array) $left[$key];
                foreach ((array) $value as $name => $property) {
                    if (isset($properties[$name]) && $properties[$name] !== $property) {
                        throw new McpException('Cannot safely merge overlapping allOf properties.');
                    }
                    $properties[$name] = $property;
                }
                $left[$key] = $properties === [] ? new \stdClass() : $properties;
            } elseif ($key === 'required') {
                $left[$key] = array_values(array_unique([...$left[$key], ...$value]));
            } elseif ($key === 'title' || $key === 'description') {
                continue;
            } elseif ($left[$key] !== $value) {
                throw new McpException('Cannot safely merge conflicting schema constraints.');
            }
        }

        return $left;
    }
}
