<?php
/**
 * Cowboy MCP – Admin: Connections page (connect flow, credentials, Doctor).
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Admin_Connections {

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

    /** Echo the static inline SVG icon for a client (monochrome, stroke = currentColor). */
    private static function render_client_icon( string $slug ): void {
        $icons = [
            'claude-ai'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"></path></svg>',
            'claude-desktop' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"></rect><path d="M9 20h6M12 16v4"></path></svg>',
            'chatgpt'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 21l1.9-5.4A8 8 0 1 1 21 12z"></path></svg>',
            'claude-code'    =>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M7 9l3 3-3 3M13 15h4"></path></svg>',
            'codex'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5"></path></svg>',
            'opencode'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17l6-6-6-6M12 19h8"></path></svg>',
            'cursor'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3l7.5 18 2.6-7.9L22 10.5z"></path></svg>',
            'gemini-cli'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4z"></path></svg>',
        ];
        echo $icons[ $slug ] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literals defined directly above; no user input.
    }

    /**
     * "New connections" switch (safety lock): the only time the connector accepts a
     * registration from a new AI app. Rendered at the top of each OAuth client
     * panel (claude.ai, Claude app, ChatGPT) once the connector is on - API-key
     * tools never see it because they never register.
     */
    private static function render_connections_gate(): void {
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
                    <button type="submit" name="cowboy_mcp_toggle_connections" value="<?php echo $on ? 'disable' : 'enable'; ?>" class="cmcp-btn <?php echo $on ? '' : 'cmcp-btn--primary'; ?>"><?php
                        echo $on ? esc_html__( 'Disable now', 'cowboy-mcp' ) : esc_html__( 'Enable for 30 minutes', 'cowboy-mcp' );
                    ?></button>
                </form>
                <p class="description"><?php esc_html_e( 'Step one when adding ChatGPT or a Claude app: enable this, then add the app. It switches itself off after 30 minutes. API keys do not use it, and connections that already exist keep working either way.', 'cowboy-mcp' ); ?></p>
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

        if ( $oauth_on ) {
            self::render_connections_gate();
        }

        if ( ! $oauth_on ) :
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
        ?>
        <?php self::step_open( '1', __( 'Copy your connection link', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo esc_html( $paste_hint ); ?></p>
                <?php self::render_code( 'mcp-oauth-url-' . $slug, $endpoint, __( 'Connection link', 'cowboy-mcp' ), __( 'Copy connector URL', 'cowboy-mcp' ) ); ?>
        <?php self::step_close(); ?>

        <?php self::step_open( '2', $step2_title, '' ); ?>
                <ol class="mcp-substeps">
                    <?php if ( $is_chatgpt ) : ?>
                        <li><?php echo wp_kses( __( 'Go to <code>chatgpt.com</code> in your browser and sign in (MCP apps work on the web only).', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Go to <code>Settings → Security and login</code> and turn on <strong>Developer mode</strong> (one-time).', 'cowboy-mcp' ), [ 'code' => [], 'strong' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Go to <code>Plugins</code>, click <strong>+</strong> and choose <strong>Create MCP App</strong>.', 'cowboy-mcp' ), [ 'code' => [], 'strong' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Give it a name, paste the link from step 1 as the <strong>MCP server URL</strong>, set Authentication to <strong>OAuth</strong>, and create it.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                    <?php else : ?>
                        <?php if ( $is_desktop ) : ?>
                            <li><?php echo wp_kses( __( 'Open the <strong>Claude</strong> desktop app and sign in.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                        <?php else : ?>
                            <li><?php echo wp_kses( __( 'Go to <code>claude.ai</code> in your browser and sign in.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
                        <?php endif; ?>
                        <li><?php echo wp_kses( __( 'Go to <code>Customize → Connectors</code>.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Click <strong>+ Add</strong>, then <strong>Add custom connector</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Give it a name, paste the link from step 1, click <strong>Continue</strong> and then <strong>Add</strong>. If Claude asks which OAuth client to use, choose <strong>Register automatically</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                    <?php endif; ?>
                </ol>
        <?php self::step_close(); ?>

        <?php self::step_open( '3', __( 'Approve access', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo wp_kses( $approve_text, [ 'strong' => [] ] ); ?></p>
                <p class="description"><?php echo wp_kses( $plan_note, [ 'strong' => [] ] ); ?></p>
        <?php self::step_close(); ?>
        <?php
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
        self::render_key_step( 'claude-desktop', 'Claude Desktop', $new_key, $has_keys, 'read_only' );
        ?>
        <?php self::step_open( '2', __( 'Add the bridge to Claude Desktop', 'cowboy-mcp' ), '' ); ?>
                <p><?php echo wp_kses( __( 'Add this to <code>claude_desktop_config.json</code> (create the file if it does not exist), then fully quit and restart the Claude app:', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <?php self::render_code( 'mcp-cmd-claude-desktop', self::bridge_config_snippet( $domain, $endpoint, $key_display ), 'claude_desktop_config.json', __( 'Copy setup command', 'cowboy-mcp' ) ); ?>
                <p class="description"><?php echo wp_kses( __( 'macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code> — Windows: <code>%APPDATA%\Claude\claude_desktop_config.json</code>', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
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

    private static function render_key_step( string $slug, string $label, $new_key, bool $has_keys, string $default_scope = 'full' ): void {
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
                        <?php self::render_scope_radios( $default_scope ); ?>
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
                <?php if ( $has_creds ) : ?>
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
