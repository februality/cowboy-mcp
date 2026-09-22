<?php
/**
 * Cowboy MCP – OAuth 2.1 Authorization Server
 *
 * Self-contained OAuth 2.1 (authorization-code + PKCE) so Claude Desktop / web
 * custom connectors can connect this site with no terminal. OFF by default
 * (cowboy_mcp_settings['oauth_enabled']). Issued access tokens are validated by
 * Cowboy_MCP_Auth and act as the approving administrator.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_OAuth {

    const TOKENS_OPTION  = 'cowboy_mcp_oauth_tokens';
    const REFRESH_OPTION = 'cowboy_mcp_oauth_refresh';
    const CLIENTS_OPTION = 'cowboy_mcp_oauth_clients';
    const CODE_PREFIX    = 'cowboy_mcp_oauth_code_';
    const ACCESS_TTL     = 3600;
    const REFRESH_TTL    = 2592000;
    const CODE_TTL       = 60;
    const MAX_CLIENTS    = 100;
    // Registration is unauthenticated by design (RFC 7591), so everything it stores is bounded.
    const MAX_CLIENT_NAME_LEN  = 100;
    const MAX_REDIRECT_URIS    = 10;  // VS Code registers six; leave headroom
    const MAX_REDIRECT_URI_LEN = 512;
    const REGISTER_RATE_LIMIT  = 20;  // per IP per minute; its own bucket, never the auth-failure one
    /**
     * Safety lock: registration only answers while an administrator has "New
     * connections" switched on (Connection tab button; auto-off after 30 minutes;
     * also switched on when the connector itself is enabled). Everything an existing
     * connection uses (authorize, token, refresh, the MCP endpoint, recovery via
     * signed ids) ignores the lock.
     */
    const REGISTRATION_WINDOW    = 1800;
    const REGISTRATION_TRANSIENT = 'cowboy_mcp_oauth_reg_open';
    /**
     * Hosts an OAuth client may send the browser back to. Registration is open to
     * anyone, so without this an attacker registers "ChatGPT" with their own
     * redirect_uri and phishes an administrator's Approve click into admin tokens.
     * Subdomains match; loopback hosts may use plain http (CLI clients).
     * Administrators extend the list in Settings (oauth_extra_redirect_hosts) or
     * via the cowboy_mcp_oauth_redirect_hosts filter; oauth_redirect_allowlist=false
     * restores the old any-host behaviour.
     */
    const DEFAULT_REDIRECT_HOSTS = [ 'chatgpt.com', 'openai.com', 'claude.ai', 'claude.com', 'anthropic.com', 'vscode.dev', 'cursor.com', 'perplexity.ai', 'n8n.cloud' ];
    const LOOPBACK_HOSTS         = [ 'localhost', '127.0.0.1', '::1' ];
    const SIGNED_PREFIX = 'cmcp_client_s1_';
    const SIGNED_MAX_ID = 255; // whole id; longer registrations keep the legacy random id
    const SIGNED_SIG_LEN = 16; // bytes of HMAC-SHA256 kept (128-bit tag)

    /** Populated by validate_access_token() so Cowboy_MCP_Auth can log the connection. */
    public static array $last_token_context = [];

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'handle_root_requests' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /* ── State ─────────────────────────────────────────────── */

    public static function is_enabled(): bool {
        if ( ! class_exists( 'Cowboy_MCP_Tools' ) ) {
            return false;
        }
        $s = Cowboy_MCP_Tools::get_settings();
        return ! empty( $s['enabled'] ) && ! empty( $s['oauth_enabled'] );
    }

    public static function issuer(): string {
        $home   = home_url();
        $scheme = wp_parse_url( $home, PHP_URL_SCHEME ) ?: 'https';
        $host   = wp_parse_url( $home, PHP_URL_HOST ) ?: '';
        $port   = wp_parse_url( $home, PHP_URL_PORT );
        $issuer = $scheme . '://' . $host;
        if ( $port ) {
            $issuer .= ':' . $port;
        }
        return $issuer;
    }

    public static function resource_url(): string {
        return rest_url( 'cowboy-mcp/v1/endpoint' );
    }

    /**
     * Best-effort check that the desktop connector (which connects from
     * Anthropic's cloud) can actually reach this site: public HTTPS host.
     */
    public static function site_is_publicly_reachable(): bool {
        $home = home_url();
        if ( wp_parse_url( $home, PHP_URL_SCHEME ) !== 'https' ) {
            return false;
        }
        $host = (string) wp_parse_url( $home, PHP_URL_HOST );
        if ( $host === '' || $host === 'localhost' ) {
            return false;
        }
        if ( preg_match( '/\.(local|test|localhost|example|invalid)$/i', $host ) ) {
            return false;
        }
        if ( filter_var( $host, FILTER_VALIDATE_IP )
            && filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return false;
        }
        return true;
    }

    /* ── Registration window (safety lock) ─────────────────── */

    /** Open (or extend) the registration window. Admin-context callers only. Returns the closing timestamp. */
    public static function open_registration_window(): int {
        $until = time() + self::REGISTRATION_WINDOW;
        set_transient( self::REGISTRATION_TRANSIENT, $until, self::REGISTRATION_WINDOW );
        return $until;
    }

    public static function close_registration_window(): void {
        delete_transient( self::REGISTRATION_TRANSIENT );
    }

    public static function registration_seconds_left(): int {
        $until = (int) get_transient( self::REGISTRATION_TRANSIENT );
        return max( 0, $until - time() );
    }

    public static function registration_open(): bool {
        /**
         * Filters whether Dynamic Client Registration currently answers. Return true
         * to keep it always open (automated provisioning); the default is the
         * 30-minute window an administrator switches on from the Connection tab.
         *
         * @param bool $open
         */
        return (bool) apply_filters( 'cowboy_mcp_oauth_registration_open', self::registration_seconds_left() > 0 );
    }

    /* ── Redirect-host allowlist ───────────────────────────── */

    public static function redirect_allowlist_enabled(): bool {
        if ( ! class_exists( 'Cowboy_MCP_Tools' ) ) {
            return true;
        }
        $s = Cowboy_MCP_Tools::get_settings();
        return ! isset( $s['oauth_redirect_allowlist'] ) || ! empty( $s['oauth_redirect_allowlist'] );
    }

    /** Administrator-added hosts (Settings), normalised. */
    public static function extra_redirect_hosts(): array {
        $s     = class_exists( 'Cowboy_MCP_Tools' ) ? Cowboy_MCP_Tools::get_settings() : [];
        $hosts = $s['oauth_extra_redirect_hosts'] ?? [];
        return self::sanitize_host_list( is_array( $hosts ) ? $hosts : [] );
    }

    /** Lower-case, strip scheme/path/port, keep only syntactically valid host names. */
    public static function sanitize_host_list( array $hosts ): array {
        $out = [];
        foreach ( $hosts as $h ) {
            $h = strtolower( trim( (string) $h ) );
            if ( $h === '' ) {
                continue;
            }
            if ( str_contains( $h, '://' ) ) {
                $h = (string) wp_parse_url( $h, PHP_URL_HOST );
            }
            $h = preg_replace( '~[/:].*$~', '', $h );
            if ( preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $h ) && ! in_array( $h, $out, true ) ) {
                $out[] = $h;
            }
        }
        return array_slice( $out, 0, 50 );
    }

    /**
     * Whether a redirect_uri may receive an authorization code from this site.
     * Only ever loosened by the site's own administrator — never by a registrant.
     */
    public static function redirect_uri_allowed( string $uri ): bool {
        $scheme = strtolower( (string) wp_parse_url( $uri, PHP_URL_SCHEME ) );
        $host   = strtolower( trim( (string) wp_parse_url( $uri, PHP_URL_HOST ), '[]' ) );
        if ( $host === '' || ! in_array( $scheme, [ 'https', 'http' ], true ) ) {
            return false;
        }
        if ( ! self::redirect_allowlist_enabled() ) {
            return true;
        }
        if ( in_array( $host, self::LOOPBACK_HOSTS, true ) ) {
            return true; // CLI clients listen on a local port over plain http
        }
        $extra = self::extra_redirect_hosts();
        /**
         * Filters the redirect hosts accepted for OAuth clients (subdomains match).
         *
         * @param string[] $hosts Default vendor hosts plus administrator-added hosts.
         */
        $allowed = apply_filters( 'cowboy_mcp_oauth_redirect_hosts', array_merge( self::DEFAULT_REDIRECT_HOSTS, $extra ) );
        foreach ( (array) $allowed as $a ) {
            $a = strtolower( trim( (string) $a ) );
            if ( $a === '' ) {
                continue;
            }
            if ( $host === $a || str_ends_with( $host, '.' . $a ) ) {
                // Admin-added hosts may be internal http; vendor defaults must be https.
                return $scheme === 'https' || in_array( $a, $extra, true );
            }
        }
        return false;
    }

    /* ── PKCE helpers ──────────────────────────────────────── */

    public static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    public static function base64url_decode( string $data ): ?string {
        $b64 = strtr( $data, '-_', '+/' );
        $b64 = str_pad( $b64, strlen( $b64 ) + ( 4 - strlen( $b64 ) % 4 ) % 4, '=' );
        $out = base64_decode( $b64, true );
        return ( false === $out ) ? null : $out;
    }

    public static function verify_pkce( string $verifier, string $challenge, string $method ): bool {
        if ( $method !== 'S256' || $verifier === '' || $challenge === '' ) {
            return false;
        }
        $computed = self::base64url_encode( hash( 'sha256', $verifier, true ) );
        return hash_equals( $challenge, $computed );
    }

    /* ── Token model ───────────────────────────────────────── */

    private static function random_id(): string {
        return bin2hex( random_bytes( 8 ) );
    }

    private static function random_secret(): string {
        return bin2hex( random_bytes( 32 ) );
    }

    /* ── Signed client ids ─────────────────────────────────── */

    /**
     * Key for self-describing client ids. Derived from the wp-config.php salts
     * (which a database overwrite — staging sync, backup restore — does not touch)
     * and bound to this site's issuer so an id minted on production does not
     * verify on a staging copy that shares the same salts.
     */
    private static function client_signing_key(): string {
        // Blog id too: subdirectory-multisite subsites share both salts and issuer().
        return hash_hmac( 'sha256', 'cowboy-mcp-oauth-client-v1|' . self::issuer() . '|' . get_current_blog_id(), wp_salt( 'auth' ), true );
    }

    private static function client_signature( string $body ): string {
        return self::base64url_encode( substr( hash_hmac( 'sha256', $body, self::client_signing_key(), true ), 0, self::SIGNED_SIG_LEN ) );
    }

    /** Returns null when the registration is too large to travel inside a 255-char client_id. */
    private static function mint_signed_client_id( string $name, array $redirect_uris, int $created ): ?string {
        // 'j' keeps ids unique: identical metadata in the same second must not share a row (and its tool_scope).
        $payload = wp_json_encode( [ 'n' => $name, 'r' => array_values( $redirect_uris ), 't' => $created, 'j' => bin2hex( random_bytes( 8 ) ) ], JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $payload ) ) {
            return null;
        }
        $body = self::base64url_encode( $payload );
        $id   = self::SIGNED_PREFIX . $body . '.' . self::client_signature( $body );
        // Stay inside the most conservative client-side storage assumption (255 chars).
        return ( strlen( $id ) <= self::SIGNED_MAX_ID ) ? $id : null;
    }

    /**
     * Verify a self-describing client id and return the client record it encodes,
     * or null. Never touches the database.
     */
    public static function verify_signed_client_id( string $client_id ): ?array {
        if ( strlen( $client_id ) > self::SIGNED_MAX_ID || ! str_starts_with( $client_id, self::SIGNED_PREFIX ) ) {
            return null;
        }
        $parts = explode( '.', substr( $client_id, strlen( self::SIGNED_PREFIX ) ) );
        if ( count( $parts ) !== 2 || $parts[0] === '' || $parts[1] === '' ) {
            return null;
        }
        [ $body, $sig ] = $parts;
        if ( ! hash_equals( self::client_signature( $body ), $sig ) ) {
            return null;
        }
        $json = self::base64url_decode( $body );
        $data = is_string( $json ) ? json_decode( $json, true ) : null;
        if ( ! is_array( $data ) || ! is_string( $data['n'] ?? null ) || ! is_array( $data['r'] ?? null ) || empty( $data['r'] ) ) {
            return null;
        }
        foreach ( $data['r'] as $uri ) {
            if ( ! is_string( $uri ) || $uri === '' || esc_url_raw( $uri, [ 'https', 'http' ] ) !== $uri ) {
                return null;
            }
        }
        return [
            'client_id'                  => $client_id,
            'client_name'                => sanitize_text_field( $data['n'] ),
            'redirect_uris'              => array_values( $data['r'] ),
            'created'                    => (int) ( $data['t'] ?? 0 ),
            'last_used'                  => null,
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * Look a client up: stored row first, then a valid signature. With $rehydrate
     * the row is re-created from the signature. SECURITY: call with $rehydrate only
     * once a logged-in administrator is present (handle_authorize() does) — signed
     * ids never expire, so an unauthenticated caller must not be able to make this write.
     */
    public static function resolve_client( string $client_id, bool $rehydrate = false ): ?array {
        if ( $client_id === '' ) {
            return null;
        }
        $clients = get_option( self::CLIENTS_OPTION, [] );
        if ( ! empty( $clients[ $client_id ] ) ) {
            return $clients[ $client_id ];
        }
        $client = self::verify_signed_client_id( $client_id );
        if ( null === $client || ! $rehydrate ) {
            return $client;
        }
        // The tool scope lived on the lost row. Any token that outlived it (a refresh
        // token survives row eviction) would come back UNSCOPED = full access, so a
        // re-created client always starts with no tokens: the only way to a token is
        // the consent screen, which sets the scope again. revoke_connection() finds no
        // row here and just clears the client's access + refresh tokens.
        self::revoke_connection( $client_id );
        $clients = get_option( self::CLIENTS_OPTION, [] );
        if ( count( $clients ) >= self::MAX_CLIENTS ) {
            $clients = self::prune_unused_clients( $clients );
        }
        $client['rehydrated']  = time();
        $clients[ $client_id ] = $client;
        update_option( self::CLIENTS_OPTION, $clients, false );
        Cowboy_MCP_Auth::log( 'oauth_client_rehydrated', [ 'client_name' => $client['client_name'] ] );
        return $client;
    }

    public static function client_is_known( string $client_id ): bool {
        return null !== self::resolve_client( $client_id );
    }

    /* ── Tokens ────────────────────────────────────────────── */

    private static function revoke_token_record( string $access_id ): void {
        $tokens = get_option( self::TOKENS_OPTION, [] );
        if ( isset( $tokens[ $access_id ] ) ) {
            unset( $tokens[ $access_id ] );
            update_option( self::TOKENS_OPTION, $tokens, false );
        }
    }

    public static function prune_expired(): void {
        $now = time();

        $tokens  = get_option( self::TOKENS_OPTION, [] );
        $changed = false;
        foreach ( $tokens as $id => $t ) {
            if ( (int) ( $t['expires'] ?? 0 ) < $now ) {
                unset( $tokens[ $id ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( self::TOKENS_OPTION, $tokens, false );
        }

        $refresh = get_option( self::REFRESH_OPTION, [] );
        $changed = false;
        foreach ( $refresh as $id => $r ) {
            if ( (int) ( $r['expires'] ?? 0 ) < $now ) {
                unset( $refresh[ $id ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( self::REFRESH_OPTION, $refresh, false );
        }
    }

    public static function issue_authorization_code( array $ctx ): string {
        $code = bin2hex( random_bytes( 32 ) );
        set_transient( self::CODE_PREFIX . $code, $ctx, self::CODE_TTL );
        return $code;
    }

    /**
     * Issue an access+refresh token pair bound to a user, client, and audience.
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:?string}
     */
    public static function issue_tokens( int $user_id, string $client_id, string $aud, ?string $scope = 'mcp' ): array {
        self::prune_expired();
        $now = time();

        $at_id     = self::random_id();
        $at_secret = self::random_secret();
        $rt_id     = self::random_id();
        $rt_secret = self::random_secret();

        $tokens = get_option( self::TOKENS_OPTION, [] );
        $tokens[ $at_id ] = [
            'id'          => $at_id,
            'secret_hash' => hash( 'sha256', $at_secret ),
            'user_id'     => $user_id,
            'client_id'   => $client_id,
            'aud'         => $aud,
            'scope'       => $scope,
            'created'     => $now,
            'expires'     => $now + self::ACCESS_TTL,
            'last_used'   => null,
            'refresh_id'  => $rt_id,
        ];
        update_option( self::TOKENS_OPTION, $tokens, false );

        $refresh = get_option( self::REFRESH_OPTION, [] );
        $refresh[ $rt_id ] = [
            'id'          => $rt_id,
            'secret_hash' => hash( 'sha256', $rt_secret ),
            'user_id'     => $user_id,
            'client_id'   => $client_id,
            'aud'         => $aud,
            'scope'       => $scope,
            'created'     => $now,
            'expires'     => $now + self::REFRESH_TTL,
            'access_id'   => $at_id,
            'used'        => false,
        ];
        update_option( self::REFRESH_OPTION, $refresh, false );

        return [
            'access_token'  => 'cmcp_at_' . $at_id . '.' . $at_secret,
            'refresh_token' => 'cmcp_rt_' . $rt_id . '.' . $rt_secret,
            'expires_in'    => self::ACCESS_TTL,
            'scope'         => $scope,
        ];
    }

    /**
     * Validate an OAuth access token. Returns the bound user_id or WP_Error.
     * Populates self::$last_token_context for audit logging.
     */
    public static function validate_access_token( string $token ): int|WP_Error {
        if ( ! str_starts_with( $token, 'cmcp_at_' ) ) {
            return new WP_Error( 'invalid_token', 'Not an OAuth access token.' );
        }
        $parts = explode( '.', substr( $token, strlen( 'cmcp_at_' ) ), 2 );
        if ( count( $parts ) !== 2 ) {
            return new WP_Error( 'invalid_token', 'Malformed access token.' );
        }
        [ $id, $secret ] = $parts;

        $tokens = get_option( self::TOKENS_OPTION, [] );
        if ( empty( $tokens[ $id ] ) ) {
            return new WP_Error( 'invalid_token', 'Unknown access token.' );
        }
        $rec = $tokens[ $id ];

        if ( ! hash_equals( (string) $rec['secret_hash'], hash( 'sha256', $secret ) ) ) {
            return new WP_Error( 'invalid_token', 'Access token mismatch.' );
        }
        if ( time() > (int) $rec['expires'] ) {
            return new WP_Error( 'expired_token', 'Access token expired.' );
        }
        if ( (string) ( $rec['aud'] ?? '' ) !== self::resource_url() ) {
            return new WP_Error( 'invalid_token', 'Access token audience mismatch.' );
        }

        // Fail closed: the bound user must still exist and remain an administrator.
        $user = get_user_by( 'id', (int) $rec['user_id'] );
        if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
            self::revoke_token_record( $id );
            return new WP_Error( 'revoked_token', 'Bound user no longer authorized.' );
        }

        // Throttle last_used writes (≤ once/min).
        $now = time();
        if ( empty( $rec['last_used'] ) || ( $now - (int) $rec['last_used'] ) >= 60 ) {
            $tokens[ $id ]['last_used'] = $now;
            update_option( self::TOKENS_OPTION, $tokens, false );
        }

        $clients = get_option( self::CLIENTS_OPTION, [] );
        if ( empty( $clients[ $rec['client_id'] ] ) ) {
            // The client registration behind this token is gone (revoked/pruned) —
            // fail closed rather than authenticating with an unscoped/stale context.
            self::revoke_token_record( $id );
            return new WP_Error( 'revoked_token', 'Client registration no longer exists.' );
        }
        $tool_scope = $clients[ $rec['client_id'] ]['tool_scope'] ?? null;
        self::$last_token_context = [
            'key_id'     => 'oauth_' . $id,
            'key_label'  => $clients[ $rec['client_id'] ]['client_name'] ?? ( 'OAuth: ' . $rec['client_id'] ),
            'key_prefix' => 'cmcp_at',
            'scope'      => is_array( $tool_scope ) ? $tool_scope : null,
        ];

        return (int) $rec['user_id'];
    }

    /**
     * Exchange a refresh token for a new pair, rotating (single-use) the old one.
     * Reuse of an already-used refresh token revokes the whole connection.
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:?string}|WP_Error
     */
    public static function rotate_refresh_token( string $token, string $client_id ): array|WP_Error {
        if ( ! str_starts_with( $token, 'cmcp_rt_' ) ) {
            return new WP_Error( 'invalid_grant', 'Not a refresh token.' );
        }
        $parts = explode( '.', substr( $token, strlen( 'cmcp_rt_' ) ), 2 );
        if ( count( $parts ) !== 2 ) {
            return new WP_Error( 'invalid_grant', 'Malformed refresh token.' );
        }
        [ $id, $secret ] = $parts;

        $refresh = get_option( self::REFRESH_OPTION, [] );
        if ( empty( $refresh[ $id ] ) ) {
            return new WP_Error( 'invalid_grant', 'Unknown refresh token.' );
        }
        $rec = $refresh[ $id ];

        if ( ! hash_equals( (string) $rec['secret_hash'], hash( 'sha256', $secret ) ) ) {
            return new WP_Error( 'invalid_grant', 'Refresh token mismatch.' );
        }
        if ( $client_id !== '' && $client_id !== $rec['client_id'] ) {
            return new WP_Error( 'invalid_grant', 'Client mismatch.' );
        }
        if ( time() > (int) $rec['expires'] ) {
            unset( $refresh[ $id ] );
            update_option( self::REFRESH_OPTION, $refresh, false );
            return new WP_Error( 'invalid_grant', 'Refresh token expired.' );
        }
        if ( ! empty( $rec['used'] ) ) {
            // Replay → revoke the whole connection.
            self::revoke_connection( (string) $rec['client_id'] );
            return new WP_Error( 'invalid_grant', 'Refresh token reuse detected; connection revoked.' );
        }

        $user = get_user_by( 'id', (int) $rec['user_id'] );
        if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
            unset( $refresh[ $id ] );
            update_option( self::REFRESH_OPTION, $refresh, false );
            if ( ! empty( $rec['access_id'] ) ) {
                self::revoke_token_record( (string) $rec['access_id'] );
            }
            return new WP_Error( 'invalid_grant', 'Bound user no longer permitted.' );
        }

        // Single-use: mark old refresh used, drop its access token, issue a new pair.
        // used=true records are intentionally retained until TTL expiry — do NOT prune them early;
        // they are the replay-detection proof that a token was already consumed.
        $refresh[ $id ]['used'] = true;
        update_option( self::REFRESH_OPTION, $refresh, false );
        if ( ! empty( $rec['access_id'] ) ) {
            self::revoke_token_record( (string) $rec['access_id'] );
        }

        return self::issue_tokens( (int) $rec['user_id'], (string) $rec['client_id'], (string) $rec['aud'], (string) $rec['scope'] );
    }

    /**
     * List active connections (one row per client that holds at least one token).
     *
     * @return array<int,array{client_id:string,client_name:string,user:string,created:int,last_used:int}>
     */
    public static function list_connections(): array {
        self::prune_expired();
        $clients = get_option( self::CLIENTS_OPTION, [] );
        $tokens  = get_option( self::TOKENS_OPTION, [] );

        $by_client = [];
        foreach ( $tokens as $t ) {
            $cid = $t['client_id'] ?? '';
            if ( $cid === '' ) {
                continue;
            }
            if ( empty( $by_client[ $cid ] ) ) {
                $by_client[ $cid ] = [
                    'user_id'   => (int) $t['user_id'],
                    'last_used' => (int) $t['last_used'],
                    'created'   => (int) $t['created'],
                ];
            } else {
                $by_client[ $cid ]['last_used'] = max( $by_client[ $cid ]['last_used'], (int) $t['last_used'] );
                $by_client[ $cid ]['created']   = min( $by_client[ $cid ]['created'], (int) $t['created'] );
            }
        }

        $out = [];
        foreach ( $by_client as $cid => $info ) {
            $user  = get_user_by( 'id', $info['user_id'] );
            $out[] = [
                'client_id'   => $cid,
                'client_name' => $clients[ $cid ]['client_name'] ?? __( 'Unknown client', 'cowboy-mcp' ),
                'user'        => $user ? $user->user_login : '—',
                'created'     => $info['created'],
                'last_used'   => $info['last_used'],
                'tool_scope'  => ( isset( $clients[ $cid ]['tool_scope'] ) && is_array( $clients[ $cid ]['tool_scope'] ) ) ? $clients[ $cid ]['tool_scope'] : null,
            ];
        }
        return $out;
    }

    public static function revoke_connection( string $client_id ): bool {
        $found = false;

        $clients = get_option( self::CLIENTS_OPTION, [] );
        if ( isset( $clients[ $client_id ] ) ) {
            unset( $clients[ $client_id ] );
            update_option( self::CLIENTS_OPTION, $clients, false );
            $found = true;
        }

        $tokens = get_option( self::TOKENS_OPTION, [] );
        foreach ( $tokens as $tid => $t ) {
            if ( ( $t['client_id'] ?? '' ) === $client_id ) {
                unset( $tokens[ $tid ] );
                $found = true;
            }
        }
        update_option( self::TOKENS_OPTION, $tokens, false );

        $refresh = get_option( self::REFRESH_OPTION, [] );
        foreach ( $refresh as $rid => $r ) {
            if ( ( $r['client_id'] ?? '' ) === $client_id ) {
                unset( $refresh[ $rid ] );
                $found = true;
            }
        }
        update_option( self::REFRESH_OPTION, $refresh, false );

        return $found;
    }

    /**
     * Update (or clear, with null) a connection's tool scope from wp-admin.
     * Takes effect on the connection's next request — tokens are untouched.
     */
    public static function update_connection_scope( string $client_id, ?array $scope ): bool {
        $clients = get_option( self::CLIENTS_OPTION, [] );
        if ( ! isset( $clients[ $client_id ] ) ) {
            return false;
        }
        $sanitized = Cowboy_MCP_Auth::sanitize_scope( $scope );
        if ( null === $sanitized ) {
            unset( $clients[ $client_id ]['tool_scope'] );
        } else {
            $clients[ $client_id ]['tool_scope'] = $sanitized;
        }
        update_option( self::CLIENTS_OPTION, $clients, false );
        return true;
    }

    /* ── Response helpers ──────────────────────────────────── */

    /** Emit a standalone JSON document and exit (for root .well-known routes). */
    private static function emit_json( array $data, int $status = 200 ): void {
        status_header( $status );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Access-Control-Allow-Origin: *' );
        echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function rest_json( array $data, int $status = 200 ): WP_REST_Response {
        $resp = new WP_REST_Response( $data, $status );
        $resp->header( 'Access-Control-Allow-Origin', '*' );
        $resp->header( 'Cache-Control', 'no-store' );
        return $resp;
    }

    /** OAuth-style error object (RFC 6749 §5.2). */
    private static function rest_error( string $code, string $message, int $status ): WP_REST_Response {
        return self::rest_json( [ 'error' => $code, 'error_description' => $message ], $status );
    }

    /* ── Root dispatch + discovery ─────────────────────────── */

    /**
     * Serve the root-level OAuth paths that cannot live under /wp-json/:
     * the two .well-known discovery docs and the interactive /authorize page.
     * Matched against the raw request path so it is permalink-independent.
     */
    public static function handle_root_requests(): void {
        if ( ! self::is_enabled() ) {
            return;
        }
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        if ( ! is_string( $path ) || $path === '' ) {
            return;
        }
        $path = untrailingslashit( $path );

        if ( $path === '/.well-known/oauth-protected-resource' ) {
            self::render_protected_resource_metadata();
        } elseif ( $path === '/.well-known/oauth-authorization-server' ) {
            self::render_as_metadata();
        } elseif ( $path === '/cowboy-mcp-oauth/authorize' ) {
            self::handle_authorize();
        }
    }

    public static function render_protected_resource_metadata(): void {
        self::emit_json( [
            'resource'                 => self::resource_url(),
            'authorization_servers'    => [ self::issuer() ],
            'bearer_methods_supported' => [ 'header' ],
            'scopes_supported'         => [ 'mcp' ],
            'resource_documentation'   => 'https://cowboymcp.com',
        ] );
    }

    public static function render_as_metadata(): void {
        $issuer = self::issuer();
        self::emit_json( [
            'issuer'                                => $issuer,
            'authorization_endpoint'                => $issuer . '/cowboy-mcp-oauth/authorize',
            'token_endpoint'                        => rest_url( 'cowboy-mcp/v1/oauth/token' ),
            'registration_endpoint'                 => rest_url( 'cowboy-mcp/v1/oauth/register' ),
            'response_types_supported'              => [ 'code' ],
            'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
            'code_challenge_methods_supported'      => [ 'S256' ],
            'token_endpoint_auth_methods_supported' => [ 'none' ],
            'scopes_supported'                      => [ 'mcp' ],
        ] );
    }

    public static function register_routes(): void {
        register_rest_route( 'cowboy-mcp/v1', '/oauth/register', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_register' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'cowboy-mcp/v1', '/oauth/token', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_token' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ── Dynamic Client Registration (RFC 7591) ────────────── */

    public static function handle_register( WP_REST_Request $request ) {
        if ( ! self::is_enabled() ) {
            return self::rest_error( 'oauth_disabled', 'OAuth connector is not enabled.', 404 );
        }
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = $request->get_params();
        }

        if ( ! self::registration_open() ) {
            return self::rest_error( 'registration_closed', 'New connections are switched off on this site right now. An administrator switches them on for 30 minutes with the "New connections" button under Settings > Cowboy MCP > Connection in WordPress; then add the app again. Existing connections are not affected.', 403 );
        }

        $client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        if ( $client_ip !== '' && ! Cowboy_MCP_Auth::check_rate_limit( 'reg_' . md5( $client_ip ), self::REGISTER_RATE_LIMIT ) ) {
            $resp = self::rest_error( 'too_many_requests', 'Too many registrations. Try again in a minute.', 429 );
            $resp->header( 'Retry-After', '60' );
            return $resp;
        }

        $redirect_uris = $body['redirect_uris'] ?? [];
        if ( ! is_array( $redirect_uris ) || empty( $redirect_uris ) ) {
            return self::rest_error( 'invalid_redirect_uri', 'redirect_uris is required.', 400 );
        }
        if ( count( $redirect_uris ) > self::MAX_REDIRECT_URIS ) {
            return self::rest_error( 'invalid_redirect_uri', 'At most ' . self::MAX_REDIRECT_URIS . ' redirect_uris are accepted.', 400 );
        }
        // Filter, never reject on one bad entry: Cursor and VS Code register a list that
        // mixes a loopback URL with custom-scheme and vendor-web entries. Only the
        // entries that pass are registered; the request fails only if none survive.
        $clean   = [];
        $dropped = [];
        foreach ( $redirect_uris as $uri ) {
            if ( ! is_string( $uri ) || strlen( $uri ) > self::MAX_REDIRECT_URI_LEN ) {
                return self::rest_error( 'invalid_redirect_uri', 'Each redirect_uri must be a string of at most ' . self::MAX_REDIRECT_URI_LEN . ' characters.', 400 );
            }
            $uri = esc_url_raw( $uri, [ 'https', 'http' ] );
            if ( $uri === '' ) {
                continue;
            }
            if ( ! self::redirect_uri_allowed( $uri ) ) {
                $dropped[] = (string) wp_parse_url( $uri, PHP_URL_HOST );
                continue;
            }
            $clean[] = $uri;
        }
        if ( empty( $clean ) ) {
            if ( ! empty( $dropped ) ) {
                return self::rest_error(
                    'invalid_redirect_uri',
                    sprintf(
                        '%s is not an allowed redirect host on this site. Known AI apps (%s) and localhost are accepted over https; an administrator can add other hosts under Settings > Cowboy MCP > Desktop Connector.',
                        implode( ', ', array_unique( $dropped ) ),
                        implode( ', ', self::DEFAULT_REDIRECT_HOSTS )
                    ),
                    400
                );
            }
            return self::rest_error( 'invalid_redirect_uri', 'No valid redirect_uris supplied.', 400 );
        }

        $clients = get_option( self::CLIENTS_OPTION, [] );
        if ( count( $clients ) >= self::MAX_CLIENTS ) {
            // Bound DCR abuse: drop the oldest clients that hold no active tokens.
            $clients = self::prune_unused_clients( $clients );
        }

        $now  = time();
        // Truncate, never reject: a long display name must not stop a real client connecting.
        $raw_name = $body['client_name'] ?? '';
        $name     = sanitize_text_field( substr( is_string( $raw_name ) ? $raw_name : '', 0, 4 * self::MAX_CLIENT_NAME_LEN ) );
        $name     = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::MAX_CLIENT_NAME_LEN ) : substr( $name, 0, self::MAX_CLIENT_NAME_LEN );
        if ( $name === '' ) {
            $name = 'MCP Client';
        }
        // Self-describing id so the registration survives a database overwrite;
        // registrations too long for one keep the row-only random id.
        $client_id = self::mint_signed_client_id( $name, $clean, $now )
            ?? 'cmcp_client_' . bin2hex( random_bytes( 8 ) );

        $clients[ $client_id ] = [
            'client_id'                  => $client_id,
            'client_name'                => $name,
            'redirect_uris'              => $clean,
            'created'                    => $now,
            'last_used'                  => null,
            'token_endpoint_auth_method' => 'none',
        ];
        update_option( self::CLIENTS_OPTION, $clients, false );

        return self::rest_json( [
            'client_id'                  => $client_id,
            'client_id_issued_at'        => $now,
            'client_name'                => $name,
            'redirect_uris'              => $clean,
            'grant_types'                => [ 'authorization_code', 'refresh_token' ],
            'response_types'             => [ 'code' ],
            'token_endpoint_auth_method' => 'none',
        ], 201 );
    }

    /** Drop registered clients (oldest first) that currently hold no tokens. */
    private static function prune_unused_clients( array $clients ): array {
        $tokens     = get_option( self::TOKENS_OPTION, [] );
        $active_ids = [];
        foreach ( $tokens as $t ) {
            if ( ! empty( $t['client_id'] ) ) {
                $active_ids[ $t['client_id'] ] = true;
            }
        }
        // A connection idle for over an hour has no access token but still holds a
        // 30-day refresh token — it is not "unused", and DCR spam must not evict it.
        $now = time();
        foreach ( get_option( self::REFRESH_OPTION, [] ) as $r ) {
            if ( ! empty( $r['client_id'] ) && empty( $r['used'] ) && (int) ( $r['expires'] ?? 0 ) > $now ) {
                $active_ids[ $r['client_id'] ] = true;
            }
        }
        $unused = array_filter( $clients, fn( $c ) => empty( $active_ids[ $c['client_id'] ] ) );
        uasort( $unused, fn( $a, $b ) => ( (int) $a['created'] ) <=> ( (int) $b['created'] ) );
        // Remove up to half of MAX_CLIENTS worth of the oldest unused clients.
        $remove = array_slice( array_keys( $unused ), 0, (int) ceil( self::MAX_CLIENTS / 2 ) );
        foreach ( $remove as $cid ) {
            unset( $clients[ $cid ] );
        }
        return $clients;
    }

    /* ── Token endpoint ────────────────────────────────────── */

    public static function handle_token( WP_REST_Request $request ) {
        if ( ! self::is_enabled() ) {
            return self::rest_error( 'oauth_disabled', 'OAuth connector is not enabled.', 404 );
        }
        $grant = sanitize_text_field( (string) $request->get_param( 'grant_type' ) );

        return match ( $grant ) {
            'authorization_code' => self::token_authorization_code( $request ),
            'refresh_token'      => self::token_refresh( $request ),
            default              => self::rest_error( 'unsupported_grant_type', 'Unsupported grant_type.', 400 ),
        };
    }

    private static function token_authorization_code( WP_REST_Request $request ) {
        $code         = sanitize_text_field( (string) $request->get_param( 'code' ) );
        $verifier     = (string) $request->get_param( 'code_verifier' );
        $redirect_uri = esc_url_raw( (string) $request->get_param( 'redirect_uri' ), [ 'https', 'http' ] );
        $client_id    = sanitize_text_field( (string) $request->get_param( 'client_id' ) );

        if ( $code === '' ) {
            return self::rest_error( 'invalid_request', 'Missing code.', 400 );
        }
        if ( ! self::client_is_known( $client_id ) ) {
            // RFC 6749 §5.2. MCP clients treat invalid_client as "registration is
            // gone — register again"; invalid_grant only makes them drop tokens.
            // Checked before the code is read so an unknown client cannot burn it.
            return self::rest_error( 'invalid_client', 'Unknown client. Register again.', 401 );
        }

        $ctx = get_transient( self::CODE_PREFIX . $code );
        delete_transient( self::CODE_PREFIX . $code ); // single use
        if ( ! is_array( $ctx ) ) {
            return self::rest_error( 'invalid_grant', 'Authorization code invalid or expired.', 400 );
        }
        if ( $client_id !== $ctx['client_id'] || $redirect_uri !== $ctx['redirect_uri'] ) {
            return self::rest_error( 'invalid_grant', 'Client or redirect_uri mismatch.', 400 );
        }
        if ( ! self::verify_pkce( $verifier, (string) $ctx['code_challenge'], (string) $ctx['code_challenge_method'] ) ) {
            return self::rest_error( 'invalid_grant', 'PKCE verification failed.', 400 );
        }

        $user = get_user_by( 'id', (int) $ctx['user_id'] );
        if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
            return self::rest_error( 'invalid_grant', 'Authorizing user is no longer permitted.', 400 );
        }

        $t = self::issue_tokens( (int) $ctx['user_id'], $client_id, (string) $ctx['aud'], (string) $ctx['scope'] );
        return self::rest_json( [
            'access_token'  => $t['access_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => $t['expires_in'],
            'refresh_token' => $t['refresh_token'],
            'scope'         => $t['scope'],
        ] );
    }

    private static function token_refresh( WP_REST_Request $request ) {
        $refresh   = (string) $request->get_param( 'refresh_token' );
        $client_id = sanitize_text_field( (string) $request->get_param( 'client_id' ) );

        if ( $client_id !== '' && ! self::client_is_known( $client_id ) ) {
            return self::rest_error( 'invalid_client', 'Unknown client. Register again.', 401 );
        }

        $t = self::rotate_refresh_token( $refresh, $client_id );
        if ( is_wp_error( $t ) ) {
            return self::rest_error( 'invalid_grant', $t->get_error_message(), 400 );
        }
        return self::rest_json( [
            'access_token'  => $t['access_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => $t['expires_in'],
            'refresh_token' => $t['refresh_token'],
            'scope'         => $t['scope'],
        ] );
    }

    /* ── Authorize + consent ───────────────────────────────── */

    public static function handle_authorize(): void {
        if ( ! self::is_enabled() ) {
            self::authorize_fatal( __( 'The OAuth connector is not enabled on this site.', 'cowboy-mcp' ) );
        }

        // The consent screen hands out administrator access on one click: never frameable.
        header( 'X-Frame-Options: DENY' );
        header( "Content-Security-Policy: frame-ancestors 'none'" );

        $method  = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
        $is_post = ( strtoupper( $method ) === 'POST' );
        // phpcs:ignore WordPress.Security.NonceVerification
        $src = $is_post ? $_POST : $_GET;

        $client_id    = sanitize_text_field( wp_unslash( $src['client_id'] ?? '' ) );
        $redirect_uri = esc_url_raw( wp_unslash( $src['redirect_uri'] ?? '' ), [ 'https', 'http' ] );
        $state        = sanitize_text_field( wp_unslash( $src['state'] ?? '' ) );
        $challenge    = sanitize_text_field( wp_unslash( $src['code_challenge'] ?? '' ) );
        $challenge_m  = sanitize_text_field( wp_unslash( $src['code_challenge_method'] ?? '' ) );
        $scope        = sanitize_text_field( wp_unslash( $src['scope'] ?? 'mcp' ) );
        $resource     = esc_url_raw( wp_unslash( $src['resource'] ?? self::resource_url() ), [ 'https', 'http' ] );

        // Validate client + redirect_uri BEFORE trusting them for any redirect.
        // Verify only: this runs for unauthenticated visitors and must not write.
        $client   = self::resolve_client( $client_id );
        $adopting = false;
        if ( null === $client ) {
            // Rescue: an id this site cannot verify (registered before 1.6.8, then lost to
            // a database overwrite). It may be adopted on an administrator's Approve,
            // under exactly the rules a new registration gets: only while "New
            // connections" is enabled, only to an allowed destination, and only after
            // the admin is logged in (checked below). Nothing is recorded before the
            // Approve click. Registration is open to anyone, so this grants nothing new.
            $adoptable = self::registration_open()
                && (bool) apply_filters( 'cowboy_mcp_oauth_adopt_unknown_clients', true )
                && $client_id !== '' && strlen( $client_id ) <= self::SIGNED_MAX_ID && preg_match( '/^[A-Za-z0-9._~-]+$/', $client_id )
                && $redirect_uri !== '';
            if ( ! $adoptable ) {
                self::authorize_fatal( __( 'This site has no record of this app. That usually means the site\'s database was restored or copied from another site. To let the app connect again: under Settings > Cowboy MCP > Connection, pick the app and click "Enable for 30 minutes", then use Reconnect in the app and approve it here.', 'cowboy-mcp' ) );
            }
            $adopting = true;
            $client   = [
                'client_id'                  => $client_id,
                'client_name'                => sprintf( 'App at %s', (string) wp_parse_url( $redirect_uri, PHP_URL_HOST ) ),
                'redirect_uris'              => [ $redirect_uri ],
                'created'                    => time(),
                'last_used'                  => null,
                'token_endpoint_auth_method' => 'none',
                'adopted'                    => time(),
            ];
        }
        if ( $redirect_uri === '' || ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
            self::authorize_fatal( __( 'Invalid redirect_uri for this client.', 'cowboy-mcp' ) );
        }

        // Must be a logged-in administrator.
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( self::current_authorize_url() ) );
            exit;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            self::authorize_fatal( __( 'You must be an administrator to authorize this connection.', 'cowboy-mcp' ) );
        }

        // Malformed requests get an error page, never a redirect: anyone can register a
        // client with any redirect_uri, so redirecting here (least of all before login)
        // would make this URL an open redirector on the site's own domain. A working
        // client never reaches these branches; an admin's Deny below still redirects.
        if ( $challenge === '' || $challenge_m !== 'S256' ) {
            self::authorize_fatal( __( 'This connection request is missing a required security parameter (PKCE S256). Start the connection again from your AI app.', 'cowboy-mcp' ) );
        }
        // RFC 8707 audience: only this server's resource is supported.
        if ( $resource !== self::resource_url() ) {
            self::authorize_fatal( __( 'This connection request is for a different address than this site\'s MCP endpoint. Check the server URL in your AI app.', 'cowboy-mcp' ) );
        }

        // Checked here, not only at registration: a client registered before this list
        // existed (or before an administrator removed a host) must not be usable either.
        // Error page, never a redirect - the destination is the very thing in question.
        if ( ! self::redirect_uri_allowed( $redirect_uri ) ) {
            self::authorize_fatal( sprintf(
                /* translators: %s: host name the app asked to be sent back to */
                __( 'This connection would send you to %s, which is not an allowed destination on this site. If you trust that app, an administrator can add its host under Settings > Cowboy MCP > Desktop Connector.', 'cowboy-mcp' ),
                (string) wp_parse_url( $redirect_uri, PHP_URL_HOST )
            ) );
        }

        // An administrator is present: a signature-only client may now be persisted
        // (this also clears any token that outlived its row — see resolve_client()).
        $client  = self::resolve_client( $client_id, true ) ?? $client;
        $clients = get_option( self::CLIENTS_OPTION, [] );

        if ( $is_post ) {
            if ( ! isset( $_POST['_wpnonce'] )
                || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cowboy_mcp_oauth_consent' ) ) {
                self::authorize_fatal( __( 'Security check failed. Please try again.', 'cowboy-mcp' ) );
            }
            if ( empty( $_POST['cowboy_mcp_oauth_approve'] ) ) {
                self::authorize_redirect_error( $redirect_uri, $state, 'access_denied', 'The request was denied.' );
            }
            if ( $adopting ) {
                // First and only write for an adopted id: clear any token that outlived its
                // old row (it would come back unscoped), then record the client so the
                // code exchange and later refreshes find it.
                self::revoke_connection( $client_id );
                $clients = get_option( self::CLIENTS_OPTION, [] );
                if ( count( $clients ) >= self::MAX_CLIENTS ) {
                    $clients = self::prune_unused_clients( $clients );
                }
                $clients[ $client_id ] = $client;
                update_option( self::CLIENTS_OPTION, $clients, false );
                Cowboy_MCP_Auth::log( 'oauth_client_adopted', [ 'redirect_host' => (string) wp_parse_url( $redirect_uri, PHP_URL_HOST ) ] );
            }

            $code = self::issue_authorization_code( [
                'client_id'             => $client_id,
                'redirect_uri'          => $redirect_uri,
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
                'user_id'               => get_current_user_id(),
                'scope'                 => $scope,
                'aud'                   => $resource,
            ] );

            $scope_mode   = sanitize_text_field( wp_unslash( $_POST['cowboy_mcp_oauth_scope_mode'] ?? 'full' ) );
            $current_mode = $clients[ $client_id ]['tool_scope']['mode'] ?? 'full';
            if ( 'read_only' === $scope_mode ) {
                $clients[ $client_id ]['tool_scope'] = [ 'mode' => 'read_only', 'allowed_tools' => [] ];
            } elseif ( 'keep_custom' === $scope_mode && 'custom' === $current_mode ) {
                // Re-consent without touching the existing custom scope.
            } else {
                // Latest consent wins: an earlier read-only/custom grant is
                // replaced by this full-access approval. A stale "keep_custom"
                // posted against a scope that is no longer custom falls back to
                // full, fail-safe, rather than silently discarding nothing.
                unset( $clients[ $client_id ]['tool_scope'] );
            }
            unset( $clients[ $client_id ]['rehydrated'] );
            $clients[ $client_id ]['last_used'] = time();
            update_option( self::CLIENTS_OPTION, $clients, false );

            $sep = ( strpos( $redirect_uri, '?' ) === false ) ? '?' : '&';
            $loc = $redirect_uri . $sep . http_build_query( [ 'code' => $code, 'state' => $state ] );
            wp_redirect( $loc ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri validated against the registered client
            exit;
        }

        self::render_consent_screen( $client, $redirect_uri, $state, $challenge, $challenge_m, $scope, $resource );
    }

    private static function current_authorize_url(): string {
        // REQUEST_URI already contains percent-encoded OAuth values. Running it through
        // sanitize_text_field() strips those octets and corrupts redirect_uri/resource
        // when the authorization flow round-trips through wp-login.php.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve encoded OAuth query values; the complete URL is sanitized below.
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/cowboy-mcp-oauth/authorize';
        return esc_url_raw( self::issuer() . $uri, [ 'https', 'http' ] );
    }

    private static function authorize_redirect_error( string $redirect_uri, string $state, string $error, string $desc ): void {
        $sep = ( strpos( $redirect_uri, '?' ) === false ) ? '?' : '&';
        $loc = $redirect_uri . $sep . http_build_query( [ 'error' => $error, 'error_description' => $desc, 'state' => $state ] );
        wp_redirect( $loc ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri validated against the registered client
        exit;
    }

    /** Render a minimal standalone error page (not the admin settings page). */
    private static function authorize_fatal( string $message ): void {
        status_header( 400 );
        nocache_headers();
        $heading = __( 'Connection error', 'cowboy-mcp' );
        $body    = '<h1>' . esc_html( $heading ) . '</h1><p>' . esc_html( $message ) . '</p>';
        wp_die( wp_kses_post( $body ), esc_html( $heading ), [ 'response' => 400 ] );
    }

    private static function render_consent_screen( array $client, string $redirect_uri, string $state, string $challenge, string $challenge_m, string $scope, string $resource ): void {
        $site_name   = get_bloginfo( 'name' );
        $client_name = $client['client_name'] ?? __( 'An application', 'cowboy-mcp' );
        $user        = wp_get_current_user();
        $action      = esc_url( self::issuer() . '/cowboy-mcp-oauth/authorize' );
        $dest_host   = (string) wp_parse_url( $redirect_uri, PHP_URL_HOST );

        // Preselect the radio matching the client's currently stored scope so
        // re-consent doesn't silently overwrite a custom grant (see handle_authorize()).
        $tool_scope     = $client['tool_scope'] ?? null;
        $stored_mode    = $tool_scope['mode'] ?? 'full';
        $is_custom      = ( 'custom' === $stored_mode );
        $custom_count   = $is_custom ? count( $tool_scope['allowed_tools'] ?? [] ) : 0;

        // This is a standalone document rendered outside the normal wp-admin/theme
        // page lifecycle (no wp_head()/wp_footer() runs here), so the enqueued style
        // is printed explicitly via wp_print_styles() rather than relying on a hook.
        $css_path = COWBOY_MCP_PATH . 'admin/css/oauth-consent.css';
        wp_register_style(
            'cowboy-mcp-oauth-consent',
            COWBOY_MCP_URL . 'admin/css/oauth-consent.css',
            [],
            file_exists( $css_path ) ? (string) filemtime( $css_path ) : COWBOY_MCP_VERSION
        );
        wp_enqueue_style( 'cowboy-mcp-oauth-consent' );

        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html__( 'Authorize connection', 'cowboy-mcp' ); ?></title>
<?php wp_print_styles(); ?>
</head>
<body>
<div class="cowboy-mcp-oauth-card">
 <h1><?php
    /* translators: %s: application/client name */
    printf( esc_html__( '%s wants to connect', 'cowboy-mcp' ), '<strong>' . esc_html( $client_name ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
 ?></h1>
 <p><?php
    /* translators: %s: site name */
    printf( esc_html__( 'It will be able to read and manage %s through the MCP server, acting with your administrator permissions.', 'cowboy-mcp' ), '<strong>' . esc_html( $site_name ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
 ?></p>
 <div class="who">
    <?php
    /* translators: %s: WordPress username */
    printf( esc_html__( 'Authorizing as %s', 'cowboy-mcp' ), '<strong>' . esc_html( $user->user_login ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
 </div>
 <div class="dest"><?php
    /* translators: %s: host name of the address the browser is sent to after approval, e.g. chatgpt.com */
    printf( esc_html__( 'After you approve, you will be sent to %s', 'cowboy-mcp' ), '<strong>' . esc_html( $dest_host ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
 ?></div>
 <p class="muted note"><?php esc_html_e( 'The app name above is supplied by the app and is not verified. Approve only if you just started this connection yourself.', 'cowboy-mcp' ); ?></p>
 <?php if ( ! empty( $client['rehydrated'] ) ) : ?>
 <p class="muted note"><?php esc_html_e( 'This site has no stored record of this connection. That is normal after a database restore or a staging sync. If you did not just start this from your AI app, choose Deny.', 'cowboy-mcp' ); ?></p>
 <?php elseif ( ! empty( $client['adopted'] ) && empty( get_option( self::CLIENTS_OPTION, [] )[ $client['client_id'] ] ) ) : ?>
 <p class="muted note"><?php esc_html_e( 'This site has no record of this app. Approving registers it here and sends you to the address above. That is expected after a database restore or a staging sync for a connection made before Cowboy MCP 1.6.8. If you did not just start this from your AI app, choose Deny.', 'cowboy-mcp' ); ?></p>
 <?php endif; ?>
 <form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>">
    <?php wp_nonce_field( 'cowboy_mcp_oauth_consent' ); ?>
    <input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ); ?>">
    <input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
    <input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
    <input type="hidden" name="code_challenge" value="<?php echo esc_attr( $challenge ); ?>">
    <input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $challenge_m ); ?>">
    <input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
    <input type="hidden" name="resource" value="<?php echo esc_attr( $resource ); ?>">
    <fieldset class="scope">
        <legend><?php esc_html_e( 'Access level', 'cowboy-mcp' ); ?></legend>
        <?php if ( $is_custom ) : ?>
        <label>
            <input type="radio" name="cowboy_mcp_oauth_scope_mode" value="keep_custom" checked>
            <?php
            /* translators: %d: number of tools allowed in the connection's current custom scope */
            printf( esc_html__( 'Keep current custom scope (%d tools)', 'cowboy-mcp' ), (int) $custom_count );
            ?>
        </label>
        <?php endif; ?>
        <label>
            <input type="radio" name="cowboy_mcp_oauth_scope_mode" value="full" <?php checked( ! $is_custom && 'full' === $stored_mode ); ?>>
            <?php esc_html_e( 'Full access — read and manage the site', 'cowboy-mcp' ); ?>
        </label>
        <label>
            <input type="radio" name="cowboy_mcp_oauth_scope_mode" value="read_only" <?php checked( ! $is_custom && 'read_only' === $stored_mode ); ?>>
            <?php esc_html_e( 'Read-only — inspect the site, make no changes', 'cowboy-mcp' ); ?>
        </label>
    </fieldset>
    <div class="actions">
        <button class="deny" type="submit" name="cowboy_mcp_oauth_deny" value="1"><?php esc_html_e( 'Deny', 'cowboy-mcp' ); ?></button>
        <button class="approve" type="submit" name="cowboy_mcp_oauth_approve" value="1"><?php esc_html_e( 'Approve', 'cowboy-mcp' ); ?></button>
    </div>
 </form>
 <p class="muted"><?php
    /* translators: %s: redirect URI */
    printf( esc_html__( 'Redirects to: %s', 'cowboy-mcp' ), esc_html( $redirect_uri ) );
 ?></p>
</div>
</body>
</html>
        <?php
        exit;
    }
}
