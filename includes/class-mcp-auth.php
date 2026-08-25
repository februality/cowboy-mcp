<?php
/**
 * Cowboy MCP – Authentication
 *
 * Manages API key generation, Bearer token validation, and rate limiting.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Auth {

    /** Option key where API keys are stored. */
    const OPTION_KEY = 'cowboy_mcp_api_keys';

    /** Rate limit window duration in seconds. */
    private const RATE_LIMIT_WINDOW = 60;

    /** Per-IP request ceiling per window (applies to all requests, incl. auth failures). */
    private const IP_RATE_LIMIT = 30;

    /** Populated on successful validation so log entries can identify the key. */
    public static array $current_key_context = [];

    /**
     * Bootstrap hook — intentionally empty.
     *
     * Auth validation is triggered per-request via the REST permission_callback,
     * not via a global hook. This method exists so the boot sequence in
     * cowboy-mcp.php can call ::init() uniformly for every class.
     */
    public static function init(): void {
    }

    /* ── Key management ────────────────────────────────────── */

    /**
     * Generate a new API key.
     *
     * @param string $label  Human-readable label for the key.
     * @return array{id: string, key: string, label: string, created: int}
     */
    public static function generate_key( string $label = 'Claude Code', ?array $scope = null ): array {
        $keys = get_option( self::OPTION_KEY, [] );

        $raw_key = 'mcp_' . bin2hex( random_bytes( 32 ) );
        $id      = substr( md5( $raw_key ), 0, 12 );

        $record = [
            'id'       => $id,
            'hash'     => wp_hash_password( $raw_key ),
            'label'    => sanitize_text_field( $label ),
            'created'  => time(),
            'last_used' => null,
            'prefix'   => substr( $raw_key, 0, 8 ),  // visible prefix for identification
        ];

        $sanitized_scope = self::sanitize_scope( $scope );
        if ( null !== $sanitized_scope ) {
            $record['scope'] = $sanitized_scope;
        }

        $keys[ $id ] = $record;
        update_option( self::OPTION_KEY, $keys );

        // Return the raw key only once (never stored in plain text).
        return [
            'id'      => $id,
            'key'     => $raw_key,
            'label'   => $record['label'],
            'created' => $record['created'],
        ];
    }

    /**
     * Revoke an API key by its ID.
     */
    public static function revoke_key( string $id ): bool {
        $keys = get_option( self::OPTION_KEY, [] );
        if ( ! isset( $keys[ $id ] ) ) {
            return false;
        }
        unset( $keys[ $id ] );
        update_option( self::OPTION_KEY, $keys );
        return true;
    }

    /**
     * Normalize a scope array from admin/consent input.
     *
     * Returns null (= full access, no scope field stored) for absent or
     * unrecognized modes, so malformed input can never store a corrupt scope.
     * Tool names are validated against the tool-name character set; anything
     * else is dropped.
     */
    public static function sanitize_scope( ?array $scope ): ?array {
        $mode = $scope['mode'] ?? '';
        if ( 'read_only' === $mode ) {
            return [ 'mode' => 'read_only', 'allowed_tools' => [] ];
        }
        if ( 'custom' === $mode ) {
            $tools = array_values( array_unique( array_filter( array_map(
                // Cowboy tool names (underscores, no slash) or Abilities API names
                // (namespace/name, dashes) — the inbound bridge exposes the latter as tools.
                static fn( $t ) => preg_match( '/^(?:[a-z0-9_]{1,64}|[a-z0-9-]{1,64}\/[a-z0-9-]{1,64})$/', (string) $t ) ? (string) $t : '',
                (array) ( $scope['allowed_tools'] ?? [] )
            ) ) ) );
            return [ 'mode' => 'custom', 'allowed_tools' => $tools ];
        }
        return null;
    }

    /**
     * Update (or clear, with null) a key's scope. Takes effect on the key's
     * next request — no re-issue needed.
     */
    public static function update_key_scope( string $id, ?array $scope ): bool {
        $keys = get_option( self::OPTION_KEY, [] );
        if ( ! isset( $keys[ $id ] ) ) {
            return false;
        }
        $sanitized = self::sanitize_scope( $scope );
        if ( null === $sanitized ) {
            unset( $keys[ $id ]['scope'] );
        } else {
            $keys[ $id ]['scope'] = $sanitized;
        }
        update_option( self::OPTION_KEY, $keys );
        return true;
    }

    /**
     * List all keys (without hashes).
     *
     * @return array<int, array{id: string, label: string, prefix: string, created: int, last_used: int|null}>
     */
    public static function list_keys(): array {
        $keys = get_option( self::OPTION_KEY, [] );
        return array_map( function ( $k ) {
            return [
                'id'        => $k['id'],
                'label'     => $k['label'],
                'prefix'    => $k['prefix'] ?? '---',
                'created'   => $k['created'],
                'last_used' => $k['last_used'] ?? null,
                'scope'     => ( isset( $k['scope'] ) && is_array( $k['scope'] ) ) ? $k['scope'] : null,
            ];
        }, array_values( $keys ) );
    }

    /**
     * Whether anything can authenticate to this site yet — an API key or a
     * registered OAuth client. Drives the post-activation setup notice.
     */
    public static function site_has_credentials(): bool {
        return ! empty( get_option( self::OPTION_KEY ) )
            || ! empty( get_option( Cowboy_MCP_OAuth::CLIENTS_OPTION ) );
    }

    /* ── Validation ────────────────────────────────────────── */

    /**
     * Validate a Bearer token from the request.
     *
     * @return bool|WP_Error
     */
    public static function validate_request( WP_REST_Request $request ) {
        $settings = Cowboy_MCP_Tools::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return new WP_Error( 'mcp_disabled', 'MCP server is disabled.', [ 'status' => 503 ] );
        }

        // Per-IP throttle BEFORE any token work, applied to every request including
        // failures. Bounds credential stuffing and bcrypt CPU cost (the per-key limit
        // below only protects already-authenticated callers).
        $client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        if ( $client_ip !== '' && ! self::check_rate_limit( 'ip_' . md5( $client_ip ), self::IP_RATE_LIMIT ) ) {
            self::log( 'rate_limit_ip_exceeded', [ 'ip' => $client_ip ] );
            return new WP_Error( 'mcp_rate_limit', 'Rate limit exceeded.', [ 'status' => 429 ] );
        }

        // Origin validation (MCP Streamable HTTP spec; defense-in-depth vs DNS rebinding).
        // Browser clients send Origin; CLI/agent clients omit it — reject only a present,
        // non-allowed Origin so legitimate non-browser clients are unaffected.
        $origin = $request->get_header( 'Origin' );
        if ( $origin && ! self::is_allowed_origin( $origin ) ) {
            self::log( 'origin_rejected', [ 'origin' => $origin ] );
            return new WP_Error( 'mcp_forbidden', 'Origin not allowed.', [ 'status' => 403 ] );
        }

        $auth_header = $request->get_header( 'Authorization' );
        if ( ! $auth_header || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
            self::log( 'auth_missing_header', [
                'ip'              => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ),
                'x_forwarded_for' => $request->get_header( 'X-Forwarded-For' ) ?: null,
            ] );
            self::send_www_authenticate();
            return new WP_Error( 'mcp_unauthorized', 'Missing or invalid Authorization header.', [ 'status' => 401 ] );
        }

        $token = substr( $auth_header, 7 );

        // ── OAuth access token branch ──
        // OAuth tokens carry a distinct prefix and are validated against the OAuth
        // store; everything downstream (current user, settings, rate limits) then
        // behaves exactly like an API key.
        if ( str_starts_with( $token, 'cmcp_at_' ) ) {
            if ( ! class_exists( 'Cowboy_MCP_OAuth' ) || ! Cowboy_MCP_OAuth::is_enabled() ) {
                self::send_www_authenticate();
                return new WP_Error( 'mcp_unauthorized', 'OAuth connector is not enabled.', [ 'status' => 401 ] );
            }
            $user_id = Cowboy_MCP_OAuth::validate_access_token( $token );
            if ( is_wp_error( $user_id ) ) {
                self::log( 'auth_invalid_oauth', [ 'reason' => $user_id->get_error_code() ] );
                self::send_www_authenticate();
                return new WP_Error( 'mcp_unauthorized', 'Invalid or expired access token.', [ 'status' => 401 ] );
            }
            self::$current_key_context = Cowboy_MCP_OAuth::$last_token_context;
            if ( ! self::check_rate_limit( self::$current_key_context['key_id'] ?? 'oauth', $settings['rate_limit'] ?? 120 ) ) {
                self::log( 'rate_limit_exceeded', self::$current_key_context );
                return new WP_Error( 'mcp_rate_limit', 'Rate limit exceeded.', [ 'status' => 429 ] );
            }
            wp_set_current_user( $user_id );
            return true;
        }

        $keys = get_option( self::OPTION_KEY, [] );

        // Check the token against every key with bcrypt. The stored `prefix` is derived
        // from the secret, so it must NOT be used as a fast-path gate — doing so leaks
        // key bytes via timing. Key counts are tiny, so checking all is cheap.
        foreach ( $keys as $id => &$record ) {
            if ( empty( $record['hash'] ) ) {
                continue;
            }
            if ( wp_check_password( $token, $record['hash'] ) ) {
                // Throttle last_used updates (at most once per minute).
                $now = time();
                if ( empty( $record['last_used'] ) || ( $now - $record['last_used'] ) >= 60 ) {
                    $record['last_used'] = $now;
                    update_option( self::OPTION_KEY, $keys );
                }

                // Populate key context for structured logging.
                self::$current_key_context = [
                    'key_id'     => $id,
                    'key_label'  => $record['label'] ?? '',
                    'key_prefix' => $record['prefix'] ?? '',
                    'scope'      => ( isset( $record['scope'] ) && is_array( $record['scope'] ) ) ? $record['scope'] : null,
                ];

                // Rate limit check.
                if ( ! self::check_rate_limit( $id, $settings['rate_limit'] ?? 120 ) ) {
                    self::log( 'rate_limit_exceeded', self::$current_key_context );
                    return new WP_Error( 'mcp_rate_limit', 'Rate limit exceeded.', [ 'status' => 429 ] );
                }

                // Set current user to admin so WP capabilities work.
                $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
                if ( empty( $admins ) ) {
                    return new WP_Error( 'mcp_no_admin', 'No administrator account found. MCP requires at least one admin user.', [ 'status' => 500 ] );
                }
                wp_set_current_user( $admins[0]->ID );

                return true;
            }
        }

        self::log( 'auth_invalid_key', [ 'token_hash' => substr( hash( 'sha256', $token ), 0, 12 ) ] );
        self::send_www_authenticate();
        return new WP_Error( 'mcp_unauthorized', 'Invalid API key.', [ 'status' => 401 ] );
    }

    /**
     * Emit the RFC 9728 WWW-Authenticate breadcrumb so MCP clients can discover
     * the OAuth metadata. No-op unless the OAuth connector is enabled.
     */
    private static function send_www_authenticate(): void {
        if ( ! class_exists( 'Cowboy_MCP_OAuth' ) || ! Cowboy_MCP_OAuth::is_enabled() || headers_sent() ) {
            return;
        }
        $prm = Cowboy_MCP_OAuth::issuer() . '/.well-known/oauth-protected-resource';
        header( 'WWW-Authenticate: Bearer resource_metadata="' . esc_url_raw( $prm ) . '"' );
    }

    /**
     * Whether a request Origin is allowed. Defaults to the site's own host(s); extend
     * via the `cowboy_mcp_allowed_origins` filter (array of allowed hostnames).
     */
    private static function is_allowed_origin( string $origin ): bool {
        $origin_host = wp_parse_url( $origin, PHP_URL_HOST );
        if ( ! $origin_host ) {
            return false;
        }
        $allowed = array_filter( [
            wp_parse_url( home_url(), PHP_URL_HOST ),
            wp_parse_url( site_url(), PHP_URL_HOST ),
        ] );
        /**
         * Filter the allowed Origin hostnames for MCP requests.
         *
         * @param string[] $allowed Allowed hostnames.
         */
        $allowed = apply_filters( 'cowboy_mcp_allowed_origins', $allowed );
        return in_array( strtolower( $origin_host ), array_map( 'strtolower', (array) $allowed ), true );
    }

    /* ── Logging ───────────────────────────────────────────── */

    /**
     * Write a structured JSON log line for auth events when log_requests is enabled.
     * Public so Cowboy_MCP_Tools can delegate to a single logging implementation.
     */
    public static function log( string $event, array $context = [] ): void {
        // Always write to the DB audit log.
        Cowboy_MCP_Audit_Log::log( $event, $context );

        // Also write to error_log when log_requests is enabled.
        $settings = Cowboy_MCP_Tools::get_settings();
        if ( ! empty( $settings['log_requests'] ) ) {
            $entry = array_merge(
                [
                    'event'     => $event,
                    'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                ],
                $context
            );
            error_log( '[COWBOY_MCP] ' . wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /* ── Rate limiting (transient-based) ───────────────────── */

    /**
     * Note: Transient-based rate limiting is inherently approximate. Under high concurrency,
     * the non-atomic read-check-write cycle means two simultaneous requests could both pass
     * the limit check. This is acceptable for the plugin's use case (AI agent rate limiting)
     * but should not be relied upon as a strict security boundary.
     * Public so the Abilities bridge can limit foreign-transport callers per user.
     */
    public static function check_rate_limit( string $key_id, int $per_minute ): bool {
        $transient  = 'cowboy_mcp_rl_' . $key_id;
        $window     = get_transient( $transient );

        if ( false === $window ) {
            set_transient( $transient, [ 'count' => 1, 'start' => time() ], self::RATE_LIMIT_WINDOW );
            return true;
        }

        if ( time() - $window['start'] > self::RATE_LIMIT_WINDOW ) {
            set_transient( $transient, [ 'count' => 1, 'start' => time() ], self::RATE_LIMIT_WINDOW );
            return true;
        }

        if ( $window['count'] >= $per_minute ) {
            return false;
        }

        $window['count']++;
        set_transient( $transient, $window, self::RATE_LIMIT_WINDOW );
        return true;
    }
}
