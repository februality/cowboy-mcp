<?php
/**
 * Cowboy MCP – Admin: Connections page (connect flow, credentials, Doctor).
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Admin_Connections {

    // Verified 2026-10-06 against live ChatGPT: the Plugins page; its + menu offers 'Add custom MCP server'.
    const CHATGPT_URL = 'https://chatgpt.com/plugins';

    /** Remember (30 min, per admin) which app the admin just started connecting. */
    public static function set_connect_watch( string $app ): void {
        set_transient( 'cowboy_mcp_connect_watch_' . get_current_user_id(), [ 'app' => $app, 'since' => time() ], 30 * MINUTE_IN_SECONDS );
    }

    /** @return array{app:string,since:int}|null */
    public static function connect_watch(): ?array {
        $w = get_transient( 'cowboy_mcp_connect_watch_' . get_current_user_id() );
        return ( is_array( $w ) && isset( $w['app'], $w['since'] ) ) ? $w : null;
    }

    /**
     * Read-only: how far the watched app has got since the click. Never writes
     * clients or tokens. Only clients whose redirect host maps to the watched
     * app's label count, so unrelated connections can never report "connected".
     *
     * @param array{app:string,since:int} $watch
     * @return array{stage:string,reused:bool,app:string}
     */
    public static function connect_status( array $watch ): array {
        $label   = 'chatgpt' === $watch['app'] ? 'ChatGPT' : 'Claude';
        $since   = (int) $watch['since'];
        $clients = (array) get_option( 'cowboy_mcp_oauth_clients', [] );
        $tokens  = array_merge( array_values( (array) get_option( 'cowboy_mcp_oauth_tokens', [] ) ), array_values( (array) get_option( 'cowboy_mcp_oauth_refresh', [] ) ) );
        $order   = [ 'waiting' => 0, 'registered' => 1, 'approved' => 2, 'connected' => 3 ];
        $stage   = 'waiting';
        $reused  = false;
        foreach ( $clients as $cid => $c ) {
            if ( ! is_array( $c ) ) {
                continue;
            }
            $host = (string) wp_parse_url( (string) ( $c['redirect_uris'][0] ?? '' ), PHP_URL_HOST );
            if ( '' === $host || Cowboy_MCP_OAuth::app_label_for_host( $host ) !== $label ) {
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
            }
        }
        if ( 'waiting' === $stage ) {
            foreach ( Cowboy_MCP_OAuth::blocked_attempts() as $a ) {
                if ( $a['app'] === $label && (int) $a['at'] >= $since ) {
                    $stage = 'blocked';
                    break;
                }
            }
        }
        return [ 'stage' => $stage, 'reused' => $reused, 'app' => $watch['app'] ];
    }

    /** Whole translated sentences per app and stage. */
    public static function connect_status_text( array $st ): string {
        $gpt = 'chatgpt' === $st['app'];
        return match ( $st['stage'] ) {
            'registered' => $gpt ? __( 'ChatGPT found your site — approve access on the sign-in page.', 'cowboy-mcp' ) : __( 'Claude found your site — approve access on the sign-in page.', 'cowboy-mcp' ),
            'approved'   => $gpt ? __( 'Approved — waiting for ChatGPT\'s first request.', 'cowboy-mcp' ) : __( 'Approved — waiting for Claude\'s first request.', 'cowboy-mcp' ),
            'connected'  => ! empty( $st['reused'] )
                ? ( $gpt ? __( '✓ Connected — ChatGPT reused your earlier connection.', 'cowboy-mcp' ) : __( '✓ Connected — Claude reused your earlier connection.', 'cowboy-mcp' ) )
                : ( $gpt ? __( '✓ Connected — ChatGPT can now use this site.', 'cowboy-mcp' ) : __( '✓ Connected — Claude can now use this site.', 'cowboy-mcp' ) ),
            'blocked'    => $gpt ? __( 'ChatGPT tried to connect, but new connections were off.', 'cowboy-mcp' ) : __( 'Claude tried to connect, but new connections were off.', 'cowboy-mcp' ),
            default      => $gpt ? __( 'Waiting for ChatGPT…', 'cowboy-mcp' ) : __( 'Waiting for Claude…', 'cowboy-mcp' ),
        };
    }

    public static function render_connect_status(): void {
        $w = self::connect_watch();
        if ( ! $w || ! class_exists( 'Cowboy_MCP_OAuth' ) ) {
            return;
        }
        $st = self::connect_status( $w );
        ?>
        <div class="cmcp-connect-status cmcp-connect-status--<?php echo esc_attr( $st['stage'] ); ?>" aria-live="polite" data-cmcp-connect-watch data-stage="<?php echo esc_attr( $st['stage'] ); ?>">
            <span class="cmcp-connect-status-text"><?php echo esc_html( self::connect_status_text( $st ) ); ?></span>
            <a class="cmcp-connect-status-refresh" href="<?php echo esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'connection' ] ) ); ?>" <?php echo 'connected' === $st['stage'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Refresh list', 'cowboy-mcp' ); ?></a>
        </div>
        <?php
    }

    public static function client_registry(): array {
        // 'local' is how the client behaves against a local dev site: it either
        // works as-is ('works'), works through the on-machine mcp-remote bridge
        // ('bridge'), or is cloud-initiated and needs a public URL ('tunnel').
        // Display metadata only — never used for gating.
        return [
            'claude-ai'      => [ 'label' => 'claude.ai',      'group' => 'no-terminal', 'type' => 'oauth',  'local' => 'tunnel' ],
            'claude-desktop' => [ 'label' => 'Claude Desktop', 'group' => 'no-terminal', 'type' => 'oauth',  'local' => 'bridge' ],
            'chatgpt'        => [ 'label' => 'ChatGPT',        'group' => 'no-terminal', 'type' => 'oauth',  'local' => 'tunnel' ],
            'claude-code'    => [ 'label' => 'Claude Code',    'group' => 'terminal',    'type' => 'apikey', 'local' => 'works' ],
            'codex'          => [ 'label' => 'Codex',          'group' => 'terminal',    'type' => 'apikey', 'local' => 'works' ],
            'opencode'       => [ 'label' => 'Opencode',       'group' => 'terminal',    'type' => 'apikey', 'local' => 'works' ],
            'cursor'         => [ 'label' => 'Cursor',         'group' => 'terminal',    'type' => 'apikey', 'local' => 'works' ],
            'gemini-cli'     => [ 'label' => 'Gemini CLI',     'group' => 'terminal',    'type' => 'apikey', 'local' => 'works' ],
        ];
    }

    /** Whether this site's host looks like a local development site (advisory, UI only). */
    private static function site_looks_local(): bool {
        return Cowboy_MCP_Security::host_looks_local( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    }

    /**
     * Echo the inline SVG logo for a client (monochrome, fill = currentColor).
     * Marks: Claude, Cursor, Google Gemini and OpenCode from Simple Icons 16.34.0 (CC0 vectors of the
     * brands' own marks); OpenAI's blossom from OpenAI's official logo pack (cdn.openai.com/brand),
     * which also stands for Codex. Used only to identify the app being connected; no endorsement.
     */
    private static function render_client_icon( string $slug ): void {
        $claude = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z"></path></svg>';
        $openai = '<svg viewBox="118.5 117.8 484.2 484.2" fill="currentColor"><path d="M304.246 294.611V249.028C304.246 245.189 305.687 242.309 309.044 240.392L400.692 187.612C413.167 180.415 428.042 177.058 443.394 177.058C500.971 177.058 537.44 221.682 537.44 269.182C537.44 272.54 537.44 276.379 536.959 280.218L441.954 224.558C436.197 221.201 430.437 221.201 424.68 224.558L304.246 294.611ZM518.245 472.145V363.224C518.245 356.505 515.364 351.707 509.608 348.349L389.174 278.296L428.519 255.743C431.877 253.826 434.757 253.826 438.115 255.743L529.762 308.523C556.154 323.879 573.905 356.505 573.905 388.171C573.905 424.636 552.315 458.225 518.245 472.141V472.145ZM275.937 376.182L236.592 353.152C233.235 351.235 231.794 348.354 231.794 344.515V238.956C231.794 187.617 271.139 148.749 324.4 148.749C344.555 148.749 363.264 155.468 379.102 167.463L284.578 222.164C278.822 225.521 275.942 230.319 275.942 237.039V376.186L275.937 376.182ZM360.626 425.122L304.246 393.455V326.283L360.626 294.616L417.002 326.283V393.455L360.626 425.122ZM396.852 570.989C376.698 570.989 357.989 564.27 342.151 552.276L436.674 497.574C442.431 494.217 445.311 489.419 445.311 482.699V343.552L485.138 366.582C488.495 368.499 489.936 371.379 489.936 375.219V480.778C489.936 532.117 450.109 570.985 396.852 570.985V570.989ZM283.134 463.99L191.486 411.211C165.094 395.854 147.343 363.229 147.343 331.562C147.343 294.616 169.415 261.509 203.48 247.593V356.991C203.48 363.71 206.361 368.508 212.117 371.866L332.074 441.437L292.729 463.99C289.372 465.907 286.491 465.907 283.134 463.99ZM277.859 542.68C223.639 542.68 183.813 501.895 183.813 451.514C183.813 447.675 184.294 443.836 184.771 439.997L279.295 494.698C285.051 498.056 290.812 498.056 296.568 494.698L417.002 425.127V470.71C417.002 474.549 415.562 477.429 412.204 479.346L320.557 532.126C308.081 539.323 293.206 542.68 277.854 542.68H277.859ZM396.852 599.776C454.911 599.776 503.37 558.513 514.41 503.812C568.149 489.896 602.696 439.515 602.696 388.176C602.696 354.587 588.303 321.962 562.392 298.45C564.791 288.373 566.231 278.296 566.231 268.224C566.231 199.611 510.571 148.267 446.274 148.267C433.322 148.267 420.846 150.184 408.37 154.505C386.775 133.392 357.026 119.958 324.4 119.958C266.342 119.958 217.883 161.22 206.843 215.921C153.104 229.837 118.557 280.218 118.557 331.557C118.557 365.146 132.95 397.771 158.861 421.283C156.462 431.36 155.022 441.437 155.022 451.51C155.022 520.123 210.682 571.466 274.978 571.466C287.931 571.466 300.407 569.549 312.883 565.228C334.473 586.341 364.222 599.776 396.852 599.776Z"></path></svg>';
        $icons  = [
            'claude-ai'      => $claude,
            'claude-desktop' => $claude,
            'claude-code'    => $claude,
            'chatgpt'        => $openai,
            'codex'          => $openai,
            'opencode'       => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd"><path d="M22 24H2V0h20zM17 4.8H7v14.4h10z"></path></svg>',
            'cursor'         => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.503.131 1.891 5.678a.84.84 0 0 0-.42.726v11.188c0 .3.162.575.42.724l9.609 5.55a1 1 0 0 0 .998 0l9.61-5.55a.84.84 0 0 0 .42-.724V6.404a.84.84 0 0 0-.42-.726L12.497.131a1.01 1.01 0 0 0-.996 0M2.657 6.338h18.55c.263 0 .43.287.297.515L12.23 22.918c-.062.107-.229.064-.229-.06V12.335a.59.59 0 0 0-.295-.51l-9.11-5.257c-.109-.063-.064-.23.061-.23"></path></svg>',
            'gemini-cli'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.04 19.32Q12 21.51 12 24q0-2.49.93-4.68.96-2.19 2.58-3.81t3.81-2.55Q21.51 12 24 12q-2.49 0-4.68-.93a12.3 12.3 0 0 1-3.81-2.58 12.3 12.3 0 0 1-2.58-3.81Q12 2.49 12 0q0 2.49-.96 4.68-.93 2.19-2.55 3.81a12.3 12.3 0 0 1-3.81 2.58Q2.49 12 0 12q2.49 0 4.68.96 2.19.93 3.81 2.55t2.55 3.81"></path></svg>',
        ];
        echo $icons[ $slug ] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literals defined directly above; no user input.
    }

    /**
     * "New connections" switch (safety lock): the only time the connector accepts a
     * registration from a new AI app. Rendered at the top of each OAuth client
     * panel (claude.ai, Claude app, ChatGPT) once the connector is on - API-key
     * tools never see it because they never register.
     */
    /** $quick: the panel offers "Add to Claude", which opens the window itself. */
    private static function render_connections_gate( bool $quick = false ): void {
        $settings = get_option( 'cowboy_mcp_settings', [] );
        if ( ! class_exists( 'Cowboy_MCP_OAuth' ) || empty( $settings['enabled'] ) || empty( $settings['oauth_enabled'] ) ) {
            return;
        }
        $left = Cowboy_MCP_OAuth::registration_seconds_left();
        $on   = $left > 0;
        ?>
        <div class="cmcp-gate mcp-conn-gate <?php echo $on ? 'mcp-conn-gate--on' : 'mcp-conn-gate--off'; ?>">
                <form method="post" class="mcp-conn-gate-form">
                    <?php wp_nonce_field( 'cowboy_mcp_toggle_connections' ); ?>
                    <span class="mcp-conn-gate-status"><span class="cmcp-dot mcp-conn-gate-dot" aria-hidden="true"></span>
                        <strong class="mcp-conn-gate-label"><?php echo $on ? esc_html__( 'New connections: enabled', 'cowboy-mcp' ) : esc_html__( 'New connections: disabled', 'cowboy-mcp' ); ?></strong>
                        <?php if ( $on ) : ?>
                            <span class="mcp-conn-gate-timer"><?php
                                /* translators: %s: countdown such as 29:59 */
                                printf( esc_html__( '%s left', 'cowboy-mcp' ), '<span data-mcp-lock-until="' . esc_attr( (string) ( time() + $left ) ) . '">' . esc_html( gmdate( 'i:s', $left ) ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?></span>
                        <?php endif; ?>
                    </span>
                    <button type="submit" name="cowboy_mcp_toggle_connections" value="<?php echo $on ? 'disable' : 'enable'; ?>" class="cmcp-btn <?php echo ( $on || $quick ) ? '' : 'cmcp-btn--primary'; ?>"><?php
                        echo $on ? esc_html__( 'Disable now', 'cowboy-mcp' ) : esc_html__( 'Enable for 30 minutes', 'cowboy-mcp' );
                    ?></button>
                </form>
                <p class="description"><?php
                    echo $quick
                        ? esc_html__( 'Add to Claude below turns this on for you. Adding it by hand? Enable it first. It switches itself off after 30 minutes, and connections that already exist keep working either way.', 'cowboy-mcp' )
                        : esc_html__( 'Step one when adding ChatGPT or a Claude app: enable this, then add the app. It switches itself off after 30 minutes. API keys do not use it, and connections that already exist keep working either way.', 'cowboy-mcp' );
                ?></p>
        </div>
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
            self::render_local_oauth_guidance( $slug );
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
        $warning_text = $is_chatgpt
            ? __( '<strong>Heads up:</strong> this site does not appear to be on a public HTTPS address. ChatGPT connects from OpenAI\'s cloud, so it cannot reach local, private, or non-HTTPS sites. A terminal tool works here instead, or connect through a tunnel/staging URL.', 'cowboy-mcp' )
            : __( '<strong>Heads up:</strong> this site does not appear to be on a public HTTPS address. The Claude apps connect from Anthropic\'s cloud, so they cannot reach local, private, or non-HTTPS sites. A terminal tool works here instead, or connect through a tunnel/staging URL.', 'cowboy-mcp' );
        $enable_text  = $is_chatgpt
            ? __( 'This opens a secure sign-in so ChatGPT can connect without a terminal. No access is granted until you approve it in your browser.', 'cowboy-mcp' )
            : __( 'This opens a secure sign-in so the Claude apps can connect without a terminal. No access is granted until you approve it in your browser.', 'cowboy-mcp' );
        $paste_hint   = $is_chatgpt
            ? __( "You'll paste this into ChatGPT in the next step.", 'cowboy-mcp' )
            : __( "You'll paste this into Claude in the next step.", 'cowboy-mcp' );
        $step2_title  = match ( true ) {
            $is_chatgpt => __( 'Add it in ChatGPT', 'cowboy-mcp' ),
            $is_desktop => __( 'Add it in the Claude app', 'cowboy-mcp' ),
            default     => __( 'Add it on claude.ai', 'cowboy-mcp' ),
        };
        $approve_text = $is_chatgpt
            ? __( 'ChatGPT opens a sign-in page on <strong>your site</strong>. Review what it is asking for and click <strong>Approve</strong>. To use it, open the <strong>+</strong> menu in a chat, choose <strong>Developer mode</strong> and select your site.', 'cowboy-mcp' )
            : __( 'Claude opens a sign-in page on <strong>your site</strong>. Review what it is asking for and click <strong>Approve</strong>. To use it, click <strong>+</strong> in a chat, open <strong>Connectors</strong> and make sure your site is switched on.', 'cowboy-mcp' );
        $plan_note    = $is_chatgpt
            ? __( 'Requires a ChatGPT <strong>Plus, Pro, Business, Enterprise or Edu</strong> plan, on the web. On Business and Enterprise workspaces an admin has to allow Developer mode first.', 'cowboy-mcp' )
            : __( 'Custom connectors work on every Claude plan (Free is limited to one). On Team and Enterprise, an organization owner adds the connector first under <strong>Organization settings → Connectors</strong>; members then click <strong>Connect</strong>.', 'cowboy-mcp' );

        if ( $show_warning && ! $reachable ) :
            ?>
            <div class="cmcp-callout"><p><?php
                echo wp_kses( $warning_text, [ 'strong' => [] ] );
            ?></p></div>
            <?php
        endif;

        // Claude's install link only works where Claude can reach the site: public HTTPS
        // (its dialog rejects http://, and it connects from Anthropic's cloud).
        $quick = ! $is_chatgpt && $oauth_avail && $reachable;
        $chatgpt_quick = $is_chatgpt && $oauth_avail && $reachable;

        if ( $oauth_on ) {
            self::render_connections_gate( $quick );
        }

        if ( ! $oauth_on && ! $quick && ! $chatgpt_quick ) :
            // Browsing never flips settings — enabling the connector is an explicit click.
            ?>
            <?php self::step_open( '1', __( 'Turn on the Desktop Connector', 'cowboy-mcp' ), '' ); ?>
                    <p><?php echo esc_html( $enable_text ); ?></p>
                    <form method="post" class="mcp-inline-form">
                        <?php wp_nonce_field( 'cowboy_mcp_enable_oauth' ); ?>
                        <button type="submit" name="cowboy_mcp_enable_oauth" class="cmcp-btn cmcp-btn--primary"><?php esc_html_e( 'Enable Desktop Connector', 'cowboy-mcp' ); ?></button>
                    </form>
            <?php self::step_close(); ?>
            <?php
            return;
        endif;

        if ( $quick ) :
            ?>
            <?php self::step_open( '1', __( 'Add it to Claude', 'cowboy-mcp' ), '' ); ?>
                    <?php self::render_add_to_claude_form( 'me', 'cmcp-btn cmcp-btn--primary', __( 'Add to Claude', 'cowboy-mcp' ) ); ?>
                    <p><?php
                        echo wp_kses(
                            $is_desktop
                                /* translators: %s: this site's MCP endpoint URL */
                                ? sprintf( __( 'Opens claude.ai in a new tab with this site filled in, and turns on new connections for 30 minutes. Check the address is <code>%s</code>, then click <strong>Continue</strong> and <strong>Add</strong>. The connector then shows up in the Claude app too.', 'cowboy-mcp' ), esc_html( $endpoint ) )
                                /* translators: %s: this site's MCP endpoint URL */
                                : sprintf( __( 'Opens claude.ai in a new tab with this site filled in, and turns on new connections for 30 minutes. Check the address is <code>%s</code>, then click <strong>Continue</strong> and <strong>Add</strong>.', 'cowboy-mcp' ), esc_html( $endpoint ) ),
                            [ 'code' => [], 'strong' => [] ]
                        );
                    ?></p>
                    <p class="description"><?php echo wp_kses( __( 'Already added? Claude reports that a connector with this URL already exists. Find it under <strong>Customize → Connectors</strong> and click <strong>Connect</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                    <details class="mcp-local-details">
                        <summary><?php esc_html_e( 'Add it by hand instead', 'cowboy-mcp' ); ?></summary>
                        <div class="mcp-local-details-body">
                            <?php self::render_code( 'mcp-oauth-url-' . $slug, $endpoint, __( 'Connection link', 'cowboy-mcp' ), __( 'Copy connector URL', 'cowboy-mcp' ) ); ?>
                            <?php self::render_claude_manual_substeps( $is_desktop, true ); ?>
                        </div>
                    </details>
            <?php self::step_close(); ?>

            <?php self::step_open( '2', __( 'Approve access', 'cowboy-mcp' ), '' ); ?>
                    <p><?php echo wp_kses( $approve_text, [ 'strong' => [] ] ); ?></p>
                    <p class="description"><?php echo wp_kses( $plan_note, [ 'strong' => [] ] ); ?></p>
                    <div class="description cmcp-org-add"><?php esc_html_e( 'Organization owner on Team or Enterprise?', 'cowboy-mcp' ); ?>
                        <?php self::render_add_to_claude_form( 'org', 'cmcp-linkbtn', __( 'Add it for your organization', 'cowboy-mcp' ) ); ?></div>
            <?php self::step_close(); ?>
            <?php
            return;
        endif;
        ?>
        <?php if ( $chatgpt_quick ) : ?>
        <?php self::step_open( '1', __( 'Open ChatGPT', 'cowboy-mcp' ), '' ); ?>
                <form method="post" target="_blank" class="mcp-inline-form" data-cmcp-refresh-after-submit data-cmcp-copy="<?php echo esc_attr( $endpoint ); ?>">
                    <?php wp_nonce_field( 'cowboy_mcp_open_chatgpt' ); ?>
                    <button type="submit" name="cowboy_mcp_open_chatgpt" value="1" class="cmcp-btn cmcp-btn--primary"><?php
                        esc_html_e( 'Open ChatGPT', 'cowboy-mcp' );
                        echo Cowboy_MCP_Admin::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
                    ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'cowboy-mcp' ); ?></span></button>
                </form>
                <p class="cmcp-copy-note" data-cmcp-copy-note hidden><?php esc_html_e( 'Connection link copied — paste it into ChatGPT as the MCP server URL.', 'cowboy-mcp' ); ?></p>
                <p><?php esc_html_e( 'Copies your connection link, turns on new connections for 30 minutes and opens ChatGPT in a new tab.', 'cowboy-mcp' ); ?></p>
                <details class="mcp-local-details">
                    <summary><?php esc_html_e( 'Copy the link yourself', 'cowboy-mcp' ); ?></summary>
                    <div class="mcp-local-details-body"><?php self::render_code( 'mcp-oauth-url-' . $slug, $endpoint, __( 'Connection link', 'cowboy-mcp' ), __( 'Copy connector URL', 'cowboy-mcp' ) ); ?></div>
                </details>
        <?php self::step_close(); ?>
        <?php else : ?>
        <?php self::step_open( '1', __( 'Copy your connection link', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo esc_html( $paste_hint ); ?></p>
                <?php self::render_code( 'mcp-oauth-url-' . $slug, $endpoint, __( 'Connection link', 'cowboy-mcp' ), __( 'Copy connector URL', 'cowboy-mcp' ) ); ?>
        <?php self::step_close(); ?>
        <?php endif; ?>

        <?php self::step_open( '2', $step2_title, '' ); ?>
                <?php if ( $is_chatgpt ) : ?>
                    <ol class="mcp-substeps">
                        <li><?php echo wp_kses( __( 'Go to <code>chatgpt.com</code> in your browser and sign in (MCP apps work on the web only).', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Go to <code>Settings → Security and login</code> and turn on <strong>Developer mode</strong> (one-time).', 'cowboy-mcp' ), [ 'code' => [], 'strong' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Go to <code>Plugins</code>, click <strong>+</strong> and choose <strong>Add custom MCP server</strong>.', 'cowboy-mcp' ), [ 'code' => [], 'strong' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Give it a name, paste the link from step 1 as the <strong>MCP server URL</strong>, set Authentication to <strong>OAuth</strong>, and create it.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                    </ol>
                <?php else : ?>
                    <?php self::render_claude_manual_substeps( $is_desktop ); ?>
                <?php endif; ?>
        <?php self::step_close(); ?>

        <?php self::step_open( '3', __( 'Approve access', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo wp_kses( $approve_text, [ 'strong' => [] ] ); ?></p>
                <p class="description"><?php echo wp_kses( $plan_note, [ 'strong' => [] ] ); ?></p>
        <?php self::step_close(); ?>
        <?php
    }

    /** Claude's "Add custom connector" steps, for pasting the connection link by hand ($link_above: shown just above, not in step 1). */
    private static function render_claude_manual_substeps( bool $is_desktop, bool $link_above = false ): void {
        ?>
        <ol class="mcp-substeps">
            <?php if ( $is_desktop ) : ?>
                <li><?php echo wp_kses( __( 'Open the <strong>Claude</strong> desktop app and sign in.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
            <?php else : ?>
                <li><?php echo wp_kses( __( 'Go to <code>claude.ai</code> in your browser and sign in.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
            <?php endif; ?>
            <li><?php echo wp_kses( __( 'Go to <code>Customize → Connectors</code>.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
            <li><?php echo wp_kses( __( 'Click <strong>+ Add</strong>, then <strong>Add custom connector</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
            <li><?php
                echo wp_kses(
                    $link_above
                        ? __( 'Give it a name, paste the link above, click <strong>Continue</strong> and then <strong>Add</strong>. If Claude asks which OAuth client to use, choose <strong>Register automatically</strong>.', 'cowboy-mcp' )
                        : __( 'Give it a name, paste the link from step 1, click <strong>Continue</strong> and then <strong>Add</strong>. If Claude asks which OAuth client to use, choose <strong>Register automatically</strong>.', 'cowboy-mcp' ),
                    [ 'strong' => [] ]
                );
            ?></li>
        </ol>
        <?php
    }

    /**
     * POST form that opens the registration window and sends the admin (new tab) to
     * Claude's prefilled "Add custom connector" dialog. $target: 'me' or 'org'.
     */
    private static function render_add_to_claude_form( string $target, string $class, string $label ): void {
        ?>
        <form method="post" target="_blank" class="mcp-inline-form cmcp-add-claude" data-cmcp-refresh-after-submit>
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

    private static function render_local_oauth_guidance( string $slug ): void {
        // Same two msgids the connector flow uses, so existing translations carry over.
        $warning_text = ( 'chatgpt' === $slug )
            ? __( '<strong>Heads up:</strong> this site does not appear to be on a public HTTPS address. ChatGPT connects from OpenAI\'s cloud, so it cannot reach local, private, or non-HTTPS sites. A terminal tool works here instead, or connect through a tunnel/staging URL.', 'cowboy-mcp' )
            : __( '<strong>Heads up:</strong> this site does not appear to be on a public HTTPS address. The Claude apps connect from Anthropic\'s cloud, so they cannot reach local, private, or non-HTTPS sites. A terminal tool works here instead, or connect through a tunnel/staging URL.', 'cowboy-mcp' );
        ?>
        <div class="cmcp-callout"><p><?php echo wp_kses( $warning_text, [ 'strong' => [] ] ); ?></p></div>
        <div class="mcp-local-guidance">
            <p><strong><?php esc_html_e( 'What works on a local site:', 'cowboy-mcp' ); ?></strong></p>
            <ul>
                <li><?php echo wp_kses( __( '<strong>Terminal tools</strong> (Claude Code, Codex, Cursor, Gemini CLI) — connect right now, no public URL needed. Pick one in the sidebar.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                <li><?php echo wp_kses( __( '<strong>Claude Desktop</strong> — connects through a local bridge that runs on this computer. Pick it in the sidebar for instructions.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                <li><?php echo wp_kses( __( '<strong>claude.ai and ChatGPT</strong> — cloud-only: they need a public HTTPS address. A tunnel (e.g. ngrok or Cloudflare Tunnel) works temporarily, but it exposes your entire dev site to the internet while it runs, and the WordPress Site Address must be set to the tunnel URL for the sign-in to work.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
            </ul>
        </div>
        <?php
    }

    private static function render_desktop_bridge_flow( string $endpoint, array $keys, $new_key ): void {
        $host        = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $domain      = str_replace( '.', '-', $host );
        $key_display = $new_key ?: 'YOUR_API_KEY';
        $has_keys    = ! empty( $keys );
        // Offer the plain-http fallback only for loopback-shaped hosts: the key
        // must never be suggested over plaintext on a real network.
        $offer_http  = 'https' === wp_parse_url( $endpoint, PHP_URL_SCHEME )
            && Cowboy_MCP_Security::host_is_loopback_shaped( $host );
        ?>
        <div class="cmcp-callout cmcp-callout--info"><p><?php
            echo wp_kses( __( 'This site runs on your computer, so the cloud connector cannot reach it. Connect Claude Desktop through a <strong>local bridge</strong> instead: a small helper that runs on this computer and forwards Claude Desktop to the site. Nothing is exposed to the internet.', 'cowboy-mcp' ), [ 'strong' => [] ] );
        ?></p></div>
        <?php
        self::render_key_step( 'claude-desktop', 'Claude Desktop', $new_key, $has_keys );
        ?>
        <?php self::step_open( '2', __( 'Add the bridge to Claude Desktop', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo wp_kses( __( 'Add this to <code>claude_desktop_config.json</code> (create the file if it does not exist), then fully quit and restart the Claude app:', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php self::render_code( 'mcp-cmd-claude-desktop', self::bridge_config_snippet( $domain, $endpoint, $key_display ), 'claude_desktop_config.json', __( 'Copy setup command', 'cowboy-mcp' ) ); ?>
                <p class="description cmcp-os-paths">
                    <?php echo wp_kses( __( 'macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>', 'cowboy-mcp' ), [ 'code' => [] ] ); ?><br>
                    <?php echo wp_kses( __( 'Windows: <code>%APPDATA%\Claude\claude_desktop_config.json</code>', 'cowboy-mcp' ), [ 'code' => [] ] ); ?>
                </p>
                <p class="description"><?php echo wp_kses( __( 'Requires Node.js on this computer — the bridge is the standard open-source <code>mcp-remote</code> package, started on demand via <code>npx</code>. It connects only from your computer to this site.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <p class="description"><?php echo wp_kses( __( 'Your API key is stored in plain text in that file, so prefer a <strong>read-only</strong> key unless this connection needs to make changes — and revoke it here when you no longer use it.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                <?php
                if ( $offer_http ) {
                    self::render_cert_error_details( 'claude-desktop', self::bridge_config_snippet( $domain, set_url_scheme( $endpoint, 'http' ), $key_display ), 'claude_desktop_config.json' );
                }
                ?>
        <?php self::step_close(); ?>
        <?php self::step_open( '3', __( 'Check it worked', 'cowboy-mcp' ), '' ); ?>
                <p><?php
                    /* translators: %s: the MCP server name shown in the client. */
                    echo wp_kses( sprintf( __( 'Open a new chat in Claude Desktop and click the tools icon — <code>%s</code> should be listed. The first start can take a moment while the bridge downloads.', 'cowboy-mcp' ), esc_html( $domain ) ), [ 'code' => [] ] );
                ?></p>
        <?php self::step_close(); ?>
        <?php
    }

    /** claude_desktop_config.json snippet. The Authorization header value goes
     *  through mcp-remote's ${VAR} env expansion so the space in "Bearer <key>"
     *  survives Claude Desktop's Windows argument handling. */
    private static function bridge_config_snippet( string $domain, string $endpoint, string $key_display ): string {
        return "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"command\": \"npx\",\n      \"args\": [\"-y\", \"mcp-remote\", \"{$endpoint}\",\n        \"--header\", \"Authorization:\${AUTH_HEADER}\"],\n      \"env\": { \"AUTH_HEADER\": \"Bearer {$key_display}\" }\n    }\n  }\n}";
    }

    private static function render_cert_error_details( string $slug, string $alt_snippet, string $title ): void {
        $alt_id = 'mcp-cmd-http-' . $slug;
        ?>
        <details class="mcp-local-details">
            <summary><?php esc_html_e( 'Getting a certificate error?', 'cowboy-mcp' ); ?></summary>
            <div class="mcp-local-details-body">
                <p><?php echo wp_kses( __( 'Local sites usually use a self-signed certificate that AI tools reject. Since this site runs on this same computer, you can use the plain <code>http://</code> address instead — the connection never leaves your machine. Avoid the <code>NODE_TLS_REJECT_UNAUTHORIZED=0</code> workaround you may see online: it disables certificate checks for everything that tool connects to.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
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

    private static function render_key_step( string $slug, string $label, $new_key, bool $has_keys ): void {
        ?>
        <?php self::step_open( $new_key ? '✓' : '1', __( 'Create an API key', 'cowboy-mcp' ), $new_key ? 'done' : '' ); ?>
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
            'gemini-cli' => esc_html__( 'Run this in your terminal:', 'cowboy-mcp' ),
            'codex'      => wp_kses( __( 'Run these in your terminal. Codex reads the key each time it starts, so also add the <code>export</code> line to your shell profile (e.g. <code>~/.zshrc</code>):', 'cowboy-mcp' ), [ 'code' => [] ] ),
            'opencode'   => wp_kses( __( 'Add this to <code>~/.config/opencode/opencode.json</code> (or a project <code>opencode.json</code>), then restart Opencode:', 'cowboy-mcp' ), [ 'code' => [] ] ),
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

    /** Title shown on a snippet's terminal bar: the file it goes into, or "terminal". */
    private static function snippet_title( string $slug ): string {
        return match ( $slug ) {
            'opencode' => '~/.config/opencode/opencode.json',
            'cursor'   => '~/.cursor/mcp.json',
            default    => __( 'terminal', 'cowboy-mcp' ),
        };
    }

    /** Raw (unescaped) setup snippet for an API-key client. Escaped at output. */
    private static function install_snippet( string $slug, string $domain, string $endpoint, string $key_display ): string {
        return match ( $slug ) {
            'claude-code' => 'claude mcp add --transport http ' . $domain . ' ' . $endpoint . ' --header "Authorization: Bearer ' . $key_display . '"',
            'codex'       => 'export COWBOY_MCP_API_KEY="' . $key_display . "\"\n" . 'codex mcp add ' . $domain . ' --url ' . $endpoint . ' --bearer-token-env-var COWBOY_MCP_API_KEY',
            'opencode'    => "{\n  \"mcp\": {\n    \"{$domain}\": {\n      \"type\": \"remote\",\n      \"url\": \"{$endpoint}\",\n      \"oauth\": false,\n      \"headers\": {\n        \"Authorization\": \"Bearer {$key_display}\"\n      }\n    }\n  }\n}",
            'cursor'      => "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"url\": \"{$endpoint}\",\n      \"headers\": {\n        \"Authorization\": \"Bearer {$key_display}\"\n      }\n    }\n  }\n}",
            'gemini-cli'  => 'gemini mcp add --scope user --transport http ' . $domain . ' ' . $endpoint . ' --header "Authorization: Bearer ' . $key_display . '"',
            default       => '',
        };
    }

    private static function render_verify_step( string $slug, string $domain ): void {
        $text = match ( $slug ) {
            /* translators: %s: the MCP server name shown in the client. */
            'claude-code' => sprintf( __( 'Open a new Claude Code session and run <code>/mcp</code> — <code>%s</code> should be listed as connected.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'codex'       => sprintf( __( 'Start Codex and run <code>/mcp</code> — <code>%s</code> should be listed with its tools.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'opencode'    => sprintf( __( 'Run <code>opencode mcp list</code> — <code>%s</code> should be listed as connected.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'cursor'      => sprintf( __( 'Open <code>Customize</code> in the Cursor sidebar — <code>%s</code> should be listed and enabled. Errors show under <code>Output → MCP Logs</code>.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'gemini-cli'  => sprintf( __( 'Run <code>gemini mcp list</code> — <code>%s</code> should show as connected.', 'cowboy-mcp' ), esc_html( $domain ) ),
            default       => '',
        };
        ?>
        <?php self::step_open( '3', __( 'Check it worked', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo wp_kses( $text, [ 'code' => [] ] ); ?></p>
        <?php self::step_close(); ?>
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
                            if ( 'oauth' === $client['type'] ) {
                                self::render_oauth_client_panel( $slug, $endpoint, $is_local, $keys, $new_key );
                            } else {
                                self::render_api_client_panel( $slug, $client['label'], $keys, $endpoint, $new_key, $is_local );
                            }
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
            <div class="cmcp-ep">
                <?php esc_html_e( 'Another MCP client? Use this endpoint:', 'cowboy-mcp' ); ?>
                <div class="cmcp-ep-row">
                    <code id="cmcp-endpoint"><?php echo esc_html( $endpoint ); ?></code>
                    <button type="button" class="cmcp-icon-btn mcp-copy-btn" data-copy-target="cmcp-endpoint" data-cmcp-icon aria-label="<?php esc_attr_e( 'Copy endpoint URL', 'cowboy-mcp' ); ?>"><?php
                        echo Cowboy_MCP_Admin::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal.
                    ?></button>
                </div>
            </div>
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

    private static function render_code( string $id, string $text, string $title, string $copy_label ): void {
        ?>
        <div class="cmcp-term-wrap">
            <div class="cmcp-term-bar">
                <span class="cmcp-lamp" aria-hidden="true"></span>
                <span><?php echo esc_html( $title ); ?></span>
                <button type="button" class="cmcp-term-copy mcp-copy-btn" data-copy-target="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( $copy_label ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
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
                        self::render_credential_row( [
                            'name'      => (string) $k['label'],
                            'sub'       => $k['prefix'] . '…',
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
                        self::render_credential_row( [
                            'name'      => (string) $c['client_name'],
                            /* translators: %s: WordPress username of the admin who approved the connection */
                            'sub'       => sprintf( __( 'approved by %s', 'cowboy-mcp' ), $c['user'] ),
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
