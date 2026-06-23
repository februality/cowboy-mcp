<?php
/**
 * Cowboy MCP – Admin Settings
 *
 * Provides the Settings → Cowboy MCP admin page for:
 *   • Guided connection setup (generate key, configure AI tool)
 *   • Plugin settings (safe mode, rate limits, etc.)
 *   • Viewing the audit log
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Admin {

    const SLUG = 'cowboy-mcp';

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_cowboy_mcp_dismiss_new_key', [ __CLASS__, 'ajax_dismiss_new_key' ] );
    }

    public static function add_menu(): void {
        add_options_page(
            __( 'Cowboy MCP', 'cowboy-mcp' ),
            __( 'Cowboy MCP', 'cowboy-mcp' ),
            'manage_options',
            self::SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( $hook !== 'settings_page_' . self::SLUG ) {
            return;
        }
        wp_enqueue_style(
            'cowboy-mcp-admin',
            COWBOY_MCP_URL . 'admin/css/mcp-admin.css',
            [],
            COWBOY_MCP_VERSION
        );
        wp_enqueue_script(
            'cowboy-mcp-admin',
            COWBOY_MCP_URL . 'admin/js/mcp-admin.js',
            [],
            COWBOY_MCP_VERSION,
            true
        );
        wp_localize_script( 'cowboy-mcp-admin', 'cowboyMcpAdmin', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'dismissNonce' => wp_create_nonce( 'cowboy_mcp_dismiss_new_key' ),
        ] );
    }

    /* ── AJAX: dismiss new key notice ─────────────────────── */

    public static function ajax_dismiss_new_key(): void {
        check_ajax_referer( 'cowboy_mcp_dismiss_new_key' );
        if ( current_user_can( 'manage_options' ) ) {
            delete_transient( 'cowboy_mcp_new_key_' . get_current_user_id() );
        }
        wp_die();
    }

    /* ── Action handler (key gen / revoke / settings / audit log) ── */

    public static function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Generate key.
        if ( isset( $_POST['cowboy_mcp_generate_key'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_generate_key' ) ) {
            $label = sanitize_text_field( wp_unslash( $_POST['key_label'] ?? '' ) );
            if ( $label === '' ) {
                $label = 'API Key';
            }
            $result = Cowboy_MCP_Auth::generate_key( $label );
            set_transient( 'cowboy_mcp_new_key_' . get_current_user_id(), $result['key'], 3600 );
            add_settings_error( 'cowboy_mcp', 'key_created', __( 'API key created. Copy it now — it will only be shown once.', 'cowboy-mcp' ), 'success' );
        }

        // Revoke key.
        if ( isset( $_POST['cowboy_mcp_revoke_key'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_revoke_key' ) ) {
            $id = sanitize_text_field( wp_unslash( $_POST['key_id'] ?? '' ) );
            Cowboy_MCP_Auth::revoke_key( $id );
            add_settings_error( 'cowboy_mcp', 'key_revoked', __( 'API key revoked.', 'cowboy-mcp' ), 'info' );
        }

        // Revoke OAuth connection.
        if ( isset( $_POST['cowboy_mcp_revoke_oauth'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_revoke_oauth' ) ) {
            $cid = sanitize_text_field( wp_unslash( $_POST['oauth_client_id'] ?? '' ) );
            if ( class_exists( 'Cowboy_MCP_OAuth' ) && $cid !== '' ) {
                Cowboy_MCP_OAuth::revoke_connection( $cid );
                add_settings_error( 'cowboy_mcp', 'oauth_revoked', __( 'Connection revoked.', 'cowboy-mcp' ), 'info' );
            }
        }

        // Save settings.
        if ( isset( $_POST['cowboy_mcp_save_settings'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_save_settings' ) ) {
            $existing = get_option( 'cowboy_mcp_settings', [] );
            $settings = [
                'enabled'       => ! empty( $_POST['cowboy_mcp_enabled'] ),
                'safe_mode'     => ! empty( $_POST['cowboy_mcp_safe_mode'] ),
                'power_mode'    => ! empty( $_POST['cowboy_mcp_power_mode'] ),
                // Preserve any configured allowlist (there is no UI field for it; hardcoding
                // 'all' here would silently wipe a restriction set via option/filter).
                'allowed_tools' => $existing['allowed_tools'] ?? 'all',
                'log_requests'  => ! empty( $_POST['cowboy_mcp_log_requests'] ),
                'rate_limit'    => max( 10, (int) sanitize_text_field( wp_unslash( $_POST['cowboy_mcp_rate_limit'] ?? '' ) ) ),
                'oauth_enabled' => ! empty( $_POST['cowboy_mcp_oauth_enabled'] ),
            ];
            update_option( 'cowboy_mcp_settings', $settings );
            add_settings_error( 'cowboy_mcp', 'settings_saved', __( 'Settings saved.', 'cowboy-mcp' ), 'success' );
        }

        // Clear audit log.
        if ( isset( $_POST['cowboy_mcp_clear_audit_log'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_clear_audit_log' ) ) {
            if ( class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
                Cowboy_MCP_Audit_Log::clear();
                add_settings_error( 'cowboy_mcp', 'log_cleared', __( 'Audit log cleared.', 'cowboy-mcp' ), 'info' );
            }
        }
    }

    /* ── Page renderer ────────────────────────────────────── */

    public static function render_page(): void {
        $settings = get_option( 'cowboy_mcp_settings', [] );
        $keys     = Cowboy_MCP_Auth::list_keys();
        $endpoint = rest_url( 'cowboy-mcp/v1/endpoint' );
        $new_key  = get_transient( 'cowboy_mcp_new_key_' . get_current_user_id() );

        // Tab routing with backwards compat.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'connection' ) );

        $tab_map = [
            'connection' => 'connection',
            'settings'   => 'settings',
            'audit-log'  => 'logs',
            'logs'       => 'logs',
        ];
        $active_tab = $tab_map[ $raw_tab ] ?? 'connection';

        ?>
        <div class="wrap mcp-admin">
            <h1><?php
                /* translators: plugin version */
                printf( '&#x1f50c; %s <small style="font-size:12px;color:#888">v%s</small>',
                    esc_html__( 'Cowboy MCP', 'cowboy-mcp' ),
                    esc_html( COWBOY_MCP_VERSION )
                );
            ?></h1>
            <p class="description"><?php
                echo wp_kses(
                    __( 'Connect AI coding agents like <strong>Claude Code</strong>, <strong>Codex</strong>, or <strong>Opencode</strong> to this WordPress site via the Model Context Protocol.', 'cowboy-mcp' ),
                    [ 'strong' => [] ]
                );
            ?></p>

            <nav class="nav-tab-wrapper mcp-nav-tabs">
                <a href="?page=<?php echo esc_attr( self::SLUG ); ?>&tab=connection" class="nav-tab <?php echo esc_attr( $active_tab === 'connection' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Connection', 'cowboy-mcp' ); ?></a>
                <a href="?page=<?php echo esc_attr( self::SLUG ); ?>&tab=settings" class="nav-tab <?php echo esc_attr( $active_tab === 'settings' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Settings', 'cowboy-mcp' ); ?></a>
                <a href="?page=<?php echo esc_attr( self::SLUG ); ?>&tab=logs" class="nav-tab <?php echo esc_attr( $active_tab === 'logs' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Logs', 'cowboy-mcp' ); ?></a>
            </nav>

            <?php
            match ( $active_tab ) {
                'settings' => self::render_settings_tab( $settings ),
                'logs'     => self::render_logs_tab(),
                default    => self::render_connection_tab( $keys, $endpoint, $new_key ),
            };
            ?>

        </div>
        <?php
    }

    /* ── Connection tab ───────────────────────────────────── */

    private static function render_connection_tab( array $keys, string $endpoint, $new_key ): void {
        $domain   = str_replace( '.', '-', wp_parse_url( home_url(), PHP_URL_HOST ) );
        $has_keys = ! empty( $keys );
        $key_display = $new_key ?: 'YOUR_API_KEY';

        /* ── Step 1: Generate an API Key ──────────────────── */
        ?>
        <div class="mcp-step <?php echo esc_attr( $new_key ? 'mcp-step--completed' : 'mcp-step--active' ); ?>">
            <div class="mcp-step-header">
                <span class="mcp-step-number"><?php echo esc_html( $new_key ? '✓' : '1' ); ?></span>
                <h3 style="margin:0;"><?php esc_html_e( 'Generate an API Key', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <?php if ( $new_key ): ?>
                    <p><?php echo wp_kses( __( 'Your new API key &mdash; <strong>copy it now</strong>, it will not be shown again:', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                    <div class="mcp-code-block">
                        <code id="mcp-new-key"><?php echo esc_html( $new_key ); ?></code>
                    </div>
                    <button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-new-key" aria-label="<?php echo esc_attr__( 'Copy API key', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
                    <button type="button" id="mcp-dismiss-key" class="button button-small" style="margin-left:4px;"><?php esc_html_e( "I've copied my key", 'cowboy-mcp' ); ?></button>
                <?php elseif ( ! $has_keys ): ?>
                    <p><?php esc_html_e( 'Get started by generating your first API key.', 'cowboy-mcp' ); ?></p>
                    <form method="post" class="mcp-generate-form">
                        <?php wp_nonce_field( 'cowboy_mcp_generate_key' ); ?>
                        <input type="text" name="key_label" value="" placeholder="<?php echo esc_attr__( 'e.g. Claude Code', 'cowboy-mcp' ); ?>" class="regular-text" style="max-width: 200px;">
                        <button type="submit" name="cowboy_mcp_generate_key" class="button button-primary"><?php esc_html_e( 'Generate API Key', 'cowboy-mcp' ); ?></button>
                    </form>
                <?php else: ?>
                    <form method="post" class="mcp-generate-form">
                        <?php wp_nonce_field( 'cowboy_mcp_generate_key' ); ?>
                        <input type="text" name="key_label" value="" placeholder="<?php echo esc_attr__( 'e.g. Claude Code', 'cowboy-mcp' ); ?>" class="regular-text" style="max-width: 200px;">
                        <button type="submit" name="cowboy_mcp_generate_key" class="button button-primary"><?php esc_html_e( 'Generate Another Key', 'cowboy-mcp' ); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php /* ── Step 2: Choose Your AI Tool ────────────────── */ ?>
        <div class="mcp-step <?php echo esc_attr( $new_key ? 'mcp-step--active' : '' ); ?>">
            <div class="mcp-step-header">
                <span class="mcp-step-number">2</span>
                <h3 style="margin:0;"><?php esc_html_e( 'Choose Your AI Tool & Run the Command', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <div class="mcp-tabs" data-tabs="connect-cmd">
                    <nav class="mcp-tabs-nav" role="tablist">
                        <button type="button" class="mcp-tab-btn mcp-tab-btn--active" data-tab="claude-code" role="tab" aria-selected="true" aria-controls="connect-cmd-panel-claude-code" tabindex="0">Claude Code</button>
                        <button type="button" class="mcp-tab-btn" data-tab="codex" role="tab" aria-selected="false" aria-controls="connect-cmd-panel-codex" tabindex="-1">Codex</button>
                        <button type="button" class="mcp-tab-btn" data-tab="opencode" role="tab" aria-selected="false" aria-controls="connect-cmd-panel-opencode" tabindex="-1">Opencode</button>
                    </nav>
                    <div class="mcp-tab-panel mcp-tab-panel--active" data-panel="claude-code" role="tabpanel" id="connect-cmd-panel-claude-code" tabindex="0">
                        <p><?php esc_html_e( 'Run this command in your terminal:', 'cowboy-mcp' ); ?></p>
                        <div class="mcp-code-block">
                            <code id="mcp-cmd-claude">claude mcp add --transport http <?php echo esc_attr( $domain ); ?> <?php echo esc_url( $endpoint ); ?> --header "Authorization: Bearer <?php echo esc_html( $key_display ); ?>"</code>
                        </div>
                        <button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-cmd-claude" aria-label="<?php echo esc_attr__( 'Copy Claude Code command', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
                    </div>
                    <div class="mcp-tab-panel" data-panel="codex" role="tabpanel" id="connect-cmd-panel-codex" tabindex="0">
                        <p><?php esc_html_e( 'Run these commands in your terminal:', 'cowboy-mcp' ); ?></p>
                        <div class="mcp-code-block">
                            <code id="mcp-cmd-codex">export COWBOY_MCP_API_KEY="<?php echo esc_html( $key_display ); ?>"
codex mcp add <?php echo esc_attr( $domain ); ?> --url <?php echo esc_url( $endpoint ); ?> --bearer-token-env-var COWBOY_MCP_API_KEY</code>
                        </div>
                        <button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-cmd-codex" aria-label="<?php echo esc_attr__( 'Copy Codex command', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
                    </div>
                    <div class="mcp-tab-panel" data-panel="opencode" role="tabpanel" id="connect-cmd-panel-opencode" tabindex="0">
                        <p><?php echo wp_kses( __( 'Add to your <code>opencode.json</code>:', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                        <div class="mcp-code-block">
                            <code id="mcp-cmd-opencode">{
  "mcp": {
    "<?php echo esc_attr( $domain ); ?>": {
      "type": "remote",
      "url": "<?php echo esc_url( $endpoint ); ?>",
      "headers": {
        "Authorization": "Bearer <?php echo esc_html( $key_display ); ?>"
      }
    }
  }
}</code>
                        </div>
                        <button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-cmd-opencode" aria-label="<?php echo esc_attr__( 'Copy Opencode config', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <?php /* ── Step 3: Verify Connection ──────────────────── */ ?>
        <div class="mcp-step">
            <div class="mcp-step-header">
                <span class="mcp-step-number">3</span>
                <h3 style="margin:0;"><?php esc_html_e( 'Verify Connection', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <p><?php esc_html_e( 'Open your AI tool and verify the MCP server is connected. In Claude Code, run /mcp and confirm your site appears in the list as connected.', 'cowboy-mcp' ); ?></p>
                <p><?php
                    /* translators: %s: MCP endpoint URL */
                    printf( '%s <code>%s</code>',
                        esc_html__( 'MCP endpoint:', 'cowboy-mcp' ),
                        esc_url( $endpoint )
                    );
                ?></p>
            </div>
        </div>

        <?php /* ── Existing Keys table ────────────────────────── */
        if ( $has_keys ): ?>
        <div class="postbox" style="margin-top:8px;">
            <div class="postbox-header"><h2><?php esc_html_e( 'Existing Keys', 'cowboy-mcp' ); ?></h2></div>
            <div class="inside">
                <div class="mcp-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Label', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Prefix', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Created', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Last Used', 'cowboy-mcp' ); ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $keys as $k ): ?>
                        <tr>
                            <td><?php echo esc_html( $k['label'] ); ?></td>
                            <td><code><?php echo esc_html( $k['prefix'] ); ?>&hellip;</code></td>
                            <td><?php echo esc_html( wp_date( 'M j, Y', $k['created'] ) ); ?></td>
                            <td><?php
                                if ( $k['last_used'] ) {
                                    /* translators: %s: human-readable time difference, e.g. "2 hours" */
                                    printf( esc_html__( '%s ago', 'cowboy-mcp' ), esc_html( human_time_diff( $k['last_used'] ) ) );
                                } else {
                                    echo '<em>' . esc_html__( 'never', 'cowboy-mcp' ) . '</em>';
                                }
                            ?></td>
                            <td>
                                <form method="post" class="mcp-revoke-form">
                                    <?php wp_nonce_field( 'cowboy_mcp_revoke_key' ); ?>
                                    <input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>">
                                    <button type="submit" name="cowboy_mcp_revoke_key" class="button button-small button-link-delete"
                                            data-confirm="<?php echo esc_attr__( 'Revoke this key? Any client using it will lose access.', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Revoke', 'cowboy-mcp' ); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php endif;

        /* ── Connect with Claude Desktop (OAuth) ──────────── */
        $oauth_on    = class_exists( 'Cowboy_MCP_OAuth' ) && Cowboy_MCP_OAuth::is_enabled();
        $reachable   = ! class_exists( 'Cowboy_MCP_OAuth' ) || Cowboy_MCP_OAuth::site_is_publicly_reachable();
        $connections = ( class_exists( 'Cowboy_MCP_OAuth' ) && $oauth_on ) ? Cowboy_MCP_OAuth::list_connections() : [];
        $settings_url = admin_url( 'options-general.php?page=' . self::SLUG . '&tab=settings' );
        ?>
        <div class="postbox" style="margin-top:8px;">
            <div class="postbox-header"><h2><?php esc_html_e( 'Connect with Claude Desktop (no terminal)', 'cowboy-mcp' ); ?></h2></div>
            <div class="inside">
                <?php if ( ! $reachable ): ?>
                    <div class="notice notice-warning inline"><p><?php
                        esc_html_e( 'This site does not appear to be on a public HTTPS address. The Claude apps connect from Anthropic\'s cloud, so the desktop connector cannot reach local, private, or non-HTTPS sites. You can still enable it for tunnels/staging, but it will not work on a purely local install.', 'cowboy-mcp' );
                    ?></p></div>
                <?php endif; ?>

                <?php if ( ! $oauth_on ): ?>
                    <p><?php
                        printf(
                            wp_kses( __( 'Let non-technical users connect from the Claude Desktop or web app — no terminal required. <a href="%s">Enable the Desktop Connector</a> in Settings to turn it on.', 'cowboy-mcp' ), [ 'a' => [ 'href' => [] ] ] ),
                            esc_url( $settings_url )
                        );
                    ?></p>
                <?php else: ?>
                    <p><?php esc_html_e( 'In Claude Desktop or claude.ai, go to Settings → Connectors → Add custom connector, then paste this URL and approve the browser sign-in:', 'cowboy-mcp' ); ?></p>
                    <div class="mcp-code-block">
                        <code id="mcp-oauth-url"><?php echo esc_url( $endpoint ); ?></code>
                    </div>
                    <button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-oauth-url" aria-label="<?php echo esc_attr__( 'Copy connector URL', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
                    <p class="description" style="margin-top:8px;"><?php esc_html_e( 'Custom connectors require a Claude Pro, Max, Team, or Enterprise plan.', 'cowboy-mcp' ); ?></p>

                    <?php if ( ! empty( $connections ) ): ?>
                        <h3 style="margin-top:20px;"><?php esc_html_e( 'Connected apps', 'cowboy-mcp' ); ?></h3>
                        <div class="mcp-table-wrap">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'App', 'cowboy-mcp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Authorized by', 'cowboy-mcp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Connected', 'cowboy-mcp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Last Used', 'cowboy-mcp' ); ?></th>
                                    <th scope="col"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $connections as $c ): ?>
                                <tr>
                                    <td><?php echo esc_html( $c['client_name'] ); ?></td>
                                    <td><?php echo esc_html( $c['user'] ); ?></td>
                                    <td><?php echo esc_html( $c['created'] ? wp_date( 'M j, Y', $c['created'] ) : '—' ); ?></td>
                                    <td><?php
                                        if ( $c['last_used'] ) {
                                            /* translators: %s: human-readable time difference */
                                            printf( esc_html__( '%s ago', 'cowboy-mcp' ), esc_html( human_time_diff( $c['last_used'] ) ) );
                                        } else {
                                            echo '<em>' . esc_html__( 'never', 'cowboy-mcp' ) . '</em>';
                                        }
                                    ?></td>
                                    <td>
                                        <form method="post" class="mcp-revoke-form">
                                            <?php wp_nonce_field( 'cowboy_mcp_revoke_oauth' ); ?>
                                            <input type="hidden" name="oauth_client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>">
                                            <button type="submit" name="cowboy_mcp_revoke_oauth" class="button button-small button-link-delete"
                                                    data-confirm="<?php echo esc_attr__( 'Revoke this connection? The app will lose access immediately.', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Revoke', 'cowboy-mcp' ); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php else: ?>
                        <p class="description" style="margin-top:12px;"><em><?php esc_html_e( 'No apps connected yet.', 'cowboy-mcp' ); ?></em></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ── Settings tab ─────────────────────────────────────── */

    private static function render_settings_tab( array $settings ): void {
        ?>
            <div class="postbox">
                <div class="postbox-header"><h2><?php esc_html_e( 'Settings', 'cowboy-mcp' ); ?></h2></div>
                <div class="inside">
                    <form method="post">
                        <?php wp_nonce_field( 'cowboy_mcp_save_settings' ); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'MCP Server', 'cowboy-mcp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="cowboy_mcp_enabled" value="1" <?php checked( $settings['enabled'] ?? true ); ?>>
                                        <?php esc_html_e( 'Enabled', 'cowboy-mcp' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Disabling will reject all MCP requests.', 'cowboy-mcp' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Safe Mode', 'cowboy-mcp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="cowboy_mcp_safe_mode" value="1" <?php checked( $settings['safe_mode'] ?? true ); ?>>
                                        <?php esc_html_e( 'Require confirmation for destructive operations', 'cowboy-mcp' ); ?>
                                    </label>
                                    <p class="description"><?php
                                        echo wp_kses(
                                            __( 'When enabled, tools marked as destructive (delete, drop, WP-CLI write commands, etc.) require <code>confirm: true</code> in the request.', 'cowboy-mcp' ),
                                            [ 'code' => [] ]
                                        );
                                    ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Power Mode', 'cowboy-mcp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="cowboy_mcp_power_mode" value="1" <?php checked( $settings['power_mode'] ?? false ); ?>>
                                        <?php esc_html_e( 'Lift safety restrictions for advanced operations', 'cowboy-mcp' ); ?>
                                    </label>
                                    <p class="description" style="color:#b32d2e;"><?php
                                        echo wp_kses(
                                            __( '<strong>Danger:</strong> allows <code>eval</code>/<code>shell</code>, dangerous SQL, writing files anywhere, and requests to internal addresses. This grants effective <strong>remote code execution</strong> to anyone holding an API key. Only enable on a trusted, locked-down site you control. Your MCP API keys and the plugin&#8217;s own settings stay protected.', 'cowboy-mcp' ),
                                            [ 'strong' => [], 'code' => [] ]
                                        );
                                    ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Desktop Connector (OAuth)', 'cowboy-mcp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="cowboy_mcp_oauth_enabled" value="1" <?php checked( $settings['oauth_enabled'] ?? false ); ?>>
                                        <?php esc_html_e( 'Allow connecting via Claude Desktop / web (OAuth)', 'cowboy-mcp' ); ?>
                                    </label>
                                    <p class="description"><?php
                                        echo wp_kses(
                                            __( 'Enables an OAuth 2.1 sign-in flow so the Claude apps can connect without the terminal. This exposes public OAuth discovery, registration, and token endpoints (no tokens are issued without an administrator approving in the browser). Leave off if you only connect via the terminal.', 'cowboy-mcp' ),
                                            [ 'code' => [] ]
                                        );
                                    ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Rate Limit', 'cowboy-mcp' ); ?></th>
                                <td>
                                    <input type="number" name="cowboy_mcp_rate_limit" value="<?php echo esc_attr( (int) ( $settings['rate_limit'] ?? 120 ) ); ?>" min="10" max="1000" class="small-text">
                                    <span><?php esc_html_e( 'requests per minute per key', 'cowboy-mcp' ); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Request Logging', 'cowboy-mcp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="cowboy_mcp_log_requests" value="1" <?php checked( $settings['log_requests'] ?? false ); ?>>
                                        <?php echo wp_kses( __( 'Log all tool calls to <code>debug.log</code>', 'cowboy-mcp' ), [ 'code' => [] ] ); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" name="cowboy_mcp_save_settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'cowboy-mcp' ); ?></button>
                        </p>
                    </form>
                </div>
            </div>
        <?php
    }

    /* ── Logs tab ──────────────────────────────────────────── */

    private static function render_logs_tab(): void {
        if ( ! class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Audit log is not available. Please deactivate and reactivate the plugin.', 'cowboy-mcp' ) . '</p></div>';
            return;
        }

        // Parse filters from query string (read-only admin filters, fully sanitized).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $filters = [
            'event'     => sanitize_text_field( wp_unslash( $_GET['log_event'] ?? '' ) ),
            'tool'      => sanitize_text_field( wp_unslash( $_GET['log_tool'] ?? '' ) ),
            'date_from' => sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ),
            'date_to'   => sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ),
            'per_page'  => max( 10, min( 200, (int) sanitize_text_field( wp_unslash( $_GET['per_page'] ?? '' ) ) ) ),
            'page'      => max( 1, (int) sanitize_text_field( wp_unslash( $_GET['paged'] ?? '' ) ) ),
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $result      = Cowboy_MCP_Audit_Log::query( $filters );
        $entries     = $result['entries'];
        $total       = $result['total'];
        $total_pages = (int) ceil( $total / $filters['per_page'] );
        $base_url    = admin_url( 'options-general.php?page=' . self::SLUG . '&tab=logs' );

        $has_active_filter = $filters['event'] !== '' || $filters['tool'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== '';
        ?>
            <p class="description"><?php esc_html_e( 'Structured log of all MCP tool calls, errors, and auth events. Auto-pruned after 30 days.', 'cowboy-mcp' ); ?></p>

            <!-- Filters -->
            <form method="get" class="mcp-filter-form">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
                <input type="hidden" name="tab" value="logs">
                <label class="mcp-filter-label">
                    <?php esc_html_e( 'Event', 'cowboy-mcp' ); ?>
                    <select name="log_event" style="min-width:130px;">
                        <option value=""><?php esc_html_e( 'All events', 'cowboy-mcp' ); ?></option>
                        <?php foreach ( [ 'tool_call', 'tool_error', 'tool_exception', 'auth_missing_header', 'auth_invalid_key', 'rate_limit_exceeded' ] as $ev ): ?>
                            <option value="<?php echo esc_attr( $ev ); ?>" <?php selected( $filters['event'], $ev ); ?>><?php echo esc_html( $ev ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="mcp-filter-label">
                    <?php esc_html_e( 'Tool', 'cowboy-mcp' ); ?>
                    <input type="text" name="log_tool" value="<?php echo esc_attr( $filters['tool'] ); ?>" placeholder="<?php echo esc_attr__( 'e.g. wp_list_posts', 'cowboy-mcp' ); ?>" style="width:150px;">
                </label>
                <label class="mcp-filter-label">
                    <?php esc_html_e( 'From', 'cowboy-mcp' ); ?>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
                </label>
                <label class="mcp-filter-label">
                    <?php esc_html_e( 'To', 'cowboy-mcp' ); ?>
                    <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
                </label>
                <label class="mcp-filter-label">
                    <?php esc_html_e( 'Per page', 'cowboy-mcp' ); ?>
                    <select name="per_page">
                        <?php foreach ( [ 25, 50, 100 ] as $pp ): ?>
                            <option value="<?php echo esc_attr( (int) $pp ); ?>" <?php selected( $filters['per_page'], $pp ); ?>><?php echo esc_html( (int) $pp ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'cowboy-mcp' ); ?></button>
                <?php if ( $has_active_filter ): ?>
                    <span class="mcp-filter-badge"><?php esc_html_e( 'Filtered', 'cowboy-mcp' ); ?> &mdash; <a href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'cowboy-mcp' ); ?></a></span>
                <?php endif; ?>
            </form>

            <!-- Clear button -->
            <form method="post" class="mcp-clear-form">
                <?php wp_nonce_field( 'cowboy_mcp_clear_audit_log' ); ?>
                <button type="submit" name="cowboy_mcp_clear_audit_log" class="button button-link-delete"
                        data-confirm="<?php echo esc_attr__( 'Clear all audit log entries? This cannot be undone.', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Clear All Logs', 'cowboy-mcp' ); ?></button>
                <span class="description" style="margin-left:8px;"><?php
                    /* translators: %s: total number of log entries */
                    printf( esc_html__( '%s entries total', 'cowboy-mcp' ), esc_html( number_format( $total ) ) );
                ?></span>
            </form>

            <!-- Table -->
            <?php if ( empty( $entries ) ): ?>
                <p><em><?php esc_html_e( 'No log entries found.', 'cowboy-mcp' ); ?></em></p>
            <?php else: ?>
                <div class="mcp-table-wrap">
                <table class="widefat striped mcp-audit-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width:140px"><?php esc_html_e( 'Timestamp', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Key', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Event', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Tool', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Status', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'IP', 'cowboy-mcp' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $entries as $row ):
                        $has_args = ! empty( $row['args'] );
                    ?>
                        <tr class="<?php echo esc_attr( $has_args ? 'mcp-log-row' : '' ); ?>">
                            <td><span class="mcp-expand-arrow"><?php echo esc_html( $has_args ? '▶ ' : '' ); ?></span><code style="font-size:11px;"><?php echo esc_html( $row['timestamp'] ); ?></code></td>
                            <td><?php echo esc_html( $row['key_label'] ?: $row['key_id'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row['event'] ); ?></td>
                            <td><code><?php echo esc_html( $row['tool'] ?: '—' ); ?></code></td>
                            <td><?php echo esc_html( $row['result_status'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row['ip'] ?: '—' ); ?></td>
                        </tr>
                        <?php if ( $has_args ): ?>
                        <tr class="mcp-log-detail">
                            <td colspan="6"><pre><?php echo esc_html( is_array( $row['args'] ) ? wp_json_encode( $row['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $row['args'] ); ?></pre></td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Pagination -->
                <?php if ( $total_pages > 1 ): ?>
                <div class="mcp-pagination">
                    <?php if ( $filters['page'] > 1 ): ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $filters['page'] - 1, $base_url ) ); ?>" class="button button-small">&laquo; <?php esc_html_e( 'Previous', 'cowboy-mcp' ); ?></a>
                    <?php endif; ?>
                    <span><?php
                        /* translators: 1: current page number, 2: total number of pages */
                        printf( esc_html__( 'Page %1$d of %2$d', 'cowboy-mcp' ), (int) $filters['page'], (int) $total_pages );
                    ?></span>
                    <?php if ( $filters['page'] < $total_pages ): ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $filters['page'] + 1, $base_url ) ); ?>" class="button button-small"><?php esc_html_e( 'Next', 'cowboy-mcp' ); ?> &raquo;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php
    }

}
