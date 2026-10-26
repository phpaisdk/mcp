<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use AiSdk\Mcp\Server\Server;
use AiSdk\Mcp\Server\StdioServer;
use AiSdk\Tool;

$server = Server::make('env', '1.0.0')
    ->tool(Tool::make('read_env', 'Reads an environment variable')->run(fn(): string => (string) getenv('AISDK_MCP_TEST_SECRET')))
    ->tool(Tool::make('read_extra', 'Reads an explicitly passed variable')->run(fn(): string => (string) getenv('AISDK_MCP_EXTRA')));

(new StdioServer($server))->run();
