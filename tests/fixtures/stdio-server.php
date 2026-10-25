<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../Support/helpers.php';

(new AiSdk\Mcp\Server\StdioServer(demoServer()))->run();
