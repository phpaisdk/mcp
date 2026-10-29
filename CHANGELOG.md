# Changelog

Notable changes to `aisdk/mcp`. Release notes are also published at https://github.com/phpaisdk/mcp/releases.

## v0.10.0 — 2026-10-29

### First release

- Expose SDK tools through a standalone MCP server, or use remote MCP tools with any model through the client. Streamable HTTP, stdio and in-memory transports; stateless by default, with `Client::legacyHttp()` for initialize-based servers.
- Remote tools can be marked as needing approval so an `Agent` pauses before calling them.

### Security

- stdio child processes get an allow-listed environment, peer messages and bodies are bounded, HTTP requests can be authenticated before parsing, and servers refuse approval-gated tools.

### Compatibility

- Requires `aisdk/core ^0.10.0`.
