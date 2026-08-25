<?php
/**
 * Cowboy MCP – Admin Settings
 *
 * Provides the Settings → Cowboy MCP admin page for:
 *   • Guided connection setup (per-client sidebar: pick your AI app, follow tailored steps)
 *   • Plugin settings (safe mode, power mode, rate limits, etc.)
 *   • Viewing the audit log
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Admin {

    const SLUG = 'cowboy-mcp';

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'maybe_redirect_after_activation' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ __CLASS__, 'render_setup_notice' ] );
        add_action( 'wp_ajax_cowboy_mcp_dismiss_new_key', [ __CLASS__, 'ajax_dismiss_new_key' ] );
        add_action( 'wp_ajax_cowboy_mcp_dismiss_setup_notice', [ __CLASS__, 'ajax_dismiss_setup_notice' ] );
        add_action( 'wp_ajax_cowboy_mcp_set_conn_client', [ __CLASS__, 'ajax_set_conn_client' ] );
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

    /* ── One-time redirect to the connection page after activation ── */

    public static function maybe_redirect_after_activation(): void {
        if ( ! get_transient( 'cowboy_mcp_activation_redirect' ) ) {
            return;
        }
        delete_transient( 'cowboy_mcp_activation_redirect' );

        if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
            return;
        }
        // Never hijack a bulk "activate selected plugins" action.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_REQUEST['activate-multi'] ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
        exit;
    }

    /* ── Persistent "connect your AI" notice until the first key exists ── */

    /**
     * Whether the post-activation setup notice should render on this request.
     *
     * The flag is set on activation (only when the site has no credentials)
     * and cleared by the dismiss AJAX or — here — the moment an API key or
     * OAuth client exists, so a site that completed setup never sees it
     * again even if nobody clicked the ×.
     */
    private static function setup_notice_due(): bool {
        if ( ! get_option( 'cowboy_mcp_setup_notice' ) || ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) ) {
            return false;
        }
        if ( Cowboy_MCP_Auth::site_has_credentials() ) {
            delete_option( 'cowboy_mcp_setup_notice' );
            return false;
        }
        return true;
    }

    public static function render_setup_notice(): void {
        if ( ! self::setup_notice_due() ) {
            return;
        }
        ?>
        <div class="notice notice-success is-dismissible mcp-setup-notice">
            <p class="mcp-setup-notice-title"><strong><?php esc_html_e( 'Cowboy MCP is active!', 'cowboy-mcp' ); ?></strong></p>
            <p><?php esc_html_e( 'Connect Claude, Cursor, or any MCP client — it takes about a minute.', 'cowboy-mcp' ); ?></p>
            <p><a class="button button-primary mcp-setup-notice-cta" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Connect your AI', 'cowboy-mcp' ); ?></a></p>
        </div>
        <?php
    }

    public static function ajax_dismiss_setup_notice(): void {
        check_ajax_referer( 'cowboy_mcp_dismiss_setup_notice' );
        if ( current_user_can( 'manage_options' ) ) {
            delete_option( 'cowboy_mcp_setup_notice' );
        }
        wp_die();
    }

    public static function enqueue_assets( string $hook ): void {
        if ( self::setup_notice_due() ) {
            $css_path = COWBOY_MCP_PATH . 'admin/css/mcp-notice.css';
            $js_path  = COWBOY_MCP_PATH . 'admin/js/mcp-notice.js';
            wp_enqueue_style(
                'cowboy-mcp-notice',
                COWBOY_MCP_URL . 'admin/css/mcp-notice.css',
                [],
                file_exists( $css_path ) ? (string) filemtime( $css_path ) : COWBOY_MCP_VERSION
            );
            wp_enqueue_script(
                'cowboy-mcp-notice',
                COWBOY_MCP_URL . 'admin/js/mcp-notice.js',
                [],
                file_exists( $js_path ) ? (string) filemtime( $js_path ) : COWBOY_MCP_VERSION,
                true
            );
            wp_localize_script( 'cowboy-mcp-notice', 'cowboyMcpNotice', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'cowboy_mcp_dismiss_setup_notice' ),
            ] );
        }

        if ( $hook !== 'settings_page_' . self::SLUG ) {
            return;
        }
        // Version assets by file mtime so edits bust browser/page caches even
        // between releases (falls back to the plugin version if unreadable).
        $css_path = COWBOY_MCP_PATH . 'admin/css/mcp-admin.css';
        $js_path  = COWBOY_MCP_PATH . 'admin/js/mcp-admin.js';
        $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : COWBOY_MCP_VERSION;
        $js_ver   = file_exists( $js_path )  ? (string) filemtime( $js_path )  : COWBOY_MCP_VERSION;

        wp_enqueue_style(
            'cowboy-mcp-admin',
            COWBOY_MCP_URL . 'admin/css/mcp-admin.css',
            [],
            $css_ver
        );
        wp_enqueue_script(
            'cowboy-mcp-admin',
            COWBOY_MCP_URL . 'admin/js/mcp-admin.js',
            [],
            $js_ver,
            true
        );
        wp_localize_script( 'cowboy-mcp-admin', 'cowboyMcpAdmin', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'dismissNonce' => wp_create_nonce( 'cowboy_mcp_dismiss_new_key' ),
            'connNonce'    => wp_create_nonce( 'cowboy_mcp_set_conn_client' ),
        ] );
        wp_localize_script( 'cowboy-mcp-admin', 'cowboyMcpDoctor', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cowboy_mcp_doctor' ),
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

    /* ── AJAX: remember the client selected in the connection sidebar ── */

    public static function ajax_set_conn_client(): void {
        check_ajax_referer( 'cowboy_mcp_set_conn_client' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '', '', 403 );
        }
        $client = sanitize_text_field( wp_unslash( $_POST['client'] ?? '' ) );
        if ( array_key_exists( $client, self::client_registry() ) ) {
            update_user_meta( get_current_user_id(), 'cowboy_mcp_conn_client', $client );
            // Legacy pre-sidebar preference — no longer read; clean it up.
            delete_user_meta( get_current_user_id(), 'cowboy_mcp_conn_method' );
        }
        wp_die();
    }

    /* ── Client registry: single source for sidebar, panels, and AJAX validation ── */

    private static function client_registry(): array {
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
            $scope = self::scope_from_post();
            if ( false === $scope ) {
                add_settings_error( 'cowboy_mcp', 'scope_invalid', __( 'Select an access level before saving.', 'cowboy-mcp' ), 'error' );
            } else {
                $result = Cowboy_MCP_Auth::generate_key( $label, $scope );
                set_transient( 'cowboy_mcp_new_key_' . get_current_user_id(), $result['key'], 3600 );
                add_settings_error( 'cowboy_mcp', 'key_created', __( 'API key created. Copy it now — it will only be shown once.', 'cowboy-mcp' ), 'success' );
            }
        }

        // Revoke key.
        if ( isset( $_POST['cowboy_mcp_revoke_key'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_revoke_key' ) ) {
            $id = sanitize_text_field( wp_unslash( $_POST['key_id'] ?? '' ) );
            Cowboy_MCP_Auth::revoke_key( $id );
            add_settings_error( 'cowboy_mcp', 'key_revoked', __( 'API key revoked.', 'cowboy-mcp' ), 'info' );
        }

        // Update key scope.
        if ( isset( $_POST['cowboy_mcp_update_key_scope'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_update_key_scope' ) ) {
            $id    = sanitize_text_field( wp_unslash( $_POST['key_id'] ?? '' ) );
            $scope = self::scope_from_post();
            if ( false === $scope ) {
                add_settings_error( 'cowboy_mcp', 'scope_invalid', __( 'Select an access level before saving.', 'cowboy-mcp' ), 'error' );
            } elseif ( Cowboy_MCP_Auth::update_key_scope( $id, $scope ) ) {
                add_settings_error( 'cowboy_mcp', 'scope_updated', __( 'Key scope updated. It takes effect on the key\'s next request.', 'cowboy-mcp' ), 'success' );
            } else {
                add_settings_error( 'cowboy_mcp', 'scope_update_failed', __( 'Could not update key scope.', 'cowboy-mcp' ), 'error' );
            }
        }

        // Revoke OAuth connection.
        if ( isset( $_POST['cowboy_mcp_revoke_oauth'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_revoke_oauth' ) ) {
            $cid = sanitize_text_field( wp_unslash( $_POST['oauth_client_id'] ?? '' ) );
            if ( class_exists( 'Cowboy_MCP_OAuth' ) && $cid !== '' ) {
                Cowboy_MCP_OAuth::revoke_connection( $cid );
                add_settings_error( 'cowboy_mcp', 'oauth_revoked', __( 'Connection revoked.', 'cowboy-mcp' ), 'info' );
            }
        }

        // Update OAuth connection scope.
        if ( isset( $_POST['cowboy_mcp_update_oauth_scope'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_update_oauth_scope' ) ) {
            $cid   = sanitize_text_field( wp_unslash( $_POST['oauth_client_id'] ?? '' ) );
            $scope = self::scope_from_post();
            if ( false === $scope ) {
                add_settings_error( 'cowboy_mcp', 'scope_invalid', __( 'Select an access level before saving.', 'cowboy-mcp' ), 'error' );
            } elseif ( class_exists( 'Cowboy_MCP_OAuth' ) && $cid !== '' && Cowboy_MCP_OAuth::update_connection_scope( $cid, $scope ) ) {
                add_settings_error( 'cowboy_mcp', 'oauth_scope_updated', __( 'Connection scope updated. It takes effect on the connection\'s next request.', 'cowboy-mcp' ), 'success' );
            } else {
                add_settings_error( 'cowboy_mcp', 'oauth_scope_update_failed', __( 'Could not update connection scope.', 'cowboy-mcp' ), 'error' );
            }
        }

        // Explicitly enable the Desktop Connector (fallback button on the desktop path).
        if ( isset( $_POST['cowboy_mcp_enable_oauth'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_enable_oauth' ) ) {
            self::enable_oauth_connector( __( 'Desktop Connector enabled.', 'cowboy-mcp' ) );
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

                'undo_enabled'           => ! empty( $_POST['cowboy_mcp_undo_enabled'] ),
                'undo_retention_days'    => max( 1, (int) sanitize_text_field( wp_unslash( $_POST['cowboy_mcp_undo_retention_days'] ?? '7' ) ) ),
                'checkpoint_max'         => max( 1, (int) sanitize_text_field( wp_unslash( $_POST['cowboy_mcp_checkpoint_max'] ?? '5' ) ) ),
                'auto_checkpoint_wp_cli' => ! empty( $_POST['cowboy_mcp_auto_checkpoint_wp_cli'] ),
                'auto_checkpoint_updates' => ! empty( $_POST['cowboy_mcp_auto_checkpoint_updates'] ),

                // Abilities bridge switches are only rendered on WP >= 6.9; carry the stored
                // values forward otherwise so a save on an older core cannot persist `false`.
                'abilities_expose'        => function_exists( 'wp_register_ability' ) ? ! empty( $_POST['cowboy_mcp_abilities_expose'] ) : ( $existing['abilities_expose'] ?? true ),
                'abilities_consume'       => function_exists( 'wp_register_ability' ) ? ! empty( $_POST['cowboy_mcp_abilities_consume'] ) : ( $existing['abilities_consume'] ?? true ),
            ];
            update_option( 'cowboy_mcp_settings', $settings );
            if ( class_exists( 'Cowboy_MCP_Abilities' ) && function_exists( 'wp_register_ability' ) ) {
                Cowboy_MCP_Abilities::rebuild_index();   // escape hatch: a settings save always refreshes the ability index
            }
            add_settings_error( 'cowboy_mcp', 'settings_saved', __( 'Settings saved.', 'cowboy-mcp' ), 'success' );
        }

        // Clear audit log.
        if ( isset( $_POST['cowboy_mcp_clear_audit_log'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_clear_audit_log' ) ) {
            if ( class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
                Cowboy_MCP_Audit_Log::clear();
                add_settings_error( 'cowboy_mcp', 'log_cleared', __( 'Audit log cleared.', 'cowboy-mcp' ), 'info' );
            }
        }

        // Undo a journal entry (Activity tab).
        if ( isset( $_POST['cowboy_mcp_undo_change'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_activity' ) ) {
            $change_id = absint( wp_unslash( $_POST['change_id'] ?? 0 ) );
            $force     = ! empty( $_POST['force'] );
            $result    = Cowboy_MCP_Rollback::undo( $change_id, $force, 'admin' );
            if ( is_wp_error( $result ) ) {
                if ( $result->get_error_code() === 'undo_conflict' ) {
                    set_transient( 'cowboy_mcp_undo_conflict_' . get_current_user_id(), [ 'change_id' => $change_id, 'message' => $result->get_error_message() ], 300 );
                } else {
                    add_settings_error( 'cowboy_mcp', 'undo_failed', esc_html( $result->get_error_message() ), 'error' );
                }
            } else {
                $note = ! empty( $result['note'] ) ? ' ' . $result['note'] : '';
                /* translators: 1: change ID number, 2: optional note appended after the message */
                add_settings_error( 'cowboy_mcp', 'undo_ok', sprintf( esc_html__( 'Change #%1$d undone.%2$s', 'cowboy-mcp' ), $change_id, esc_html( $note ) ), 'success' );
                if ( class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
                    Cowboy_MCP_Audit_Log::log( 'admin_undo_change', [ 'key_id' => 'admin', 'tool' => 'wp_undo_change', 'args' => [ 'change_id' => $change_id, 'force' => $force ] ] );
                }
            }
        }

        // Undo an entire batch.
        if ( isset( $_POST['cowboy_mcp_undo_batch'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_activity' ) ) {
            $batch  = sanitize_text_field( wp_unslash( $_POST['batch_id'] ?? '' ) );
            $result = Cowboy_MCP_Rollback::undo_batch( $batch, ! empty( $_POST['force'] ), 'admin' );
            if ( is_wp_error( $result ) ) {
                add_settings_error( 'cowboy_mcp', 'undo_failed', esc_html( $result->get_error_message() ), 'error' );
            } else {
                /* translators: 1: number of entries undone, 2: total number of entries in the batch */
                $msg = sprintf( esc_html__( '%1$d of %2$d batch entries undone.', 'cowboy-mcp' ), (int) $result['undone_count'], count( $result['results'] ) );
                add_settings_error( 'cowboy_mcp', 'undo_batch', $msg, $result['stopped_early'] ? 'warning' : 'success' );
                if ( class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
                    Cowboy_MCP_Audit_Log::log( 'admin_undo_batch', [ 'key_id' => 'admin', 'tool' => 'wp_undo_change', 'args' => [ 'batch_id' => $batch, 'force' => ! empty( $_POST['force'] ) ] ] );
                }
            }
        }

        // Checkpoints: create / restore / delete.
        if ( isset( $_POST['cowboy_mcp_create_checkpoint'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_activity' ) ) {
            $label = sanitize_text_field( wp_unslash( $_POST['checkpoint_label'] ?? '' ) );
            $r     = Cowboy_MCP_Checkpoint::create( $label, 'manual' );
            if ( is_wp_error( $r ) ) {
                add_settings_error( 'cowboy_mcp', 'cp_failed', esc_html( $r->get_error_message() ), 'error' );
            } else {
                /* translators: 1: checkpoint ID number, 2: human-readable file size */
                add_settings_error( 'cowboy_mcp', 'cp_ok', sprintf( esc_html__( 'Checkpoint #%1$d created (%2$s).', 'cowboy-mcp' ), (int) $r['checkpoint_id'], esc_html( size_format( (int) $r['size_bytes'] ) ) ), 'success' );
                if ( class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
                    Cowboy_MCP_Audit_Log::log( 'admin_create_checkpoint', [ 'key_id' => 'admin', 'tool' => 'wp_create_checkpoint', 'args' => [ 'label' => $label, 'checkpoint_id' => (int) $r['checkpoint_id'] ] ] );
                }
            }
        }
        if ( isset( $_POST['cowboy_mcp_restore_checkpoint'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_activity' ) ) {
            $r = Cowboy_MCP_Checkpoint::restore( absint( wp_unslash( $_POST['checkpoint_id'] ?? 0 ) ), 'admin' );
            if ( is_wp_error( $r ) ) {
                add_settings_error( 'cowboy_mcp', 'cp_failed', esc_html( $r->get_error_message() ), 'error' );
            } else {
                /* translators: 1: restored checkpoint ID number, 2: pre-restore safety checkpoint ID number */
                add_settings_error( 'cowboy_mcp', 'cp_restored', sprintf( esc_html__( 'Database restored from checkpoint #%1$d. Pre-restore safety checkpoint: #%2$d.', 'cowboy-mcp' ), (int) $r['checkpoint_id'], (int) $r['pre_restore_checkpoint_id'] ), 'success' );
                if ( class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
                    Cowboy_MCP_Audit_Log::log( 'admin_restore_checkpoint', [ 'key_id' => 'admin', 'tool' => 'wp_restore_checkpoint', 'args' => [ 'checkpoint_id' => absint( wp_unslash( $_POST['checkpoint_id'] ?? 0 ) ) ] ] );
                }
            }
        }
        if ( isset( $_POST['cowboy_mcp_delete_checkpoint'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_activity' ) ) {
            $r = Cowboy_MCP_Checkpoint::delete( absint( wp_unslash( $_POST['checkpoint_id'] ?? 0 ) ) );
            if ( is_wp_error( $r ) ) {
                add_settings_error( 'cowboy_mcp', 'cp_failed', esc_html( $r->get_error_message() ), 'error' );
            } else {
                add_settings_error( 'cowboy_mcp', 'cp_deleted', esc_html__( 'Checkpoint deleted.', 'cowboy-mcp' ), 'info' );
                if ( class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
                    Cowboy_MCP_Audit_Log::log( 'admin_delete_checkpoint', [ 'key_id' => 'admin', 'tool' => 'wp_delete_checkpoint', 'args' => [ 'checkpoint_id' => absint( wp_unslash( $_POST['checkpoint_id'] ?? 0 ) ) ] ] );
                }
            }
        }
    }

    /**
     * Map posted key_scope_mode/allowed_tools[] fields to a scope array.
     * Returns null for full access, an array for read_only/custom, or false
     * (an "invalid" sentinel) when key_scope_mode is missing or unrecognized —
     * callers must treat false as "reject the submission", never as "full".
     */
    private static function scope_from_post(): array|false|null {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify their own nonce before invoking.
        if ( ! isset( $_POST['key_scope_mode'] ) ) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $mode = sanitize_text_field( wp_unslash( $_POST['key_scope_mode'] ) );
        if ( 'full' === $mode ) {
            return null;
        }
        if ( 'read_only' === $mode ) {
            return [ 'mode' => 'read_only' ];
        }
        if ( 'custom' === $mode ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $tools = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['allowed_tools'] ?? [] ) );
            return [ 'mode' => 'custom', 'allowed_tools' => $tools ];
        }
        return false;
    }

    /** Flip oauth_enabled on (preserving the rest of the settings array). */
    private static function enable_oauth_connector( string $notice ): void {
        $s = get_option( 'cowboy_mcp_settings', [] );
        if ( empty( $s['oauth_enabled'] ) ) {
            $s['oauth_enabled'] = true;
            update_option( 'cowboy_mcp_settings', $s );
            add_settings_error( 'cowboy_mcp', 'oauth_on', $notice, 'success' );
        }
    }

    /* ── Page renderer ────────────────────────────────────── */

    public static function render_page(): void {
        $settings = get_option( 'cowboy_mcp_settings', [] );
        $keys     = Cowboy_MCP_Auth::list_keys();
        $endpoint = rest_url( 'cowboy-mcp/v1/endpoint' );
        $new_key  = get_transient( 'cowboy_mcp_new_key_' . get_current_user_id() );

        $active_client = (string) get_user_meta( get_current_user_id(), 'cowboy_mcp_conn_client', true );
        if ( ! array_key_exists( $active_client, self::client_registry() ) ) {
            $active_client = '';
        }

        // Tab routing with backwards compat.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'connection' ) );

        $tab_map = [
            'connection' => 'connection',
            'settings'   => 'settings',
            'activity'   => 'activity',
            'audit-log'  => 'logs',
            'logs'       => 'logs',
            'about'      => 'about',
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
                    __( 'Connect AI agents like <strong>Claude</strong>, <strong>ChatGPT</strong>, or <strong>Codex</strong> to this WordPress site over the Model Context Protocol.', 'cowboy-mcp' ),
                    [ 'strong' => [] ]
                );
            ?></p>

            <nav class="nav-tab-wrapper mcp-nav-tabs">
                <a href="?page=<?php echo esc_attr( self::SLUG ); ?>&tab=connection" class="nav-tab <?php echo esc_attr( $active_tab === 'connection' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Connection', 'cowboy-mcp' ); ?></a>
                <a href="?page=<?php echo esc_attr( self::SLUG ); ?>&tab=settings" class="nav-tab <?php echo esc_attr( $active_tab === 'settings' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Settings', 'cowboy-mcp' ); ?></a>
                <a href="?page=<?php echo esc_attr( self::SLUG ); ?>&tab=activity" class="nav-tab <?php echo esc_attr( $active_tab === 'activity' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Activity', 'cowboy-mcp' ); ?></a>
                <a href="?page=<?php echo esc_attr( self::SLUG ); ?>&tab=logs" class="nav-tab <?php echo esc_attr( $active_tab === 'logs' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Logs', 'cowboy-mcp' ); ?></a>
                <a href="?page=<?php echo esc_attr( self::SLUG ); ?>&tab=about" class="nav-tab <?php echo esc_attr( $active_tab === 'about' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'About', 'cowboy-mcp' ); ?></a>
            </nav>

            <?php
            match ( $active_tab ) {
                'settings' => self::render_settings_tab( $settings ),
                'activity' => self::render_activity_tab(),
                'logs'     => self::render_logs_tab(),
                'about'    => self::render_about_tab(),
                default    => self::render_connection_tab( $keys, $endpoint, $new_key, $active_client ),
            };
            ?>

        </div>
        <?php
    }

    /* ── About tab ────────────────────────────────────────── */

    private static function render_about_tab(): void {
        ?>
        <div class="postbox">
            <div class="inside">
                <p class="mcp-about-lead"><?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: Model Context Protocol website URL. */
                            __( '<strong>Cowboy MCP</strong> turns this WordPress site into a <a href="%s" target="_blank" rel="noopener noreferrer">Model Context Protocol</a> server, so AI coding agents like Claude Code, Codex, and Opencode can read, edit, and manage the whole site through a single authenticated endpoint.', 'cowboy-mcp' ),
                            esc_url( 'https://modelcontextprotocol.io/' )
                        ),
                        [
                            'strong' => [],
                            'a'      => [ 'href' => [], 'target' => [], 'rel' => [] ],
                        ]
                    );
                ?></p>

                <div class="mcp-choice-grid mcp-res-grid">
                    <a class="mcp-res-card" href="https://cowboymcp.com" target="_blank" rel="noopener noreferrer">
                        <span class="mcp-res-ic" aria-hidden="true">&#x1f310;</span>
                        <span class="mcp-res-body">
                            <span class="mcp-res-title"><?php esc_html_e( 'Website', 'cowboy-mcp' ); ?> &rarr;</span>
                            <span class="mcp-res-sub"><?php esc_html_e( 'Project home, guides, and news.', 'cowboy-mcp' ); ?></span>
                        </span>
                    </a>
                    <a class="mcp-res-card" href="https://github.com/februality/cowboy-mcp" target="_blank" rel="noopener noreferrer">
                        <span class="mcp-res-ic" aria-hidden="true">&#x1f419;</span>
                        <span class="mcp-res-body">
                            <span class="mcp-res-title"><?php esc_html_e( 'GitHub', 'cowboy-mcp' ); ?> &rarr;</span>
                            <span class="mcp-res-sub"><?php esc_html_e( 'Source, issues & releases.', 'cowboy-mcp' ); ?></span>
                        </span>
                    </a>
                    <a class="mcp-res-card" href="https://wordpress.org/support/plugin/cowboy-mcp/" target="_blank" rel="noopener noreferrer">
                        <span class="mcp-res-ic" aria-hidden="true">&#x1f4ac;</span>
                        <span class="mcp-res-body">
                            <span class="mcp-res-title"><?php esc_html_e( 'Get help', 'cowboy-mcp' ); ?> &rarr;</span>
                            <span class="mcp-res-sub"><?php esc_html_e( 'Ask in the WordPress.org support forum. Paste your Connection Doctor report for a fast answer.', 'cowboy-mcp' ); ?></span>
                        </span>
                    </a>
                    <a class="mcp-res-card" href="https://wordpress.org/support/plugin/cowboy-mcp/reviews/#new-post" target="_blank" rel="noopener noreferrer">
                        <span class="mcp-res-ic" aria-hidden="true">&#x2b50;</span>
                        <span class="mcp-res-body">
                            <span class="mcp-res-title"><?php esc_html_e( 'Leave a review', 'cowboy-mcp' ); ?> &rarr;</span>
                            <span class="mcp-res-sub"><?php esc_html_e( 'Enjoying Cowboy MCP? A review on WordPress.org helps other site owners find it.', 'cowboy-mcp' ); ?></span>
                        </span>
                    </a>
                </div>

                <p class="mcp-about-foot"><?php
                    printf(
                        /* translators: %s: plugin version number */
                        esc_html__( 'v%s · GPL-2.0', 'cowboy-mcp' ),
                        esc_html( COWBOY_MCP_VERSION )
                    );
                ?></p>
            </div>
        </div>
        <?php
    }

    /* ── Connection tab: client sidebar + per-client panels ── */

    private static function render_connection_tab( array $keys, string $endpoint, $new_key, string $active_client ): void {
        $registry = self::client_registry();
        $is_local = self::site_looks_local();
        ?>
        <div class="mcp-conn-layout">
            <?php self::render_client_sidebar( $registry, $active_client, $is_local ); ?>
            <div class="mcp-conn-main">

                <div class="mcp-client-panel mcp-client-panel--placeholder <?php echo esc_attr( $active_client === '' ? 'mcp-client-panel--active' : '' ); ?>" data-client-panel="">
                    <div class="postbox">
                        <div class="inside mcp-conn-placeholder">
                            <p class="mcp-chooser-q"><?php esc_html_e( 'Which app do you want to connect?', 'cowboy-mcp' ); ?></p>
                            <p class="mcp-chooser-sub"><?php esc_html_e( 'Pick your AI app or coding tool from the list to see step-by-step setup instructions.', 'cowboy-mcp' ); ?></p>
                        </div>
                    </div>
                </div>

                <?php foreach ( $registry as $slug => $client ) : ?>
                    <div id="mcp-client-panel-<?php echo esc_attr( $slug ); ?>"
                         class="mcp-client-panel <?php echo esc_attr( $slug === $active_client ? 'mcp-client-panel--active' : '' ); ?>"
                         data-client-panel="<?php echo esc_attr( $slug ); ?>"
                         role="tabpanel"
                         aria-labelledby="mcp-conn-item-<?php echo esc_attr( $slug ); ?>"
                         tabindex="0">
                        <?php
                        if ( $client['type'] === 'oauth' ) {
                            self::render_oauth_client_panel( $slug, $endpoint, $is_local, $keys, $new_key );
                        } else {
                            self::render_api_client_panel( $slug, $client['label'], $keys, $endpoint, $new_key, $is_local );
                        }
                        ?>
                    </div>
                <?php endforeach; ?>

            </div>
        </div>

        <div class="cowboy-doctor" id="cowboy-doctor">
            <h2><?php esc_html_e( 'Connection Doctor', 'cowboy-mcp' ); ?></h2>
            <p><?php esc_html_e( 'Test whether AI clients can reach this site and get a copy-pasteable diagnosis for anything broken.', 'cowboy-mcp' ); ?></p>
            <button type="button" class="button button-secondary" id="cowboy-doctor-run"><?php esc_html_e( 'Run checks', 'cowboy-mcp' ); ?></button>
            <button type="button" class="button" id="cowboy-doctor-copy" hidden><?php esc_html_e( 'Copy report', 'cowboy-mcp' ); ?></button>
            <a class="button" id="cowboy-doctor-help" href="https://wordpress.org/support/plugin/cowboy-mcp/" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'Get help on WordPress.org', 'cowboy-mcp' ); ?> &#x2197;</a>
            <div id="cowboy-doctor-results" aria-live="polite"></div>
        </div>
        <?php
        self::render_scope_checklist_template();
    }

    /* ── Sidebar: grouped client buttons ─────────────────── */

    private static function render_client_sidebar( array $registry, string $active_client, bool $is_local = false ): void {
        $groups = [
            'no-terminal' => __( 'No terminal needed', 'cowboy-mcp' ),
            'terminal'    => __( 'Terminal & IDE tools', 'cowboy-mcp' ),
        ];
        $first_slug = array_key_first( $registry );
        ?>
        <nav class="mcp-conn-sidebar" role="tablist" aria-orientation="vertical" aria-label="<?php echo esc_attr__( 'AI clients', 'cowboy-mcp' ); ?>">
            <?php foreach ( $groups as $group_key => $group_label ) : ?>
                <p class="mcp-conn-group-label" role="presentation"><?php echo esc_html( $group_label ); ?></p>
                <?php foreach ( $registry as $slug => $client ) :
                    if ( $client['group'] !== $group_key ) {
                        continue;
                    }
                    $is_active = ( $slug === $active_client );
                    // Roving tabindex: the active item is focusable — or the first item when nothing is selected yet.
                    $tabindex = ( $is_active || ( $active_client === '' && $slug === $first_slug ) ) ? '0' : '-1';
                    ?>
                    <button type="button"
                            id="mcp-conn-item-<?php echo esc_attr( $slug ); ?>"
                            class="mcp-conn-item <?php echo esc_attr( $is_active ? 'mcp-conn-item--active' : '' ); ?>"
                            data-client="<?php echo esc_attr( $slug ); ?>"
                            role="tab"
                            aria-selected="<?php echo esc_attr( $is_active ? 'true' : 'false' ); ?>"
                            aria-controls="mcp-client-panel-<?php echo esc_attr( $slug ); ?>"
                            tabindex="<?php echo esc_attr( $tabindex ); ?>">
                        <span class="mcp-conn-ic" aria-hidden="true"><?php self::render_client_icon( $slug ); ?></span>
                        <span class="mcp-conn-label"><?php echo esc_html( $client['label'] ); ?></span>
                        <?php if ( $is_local ) : // Local-site capability badge (advisory copy only). ?>
                            <?php if ( 'tunnel' === ( $client['local'] ?? '' ) ) : ?>
                                <span class="mcp-conn-badge mcp-conn-badge--warn"><?php esc_html_e( 'Needs public URL', 'cowboy-mcp' ); ?></span>
                            <?php else : ?>
                                <span class="mcp-conn-badge mcp-conn-badge--ok"><?php esc_html_e( 'Works locally', 'cowboy-mcp' ); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /* ── OAuth clients: claude.ai / Claude Desktop ────────── */

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

    /* ── Cloud-connector flow (shared by all three OAuth panels) ── */

    private static function render_oauth_connector_flow( string $slug, string $endpoint, bool $show_warning = true ): void {
        $oauth_avail = class_exists( 'Cowboy_MCP_OAuth' );
        // Read the option directly instead of Cowboy_MCP_OAuth::is_enabled(): that
        // helper memoizes settings at request start, so it still reports "off" in
        // the very response that enable_oauth_connector() just turned it on.
        $settings    = get_option( 'cowboy_mcp_settings', [] );
        $oauth_on    = $oauth_avail && ! empty( $settings['enabled'] ) && ! empty( $settings['oauth_enabled'] );
        $reachable   = ! $oauth_avail || Cowboy_MCP_OAuth::site_is_publicly_reachable();
        $connections = ( $oauth_avail && $oauth_on ) ? Cowboy_MCP_OAuth::list_connections() : [];
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
            ? __( 'ChatGPT opens a sign-in page on <strong>your site</strong>. Review what it is asking for and click <strong>Approve</strong>. That is it — you are connected.', 'cowboy-mcp' )
            : __( 'Claude opens a sign-in page on <strong>your site</strong>. Review what it is asking for and click <strong>Approve</strong>. That is it — you are connected.', 'cowboy-mcp' );
        $plan_note    = $is_chatgpt
            ? __( 'Custom connectors require a paid ChatGPT plan with <strong>Developer mode</strong> (beta) enabled.', 'cowboy-mcp' )
            : __( 'Custom connectors require a Claude <strong>Pro, Max, Team, or Enterprise</strong> plan.', 'cowboy-mcp' );

        if ( $show_warning && ! $reachable ) :
            ?>
            <div class="notice notice-warning inline"><p><?php
                echo wp_kses( $warning_text, [ 'strong' => [] ] );
            ?></p></div>
            <?php
        endif;

        if ( ! $oauth_on ) :
            // Browsing never flips settings — enabling the connector is an explicit click.
            ?>
            <div class="mcp-step mcp-step--active">
                <div class="mcp-step-header">
                    <span class="mcp-step-number">1</span>
                    <h3 style="margin:0;"><?php esc_html_e( 'Turn on the Desktop Connector', 'cowboy-mcp' ); ?></h3>
                </div>
                <div class="mcp-step-body">
                    <p><?php echo esc_html( $enable_text ); ?></p>
                    <form method="post" class="mcp-inline-form">
                        <?php wp_nonce_field( 'cowboy_mcp_enable_oauth' ); ?>
                        <button type="submit" name="cowboy_mcp_enable_oauth" class="button button-primary"><?php esc_html_e( 'Enable Desktop Connector', 'cowboy-mcp' ); ?></button>
                    </form>
                </div>
            </div>
            <?php
            return;
        endif;
        ?>
        <div class="mcp-step mcp-step--active">
            <div class="mcp-step-header">
                <span class="mcp-step-number">1</span>
                <h3 style="margin:0;"><?php esc_html_e( 'Copy your connection link', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <p><?php echo esc_html( $paste_hint ); ?></p>
                <div class="mcp-code-block">
                    <code id="mcp-oauth-url-<?php echo esc_attr( $slug ); ?>"><?php echo esc_url( $endpoint ); ?></code>
                </div>
                <button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-oauth-url-<?php echo esc_attr( $slug ); ?>" aria-label="<?php echo esc_attr__( 'Copy connector URL', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
            </div>
        </div>

        <div class="mcp-step">
            <div class="mcp-step-header">
                <span class="mcp-step-number">2</span>
                <h3 style="margin:0;"><?php echo esc_html( $step2_title ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <ol class="mcp-substeps">
                    <?php if ( $is_chatgpt ) : ?>
                        <li><?php echo wp_kses( __( 'Go to <code>chatgpt.com</code> (or open the ChatGPT desktop app) and sign in.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Go to <code>Settings → Connectors → Advanced</code> and turn on <strong>Developer mode</strong> (one-time).', 'cowboy-mcp' ), [ 'code' => [], 'strong' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Back in <code>Connectors</code>, click <strong>Create</strong>.', 'cowboy-mcp' ), [ 'code' => [], 'strong' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Paste the link from step 1 as the <strong>MCP server URL</strong>, set Authentication to <strong>OAuth</strong>, and click <strong>Create</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                    <?php else : ?>
                        <?php if ( $is_desktop ) : ?>
                            <li><?php echo wp_kses( __( 'Open the <strong>Claude</strong> desktop app and sign in.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                        <?php else : ?>
                            <li><?php echo wp_kses( __( 'Go to <code>claude.ai</code> in your browser and sign in.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
                        <?php endif; ?>
                        <li><?php echo wp_kses( __( 'Go to <code>Customize → Connections</code>.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Click <strong>Add custom connector</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                        <li><?php echo wp_kses( __( 'Paste the link from step 1 and click <strong>Add</strong>.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></li>
                    <?php endif; ?>
                </ol>
            </div>
        </div>

        <div class="mcp-step">
            <div class="mcp-step-header">
                <span class="mcp-step-number">3</span>
                <h3 style="margin:0;"><?php esc_html_e( 'Approve access', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <p><?php echo wp_kses( $approve_text, [ 'strong' => [] ] ); ?></p>
                <p class="description"><?php echo wp_kses( $plan_note, [ 'strong' => [] ] ); ?></p>
            </div>
        </div>
        <?php
        self::render_connections_table( $connections );
    }

    /* ── Local-site guidance for the cloud-only panels (claude.ai / ChatGPT) ── */

    private static function render_local_oauth_guidance( string $slug ): void {
        // Same two msgids the connector flow uses, so existing translations carry over.
        $warning_text = ( 'chatgpt' === $slug )
            ? __( '<strong>Heads up:</strong> this site does not appear to be on a public HTTPS address. ChatGPT connects from OpenAI\'s cloud, so it cannot reach local, private, or non-HTTPS sites. A terminal tool works here instead, or connect through a tunnel/staging URL.', 'cowboy-mcp' )
            : __( '<strong>Heads up:</strong> this site does not appear to be on a public HTTPS address. The Claude apps connect from Anthropic\'s cloud, so they cannot reach local, private, or non-HTTPS sites. A terminal tool works here instead, or connect through a tunnel/staging URL.', 'cowboy-mcp' );
        ?>
        <div class="notice notice-warning inline"><p><?php echo wp_kses( $warning_text, [ 'strong' => [] ] ); ?></p></div>
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

    /* ── Claude Desktop on a local site: on-machine mcp-remote bridge ── */

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
        <div class="notice notice-info inline"><p><?php
            echo wp_kses( __( 'This site runs on your computer, so the cloud connector cannot reach it. Connect Claude Desktop through a <strong>local bridge</strong> instead: a small helper that runs on this computer and forwards Claude Desktop to the site. Nothing is exposed to the internet.', 'cowboy-mcp' ), [ 'strong' => [] ] );
        ?></p></div>
        <?php
        self::render_key_step( 'claude-desktop', 'Claude Desktop', $new_key, $has_keys, 'read_only' );
        ?>
        <div class="mcp-step <?php echo esc_attr( $new_key ? 'mcp-step--active' : '' ); ?>">
            <div class="mcp-step-header">
                <span class="mcp-step-number">2</span>
                <h3 style="margin:0;"><?php esc_html_e( 'Add the bridge to Claude Desktop', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <p><?php echo wp_kses( __( 'Add this to <code>claude_desktop_config.json</code> (create the file if it does not exist), then fully quit and restart the Claude app:', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <div class="mcp-code-block">
                    <code id="mcp-cmd-claude-desktop"><?php echo esc_html( self::bridge_config_snippet( $domain, $endpoint, $key_display ) ); ?></code>
                </div>
                <button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-cmd-claude-desktop" aria-label="<?php echo esc_attr__( 'Copy setup command', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
                <p class="description"><?php echo wp_kses( __( 'macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code> — Windows: <code>%APPDATA%\Claude\claude_desktop_config.json</code>', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <p class="description"><?php echo wp_kses( __( 'Requires Node.js on this computer — the bridge is the standard open-source <code>mcp-remote</code> package, started on demand via <code>npx</code>. It connects only from your computer to this site.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <p class="description"><?php echo wp_kses( __( 'Your API key is stored in plain text in that file, so prefer a <strong>read-only</strong> key unless this connection needs to make changes — and revoke it here when you no longer use it.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p>
                <?php
                if ( $offer_http ) {
                    self::render_cert_error_details( 'claude-desktop', self::bridge_config_snippet( $domain, set_url_scheme( $endpoint, 'http' ), $key_display ) );
                }
                ?>
            </div>
        </div>
        <div class="mcp-step">
            <div class="mcp-step-header">
                <span class="mcp-step-number">3</span>
                <h3 style="margin:0;"><?php esc_html_e( 'Check it worked', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <p><?php
                    /* translators: %s: the MCP server name shown in the client. */
                    echo wp_kses( sprintf( __( 'Open a new chat in Claude Desktop and click the tools icon — <code>%s</code> should be listed. The first start can take a moment while the bridge downloads.', 'cowboy-mcp' ), esc_html( $domain ) ), [ 'code' => [] ] );
                ?></p>
            </div>
        </div>
        <?php
        if ( $has_keys ) {
            self::render_keys_table( $keys );
        }
    }

    /** claude_desktop_config.json snippet. The Authorization header value goes
     *  through mcp-remote's ${VAR} env expansion so the space in "Bearer <key>"
     *  survives Claude Desktop's Windows argument handling. */
    private static function bridge_config_snippet( string $domain, string $endpoint, string $key_display ): string {
        return "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"command\": \"npx\",\n      \"args\": [\"-y\", \"mcp-remote\", \"{$endpoint}\",\n        \"--header\", \"Authorization:\${AUTH_HEADER}\"],\n      \"env\": { \"AUTH_HEADER\": \"Bearer {$key_display}\" }\n    }\n  }\n}";
    }

    /* ── Self-signed-certificate fallback (loopback-shaped local sites only) ── */

    private static function render_cert_error_details( string $slug, string $alt_snippet ): void {
        $alt_id = 'mcp-cmd-http-' . $slug;
        ?>
        <details class="mcp-local-details">
            <summary><?php esc_html_e( 'Getting a certificate error?', 'cowboy-mcp' ); ?></summary>
            <div class="mcp-local-details-body">
                <p><?php echo wp_kses( __( 'Local sites usually use a self-signed certificate that AI tools reject. Since this site runs on this same computer, you can use the plain <code>http://</code> address instead — the connection never leaves your machine. Avoid the <code>NODE_TLS_REJECT_UNAUTHORIZED=0</code> workaround you may see online: it disables certificate checks for everything that tool connects to.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
                <div class="mcp-code-block">
                    <code id="<?php echo esc_attr( $alt_id ); ?>"><?php echo esc_html( $alt_snippet ); ?></code>
                </div>
                <button type="button" class="button button-small mcp-copy-btn" data-copy-target="<?php echo esc_attr( $alt_id ); ?>" aria-label="<?php echo esc_attr__( 'Copy setup command', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
            </div>
        </details>
        <?php
    }

    /* ── API-key clients: Claude Code / Codex / Opencode / Cursor / Gemini CLI ── */

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

        if ( $has_keys ) {
            self::render_keys_table( $keys );
        }
    }

    /* ── Step 1 partial: create an API key (one per API-key panel) ── */

    private static function render_key_step( string $slug, string $label, $new_key, bool $has_keys, string $default_scope = 'full' ): void {
        ?>
        <div class="mcp-step mcp-key-step <?php echo esc_attr( $new_key ? 'mcp-step--completed' : 'mcp-step--active' ); ?>">
            <div class="mcp-step-header">
                <span class="mcp-step-number"><?php echo esc_html( $new_key ? '✓' : '1' ); ?></span>
                <h3 style="margin:0;"><?php esc_html_e( 'Create an API key', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <?php if ( $new_key ) : ?>
                    <div class="notice notice-success inline"><p><?php echo wp_kses( __( '<strong>Copy your key now</strong> — for security it will not be shown again.', 'cowboy-mcp' ), [ 'strong' => [] ] ); ?></p></div>
                    <div class="mcp-code-block">
                        <code id="mcp-new-key-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $new_key ); ?></code>
                    </div>
                    <button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-new-key-<?php echo esc_attr( $slug ); ?>" aria-label="<?php echo esc_attr__( 'Copy API key', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
                    <button type="button" class="button button-small mcp-dismiss-key" style="margin-left:4px;"><?php esc_html_e( "I've saved my key", 'cowboy-mcp' ); ?></button>
                <?php else : ?>
                    <p><?php esc_html_e( 'Give it a name so you can recognize it later, then generate.', 'cowboy-mcp' ); ?></p>
                    <form method="post" class="mcp-generate-form">
                        <?php wp_nonce_field( 'cowboy_mcp_generate_key' ); ?>
                        <input type="text" name="key_label" value="" placeholder="<?php
                            /* translators: %s: client name, e.g. "Claude Code" */
                            echo esc_attr( sprintf( __( 'e.g. %s on my laptop', 'cowboy-mcp' ), $label ) );
                        ?>" class="regular-text" style="max-width: 240px;">
                        <?php self::render_scope_radios( $default_scope ); ?>
                        <button type="submit" name="cowboy_mcp_generate_key" class="button button-primary"><?php echo $has_keys ? esc_html__( 'Generate another key', 'cowboy-mcp' ) : esc_html__( 'Generate API key', 'cowboy-mcp' ); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ── Step 2 partial: client-specific install instructions ── */

    private static function render_install_step( string $slug, string $domain, string $endpoint, string $key_display, bool $step_active, bool $offer_http_variant = false ): void {
        $code_id = 'mcp-cmd-' . $slug;
        $intro   = match ( $slug ) {
            'claude-code',
            'gemini-cli' => esc_html__( 'Run this in your terminal:', 'cowboy-mcp' ),
            'codex'      => esc_html__( 'Run these in your terminal:', 'cowboy-mcp' ),
            'opencode'   => wp_kses( __( 'Add this to your <code>opencode.json</code>:', 'cowboy-mcp' ), [ 'code' => [] ] ),
            'cursor'     => wp_kses( __( 'Add this to <code>~/.cursor/mcp.json</code> (create the file if it does not exist), then reload Cursor:', 'cowboy-mcp' ), [ 'code' => [] ] ),
            default      => '',
        };
        ?>
        <div class="mcp-step <?php echo esc_attr( $step_active ? 'mcp-step--active' : '' ); ?>">
            <div class="mcp-step-header">
                <span class="mcp-step-number">2</span>
                <h3 style="margin:0;"><?php esc_html_e( 'Add the server to your tool', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <p><?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per-branch in the match above. ?></p>
                <div class="mcp-code-block">
                    <code id="<?php echo esc_attr( $code_id ); ?>"><?php echo esc_html( self::install_snippet( $slug, $domain, $endpoint, $key_display ) ); ?></code>
                </div>
                <button type="button" class="button button-small mcp-copy-btn" data-copy-target="<?php echo esc_attr( $code_id ); ?>" aria-label="<?php echo esc_attr__( 'Copy setup command', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Copy', 'cowboy-mcp' ); ?></button>
                <?php
                if ( $offer_http_variant ) {
                    self::render_cert_error_details( $slug, self::install_snippet( $slug, $domain, set_url_scheme( $endpoint, 'http' ), $key_display ) );
                }
                ?>
            </div>
        </div>
        <?php
    }

    /** Raw (unescaped) setup snippet for an API-key client. Escaped at output. */
    private static function install_snippet( string $slug, string $domain, string $endpoint, string $key_display ): string {
        return match ( $slug ) {
            'claude-code' => 'claude mcp add --transport http ' . $domain . ' ' . $endpoint . ' --header "Authorization: Bearer ' . $key_display . '"',
            'codex'       => 'export COWBOY_MCP_API_KEY="' . $key_display . "\"\n" . 'codex mcp add ' . $domain . ' --url ' . $endpoint . ' --bearer-token-env-var COWBOY_MCP_API_KEY',
            'opencode'    => "{\n  \"mcp\": {\n    \"{$domain}\": {\n      \"type\": \"remote\",\n      \"url\": \"{$endpoint}\",\n      \"headers\": {\n        \"Authorization\": \"Bearer {$key_display}\"\n      }\n    }\n  }\n}",
            'cursor'      => "{\n  \"mcpServers\": {\n    \"{$domain}\": {\n      \"url\": \"{$endpoint}\",\n      \"headers\": {\n        \"Authorization\": \"Bearer {$key_display}\"\n      }\n    }\n  }\n}",
            'gemini-cli'  => 'gemini mcp add --transport http ' . $domain . ' ' . $endpoint . ' --header "Authorization: Bearer ' . $key_display . '"',
            default       => '',
        };
    }

    /* ── Step 3 partial: client-specific verification ─────── */

    private static function render_verify_step( string $slug, string $domain ): void {
        $text = match ( $slug ) {
            /* translators: %s: the MCP server name shown in the client. */
            'claude-code' => sprintf( __( 'Open a new Claude Code session and run <code>/mcp</code> — <code>%s</code> should be listed as connected.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'codex'       => sprintf( __( 'Run <code>codex mcp list</code> — <code>%s</code> should appear in the list.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'opencode'    => sprintf( __( 'Restart Opencode and ask it to list its MCP servers — <code>%s</code> should be available.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'cursor'      => sprintf( __( 'Open <code>Cursor Settings → MCP</code> — <code>%s</code> should appear with a green status dot.', 'cowboy-mcp' ), esc_html( $domain ) ),
            /* translators: %s: the MCP server name shown in the client. */
            'gemini-cli'  => sprintf( __( 'Run <code>gemini mcp list</code> — <code>%s</code> should show as connected.', 'cowboy-mcp' ), esc_html( $domain ) ),
            default       => '',
        };
        ?>
        <div class="mcp-step">
            <div class="mcp-step-header">
                <span class="mcp-step-number">3</span>
                <h3 style="margin:0;"><?php esc_html_e( 'Check it worked', 'cowboy-mcp' ); ?></h3>
            </div>
            <div class="mcp-step-body">
                <p><?php echo wp_kses( $text, [ 'code' => [] ] ); ?></p>
            </div>
        </div>
        <?php
    }

    /* ── Table partial: existing API keys ─────────────────── */

    private static function render_keys_table( array $keys ): void {
        ?>
        <h3 class="mcp-table-cap"><?php esc_html_e( 'Existing keys', 'cowboy-mcp' ); ?></h3>
        <div class="mcp-table-wrap">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Label', 'cowboy-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Prefix', 'cowboy-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Created', 'cowboy-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Last Used', 'cowboy-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Scope', 'cowboy-mcp' ); ?></th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $keys as $k ) : ?>
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
                        <?php echo esc_html( self::scope_badge( $k['scope'] ?? null ) ); ?>
                        <button type="button" class="button-link mcp-edit-scope" aria-expanded="false"><?php esc_html_e( 'Edit', 'cowboy-mcp' ); ?></button>
                    </td>
                    <td>
                        <form method="post" class="mcp-revoke-form">
                            <?php wp_nonce_field( 'cowboy_mcp_revoke_key' ); ?>
                            <input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>">
                            <button type="submit" name="cowboy_mcp_revoke_key" class="button button-small button-link-delete"
                                    data-confirm="<?php echo esc_attr__( 'Revoke this key? Any client using it will lose access.', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Revoke', 'cowboy-mcp' ); ?></button>
                        </form>
                    </td>
                </tr>
                <tr class="mcp-scope-editor-row" hidden>
                    <td colspan="6">
                        <form method="post" class="mcp-scope-editor-form">
                            <?php wp_nonce_field( 'cowboy_mcp_update_key_scope' ); ?>
                            <input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>">
                            <?php
                            $key_scope = $k['scope'] ?? null;
                            self::render_scope_radios(
                                $key_scope['mode'] ?? 'full',
                                (string) wp_json_encode( $key_scope['allowed_tools'] ?? [] )
                            );
                            ?>
                            <button type="submit" name="cowboy_mcp_update_key_scope" class="button button-primary"><?php esc_html_e( 'Save scope', 'cowboy-mcp' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /* ── Table partial: connected OAuth apps ──────────────── */

    private static function render_connections_table( array $connections ): void {
        if ( empty( $connections ) ) {
            return;
        }
        ?>
        <h3 class="mcp-table-cap"><?php esc_html_e( 'Connected apps', 'cowboy-mcp' ); ?></h3>
        <div class="mcp-table-wrap">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'App', 'cowboy-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Authorized by', 'cowboy-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Connected', 'cowboy-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Last Used', 'cowboy-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Scope', 'cowboy-mcp' ); ?></th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $connections as $c ) : ?>
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
                        <?php echo esc_html( self::scope_badge( $c['tool_scope'] ?? null ) ); ?>
                        <button type="button" class="button-link mcp-edit-scope" aria-expanded="false"><?php esc_html_e( 'Edit', 'cowboy-mcp' ); ?></button>
                    </td>
                    <td>
                        <form method="post" class="mcp-revoke-form">
                            <?php wp_nonce_field( 'cowboy_mcp_revoke_oauth' ); ?>
                            <input type="hidden" name="oauth_client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>">
                            <button type="submit" name="cowboy_mcp_revoke_oauth" class="button button-small button-link-delete"
                                    data-confirm="<?php echo esc_attr__( 'Revoke this connection? The app will lose access immediately.', 'cowboy-mcp' ); ?>"><?php esc_html_e( 'Revoke', 'cowboy-mcp' ); ?></button>
                        </form>
                    </td>
                </tr>
                <tr class="mcp-scope-editor-row" hidden>
                    <td colspan="6">
                        <form method="post" class="mcp-scope-editor-form">
                            <?php wp_nonce_field( 'cowboy_mcp_update_oauth_scope' ); ?>
                            <input type="hidden" name="oauth_client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>">
                            <?php
                            $conn_scope = $c['tool_scope'] ?? null;
                            self::render_scope_radios(
                                $conn_scope['mode'] ?? 'full',
                                (string) wp_json_encode( $conn_scope['allowed_tools'] ?? [] )
                            );
                            ?>
                            <button type="submit" name="cowboy_mcp_update_oauth_scope" class="button button-primary"><?php esc_html_e( 'Save scope', 'cowboy-mcp' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
        self::render_scope_checklist_template();
    }

    /* ── Scope UI partials ────────────────────────────────── */

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
            <div class="mcp-scope-checklist">
                <?php foreach ( $catalog['categories'] as $cat => $info ) : ?>
                    <details class="mcp-scope-cat">
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
        ?>
        <div class="mcp-scope-select" data-scope-tools="<?php echo esc_attr( $tools_json ); ?>">
            <label><input type="radio" name="key_scope_mode" value="full" <?php checked( $mode, 'full' ); ?>>
                <?php esc_html_e( 'Full access', 'cowboy-mcp' ); ?></label>
            <label><input type="radio" name="key_scope_mode" value="read_only" <?php checked( $mode, 'read_only' ); ?>>
                <?php esc_html_e( 'Read-only', 'cowboy-mcp' ); ?></label>
            <label><input type="radio" name="key_scope_mode" value="custom" <?php checked( $mode, 'custom' ); ?>>
                <?php esc_html_e( 'Custom…', 'cowboy-mcp' ); ?></label>
            <div class="mcp-scope-custom-slot" <?php echo 'custom' === $mode ? '' : 'hidden'; ?>></div>
        </div>
        <?php
    }

    /** Human badge for a stored scope array. */
    private static function scope_badge( ?array $scope ): string {
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

    /* ── Settings tab ─────────────────────────────────────── */

    private static function render_settings_tab( array $settings ): void {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'cowboy_mcp_save_settings' ); ?>

            <div class="postbox">
                <div class="postbox-header"><h2><?php esc_html_e( 'General', 'cowboy-mcp' ); ?></h2></div>
                <div class="inside">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'MCP Server', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_enabled" value="1" <?php checked( $settings['enabled'] ?? true ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php esc_html_e( 'Enabled', 'cowboy-mcp' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'When off, every MCP request is rejected.', 'cowboy-mcp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Safe Mode', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_safe_mode" value="1" <?php checked( $settings['safe_mode'] ?? true ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php esc_html_e( 'Require confirmation for destructive operations', 'cowboy-mcp' ); ?>
                                </label>
                                <p class="description"><?php
                                    echo wp_kses(
                                        __( 'Tools marked as destructive (delete, drop, WP-CLI write commands, etc.) require <code>confirm: true</code> in the request.', 'cowboy-mcp' ),
                                        [ 'code' => [] ]
                                    );
                                ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Desktop Connector', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_oauth_enabled" value="1" <?php checked( $settings['oauth_enabled'] ?? false ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php esc_html_e( 'Allow connecting via Claude Desktop / web (OAuth)', 'cowboy-mcp' ); ?>
                                </label>
                                <p class="description"><?php
                                    echo wp_kses(
                                        __( 'Turns on the one-click browser sign-in used by the &#8220;Claude Desktop&#8221; connection path. This exposes public OAuth discovery, registration, and token endpoints — no tokens are issued until an administrator approves in the browser. Leave off if you only connect via the terminal.', 'cowboy-mcp' ),
                                        [ 'code' => [] ]
                                    );
                                ?></p>
                                <?php if ( ! empty( $settings['oauth_enabled'] ) ) : ?>
                                    <p class="description"><?php esc_html_e( 'Tip: run the Connection Doctor on the Connection tab to verify the connector end to end.', 'cowboy-mcp' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Rate Limit', 'cowboy-mcp' ); ?></th>
                            <td>
                                <input type="number" name="cowboy_mcp_rate_limit" value="<?php echo esc_attr( (int) ( $settings['rate_limit'] ?? 120 ) ); ?>" min="10" max="1000" class="small-text">
                                <span><?php esc_html_e( 'requests per minute, per key', 'cowboy-mcp' ); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Request Logging', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_log_requests" value="1" <?php checked( $settings['log_requests'] ?? false ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php echo wp_kses( __( 'Log all tool calls to <code>debug.log</code>', 'cowboy-mcp' ), [ 'code' => [] ] ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="postbox">
                <div class="postbox-header"><h2><?php esc_html_e( 'Undo & Checkpoints', 'cowboy-mcp' ); ?></h2></div>
                <div class="inside">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Undo Journal', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_undo_enabled" value="1" <?php checked( ! isset( $settings['undo_enabled'] ) || $settings['undo_enabled'] ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php esc_html_e( 'Undo journal — capture before-state of every mutating tool call', 'cowboy-mcp' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'Lets changes made through MCP be undone from the Activity tab. Disabling stops new entries from being captured; existing history is kept until it expires.', 'cowboy-mcp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Undo Retention', 'cowboy-mcp' ); ?></th>
                            <td>
                                <input type="number" name="cowboy_mcp_undo_retention_days" value="<?php echo esc_attr( (int) ( $settings['undo_retention_days'] ?? 7 ) ); ?>" min="1" class="small-text">
                                <span><?php esc_html_e( 'days to keep undo history', 'cowboy-mcp' ); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Checkpoint Limit', 'cowboy-mcp' ); ?></th>
                            <td>
                                <input type="number" name="cowboy_mcp_checkpoint_max" value="<?php echo esc_attr( (int) ( $settings['checkpoint_max'] ?? 5 ) ); ?>" min="1" class="small-text">
                                <span><?php esc_html_e( 'checkpoints to keep', 'cowboy-mcp' ); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto-Checkpoint', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_auto_checkpoint_wp_cli" value="1" <?php checked( ! isset( $settings['auto_checkpoint_wp_cli'] ) || $settings['auto_checkpoint_wp_cli'] ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php esc_html_e( 'Auto-checkpoint before mutating WP-CLI commands', 'cowboy-mcp' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'Takes a full-database checkpoint before running a WP-CLI command that is not read-only, so it can be rolled back even if the command itself cannot be undone.', 'cowboy-mcp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto-Checkpoint', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_auto_checkpoint_updates" value="1" <?php checked( ! isset( $settings['auto_checkpoint_updates'] ) || $settings['auto_checkpoint_updates'] ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php esc_html_e( 'Auto-checkpoint before plugin & theme updates', 'cowboy-mcp' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'Takes a full-database checkpoint before plugin or theme updates, so database migrations run by an update can be rolled back. The old files are separately backed up per update for undo.', 'cowboy-mcp' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="postbox">
                <div class="postbox-header"><h2><?php esc_html_e( 'WordPress Abilities API', 'cowboy-mcp' ); ?></h2></div>
                <div class="inside">
                    <?php if ( function_exists( 'wp_register_ability' ) ) : ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Expose Tools', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_abilities_expose" value="1" <?php checked( ! isset( $settings['abilities_expose'] ) || $settings['abilities_expose'] ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php esc_html_e( 'Expose tools as WordPress Abilities', 'cowboy-mcp' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( "Registers every allowed tool as a cowboy-mcp/* ability so WP-CLI, the REST API, MCP adapters and AI agents can call it — with Cowboy's safe mode, audit log and undo.", 'cowboy-mcp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Use Abilities', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_abilities_consume" value="1" <?php checked( ! isset( $settings['abilities_consume'] ) || $settings['abilities_consume'] ); ?>><span class="mcp-switch-track"></span></span>
                                    <?php esc_html_e( 'Use abilities from other plugins', 'cowboy-mcp' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'Shows abilities registered by other plugins as tools in the abilities category. They run their own permission checks and are not undoable.', 'cowboy-mcp' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php else : ?>
                    <p class="description"><?php esc_html_e( 'WordPress Abilities API requires WordPress 6.9 or newer.', 'cowboy-mcp' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="postbox mcp-danger-zone">
                <div class="postbox-header"><h2><?php esc_html_e( 'Power Mode', 'cowboy-mcp' ); ?> <span class="mcp-h2-sub"><?php esc_html_e( 'advanced · off by default', 'cowboy-mcp' ); ?></span></h2></div>
                <div class="inside">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Power Mode', 'cowboy-mcp' ); ?></th>
                            <td>
                                <label class="mcp-switch-label">
                                    <span class="mcp-switch"><input type="checkbox" name="cowboy_mcp_power_mode" value="1" <?php checked( $settings['power_mode'] ?? false ); ?>><span class="mcp-switch-track"></span></span>
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
                    </table>
                </div>
            </div>

            <p class="submit">
                <button type="submit" name="cowboy_mcp_save_settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'cowboy-mcp' ); ?></button>
            </p>
        </form>
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
                        <?php foreach ( [ 'tool_call', 'tool_error', 'tool_exception', 'auth_missing_header', 'auth_invalid_key', 'rate_limit_exceeded' ] as $ev ) : ?>
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
                        <?php foreach ( [ 25, 50, 100 ] as $pp ) : ?>
                            <option value="<?php echo esc_attr( (int) $pp ); ?>" <?php selected( $filters['per_page'], $pp ); ?>><?php echo esc_html( (int) $pp ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'cowboy-mcp' ); ?></button>
                <?php if ( $has_active_filter ) : ?>
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
            <?php if ( empty( $entries ) ) : ?>
                <p><em><?php esc_html_e( 'No log entries found.', 'cowboy-mcp' ); ?></em></p>
            <?php else : ?>
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
                    <?php foreach ( $entries as $row ) :
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
                        <?php if ( $has_args ) : ?>
                        <tr class="mcp-log-detail">
                            <td colspan="6"><pre><?php echo esc_html( is_array( $row['args'] ) ? wp_json_encode( $row['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $row['args'] ); ?></pre></td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Pagination -->
                <?php if ( $total_pages > 1 ) : ?>
                <div class="mcp-pagination">
                    <?php if ( $filters['page'] > 1 ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $filters['page'] - 1, $base_url ) ); ?>" class="button button-small">&laquo; <?php esc_html_e( 'Previous', 'cowboy-mcp' ); ?></a>
                    <?php endif; ?>
                    <span><?php
                        /* translators: 1: current page number, 2: total number of pages */
                        printf( esc_html__( 'Page %1$d of %2$d', 'cowboy-mcp' ), (int) $filters['page'], (int) $total_pages );
                    ?></span>
                    <?php if ( $filters['page'] < $total_pages ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $filters['page'] + 1, $base_url ) ); ?>" class="button button-small"><?php esc_html_e( 'Next', 'cowboy-mcp' ); ?> &raquo;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php
    }

    /* ── Activity / Undo tab ─────────────────────────────── */

    private static function render_activity_tab(): void {
        $per_page = 25;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page   = max( 1, absint( wp_unslash( $_GET['activity_page'] ?? 1 ) ) );
        $result = Cowboy_MCP_Rollback::query( [ 'per_page' => $per_page, 'page' => $page ] );
        $nonce  = wp_create_nonce( 'cowboy_mcp_activity' );

        // Pending conflict from a previous undo attempt → force prompt.
        $conflict = get_transient( 'cowboy_mcp_undo_conflict_' . get_current_user_id() );
        if ( $conflict ) {
            delete_transient( 'cowboy_mcp_undo_conflict_' . get_current_user_id() );
            ?>
            <div class="notice notice-warning">
                <p><strong><?php esc_html_e( 'Undo conflict:', 'cowboy-mcp' ); ?></strong> <?php echo esc_html( $conflict['message'] ); ?></p>
                <form method="post" style="margin-bottom:10px">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <input type="hidden" name="change_id" value="<?php echo esc_attr( $conflict['change_id'] ); ?>">
                    <input type="hidden" name="force" value="1">
                    <button type="submit" name="cowboy_mcp_undo_change" value="1" class="button button-secondary"><?php esc_html_e( 'Force undo anyway', 'cowboy-mcp' ); ?></button>
                </form>
            </div>
            <?php
        }

        // ── Checkpoints panel ──
        $checkpoints = Cowboy_MCP_Checkpoint::list_all();
        ?>
        <h2><?php esc_html_e( 'Database Checkpoints', 'cowboy-mcp' ); ?></h2>
        <form method="post" class="mcp-cp-create">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
            <input type="text" name="checkpoint_label" placeholder="<?php esc_attr_e( 'Label (optional)', 'cowboy-mcp' ); ?>">
            <button type="submit" name="cowboy_mcp_create_checkpoint" value="1" class="button"><?php esc_html_e( 'Create checkpoint now', 'cowboy-mcp' ); ?></button>
        </form>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'ID', 'cowboy-mcp' ); ?></th><th><?php esc_html_e( 'Created', 'cowboy-mcp' ); ?></th>
                <th><?php esc_html_e( 'Label', 'cowboy-mcp' ); ?></th><th><?php esc_html_e( 'Trigger', 'cowboy-mcp' ); ?></th>
                <th><?php esc_html_e( 'Size', 'cowboy-mcp' ); ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $checkpoints ) ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No checkpoints yet.', 'cowboy-mcp' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $checkpoints as $cp ) : ?>
                <tr>
                    <td>#<?php echo (int) $cp['id']; ?></td>
                    <td><?php echo esc_html( $cp['created'] ); ?></td>
                    <td><?php echo esc_html( $cp['label'] ); ?></td>
                    <td><span class="mcp-badge mcp-badge-<?php echo esc_attr( $cp['trigger_type'] ); ?>"><?php echo esc_html( $cp['trigger_type'] ); ?></span></td>
                    <td><?php echo esc_html( size_format( (int) $cp['size_bytes'] ) ); ?></td>
                    <td>
                        <form method="post" style="display:inline" data-mcp-confirm="<?php
                            /* translators: 1: checkpoint ID number, 2: checkpoint creation date/time */
                            echo esc_attr( sprintf( __( 'Restore the database to checkpoint #%1$s (%2$s)? EVERYTHING changed since then — including changes made outside MCP — will be lost. A pre-restore safety checkpoint is taken first.', 'cowboy-mcp' ), $cp['id'], $cp['created'] ) );
                        ?>" data-mcp-confirm-2="<?php esc_attr_e( 'Really sure? This rewrites every site table.', 'cowboy-mcp' ); ?>">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                            <input type="hidden" name="checkpoint_id" value="<?php echo (int) $cp['id']; ?>">
                            <button type="submit" name="cowboy_mcp_restore_checkpoint" value="1" class="button button-small"><?php esc_html_e( 'Restore', 'cowboy-mcp' ); ?></button>
                        </form>
                        <form method="post" style="display:inline" data-mcp-confirm="<?php esc_attr_e( 'Delete this checkpoint?', 'cowboy-mcp' ); ?>">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                            <input type="hidden" name="checkpoint_id" value="<?php echo (int) $cp['id']; ?>">
                            <button type="submit" name="cowboy_mcp_delete_checkpoint" value="1" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'cowboy-mcp' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2 style="margin-top:24px"><?php esc_html_e( 'Change Journal', 'cowboy-mcp' ); ?></h2>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'ID', 'cowboy-mcp' ); ?></th><th><?php esc_html_e( 'Time', 'cowboy-mcp' ); ?></th>
                <th><?php esc_html_e( 'Tool', 'cowboy-mcp' ); ?></th><th><?php esc_html_e( 'Object', 'cowboy-mcp' ); ?></th>
                <th><?php esc_html_e( 'Action', 'cowboy-mcp' ); ?></th><th><?php esc_html_e( 'Key', 'cowboy-mcp' ); ?></th>
                <th><?php esc_html_e( 'Status', 'cowboy-mcp' ); ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $result['entries'] ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No journaled changes yet.', 'cowboy-mcp' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $result['entries'] as $e ) : ?>
                <tr>
                    <td>#<?php echo (int) $e['id']; ?></td>
                    <td><?php echo esc_html( $e['timestamp'] ); ?></td>
                    <td><code><?php echo esc_html( $e['tool'] ); ?></code></td>
                    <td title="<?php echo esc_attr( $e['object_type'] . ' ' . $e['object_id'] ); ?>"><?php echo esc_html( $e['object_label'] ?: $e['object_id'] ); ?></td>
                    <td><?php echo esc_html( $e['action'] ); ?></td>
                    <td><?php echo esc_html( $e['key_label'] ?: $e['key_id'] ); ?></td>
                    <td>
                        <span class="mcp-badge mcp-badge-<?php echo esc_attr( $e['status'] ); ?>"><?php echo esc_html( str_replace( '_', ' ', $e['status'] ) ); ?></span>
                        <?php if ( $e['status'] === 'not_undoable' && $e['not_undoable_reason'] ) : ?>
                            <span class="dashicons dashicons-info-outline" title="<?php echo esc_attr( $e['not_undoable_reason'] ); ?>"></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $e['status'] === 'active' ) : ?>
                            <form method="post" style="display:inline" data-mcp-confirm="<?php
                                /* translators: %s: change ID number */
                                echo esc_attr( sprintf( __( 'Undo change #%s?', 'cowboy-mcp' ), $e['id'] ) );
                            ?>">
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                                <input type="hidden" name="change_id" value="<?php echo (int) $e['id']; ?>">
                                <button type="submit" name="cowboy_mcp_undo_change" value="1" class="button button-small"><?php esc_html_e( 'Undo', 'cowboy-mcp' ); ?></button>
                            </form>
                            <?php if ( $e['batch_id'] ) : ?>
                                <form method="post" style="display:inline" data-mcp-confirm="<?php esc_attr_e( 'Undo ALL active changes in this batch (newest first)?', 'cowboy-mcp' ); ?>">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                                    <input type="hidden" name="batch_id" value="<?php echo esc_attr( $e['batch_id'] ); ?>">
                                    <button type="submit" name="cowboy_mcp_undo_batch" value="1" class="button button-small"><?php esc_html_e( 'Undo batch', 'cowboy-mcp' ); ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $pages = (int) ceil( $result['total'] / $per_page );
        if ( $pages > 1 ) {
            echo '<p class="mcp-pagination">';
            for ( $i = 1; $i <= $pages; $i++ ) {
                $url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'activity', 'activity_page' => $i ], admin_url( 'options-general.php' ) );
                printf( '<a class="button button-small%s" href="%s">%d</a> ', $i === $page ? ' button-primary' : '', esc_url( $url ), (int) $i );
            }
            echo '</p>';
        }
    }

}
