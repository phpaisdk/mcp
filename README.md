# aisdk/mcp

<a href="https://github.com/phpaisdk/mcp/actions"><img alt="GitHub Workflow Status" src="https://img.shields.io/github/actions/workflow/status/phpaisdk/mcp/tests.yml?branch=main&label=Tests"></a>
<a href="https://packagist.org/packages/aisdk/mcp"><img alt="Total Downloads" src="https://img.shields.io/packagist/dt/aisdk/mcp"></a>
<a href="https://packagist.org/packages/aisdk/mcp"><img alt="Latest Version" src="https://img.shields.io/packagist/v/aisdk/mcp"></a>
<a href="https://packagist.org/packages/aisdk/mcp"><img alt="License" src="https://img.shields.io/packagist/l/aisdk/mcp"></a>
<a href="https://whyphp.dev"><img src="https://img.shields.io/badge/Why_PHP-in_2026-7A86E8?style=flat-square&labelColor=18181b" alt="Why PHP in 2026"></a>

------

Stateless [Model Context Protocol](https://modelcontextprotocol.io) client and server for the PHP AI SDK, implementing revision **2026-07-28**.

- **Server**: expose SDK tools and resources to any MCP client over Streamable HTTP (a PSR-15 handler) or stdio.
- **Client**: connect to MCP servers and hand their tools to `Generate::text()` as ordinary SDK tools, so they work with every provider.
- **Stateless by design**: modern clients send version and capabilities on every request. Servers also accept legacy initialization without storing sessions, so any server instance can answer subsequent requests.
- **No dependency on another MCP SDK**: only `aisdk/core` and PSR interfaces.

## Installation

```bash
composer require aisdk/mcp
```

## Use remote MCP tools with a model

```php
use AiSdk\Generate;
use AiSdk\Mcp\Client\Client;
use AiSdk\OpenAI;

$mcp = Client::http('https://example.com/mcp', $psr18, $requestFactory, $streamFactory, [
    'Authorization' => 'Bearer '.$token,
]);

$result = Generate::text('What is in the intro doc?')
    ->model(OpenAI::model('gpt-5.5'))
    ->tools(...$mcp->tools(prefix: 'docs_'))
    ->maxSteps(5)
    ->run();
```

Over stdio:

```php
$mcp = Client::stdio(['php', 'server.php']);
```

Other client calls:

```php
$mcp->discover();                       // versions, capabilities, instructions
$mcp->listTools();                      // cached for the server's ttlMs
$mcp->callTool('search', ['query' => 'streaming']);
$mcp->listResources();
$mcp->readResource('docs://intro');
```

Tool execution errors (`isError: true`) are returned to the model as text so it can recover. Protocol errors raise `ProtocolException`.

### Remote tool schemas

`McpTool::inputSchemaForProvider()` normalizes remote JSON Schema into a bounded subset before exposing it to a model. Local JSON Pointer `$ref`s are inlined, simple `allOf` objects are merged, and `anyOf`, enums, required fields and explicit `additionalProperties` are preserved. Object schemas receive `properties: {}` when absent and default to `additionalProperties: false`. Only the shared `date-time` format is retained; dialect identifiers, definitions after expansion, and unsupported keywords are removed. The remote server still owns argument validation, and providers may apply their own strict-mode restrictions.

External references, cycles, conflicting composition, invalid shapes and oversized schemas raise `McpException`. Limits are 32 nesting levels, 4,096 nodes, 128 reference expansions and 256 KiB before and after expansion. No schema references trigger network access.

### Servers that ask for input

A server can pause a `tools/call` and ask for input (elicitation). Register a handler and the client completes the round trip for you, echoing the server's opaque `requestState` exactly:

```php
$mcp->onInputRequired(function (string $key, string $method, array $params): array {
    return ['action' => 'accept', 'content' => ['name' => 'octocat']];
});
```

Without a handler, a request for input raises `InputRequiredException`.

## Build an MCP server

Any `AiSdk\Tool` is an MCP tool.

```php
use AiSdk\Schema;
use AiSdk\Tool;
use AiSdk\Mcp\Server\HttpHandler;
use AiSdk\Mcp\Server\Server;
use AiSdk\Mcp\Server\TextResource;

$search = Tool::make('search_docs', 'Search the documentation')
    ->input(Schema::object('search_input', properties: [
        Schema::string('query', 'What to look for')->required(),
    ]))
    ->run(fn (array $input): array => $docs->search($input['query']));

$server = Server::make('my-docs', '1.0.0')
    ->instructions('Search before answering questions about the SDK.')
    ->tool($search, ['readOnlyHint' => true])
    ->resource(TextResource::text('docs://intro', 'intro', fn () => file_get_contents('intro.md')))
    ->cache(ttlMs: 300_000);
```

Streamable HTTP (a PSR-15 `RequestHandlerInterface`, so it mounts in Laravel, Symfony, Slim or plain PHP):

```php
$handler = new HttpHandler($server, $psr17ResponseFactory, $psr17StreamFactory, allowedOrigins: ['https://app.example.com']);

// Route POST /mcp to $handler->handle($serverRequest).
```

stdio:

```php
(new StdioServer($server))->run(); // reads STDIN, writes STDOUT; log to STDERR only
```

Server behaviour worth knowing:

- Tools are listed in deterministic order, with `ttlMs`/`cacheScope` cache hints.
- Invalid tool input is returned as an execution error the model can correct. Exceptions thrown by a handler are reported generically; call `exposeToolErrors()` to include their messages.
- The modern HTTP binding validates `Origin` (same host by default), `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` against the body, and answers `405` to `GET`/`DELETE`.

## Prompts, templates and completions

```php
use AiSdk\Mcp\Server\Prompt;
use AiSdk\Mcp\Server\ResourceTemplate;

$prompt = new Prompt('review', fn (array $args): array => [
    ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Review '.$args['language']]],
], arguments: [['name' => 'language', 'description' => 'Source language', 'required' => true]]);
$prompt->onComplete('language', fn (string $prefix, array $context): array => ['PHP', 'Python']);

$template = new ResourceTemplate('docs://{section}/{page}', 'docs',
    fn (array $variables, string $uri): string => $docs->read($variables['section'], $variables['page']));
$template->onComplete('section', fn (string $prefix, array $context): array => ['guide', 'reference']);
$server->prompt($prompt)->resourceTemplate($template);

$mcp->listPrompts();
$mcp->getPrompt('review', ['language' => 'PHP']); // description and messages
$mcp->listResourceTemplates();
$mcp->readResource('docs://guide/intro');
$mcp->complete(['type' => 'ref/prompt', 'name' => 'review'], 'language', 'P');
$mcp->complete(['type' => 'ref/resource', 'uri' => 'docs://{section}/{page}'], 'section', 'g');
```

Template matching supports RFC 6570 level 1-2 scalar variables (`{var}`, `{a,b}`, `{+path}`, `{#fragment}`). Values are percent-decoded and remain untrusted: apply your own authorization and path restrictions in the reader. Templates are limited to 4 KiB and 32 variable occurrences; URIs to 8 KiB. Exact resources take precedence over templates. Completion callbacks return a bounded list of strings; responses include at most 100 suggestions with `total` and `hasMore`. The optional fourth argument to `complete()` is the map of already resolved arguments.

## Rich tool results

```php
use AiSdk\Mcp\Server\ToolOutput;

$server->tool($tool, outputSchema: Schema::object('answer', properties: [
    Schema::integer('value')->required(),
])); // successful results must include matching structured content

ToolOutput::structured(['value' => 42]); // also supplies a text fallback
ToolOutput::image($base64Png, 'image/png');
ToolOutput::audio($base64Wav, 'audio/wav');
ToolOutput::resourceLink($resource);
ToolOutput::blocks($contentBlocks)->withStructured(['value' => 42]);
ToolOutput::text('Hello')->annotations(['audience' => ['assistant'], 'priority' => 0.8])
    ->withMeta(['traceId' => '123']);
```

`Server::tool()` accepts optional `icons` and `meta` arguments. `TextResource::text()` accepts `annotations`, `icons` and `meta`; `Prompt` and `ResourceTemplate` accept icons and metadata too. Metadata is serialized as `_meta`. Output schemas use core's `Schema` primitives and validator; invalid or missing structured outputs become tool execution errors. Content is validated and capped at 4 MiB. Discovery and legacy initialization advertise registered capabilities, with `listChanged: false` and no resource subscriptions.

## Security

MCP servers and clients handle untrusted input in both directions, so the defaults are conservative:

- **stdio servers do not inherit your environment.** `Client::stdio()` passes only `PATH`, `HOME` and similar basics plus the `env` you give it, because your environment usually holds API keys. Pass `inheritEnvironment: true` only for servers you fully trust.
- **Response size limits.** The HTTP client refuses responses and event streams over 10 MiB (`maxResponseBytes`).
- **Message size limits.** `HttpHandler` answers `413` above 1 MiB (`maxBodyBytes`), even when the body has no declared size. `StdioServer` rejects lines above 4 MiB, and the stdio client closes a server process whose message exceeds `maxLineBytes` (4 MiB).
- **Authentication hook.** `HttpHandler` takes an `authenticator` closure that can return a `401` response before any message is processed. Always set one on a public endpoint.
- **Origin validation** is on by default (same host), which blocks DNS-rebinding attacks from browsers.
- **Tool errors are generic by default.** Exceptions from tool handlers are not sent to the model unless you call `exposeToolErrors()`.
- **Redirects.** Pass a PSR-18 client with redirects disabled for MCP endpoints, so credentials in custom headers cannot follow a redirect to another host.
- **Servers refuse approval-gated tools.** `Server::tool()` throws for a tool marked `needsApproval()`, because an MCP server cannot collect a human decision. Gate sensitive actions inside your own handler.
- **Remote tools can require approval.** `$mcp->tools(requireApproval: true)` marks every remote tool so an `Agent` pauses before calling it. Remote tools with names outside `A-Z a-z 0-9 _ - .` are skipped.
- **Treat tool descriptions and results from third-party servers as untrusted input.** They can contain prompt-injection text. Prefer allow-listing tools (`$mcp->tools(only: ['search'])`) and use `requireApproval: true` for tools with side effects.

## OAuth resource-server helpers

These helpers implement the resource-server side of RFC 9728 and RFC 6750. Supply your own authorization server and token verifier; the package performs no OAuth discovery or token issuance.

```php
use AiSdk\Mcp\Auth\BearerAuthenticator;
use AiSdk\Mcp\Auth\ProtectedResourceMetadata;

$metadata = new ProtectedResourceMetadata(
    resource: 'https://example.com/mcp',
    authorizationServers: ['https://auth.example.com'],
    scopesSupported: ['mcp:tools'],
);

// $verifyToken is your application's callable(string $token, ServerRequestInterface $request): ?array.
// Validate signature, issuer, audience/resource, expiry and revocation. Return granted
// scope strings for a valid token, or null for an invalid token. Do not log the token.
$authenticator = new BearerAuthenticator(
    verifier: $verifyToken,
    responses: $responseFactory,
    metadataUrl: $metadata->metadataUrl(),
    requiredScopes: ['mcp:tools'],
);
$handler = new HttpHandler($server, $responseFactory, $streamFactory, authenticator: $authenticator);

// Route GET /.well-known/oauth-protected-resource/mcp:
$response = $metadata->response($responseFactory, $streamFactory);
// Route POST /mcp:
$response = $handler->handle($serverRequest);
```

For a resource identifier without a path, `metadataUrl()` uses `/.well-known/oauth-protected-resource`. A resource path is appended to that well-known path per RFC 9728. The document advertises header tokens by default. Missing or invalid tokens receive 401, insufficient scopes receive 403 with `error="insufficient_scope"` and all required scopes, and malformed credentials receive 400. Verifier failures deny access without exposing their messages. Authentication runs before parsing the MCP body.

`BearerToken::extract($request)` accepts only one bounded `Authorization: Bearer ...` header. `BearerChallenge::header($metadataUrl, error: 'insufficient_scope', scopes: ['mcp:tools'])` builds a safe challenge independently; `BearerChallenge::response()` builds a PSR-7 rejection. Resource, issuer and metadata URLs require HTTPS except for exact `localhost`, `127.0.0.1` and `[::1]` loopback hosts. Credentials, query strings, fragments and control characters are rejected. URL validation performs no network requests or redirects.

## Compatibility

| | |
|---|---|
| Protocol | `2026-07-28`; servers also serve `2025-11-25`, `2025-06-18`, `2025-03-26` |
| Transports | Streamable HTTP (client and server), stdio (client and server), in-memory |
| Servers using the older `initialize` handshake | Opt-in `Client::legacyHttp()` for Streamable HTTP; default clients remain stateless |
| Roots, Sampling, Logging | Deprecated by the spec; not implemented |
| Not implemented yet | `subscriptions/listen`, the Tasks and MCP Apps extensions, OAuth client discovery |

### Serve legacy clients

`Server`, `HttpHandler` and `StdioServer` serve initialize-based clients alongside modern clients with the same tools and resources. Legacy initialization returns the requested supported revision (or `2025-11-25` for an unknown revision), server identity, capabilities and instructions. `notifications/initialized` is accepted and `ping` returns an empty result. No session IDs are issued or stored, and supplied session IDs are ignored.

For HTTP, legacy requests use `MCP-Protocol-Version` without modern metadata or `Mcp-Method`/`Mcp-Name` headers. Headerless legacy HTTP requests use `2025-03-26`. Unsupported legacy version headers return HTTP 400 with JSON-RPC `-32602`; modern requests retain their metadata and header checks. For stdio, requests carrying modern protocol metadata use modern semantics; requests without it use legacy semantics. The negotiated legacy revisions share the implemented tools/resources behavior, so no connection state is needed. GET/SSE streams, session deletion, subscriptions and server-initiated requests are unsupported.

### Connect to legacy HTTP servers

Use `Client::legacyHttp($url, $http, $requestFactory, $streamFactory)` for initialize-based peers. It supports explicit revisions `2025-11-25` (default), `2025-06-18`, and `2025-03-26`. Initialization must return the configured version. The client sends `notifications/initialized`, preserves the session header and never automatically replays a failed tool call. Default `Client::http()` behavior is unchanged. The client has no `legacyStdio()` factory. Old GET/SSE transport, server-initiated requests and automatic session recovery are not supported.

## Development

```bash
composer test:all   # pint, phpstan (level 8), pest
```

### Official MCP conformance suite (opt-in)

The official [MCP conformance framework](https://github.com/modelcontextprotocol/conformance) supports server and client testing ([SDK integration guide](https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md)). Node.js 22+ and npm/npx are required in addition to this package's Composer development dependencies.

```bash
composer conformance -- --scenario server-initialize
composer conformance -- --scenario tools-list
composer conformance                         # full upstream active server suite
```

The command starts `tests/fixtures/conformance-http.php` on `127.0.0.1:8765`, invokes the pinned stable `@modelcontextprotocol/conformance@0.1.16` tool through npx, and stops the fixture on exit. The first run downloads development tooling to npm's cache. Set `MCP_CONFORMANCE_PORT` to choose another local port. Results are written under ignored `results/` directories. Failures remain visible and return a nonzero status; no expected-failure baseline is applied.

Upstream stable 0.1.16 tests initialize-based revisions and does not include the 2026-07-28 stateless scenarios. To test modern requests using upstream's currently published prerelease tooling explicitly:

```bash
MCP_CONFORMANCE_PACKAGE=@modelcontextprotocol/conformance@0.2.0-alpha.12 \
    composer conformance -- --scenario server-stateless
```

The fixture registers the official test names for text/image/audio/embedded tool content, execution errors, text resources, templates and prompts. It does not implement the everything-server's logging, progress streams, sampling, elicitation, binary resources, subscriptions or extension fixtures; related full-suite scenarios can fail or skip. Verified locally: stable `server-initialize` and `tools-list` each pass; prerelease `server-stateless` passes 21/25 checks. Its four failures require diagnostic fixture tools (`test_missing_capability`, `test_streaming_elicitation`, `test_logging_tool`) that are not implemented here. No full-suite pass is claimed. These commands test the server role; they do not establish OAuth-client conformance or a full protocol conformance claim. Conformance is intentionally excluded from `test:all` and default CI.

## License

MIT
