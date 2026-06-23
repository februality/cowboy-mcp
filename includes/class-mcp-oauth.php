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

    /* ── Stubs filled in by later tasks ─────────────────────── */

    public static function handle_root_requests(): void {}
    public static function register_routes(): void {}
}
