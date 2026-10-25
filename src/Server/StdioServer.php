<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Server;

use AiSdk\Mcp\Exceptions\ProtocolException;

/**
 * stdio binding: newline-delimited JSON-RPC over a pair of streams.
 *
 * Nothing but protocol messages may be written to the output stream; send
 * diagnostics to stderr.
 */
final class StdioServer
{
    public function __construct(private readonly Server $server) {}

    /**
     * Serve until the input stream reaches EOF.
     *
     * @param  resource  $input
     * @param  resource  $output
     */
    public function run($input = STDIN, $output = STDOUT): void
    {
        while (($line = fgets($input)) !== false) {
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

        $response = $this->server->handle($message);

        return $response === null
            ? null
            : json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
