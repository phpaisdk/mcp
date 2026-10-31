# Changelog

Notable changes to `aisdk/mcp`. Release notes are also published at https://github.com/phpaisdk/mcp/releases.

## v0.11.0 — 2026-10-31

- Requires `aisdk/core ^0.11.0`.

- Run the official MCP server conformance suite against local fixtures with the opt-in `composer conformance` command.

- Publish OAuth protected-resource metadata and scope challenges, and authenticate header bearer tokens through an application-supplied verifier before parsing MCP requests.

- Adapt remote tool schemas for model providers with bounded local-reference expansion and safe failures for cyclic, oversized or conflicting schemas.

- Register prompts, URI resource templates and argument completions; clients can list, render and complete them over either protocol era.
- Return validated structured outputs, images, audio and resource links, with annotations, icons and metadata.

- Serve legacy MCP clients over Streamable HTTP and stdio alongside modern clients, with initialization, ping and shared tools/resources without session storage.
- Reject malformed notification parameters and unsupported legacy protocol-version headers before processing.

## v0.10.1 — 2026-10-29

- Odd JSON-RPC errors, non-PSR HTTP clients, a missing stdio command and malformed tool lists raise typed `McpException`/`ProtocolException` instead of PHP warnings or native exceptions.
- Approval-gated tools are refused on servers; remote tools can require approval; message sizes are bounded.

## v0.10.0 — 2026-10-29

### First release

- Expose SDK tools through a standalone MCP server, or use remote MCP tools with any model through the client. Streamable HTTP, stdio and in-memory transports; stateless by default, with `Client::legacyHttp()` for initialize-based servers.
- Remote tools can be marked as needing approval so an `Agent` pauses before calling them.

### Security

- stdio child processes get an allow-listed environment, peer messages and bodies are bounded, HTTP requests can be authenticated before parsing, and servers refuse approval-gated tools.

### Compatibility

- Requires `aisdk/core ^0.10.0`.
