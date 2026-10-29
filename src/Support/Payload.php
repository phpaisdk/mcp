<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Support;

use AiSdk\Exceptions\AiSdkException;
use AiSdk\Mcp\Exceptions\McpException;

/** Bounds application-produced wire values before they reach a transport. */
final class Payload
{
    public static function invoke(callable $callback, mixed ...$arguments): mixed
    {
        try {
            return $callback(...$arguments);
        } catch (AiSdkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new McpException('MCP handler failed.', [], $e);
        }
    }

    public static function check(mixed $value, int $maxBytes = 4_194_304): void
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR, 64);
        } catch (\Throwable $e) {
            throw new McpException('MCP payload must be finite JSON with depth at most 64.', [], $e);
        }

        if (strlen($json) > $maxBytes) {
            throw new McpException('MCP payload exceeds its byte limit.');
        }
    }

    /** @return list<string> */
    public static function strings(mixed $values): array
    {
        self::check($values, 65_536);
        if (! is_array($values) || ! array_is_list($values)) {
            throw new McpException('Completion handler must return a list of strings.');
        }
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new McpException('Completion handler must return a list of strings.');
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $annotations */
    public static function annotations(array $annotations): void
    {
        self::check($annotations, 16_384);
        if (isset($annotations['audience']) && (! is_array($annotations['audience']) || ! array_is_list($annotations['audience'])
            || count($annotations['audience']) > 2)) {
            throw new McpException('Invalid MCP annotation audience.');
        }
        foreach ($annotations['audience'] ?? [] as $audience) {
            if (! in_array($audience, ['user', 'assistant'], true)) {
                throw new McpException('Invalid MCP annotation audience.');
            }
        }
        $priority = $annotations['priority'] ?? null;
        if ($priority !== null && ((! is_int($priority) && ! is_float($priority)) || $priority < 0 || $priority > 1)) {
            throw new McpException('MCP annotation priority must be between zero and one.');
        }
    }

    /** @param list<array<string, mixed>> $icons */
    public static function icons(array $icons): void
    {
        self::check($icons, 65_536);
        if (! array_is_list($icons) || count($icons) > 16) {
            throw new McpException('Invalid MCP icons.');
        }
        foreach ($icons as $icon) {
            if (! is_array($icon) || ! is_string($icon['src'] ?? null) || $icon['src'] === '') {
                throw new McpException('An MCP icon requires a source.');
            }
        }
    }

    /**
     * @param array<mixed> $blocks
     * @phpstan-assert list<array<string, mixed>> $blocks
     */
    public static function content(array $blocks): void
    {
        self::check($blocks);
        if (! array_is_list($blocks) || count($blocks) > 1000) {
            throw new McpException('MCP content must be a bounded list.');
        }
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                throw new McpException('Invalid MCP content block.');
            }
            foreach (array_keys($block) as $key) {
                if (! is_string($key)) {
                    throw new McpException('Invalid MCP content field.');
                }
            }
            $valid = match ($block['type'] ?? null) {
                'text' => is_string($block['text'] ?? null),
                'image', 'audio' => is_string($block['data'] ?? null) && is_string($block['mimeType'] ?? null)
                    && base64_decode($block['data'], true) !== false,
                'resource_link' => is_string($block['uri'] ?? null) && is_string($block['name'] ?? null),
                'resource' => is_array($block['resource'] ?? null) && is_string($block['resource']['uri'] ?? null)
                    && (is_string($block['resource']['text'] ?? null) || is_string($block['resource']['blob'] ?? null)),
                default => false,
            };
            if (! $valid) {
                throw new McpException('Invalid MCP content block.');
            }
            if (isset($block['annotations'])) {
                if (! is_array($block['annotations']) && ! $block['annotations'] instanceof \stdClass) {
                    throw new McpException('Invalid MCP content annotations.');
                }
                self::annotations((array) $block['annotations']);
            }
        }
    }
}
