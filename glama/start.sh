#!/bin/sh
# Glama start command: serve the sandbox WordPress on loopback, mint a throwaway API key, then speak
# MCP over stdio through stdio-bridge.mjs (Glama runs it behind mcp-proxy). Logs go to stderr only;
# stdout carries the protocol.
set -eu

WP=/wp
PORT=8765

PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$WP" >/tmp/php-server.log 2>&1 &

i=0
until curl -fsS -o /dev/null "http://127.0.0.1:$PORT/?rest_route=/" 2>/dev/null; do
	i=$((i + 1))
	[ "$i" -lt 100 ] || { echo "WordPress did not start" >&2; cat /tmp/php-server.log >&2; exit 1; }
	sleep 0.1
done

COWBOY_MCP_KEY=$(/usr/local/bin/wp --allow-root --path="$WP" eval 'echo Cowboy_MCP_Auth::generate_key( "Glama inspector" )["key"];' 2>/dev/null | tail -n 1)
COWBOY_MCP_URL="http://127.0.0.1:$PORT/?rest_route=/cowboy-mcp/v1/endpoint"
export COWBOY_MCP_KEY COWBOY_MCP_URL

exec node "$(dirname "$0")/stdio-bridge.mjs"
