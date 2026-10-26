# aisdk/mcp: guide for coding agents

Stateless Model Context Protocol client and server (revision **2026-07-28**) for the PHP AI SDK. Depends only on `aisdk/core` and PSR interfaces. Do not add another MCP SDK as a dependency.

## What this package is for

- Expose SDK tools/resources as an MCP server (`Server` + `HttpHandler` or `StdioServer`).
- Connect to MCP servers and use their tools with any provider (`Client::tools()` returns `AiSdk\Tool` instances).
- The PHP AI SDK website's docs MCP server is built on this package; keep the public API stable.

## Protocol rules you must not break

- **No sessions, no handshake.** Never add `initialize`, `Mcp-Session-Id`, or per-connection state. Every request carries `_meta` with `io.modelcontextprotocol/protocolVersion` and `io.modelcontextprotocol/clientCapabilities`.
- Results always carry `resultType` (`complete` or `input_required`). A missing `resultType` from a peer means `complete`.
- Servers never send requests to clients. Input from the client travels via `input_required` results and `inputResponses` on a retry with a **new** JSON-RPC id; `requestState` is opaque and echoed exactly.
- Error codes: `-32020` header mismatch, `-32021` missing capability, `-32022` unsupported version (`data.supported`/`data.requested`). Do not emit other codes in `-32020..-32099`.
- Streamable HTTP: POST only (`405` for GET/DELETE), `202` for notifications, `400` for metadata/header errors, `404` for unknown methods.
- Roots, Sampling and Logging are deprecated: do not implement them.
- Spec source of truth: https://modelcontextprotocol.io/specification/2026-07-28 (every page is also available as `.md`).

## Security rules

- Never inherit the parent environment into a stdio child by default; keep `StdioTransport::environment()` allow-list based.
- Every read from a peer is size-bounded (`maxResponseBytes`, `maxBodyBytes`, `maxLineBytes`). Do not add unbounded reads or buffers.
- `HttpHandler` must run the `authenticator` before parsing the body. Do not log request bodies or `Authorization` headers.
- Tool errors returned to the model are generic unless `exposeToolErrors()` was called.

## Layout

| Path | Purpose |
|---|---|
| `src/Protocol.php` | Version, `_meta` keys, error codes |
| `src/Server/Server.php` | Stateless dispatcher; `process()` returns `Reply` (body + HTTP status) |
| `src/Server/HttpHandler.php` | PSR-15 Streamable HTTP binding and header/Origin validation |
| `src/Server/StdioServer.php` | Newline-delimited stdio binding |
| `src/Client/Client.php` | Requests, version negotiation, MRTR loop, tool cache |
| `src/Client/{Http,Stdio,InMemory}Transport.php` | `Transport` implementations |
| `src/Client/McpTool.php` | Remote tool as `AiSdk\Tool` |
| `src/Support/` | `Meta`, `HeaderEncoding`, `ParamHeaders` (x-mcp-header) |
| `tests/Support/helpers.php` | `demoServer()`, `rpc()`; `HandlerClient` links a PSR-18 client to a PSR-15 handler with no network |

## Conventions

- PHP 8.3+, `declare(strict_types=1)`, PSR-4 `AiSdk\Mcp\` in `src/`.
- Use core exceptions' base (`AiSdk\Exceptions\AiSdkException` via `McpException`); protocol errors are `ProtocolException` with an `httpStatus`.
- Tests must not use the network. Use `InMemoryTransport`, `HandlerClient`, or the stdio fixture in `tests/fixtures/`.
- Messages in tests must be JSON round-tripped (the `rpc()` helper does this) because real transports deliver decoded arrays, not `stdClass`.

## Commands

```bash
composer lint       # pint --test
composer analyze    # phpstan level 8
composer test       # pest
composer test:all   # all three
```

## Docs

- Package docs: https://phpaisdk.com/docs/mcp (Markdown: append `.md`)
- Index for agents: https://phpaisdk.com/llms.txt
