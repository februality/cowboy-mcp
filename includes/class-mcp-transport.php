<?php
/**
 * Cowboy MCP – Streamable HTTP Transport
 *
 * Implements the MCP 2025-06-18 Streamable HTTP transport specification
 * as a WordPress REST API endpoint at /wp-json/cowboy-mcp/v1/endpoint.
 *
 * Protocol flow:
 *   1. Client POSTs  { method: "initialize", ... }
 *   2. Server returns capabilities + Mcp-Session-Id header.
 *   3. Client POSTs  { method: "notifications/initialized" }
 *   4. Client POSTs tool/resource calls; server returns JSON or SSE.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Transport {

    /** @var string REST namespace */
    const NS = 'cowboy-mcp/v1';

    /** @var string Session storage option prefix */
    const SESSION_PREFIX = 'cowboy_mcp_sess_';

    /** @var array Queued MCP log notifications to deliver with the response. */
    private static array $notification_queue = [];

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_filter( 'rest_pre_serve_request', [ __CLASS__, 'maybe_serve_sse' ], 10, 4 );
    }

    /* ── REST Routes ──────────────────────────────────────── */

    public static function register_routes(): void {
        // Single MCP endpoint supporting POST (messages) and GET (SSE stream) and DELETE (terminate)
        register_rest_route( self::NS, '/endpoint', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_post' ],
                'permission_callback' => [ Cowboy_MCP_Auth::class, 'validate_request' ],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'handle_get' ],
                'permission_callback' => [ Cowboy_MCP_Auth::class, 'validate_request' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'handle_delete' ],
                'permission_callback' => [ Cowboy_MCP_Auth::class, 'validate_request' ],
            ],
        ]);
    }

    /* ── POST handler (all JSON-RPC messages) ─────────────── */

    public static function handle_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();

        if ( empty( $body ) || ! isset( $body['jsonrpc'] ) ) {
            return new WP_Error( 'invalid_jsonrpc', 'Invalid JSON-RPC request.', [ 'status' => 400 ] );
        }

        // Validate JSON-RPC version.
        if ( ( $body['jsonrpc'] ?? '' ) !== '2.0' ) {
            return self::jsonrpc_error( $body['id'] ?? null, -32600, 'Invalid JSON-RPC version.' );
        }

        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];
        $id     = $body['id'] ?? null;

        // Params must be an array (object/array in JSON-RPC).
        if ( ! is_array( $params ) ) {
            return self::jsonrpc_error( $id, -32602, 'Invalid params: must be an object or array.' );
        }

        // ── Notifications (no id) → 202 Accepted ──
        if ( $id === null ) {
            return self::handle_notification( $method, $params );
        }

        // ── Requests (with id) → JSON response ──
        $result = self::dispatch( $method, $params, $request );

        if ( is_wp_error( $result ) ) {
            $response = new WP_REST_Response( [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => [
                    'code'    => (int) ( $result->get_error_data()['code'] ?? -32603 ),
                    'message' => $result->get_error_message(),
                ],
            ], 200 );
        } else {
            $response = new WP_REST_Response( [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => $result,
            ], 200 );
        }

        // Attach session header on initialize response.
        if ( $method === 'initialize' && ! is_wp_error( $result ) ) {
            $session_id = self::create_session();
            $response->header( 'Mcp-Session-Id', $session_id );
        }

        $response->header( 'Content-Type', 'application/json' );
        return $response;
    }

    /* ── GET handler (SSE stream – optional, return 405) ──── */

    public static function handle_get( WP_REST_Request $request ): WP_REST_Response {
        // This simple implementation does not support server-initiated SSE.
        return new WP_REST_Response( null, 405 );
    }

    /* ── DELETE handler (terminate session) ────────────────── */

    public static function handle_delete( WP_REST_Request $request ): WP_REST_Response {
        $session = $request->get_header( 'Mcp-Session-Id' );
        if ( $session ) {
            $data = get_transient( self::SESSION_PREFIX . $session );
            // Only allow deletion if session belongs to the current authenticated key.
            $current_key = Cowboy_MCP_Auth::$current_key_context['key_id'] ?? null;
            if ( $data && isset( $data['key_id'] ) && $data['key_id'] !== $current_key ) {
                return new WP_REST_Response( [ 'error' => 'Session does not belong to this API key.' ], 403 );
            }
            delete_transient( self::SESSION_PREFIX . $session );
        }
        return new WP_REST_Response( null, 204 );
    }

    /* ── Dispatch method calls ────────────────────────────── */

    /**
     * Route an MCP method to its handler.
     *
     * Returns WP_Error for protocol-level failures (unknown method, invalid params).
     * Handler-level errors (e.g. tool not found) are returned as successful JSON-RPC
     * responses with an `isError` flag — they use WP_Error internally but the dispatch
     * caller wraps them into a JSON-RPC error envelope via the `code` key in error data,
     * while the `status` key (HTTP status) is only set by auth/transport errors.
     */
    private static function dispatch( string $method, array $params, WP_REST_Request $request ): array|\stdClass|WP_Error {
        return match ( $method ) {
            'initialize'              => self::handle_initialize( $params ),
            'ping'                    => (object) [],
            'tools/list'              => Cowboy_MCP_Tools::list_tools( $params ),
            'tools/call'              => Cowboy_MCP_Tools::call_tool( $params ),
            'resources/list'          => Cowboy_MCP_Resources::list_resources( $params ),
            'resources/read'          => Cowboy_MCP_Resources::read_resource( $params ),
            'resources/templates/list'=> Cowboy_MCP_Resources::list_resource_templates( $params ),
            'prompts/list'            => Cowboy_MCP_Prompts::list_prompts( $params ),
            'prompts/get'             => Cowboy_MCP_Prompts::get_prompt( $params ),
            'completion/complete'     => Cowboy_MCP_Completion::complete( $params ),
            'logging/setLevel'        => self::handle_set_log_level( $params, $request ),
            default                   => new WP_Error( 'method_not_found', "Unknown method: {$method}", [ 'code' => -32601 ] ),
        };
    }

    /* ── MCP Lifecycle ────────────────────────────────────── */

    private static function handle_initialize( array $params ): array {
        return [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => [
                'tools'      => [ 'listChanged' => false ],
                'resources'  => [ 'subscribe' => false, 'listChanged' => false ],
                'prompts'    => [ 'listChanged' => false ],
                'completion' => (object) [],
                'logging'    => (object) [],
            ],
            'serverInfo' => [
                'name'    => wp_parse_url( home_url(), PHP_URL_HOST ),
                'version' => COWBOY_MCP_VERSION,
            ],
            'instructions' => self::build_instructions(),
        ];
    }

    private static function handle_notification( string $method, array $params ): WP_REST_Response {
        // Accept known notifications silently.
        return new WP_REST_Response( null, 202 );
    }

    /* ── Logging ──────────────────────────────────────────── */

    /**
     * Handle logging/setLevel — stores the requested log level in the session.
     */
    private static function handle_set_log_level( array $params, WP_REST_Request $request ): \stdClass|WP_Error {
        $valid_levels = [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ];
        $level = $params['level'] ?? '';
        if ( ! in_array( $level, $valid_levels, true ) ) {
            return new WP_Error( 'invalid_params', "Invalid log level: {$level}", [ 'code' => -32602 ] );
        }

        $session_id = $request->get_header( 'Mcp-Session-Id' );
        if ( $session_id ) {
            $session = get_transient( self::SESSION_PREFIX . $session_id );
            if ( $session ) {
                $session['log_level'] = $level;
                set_transient( self::SESSION_PREFIX . $session_id, $session, HOUR_IN_SECONDS );
            }
        }

        return (object) [];
    }

    /* ── Notification queue ───────────────────────────────── */

    /**
     * Queue a log notification to be delivered with the next response.
     */
    public static function queue_notification( string $level, string $data, string $logger = 'cowboy-mcp' ): void {
        self::$notification_queue[] = [
            'jsonrpc' => '2.0',
            'method'  => 'notifications/message',
            'params'  => compact( 'level', 'logger', 'data' ),
        ];
    }

    /**
     * Flush and return all queued notifications.
     */
    private static function flush_notifications(): array {
        $q = self::$notification_queue;
        self::$notification_queue = [];
        return $q;
    }

    /* ── SSE delivery ─────────────────────────────────────── */

    /**
     * Intercept MCP responses with pending notifications and deliver as SSE.
     */
    public static function maybe_serve_sse( $served, $result, $request, $server ): bool {
        if ( $served || empty( self::$notification_queue ) ) {
            return $served;
        }
        if ( $request->get_route() !== '/cowboy-mcp/v1/endpoint' || $request->get_method() !== 'POST' ) {
            return $served;
        }

        // Only use SSE when the client explicitly accepts it.
        // Per MCP Streamable HTTP spec, clients send Accept: application/json, text/event-stream.
        // Plain HTTP/JSON clients (curl, non-MCP) omit text/event-stream.
        $accept = $request->get_header( 'accept' ) ?? '';
        if ( stripos( $accept, 'text/event-stream' ) === false ) {
            self::flush_notifications(); // Discard — can't deliver inline with JSON.
            return $served;
        }

        $notifications = self::flush_notifications();
        $response_data = $result->get_data();

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );

        foreach ( $notifications as $n ) {
            $encoded = wp_json_encode( $n );
            if ( $encoded !== false ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo "event: message\ndata: {$encoded}\n\n";
            }
        }

        $encoded = wp_json_encode( $response_data );
        if ( $encoded === false ) {
            // Encoding failed (non-UTF-8 data, etc.) — send a JSON-RPC error instead.
            $fallback = wp_json_encode( [
                'jsonrpc' => '2.0',
                'id'      => $response_data['id'] ?? null,
                'error'   => [ 'code' => -32603, 'message' => 'Response encoding failed.' ],
            ] );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo "event: message\ndata: {$fallback}\n\n";
        } else {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo "event: message\ndata: {$encoded}\n\n";
        }

        flush();
        return true;
    }

    /* ── Session helpers ──────────────────────────────────── */

    private static function create_session(): string {
        $id = bin2hex( random_bytes( 16 ) );
        set_transient( self::SESSION_PREFIX . $id, [
            'created' => time(),
            'key_id'  => Cowboy_MCP_Auth::$current_key_context['key_id'] ?? null,
        ], HOUR_IN_SECONDS );
        return $id;
    }

    /* ── Instruction builder ──────────────────────────────── */

    private static function build_instructions(): string {
        global $wpdb;

        $name   = get_bloginfo( 'name' );
        $url    = home_url();
        $host   = wp_parse_url( $url, PHP_URL_HOST );
        $theme  = wp_get_theme()->get( 'Name' );
        $ver    = get_bloginfo( 'version' );
        $prefix = $wpdb->prefix;

        $settings  = get_option( 'cowboy_mcp_settings', [] );
        $safe_mode = ! empty( $settings['safe_mode'] ) ? 'ON' : 'OFF';

        $text = "You are connected to the WordPress site \"{$name}\" at {$url} (domain: {$host}).\n"
            . "WordPress {$ver}, theme \"{$theme}\", table prefix \"{$prefix}\".\n"
            . "\n"
            . "IMPORTANT: Read the wordpress://tools/catalog resource first — it lists every available tool with a one-line description, grouped by category. Find the tool you need there, then call cowboy_run directly; use cowboy_discover only to search by keyword or to fetch a tool's full inputSchema.\n"
            . "\n"
            . "Safety: Safe mode is {$safe_mode}. When ON, destructive tools require confirm: true. All non-read-only tools support dry_run: true to preview changes without executing.\n"
            . "\n"
            . "Beyond tools, use resources/list for read-only site data (site info, recent posts, plugin list, etc.), resources/templates/list for parameterized lookups like wordpress://posts/{id}, and prompts/list for guided workflows (site audit, SEO optimization, content migration, troubleshooting, security hardening, performance optimization).\n"
            . "\n"
            . "Conventions: Use {prefix} as the table prefix in SQL (e.g. {prefix}posts). Plugin file paths use folder/file.php format (e.g. woocommerce/woocommerce.php). File operations are relative to wp-content/. Post statuses: publish, draft, pending, private, trash, future.\n"
            . "\n"
            . "Always confirm destructive actions with the user before executing them.";

        // Append tool catalog so the model knows what's available.
        $catalog = Cowboy_MCP_Tools::get_gateway_catalog();
        if ( ! empty( $catalog ) ) {
            $total = array_sum( array_column( $catalog, 'count' ) );
            $lines = [];
            foreach ( $catalog as $cat => $info ) {
                $label   = str_replace( '_', '/', $cat );
                $lines[] = "- {$label} ({$info['count']} tools): {$info['description']}";
            }
            $text .= "\n\nThis site exposes {$total} tools via 2 gateway meta-tools. Categories:\n"
                . implode( "\n", $lines ) . "\n\n"
                . "Workflow: read wordpress://tools/catalog to find a tool by name, then cowboy_run to execute it; use cowboy_discover to search by keyword or to get a tool's full inputSchema.";
        }

        return $text;
    }

    /* ── JSON-RPC error helper ────────────────────────────── */

    private static function jsonrpc_error( $id, int $code, string $message ): WP_REST_Response {
        return new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => compact( 'code', 'message' ),
        ], 200 );
    }
}
