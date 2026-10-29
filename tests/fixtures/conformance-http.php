<?php

declare(strict_types=1);

// Development-only router. Start through tests/conformance.sh on a loopback interface.
require __DIR__ . '/../../vendor/autoload.php';

use AiSdk\Mcp\Server\HttpHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/mcp') {
    http_response_code(404);

    return;
}

$factory = new Psr17Factory();
$server = require __DIR__ . '/conformance-server.php';
$input = fopen('php://input', 'rb');
$request = new ServerRequest($_SERVER['REQUEST_METHOD'] ?? 'POST', 'http://127.0.0.1:' . ($_SERVER['SERVER_PORT'] ?? '8765') . '/mcp', getallheaders(), $input);
$response = (new HttpHandler($server, $factory, $factory))->handle($request);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->getBody();
