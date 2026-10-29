<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Mcp\Exceptions\ProtocolException;
use AiSdk\Mcp\Protocol;

/**
 * stdio binding: newline-delimited JSON-RPC over a pair of streams.
 *
 * Nothing but protocol messages may be written to the output stream; send
 * diagnostics to stderr.
 */
final class StdioServer
{
    public function __construct(
        private readonly Server $server,
        private readonly int $maxLineBytes = 4_194_304,
    ) {}

    /**
     * Serve until the input stream reaches EOF.
     *
     * @param  resource  $input
     * @param  resource  $output
     */
    public function run($input = STDIN, $output = STDOUT): void
    {
        while (($line = stream_get_line($input, $this->maxLineBytes + 1, "\n")) !== false) {
            if (strlen($line) > $this->maxLineBytes) {
                // Drain the rest of the oversized message, then report it.
                while (strlen((string) stream_get_line($input, $this->maxLineBytes + 1, "\n")) > $this->maxLineBytes) {
                }

                fwrite($output, json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => Protocol::INVALID_REQUEST, 'message' => 'Message too large.']], JSON_THROW_ON_ERROR) . "\n");
                fflush($output);

                continue;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $response = $this->respond($line);

            if ($response !== null) {
                fwrite($output, $response . "\n");
                fflush($output);
            }
        }
    }

    public function respond(string $line): ?string
    {
        $message = json_decode($line, true);

        if (! is_array($message) || array_is_list($message)) {
            $error = $message === null && json_last_error() !== JSON_ERROR_NONE
                ? ProtocolException::parseError()
                : ProtocolException::invalidRequest('The input must be a single JSON-RPC message.');

            return json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => $error->toError()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $version = Server::hasProtocolMeta($params) ? Protocol::VERSION : Protocol::LEGACY_VERSIONS[0];
        $response = $this->server->process($message, $version)->body;

        return $response === null
            ? null
            : json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
