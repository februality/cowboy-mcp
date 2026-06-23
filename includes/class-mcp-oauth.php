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

    /* ── PKCE helpers ──────────────────────────────────────── */

    public static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
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
        self::$last_token_context = [
            'key_id'     => 'oauth_' . $id,
            'key_label'  => $clients[ $rec['client_id'] ]['client_name'] ?? ( 'OAuth: ' . $rec['client_id'] ),
            'key_prefix' => 'cmcp_at',
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

    /* ── Stubs filled in by later tasks ─────────────────────── */

    public static function handle_root_requests(): void {}
    public static function register_routes(): void {}
}
