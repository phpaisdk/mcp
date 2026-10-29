#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# Explicit developer command only. The pinned stable upstream tool tests legacy MCP.
package="${MCP_CONFORMANCE_PACKAGE:-@modelcontextprotocol/conformance@0.1.16}"
port="${MCP_CONFORMANCE_PORT:-8765}"
if [[ ! "$port" =~ ^[0-9]{1,5}$ ]] || ((port < 1024 || port > 65535)); then
    echo 'MCP_CONFORMANCE_PORT must be an integer between 1024 and 65535.' >&2
    exit 1
fi
command -v php >/dev/null
command -v node >/dev/null
command -v npx >/dev/null
if php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1], $errno, $error, 0.2) === false ? 1 : 0);' "$port"; then
    echo 'Conformance fixture port is already in use; choose MCP_CONFORMANCE_PORT.' >&2
    exit 1
fi
log="$(mktemp)"
php -S "127.0.0.1:$port" tests/fixtures/conformance-http.php >"$log" 2>&1 &
server_pid=$!
cleanup() {
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    rm -f "$log"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
ready=false
for ((attempt = 0; attempt < 50; attempt++)); do
    if ! kill -0 "$server_pid" 2>/dev/null; then
        cat "$log" >&2
        exit 1
    fi
    if php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1], $errno, $error, 0.2) === false ? 1 : 0);' "$port"; then
        ready=true
        break
    fi
    sleep 0.1
done
if [[ "$ready" != true ]]; then
    echo 'Conformance fixture did not become ready.' >&2
    exit 1
fi
if [[ $# -eq 0 ]]; then
    set -- --suite active
fi
npx --yes --package="$package" conformance server --url "http://127.0.0.1:$port/mcp" "$@"
