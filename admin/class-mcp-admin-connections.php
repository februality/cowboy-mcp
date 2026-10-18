<?php
/**
 * Cowboy MCP – Admin: Connections page (connect flow, credentials, Doctor).
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Admin_Connections {

    // Verified 2026-10-06 against live ChatGPT: the Plugins page; its + menu offers 'Add custom MCP server'.
    const CHATGPT_URL = 'https://chatgpt.com/plugins';

    /** Remember (30 min, per admin) which app the admin just started connecting, and how. */
    public static function set_connect_watch( string $app, string $mode = 'oauth' ): void {
        set_transient( 'cowboy_mcp_connect_watch_' . get_current_user_id(), [ 'app' => $app, 'mode' => $mode, 'since' => time() ], 30 * MINUTE_IN_SECONDS );
    }

    /** @return array{app:string,since:int,mode?:string}|null  (1.7.1 transients carry no mode = oauth) */
    public static function connect_watch(): ?array {
        $w = get_transient( 'cowboy_mcp_connect_watch_' . get_current_user_id() );
        return ( is_array( $w ) && isset( $w['app'], $w['since'] ) ) ? $w : null;
    }

    /** Display label for a watch slug ('claude' = the hosted Claude apps). */
    public static function watch_label( string $app ): string {
        if ( class_exists( 'Cowboy_MCP_OAuth' ) && isset( Cowboy_MCP_OAuth::CLIENT_LABELS[ $app ] ) ) {
            return Cowboy_MCP_OAuth::CLIENT_LABELS[ $app ];
        }
        return self::client_registry()[ $app ]['label'] ?? __( 'the app', 'cowboy-mcp' );
    }

    /**
     * Read-only: how far the watched app has got since the click. Never writes clients or tokens.
     * OAuth mode: only client rows whose derived slug matches the watch count, so unrelated
     * connections can never report "connected". Key mode: only keys created at most 30 min before the click
     * (and used after it) count.
     *
     * @param array{app:string,since:int,mode?:string} $watch
     * @return array{stage:string,reused:bool,app:string,label:string,mode:string}
     */
    public static function connect_status( array $watch ): array {
        $app   = (string) $watch['app'];
        $mode  = 'key' === ( $watch['mode'] ?? 'oauth' ) ? 'key' : 'oauth';
        $since = (int) $watch['since'];
        $out   = [ 'stage' => 'waiting', 'reused' => false, 'app' => $app, 'label' => self::watch_label( $app ), 'mode' => $mode ];
        $oauth = class_exists( 'Cowboy_MCP_OAuth' );
        if ( 'key' === $mode ) {
            foreach ( Cowboy_MCP_Auth::list_keys() as $k ) {
                // Keys are minted before Copy (the snippet embeds them), so a key created up to 30 min before the click counts; older keys never do.
                if ( (int) ( $k['created'] ?? 0 ) >= $since - 30 * MINUTE_IN_SECONDS && ( (int) ( $k['last_used'] ?? 0 ) >= $since || (int) ( $k['client_info']['at'] ?? 0 ) >= $since ) ) {
                    $out['stage'] = 'connected';
                    $who          = $oauth ? Cowboy_MCP_OAuth::client_display_name( $k['client_info'] ?? null ) : null;
                    $out['label'] = $who ?? $out['label'];
                    break;
                }
            }
            return $out;
        }
        if ( ! $oauth ) {
            return $out;
        }
        $clients = (array) get_option( 'cowboy_mcp_oauth_clients', [] );
        $tokens  = array_merge( array_values( (array) get_option( 'cowboy_mcp_oauth_tokens', [] ) ), array_values( (array) get_option( 'cowboy_mcp_oauth_refresh', [] ) ) );
        $order   = [ 'waiting' => 0, 'registered' => 1, 'approved' => 2, 'connected' => 3 ];
        $stage   = 'waiting';
        $reused  = false;
        $named   = null;
        foreach ( $clients as $cid => $c ) {
            if ( ! is_array( $c ) || Cowboy_MCP_OAuth::client_slug_for_row( $c ) !== $app ) {
                continue;
            }
            $mine  = array_filter( $tokens, static fn( $t ) => is_array( $t ) && ( $t['client_id'] ?? '' ) === $cid );
            $used  = array_filter( $mine, static fn( $t ) => ! empty( $t['last_used'] ) && (int) $t['last_used'] >= $since );
            $fresh = (int) ( $c['created'] ?? 0 ) >= $since;
            if ( $fresh ) {
                $s = $used ? 'connected' : ( $mine ? 'approved' : 'registered' );
            } elseif ( $used ) {
                $s = 'connected';
            } else {
                continue;
            }
            if ( $order[ $s ] > $order[ $stage ] || ( 'connected' === $s && 'connected' === $stage && $fresh ) ) {
                $stage  = $s;
                $reused = 'connected' === $s && ! $fresh;
                $ci     = Cowboy_MCP_Auth::client_info_for( 'oauth', (string) $cid );
                $named  = (int) ( $ci['at'] ?? 0 ) >= $since ? Cowboy_MCP_OAuth::client_display_name( $ci ) : null;
            }
        }
        if ( 'waiting' === $stage ) {
            foreach ( Cowboy_MCP_OAuth::blocked_attempts() as $a ) {
                if ( ( ( $a['slug'] ?? null ) === $app || ( $a['app'] ?? '' ) === $out['label'] ) && (int) ( $a['at'] ?? 0 ) >= $since ) {
                    $stage = 'blocked';
                    break;
                }
            }
        }
        $out['stage']  = $stage;
        $out['reused'] = $reused;
        if ( $named ) {
            $out['label'] = $named;
        }
        return $out;
    }

    /** One sentence per stage; the app name is a proper noun injected as %s. */
    public static function connect_status_text( array $st ): string {
        $n = (string) ( $st['label'] ?? self::watch_label( (string) ( $st['app'] ?? '' ) ) );
        switch ( $st['stage'] ) {
            case 'registered':
                /* translators: %s: AI app name, e.g. Claude Code */
                return sprintf( __( '%s found your site — approve access on the sign-in page.', 'cowboy-mcp' ), $n );
            case 'approved':
                /* translators: %s: AI app name, e.g. Claude Code */
                return sprintf( __( 'Approved — waiting for the first request from %s.', 'cowboy-mcp' ), $n );
            case 'connected':
                if ( ! empty( $st['reused'] ) ) {
                    /* translators: %s: AI app name, e.g. Claude Code */
                    return sprintf( __( '✓ Connected — %s reused your earlier connection.', 'cowboy-mcp' ), $n );
                }
                /* translators: %s: AI app name, e.g. Claude Code */
                return sprintf( __( '✓ Connected — %s can now use this site.', 'cowboy-mcp' ), $n );
            case 'blocked':
                /* translators: %s: AI app name, e.g. Claude Code */
                return sprintf( __( '%s tried to connect, but new connections were off.', 'cowboy-mcp' ), $n );
            default:
                // "Pairing enabled" only while the 30-minute window is open: CIMD clients (Claude Code, Codex) and
                // API-key copies start a watch without opening it, and the line must not claim otherwise.
                if ( class_exists( 'Cowboy_MCP_OAuth' ) && Cowboy_MCP_OAuth::registration_seconds_left() > 0 ) {
                    /* translators: %s: AI app name, e.g. Claude Code */
                    return sprintf( __( 'Pairing enabled, waiting for %s…', 'cowboy-mcp' ), $n );
                }
                /* translators: %s: AI app name, e.g. Claude Code */
                return sprintf( __( 'Waiting for %s…', 'cowboy-mcp' ), $n );
        }
    }

    public static function render_connect_status(): void {
        $w = self::connect_watch();
        if ( ! $w ) {
            return;
        }
        $st   = self::connect_status( $w );
        $left = class_exists( 'Cowboy_MCP_OAuth' ) ? Cowboy_MCP_OAuth::registration_seconds_left() : 0;
        ?>
        <div class="cmcp-connect-status cmcp-connect-status--<?php echo esc_attr( $st['stage'] ); ?>" aria-live="polite" data-cmcp-connect-watch data-stage="<?php echo esc_attr( $st['stage'] ); ?>">
            <span class="cmcp-connect-status-text"><?php echo esc_html( self::connect_status_text( $st ) ); ?></span>
            <a class="cmcp-connect-status-refresh" href="<?php echo esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'connection' ] ) ); ?>" <?php echo 'connected' === $st['stage'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Refresh list', 'cowboy-mcp' ); ?></a>
            <?php if ( $left > 0 ) : // The New-connections window this click opened: visible only while it is open. ?>
                <span class="cmcp-connect-status-window" data-cmcp-conn-open>
                    <span><?php
                        /* translators: %s: countdown such as 29:59 */
                        printf( esc_html__( 'New connections open · %s left', 'cowboy-mcp' ), '<span data-mcp-lock-until="' . esc_attr( (string) ( time() + $left ) ) . '">' . esc_html( gmdate( 'i:s', $left ) ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise above.
                    ?></span>
                    <form method="post" class="mcp-inline-form">
                        <?php wp_nonce_field( 'cowboy_mcp_toggle_connections' ); ?>
                        <button type="submit" name="cowboy_mcp_toggle_connections" value="disable" class="cmcp-linkbtn"><?php esc_html_e( 'Disable pairing', 'cowboy-mcp' ); ?></button>
                    </form>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function client_registry(): array {
        // 'local' is how the client behaves against a local dev site: it either
        // works as-is ('works'), works through the on-machine mcp-remote bridge
        // ('bridge'), or is cloud-initiated and needs a public URL ('tunnel').
        // Display metadata only — never used for gating.
        return [
            'claude-ai'      => [ 'label' => 'claude.ai',      'group' => 'no-terminal', 'type' => 'oauth',      'watch' => 'claude',      'local' => 'tunnel' ],
            'claude-desktop' => [ 'label' => 'Claude Desktop', 'group' => 'no-terminal', 'type' => 'oauth',      'watch' => 'claude',      'local' => 'bridge' ],
            'chatgpt'        => [ 'label' => 'ChatGPT',        'group' => 'no-terminal', 'type' => 'oauth',      'watch' => 'chatgpt',     'local' => 'tunnel' ],
            // open_url: Grok verified from docs.x.ai 2026-10-07; the other two are candidates until Task 4 verifies them live (record URL + date here).
            'gemini-app'     => [ 'label' => 'Gemini',         'group' => 'no-terminal', 'type' => 'oauth-cloud', 'watch' => 'gemini-app',  'local' => 'tunnel', 'open_url' => 'https://gemini.google.com/apps',               'open_host' => 'gemini.google.com' ], // /apps = Connected Apps page with the Custom apps field (verified 2026-10-11)
            'grok'           => [ 'label' => 'Grok',           'group' => 'no-terminal', 'type' => 'oauth-cloud', 'watch' => 'grok',        'local' => 'tunnel', 'open_url' => 'https://grok.com/connectors',                  'open_host' => 'grok.com' ],
            'perplexity'     => [ 'label' => 'Perplexity',     'group' => 'no-terminal', 'type' => 'oauth-cloud', 'watch' => 'perplexity',  'local' => 'tunnel', 'open_url' => 'https://www.perplexity.ai/computer/connectors', 'open_host' => 'www.perplexity.ai' ],
            // Meta Muse (US). Verified live 2026-10-14: no Settings entry for custom connectors — Muse creates one when asked in chat; DCR name "Muse", callback agent.meta.ai/api/hatch/oauth/callback.
            'muse'           => [ 'label' => 'Muse',           'group' => 'no-terminal', 'type' => 'oauth-cloud', 'watch' => 'muse',        'local' => 'tunnel', 'open_url' => 'https://muse.ai/',                             'open_host' => 'muse.ai' ],
            // 'cimd': the client identifies itself with a Client ID Metadata Document, so it never
            // registers and the "New connections" window is irrelevant to it (verified 2026-10-09).
            'claude-code'    => [ 'label' => 'Claude Code',    'group' => 'terminal',    'type' => 'oauth-cli',  'watch' => 'claude-code', 'local' => 'works', 'cimd' => true ],
            'codex'          => [ 'label' => 'Codex',          'group' => 'terminal',    'type' => 'oauth-cli',  'watch' => 'codex',       'local' => 'works', 'cimd' => true ],
            'cursor'         => [ 'label' => 'Cursor',         'group' => 'terminal',    'type' => 'oauth-link', 'watch' => 'cursor',      'local' => 'works' ],
            'vscode'         => [ 'label' => 'VS Code',        'group' => 'terminal',    'type' => 'oauth-link', 'watch' => 'vscode',      'local' => 'works' ],
            'gemini-cli'     => [ 'label' => 'Gemini CLI',     'group' => 'terminal',    'type' => 'oauth-cli',  'watch' => 'gemini-cli',  'local' => 'works' ],
            'opencode'       => [ 'label' => 'Opencode',       'group' => 'terminal',    'type' => 'oauth-cli',  'watch' => 'opencode',    'local' => 'works' ],
            // Meta's terminal agent: no `mcp add` command, so the panel writes ~/.config/muse/settings.json and then runs `muse mcp login` (verified against Muse Code 1.4.8, 2026-10-14).
            'muse-code'      => [ 'label' => 'Muse Code',      'group' => 'terminal',    'type' => 'oauth-cli',  'watch' => 'muse-code',   'local' => 'works' ],
            'n8n'            => [ 'label' => 'n8n',            'group' => 'automation',  'type' => 'n8n',        'watch' => 'n8n',         'local' => 'works' ],
            // OpenClaw (self-hosted agent gateway): `openclaw mcp add … --auth oauth` + `openclaw mcp login`; DCR "OpenClaw MCP" on 127.0.0.1:8989 (verified live against openclaw 2026.10.4, 2026-10-16).
            'openclaw'       => [ 'label' => 'OpenClaw',       'group' => 'automation',  'type' => 'oauth-cli',  'watch' => 'openclaw',    'local' => 'works' ],
            // Hermes Agent (Nous Research): `hermes mcp add <name> --url … --auth oauth` signs in at once. It identifies itself with a
            // CIMD document on main (CIMD_DOCUMENT_HOSTS has nousresearch.github.io) and with DCR "Hermes Agent" on older builds, so
            // no 'cimd' flag: the Copy still opens the window for the DCR case (verified 2026-10-16).
            'hermes'         => [ 'label' => 'Hermes Agent',   'group' => 'automation',  'type' => 'oauth-cli',  'watch' => 'hermes',      'local' => 'works' ],
        ];
    }

    /** Whether this site's host looks like a local development site (advisory, UI only). */
    public static function site_looks_local(): bool {
        return Cowboy_MCP_Security::host_looks_local( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    }

    /**
     * Echo the inline SVG logo for a client (monochrome, fill = currentColor).
     * Marks: Claude, Cursor, Google Gemini, Perplexity, OpenCode and n8n from Simple Icons 16.34.0 (CC0 vectors of the
     * brands' own marks); OpenAI's blossom from OpenAI's official logo pack (cdn.openai.com/brand),
     * which also stands for Codex; Grok's mark from Lobe Icons 1.95.1 (MIT, @lobehub/icons-static-svg — Simple Icons
     * carries no Grok/xAI mark); Meta's mark (Simple Icons 16.34.0) for Muse and Muse Code. VS Code: a neutral code-brackets glyph (no brand mark);
     * OpenClaw's lobster and Hermes Agent's Nous mascot from Lobe Icons 1.95.1 (MIT; Simple Icons carries neither, 2026-10-17), coordinates re-rounded with svgo. Used only to identify the app
     * being connected; no endorsement.
     */
    private static function render_client_icon( string $slug ): void {
        $claude = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z"></path></svg>';
        $openai = '<svg viewBox="118.5 117.8 484.2 484.2" fill="currentColor"><path d="M304.246 294.611V249.028C304.246 245.189 305.687 242.309 309.044 240.392L400.692 187.612C413.167 180.415 428.042 177.058 443.394 177.058C500.971 177.058 537.44 221.682 537.44 269.182C537.44 272.54 537.44 276.379 536.959 280.218L441.954 224.558C436.197 221.201 430.437 221.201 424.68 224.558L304.246 294.611ZM518.245 472.145V363.224C518.245 356.505 515.364 351.707 509.608 348.349L389.174 278.296L428.519 255.743C431.877 253.826 434.757 253.826 438.115 255.743L529.762 308.523C556.154 323.879 573.905 356.505 573.905 388.171C573.905 424.636 552.315 458.225 518.245 472.141V472.145ZM275.937 376.182L236.592 353.152C233.235 351.235 231.794 348.354 231.794 344.515V238.956C231.794 187.617 271.139 148.749 324.4 148.749C344.555 148.749 363.264 155.468 379.102 167.463L284.578 222.164C278.822 225.521 275.942 230.319 275.942 237.039V376.186L275.937 376.182ZM360.626 425.122L304.246 393.455V326.283L360.626 294.616L417.002 326.283V393.455L360.626 425.122ZM396.852 570.989C376.698 570.989 357.989 564.27 342.151 552.276L436.674 497.574C442.431 494.217 445.311 489.419 445.311 482.699V343.552L485.138 366.582C488.495 368.499 489.936 371.379 489.936 375.219V480.778C489.936 532.117 450.109 570.985 396.852 570.985V570.989ZM283.134 463.99L191.486 411.211C165.094 395.854 147.343 363.229 147.343 331.562C147.343 294.616 169.415 261.509 203.48 247.593V356.991C203.48 363.71 206.361 368.508 212.117 371.866L332.074 441.437L292.729 463.99C289.372 465.907 286.491 465.907 283.134 463.99ZM277.859 542.68C223.639 542.68 183.813 501.895 183.813 451.514C183.813 447.675 184.294 443.836 184.771 439.997L279.295 494.698C285.051 498.056 290.812 498.056 296.568 494.698L417.002 425.127V470.71C417.002 474.549 415.562 477.429 412.204 479.346L320.557 532.126C308.081 539.323 293.206 542.68 277.854 542.68H277.859ZM396.852 599.776C454.911 599.776 503.37 558.513 514.41 503.812C568.149 489.896 602.696 439.515 602.696 388.176C602.696 354.587 588.303 321.962 562.392 298.45C564.791 288.373 566.231 278.296 566.231 268.224C566.231 199.611 510.571 148.267 446.274 148.267C433.322 148.267 420.846 150.184 408.37 154.505C386.775 133.392 357.026 119.958 324.4 119.958C266.342 119.958 217.883 161.22 206.843 215.921C153.104 229.837 118.557 280.218 118.557 331.557C118.557 365.146 132.95 397.771 158.861 421.283C156.462 431.36 155.022 441.437 155.022 451.51C155.022 520.123 210.682 571.466 274.978 571.466C287.931 571.466 300.407 569.549 312.883 565.228C334.473 586.341 364.222 599.776 396.852 599.776Z"></path></svg>';
        $meta   = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.915 4.03c-1.968 0-3.683 1.28-4.871 3.113C.704 9.208 0 11.883 0 14.449c0 .706.07 1.369.21 1.973a6.624 6.624 0 0 0 .265.86 5.297 5.297 0 0 0 .371.761c.696 1.159 1.818 1.927 3.593 1.927 1.497 0 2.633-.671 3.965-2.444.76-1.012 1.144-1.626 2.663-4.32l.756-1.339.186-.325c.061.1.121.196.183.3l2.152 3.595c.724 1.21 1.665 2.556 2.47 3.314 1.046.987 1.992 1.22 3.06 1.22 1.075 0 1.876-.355 2.455-.843a3.743 3.743 0 0 0 .81-.973c.542-.939.861-2.127.861-3.745 0-2.72-.681-5.357-2.084-7.45-1.282-1.912-2.957-2.93-4.716-2.93-1.047 0-2.088.467-3.053 1.308-.652.57-1.257 1.29-1.82 2.05-.69-.875-1.335-1.547-1.958-2.056-1.182-.966-2.315-1.303-3.454-1.303zm10.16 2.053c1.147 0 2.188.758 2.992 1.999 1.132 1.748 1.647 4.195 1.647 6.4 0 1.548-.368 2.9-1.839 2.9-.58 0-1.027-.23-1.664-1.004-.496-.601-1.343-1.878-2.832-4.358l-.617-1.028a44.908 44.908 0 0 0-1.255-1.98c.07-.109.141-.224.211-.327 1.12-1.667 2.118-2.602 3.358-2.602zm-10.201.553c1.265 0 2.058.791 2.675 1.446.307.327.737.871 1.234 1.579l-1.02 1.566c-.757 1.163-1.882 3.017-2.837 4.338-1.191 1.649-1.81 1.817-2.486 1.817-.524 0-1.038-.237-1.383-.794-.263-.426-.464-1.13-.464-2.046 0-2.221.63-4.535 1.66-6.088.454-.687.964-1.226 1.533-1.533a2.264 2.264 0 0 1 1.088-.285z"></path></svg>';
        $icons  = [
            'claude-ai'      => $claude,
            'claude-desktop' => $claude,
            'claude-code'    => $claude,
            'chatgpt'        => $openai,
            'codex'          => $openai,
            'opencode'       => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd"><path d="M22 24H2V0h20zM17 4.8H7v14.4h10z"></path></svg>',
            'cursor'         => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.503.131 1.891 5.678a.84.84 0 0 0-.42.726v11.188c0 .3.162.575.42.724l9.609 5.55a1 1 0 0 0 .998 0l9.61-5.55a.84.84 0 0 0 .42-.724V6.404a.84.84 0 0 0-.42-.726L12.497.131a1.01 1.01 0 0 0-.996 0M2.657 6.338h18.55c.263 0 .43.287.297.515L12.23 22.918c-.062.107-.229.064-.229-.06V12.335a.59.59 0 0 0-.295-.51l-9.11-5.257c-.109-.063-.064-.23.061-.23"></path></svg>',
            'vscode'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6 2 12l6 6M16 6l6 6-6 6M14 4l-4 16"/></svg>',
            'perplexity'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M22.3977 7.0896h-2.3106V.0676l-7.5094 6.3542V.1577h-1.1554v6.1966L4.4904 0v7.0896H1.6023v10.3976h2.8882V24l6.932-6.3591v6.2005h1.1554v-6.0469l6.9318 6.1807v-6.4879h2.8882V7.0896zm-3.4657-4.531v4.531h-5.355l5.355-4.531zm-13.2862.0676 4.8691 4.4634H5.6458V2.6262zM2.7576 16.332V8.245h7.8476l-6.1149 6.1147v1.9723H2.7576zm2.8882 5.0404v-3.8852h.0001v-2.6488l5.7763-5.7764v7.0111l-5.7764 5.2993zm12.7086.0248-5.7766-5.1509V9.0618l5.7766 5.7766v6.5588zm2.8882-5.0652h-1.733v-1.9723L13.3948 8.245h7.8478v8.087z"></path></svg>',
            'grok'           => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd"><path d="M9.27 15.29l7.978-5.897c.391-.29.95-.177 1.137.272.98 2.369.542 5.215-1.41 7.169-1.951 1.954-4.667 2.382-7.149 1.406l-2.711 1.257c3.889 2.661 8.611 2.003 11.562-.953 2.341-2.344 3.066-5.539 2.388-8.42l.006.007c-.983-4.232.242-5.924 2.75-9.383.06-.082.12-.164.179-.248l-3.301 3.305v-.01L9.267 15.292M7.623 16.723c-2.792-2.67-2.31-6.801.071-9.184 1.761-1.763 4.647-2.483 7.166-1.425l2.705-1.25a7.808 7.808 0 00-1.829-1A8.975 8.975 0 005.984 5.83c-2.533 2.536-3.33 6.436-1.962 9.764 1.022 2.487-.653 4.246-2.34 6.022-.599.63-1.199 1.259-1.682 1.925l7.62-6.815"/></svg>',
            'gemini-app'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.04 19.32Q12 21.51 12 24q0-2.49.93-4.68.96-2.19 2.58-3.81t3.81-2.55Q21.51 12 24 12q-2.49 0-4.68-.93a12.3 12.3 0 0 1-3.81-2.58 12.3 12.3 0 0 1-2.58-3.81Q12 2.49 12 0q0 2.49-.96 4.68-.93 2.19-2.55 3.81a12.3 12.3 0 0 1-3.81 2.58Q2.49 12 0 12q2.49 0 4.68.96 2.19.93 3.81 2.55t2.55 3.81"></path></svg>',
            'n8n'            => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.4737 5.6842c-1.1772 0-2.1663.8051-2.4468 1.8947h-2.8955c-1.235 0-2.289.893-2.492 2.111l-.1038.623a1.263 1.263 0 0 1-1.246 1.0555H11.289c-.2805-1.0896-1.2696-1.8947-2.4468-1.8947s-2.1663.8051-2.4467 1.8947H4.973c-.2805-1.0896-1.2696-1.8947-2.4468-1.8947C1.1311 9.4737 0 10.6047 0 12s1.131 2.5263 2.5263 2.5263c1.1772 0 2.1663-.8051 2.4468-1.8947h1.4223c.2804 1.0896 1.2696 1.8947 2.4467 1.8947 1.1772 0 2.1663-.8051 2.4468-1.8947h1.0008a1.263 1.263 0 0 1 1.2459 1.0555l.1038.623c.203 1.218 1.257 2.111 2.492 2.111h.3692c.2804 1.0895 1.2696 1.8947 2.4468 1.8947 1.3952 0 2.5263-1.131 2.5263-2.5263s-1.131-2.5263-2.5263-2.5263c-1.1772 0-2.1664.805-2.4468 1.8947h-.3692a1.263 1.263 0 0 1-1.246-1.0555l-.1037-.623A2.52 2.52 0 0 0 13.9607 12a2.52 2.52 0 0 0 .821-1.4794l.1038-.623a1.263 1.263 0 0 1 1.2459-1.0555h2.8955c.2805 1.0896 1.2696 1.8947 2.4468 1.8947 1.3952 0 2.5263-1.131 2.5263-2.5263s-1.131-2.5263-2.5263-2.5263m0 1.2632a1.263 1.263 0 0 1 1.2631 1.2631 1.263 1.263 0 0 1-1.2631 1.2632 1.263 1.263 0 0 1-1.2632-1.2632 1.263 1.263 0 0 1 1.2632-1.2631M2.5263 10.7368A1.263 1.263 0 0 1 3.7895 12a1.263 1.263 0 0 1-1.2632 1.2632A1.263 1.263 0 0 1 1.2632 12a1.263 1.263 0 0 1 1.2631-1.2632m6.3158 0A1.263 1.263 0 0 1 10.1053 12a1.263 1.263 0 0 1-1.2632 1.2632A1.263 1.263 0 0 1 7.579 12a1.263 1.263 0 0 1 1.2632-1.2632m10.1053 3.7895a1.263 1.263 0 0 1 1.2631 1.2632 1.263 1.263 0 0 1-1.2631 1.2631 1.263 1.263 0 0 1-1.2632-1.2631 1.263 1.263 0 0 1 1.2632-1.2632"/></svg>',
            'muse'           => $meta,
            'muse-code'      => $meta,
            'openclaw'       => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd"><path d="M9.05 7.1a.53.53 0 1 1 0 1.06.53.53 0 0 1 0-1.06m6.33 0a.53.53 0 1 1 0 1.06.53.53 0 0 1 0-1.06"/><path d="M16.88 1.91c.58-.27 1.14-.32 1.61-.03a.32.32 0 0 1-.32.54c-.23-.14-.55-.16-1.03.06q-.53.25-1.23.87a9.4 9.4 0 0 1 5.15 5.92 6 6 0 0 0-.7.19c-.58.01-1.18.2-1.5.73-.35.55-.28 1.28.06 2.07l.01.01v.02a4.8 4.8 0 0 0 2.2 2.28c-.9 3.27-3.43 6.14-5.97 6.99v2.1h-2.1v-2.1a3 3 0 0 1-2.12 0v2.1h-2.1v-2.1c-2.54-.85-5.07-3.72-5.98-6.99a4.7 4.7 0 0 0 2.2-2.28v-.02l.01-.01c.34-.8.41-1.52.08-2.07-.33-.53-.93-.72-1.5-.73a6 6 0 0 0-.71-.19 9.4 9.4 0 0 1 5.15-5.92 5 5 0 0 0-1.24-.87c-.47-.22-.79-.2-1.02-.06a.3.3 0 0 1-.43-.11.3.3 0 0 1 .1-.44c.48-.28 1.04-.23 1.62.04q.74.35 1.62 1.18A10 10 0 0 1 12 2.57q1.8.01 3.26.52a7 7 0 0 1 1.62-1.18M8.84 6.58a1.27 1.27 0 1 0 0 2.53 1.27 1.27 0 0 0 0-2.53m6.33 0a1.27 1.27 0 1 0 0 2.53 1.27 1.27 0 0 0 0-2.53"/><path d="M.4 13.12c-.97-1.93-.17-3.86 2.4-3.37l.06.01.26.06.1.03.25.08.09.02c.53 0 .95.16 1.16.5.21.35.21.87-.1 1.61l-.28.5h-.01a5 5 0 0 1-.62.8l-.02.02c-1.1 1.12-2.5 1.34-3.3-.26M21.2 9.75c2.57-.5 3.37 1.44 2.4 3.37-.58 1.16-1.47 1.36-2.34.96l-.12-.06-.02-.01-.12-.07-.02-.01-.1-.07-.06-.04c-.55-.38-1.06-1-1.44-1.76q-.38-.96-.17-1.47c.18-.44.64-.64 1.23-.64l.1-.03.24-.07.11-.03z"/></svg>',
            'hermes'         => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd"><path d="m6 12.8.3.2v.1zm2.4-3.4h.7l.6.2h.1-.6L9 9.4l.6.1.8.5h.1l.1.1h.3q0 .2.1.1 0 .2-.3.2v.2q.1.2-.4.2v.1h-.1v.2H10v.1h-.3v.1H8.3Q8.2 11 8 11h.2l.5.1h.6q.5 0 .7-.4l.2-.2-.4-.3q0 .2-.2.2v.3l-.2.2h-.6l-.2-.2v-.2q-.2 0-.1-.4V10l-.4.4h-.2v-.2l.1-.4h-.2q.2-.3.6-.5M8 10.8"/><path d="M12 0h1.4l2.5.9a7 7 0 0 1 3 2.6l.7 1.5.7 1.7.4 2 .3 1.5.5 2.1 1.2 4.8V19l-.2 1.2-1 1.6v.2l.6-.6q.6-.7 1-1.5l.3-1.7q0-.6-.3-1.2v-.1h.1l.3.4q.5.7.5 1.7a4 4 0 0 1-.5 2q-.5.9-1.5 1.4l-.6.3-.3.4v.6l-.2.1-.1-.1v-.2l-.4-.9-.7-1-1-.4-.6-.2h-.2l.5.2a4 4 0 0 1 2 1.6l.2.5v.6h-.1l-.1-.2-.1-.2-1.4-1.7-.6-.3-1.1-.2h-.6l-.8.4-.8.7-1 1-.3.6v.1h-.4v-.4q0-.5.4-1l.1.1 1-1.1.9-.6h-.1l-.5.2q-.6.1-1.1.5l-.4.4-1 1.7-.1.1v.1h-.6l.4-.5.7-.9.1-.1.8-1 .3-.2H14l-.7.5-1.2.7-1 .5.1-.3-.4.3-.4.5-.4.4-.1.1H8.8v-.3h-.2v.3h-.2l.2-.3q-.4 0-.6.3H5.2l.5-.7q.6-.7.7-1.6l-.4.4q-.4.5-1 .5l-.9-.1q-.6-.3-.7-1.1 0-.2-.2-.2L2 20.6a2 2 0 0 1-.8-1.1L1 18.2l.2.5q0 .4.5.9l.9.5h1l.7-.5q.5-.4.6-1.2v-.1.2q-.6.8-1.3 1.1-.5.3-1 .2l-1-.7-.4-.9v-.9c0-.4.5-1.2.7-1.3v.2l-.1.3-.1.9.2.6q.3.4.6.4h.8l.3-.6q.3-.5.3-1.1l.2-1V15l.1-.7.1-.7V13l.1-.5V12l.1-.7V11l-.6-.4-.2-.4h-.2l.3.5.2.3.2.2H4a3 3 0 0 1-1.3-.9Q2 9.7 2 8.7v-1q0-1 .4-1.8L3 4.8l.7-1q.8-.9 1.7-1.5A13 13 0 0 1 11.3 0zm-2 22.8-.2.2-.6.9.5-.5.3-.4zm1.7-3.7-.7.6-.3.4-.4.3h-.6v.2l.5.7.4.7.1.4h.2l.9-.5.6-.3 1-.7h.1l-.8-.5-.5-.5-.4-.6zm-5.9.3-.1.3-.5 1q-.3.4-.8.5h-.9v.2l.5.2h.5l.4-.2q.4-.3.6-.7l.3-1.1zm15-4.6v.6l.2.6v1l-.2 1-.4 1.5v.1l.3-.3q.5-.5.6-1 .3-.7.1-1.6 0-.9-.6-1.9m-15-6.5.4.8v.2l-.9.2.2.1v.2l.5.4.2.6q-.2.8-.7 1.2h-.2l-.1.3v.2l.4.5.2.3v.7l.1.1h.5l.5.3.4.2h.3v.2l-.3.1H7h.2q-.5-.3-.9-.1v.3q-.2-.1 0-.3a.2.2 0 0 0-.3.2l.2.2h.5l-.3.2v.1h-.2l-.2-.2v-.6q-.1 0 0-.2h-.1l-.2-.4v-.4l.1-.1v-.2l-.1-.4-.5-.5-.1.6.2 1q.1.8.5 1.2l1 1.8q.3.3.7.3H8l.9-.2h.5l.6.2 1 1.3q0 .2.1 0l.4-.3v-2.1l.4-2.3.3-1.5.2-1.9v-1q0-.2-.2-.2l-.6-.1q-.2 0-.2-.2v-.2l-.1.2-.1.1H9.4L9.2 9q0-.1-.2 0l-.5.1-.7.4-.3.2h-.2v-.2l.3-.3H7q-.2.2-.4 0L6 8.4zm8.6 9h-.2l-.6.4-.3.2v.6l.3.1h.3q.4 0 .6-.3v-.9zm-.3-2.5-.3 1-.4 1.3.8-.5zm3.1-8 .1.2.3.7.5 2 .6 1.9.3 1.1.2.8.1.6.1.4V14l-.1-.6v-.2L19 12l-.6-2-.5-1.7-.2-.7-.1-.4-.1-.3zm-11 7.5.4.1zm-1-3.5v.8q.1.3.4.1l-.1-.3v-.2l-.2-.2zm0-1-.4.1v.5h.6l.5.2.1.1-.1-.3H5q.4-.2.7 0 0-.2-.2-.3l-.3-.1zm10.3.3-.6.3.2.1h1.4l-.5-.3zM8 6.5l.2 1 .5 1v.2H9l-.4-1q-.4-.6-.6-1.3m2.4-6h-.6L9 .7l-.9.3H10l1.3.6a6 6 0 0 1 2.3 2.7l.7 2.4.1.2q.2 0 .3.2l-.2.3-.2.3.3-.3.1-.3v-.2l-.1-.1q-.2 0-.2-.2t.2-.4h.3l.5-.1q.2 0 .2.2l-.1.3h-.1a.1.1 0 0 0 0 .2l.2.4.6.3h.1l-.6-.3-.1-.1-.2-.2.2-.2.1-.2-.2-1-.8-2-1.4-1.8-1.2-.8-1-.3zM4.1 3.9 4 4l-.4.4-.2.2q-.4.3-.6.9v.2H3V6l-.2.5L3 6l1-2zm.1.6-.2.2h-.2q-.2 0-.3.2l-.2.6-.1.3h.4v.3l-.1.1v-.1l.3-.4.1-.1V6l.3-.1v.2l.5-1h-.4l.1-.5q0 .3-.3.1v-.3zm2-.7-.2.4-.5.7q-.1.3-.6.5v-.1l.2-.6L5 5l-.5 1h.1q.2 0 .3-.2l.1-.1.2-.1h.1v.1l.1.2V6L6 4.6zm4-.3.2.6v.4l-.1-.1h-.1v.2l-.3.2h-.1v-.1h-.2l-.4.2v.4l.1.1q.3 0 .7.3l.1-.2h.3v.2-.4l.4.6v-.6h.1l.2.5v-.4h.3c0-.2-.4-1-.8-1.5zm-2.6.7-.2.6-.2.2h-.6l-.4-.2H6v.7h.8l.5-.2h.1l.1.2v-.2l.2.2zm.3.3.1.6h.9l.2.2h.1V5l-.3-.6zm10-1.8.2.5v.5H18l-.2-.2-.3-.3h-.1l.2.3v.2h-.3l-.2.1V4q.2.2 0 .2l-.1.2h-.2l-.7-.1L16 4l-.1-.2v.5h-.6v.2h.3l.5.3.2.3v-.3l.1.1.2.3v.1h.1l-.4-1 1 .4.2-.2h.1l.3.5.1.3V5l-.3-.6q.3.1.6.8l-.1-.5v-.3h.1v-.1l.3.3-.2-.5h.2v-.8l.3.4V4l-.3-.6zm-6.5.3v.4l-.3.1-.1.4-.1.1v.2l.1.2.1.1h.3l.2.1.2-.1h.3v-.3h.1v-.7q.2 0 .3.3l.5.8h.4l-.2-.4H13l-.5-.9-.3-.2-.3.3h-.2z"/></svg>',
            'gemini-cli'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.04 19.32Q12 21.51 12 24q0-2.49.93-4.68.96-2.19 2.58-3.81t3.81-2.55Q21.51 12 24 12q-2.49 0-4.68-.93a12.3 12.3 0 0 1-3.81-2.58 12.3 12.3 0 0 1-2.58-3.81Q12 2.49 12 0q0 2.49-.96 4.68-.93 2.19-2.55 3.81a12.3 12.3 0 0 1-3.81 2.58Q2.49 12 0 12q2.49 0 4.68.96 2.19.93 3.81 2.55t2.55 3.81"></path></svg>',
        ];
        echo $icons[ $slug ] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literals defined directly above; no user input.
    }

    /** One callout for sites the cloud apps cannot reach (local, private or plain http); the sidebar hints already say which clients work locally. */
    private static function render_unreachable_callout( string $slug ): void {
        if ( 'chatgpt' === $slug ) {
            $text = __( 'This site does not appear to be on a public HTTPS address, so ChatGPT cannot reach it from OpenAI\'s cloud. Use a terminal tool from the sidebar instead, or set the WordPress Site Address to a tunnel or staging URL.', 'cowboy-mcp' );
        } elseif ( 'oauth-cloud' === ( self::client_registry()[ $slug ]['type'] ?? '' ) ) {
            $label = (string) ( self::client_registry()[ $slug ]['label'] ?? $slug );
            /* translators: %s: AI app name, e.g. Perplexity */
            $text = sprintf( __( 'This site does not appear to be on a public HTTPS address, so %s cannot reach it from the vendor\'s cloud. Use a terminal tool from the sidebar instead, or set the WordPress Site Address to a tunnel or staging URL.', 'cowboy-mcp' ), $label );
        } else {
            $text = __( 'This site does not appear to be on a public HTTPS address, so the Claude apps cannot reach it from Anthropic\'s cloud. Use a terminal tool or Claude Desktop from the sidebar instead, or set the WordPress Site Address to a tunnel or staging URL.', 'cowboy-mcp' );
        }
        ?>
        <div class="cmcp-callout"><p><?php echo esc_html( $text ); ?></p></div>
        <?php
    }

    /** Collapsed under "Approve access": the re-connect hint, the plan notes and (public sites) the organization-wide install link. */
    private static function render_claude_plan_details( bool $oauth_on, bool $org ): void {
        ?>
        <details class="mcp-local-details">
            <summary><?php esc_html_e( 'Already added, or on a Team or Enterprise plan?', 'cowboy-mcp' ); ?></summary>
            <div class="mcp-local-details-body">
                <?php if ( ! $oauth_on ) { self::render_enable_first(); } ?>
                <p><?php echo wp_kses( __( 'Already added? Claude reports that a connector with this URL already exists. Find it under <strong>Customize → Connectors</strong> and click <strong>Connect</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                <p><?php echo wp_kses( __( 'Custom connectors work on every Claude plan (Free is limited to one). On Team and Enterprise, an organization owner adds the connector first under <strong>Organization settings → Connectors</strong>; members then click <strong>Connect</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                <?php if ( $org ) : ?>
                    <div class="cmcp-org-add"><?php esc_html_e( 'Organization owner on Team or Enterprise?', 'cowboy-mcp' ); ?>
                        <?php self::render_add_to_claude_form( 'org', 'cmcp-linkbtn', __( 'Add it for your organization', 'cowboy-mcp' ) ); ?></div>
                <?php endif; ?>
            </div>
        </details>
        <?php
    }

    private static function render_oauth_client_panel( string $slug, string $endpoint, bool $is_local = false, array $keys = [], $new_key = null ): void {
        if ( $is_local && 'claude-desktop' === $slug ) {
            // Local site: the cloud connector cannot reach it, but the
            // on-machine mcp-remote bridge can. Bridge first, connector
            // collapsed for the "site is actually public" case.
            self::render_desktop_bridge_flow( $endpoint, $keys, $new_key );
            ?>
            <details class="mcp-local-details">
                <summary><?php esc_html_e( 'Site actually on a public HTTPS address? Use the cloud connector instead', 'cowboy-mcp' ); ?></summary>
                <div class="mcp-local-details-body">
                    <?php self::render_oauth_connector_flow( $slug, $endpoint, false ); ?>
                </div>
            </details>
            <?php
            return;
        }
        if ( $is_local ) {
            self::render_unreachable_callout( $slug );
            self::render_oauth_connector_flow( $slug, $endpoint, false );
            return;
        }
        self::render_oauth_connector_flow( $slug, $endpoint );
    }

    private static function render_oauth_connector_flow( string $slug, string $endpoint, bool $show_warning = true ): void {
        $oauth_avail = class_exists( 'Cowboy_MCP_OAuth' );
        // Read the option directly instead of Cowboy_MCP_OAuth::is_enabled(): that
        // helper memoizes settings at request start, so it still reports "off" in
        // the very response that enable_oauth_connector() just turned it on.
        $settings    = get_option( 'cowboy_mcp_settings', [] );
        $oauth_on    = $oauth_avail && ! empty( $settings['enabled'] ) && ! empty( $settings['oauth_enabled'] );
        $reachable   = ! $oauth_avail || Cowboy_MCP_OAuth::site_is_publicly_reachable();
        $is_desktop  = ( $slug === 'claude-desktop' );
        $is_chatgpt  = ( $slug === 'chatgpt' );

        // Whole sentences per client (not sprintf'd names) so translators get
        // complete, grammatical strings.
        $approve_text = $is_chatgpt
            ? __( 'ChatGPT opens a sign-in page on <strong>your site</strong>. Click <strong>Approve</strong>. Back in ChatGPT, review the tools it found and install the plugin; in a chat, pick it from the <strong>+</strong> menu or type <strong>@</strong> and its name.', 'cowboy-mcp' )
            : __( 'Claude opens a sign-in page on <strong>your site</strong>. Click <strong>Approve</strong>, then switch the connector on under <strong>+ → Connectors</strong> in a chat.', 'cowboy-mcp' );

        if ( $show_warning && ! $reachable ) {
            self::render_unreachable_callout( $slug );
        }

        // Claude's install link only works where Claude can reach the site: public HTTPS
        // (its dialog rejects http://, and it connects from Anthropic's cloud).
        $quick         = ! $is_chatgpt && $oauth_avail && $reachable;
        $chatgpt_quick = $is_chatgpt && $oauth_avail && $reachable;

        if ( $quick ) :
            ?>
            <?php self::step_open( '1', __( 'Add it to Claude', 'cowboy-mcp' ), '' ); ?>
                    <?php self::render_add_to_claude_form( 'me', 'cmcp-btn cmcp-btn--primary', __( 'Add to Claude', 'cowboy-mcp' ) ); ?>
                    <p><?php
                        echo wp_kses(
                            $is_desktop
                                ? __( 'Opens claude.ai with this site filled in. Check the address, then click <strong>Continue</strong> and <strong>Add</strong>. The connector shows up in the Claude app too.', 'cowboy-mcp' )
                                : __( 'Opens claude.ai with this site filled in. Check the address, then click <strong>Continue</strong> and <strong>Add</strong>.', 'cowboy-mcp' ),
                            [ 'strong' => [] ]
                        );
                    ?></p>
                    <details class="mcp-local-details">
                        <summary><?php esc_html_e( 'Add it by hand instead', 'cowboy-mcp' ); ?></summary>
                        <div class="mcp-local-details-body">
                            <?php self::render_link_code( $slug, $endpoint ); ?>
                            <?php foreach ( self::claude_manual_sentences( $is_desktop, true ) as $sentence ) : ?><p><?php echo $sentence; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in claude_manual_sentences(). ?></p><?php endforeach; ?>
                        </div>
                    </details>
            <?php self::step_close(); ?>

            <?php self::step_open( '2', __( 'Approve access', 'cowboy-mcp' ), '' ); ?>
                    <p><?php echo wp_kses( $approve_text, [ 'strong' => [] ] ); ?></p>
                    <?php self::render_claude_plan_details( $oauth_on, true ); ?>
            <?php self::step_close(); ?>
            <?php
            self::render_try_step( '3', $slug );
            return;
        endif;
        ?>
        <?php self::step_open( '1', __( 'Copy your connection link', 'cowboy-mcp' ), '' ); ?>
                <?php self::render_link_code( $slug, $endpoint ); ?>
        <?php self::step_close(); ?>

        <?php
        // One flat list, one literal action per step (owner, 2026-10-16: no nested numbered lists).
        if ( $is_chatgpt ) :
            /* translators: %s: a web address, e.g. chatgpt.com */
            self::step_open( '2', sprintf( __( 'Go to %s', 'cowboy-mcp' ), 'chatgpt.com' ), '' );
            if ( $chatgpt_quick ) {
                self::render_open_app_form( 'cowboy_mcp_open_chatgpt', '1', 'ChatGPT' );
            }
            ?>
                <p><?php esc_html_e( 'Sign in. Custom MCP servers work in ChatGPT on the web only.', 'cowboy-mcp' ); ?></p>
            <?php
            self::step_close();
            // No Developer mode step any more: ChatGPT adds custom servers straight from Plugins behind a per-server risk
            // acknowledgement (help.openai.com article 12584461; live-checked 2026-10-18: Plugins → Add ▾ → Add custom MCP server,
            // fields Name / Server URL / Authentication = OAuth, checkbox, "Create as a plugin"; the plugin then sits in the chat's + menu).
            self::step_open( '3', __( 'Paste the link', 'cowboy-mcp' ), '' );
            ?>
                <p><?php
                    echo wp_kses( __( 'Go to <code>Plugins</code>, click <strong>Add</strong> and choose <strong>Add custom MCP server</strong>.', 'cowboy-mcp' ), [ 'code' => [], 'strong' => [] ] );
                    echo ' ';
                    echo wp_kses( __( 'Name it, paste the link as the <strong>Server URL</strong>, keep Authentication on <strong>OAuth</strong>, tick the risk acknowledgement and click <strong>Create as a plugin</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] );
                ?></p>
            <?php
            self::step_close();
            $approve_num = '4';
        else :
            $claude = self::claude_manual_sentences( $is_desktop );
            /* translators: %s: a web address, e.g. claude.ai */
            self::step_open( '2', $is_desktop ? __( 'Open the Claude app', 'cowboy-mcp' ) : sprintf( __( 'Go to %s', 'cowboy-mcp' ), 'claude.ai' ), '' );
            ?>
                <p><?php echo $claude[0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in claude_manual_sentences(). ?></p>
            <?php
            self::step_close();
            self::step_open( '3', __( 'Paste the link', 'cowboy-mcp' ), '' );
            ?>
                <p><?php echo $claude[1] . ' ' . $claude[2]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in claude_manual_sentences(). ?></p>
            <?php
            self::step_close();
            $approve_num = '4';
        endif;
        ?>

        <?php self::step_open( $approve_num, __( 'Approve access', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo wp_kses( $approve_text, [ 'strong' => [] ] ); ?></p>
                <?php if ( $is_chatgpt ) : ?>
                    <details class="mcp-local-details">
                        <summary><?php esc_html_e( 'Which ChatGPT plans work?', 'cowboy-mcp' ); ?></summary>
                        <div class="mcp-local-details-body"><p><?php echo wp_kses( __( 'Custom MCP servers need a ChatGPT <strong>Business, Enterprise or Edu</strong> workspace, on the web; Pro can connect one for read-only use. On Business only workspace admins and owners can add it; on Enterprise and Edu an admin grants that permission by role.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p></div>
                    </details>
                <?php else : ?>
                    <?php self::render_claude_plan_details( $oauth_on, false ); ?>
                <?php endif; ?>
        <?php self::step_close(); ?>
        <?php
        self::render_try_step( (string) ( (int) $approve_num + 1 ), $slug );
    }

    /**
     * Per-app sentences for the cloud-connector panels, one per flat step: 'go' = the address step 2 names (plain text),
     * 'go_note' = what to do there, 'paste' = where the snippet goes, 'connect' = the click that starts the sign-in.
     * Whole sentences; %s is only ever the app name elsewhere.
     * 'top' = short hard requirement shown as a callout above step 1 (Perplexity's paid plan); 'plan' = tier note; 'after' = what the app shows once the admin clicks Approve (optional).
     * Gemini verified live 2026-10-11 (personal Google account on Google AI Plus; a Workspace account has no
     * Custom apps section). Perplexity/Grok wording is from the 2026-10-07 research until their live sign-ins.
     */
    private static function cloud_copy( string $slug ): array {
        return match ( $slug ) {
            'perplexity' => [
                'go'      => 'perplexity.ai',
                'go_note' => wp_kses( __( 'Sign in, then open <strong>Customize → Connectors</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'paste'   => wp_kses( __( 'Click <strong>+ Custom connector</strong> and choose <strong>Add MCP connector</strong>. Give it a name, paste the link as the <strong>MCP server URL</strong> (Advanced already says OAuth and Streamable HTTP), tick the acknowledgement and click <strong>Add</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'connect' => wp_kses( __( 'Open the new connector and click <strong>Add connector</strong> — Perplexity opens the sign-in in a popup.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'top'   => wp_kses( __( '<strong>Needs Perplexity Pro, Max or Enterprise.</strong> The free plan has no custom connectors.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'plan'  => esc_html__( 'Verified on Pro (2026-10-12). On Enterprise, an admin has to allow member custom connectors first.', 'cowboy-mcp' ),
                'after' => wp_kses( __( 'Back in Perplexity, the connector shows a check mark. Ask a question that names it; Perplexity asks you to reply <code>yes</code> before its first tool run.', 'cowboy-mcp' ), [ 'code' => [] ] ),
            ],
            'grok' => [
                'go'      => 'grok.com/connectors',
                'go_note' => '',
                'paste'   => wp_kses( __( 'Click <strong>New Connector</strong>, choose <strong>Custom</strong>, give it a name and paste the link as the <strong>Server URL</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'connect' => wp_kses( __( 'Click <strong>Add Connector</strong> — Grok opens the sign-in in a popup.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'plan'  => esc_html__( 'Works on the free plan (verified 2026-10-12) as well as SuperGrok and X Premium+. On Business and Enterprise workspaces an admin provisions the connector first.', 'cowboy-mcp' ),
                'after' => wp_kses( __( 'Back in Grok, the connector shows as <strong>Added</strong>. In a chat, type <code>@</code> to pick it.', 'cowboy-mcp' ), [ 'strong' => [], 'code' => [] ] ),
            ],
            'gemini-app' => [
                'go'      => 'gemini.google.com/apps',
                'go_note' => wp_kses( __( 'Sign in to Gemini with a <strong>personal</strong> Google account — work and school accounts do not get Custom apps.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'paste'   => wp_kses( __( 'Under <strong>Settings → Connected Apps → Custom apps</strong>, paste the link into <strong>Add a custom app link</strong> and click <strong>Next</strong> twice.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'connect' => wp_kses( __( 'Tick the acknowledgement, click <strong>Connect</strong>, then <strong>Agree and continue</strong> — Gemini opens the sign-in page.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                'plan'  => esc_html__( 'Google lists no plan requirement (verified on a Google AI Plus account). You need a personal Google account in the United States, 18 or over, with Gemini in English and Keep Activity on; work and school accounts do not get Custom apps.', 'cowboy-mcp' ),
                'after' => wp_kses( __( 'Back in Gemini, click <strong>Connect</strong> on the “Save your custom app” card. In a chat, type <code>@</code> to pick the app; Gemini asks you to allow its first tool run.', 'cowboy-mcp' ), [ 'strong' => [], 'code' => [] ] ),
            ],
            // Verified live 2026-10-14 on muse.ai (US account): the connector is created from a chat request, Muse
            // asks to approve every request it sends to the site, and Cloudflare's Browser Integrity Check answers
            // Muse's server-side MCP client with error 1010 (sign-in and token exchange pass; MCP calls do not).
            'muse' => [
                'go'      => 'muse.ai',
                'go_note' => esc_html__( 'Or open the Muse app.', 'cowboy-mcp' ),
                'paste'   => esc_html__( 'Paste it into a new chat and send it. If Muse asks to contact your site, allow it.', 'cowboy-mcp' ),
                'connect' => wp_kses( __( 'Click <strong>Connect</strong> on the connector card, then <strong>Connect</strong> again in the dialog — Muse opens the sign-in page in a new tab.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                // Muse has no form or link for custom connectors (Settings → Connectors is Meta's catalog; the web app's
                // only "Add custom connector" strings title the credential dialogs of chat-created connectors, and it
                // reads no prompt parameter from the URL — checked 2026-10-16). A chat message creates one, so the panel
                // hands over the exact message that worked live on 2026-10-14 instead of the bare link.
                'message' => self::muse_connector_message(),
                'plan'  => esc_html__( 'Meta offers Muse to adults in the United States. Meta does not review custom connectors, so add only sites you manage.', 'cowboy-mcp' ),
                'after' => wp_kses( __( 'Muse asks you to approve each request it sends to your site (<strong>Allow once</strong> or <strong>Always allow this site</strong>).', 'cowboy-mcp' ), [ 'strong' => [] ] ),
                // Shown only when this admin page came through Cloudflare (CF-Ray): everyone else would read a warning that does not apply.
                'cloudflare' => wp_kses( __( 'This site is behind Cloudflare: its Browser Integrity Check blocks Muse with error 1010. Turn it off for <code>/wp-json/cowboy-mcp/</code>.', 'cowboy-mcp' ), [ 'code' => [] ] ),
            ],
            default => [ 'go' => '', 'go_note' => '', 'paste' => '', 'connect' => '', 'plan' => '' ],
        };
    }

    /** Cloud assistants (Perplexity, Grok, Gemini): Open button → add-it steps → Approve. $is_local is accepted for signature parity and unused. */
    private static function render_cloud_connector_panel( string $slug, array $def, string $endpoint, bool $is_local ): void {
        $label       = (string) $def['label'];
        $oauth_avail = class_exists( 'Cowboy_MCP_OAuth' );
        $reachable   = $oauth_avail && Cowboy_MCP_OAuth::site_is_publicly_reachable();
        $copy        = self::cloud_copy( $slug );

        if ( ! $reachable ) {
            self::render_unreachable_callout( $slug );
        }
        if ( ! empty( $copy['top'] ) ) : // a hard requirement the admin must know before step 1 (owner ask 2026-10-12: Perplexity's paid plan)
            ?>
            <div class="cmcp-callout cmcp-callout--warn"><p><?php echo $copy['top']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cloud_copy(). ?></p></div>
            <?php
        endif;
        // Step 1 is the snippet itself (the link, or Muse's chat message), on screen from the start: a button that copied
        // it silently left people not knowing they had anything to paste. Its Copy button starts a watched connection
        // (data-cmcp-start → ajax_start_connect(): browser sign-in on + the 30-minute window open). Open <app> belongs
        // to step 2, where the app is used — in step 1 it read as the way to copy (owner, 2026-10-16).
        $message = (string) ( $copy['message'] ?? '' );
        if ( '' !== $message ) {
            /* translators: %s: AI app name */
            self::step_open( '1', sprintf( __( 'Copy the message for %s', 'cowboy-mcp' ), $label ), '' );
            self::render_message_code( $slug, $message, $label );
        } else {
            self::step_open( '1', __( 'Copy your connection link', 'cowboy-mcp' ), '' );
            self::render_link_code( $slug, $endpoint );
        }
        self::step_close();

        // One flat list, one literal action per step — go, paste, connect, approve (owner, 2026-10-16: no nested lists).
        /* translators: %s: a web address, e.g. grok.com/connectors */
        self::step_open( '2', sprintf( __( 'Go to %s', 'cowboy-mcp' ), (string) $copy['go'] ), '' );
        if ( $reachable ) {
            self::render_open_app_form( 'cowboy_mcp_open_app', $slug, $label );
        }
        if ( '' !== $copy['go_note'] ) :
            ?><p><?php echo $copy['go_note']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cloud_copy(). ?></p><?php
        endif;
        self::step_close();
        self::step_open( '3', '' !== $message ? __( 'Paste the message', 'cowboy-mcp' ) : __( 'Paste the link', 'cowboy-mcp' ), '' );
        ?><p><?php echo $copy['paste']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cloud_copy(). ?></p><?php
        self::step_close();
        self::step_open( '4', __( 'Connect it', 'cowboy-mcp' ), '' );
        ?><p><?php echo $copy['connect']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cloud_copy(). ?></p><?php
        self::step_close();
        self::step_open( '5', __( 'Approve access', 'cowboy-mcp' ), '' );
        ?>
                <p><?php
                    /* translators: %s: AI app name */
                    echo wp_kses( sprintf( __( '%s opens a sign-in page on <strong>your site</strong>. Review what it is asking for and click <strong>Approve</strong>.', 'cowboy-mcp' ), esc_html( $label ) ), [ 'strong' => [] ] );
                ?></p>
                <?php if ( ! empty( $copy['after'] ) ) : ?><p><?php echo $copy['after']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cloud_copy(). ?></p><?php endif; ?>
                <?php if ( ! empty( $copy['cloudflare'] ) && class_exists( 'Cowboy_MCP_Doctor' ) && Cowboy_MCP_Doctor::request_via_cloudflare() ) : ?><p><?php echo $copy['cloudflare']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cloud_copy(). ?></p><?php endif; ?>
                <details class="mcp-local-details">
                    <summary><?php
                        /* translators: %s: AI app name */
                        echo esc_html( sprintf( __( 'Which %s plans work?', 'cowboy-mcp' ), $label ) );
                    ?></summary>
                    <div class="mcp-local-details-body"><p><?php echo $copy['plan']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cloud_copy(). ?></p></div>
                </details>
        <?php
        self::step_close();
        self::render_try_step( '6', $slug );
    }

    /**
     * The chat message that makes Muse create a custom connector for this site. English on purpose: it is an
     * instruction for the assistant (like tool descriptions), and the wording is the one verified live on 2026-10-14
     * (Muse built the connector card from it on the first try). The name is the site title, else the host.
     */
    private static function muse_connector_message(): string {
        $name = trim( wp_strip_all_tags( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) ) );
        if ( '' === $name ) {
            $name = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        }
        $endpoint = class_exists( 'Cowboy_MCP_OAuth' ) ? Cowboy_MCP_OAuth::resource_url() : rest_url( 'cowboy-mcp/v1/endpoint' );
        return 'Create a Custom Connector for a remote MCP server. Name: ' . $name . '. Transport: remote streamable HTTP. URL: ' . $endpoint . '. Auth: OAuth (the server supports dynamic client registration).';
    }

    /** The copyable chat message (cloud apps that create connectors from a chat message: Muse). Copying it starts a watched connection. */
    private static function render_message_code( string $slug, string $message, string $label ): void {
        $title = sprintf(
            /* translators: %s: AI app name */
            __( 'Message for %s', 'cowboy-mcp' ),
            $label
        );
        self::render_paste_field( 'mcp-oauth-msg-' . $slug, $message, $title, __( 'Copy message', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] );
    }

    /** The connection link the admin pastes into a cloud app. Copying it starts a watched connection (sign-in on, window open). */
    private static function render_link_code( string $slug, string $endpoint ): void {
        self::render_paste_field( 'mcp-oauth-url-' . $slug, $endpoint, __( 'Connection link', 'cowboy-mcp' ), __( 'Copy connector URL', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] );
    }

    /**
     * "Open <app>" in step 2 ("Go to <address>"): a new tab to the app's connectors page. It copies nothing (step 1's snippet is
     * copied on purpose); handle_actions() turns sign-in on, opens the window and starts the watch on its own too.
     */
    private static function render_open_app_form( string $action, string $value, string $label ): void {
        ?>
        <form method="post" target="_blank" action="<?php echo esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'connection', 'connect' => '1' ] ) ); ?>" class="mcp-inline-form cmcp-open-app" data-cmcp-refresh-after-submit>
            <?php wp_nonce_field( $action ); ?>
            <button type="submit" name="<?php echo esc_attr( $action ); ?>" value="<?php echo esc_attr( $value ); ?>" class="cmcp-btn cmcp-btn--primary"><?php
                /* translators: %s: AI app name */
                echo esc_html( sprintf( __( 'Open %s', 'cowboy-mcp' ), $label ) );
                echo Cowboy_MCP_Admin::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
            ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'cowboy-mcp' ); ?></span></button>
        </form>
        <?php
    }

    /** Manual connect paths with the connector off: the same Enable form, so a hand-added connector can sign in. */
    private static function render_enable_first(): void {
        ?>
        <form method="post" class="mcp-inline-form cmcp-enable-first">
            <?php wp_nonce_field( 'cowboy_mcp_enable_oauth' ); ?>
            <p><?php esc_html_e( 'Turn on sign-in first:', 'cowboy-mcp' ); ?>
            <button type="submit" name="cowboy_mcp_enable_oauth" class="cmcp-btn cmcp-btn--sm"><?php esc_html_e( 'Turn on browser sign-in', 'cowboy-mcp' ); ?></button></p>
        </form>
        <?php
    }

    /**
     * Claude's "Add custom connector" instructions as three sentences: [where to go, what to click, what to paste].
     * Shown as plain paragraphs (by-hand details) or spread over flat steps — never a nested numbered list.
     * $link_above: the link is shown just above (details), not in step 1.
     */
    private static function claude_manual_sentences( bool $is_desktop, bool $link_above = false ): array {
        return [
            wp_kses(
                $is_desktop
                    ? __( 'In the <strong>Claude</strong> desktop app, go to <code>Customize → Connectors</code>.', 'cowboy-mcp' )
                    : __( 'On <code>claude.ai</code>, go to <code>Customize → Connectors</code>.', 'cowboy-mcp' ),
                [ 'code' => [], 'strong' => [] ]
            ),
            wp_kses( __( 'Click <strong>+ Add</strong>, then <strong>Add custom connector</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ),
            wp_kses(
                // Claude's dialog (2026-10-18) detects the sign-in settings itself; its OAuth-client choices are "Use Claude's published
                // identity" (CIMD, the recommended default) and "Register automatically" (DCR) — both are paths this plugin serves.
                $link_above
                    ? __( 'Name it, paste the link above and click <strong>Continue</strong>. Keep the sign-in settings Claude detects (either OAuth client option works) and click <strong>Add</strong>.', 'cowboy-mcp' )
                    : __( 'Name it, paste the link from step 1 and click <strong>Continue</strong>. Keep the sign-in settings Claude detects (either OAuth client option works) and click <strong>Add</strong>.', 'cowboy-mcp' ),
                [ 'strong' => [] ]
            ),
        ];
    }

    /**
     * POST form that opens the registration window and sends the admin (new tab) to
     * Claude's prefilled "Add custom connector" dialog. $target: 'me' or 'org'.
     */
    private static function render_add_to_claude_form( string $target, string $class, string $label ): void {
        ?>
        <form method="post" target="_blank" action="<?php echo esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'connection', 'connect' => '1' ] ) ); ?>" class="mcp-inline-form cmcp-add-claude" data-cmcp-refresh-after-submit>
            <?php wp_nonce_field( 'cowboy_mcp_add_to_claude' ); ?>
            <button type="submit" name="cowboy_mcp_add_to_claude" value="<?php echo esc_attr( $target ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php
                echo esc_html( $label );
                echo Cowboy_MCP_Admin::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from Cowboy_MCP_Admin::icon().
            ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'cowboy-mcp' ); ?></span></button>
        </form>
        <?php
    }

    /**
     * Claude's documented custom-connector install link: opens "Add custom connector"
     * with the name and URL prefilled (the user still reviews and confirms).
     * https://claude.com/docs/connectors/building/directory-vs-custom
     */
    public static function claude_install_link( bool $org = false ): string {
        $query = http_build_query(
            [
                'modal'         => 'add-custom-connector',
                'connectorName' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
                // Must be byte-identical to resource_url(): /authorize compares it strictly.
                'connectorUrl'  => Cowboy_MCP_OAuth::resource_url(),
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        return 'https://claude.ai/' . ( $org ? 'admin-settings/connectors' : 'customize/connectors' ) . '?' . $query;
    }

    private static function render_desktop_bridge_flow( string $endpoint, array $keys, $new_key ): void {
        $host        = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $domain      = str_replace( '.', '-', $host );
        $key_display = $new_key ?: 'YOUR_API_KEY';
        $has_keys    = ! empty( $keys );
        $settings    = get_option( 'cowboy_mcp_settings', [] );
        $oauth_on    = class_exists( 'Cowboy_MCP_OAuth' ) && ! empty( $settings['enabled'] ) && ! empty( $settings['oauth_enabled'] );
        // Offer the plain-http fallback only for loopback-shaped hosts: nothing
        // must ever be suggested over plaintext on a real network.
        $offer_http  = 'https' === wp_parse_url( $endpoint, PHP_URL_SCHEME )
            && Cowboy_MCP_Security::host_is_loopback_shaped( $host );
        ?>
        <div class="cmcp-callout cmcp-callout--info"><p><?php
            echo wp_kses( __( 'This site runs on your computer, so the cloud connector cannot reach it. Claude Desktop connects through a <strong>local bridge</strong> instead: the open-source <code>mcp-remote</code> package, started with <code>npx</code> (needs Node.js). It signs in in your browser, and nothing is exposed to the internet.', 'cowboy-mcp' ), [ 'strong' => [], 'code' => [] ] );
        ?></p></div>
        <?php self::step_open( '1', __( 'Add the bridge to Claude Desktop', 'cowboy-mcp' ), '' ); ?>
                <p><?php esc_html_e( 'Run this in Terminal (macOS) or PowerShell (Windows), then fully quit and reopen the Claude app.', 'cowboy-mcp' ); ?></p>
                <?php self::render_code( 'mcp-oauth-cmd-claude-desktop', self::bridge_command_snippet( $domain, $endpoint, '' ), __( 'terminal', 'cowboy-mcp' ), __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => 'claude-desktop', 'mode' => 'oauth' ] ); ?>
                <?php
                if ( $offer_http ) {
                    self::render_cert_error_details( 'claude-desktop', self::bridge_command_snippet( $domain, set_url_scheme( $endpoint, 'http' ), '' ), __( 'terminal', 'cowboy-mcp' ) );
                }
                ?>
                <div class="cmcp-inline-sum">
                <details class="mcp-local-details">
                    <summary><?php esc_html_e( 'Edit the config file instead', 'cowboy-mcp' ); ?></summary>
                    <div class="mcp-local-details-body">
                        <p><?php echo wp_kses( __( 'Add this to <code>claude_desktop_config.json</code>, then fully quit and reopen the Claude app.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                        <?php self::render_code( 'mcp-oauth-cfg-claude-desktop', self::bridge_config_snippet( $domain, $endpoint, '' ), 'claude_desktop_config.json', __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => 'claude-desktop', 'mode' => 'oauth' ] ); ?>
                        <p class="description cmcp-os-paths">
                            <?php echo wp_kses( __( 'macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>', 'cowboy-mcp' ), [ 'code' => [] ] ); ?><br>
                            <?php echo wp_kses( __( 'Windows: <code>%APPDATA%\Claude\claude_desktop_config.json</code>', 'cowboy-mcp' ), [ 'code' => [] ] ); ?>
                        </p>
                    </div>
                </details>
                <details class="mcp-local-details cmcp-key-fallback" <?php echo $new_key ? 'open' : ''; ?>>
                    <summary><?php esc_html_e( 'Use an API key instead', 'cowboy-mcp' ); ?></summary>
                    <div class="mcp-local-details-body cmcp-key-fallback-body">
                        <p class="description"><?php esc_html_e( 'For headless setups, CI, or a tool that cannot open a browser.', 'cowboy-mcp' ); ?></p>
                        <?php self::render_key_step( 'claude-desktop', 'Claude Desktop', $new_key, $has_keys, 'a' ); ?>
                        <p><?php esc_html_e( 'Then run this instead, with the key in a header:', 'cowboy-mcp' ); ?></p>
                        <?php self::render_code( 'mcp-cmd-claude-desktop', self::bridge_command_snippet( $domain, $endpoint, $key_display ), __( 'terminal', 'cowboy-mcp' ), __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => 'claude-desktop', 'mode' => 'key' ] ); ?>
                        <p class="description"><?php echo wp_kses( __( 'The key is stored in plain text in that file, so prefer a <strong>read-only</strong> key unless this connection needs to make changes, and revoke it here when you no longer use it.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                        <?php
                        if ( $offer_http ) {
                            self::render_cert_error_details( 'claude-desktop-key', self::bridge_command_snippet( $domain, set_url_scheme( $endpoint, 'http' ), $key_display ), __( 'terminal', 'cowboy-mcp' ) );
                        }
                        ?>
                    </div>
                </details>
                </div>
        <?php self::step_close(); ?>
        <?php self::render_approve_step( $oauth_on ); ?>
        <?php self::step_open( '3', __( 'Check it worked', 'cowboy-mcp' ), '' ); ?>
                <p><?php
                    /* translators: %s: the MCP server name shown in the client. */
                    echo wp_kses( sprintf( __( 'Open a new chat in Claude Desktop, click <strong>+</strong> and open <strong>Connectors</strong> — <code>%s</code> should be listed. The first start takes a moment while the bridge downloads.', 'cowboy-mcp' ), esc_html( $domain ) ), [ 'code' => [], 'strong' => [] ] );
                ?> <?php esc_html_e( 'Then try:', 'cowboy-mcp' ); ?></p>
                <?php self::render_example_prompt( 'claude-desktop-bridge', $domain ); ?>
        <?php self::step_close(); ?>
        <?php
    }

    /**
     * claude_desktop_config.json snippet for the mcp-remote bridge. An empty $key_display
     * means OAuth (mcp-remote registers and opens the browser itself); otherwise the
     * Authorization header value goes through mcp-remote's ${VAR} env expansion so the
     * space in "Bearer <key>" survives Claude Desktop's Windows argument handling.
     * mcp-remote accepts plain http only for localhost/127.0.0.1 unless --allow-http is passed
     * (src/lib/utils.ts), so other http hosts (e.g. mysite.test) get the flag.
     */
    private static function bridge_config_snippet( string $domain, string $endpoint, string $key_display ): string {
        $host  = strtolower( (string) wp_parse_url( $endpoint, PHP_URL_HOST ) );
        $http  = 'http' === strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) && ! in_array( $host, [ 'localhost', '127.0.0.1' ], true );
        $allow = $http ? ', "--allow-http"' : '';
        if ( '' === $key_display ) {
            return "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"command\": \"npx\",\n      \"args\": [\"-y\", \"mcp-remote\", \"{$endpoint}\"{$allow}]\n    }\n  }\n}";
        }
        return "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"command\": \"npx\",\n      \"args\": [\"-y\", \"mcp-remote\", \"{$endpoint}\"{$allow},\n        \"--header\", \"Authorization:\${AUTH_HEADER}\"],\n      \"env\": { \"AUTH_HEADER\": \"Bearer {$key_display}\" }\n    }\n  }\n}";
    }

    /**
     * Which connection the live "Connected" strip should watch for a client. Claude Desktop
     * on a local site connects through the mcp-remote bridge (DCR name "MCP CLI Client"),
     * not through the hosted Claude connector.
     */
    public static function connect_watch_slug( string $client, bool $local ): string {
        if ( 'claude-desktop' === $client && $local ) {
            return 'mcp-remote';
        }
        return self::client_registry()[ $client ]['watch'] ?? $client;
    }

    private static function render_cert_error_details( string $slug, string $alt_snippet, string $title ): void {
        $alt_id = 'mcp-cmd-http-' . $slug;
        ?>
        <details class="mcp-local-details">
            <summary><?php esc_html_e( 'Getting a certificate error?', 'cowboy-mcp' ); ?></summary>
            <div class="mcp-local-details-body">
                <p><?php echo wp_kses( __( 'Local sites usually use a self-signed certificate that AI tools reject. Since this site runs on this computer, use the plain <code>http://</code> address instead: the connection never leaves your machine. Avoid the <code>NODE_TLS_REJECT_UNAUTHORIZED=0</code> workaround: it disables certificate checks for everything that tool connects to.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php self::render_code( $alt_id, $alt_snippet, $title, __( 'Copy setup command', 'cowboy-mcp' ) ); ?>
            </div>
        </details>
        <?php
    }

    private static function render_api_client_panel( string $slug, string $label, array $keys, string $endpoint, $new_key, bool $is_local = false ): void {
        $host        = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $domain      = str_replace( '.', '-', $host );
        $key_display = $new_key ?: 'YOUR_API_KEY';
        $has_keys    = ! empty( $keys );
        // Plain-http fallback only for loopback-shaped hosts (never bare private
        // IPs — that can be another machine on the LAN).
        $offer_http  = $is_local
            && 'https' === wp_parse_url( $endpoint, PHP_URL_SCHEME )
            && Cowboy_MCP_Security::host_is_loopback_shaped( $host );

        self::render_key_step( $slug, $label, $new_key, $has_keys );
        self::render_install_step( $slug, $domain, $endpoint, $key_display, (bool) $new_key, $offer_http );
        self::render_verify_step( $slug, $domain );
    }

    /** Terminal clients that sign in with OAuth: command first, API key as a fallback. */
    private static function render_oauth_cli_panel( string $slug, string $label, array $keys, string $endpoint, $new_key, bool $is_local ): void {
        $host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $domain   = str_replace( '.', '-', $host );
        $settings = get_option( 'cowboy_mcp_settings', [] );
        $oauth_on = class_exists( 'Cowboy_MCP_OAuth' ) && ! empty( $settings['enabled'] ) && ! empty( $settings['oauth_enabled'] );
        $intro    = match ( $slug ) {
            'claude-code' => esc_html__( 'Run these in your terminal. The second line opens your browser to sign in.', 'cowboy-mcp' ),
            'codex'       => esc_html__( 'Run this in your terminal. Codex opens your browser to sign in.', 'cowboy-mcp' ),
            /* translators: %s: the MCP server name used in the command */
            'gemini-cli'  => wp_kses( sprintf( __( 'Run this in your terminal, then start <code>gemini</code>: it opens your browser to sign in (if it does not, run <code>/mcp auth %s</code>).', 'cowboy-mcp' ), esc_html( $domain ) ), [ 'code' => [] ] ),
            'opencode'    => esc_html__( 'Run this in your terminal. Opencode opens your browser to sign in the first time it connects.', 'cowboy-mcp' ),
            'muse-code'   => wp_kses( __( 'Start <code>muse</code> and paste this message. Muse adds the server to its settings; approve the change when it asks.', 'cowboy-mcp' ), [ 'code' => [] ] ),
            // OpenClaw and Hermes are driven from chat apps, so the pasted message is the default (owner, 2026-10-17); the
            // terminal commands sit in the collapsed "Use the terminal instead" row (render_terminal_details()).
            'openclaw'    => esc_html__( 'Paste this message into your OpenClaw chat. OpenClaw asks you to approve the config change, then sends you the sign-in link.', 'cowboy-mcp' ),
            'hermes'      => esc_html__( 'Paste this message into your Hermes chat. Hermes adds the server and sends you a sign-in link; open it in your browser.', 'cowboy-mcp' ),
            default       => '',
        };
        ?>
        <?php self::step_open( '1', __( 'Add the server', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per-branch above. ?></p>
                <?php if ( 'muse-code' === $slug ) : ?>
                    <?php
                    $msg_title = sprintf(
                        /* translators: %s: AI app name */
                        __( 'Message for %s', 'cowboy-mcp' ),
                        $label
                    );
                    self::render_paste_field( 'mcp-oauth-msg-' . $slug, self::muse_code_message( $domain, $endpoint ), $msg_title, __( 'Copy message', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] );
                    ?>
                    <p><?php esc_html_e( 'Then quit Muse and run this. It opens your browser to sign in:', 'cowboy-mcp' ); ?></p>
                    <?php self::render_code( 'mcp-oauth-cmd-' . $slug, self::oauth_snippet( $slug, $domain, $endpoint ), __( 'terminal', 'cowboy-mcp' ), __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] ); ?>
                <?php elseif ( in_array( $slug, [ 'openclaw', 'hermes' ], true ) ) : ?>
                    <?php
                    $msg_title = sprintf(
                        /* translators: %s: AI app name */
                        __( 'Message for %s', 'cowboy-mcp' ),
                        $label
                    );
                    self::render_paste_field( 'mcp-oauth-msg-' . $slug, self::chat_message( $slug, $domain, $endpoint ), $msg_title, __( 'Copy message', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] );
                    ?>
                <?php else : ?>
                    <?php self::render_code( 'mcp-oauth-cmd-' . $slug, self::oauth_snippet( $slug, $domain, $endpoint ), __( 'terminal', 'cowboy-mcp' ), __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] ); ?>
                <?php endif; ?>
                <?php if ( 'claude-code' === $slug ) : ?>
                    <p class="description"><?php echo wp_kses( __( 'Over SSH? Add <code>--no-browser</code> to the second line and open the printed URL in a local browser.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php elseif ( 'muse-code' === $slug ) : ?>
                    <p class="description"><?php echo wp_kses( __( 'Over SSH? Add <code>--headless</code> and paste back the address your browser ends on.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php elseif ( 'openclaw' === $slug ) : ?>
                    <p class="description"><?php echo wp_kses( __( 'OpenClaw on another machine? If the sign-in cannot reach it, send OpenClaw the <code>code</code> from the address your browser ends on.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php elseif ( 'hermes' === $slug ) : ?>
                    <p class="description"><?php echo wp_kses( __( 'If your browser ends on a <code>127.0.0.1</code> address that will not load, paste that address back into the chat; Hermes completes the sign-in from there.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php endif; ?>
                <?php if ( $is_local && 'https' === wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) : ?>
                    <p class="description"><?php echo wp_kses( __( 'Local https sites usually use a self-signed certificate that these tools reject: trust it in your tool, or set the WordPress Site Address to the <code>http://</code> URL.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php endif; ?>
                <div class="cmcp-inline-sum">
                <?php if ( in_array( $slug, [ 'openclaw', 'hermes' ], true ) ) : ?>
                    <?php self::render_terminal_details( $slug, $domain, $endpoint ); ?>
                <?php endif; ?>
                <?php
                $file_text = match ( $slug ) {
                    /* translators: 1: config file path, 2: the MCP server name used in the command */
                    'muse-code' => sprintf( __( 'Add this to <code>%1$s</code> (merge it if the file exists), then run <code>muse mcp login %2$s</code>.', 'cowboy-mcp' ), '%s', esc_html( $domain ) ),
                    /* translators: 1: config file path, 2: the MCP server name used in the command */
                    'openclaw'  => sprintf( __( 'Add this to <code>%1$s</code> (merge it if the file exists), then run <code>openclaw mcp login %2$s</code>.', 'cowboy-mcp' ), '%s', esc_html( $domain ) ),
                    /* translators: 1: config file path, 2: the MCP server name used in the command */
                    'hermes'    => sprintf( __( 'Add this to <code>%1$s</code>, then run <code>hermes mcp login %2$s</code>.', 'cowboy-mcp' ), '%s', esc_html( $domain ) ),
                    default     => '',
                };
                self::render_file_fallback( $slug, $domain, $endpoint, $file_text );
                ?>
                <?php self::render_key_fallback( $slug, $label, $keys, $endpoint, $new_key, $is_local ); ?>
                </div>
        <?php self::step_close(); ?>
        <?php self::render_approve_step( $oauth_on, $slug ); ?>
        <?php self::render_verify_step_with_prompt( $slug, $domain ); ?>
        <?php
    }

    /**
     * The collapsed terminal path for the chat-first clients (OpenClaw, Hermes): the same verified commands that led the
     * panel before 2026-10-17, now the alternative. Copying still starts a watched connection.
     */
    private static function render_terminal_details( string $slug, string $domain, string $endpoint ): void {
        ?>
        <details class="mcp-local-details">
            <summary><?php esc_html_e( 'Use the terminal instead', 'cowboy-mcp' ); ?></summary>
            <div class="mcp-local-details-body">
                <?php if ( 'openclaw' === $slug ) : ?>
                    <p><?php esc_html_e( 'Run these in your terminal where OpenClaw is installed. The second line prints a sign-in link; open it in your browser.', 'cowboy-mcp' ); ?></p>
                <?php else : ?>
                    <?php // "press Enter again": Hermes's paste-back stdin thread (tools/mcp_oauth.py _paste_callback_reader) is still blocked in
                          // readline() after the browser callback won, so it swallows the first line typed at "Enable all" (reproduced 2026-10-16). ?>
                    <p><?php echo wp_kses( __( 'Run this in your terminal. Hermes opens your browser to sign in, then asks which tools to enable — answer <code>Y</code> (press Enter again if nothing happens).', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php endif; ?>
                <?php self::render_code( 'mcp-oauth-cmd-' . $slug, self::oauth_snippet( $slug, $domain, $endpoint ), __( 'terminal', 'cowboy-mcp' ), __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] ); ?>
                <?php if ( 'openclaw' === $slug ) : ?>
                    <p class="description"><?php
                        /* translators: %s: the MCP server name used in the command */
                        echo wp_kses( sprintf( __( 'OpenClaw running on another machine? Open the printed link in a local browser, copy the <code>code</code> from the address your browser ends on and run <code>openclaw mcp login %s --code &lt;code&gt;</code>.', 'cowboy-mcp' ), esc_html( $domain ) ), [ 'code' => [] ] );
                    ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'Over SSH? Open the printed link in a local browser and paste the address your browser ends on back into the terminal.', 'cowboy-mcp' ); ?></p>
                <?php endif; ?>
            </div>
        </details>
        <?php
    }

    /** The chat message for a chat-first client (English on purpose: it is an instruction for the agent, like tool descriptions). */
    private static function chat_message( string $slug, string $domain, string $endpoint ): string {
        return 'hermes' === $slug ? self::hermes_chat_message( $domain, $endpoint ) : self::openclaw_chat_message( $domain, $endpoint );
    }

    /**
     * OpenClaw: its agents carry an `openclaw` tool that proposes typed config operations (`config set`) which the owner
     * approves in the chat (Allow once / `/approve <id>`; Full Access applies them at once - docs/cli/openclaw.md
     * "Operations and approval", 2026.10.4), and a shell-capable agent may run `openclaw mcp login <name>`
     * (docs/cli/mcp/transports.md). The browser step still happens where the gateway runs, or through `--code`.
     * The model-driven turn itself was not observed live (no OpenClaw model account here); the commands in
     * render_terminal_details() are the live-verified path.
     */
    private static function openclaw_chat_message( string $domain, string $endpoint ): string {
        $json = (string) wp_json_encode( [ 'url' => $endpoint, 'transport' => 'streamable-http', 'auth' => 'oauth' ], JSON_UNESCAPED_SLASHES );
        return 'Add an MCP server to my OpenClaw config: set mcp.servers.' . $domain . ' to ' . $json . ' (propose the config change and wait for my approval). Then run `openclaw mcp login ' . $domain . '` and send me the authorization link it prints. If the sign-in cannot reach your callback, I will send you the code to finish with `openclaw mcp login ' . $domain . ' --code <code>`.';
    }

    /**
     * Hermes Agent (main, 2026-10-17): its file tool refuses to write config.yaml and `hermes mcp add` without a TTY saves
     * nothing, but `hermes config set mcp_servers.<name>.<key>` creates the entry from the agent's terminal tool, and its
     * `manage_connections` tool's `authorize` action runs OAuth for any configured server (only `install` is catalog-only).
     * Without a card (messaging apps) it returns a connect_url at once and a worker waits on a loopback listener; after
     * consent the browser lands on 127.0.0.1:<port>/callback, which the agent can fetch on its own host once the user
     * pastes it back. Verified headlessly end to end (docs/superpowers/tests/openclaw-hermes/e2e-hermes-chat.sh): DCR
     * "Hermes Agent" (CIMD is skipped under the dashboard-flow object, so the Copy's registration window matters), tokens
     * written, `hermes mcp test` lists 2 tools. The tool is registered only for guest Nous identities or portal accounts
     * with the managed-tools claim, hence the last sentence.
     */
    private static function hermes_chat_message( string $domain, string $endpoint ): string {
        return 'Add an MCP server to my Hermes config and sign in to it. 1) In your terminal run `hermes config set mcp_servers.' . $domain . '.url ' . $endpoint . '`, `hermes config set mcp_servers.' . $domain . '.auth oauth` and `hermes config set mcp_servers.' . $domain . '.connect_timeout 300`. 2) Call your manage_connections tool with action "authorize" and connectors [{"name": "' . $domain . '", "mcp": true}], then send me the connect_url it returns so I can approve in my browser. 3) If I paste back an address starting with http://127.0.0.1, fetch that exact address with curl from your terminal; that completes the sign-in. 4) Run `hermes mcp test ' . $domain . '` and tell me which tools it found. If you have no manage_connections tool, say so and I will run `hermes mcp login ' . $domain . '` myself.';
    }

    /** IDEs with an install link: Cursor, VS Code. */
    private static function render_oauth_link_panel( string $slug, string $label, array $keys, string $endpoint, $new_key, bool $is_local ): void {
        $host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $domain   = str_replace( '.', '-', $host );
        $settings = get_option( 'cowboy_mcp_settings', [] );
        $oauth_on = class_exists( 'Cowboy_MCP_OAuth' ) && ! empty( $settings['enabled'] ) && ! empty( $settings['oauth_enabled'] );
        $cursor   = ( 'cursor' === $slug );
        $btn      = $cursor ? __( 'Add to Cursor', 'cowboy-mcp' ) : __( 'Install in VS Code', 'cowboy-mcp' );
        $after    = $cursor
            ? __( 'Accept in Cursor. It opens your browser to sign in.', 'cowboy-mcp' )
            : __( 'Confirm in VS Code and trust the server. It opens your browser to sign in.', 'cowboy-mcp' );
        ?>
        <?php self::step_open( '1', $btn, '' ); ?>
                <p><a class="cmcp-btn cmcp-btn--primary cmcp-install" href="<?php echo esc_url( self::install_link( $slug, $domain, $endpoint ), [ 'https', 'cursor' ] ); ?>"<?php echo $cursor ? '' : ' target="_blank" rel="noopener"'; ?> data-cmcp-start="<?php echo esc_attr( $slug ); ?>" data-cmcp-mode="oauth"><?php
                    echo esc_html( $btn );
                    echo Cowboy_MCP_Admin::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
                ?></a></p>
                <p><?php echo esc_html( $after ); ?></p>
                <div class="cmcp-inline-sum">
                <?php if ( ! $cursor ) : ?>
                    <details class="mcp-local-details">
                        <summary><?php esc_html_e( 'Use a command instead', 'cowboy-mcp' ); ?></summary>
                        <div class="mcp-local-details-body">
                            <p><?php echo wp_kses( __( 'Run this in your terminal (the <code>code</code> command ships with VS Code), then open Copilot Chat. It signs in on first use.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                            <?php self::render_code( 'mcp-oauth-cli-' . $slug, self::oauth_snippet( $slug, $domain, $endpoint ), __( 'terminal', 'cowboy-mcp' ), __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] ); ?>
                        </div>
                    </details>
                <?php endif; ?>
                <?php
                /* translators: %s: path of the client's config file. */
                $file_text = $cursor ? __( 'Add this to <code>%s</code>, then restart Cursor. It signs in on first use.', 'cowboy-mcp' ) : '';
                self::render_file_fallback( $slug, $domain, $endpoint, $file_text ); ?>
                <?php self::render_key_fallback( $slug, $label, $keys, $endpoint, $new_key, $is_local ); ?>
                </div>
        <?php self::step_close(); ?>
        <?php self::render_approve_step( $oauth_on ); ?>
        <?php self::render_verify_step_with_prompt( $slug, $domain ); ?>
        <?php
    }

    /** Collapsed "edit the file instead" fallback under a command (clients with a config file). $text: an optional sprintf template with %s = file path. */
    private static function render_file_fallback( string $slug, string $domain, string $endpoint, string $text = '' ): void {
        $file = self::file_title( $slug );
        if ( '' === $file || '' === self::file_snippet( $slug, $domain, $endpoint ) ) {
            return;
        }
        if ( '' === $text ) {
            /* translators: %s: config file path */
            $text = __( 'Add this to <code>%s</code>, then restart the app. It signs in on first use.', 'cowboy-mcp' );
        }
        ?>
        <details class="mcp-local-details">
            <summary><?php esc_html_e( 'Edit the config file instead', 'cowboy-mcp' ); ?></summary>
            <div class="mcp-local-details-body">
                <p><?php echo wp_kses( sprintf( $text, esc_html( $file ) ), [ 'code' => [] ] ); ?></p>
                <?php self::render_code( 'mcp-oauth-cfg-' . $slug, self::file_snippet( $slug, $domain, $endpoint ), $file, __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'oauth' ] ); ?>
            </div>
        </details>
        <?php
    }

    /**
     * n8n: MCP Client Tool node. OAuth2 first (owner, 2026-10-17: fewer steps and no key to copy — n8n registers itself
     * with this site and opens the sign-in; verified live with n8n 2.42.6), the API key as the collapsed row like
     * every other panel. Self-hosted n8n on another host needs its host under Settings → Additional allowed hosts;
     * n8n cloud (n8n.cloud) and an n8n on this machine (loopback) are allowed already.
     */
    private static function render_n8n_panel( string $slug, array $def, array $keys, string $endpoint, $new_key, bool $is_local ): void {
        $settings = get_option( 'cowboy_mcp_settings', [] );
        $oauth_on = class_exists( 'Cowboy_MCP_OAuth' ) && ! empty( $settings['enabled'] ) && ! empty( $settings['oauth_enabled'] );
        if ( $is_local ) : ?>
            <div class="cmcp-callout cmcp-callout--info"><p><?php esc_html_e( "n8n cloud connects from n8n's servers and cannot reach a local site; a self-hosted n8n on this machine works.", 'cowboy-mcp' ); ?></p></div>
        <?php endif; ?>
        <?php self::step_open( '1', __( 'Add an MCP Client Tool node', 'cowboy-mcp' ), '' ); ?>
            <p><?php echo wp_kses( __( 'In your workflow, add the <strong>MCP Client Tool</strong> node to your agent and fill in:', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
            <dl class="cmcp-fields">
                <dt><?php esc_html_e( 'Endpoint', 'cowboy-mcp' ); ?></dt><dd><?php self::render_paste_field( 'mcp-n8n-ep', $endpoint, '', __( 'Copy endpoint URL', 'cowboy-mcp' ), [ 'start' => 'n8n', 'mode' => 'oauth' ] ); ?></dd>
                <dt><?php esc_html_e( 'Server Transport', 'cowboy-mcp' ); ?></dt><dd><code>HTTP Streamable</code></dd>
                <dt><?php esc_html_e( 'Authentication', 'cowboy-mcp' ); ?></dt><dd><?php echo wp_kses( __( '<strong>OAuth2</strong> — create a new OAuth2 credential and click Connect. n8n registers itself with this site and opens the sign-in.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></dd>
            </dl>
            <p class="description"><?php
                /* translators: %s: URL of the Settings tab */
                echo wp_kses( sprintf( __( 'Self-hosted n8n: add your n8n host under <a href="%s">Settings → Additional allowed hosts</a> first. n8n cloud is already allowed.', 'cowboy-mcp' ), esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'settings' ] ) . '#cowboy_mcp_oauth_extra_redirect_hosts' ) ), [ 'a' => [ 'href' => [] ] ] );
            ?></p>
            <div class="cmcp-inline-sum">
                <details class="mcp-local-details cmcp-key-fallback" <?php echo $new_key ? 'open' : ''; ?>>
                    <summary><?php esc_html_e( 'Use an API key instead', 'cowboy-mcp' ); ?></summary>
                    <div class="mcp-local-details-body cmcp-key-fallback-body">
                        <p><?php echo wp_kses( __( 'Set Authentication to <strong>Bearer Auth</strong> instead and paste the key into a new Bearer Auth credential.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                        <?php self::render_key_step( 'n8n', 'n8n', $new_key, ! empty( $keys ), 'a' ); ?>
                    </div>
                </details>
            </div>
        <?php self::step_close(); ?>
        <?php self::render_approve_step( $oauth_on ); ?>
        <?php self::step_open( '3', __( 'Check it worked', 'cowboy-mcp' ), '' ); ?>
            <p><?php echo wp_kses( __( 'Execute the node once — its Tools output lists <code>cowboy_discover</code> and <code>cowboy_run</code>.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?> <?php esc_html_e( 'Then ask your agent:', 'cowboy-mcp' ); ?></p>
            <?php self::render_example_prompt( 'n8n', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>
        <?php self::step_close(); ?>
        <?php
    }

    /**
     * Step 2 for every OAuth terminal/IDE client: one sentence. The New-connections window is opened by
     * the Copy / Add / Install click itself (data-cmcp-start → ajax_start_connect()), so it is not mentioned here.
     */
    private static function render_approve_step( bool $oauth_on, string $slug = '' ): void {
        ?>
        <?php self::step_open( '2', __( 'Approve access', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo wp_kses( __( 'A sign-in page opens on <strong>your site</strong>. Pick the access level and click <strong>Approve</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
        <?php self::step_close(); ?>
        <?php
    }

    /** Step 3: the client's own check plus one read-only prompt to try. */
    private static function render_verify_step_with_prompt( string $slug, string $domain ): void {
        ?>
        <div data-cmcp-verify-step>
        <?php self::render_verify_step( $slug, $domain ); ?>
        </div>
        <?php
    }

    /** API-key fallback for OAuth-first clients: the key step + header snippet, collapsed. */
    private static function render_key_fallback( string $slug, string $label, array $keys, string $endpoint, $new_key, bool $is_local ): void {
        $host        = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $domain      = str_replace( '.', '-', $host );
        $key_display = $new_key ?: 'YOUR_API_KEY';
        $offer_http  = $is_local && 'https' === wp_parse_url( $endpoint, PHP_URL_SCHEME ) && Cowboy_MCP_Security::host_is_loopback_shaped( $host );
        ?>
        <details class="mcp-local-details cmcp-key-fallback" <?php echo $new_key ? 'open' : ''; ?>>
            <summary><?php esc_html_e( 'Use an API key instead', 'cowboy-mcp' ); ?></summary>
            <div class="mcp-local-details-body cmcp-key-fallback-body">
                <p class="description"><?php esc_html_e( 'For headless setups, CI, or a tool that cannot open a browser.', 'cowboy-mcp' ); ?></p>
                <?php self::render_key_step( $slug, $label, $new_key, ! empty( $keys ), 'a' ); ?>
                <p><?php esc_html_e( 'Then add the server with the key in a header:', 'cowboy-mcp' ); ?></p>
                <?php self::render_code( 'mcp-cmd-' . $slug, self::install_snippet( $slug, $domain, $endpoint, $key_display ), self::snippet_title( $slug ), __( 'Copy setup command', 'cowboy-mcp' ), [ 'start' => $slug, 'mode' => 'key' ] ); ?>
                <?php if ( 'codex' === $slug ) : ?>
                    <p class="description"><?php echo wp_kses( __( 'Codex reads the key each time it starts, so also add the <code>export</code> line to your shell profile (e.g. <code>~/.zshrc</code>).', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php elseif ( 'hermes' === $slug ) : ?>
                    <p class="description"><?php echo wp_kses( __( 'Hermes asks for the key when you run this and stores it in <code>~/.hermes/.env</code>; the config file only references it.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php endif; ?>
                <?php
                if ( $offer_http ) {
                    self::render_cert_error_details( $slug, self::install_snippet( $slug, $domain, set_url_scheme( $endpoint, 'http' ), $key_display ), self::snippet_title( $slug ) );
                }
                ?>
            </div>
        </details>
        <?php
    }

    private static function render_key_step( string $slug, string $label, $new_key, bool $has_keys, string $num = '1' ): void {
        ?>
        <?php self::step_open( $new_key ? '✓' : $num, __( 'Create an API key', 'cowboy-mcp' ), $new_key ? 'done' : '' ); ?>
                <?php if ( $new_key ) : ?>
                    <div class="cmcp-keybox">
                        <p class="cmcp-keybox-note"><?php echo wp_kses( __( '<strong>Copy your key now</strong> — for security it will not be shown again.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                        <?php self::render_code( 'mcp-new-key-' . $slug, (string) $new_key, __( 'API key', 'cowboy-mcp' ), __( 'Copy API key', 'cowboy-mcp' ) ); ?>
                        <button type="button" class="cmcp-btn cmcp-btn--sm mcp-dismiss-key"><?php esc_html_e( "I've saved my key", 'cowboy-mcp' ); ?></button>
                    </div>
                <?php else : ?>
                    <p><?php esc_html_e( 'Give it a name so you can recognize it later, then generate.', 'cowboy-mcp' ); ?></p>
                    <form method="post" class="mcp-generate-form cmcp-formrow">
                        <?php wp_nonce_field( 'cowboy_mcp_generate_key' ); ?>
                        <input type="text" name="key_label" value="" placeholder="<?php
                            /* translators: %s: client name, e.g. "Claude Code" */
                            echo esc_attr( sprintf( __( 'e.g. %s on my laptop', 'cowboy-mcp' ), $label ) );
                        ?>" class="cmcp-input">
                        <?php self::render_scope_radios( 'full' ); ?>
                        <button type="submit" name="cowboy_mcp_generate_key" class="cmcp-btn cmcp-btn--primary"><?php echo $has_keys ? esc_html__( 'Generate another key', 'cowboy-mcp' ) : esc_html__( 'Generate API key', 'cowboy-mcp' ); ?></button>
                    </form>
                <?php endif; ?>
        <?php self::step_close(); ?>
        <?php
    }

    private static function render_install_step( string $slug, string $domain, string $endpoint, string $key_display, bool $step_active, bool $offer_http_variant = false ): void {
        $code_id = 'mcp-cmd-' . $slug;
        $intro   = match ( $slug ) {
            'claude-code',
            'gemini-cli',
            'opencode',
            'vscode'     => esc_html__( 'Run this in your terminal:', 'cowboy-mcp' ),
            'codex'      => wp_kses( __( 'Run these in your terminal. Codex reads the key each time it starts, so also add the <code>export</code> line to your shell profile (e.g. <code>~/.zshrc</code>):', 'cowboy-mcp' ), [ 'code' => [] ] ),
            'cursor'     => wp_kses( __( 'Add this to <code>~/.cursor/mcp.json</code> (create the file if it does not exist), then restart Cursor:', 'cowboy-mcp' ), [ 'code' => [] ] ),
            default      => '',
        };
        ?>
        <?php self::step_open( '2', __( 'Add the server to your tool', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per-branch in the match above. ?></p>
                <?php self::render_code( $code_id, self::install_snippet( $slug, $domain, $endpoint, $key_display ), self::snippet_title( $slug ), __( 'Copy setup command', 'cowboy-mcp' ) ); ?>
                <?php
                if ( $offer_http_variant ) {
                    self::render_cert_error_details( $slug, self::install_snippet( $slug, $domain, set_url_scheme( $endpoint, 'http' ), $key_display ), self::snippet_title( $slug ) );
                }
                ?>
        <?php self::step_close(); ?>
        <?php
    }

    /** Title shown on a snippet's terminal bar: the file it goes into (Cursor only), or "terminal". */
    private static function snippet_title( string $slug ): string {
        return match ( $slug ) {
            'cursor'    => '~/.cursor/mcp.json',
            'muse-code' => '~/.config/muse/settings.json',
            default     => __( 'terminal', 'cowboy-mcp' ),
        };
    }

    /**
     * The OpenClaw config file (`~/.openclaw/openclaw.json`, JSON5 - plain JSON is valid) with this server under
     * `mcp.servers` (docs/cli/mcp/transports.md, 2026.10.4). `transport` is mandatory: without it OpenClaw assumes SSE.
     * Empty $key_display = OAuth (`auth: "oauth"`); a key goes in `headers` and OAuth is left out, because static
     * headers are ignored while `auth` is oauth.
     */
    private static function openclaw_config_json( string $domain, string $endpoint, string $key_display ): string {
        $entry = [ 'url' => $endpoint, 'transport' => 'streamable-http' ];
        if ( '' !== $key_display ) {
            $entry['headers'] = [ 'Authorization' => 'Bearer ' . $key_display ];
        } else {
            $entry['auth'] = 'oauth';
        }
        return (string) wp_json_encode( [ 'mcp' => [ 'servers' => [ $domain => $entry ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    /** The Hermes Agent config file (`~/.hermes/config.yaml`, key `mcp_servers`) with this server; `auth: oauth` or a Bearer header (website/docs/user-guide/features/mcp.md, 2026-10-16). */
    private static function hermes_config_yaml( string $domain, string $endpoint, string $key_display ): string {
        $yaml = "mcp_servers:\n  {$domain}:\n    url: \"{$endpoint}\"\n";
        if ( '' !== $key_display ) {
            return $yaml . "    headers:\n      Authorization: \"Bearer {$key_display}\"\n";
        }
        return $yaml . "    auth: oauth\n";
    }

    /** Raw (unescaped) setup snippet for an API-key client: a command wherever the client has one. Escaped at output. */
    private static function install_snippet( string $slug, string $domain, string $endpoint, string $key_display ): string {
        return match ( $slug ) {
            'claude-code'    => 'claude mcp add --transport http ' . $domain . ' ' . $endpoint . ' --header "Authorization: Bearer ' . $key_display . '"',
            'codex'          => 'export COWBOY_MCP_API_KEY="' . $key_display . "\"\n" . 'codex mcp add ' . $domain . ' --url ' . $endpoint . ' --bearer-token-env-var COWBOY_MCP_API_KEY',
            // opencode's --header takes KEY=VALUE (opencode mcp add --help, 1.18.35).
            'opencode'       => 'opencode mcp add ' . $domain . ' --url ' . $endpoint . ' --header "Authorization=Bearer ' . $key_display . '"',
            'cursor'         => "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"url\": \"{$endpoint}\",\n      \"headers\": {\n        \"Authorization\": \"Bearer {$key_display}\"\n      }\n    }\n  }\n}",
            'gemini-cli'     => 'gemini mcp add --scope user --transport http ' . $domain . ' ' . $endpoint . ' --header "Authorization: Bearer ' . $key_display . '"',
            'vscode'         => "code --add-mcp '" . wp_json_encode( [ 'name' => $domain, 'type' => 'http', 'url' => $endpoint, 'headers' => [ 'Authorization' => 'Bearer ' . $key_display ] ], JSON_UNESCAPED_SLASHES ) . "'",
            'claude-desktop' => self::bridge_command_snippet( $domain, $endpoint, $key_display ),
            // A key never goes into the chat message (it would reach the model); the key path edits the file instead.
            'muse-code'      => self::muse_settings_json( $domain, $endpoint, $key_display ),
            // `--header <key=value>`, repeatable (openclaw mcp add --help, 2026.10.4); `add` probes the server before saving.
            'openclaw'       => 'openclaw mcp add ' . $domain . ' --url ' . $endpoint . ' --transport streamable-http --header "Authorization=Bearer ' . $key_display . '"',
            // `--auth header` prompts for the token (masked) and keeps it in ~/.hermes/.env, so the key is never on the command line (hermes_cli/mcp_config.py, 2026-10-16).
            'hermes'         => 'hermes mcp add ' . $domain . ' --url ' . $endpoint . ' --auth header',
            default          => '',
        };
    }

    /**
     * The message the admin pastes into Muse Code. Muse Code has no `mcp add` command (1.4.9), but its bundled
     * manage-settings skill makes any explicit settings change itself: it edits $XDG_CONFIG_HOME|~/.config/muse/
     * settings.json with its file tools after the user approves, creating the file with schema_version 1 when it is
     * missing. Its own MCP rules (bundled migrate skill): key `mcpServers`, {"type":"streamable-http", url, mode},
     * always mode "optional" (a required server that fails aborts every run), and fold a legacy `mcp_servers` key into
     * `mcpServers`, because a file with both keys loads no MCP server at all (`ambiguous_user_key`, reproduced with
     * the 1.4.9 binary). New servers load on the next launch, so the panel follows with `muse mcp login`.
     * Read from the skills bundled in the 1.4.9 binary; the model-driven edit itself is not yet observed live
     * (needs a Muse Code model login) - the config-file fallback below covers a Muse that declines.
     * English on purpose: it is an instruction for the assistant, like tool descriptions.
     */
    private static function muse_code_message( string $domain, string $endpoint ): string {
        return 'Change my Muse Code settings: add an MCP server under mcpServers named "' . $domain . '" with type "streamable-http", url "' . $endpoint . '" and mode "optional". It signs in with OAuth, so add no headers. If the file still has a legacy mcp_servers key, move its entries into mcpServers.';
    }

    /** The Muse Code settings file with this server (config-file fallback; with a key for the API-key path). */
    private static function muse_settings_json( string $domain, string $endpoint, string $key_display ): string {
        $entry = [ 'type' => 'streamable-http', 'url' => $endpoint, 'mode' => 'optional' ];
        if ( '' !== $key_display ) {
            $entry['headers'] = [ 'Authorization' => 'Bearer ' . $key_display ];
        }
        return (string) wp_json_encode( [ 'schema_version' => 1, 'mcpServers' => [ $domain => $entry ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    /**
     * One command that merges the mcp-remote bridge into claude_desktop_config.json on macOS,
     * Windows (PowerShell) or Linux — Claude Desktop has no CLI for this, and Node is already
     * required for the bridge. Single quotes keep the JS literal in both sh and PowerShell.
     * Verified 2026-10-09: creates the file and directory when missing, merges into an existing
     * file, and keeps unrelated keys. Empty $key_display = OAuth sign-in.
     */
    private static function bridge_command_snippet( string $domain, string $endpoint, string $key_display ): string {
        $host  = strtolower( (string) wp_parse_url( $endpoint, PHP_URL_HOST ) );
        $http  = 'http' === strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) && ! in_array( $host, [ 'localhost', '127.0.0.1' ], true );
        $args  = '"-y","mcp-remote","' . $endpoint . '"' . ( $http ? ',"--allow-http"' : '' );
        $entry = '{command:"npx",args:[' . $args . ']}';
        if ( '' !== $key_display ) {
            $entry = '{command:"npx",args:[' . $args . ',"--header","Authorization:${AUTH_HEADER}"],env:{AUTH_HEADER:"Bearer ' . $key_display . '"}}';
        }
        $path = 'const f=(process.platform==="win32"?process.env.APPDATA+"\\\\Claude":process.platform==="darwin"?process.env.HOME+"/Library/Application Support/Claude":process.env.HOME+"/.config/Claude")+"/claude_desktop_config.json";';
        $js   = 'const fs=require("fs"),p=require("path");' . $path
            . 'let c={};try{c=JSON.parse(fs.readFileSync(f,"utf8"))}catch(e){}'
            . 'c.mcpServers=c.mcpServers||{};c.mcpServers["' . $domain . '"]=' . $entry . ';'
            . 'fs.mkdirSync(p.dirname(f),{recursive:true});fs.writeFileSync(f,JSON.stringify(c,null,2)+"\n");'
            . 'console.log("Added ' . $domain . ' to "+f+" - now fully quit and reopen Claude.")';
        return "node -e '" . $js . "'";
    }

    /**
     * OAuth (no-key) setup snippet per client: a COMMAND wherever the client has one (owner rule:
     * nobody wants to edit config files), the config file only for Cursor, which has no CLI for
     * this. Commands verified against the installed binaries / sources on 2026-10-07 and 2026-10-09
     * (gemini mcp add 0.x, opencode mcp add 1.18, code --add-mcp VS Code 1.137).
     */
    private static function oauth_snippet( string $slug, string $domain, string $endpoint ): string {
        return match ( $slug ) {
            'claude-code'    => 'claude mcp add --transport http ' . $domain . ' ' . $endpoint . "\n" . 'claude mcp login ' . $domain,
            'codex'          => 'codex mcp add ' . $domain . ' --url ' . $endpoint,
            'gemini-cli'     => 'gemini mcp add -s user -t http ' . $domain . ' ' . $endpoint,
            'opencode'       => 'opencode mcp add ' . $domain . ' --url ' . $endpoint,
            'vscode'         => "code --add-mcp '" . wp_json_encode( [ 'name' => $domain, 'type' => 'http', 'url' => $endpoint ], JSON_UNESCAPED_SLASHES ) . "'",
            'claude-desktop' => self::bridge_command_snippet( $domain, $endpoint, '' ),
            'muse-code'      => 'muse mcp login ' . $domain,
            // Saves without probing when auth is oauth, then `login` prints the sign-in link (verified live, openclaw 2026.10.4, 2026-10-16).
            'openclaw'       => 'openclaw mcp add ' . $domain . ' --url ' . $endpoint . ' --transport streamable-http --auth oauth' . "\n" . 'openclaw mcp login ' . $domain,
            // `add --auth oauth` runs the sign-in itself (prints the link, waits for the loopback callback) and then asks which tools
            // to enable (hermes_cli/mcp_config.py, main 2026-10-16). Its probe is bounded by connect_timeout, default 30 s - too short
            // for a browser round trip (reproduced live: "timed out after 30s"), and on timeout Hermes offers to save the server
            // DISABLED. --connect-timeout 300 matches `hermes mcp login`'s own 5-minute window; it is persisted in config.yaml and
            // only matters for a server that accepts the connection and then hangs (refused/unknown hosts still fail at once).
            'hermes'         => 'hermes mcp add ' . $domain . ' --url ' . $endpoint . ' --auth oauth --connect-timeout 300',
            'cursor'         => self::file_snippet( $slug, $domain, $endpoint ),
            default          => '',
        };
    }

    /** The same setup as a config file to edit by hand: the collapsed fallback under each command. */
    private static function file_snippet( string $slug, string $domain, string $endpoint ): string {
        return match ( $slug ) {
            // Gemini CLI signs in at startup only with oauth.enabled (docs/tools/mcp-server.md).
            'gemini-cli'     => "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"httpUrl\": \"{$endpoint}\",\n      \"oauth\": { \"enabled\": true }\n    }\n  }\n}",
            'opencode'       => "{\n  \"mcp\": {\n    \"{$domain}\": {\n      \"type\": \"remote\",\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
            'cursor'         => "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
            'vscode'         => "{\n  \"servers\": {\n    \"{$domain}\": {\n      \"type\": \"http\",\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
            'claude-desktop' => self::bridge_config_snippet( $domain, $endpoint, '' ),
            'muse-code'      => self::muse_settings_json( $domain, $endpoint, '' ),
            'openclaw'       => self::openclaw_config_json( $domain, $endpoint, '' ),
            'hermes'         => self::hermes_config_yaml( $domain, $endpoint, '' ),
            default          => '',
        };
    }

    /** Config-file path shown on a file snippet's bar. */
    private static function file_title( string $slug ): string {
        return match ( $slug ) {
            'gemini-cli'     => '~/.gemini/settings.json',
            'opencode'       => '~/.config/opencode/opencode.json',
            'cursor'         => '~/.cursor/mcp.json',
            'vscode'         => '.vscode/mcp.json',
            'claude-desktop' => 'claude_desktop_config.json',
            'muse-code'      => '~/.config/muse/settings.json',
            'openclaw'       => '~/.openclaw/openclaw.json',
            'hermes'         => '~/.hermes/config.yaml',
            default          => '',
        };
    }

    /**
     * One-click install links. Cursor: cursor.com/docs/mcp/install-links (config = base64 of the inner
     * server object). VS Code: the redirect form used by github/github-mcp-server's README (config = the
     * inner object, name separate). Both verified 2026-10-07.
     */
    public static function install_link( string $slug, string $domain, string $endpoint ): string {
        if ( 'cursor' === $slug ) {
            $config = base64_encode( (string) wp_json_encode( [ 'url' => $endpoint ], JSON_UNESCAPED_SLASHES ) );
            return 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode( $domain ) . '&config=' . rawurlencode( $config );
        }
        if ( 'vscode' === $slug ) {
            $config = (string) wp_json_encode( [ 'type' => 'http', 'url' => $endpoint ], JSON_UNESCAPED_SLASHES );
            return 'https://insiders.vscode.dev/redirect/mcp/install?name=' . rawurlencode( $domain ) . '&config=' . rawurlencode( $config );
        }
        return '';
    }

    private static function render_verify_step( string $slug, string $domain ): void {
        $text = match ( $slug ) {
            /* translators: %s: the MCP server name shown in the client. */
            'claude-code' => sprintf( __( 'Run <code>/mcp</code> in a new Claude Code session — <code>%s</code> should be listed as connected.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'codex'       => sprintf( __( 'Start Codex and run <code>/mcp</code> — <code>%s</code> should be listed with its tools.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'opencode'    => sprintf( __( 'Run <code>opencode mcp list</code> — <code>%s</code> should be listed as connected.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'cursor'      => sprintf( __( 'Open <code>Customize</code> in the Cursor sidebar — <code>%s</code> should be listed and enabled. Errors show under <code>Output → MCP Logs</code>.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'gemini-cli'  => sprintf( __( 'Run <code>gemini mcp list</code> — <code>%s</code> should show as connected.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'vscode'      => sprintf( __( 'Open Copilot Chat in agent mode and click the tools icon — <code>%s</code> should be listed. Errors show under <code>Output → MCP</code>.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'muse-code'   => sprintf( __( 'Start <code>muse</code> and run <code>/mcp</code> — <code>%s</code> should be listed with its tools.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'openclaw'    => sprintf( __( 'Ask OpenClaw what the server can do, or run <code>openclaw mcp probe %s</code> — it should report 2 tools.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'hermes'      => sprintf( __( 'Hermes replies with the tools it found; to check later, run <code>hermes mcp test %s</code>.', 'cowboy-mcp' ), esc_html( $domain ) ),
            default       => '',
        };
        ?>
        <?php self::step_open( '3', __( 'Check it worked', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo wp_kses( $text, [ 'code' => [] ] ); ?> <?php esc_html_e( 'Then try:', 'cowboy-mcp' ); ?></p>
                <?php self::render_example_prompt( $slug, $domain ); ?>
        <?php self::step_close(); ?>
        <?php
    }

    /**
     * The one example prompt: short and read-only (owner, 2026-10-16) — it shows how it works and proves the
     * connection, and it also works on a read-only credential (wp_site_info). $name points the app at this site:
     * the server name for terminal/IDE clients, the host for cloud apps (their connector is whatever the admin named it).
     */
    private static function render_example_prompt( string $slug, string $name ): void {
        /* translators: %s: the MCP server name or the site's address. */
        $prompt = sprintf( __( "Using %s, tell me this site's name and WordPress version. Don't change anything.", 'cowboy-mcp' ), $name );
        self::render_paste_field( 'mcp-prompt-' . $slug, $prompt, '', __( 'Copy prompt', 'cowboy-mcp' ) );
    }

    /** Last step of the cloud-app panels (claude.ai, Claude Desktop connector, ChatGPT, Gemini, Grok, Perplexity, Muse). */
    private static function render_try_step( string $num, string $slug ): void {
        self::step_open( $num, __( 'Try it', 'cowboy-mcp' ), '' );
        ?>
                <p><?php esc_html_e( 'In a new chat, ask:', 'cowboy-mcp' ); ?></p>
        <?php
        self::render_example_prompt( $slug, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        self::step_close();
    }

    /**
     * The one "paste from" field for everything the admin pastes into another app — connection links, chat messages,
     * the n8n endpoint and the example prompt (owner, 2026-10-16: one unified input). Shell commands and config files
     * keep the terminal block (render_code()). $attrs become data-cmcp-* on the Copy button (data-cmcp-start → a watch).
     */
    private static function render_paste_field( string $id, string $text, string $label, string $copy_label, array $attrs = [] ): void {
        $data = '';
        foreach ( $attrs as $k => $v ) {
            $data .= ' data-cmcp-' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
        }
        ?>
        <div class="cmcp-paste">
            <?php if ( '' !== $label ) : ?><span class="cmcp-paste-label"><?php echo esc_html( $label ); ?></span><?php endif; ?>
            <div class="cmcp-paste-field">
                <div class="cmcp-paste-text" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $text ); ?></div>
                <button type="button" class="cmcp-btn cmcp-btn--sm cmcp-paste-copy mcp-copy-btn" data-copy-target="<?php echo esc_attr( $id ); ?>"<?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each key/value escaped above. ?> aria-label="<?php echo esc_attr( $copy_label ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * Category-grouped tool checklist, rendered ONCE per page as a <template>
     * and cloned into whichever scope form selects "Custom" (see mcp-admin.js).
     * Static guard keeps repeat call sites free (keys panels + connections table).
     */
    private static function render_scope_checklist_template(): void {
        static $rendered = false;
        if ( $rendered ) {
            return;
        }
        $rendered = true;
        $catalog  = Cowboy_MCP_Tools::get_tool_catalog();
        ?>
        <template id="mcp-scope-checklist-template">
            <div class="cmcp-scope-bulk">
                <button type="button" class="cmcp-linkbtn" data-cmcp-scope-all="1"><?php esc_html_e( 'Select all', 'cowboy-mcp' ); ?></button>
                <span aria-hidden="true">&middot;</span>
                <button type="button" class="cmcp-linkbtn" data-cmcp-scope-all="0"><?php esc_html_e( 'Deselect all', 'cowboy-mcp' ); ?></button>
            </div>
            <div class="mcp-scope-checklist cmcp-scope-checklist">
                <?php foreach ( $catalog['categories'] as $cat => $info ) : ?>
                    <details class="mcp-scope-cat cmcp-scope-cat">
                        <summary>
                            <input type="checkbox" class="mcp-scope-cat-all" aria-label="<?php
                                /* translators: %s: tool category name */
                                echo esc_attr( sprintf( __( 'Select all %s tools', 'cowboy-mcp' ), $cat ) );
                            ?>">
                            <strong><?php echo esc_html( $cat ); ?></strong>
                            <span class="mcp-scope-cat-count">(<?php echo (int) $info['count']; ?>)</span>
                        </summary>
                        <?php foreach ( $info['tools'] as $tool ) : ?>
                            <label class="mcp-scope-tool">
                                <input type="checkbox" name="allowed_tools[]" value="<?php echo esc_attr( $tool['name'] ); ?>">
                                <code><?php echo esc_html( $tool['name'] ); ?></code>
                                <span class="description"><?php echo esc_html( $tool['description'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e( 'Note: read-only MCP resources (site info, user list, recent posts, etc.) are always available to every credential regardless of tool scope.', 'cowboy-mcp' ); ?></p>
            </div>
        </template>
        <?php
    }

    /**
     * Scope radio group + empty custom slot. $tools_json pre-checks the clone
     * (JSON array of tool names, stored on the form as data-scope-tools).
     */
    private static function render_scope_radios( string $mode, string $tools_json = '[]' ): void {
        $opts = [ 'full' => __( 'Full access', 'cowboy-mcp' ), 'read_only' => __( 'Read-only', 'cowboy-mcp' ), 'custom' => __( 'Custom…', 'cowboy-mcp' ) ];
        ?>
        <div class="mcp-scope-select cmcp-scope-select" data-scope-tools="<?php echo esc_attr( $tools_json ); ?>">
            <div class="cmcp-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Access level', 'cowboy-mcp' ); ?>">
                <?php foreach ( $opts as $value => $label ) : ?>
                    <label class="cmcp-seg-opt"><input type="radio" name="key_scope_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>><span><?php echo esc_html( $label ); ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="mcp-scope-custom-slot cmcp-scope-slot" <?php echo 'custom' === $mode ? '' : 'hidden'; ?>></div>
        </div>
        <?php
    }

    /** Human badge for a stored scope array. */
    public static function scope_badge( ?array $scope ): string {
        if ( null === $scope ) {
            return __( 'Full access', 'cowboy-mcp' );
        }
        $mode = $scope['mode'] ?? '';
        if ( 'read_only' === $mode ) {
            return __( 'Read-only', 'cowboy-mcp' );
        }
        if ( 'custom' === $mode ) {
            /* translators: %d: number of tools this credential may call */
            return sprintf( __( 'Custom (%d tools)', 'cowboy-mcp' ), count( $scope['allowed_tools'] ?? [] ) );
        }
        if ( 'full' === $mode ) {
            return __( 'Full access', 'cowboy-mcp' );
        }
        // An unrecognized non-empty mode is a corrupted/tampered scope record —
        // fail closed rather than silently granting full access.
        return __( 'Unknown (blocked)', 'cowboy-mcp' );
    }

    /* ── Page ─────────────────────────────────────────────── */

    public static function render_blocked_callout(): void {
        if ( ! class_exists( 'Cowboy_MCP_OAuth' ) || empty( get_option( 'cowboy_mcp_settings', [] )['oauth_enabled'] ) ) {
            return;
        }
        $attempts = array_slice( Cowboy_MCP_OAuth::blocked_attempts(), 0, 3 );
        if ( ! $attempts ) {
            return;
        }
        $left = Cowboy_MCP_OAuth::registration_seconds_left();
        ?>
        <div class="cmcp-callout cmcp-callout--warn cmcp-blocked" role="status" data-cmcp-blocked>
            <button type="button" class="cmcp-blocked-x" aria-label="<?php esc_attr_e( 'Dismiss', 'cowboy-mcp' ); ?>">&times;</button>
            <?php foreach ( $attempts as $a ) : ?>
                <p><strong><?php
                    /* translators: 1: app name such as ChatGPT, 2: relative time such as "4 minutes" */
                    echo esc_html( sprintf( __( '%1$s tried to connect %2$s ago, but new connections were off.', 'cowboy-mcp' ), $a['app'], human_time_diff( (int) $a['at'] ) ) );
                ?></strong><?php if ( (int) $a['count'] > 1 ) : ?> <?php
                    /* translators: %d: number of attempts */
                    echo esc_html( sprintf( _n( '(%d attempt)', '(%d attempts)', (int) $a['count'], 'cowboy-mcp' ), (int) $a['count'] ) );
                endif; ?></p>
                <?php if ( empty( $a['allowlisted'] ) && '' !== $a['host'] ) : ?>
                    <p class="description"><?php
                        /* translators: %s: host name */
                        echo esc_html( sprintf( __( 'Its sign-in address (%s) is not on the allowed list either — add it to the extra sign-in hosts on the Settings tab.', 'cowboy-mcp' ), $a['host'] ) );
                    ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ( Cowboy_MCP_OAuth::registration_open() ) : ?>
                <p><?php
                    /* translators: %s: app name such as ChatGPT */
                    echo esc_html( sprintf( __( 'New connections are on — try again in %s now.', 'cowboy-mcp' ), $attempts[0]['app'] ) );
                    if ( $left > 0 ) {
                        echo ' (';
                        /* translators: %s: countdown such as 29:59 */
                        printf( esc_html__( '%s left', 'cowboy-mcp' ), '<span data-mcp-lock-until="' . esc_attr( (string) ( time() + $left ) ) . '">' . esc_html( gmdate( 'i:s', $left ) ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo ')';
                    }
                ?></p>
            <?php else : ?>
                <form method="post" class="mcp-inline-form">
                    <?php wp_nonce_field( 'cowboy_mcp_toggle_connections' ); ?>
                    <button type="submit" name="cowboy_mcp_toggle_connections" value="enable" class="cmcp-btn cmcp-btn--primary"><?php esc_html_e( 'Enable for 30 minutes', 'cowboy-mcp' ); ?></button>
                    <span><?php
                        /* translators: %s: app name such as ChatGPT */
                        echo esc_html( sprintf( __( 'Then add the app again in %s.', 'cowboy-mcp' ), $attempts[0]['app'] ) );
                    ?></span>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_tab( string $endpoint, $new_key ): void {
        $registry    = self::client_registry();
        $is_local    = self::site_looks_local();
        $keys        = Cowboy_MCP_Auth::list_keys();
        $connections = self::oauth_connections();
        $has_creds   = ! empty( $keys ) || ! empty( $connections );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
        $flow_open   = ! $has_creds || $new_key || isset( $_GET['connect'] );
        $active      = (string) get_user_meta( get_current_user_id(), 'cowboy_mcp_conn_client', true );
        if ( ! array_key_exists( $active, $registry ) ) {
            $active = '';
        }
        self::render_blocked_callout();
        self::render_connect_status();
        ?>
        <p class="cmcp-lede"><?php
            echo wp_kses(
                __( 'Connect AI agents like <strong>Claude</strong>, <strong>ChatGPT</strong>, or <strong>Codex</strong> to this WordPress site over the Model Context Protocol.', 'cowboy-mcp' ),
                [ 'strong' => [] ]
            );
        ?></p>
        <?php
        if ( $flow_open ) {
            self::render_connect_flow( $registry, $active, $is_local, $keys, $endpoint, $new_key, $has_creds );
        }
        if ( $has_creds ) {
            self::render_credentials_table( $keys, $connections, $flow_open );
        }
        self::render_doctor();
        self::render_scope_checklist_template();
    }

    /** OAuth connections are listed only while the connector is on (unchanged rule). */
    private static function oauth_connections(): array {
        $s = get_option( 'cowboy_mcp_settings', [] );
        return ( class_exists( 'Cowboy_MCP_OAuth' ) && ! empty( $s['enabled'] ) && ! empty( $s['oauth_enabled'] ) )
            ? Cowboy_MCP_OAuth::list_connections()
            : [];
    }

    private static function render_connect_flow( array $registry, string $active, bool $is_local, array $keys, string $endpoint, $new_key, bool $has_creds ): void {
        ?>
        <section class="cmcp-card cmcp-connect" aria-labelledby="cmcp-connect-h">
            <div class="cmcp-card-h">
                <h2 id="cmcp-connect-h"><?php echo $has_creds ? esc_html__( 'Connect another app', 'cowboy-mcp' ) : esc_html__( 'Connect your first app', 'cowboy-mcp' ); ?></h2>
                <span class="cmcp-sub"><?php esc_html_e( 'Pick your AI app or coding tool — setup takes about a minute.', 'cowboy-mcp' ); ?></span>
                <?php if ( $has_creds && ! $new_key ) : // A pending new key keeps the flow open until "I've saved my key". ?>
                    <div class="cmcp-right"><a class="cmcp-btn cmcp-btn--sm cmcp-btn--ghost" href="<?php echo esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'connection' ] ) ); ?>"><?php esc_html_e( 'Close', 'cowboy-mcp' ); ?></a></div>
                <?php endif; ?>
            </div>
            <div class="cmcp-add">
                <?php self::render_client_sidebar( $registry, $active, $is_local, $endpoint ); ?>
                <div class="cmcp-steps mcp-conn-main">
                    <div class="mcp-client-panel mcp-client-panel--placeholder <?php echo esc_attr( '' === $active ? 'mcp-client-panel--active' : '' ); ?>" data-client-panel="">
                        <div class="cmcp-placeholder">
                            <h3><?php esc_html_e( 'Which app do you want to connect?', 'cowboy-mcp' ); ?></h3>
                            <p><?php esc_html_e( 'Pick your AI app or coding tool from the list to see step-by-step setup instructions.', 'cowboy-mcp' ); ?></p>
                        </div>
                    </div>
                    <?php foreach ( $registry as $slug => $client ) : ?>
                        <div id="mcp-client-panel-<?php echo esc_attr( $slug ); ?>"
                             class="mcp-client-panel <?php echo esc_attr( $slug === $active ? 'mcp-client-panel--active' : '' ); ?>"
                             data-client-panel="<?php echo esc_attr( $slug ); ?>"
                             role="tabpanel" aria-labelledby="mcp-conn-item-<?php echo esc_attr( $slug ); ?>" tabindex="0">
                            <div class="cmcp-steps-head">
                                <h3><?php
                                    /* translators: %s: AI client name, e.g. "Claude Code" */
                                    echo esc_html( sprintf( __( 'Connect %s', 'cowboy-mcp' ), $client['label'] ) );
                                ?></h3>
                            </div>
                            <?php
                            match ( $client['type'] ) {
                                'oauth'      => self::render_oauth_client_panel( $slug, $endpoint, $is_local, $keys, $new_key ),
                                'oauth-cloud' => self::render_cloud_connector_panel( $slug, $client, $endpoint, $is_local ),
                                'n8n'        => self::render_n8n_panel( $slug, $client, $keys, $endpoint, $new_key, $is_local ),
                                'oauth-cli'  => self::render_oauth_cli_panel( $slug, $client['label'], $keys, $endpoint, $new_key, $is_local ),
                                'oauth-link' => self::render_oauth_link_panel( $slug, $client['label'], $keys, $endpoint, $new_key, $is_local ),
                                default      => self::render_api_client_panel( $slug, $client['label'], $keys, $endpoint, $new_key, $is_local ),
                            };
                            ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    }

    private static function render_client_sidebar( array $registry, string $active, bool $is_local, string $endpoint ): void {
        $groups = [
            'no-terminal' => __( 'No terminal needed', 'cowboy-mcp' ),
            'terminal'    => __( 'Terminal & IDE tools', 'cowboy-mcp' ),
            'automation'  => __( 'Automation & developers', 'cowboy-mcp' ),
        ];
        $first  = array_key_first( $registry );
        ?>
        <div class="cmcp-chooser">
            <nav class="mcp-conn-sidebar" role="tablist" aria-orientation="vertical" aria-label="<?php echo esc_attr__( 'AI clients', 'cowboy-mcp' ); ?>">
                <?php foreach ( $groups as $group => $group_label ) : ?>
                    <p class="cmcp-grp" role="presentation"><?php echo esc_html( $group_label ); ?></p>
                    <?php foreach ( $registry as $slug => $client ) :
                        if ( $client['group'] !== $group ) {
                            continue;
                        }
                        $on       = ( $slug === $active );
                        $tabindex = ( $on || ( '' === $active && $slug === $first ) ) ? '0' : '-1';
                        ?>
                        <button type="button" id="mcp-conn-item-<?php echo esc_attr( $slug ); ?>"
                                class="cmcp-client mcp-conn-item <?php echo esc_attr( $on ? 'mcp-conn-item--active' : '' ); ?>"
                                data-client="<?php echo esc_attr( $slug ); ?>" role="tab"
                                aria-selected="<?php echo esc_attr( $on ? 'true' : 'false' ); ?>"
                                aria-controls="mcp-client-panel-<?php echo esc_attr( $slug ); ?>"
                                tabindex="<?php echo esc_attr( $tabindex ); ?>">
                            <span class="cmcp-client-ic" aria-hidden="true"><?php self::render_client_icon( $slug ); ?></span>
                            <span class="cmcp-client-label"><?php echo esc_html( $client['label'] ); ?></span>
                            <?php if ( $is_local ) : ?>
                                <?php if ( 'tunnel' === ( $client['local'] ?? '' ) ) : ?>
                                    <span class="cmcp-hint cmcp-hint--pub"><?php esc_html_e( 'Needs public URL', 'cowboy-mcp' ); ?></span>
                                <?php else : ?>
                                    <span class="cmcp-hint"><?php esc_html_e( 'Works locally', 'cowboy-mcp' ); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    /* ── Step + code partials ─────────────────────────────── */

    private static function step_open( string $num, string $title, string $state = '' ): void {
        ?>
        <div class="cmcp-step <?php echo esc_attr( '' !== $state ? 'cmcp-step--' . $state : '' ); ?>">
            <span class="cmcp-num" aria-hidden="true"><?php echo esc_html( $num ); ?></span>
            <div class="cmcp-step-body">
                <h4 class="cmcp-step-title"><?php echo esc_html( $title ); ?></h4>
        <?php
    }

    private static function step_close(): void {
        echo '</div></div>';
    }

    private static function render_code( string $id, string $text, string $title, string $copy_label, array $attrs = [] ): void {
        $data = '';
        foreach ( $attrs as $k => $v ) {
            $data .= ' data-cmcp-' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
        }
        ?>
        <div class="cmcp-term-wrap">
            <div class="cmcp-term-bar">
                <span class="cmcp-lamp" aria-hidden="true"></span>
                <span><?php echo esc_html( $title ); ?></span>
                <button type="button" class="cmcp-term-copy mcp-copy-btn" data-copy-target="<?php echo esc_attr( $id ); ?>"<?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each key/value escaped above. ?> aria-label="<?php echo esc_attr( $copy_label ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
            </div>
            <pre class="cmcp-term" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $text ); ?></pre>
        </div>
        <?php
    }

    /* ── Connected apps ───────────────────────────────────── */

    private static function render_credentials_table( array $keys, array $connections, bool $flow_open ): void {
        ?>
        <section class="cmcp-card" aria-labelledby="cmcp-apps-h">
            <div class="cmcp-card-h">
                <h2 id="cmcp-apps-h"><?php esc_html_e( 'Connected apps', 'cowboy-mcp' ); ?></h2>
                <?php if ( ! $flow_open ) : ?>
                    <div class="cmcp-right">
                        <a class="cmcp-btn cmcp-btn--primary" data-cmcp-connect href="<?php echo esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'connection', 'connect' => '1' ] ) ); ?>"><?php
                            echo Cowboy_MCP_Admin::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal.
                            esc_html_e( 'Connect another app', 'cowboy-mcp' );
                        ?></a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="cmcp-table-wrap">
                <table class="cmcp-t cmcp-apps">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e( 'Name', 'cowboy-mcp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Type', 'cowboy-mcp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Access', 'cowboy-mcp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Created', 'cowboy-mcp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Last used', 'cowboy-mcp' ); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'cowboy-mcp' ); ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    foreach ( $keys as $k ) {
                        // Display name may be client-supplied (clientInfo title/name): render_credential_row() escapes 'sub'.
                        $who = class_exists( 'Cowboy_MCP_OAuth' ) ? Cowboy_MCP_OAuth::client_display_name( $k['client_info'] ?? null ) : null;
                        self::render_credential_row( [
                            'name'      => (string) $k['label'],
                            'sub'       => ( $who ? $who . ' · ' : '' ) . $k['prefix'] . '…',
                            'mono'      => true,
                            'type'      => __( 'API key', 'cowboy-mcp' ),
                            'scope'     => $k['scope'] ?? null,
                            'created'   => (int) $k['created'],
                            'last_used' => (int) $k['last_used'],
                            'id_field'  => 'key_id',
                            'id'        => (string) $k['id'],
                            'revoke'    => 'cowboy_mcp_revoke_key',
                            'update'    => 'cowboy_mcp_update_key_scope',
                            'warn'      => __( 'Revoke this key? Any client using it will lose access.', 'cowboy-mcp' ),
                        ] );
                    }
                    foreach ( $connections as $c ) {
                        $who = class_exists( 'Cowboy_MCP_OAuth' ) ? Cowboy_MCP_OAuth::client_display_name( $c['client_info'] ?? null ) : null;
                        if ( null === $who && ! empty( $c['slug'] ) && isset( Cowboy_MCP_OAuth::CLIENT_LABELS[ $c['slug'] ] ) ) {
                            $who = Cowboy_MCP_OAuth::CLIENT_LABELS[ $c['slug'] ]; // identity from the verified redirect host, e.g. "Gemini" on Google's "Google" row
                        }
                        if ( $who && $who !== (string) $c['client_name'] ) {
                            /* translators: 1: app name detected from its first request, 2: WordPress username of the approving admin */
                            $sub = sprintf( __( '%1$s · approved by %2$s', 'cowboy-mcp' ), $who, $c['user'] );
                        } else {
                            /* translators: %s: WordPress username of the admin who approved the connection */
                            $sub = sprintf( __( 'approved by %s', 'cowboy-mcp' ), $c['user'] );
                        }
                        self::render_credential_row( [
                            'name'      => (string) $c['client_name'],
                            'sub'       => $sub,
                            'mono'      => false,
                            'type'      => __( 'OAuth', 'cowboy-mcp' ),
                            'scope'     => $c['tool_scope'] ?? null,
                            'created'   => (int) $c['created'],
                            'last_used' => (int) $c['last_used'],
                            'id_field'  => 'oauth_client_id',
                            'id'        => (string) $c['client_id'],
                            'revoke'    => 'cowboy_mcp_revoke_oauth',
                            'update'    => 'cowboy_mcp_update_oauth_scope',
                            'warn'      => __( 'Revoke this connection? The app will lose access immediately.', 'cowboy-mcp' ),
                        ] );
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php
    }

    private static function render_credential_row( array $r ): void {
        $recent = $r['last_used'] && ( time() - $r['last_used'] ) < 15 * MINUTE_IN_SECONDS;
        $scope  = is_array( $r['scope'] ) ? $r['scope'] : null;
        ?>
        <tr>
            <td class="cmcp-nm-cell">
                <div class="cmcp-nm"><?php echo esc_html( $r['name'] ); ?></div>
                <div class="cmcp-sub<?php echo $r['mono'] ? ' cmcp-mono' : ''; ?>"><?php echo esc_html( $r['sub'] ); ?></div>
            </td>
            <td><?php echo esc_html( $r['type'] ); ?></td>
            <td><span class="cmcp-bdg<?php echo null === $scope ? '' : ' cmcp-bdg--outline'; ?>"><?php echo esc_html( self::scope_badge( $scope ) ); ?></span></td>
            <td><?php echo esc_html( $r['created'] ? wp_date( 'M j, Y', $r['created'] ) : '—' ); ?></td>
            <td><span class="cmcp-seen"><span class="cmcp-dot<?php echo $recent ? '' : ' cmcp-dot--off'; ?>" aria-hidden="true"></span><?php
                if ( $r['last_used'] ) {
                    /* translators: %s: human-readable time difference, e.g. "2 hours" */
                    printf( esc_html__( '%s ago', 'cowboy-mcp' ), esc_html( human_time_diff( $r['last_used'] ) ) );
                } else {
                    esc_html_e( 'Never', 'cowboy-mcp' );
                }
            ?></span></td>
            <td class="cmcp-actions">
                <details class="cmcp-menu">
                    <summary class="cmcp-kebab" aria-label="<?php
                        /* translators: %s: credential name */
                        echo esc_attr( sprintf( __( 'Actions for %s', 'cowboy-mcp' ), $r['name'] ) );
                    ?>"><?php echo Cowboy_MCP_Admin::icon( 'kebab' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal. ?></summary>
                    <div class="cmcp-menu-list">
                        <button type="button" class="cmcp-menu-item" data-cmcp-edit-scope><?php esc_html_e( 'Edit access…', 'cowboy-mcp' ); ?></button>
                        <form method="post" data-cmcp-confirm="<?php echo esc_attr( $r['warn'] ); ?>" data-cmcp-confirm-tone="danger" data-cmcp-confirm-label="<?php esc_attr_e( 'Revoke', 'cowboy-mcp' ); ?>">
                            <?php wp_nonce_field( $r['revoke'] ); ?>
                            <input type="hidden" name="<?php echo esc_attr( $r['id_field'] ); ?>" value="<?php echo esc_attr( $r['id'] ); ?>">
                            <button type="submit" name="<?php echo esc_attr( $r['revoke'] ); ?>" value="1" class="cmcp-menu-item cmcp-menu-item--danger"><?php esc_html_e( 'Revoke…', 'cowboy-mcp' ); ?></button>
                        </form>
                    </div>
                </details>
            </td>
        </tr>
        <tr class="cmcp-panel-row" data-cmcp-scope-row hidden>
            <td colspan="6">
                <form method="post" class="cmcp-scope-editor">
                    <?php wp_nonce_field( $r['update'] ); ?>
                    <input type="hidden" name="<?php echo esc_attr( $r['id_field'] ); ?>" value="<?php echo esc_attr( $r['id'] ); ?>">
                    <p class="cmcp-scope-title"><?php
                        /* translators: %s: credential name */
                        echo wp_kses( sprintf( __( 'Access for <strong>%s</strong>', 'cowboy-mcp' ), esc_html( $r['name'] ) ), [ 'strong' => [] ] );
                    ?></p>
                    <?php self::render_scope_radios( $scope['mode'] ?? 'full', (string) wp_json_encode( $scope['allowed_tools'] ?? [] ) ); ?>
                    <div class="cmcp-formrow">
                        <button type="button" class="cmcp-btn cmcp-btn--sm" data-cmcp-scope-cancel><?php esc_html_e( 'Cancel', 'cowboy-mcp' ); ?></button>
                        <button type="submit" name="<?php echo esc_attr( $r['update'] ); ?>" value="1" class="cmcp-btn cmcp-btn--sm cmcp-btn--primary"><?php esc_html_e( 'Save access', 'cowboy-mcp' ); ?></button>
                    </div>
                </form>
            </td>
        </tr>
        <?php
    }

    /* ── Connection Doctor (results rendered by mcp-admin.js) ── */

    private static function render_doctor(): void {
        ?>
        <section class="cmcp-card cowboy-doctor" id="cowboy-doctor" aria-labelledby="cmcp-doctor-h">
            <div class="cmcp-card-h"><h2 id="cmcp-doctor-h"><?php esc_html_e( 'Connection Doctor', 'cowboy-mcp' ); ?></h2></div>
            <div class="cmcp-doc-body">
                <p><?php esc_html_e( 'Test whether AI clients can reach this site and get a copy-pasteable diagnosis for anything broken.', 'cowboy-mcp' ); ?></p>
                <?php if ( Cowboy_MCP_Doctor::request_via_cloudflare() ) : // Advisory: CF-Ray on this admin request means Cloudflare sits in front of the site. ?>
                    <div class="cmcp-callout cmcp-callout--info cmcp-doctor-cf"><p><?php
                        echo wp_kses( __( '<strong>This site is behind Cloudflare.</strong> Some Cloudflare security settings, such as Browser Integrity Check and Bot Fight Mode, can block AI apps before WordPress sees them. Run the checks to test it.', 'cowboy-mcp' ), [ 'strong' => [] ] );
                    ?> <a href="<?php echo esc_url( Cowboy_MCP_Doctor::CLOUDFLARE_GUIDE ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'How to let AI apps through Cloudflare', 'cowboy-mcp' ); ?> &#x2197;<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'cowboy-mcp' ); ?></span></a></p></div>
                <?php endif; ?>
                <div class="cmcp-formrow">
                    <button type="button" class="cmcp-btn cmcp-btn--primary" id="cowboy-doctor-run"><?php esc_html_e( 'Run checks', 'cowboy-mcp' ); ?></button>
                    <button type="button" class="cmcp-btn" id="cowboy-doctor-copy" hidden><?php esc_html_e( 'Copy report', 'cowboy-mcp' ); ?></button>
                    <a class="cmcp-linkbtn" id="cowboy-doctor-help" href="https://wordpress.org/support/plugin/cowboy-mcp/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get help on WordPress.org', 'cowboy-mcp' ); ?> &#x2197;</a>
                </div>
                <div id="cowboy-doctor-results" aria-live="polite"></div>
            </div>
        </section>
        <?php
    }
}
