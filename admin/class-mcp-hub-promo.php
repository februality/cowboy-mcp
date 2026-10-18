<?php
/**
 * Cowboy Hub promo: the banner in the plugin's console, its dismissed-state
 * pill, and the "Cowboy Hub" link on the Plugins screen.
 *
 * Where: the console page only (never an admin notice). When: for admins once
 * the site has at least one credential, held while the feedback prompt is due.
 * Dismiss: per user, permanent, keyed by campaign so a later campaign can show
 * a new banner once to everyone.
 *
 * @package Cowboy_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cowboy_MCP_Hub_Promo {

    const CAMPAIGN = 'early-access';
    const META_KEY = 'cowboy_mcp_hub_promo_dismissed';
    const PAGE_URL = 'https://cowboymcp.com/hub';
    const NONCE    = 'cowboy_mcp_hub_dismiss';

    public static function init(): void {
        add_action( 'wp_ajax_cowboy_mcp_hub_dismiss', [ __CLASS__, 'ajax_dismiss' ] );
        add_filter( 'plugin_row_meta', [ __CLASS__, 'plugin_row_meta' ], 10, 2 );
    }

    /* ── Links ─────────────────────────────────────────────── */

    /**
     * The Hub page with campaign attribution. Nothing phones home: the utm
     * parameters only tell the page which element sent the visit.
     *
     * @param string $medium banner | pill | plugins
     */
    public static function url( string $medium ): string {
        return add_query_arg(
            [
                'utm_source'   => 'plugin',
                'utm_medium'   => sanitize_key( $medium ),
                'utm_campaign' => 'hub-' . self::CAMPAIGN,
            ],
            self::PAGE_URL
        );
    }

    /* ── Gates ─────────────────────────────────────────────── */

    public static function is_dismissed( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return self::CAMPAIGN === get_user_meta( $user_id, self::META_KEY, true );
    }

    /**
     * Banner on this request: admin, site already connected to an app, not
     * dismissed for this campaign, and the feedback prompt is not on screen
     * (it is rarer and worth more). The feedback check runs last because it
     * reads the current screen.
     */
    public static function banner_due(): bool {
        if ( ! current_user_can( 'manage_options' ) || ! Cowboy_MCP_Auth::site_has_credentials() ) {
            return false;
        }
        if ( self::is_dismissed() ) {
            return false;
        }
        return ! Cowboy_MCP_Feedback::is_due();
    }

    public static function pill_due(): bool {
        return current_user_can( 'manage_options' )
            && Cowboy_MCP_Auth::site_has_credentials()
            && self::is_dismissed();
    }

    /* ── Rendering (console shell calls these) ─────────────── */

    public static function render_banner(): void {
    }

    /**
     * @param bool $hidden Render with the `hidden` attribute (banner still on
     *                     screen; JS reveals the pill on dismiss).
     */
    public static function render_pill( bool $hidden = false ): void {
    }

    /* ── Dismiss ───────────────────────────────────────────── */

    public static function ajax_dismiss(): void {
        check_ajax_referer( self::NONCE );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( null, 403 );
        }
        update_user_meta( get_current_user_id(), self::META_KEY, self::CAMPAIGN );
        wp_send_json_success();
    }

    /* ── Plugins screen ────────────────────────────────────── */

    /**
     * Adds "Cowboy Hub" to the plugin's row meta (next to "View details").
     *
     * @param string[] $links
     * @return string[]
     */
    public static function plugin_row_meta( array $links, string $file ): array {
        if ( plugin_basename( COWBOY_MCP_PATH . 'cowboy-mcp.php' ) !== $file ) {
            return $links;
        }
        $links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">Cowboy Hub</a>',
            esc_url( self::url( 'plugins' ) )
        );
        return $links;
    }
}
