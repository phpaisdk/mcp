<?php

declare(strict_types=1);

namespace AiSdk\Mcp\Client;

use AiSdk\Mcp\Exceptions\McpException;

/**
 * stdio transport: launches the server as a subprocess and exchanges
 * newline-delimited JSON-RPC over its standard streams.
 */
final class StdioTransport implements Transport
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $buffer = '';

    /** @var (callable(array<string, mixed>): void)|null */
    private $onNotification;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env  Extra variables for the server process. The parent environment is NOT
     *                                           inherited (it usually holds API keys); only a minimal safe set is passed
     *                                           unless $inheritEnvironment is true.
     * @param  (callable(array<string, mixed>): void)|null  $onNotification
     */
    public function __construct(
        private readonly array $command,
        private readonly ?string $cwd = null,
        private readonly ?array $env = null,
        private readonly float $timeoutSeconds = 60.0,
        ?callable $onNotification = null,
        private readonly bool $inheritEnvironment = false,
    ) {
        $this->onNotification = $onNotification;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function send(array $message, array $paramHeaders = []): array
    {
        $this->ensureStarted();

        $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        if (@fwrite($this->pipes[0], $line) === false) {
            $this->close();

            throw new McpException('Could not write to the MCP server process; it may have exited.');
        }

        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $decoded = json_decode($this->readLine($deadline), true);

            if (! is_array($decoded)) {
                continue;
            }

            if (($decoded['id'] ?? null) === ($message['id'] ?? null) && (isset($decoded['result']) || isset($decoded['error']))) {
                return $decoded;
            }

            if (isset($decoded['method']) && $this->onNotification !== null) {
                ($this->onNotification)($decoded);
            }
        }
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }

        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }

        for ($i = 0; $i < 20; $i++) {
            $status = proc_get_status($this->process);

            if (! $status['running']) {
                break;
            }

            usleep(50_000);
        }

        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }

        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            fclose($this->pipes[1]);
        }

        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
        $this->buffer = '';
    }

    private function ensureStarted(): void
    {
        if ($this->process !== null) {
            return;
        }

        $stderr = defined('STDERR') ? STDERR : ['file', '/dev/null', 'w'];
        $process = proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $this->pipes, $this->cwd, $this->environment());

        if (! is_resource($process)) {
            throw new McpException('Could not start the MCP server process: ' . implode(' ', $this->command));
        }

        $this->process = $process;
    }

    /**
     * @return array<string, string>|null Null means inherit the full parent environment (explicit opt-in only).
     */
    private function environment(): ?array
    {
        if ($this->inheritEnvironment) {
            return $this->env === null ? null : array_replace(getenv(), $this->env);
        }

        $safe = [];

        foreach (['PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TMPDIR', 'TEMP', 'TMP', 'SystemRoot', 'ComSpec', 'PATHEXT'] as $name) {
            $value = getenv($name);

            if (is_string($value)) {
                $safe[$name] = $value;
            }
        }

        return array_replace($safe, $this->env ?? []);
    }

    private function readLine(float $deadline): string
    {
        while (($pos = strpos($this->buffer, "\n")) === false) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $this->close();

                throw new McpException('Timed out waiting for the MCP server process.');
            }

            $read = [$this->pipes[1]];
            $write = $except = null;

            $ready = stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000));

            if ($ready === false) {
                throw new McpException('Failed waiting for the MCP server process.');
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = fread($this->pipes[1], 65536);

            if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) {
                $this->close();

                throw new McpException('The MCP server process exited before responding.');
            }

            $this->buffer .= $chunk;
        }

        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);

        return $line;
    }
}
