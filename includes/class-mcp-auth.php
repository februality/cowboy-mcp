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

    /**
     * Second header that carries the same credential as `Authorization: Bearer …`, for hosts
     * that strip Authorization before PHP and for sites behind an HTTP Basic login (whose
     * Authorization header is the site password). Read only when no Bearer Authorization
     * header arrived, so existing connections behave exactly as before.
     */
    const KEY_HEADER = 'X-Cowboy-MCP-Key';

    /**
     * Side store for the MCP clientInfo each credential last presented (autoload no):
     * `key:<key id>` / `oauth:<client_id>` => {name, title?, version?, at}. Kept apart from the
     * credential records so the endpoint never rewrites `cowboy_mcp_api_keys` / `cowboy_mcp_oauth_clients`.
     */
    const CLIENT_INFO_OPTION = 'cowboy_mcp_client_info';

    /** Most entries kept in CLIENT_INFO_OPTION; the oldest `at` is dropped first. */
    private const CLIENT_INFO_MAX = 200;

    /** Rate limit window duration in seconds. */
    private const RATE_LIMIT_WINDOW = 60;

    /** Per-IP request ceiling per window (applies to all requests, incl. auth failures). */
    private const IP_RATE_LIMIT = 30; // failed auth attempts per IP per minute

    /** Populated on successful validation so log entries can identify the key. */
    public static array $current_key_context = [];

    /**
     * Memoized result of validate_request() for the current HTTP request.
     * WordPress invokes a route's permission_callback several times per request
     * (building the Allow header, matching the handler, dispatching), so without
     * this the rate-limit counters were incremented several times for one request
     * — a client's real request budget was a fraction of the configured limit.
     * A PHP static is per-request (a fresh script execution each time under
     * php-fpm/mod_php), so it never leaks between requests. Sentinel: not-yet-run.
     */
    private static $request_decision = "\0unset";

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
            'lookup'   => self::key_lookup( $raw_key ),
        ];
        // Who made it: the key runs as this user, and only while they are an administrator
        // (a super admin on multisite).
        if ( get_current_user_id() > 0 ) {
            $record['created_by'] = get_current_user_id();
        }

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
     * O(1) lookup value for an API key: sha256 of the 256-bit random secret, the same
     * scheme as OAuth tokens. It identifies a presented key without running bcrypt
     * over every stored key, so a valid key is recognised before the per-IP failure
     * limit and an invalid one costs no hashing. The bcrypt `hash` stays the record's
     * proof for keys made before the lookup existed (it is added on their first use).
     */
    private static function key_lookup( string $raw_key ): string {
        return hash( 'sha256', 'cowboy_mcp_key|' . $raw_key );
    }

    /**
     * Merge $changes into one key record as it is stored NOW (not the copy read at the
     * start of this request), so a revoke or scope change made meanwhile is never
     * written back over. No-op when the key no longer exists.
     */
    private static function update_key_record( string $id, array $changes ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the request's option cache
        $raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_KEY ) );
        $keys = is_string( $raw ) ? maybe_unserialize( $raw ) : [];
        if ( ! is_array( $keys ) || ! isset( $keys[ $id ] ) || ! is_array( $keys[ $id ] ) ) {
            return;
        }
        $keys[ $id ] = array_merge( $keys[ $id ], $changes );
        wp_cache_delete( self::OPTION_KEY, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        update_option( self::OPTION_KEY, $keys );
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
        self::forget_client_info( 'key', $id );
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
        if ( 'drafts' === $mode ) {
            return [ 'mode' => 'drafts', 'allowed_tools' => [] ];
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
                'client_info' => self::client_info_for( 'key', (string) $k['id'] ),
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
        // Evaluate (and account for rate limits) exactly once per HTTP request;
        // return the cached decision on the extra permission_callback invocations.
        if ( self::$request_decision !== "\0unset" ) {
            return self::$request_decision;
        }
        self::$request_decision = self::evaluate_request( $request );
        return self::$request_decision;
    }

    /**
     * The real validation + rate-limit accounting. Runs once per request; wrapped
     * by validate_request() so WordPress's repeated permission_callback calls do
     * not multiply the rate-limit counters.
     *
     * @return bool|WP_Error
     */
    private static function evaluate_request( WP_REST_Request $request ) {
        $settings = Cowboy_MCP_Tools::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return new WP_Error( 'mcp_disabled', 'MCP server is disabled.', [ 'status' => 503 ] );
        }

        // Per-IP throttle on FAILED authentication attempts. A valid credential is
        // recognised first (OAuth tokens and API keys both by an O(1) hash lookup), so a
        // shared egress IP or a proxy that hides client addresses can never lock a real
        // credential out; only callers that fail count toward it (note_auth_failure()),
        // and only they are refused once it is spent. It bounds credential stuffing and
        // the bcrypt cost of keys made before the lookup existed.
        $client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        // A CORS preflight carries no credentials by design: never count it as a failure.
        $preflight = 'OPTIONS' === strtoupper( (string) $request->get_method() );

        // Origin validation (MCP Streamable HTTP spec; defense-in-depth vs DNS rebinding).
        // Browser clients send Origin; CLI/agent clients omit it — reject only a present,
        // non-allowed Origin so legitimate non-browser clients are unaffected.
        $origin = $request->get_header( 'Origin' );
        if ( $origin && ! self::is_allowed_origin( $origin ) ) {
            if ( ! self::ip_limited( $client_ip ) ) {
                self::log( 'origin_rejected', [ 'origin' => $origin ] );
            }
            if ( ! $preflight ) {
                self::note_auth_failure( $client_ip );
            }
            return new WP_Error( 'mcp_forbidden', 'Origin not allowed.', [ 'status' => 403 ] );
        }

        $token = self::request_token( $request );
        if ( null === $token ) {
            if ( $preflight ) {
                return new WP_Error( 'mcp_unauthorized', 'Missing or invalid Authorization header.', [ 'status' => 401 ] );
            }
            if ( self::ip_limited( $client_ip ) ) {
                return self::ip_limit_error( $client_ip );
            }
            self::log( 'auth_missing_header', [
                'ip'              => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ),
                'x_forwarded_for' => $request->get_header( 'X-Forwarded-For' ) ?: null,
            ] );
            self::note_auth_failure( $client_ip );
            self::send_www_authenticate();
            return new WP_Error( 'mcp_unauthorized', 'Missing or invalid Authorization header.', [ 'status' => 401 ] );
        }


        // ── OAuth access token branch ──
        // OAuth tokens carry a distinct prefix and are validated against the OAuth
        // store; everything downstream (current user, settings, rate limits) then
        // behaves exactly like an API key.
        if ( str_starts_with( $token, 'cmcp_at_' ) ) {
            $user_id = ( class_exists( 'Cowboy_MCP_OAuth' ) && Cowboy_MCP_OAuth::is_enabled() )
                ? Cowboy_MCP_OAuth::validate_access_token( $token )
                : new WP_Error( 'oauth_disabled', 'OAuth connector is not enabled.' );
            if ( is_wp_error( $user_id ) ) {
                if ( self::ip_limited( $client_ip ) ) {
                    return self::ip_limit_error( $client_ip );
                }
                self::log( 'auth_invalid_oauth', [ 'reason' => $user_id->get_error_code() ] );
                self::note_auth_failure( $client_ip );
                self::send_www_authenticate();
                return new WP_Error( 'mcp_unauthorized', 'oauth_disabled' === $user_id->get_error_code() ? 'OAuth connector is not enabled.' : 'Invalid or expired access token.', [ 'status' => 401 ] );
            }
            self::$current_key_context = Cowboy_MCP_OAuth::$last_token_context;
            if ( ! self::check_rate_limit( self::$current_key_context['key_id'] ?? 'oauth', $settings['rate_limit'] ?? 120 ) ) {
                self::log( 'rate_limit_exceeded', self::$current_key_context );
                return new WP_Error( 'mcp_rate_limit', 'Rate limit exceeded.', [ 'status' => 429 ] );
            }
            wp_set_current_user( $user_id );
            return true;
        }

        $keys   = get_option( self::OPTION_KEY, [] );
        $lookup = self::key_lookup( $token );
        $match  = null;
        foreach ( (array) $keys as $id => $record ) {
            if ( is_array( $record ) && ! empty( $record['lookup'] ) && hash_equals( (string) $record['lookup'], $lookup ) ) {
                $match = (string) $id;
                break;
            }
        }
        if ( null === $match ) {
            // Only keys made before the lookup existed still need bcrypt. The stored
            // `prefix` is derived from the secret, so it must NOT be used as a fast-path
            // gate — doing so leaks key bytes via timing.
            if ( self::ip_limited( $client_ip ) ) {
                return self::ip_limit_error( $client_ip );
            }
            foreach ( (array) $keys as $id => $record ) {
                if ( is_array( $record ) && empty( $record['lookup'] ) && ! empty( $record['hash'] ) && wp_check_password( $token, $record['hash'] ) ) {
                    $match = (string) $id;
                    self::update_key_record( $match, [ 'lookup' => $lookup ] );
                    break;
                }
            }
        }

        if ( null !== $match ) {
            $record = $keys[ $match ];
            // Throttle last_used updates (at most once per minute).
            $now = time();
            if ( empty( $record['last_used'] ) || ( $now - $record['last_used'] ) >= 60 ) {
                self::update_key_record( $match, [ 'last_used' => $now ] );
            }

            // Populate key context for structured logging.
            self::$current_key_context = [
                'key_id'     => $match,
                'key_label'  => $record['label'] ?? '',
                'key_prefix' => $record['prefix'] ?? '',
                'scope'      => ( isset( $record['scope'] ) && is_array( $record['scope'] ) ) ? $record['scope'] : null,
            ];

            // Rate limit check.
            if ( ! self::check_rate_limit( $match, $settings['rate_limit'] ?? 120 ) ) {
                self::log( 'rate_limit_exceeded', self::$current_key_context );
                return new WP_Error( 'mcp_rate_limit', 'Rate limit exceeded.', [ 'status' => 429 ] );
            }

            // Multisite: wp_cli, the file tools and checkpoints reach the whole network,
            // so a key must have been made by a super admin, and it runs as that user.
            // Keys from before created_by existed (or from WP-CLI) fail closed there.
            if ( is_multisite() ) {
                $creator = (int) ( $record['created_by'] ?? 0 );
                if ( $creator <= 0 || ! Cowboy_MCP_Security::user_is_plugin_admin( $creator ) ) {
                    self::log( 'auth_key_not_network_admin', self::$current_key_context );
                    return new WP_Error( 'mcp_unauthorized', 'On multisite, API keys must be created by a network administrator. Create a new key in Settings > Cowboy MCP.', [ 'status' => 401 ] );
                }
                wp_set_current_user( $creator );
                return true;
            }

            // Single site: a key runs as the admin who created it (recorded since 1.7.5), so its
            // calls are attributed to that person and stop working when they lose admin rights,
            // as OAuth tokens do.
            $creator = (int) ( $record['created_by'] ?? 0 );
            if ( $creator > 0 ) {
                if ( ! Cowboy_MCP_Security::user_is_plugin_admin( $creator ) ) {
                    self::log( 'auth_key_creator_not_admin', self::$current_key_context );
                    return new WP_Error( 'mcp_unauthorized', 'The administrator who created this API key no longer has administrator rights. Create a new key in Settings > Cowboy MCP.', [ 'status' => 401 ] );
                }
                wp_set_current_user( $creator );
                return true;
            }

            // Keys without a recorded creator (made before 1.7.5, or with WP-CLI) run as the first administrator.
            $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
            if ( empty( $admins ) ) {
                return new WP_Error( 'mcp_no_admin', 'No administrator account found. MCP requires at least one admin user.', [ 'status' => 500 ] );
            }
            wp_set_current_user( $admins[0]->ID );

            return true;
        }

        self::log( 'auth_invalid_key', [ 'token_hash' => substr( hash( 'sha256', $token ), 0, 12 ) ] );
        self::note_auth_failure( $client_ip );
        self::send_www_authenticate();
        return new WP_Error( 'mcp_unauthorized', 'Invalid API key.', [ 'status' => 401 ] );
    }

    /** This request's sanitized MCP clientInfo (set by the transport on initialize). */
    public static array $last_client_info = [];

    /**
     * Remember which client is using the authenticated credential (OAuth client for cmcp_at_
     * tokens, the API key otherwise). Writes ONLY CLIENT_INFO_OPTION — never a credential
     * record — and at most once a minute per credential. Never fails the request.
     */
    public static function record_client_info( array $info ): void {
        self::$last_client_info = $info;
        $ctx = self::$current_key_context;
        if ( empty( $ctx['key_id'] ) ) {
            return;
        }
        if ( ( $ctx['key_prefix'] ?? '' ) === 'cmcp_at' ) {
            if ( class_exists( 'Cowboy_MCP_OAuth' ) ) {
                Cowboy_MCP_OAuth::stamp_client_info( substr( (string) $ctx['key_id'], 6 ), $info );
            }
            return;
        }
        $keys = get_option( self::OPTION_KEY, [] );
        if ( ! isset( $keys[ $ctx['key_id'] ] ) ) {
            return;
        }
        self::store_client_info( 'key', (string) $ctx['key_id'], $info );
    }

    /** The clientInfo last seen for a credential (`key` + key id, or `oauth` + client_id), or null. */
    public static function client_info_for( string $kind, string $id ): ?array {
        $all   = get_option( self::CLIENT_INFO_OPTION, [] );
        $entry = is_array( $all ) ? ( $all[ $kind . ':' . $id ] ?? null ) : null;
        return is_array( $entry ) ? $entry : null;
    }

    /**
     * Save a credential's clientInfo to the side option. Throttled unconditionally: an entry
     * stamped less than 60 s ago is left alone whatever the new name/version. Capped at
     * CLIENT_INFO_MAX entries (oldest `at` dropped first).
     */
    public static function store_client_info( string $kind, string $id, array $info ): void {
        // Read fresh: another request (e.g. the Doctor's loopback) may have written since this request first read it.
        wp_cache_delete( self::CLIENT_INFO_OPTION, 'options' );
        $all = get_option( self::CLIENT_INFO_OPTION, [] );
        $all = is_array( $all ) ? $all : [];
        $k   = $kind . ':' . $id;
        if ( isset( $all[ $k ] ) && ( time() - (int) ( $all[ $k ]['at'] ?? 0 ) ) < 60 ) {
            return;
        }
        unset( $all[ $k ] );
        $all[ $k ] = $info + [ 'at' => time() ];
        if ( count( $all ) > self::CLIENT_INFO_MAX ) {
            uasort( $all, static fn( $a, $b ) => ( (int) ( $a['at'] ?? 0 ) ) <=> ( (int) ( $b['at'] ?? 0 ) ) );
            $all = array_slice( $all, count( $all ) - self::CLIENT_INFO_MAX, null, true );
        }
        update_option( self::CLIENT_INFO_OPTION, $all, false );
    }

    /** Drop a credential's clientInfo entry (on revoke / client removal). */
    public static function forget_client_info( string $kind, string $id ): void {
        // Read fresh: another request (e.g. the Doctor's loopback) may have written since this request first read it.
        wp_cache_delete( self::CLIENT_INFO_OPTION, 'options' );
        $all = get_option( self::CLIENT_INFO_OPTION, [] );
        if ( ! is_array( $all ) || ! isset( $all[ $kind . ':' . $id ] ) ) {
            return;
        }
        unset( $all[ $kind . ':' . $id ] );
        update_option( self::CLIENT_INFO_OPTION, $all, false );
    }

    /**
     * The credential a request carries, or null.
     *
     * Order: a Bearer `Authorization` header as WordPress parsed it; the same header from
     * the server's own list when WordPress saw none (Apache keeps it out of $_SERVER on
     * some setups); then KEY_HEADER, as `<key>` or `Bearer <key>`. A Bearer Authorization
     * header always wins, so KEY_HEADER can never override a credential that arrived there.
     */
    public static function request_token( WP_REST_Request $request ): ?string {
        $auth = trim( (string) $request->get_header( 'Authorization' ) );
        if ( '' === $auth ) {
            $auth = self::server_authorization_header();
        }
        if ( preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
            return $m[1];
        }
        $alt = trim( (string) $request->get_header( self::KEY_HEADER ) );
        if ( preg_match( '/^(?:Bearer\s+)?(\S+)$/i', $alt, $m ) ) {
            return $m[1];
        }
        return null;
    }

    /** Authorization as the web server reports it (getallheaders / apache_request_headers), or ''. */
    private static function server_authorization_header(): string {
        foreach ( [ 'getallheaders', 'apache_request_headers' ] as $fn ) {
            if ( ! function_exists( $fn ) ) {
                continue;
            }
            $all = $fn();
            if ( ! is_array( $all ) ) {
                continue;
            }
            foreach ( $all as $name => $value ) {
                if ( is_string( $name ) && 0 === strcasecmp( $name, 'Authorization' ) && is_string( $value ) ) {
                    return trim( $value );
                }
            }
        }
        return '';
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
    public static function log( string $event, array $context = [] ): ?int {
        // Always write to the DB audit log.
        $row_id = Cowboy_MCP_Audit_Log::log( $event, $context );

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

        return $row_id;
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

    /**
     * Whether a rate-limit window is already exhausted — a read-only peek that
     * does NOT count the current request. Pairs with note_auth_failure().
     */
    public static function rate_limit_exhausted( string $key_id, int $per_minute ): bool {
        $window = get_transient( 'cowboy_mcp_rl_' . $key_id );
        if ( false === $window || time() - $window['start'] > self::RATE_LIMIT_WINDOW ) {
            return false;
        }
        return $window['count'] >= $per_minute;
    }

    /**
     * Count one failed authentication attempt against the caller's IP. Successful
     * requests never reach this, so a valid credential cannot exhaust the IP budget.
     */
    private static function note_auth_failure( string $client_ip ): void {
        if ( $client_ip === '' ) {
            return;
        }
        self::check_rate_limit( 'ip_' . md5( $client_ip ), self::IP_RATE_LIMIT );
    }

    /** Whether this IP has spent its failed-attempt budget for the current window. */
    private static function ip_limited( string $client_ip ): bool {
        return $client_ip !== '' && self::rate_limit_exhausted( 'ip_' . md5( $client_ip ), self::IP_RATE_LIMIT );
    }

    /** The 429 for a spent IP budget; audit-logged once per IP per window, not per request. */
    private static function ip_limit_error( string $client_ip ): WP_Error {
        $flag = 'cowboy_mcp_rl_logged_' . md5( $client_ip );
        if ( false === get_transient( $flag ) ) {
            set_transient( $flag, 1, self::RATE_LIMIT_WINDOW );
            self::log( 'rate_limit_ip_exceeded', [ 'ip' => $client_ip ] );
        }
        return new WP_Error( 'mcp_rate_limit', 'Rate limit exceeded.', [ 'status' => 429 ] );
    }
}
