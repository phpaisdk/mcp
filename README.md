# PHP AI SDK — MCP

A Model Context Protocol client and server for PHP. Expose SDK tools and resources over HTTP or stdio, or use remote MCP tools with any [PHP AI SDK](https://phpaisdk.com) provider.

## Installation

```bash
composer require aisdk/mcp
```

## Usage

```php
<?php

require __DIR__.'/vendor/autoload.php';

use AiSdk\Mcp\Server\Server;
use AiSdk\Mcp\Server\StdioServer;
use AiSdk\Tool;

$server = Server::make('my-tools', '1.0.0')
    ->tool(Tool::make('hello', 'Return a greeting')
        ->run(fn (): string => 'Hello from PHP!'));

(new StdioServer($server))->run();
```

Save the example as `server.php` and run it with `php server.php`. Supports modern MCP and explicit legacy client compatibility.

## Documentation

[Read the documentation](https://phpaisdk.com/docs/0.x/concepts/tools/mcp) for configuration, supported features, and more examples.

[Contributing](https://github.com/phpaisdk/.github/blob/main/CONTRIBUTING.md) · [Support](https://github.com/phpaisdk/.github/blob/main/SUPPORT.md)

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
