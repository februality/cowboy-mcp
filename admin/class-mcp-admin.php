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
        // The two notices are mutually exclusive (setup = no credentials,
        // feedback = credentials + usage) but share one CSS/JS pair.
        if ( self::setup_notice_due() || Cowboy_MCP_Feedback::is_due() || Cowboy_MCP_Repair_Notice::is_due() ) {
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
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'cowboy_mcp_dismiss_setup_notice' ),
                'feedbackNonce' => wp_create_nonce( 'cowboy_mcp_feedback' ),
                'repairNonce'   => wp_create_nonce( 'cowboy_mcp_dismiss_repair' ),
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
            'gateOff'      => __( 'New connections: disabled', 'cowboy-mcp' ),
            'gateEnable'   => __( 'Enable for 30 minutes', 'cowboy-mcp' ),
            'cancel'       => __( 'Cancel', 'cowboy-mcp' ),
            'confirm'      => __( 'Confirm', 'cowboy-mcp' ),
            'copied'       => __( 'Copied!', 'cowboy-mcp' ),
            'copyFailed'   => __( 'Failed to copy', 'cowboy-mcp' ),
            'scopeMissing' => __( 'Not currently available (kept in this scope until you untick them)', 'cowboy-mcp' ),
        ] );
        wp_localize_script( 'cowboy-mcp-admin', 'cowboyMcpDoctor', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cowboy_mcp_doctor' ),
            // On-screen text only; the copied report stays English for support threads.
            'i18n'    => [
                'running'      => __( 'Running checks…', 'cowboy-mcp' ),
                /* translators: %s: error message */
                'failed'       => __( 'Doctor failed to run: %s', 'cowboy-mcp' ),
                'serverChecks' => __( 'Server-side checks', 'cowboy-mcp' ),
                'browserChecks' => __( 'From your browser (outside the server)', 'cowboy-mcp' ),
                /* translators: %s: how to fix a failed check */
                'fix'          => __( 'Fix: %s', 'cowboy-mcp' ),
                'status'       => [
                    'pass'  => _x( 'pass', 'connection check result', 'cowboy-mcp' ),
                    'warn'  => _x( 'warning', 'connection check result', 'cowboy-mcp' ),
                    'fail'  => _x( 'fail', 'connection check result', 'cowboy-mcp' ),
                    'error' => _x( 'error', 'connection check result', 'cowboy-mcp' ),
                    'skip'  => _x( 'skipped', 'connection check result', 'cowboy-mcp' ),
                ],
                'summary'      => [
                    /* translators: %d: number of checks */
                    'fail'  => __( 'Failed: %d', 'cowboy-mcp' ),
                    /* translators: %d: number of checks */
                    'error' => __( 'Errors: %d', 'cowboy-mcp' ),
                    /* translators: %d: number of checks */
                    'warn'  => __( 'Warnings: %d', 'cowboy-mcp' ),
                    /* translators: %d: number of checks */
                    'pass'  => __( 'Passed: %d', 'cowboy-mcp' ),
                    /* translators: %d: number of checks */
                    'skip'  => __( 'Skipped: %d', 'cowboy-mcp' ),
                ],
                'probeGet'     => __( 'GET MCP endpoint', 'cowboy-mcp' ),
                'probePost'    => __( 'POST MCP endpoint', 'cowboy-mcp' ),
                /* translators: %s: discovery URL path, e.g. /oauth-authorization-server */
                'probeOauth'   => __( 'OAuth discovery %s', 'cowboy-mcp' ),
                /* translators: %s: HTTP status code */
                'probeHttp'    => __( 'HTTP %s', 'cowboy-mcp' ),
                /* translators: %s: HTTP status code */
                'probeNonJson' => __( 'HTTP %s, non-JSON body', 'cowboy-mcp' ),
                /* translators: %s: browser error message */
                'probeNetwork' => __( 'Network error: %s', 'cowboy-mcp' ),
                'probeEdgeFix' => __( 'See the server-side result for this URL; if that passed, the block is at your network edge (CDN/WAF).', 'cowboy-mcp' ),
                'probeNetFix'  => __( 'Your browser could not reach the site at all (DNS, TLS, or connection refused). Remote AI clients will hit the same wall.', 'cowboy-mcp' ),
            ],
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
        if ( array_key_exists( $client, Cowboy_MCP_Admin_Connections::client_registry() ) ) {
            update_user_meta( get_current_user_id(), 'cowboy_mcp_conn_client', $client );
            // Legacy pre-sidebar preference — no longer read; clean it up.
            delete_user_meta( get_current_user_id(), 'cowboy_mcp_conn_method' );
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
        if ( isset( $_POST['cowboy_mcp_toggle_connections'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_toggle_connections' ) ) {
            if ( 'enable' === sanitize_text_field( wp_unslash( $_POST['cowboy_mcp_toggle_connections'] ) ) ) {
                Cowboy_MCP_OAuth::open_registration_window();
            } else {
                Cowboy_MCP_OAuth::close_registration_window();
            }
        }

        // "Add to Claude": open the registration window, then hand off (new tab) to
        // Claude's prefilled Add-custom-connector dialog. Only offered on public HTTPS sites.
        if ( isset( $_POST['cowboy_mcp_add_to_claude'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cowboy_mcp_add_to_claude' ) && class_exists( 'Cowboy_MCP_OAuth' ) && ! empty( get_option( 'cowboy_mcp_settings', [] )['oauth_enabled'] ) ) {
            $org = 'org' === sanitize_text_field( wp_unslash( $_POST['cowboy_mcp_add_to_claude'] ) );
            Cowboy_MCP_OAuth::open_registration_window();
            add_filter( 'allowed_redirect_hosts', static fn( $hosts ) => array_merge( (array) $hosts, [ 'claude.ai' ] ) );
            wp_safe_redirect( Cowboy_MCP_Admin_Connections::claude_install_link( $org ) );
            exit;
        }

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
                'oauth_redirect_allowlist'   => ! empty( $_POST['cowboy_mcp_oauth_redirect_allowlist'] ),
                'oauth_extra_redirect_hosts' => Cowboy_MCP_OAuth::sanitize_host_list( preg_split( '/[\s,]+/', sanitize_textarea_field( wp_unslash( $_POST['cowboy_mcp_oauth_extra_redirect_hosts'] ?? '' ) ) ) ?: [] ),

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
                if ( 'content' === ( $r['verification']['method'] ?? '' ) ) {
                    /* translators: 1: restored checkpoint ID, 2: number of tables, 3: number of rows (formatted), 4: pre-restore safety checkpoint ID */
                    $msg = __( 'Database restored from checkpoint #%1$d — %2$d tables, %3$s rows, verified. Pre-restore safety checkpoint: #%4$d.', 'cowboy-mcp' );
                } else {
                    /* translators: 1: restored checkpoint ID, 2: number of tables, 3: number of rows (formatted), 4: pre-restore safety checkpoint ID */
                    $msg = __( 'Database restored from checkpoint #%1$d — %2$d tables, %3$s rows checked. Pre-restore safety checkpoint: #%4$d.', 'cowboy-mcp' );
                }
                add_settings_error( 'cowboy_mcp', 'cp_restored', esc_html( sprintf( $msg, (int) $r['checkpoint_id'], (int) $r['verification']['tables'], number_format_i18n( (int) $r['verification']['rows'] ), (int) $r['pre_restore_checkpoint_id'] ) ), 'success' );
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
            Cowboy_MCP_OAuth::open_registration_window();
            add_settings_error( 'cowboy_mcp', 'oauth_on', $notice, 'success' );
        }
    }

    /* ── Page renderer ────────────────────────────────────── */

    /** Resolve the requested tab (with the legacy `audit-log` alias). */
    private static function active_tab(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing.
        $raw = sanitize_key( wp_unslash( $_GET['tab'] ?? 'connection' ) );
        $map = [ 'connection' => 'connection', 'activity' => 'activity', 'logs' => 'logs', 'audit-log' => 'logs', 'settings' => 'settings', 'about' => 'about' ];
        return $map[ $raw ] ?? 'connection';
    }

    /** options-general.php?page=cowboy-mcp URL; empty values are dropped. */
    public static function url( array $args = [] ): string {
        $args = array_filter( array_merge( [ 'page' => self::SLUG ], $args ), static fn( $v ) => '' !== (string) $v );
        return add_query_arg( $args, admin_url( 'options-general.php' ) );
    }

    public static function render_page(): void {
        $settings   = get_option( 'cowboy_mcp_settings', [] );
        $active_tab = self::active_tab();
        $tabs       = [
            'connection' => __( 'Connections', 'cowboy-mcp' ),
            'activity'   => __( 'Activity', 'cowboy-mcp' ),
            'logs'       => __( 'Logs', 'cowboy-mcp' ),
            'settings'   => __( 'Settings', 'cowboy-mcp' ),
            'about'      => __( 'About', 'cowboy-mcp' ),
        ];
        ?>
        <div class="wrap cmcp">
            <header class="cmcp-head">
                <div class="cmcp-brand">
                    <img class="cmcp-logo" src="<?php echo esc_url( COWBOY_MCP_URL . 'admin/images/icon-128.png' ); ?>" alt="" width="32" height="32">
                    <span class="cmcp-name"><?php esc_html_e( 'Cowboy MCP', 'cowboy-mcp' ); ?></span>
                    <span class="cmcp-ver">v<?php echo esc_html( COWBOY_MCP_VERSION ); ?></span>
                    <?php self::render_exceptions( $settings ); ?>
                </div>
                <nav class="cmcp-tabs" aria-label="<?php esc_attr_e( 'Cowboy MCP sections', 'cowboy-mcp' ); ?>">
                    <?php foreach ( $tabs as $slug => $label ) : ?>
                        <a class="cmcp-tab" href="<?php echo esc_url( self::url( [ 'tab' => $slug ] ) ); ?>"<?php echo $slug === $active_tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                </nav>
            </header>
            <?php self::render_offbar( $settings ); ?>
            <h1 class="screen-reader-text"><?php echo esc_html( $tabs[ $active_tab ] ); ?></h1>
            <div class="cmcp-body">
                <?php
                $endpoint = rest_url( 'cowboy-mcp/v1/endpoint' );
                $new_key  = get_transient( 'cowboy_mcp_new_key_' . get_current_user_id() );
                match ( $active_tab ) {
                    'settings' => Cowboy_MCP_Admin_Settings::render_tab( $settings ),
                    'activity' => Cowboy_MCP_Admin_Activity::render_tab(),
                    'logs'     => Cowboy_MCP_Admin_Logs::render_tab(),
                    'about'    => self::render_about_tab(),
                    default    => Cowboy_MCP_Admin_Connections::render_tab( $endpoint, $new_key ),
                };
                ?>
            </div>
        </div>
        <?php
    }

    /** Header status: only states that differ from the safe defaults. */
    private static function render_exceptions( array $s ): void {
        $chips = [];
        if ( ! empty( $s['power_mode'] ) ) {
            $chips[] = [ 'red', '#cmcp-power', __( 'Power mode on', 'cowboy-mcp' ) ];
        }
        if ( ! ( $s['safe_mode'] ?? true ) ) {
            $chips[] = [ 'amber', '#cmcp-safe-mode', __( 'Safe mode off', 'cowboy-mcp' ) ];
        }
        if ( ! $chips ) {
            return;
        }
        echo '<div class="cmcp-exceptions">';
        foreach ( $chips as [ $tone, $anchor, $label ] ) {
            printf(
                '<a class="cmcp-xchip cmcp-xchip--%1$s" href="%2$s">%3$s%4$s</a>',
                esc_attr( $tone ),
                esc_url( self::url( [ 'tab' => 'settings' ] ) . $anchor ),
                self::icon( 'warning' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal.
                esc_html( $label )
            );
        }
        echo '</div>';
    }

    /** Full-width bar while the MCP server is switched off. */
    private static function render_offbar( array $s ): void {
        if ( $s['enabled'] ?? true ) {
            return;
        }
        ?>
        <div class="cmcp-offbar" role="status">
            <span><strong><?php esc_html_e( 'MCP server is off.', 'cowboy-mcp' ); ?></strong> <?php esc_html_e( 'Every request from AI apps is rejected.', 'cowboy-mcp' ); ?></span>
            <a class="cmcp-btn cmcp-btn--sm" href="<?php echo esc_url( self::url( [ 'tab' => 'settings' ] ) . '#cmcp-enabled' ); ?>"><?php esc_html_e( 'Turn on in Settings', 'cowboy-mcp' ); ?></a>
        </div>
        <?php
    }

    /** Static, trusted SVG icons (stroke = currentColor). */
    public static function icon( string $name ): string {
        $p = [
            'kebab'    => '<circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none"></circle><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"></circle><circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none"></circle>',
            'search'   => '<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path>',
            'warning'  => '<path d="M12 3l9.5 17h-19z"></path><path d="M12 10v4M12 17.5v.5"></path>',
            'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"></path>',
            'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2"></rect><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"></path>',
            'caret'    => '<path d="M9 6l6 6-6 6"></path>',
            'plus'     => '<path d="M12 5v14M5 12h14"></path>',
        ];
        return isset( $p[ $name ] )
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p[ $name ] . '</svg>'
            : '';
    }

    /** Previous / "Page X of Y" / Next. */
    public static function render_pager( int $page, int $pages, callable $url_for ): void {
        if ( $pages <= 1 ) {
            return;
        }
        ?>
        <nav class="cmcp-pager" aria-label="<?php esc_attr_e( 'Pagination', 'cowboy-mcp' ); ?>">
            <a class="cmcp-btn cmcp-btn--sm" href="<?php echo esc_url( $url_for( max( 1, $page - 1 ) ) ); ?>"<?php echo $page <= 1 ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><?php esc_html_e( 'Previous', 'cowboy-mcp' ); ?></a>
            <span><?php
                /* translators: 1: current page number, 2: total number of pages */
                printf( esc_html__( 'Page %1$d of %2$d', 'cowboy-mcp' ), (int) $page, (int) $pages );
            ?></span>
            <a class="cmcp-btn cmcp-btn--sm" href="<?php echo esc_url( $url_for( min( $pages, $page + 1 ) ) ); ?>"<?php echo $page >= $pages ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><?php esc_html_e( 'Next', 'cowboy-mcp' ); ?></a>
        </nav>
        <?php
    }

    /* ── About tab ────────────────────────────────────────── */

    private static function render_about_tab(): void {
        $links = [
            [ 'https://cowboymcp.com', __( 'Website', 'cowboy-mcp' ), __( 'Project home, guides, and news.', 'cowboy-mcp' ) ],
            [ 'https://github.com/februality/cowboy-mcp', __( 'GitHub', 'cowboy-mcp' ), __( 'Source, issues & releases.', 'cowboy-mcp' ) ],
            [ 'https://wordpress.org/support/plugin/cowboy-mcp/', __( 'Get help', 'cowboy-mcp' ), __( 'Ask in the WordPress.org support forum. Paste your Connection Doctor report for a fast answer.', 'cowboy-mcp' ) ],
            [ 'https://wordpress.org/support/plugin/cowboy-mcp/reviews/#new-post', __( 'Leave a review', 'cowboy-mcp' ), __( 'Enjoying Cowboy MCP? A review on WordPress.org helps other site owners find it.', 'cowboy-mcp' ) ],
        ];
        ?>
        <div class="cmcp-about">
            <p class="cmcp-about-lead"><?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: Model Context Protocol website URL. */
                        __( '<strong>Cowboy MCP</strong> turns this WordPress site into a <a href="%s" target="_blank" rel="noopener noreferrer">Model Context Protocol</a> server, so AI coding agents like Claude Code, Codex, and Opencode can read, edit, and manage the whole site through a single authenticated endpoint.', 'cowboy-mcp' ),
                        esc_url( 'https://modelcontextprotocol.io/' )
                    ),
                    [ 'strong' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
                );
            ?></p>
            <div class="cmcp-links">
                <?php foreach ( $links as [ $href, $title, $sub ] ) : ?>
                    <a class="cmcp-card cmcp-lcard" href="<?php echo esc_url( $href ); ?>" target="_blank" rel="noopener noreferrer">
                        <b><?php echo esc_html( $title ); ?> <?php echo self::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal. ?></b>
                        <p><?php echo esc_html( $sub ); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }





































}
