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
        if ( ! self::banner_due() ) {
            return;
        }
        $icon = esc_url( COWBOY_MCP_URL . 'admin/images/icon-128.png' );
        ?>
        <div class="cmcp-card cmcp-hub" data-cmcp-hub role="region" aria-label="Cowboy Hub">
            <div class="cmcp-hub-copy">
                <h2><?php esc_html_e( 'Every site you manage. One connection to your AI.', 'cowboy-mcp' ); ?></h2>
                <p><?php esc_html_e( 'You already run this site through your AI. Hub does the same across every site you manage: content, plugin updates with backup and rollback, malware clean-up, speed, SEO, accessibility, client reports. Any host, any AI app, nothing has to move. This free plugin stays free.', 'cowboy-mcp' ); ?></p>
                <ul class="cmcp-hub-points">
                    <li><?php esc_html_e( 'One prompt, every site, every task', 'cowboy-mcp' ); ?></li>
                    <li><?php esc_html_e( 'Ten sites or a hundred, one request', 'cowboy-mcp' ); ?></li>
                    <li><?php esc_html_e( 'Works even if the site is down', 'cowboy-mcp' ); ?></li>
                    <li><?php esc_html_e( 'Schedule changes across the whole fleet', 'cowboy-mcp' ); ?></li>
                </ul>
                <div class="cmcp-hub-actions">
                    <a class="cmcp-hub-cta" href="<?php echo esc_url( self::url( 'banner' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get early access', 'cowboy-mcp' ); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></a>
                </div>
            </div>
            <div class="cmcp-hub-art" aria-hidden="true">
                <?php echo self::diagram_svg( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal; the icon URL is escaped above. ?>
            </div>
            <button type="button" class="cmcp-hub-x" data-cmcp-hub-dismiss aria-label="<?php esc_attr_e( 'Hide the Cowboy Hub banner', 'cowboy-mcp' ); ?>">&times;</button>
        </div>
        <?php
    }

    /**
     * The dismissed-state pill at the end of the tab row.
     *
     * @param bool $hidden Render with the `hidden` attribute (banner still on
     *                     screen; JS reveals the pill on dismiss).
     */
    public static function render_pill( bool $hidden = false ): void {
        // Visible pill only once dismissed; a hidden pill only when a banner is
        // on screen for it to replace (so a held banner leaves no promo markup).
        if ( $hidden ? ! self::banner_due() : ! self::pill_due() ) {
            return;
        }
        printf(
            '<a class="cmcp-hub-pill" data-cmcp-hub-pill href="%1$s" target="_blank" rel="noopener noreferrer"%2$s><span>%3$s</span></a>',
            esc_url( self::url( 'pill' ) ),
            $hidden ? ' hidden' : '',
            wp_kses( __( 'Manage multiple sites? <b>Check out Cowboy Hub</b>', 'cowboy-mcp' ), [ 'b' => [] ] )
        );
    }

    /**
     * The live diagram: "your AI agent" (left) → the plugin icon as the hub →
     * five mock sites (right). A pulse leaves the agent every 1.2 s and fans
     * out to the next site; SMIL, no JS. Labels are part of the graphic and
     * are not translated.
     *
     * @param string $icon_url Already-escaped URL of the plugin icon.
     */
    private static function diagram_svg( string $icon_url ): string {
        $pulse = static function ( int $i, string $start ): string {
            return '<g class="cmcp-hub-pulse"><circle class="cmcp-hub-halo" r="5.5"></circle><circle class="cmcp-hub-dot" r="2.4"></circle>'
                . '<animateMotion id="pa' . $i . '" dur=".9s" begin="' . $start . 's;pa' . $i . '.end+5.1s" calcMode="spline" keySplines=".45 0 .2 1" keyTimes="0;1" keyPoints="0;1"><mpath href="#hwa"></mpath></animateMotion>'
                . '<set attributeName="opacity" to="1" begin="pa' . $i . '.begin"></set><set attributeName="opacity" to="0" begin="pa' . $i . '.end"></set></g>'
                . '<g class="cmcp-hub-pulse"><circle class="cmcp-hub-halo" r="5.5"></circle><circle class="cmcp-hub-dot" r="2.4"></circle>'
                . '<animateMotion id="ps' . $i . '" dur="1.1s" begin="pa' . $i . '.end" calcMode="spline" keySplines=".45 0 .2 1" keyTimes="0;1" keyPoints="0;1"><mpath href="#hw' . $i . '"></mpath></animateMotion>'
                . '<set attributeName="opacity" to="1" begin="ps' . $i . '.begin"></set><set attributeName="opacity" to="0" begin="ps' . $i . '.end"></set></g>';
        };
        $sites = [ [ 14, 'ridgeline.coffee' ], [ 38, 'harbor.clinic' ], [ 62, 'bluebird.shop' ], [ 86, 'northwind.law' ], [ 110, 'oakash.studio' ] ];
        $wires = '';
        $nodes = '';
        foreach ( $sites as $n => [ $y, $label ] ) {
            $i      = $n + 1;
            $wires .= '<path class="cmcp-hub-wire" d="M205.5 64 C 270 64, 280 ' . $y . ', 332 ' . $y . '"></path><path id="hw' . $i . '" fill="none" stroke="none" d="M209 64 C 270 64, 280 ' . $y . ', 329 ' . $y . '"></path>';
            $nodes .= '<circle class="cmcp-hub-node" cx="332" cy="' . $y . '" r="2.6"></circle><text class="cmcp-hub-lbl" x="342" y="' . ( $y + 4 ) . '">' . $label . '</text>';
        }
        return '<svg viewBox="0 0 450 128">'
            . '<rect class="cmcp-hub-agentbox" x="0.5" y="44.5" width="68" height="39" rx="9"></rect>'
            . '<text class="cmcp-hub-lbl cmcp-hub-lbl--b" x="34.5" y="61" text-anchor="middle">your AI</text><text class="cmcp-hub-lbl cmcp-hub-lbl--b" x="34.5" y="74" text-anchor="middle">agent</text>'
            . '<rect x="0.5" y="44.5" width="68" height="39" rx="9" fill="none" stroke="var(--cmcp-code-green)" stroke-opacity="0"><animate attributeName="stroke-opacity" values="0;.55;0" dur=".6s" begin="pa1.begin;pa2.begin;pa3.begin;pa4.begin;pa5.begin"></animate></rect>'
            . '<path class="cmcp-hub-wire" d="M69 64 L 166.5 64"></path><path id="hwa" fill="none" stroke="none" d="M72 64 L 163 64"></path>'
            . $wires . $nodes
            . '<rect class="cmcp-hub-agentbox" x="166.5" y="44.5" width="39" height="39" rx="9"></rect>'
            . '<image href="' . $icon_url . '" x="170" y="48" width="32" height="32" style="image-rendering: pixelated"></image>'
            . '<rect x="166.5" y="44.5" width="39" height="39" rx="9" fill="none" stroke="var(--cmcp-code-green)" stroke-opacity="0"><animate attributeName="stroke-opacity" values="0;.55;0" dur=".6s" begin="pa1.end;pa2.end;pa3.end;pa4.end;pa5.end"></animate></rect>'
            . $pulse( 1, '0.4' ) . $pulse( 2, '1.6' ) . $pulse( 3, '2.8' ) . $pulse( 4, '4.0' ) . $pulse( 5, '5.2' )
            . '</svg>';
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
