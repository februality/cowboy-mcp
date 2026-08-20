<?php
/**
 * Plugin Name: Cowboy MCP
 * Plugin URI:  https://cowboymcp.com
 * Description: Exposes your WordPress site as a Model Context Protocol (MCP) server so AI coding agents like Claude Code can read, edit, and manage everything on the site.
 * Version:     1.6.2
 * Author:      februality
 * Author URI:  https://profiles.wordpress.org/februality/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cowboy-mcp
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 6.2
 *
 * ── Quick Start ──────────────────────────────────────────────
 *  1. Activate the plugin.
 *  2. Go to Settings → Cowboy MCP and generate an API key.
 *  3. In your terminal:
 *     claude mcp add --transport http wordpress \
 *       https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint \
 *       --header "Authorization: Bearer YOUR_API_KEY"
 *  4. Ask Claude Code to manage your site!
 * ─────────────────────────────────────────────────────────────
 */

/*
Cowboy MCP is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Cowboy MCP is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Cowboy MCP. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

defined( 'ABSPATH' ) || exit;

define( 'COWBOY_MCP_VERSION', '1.6.2' );
define( 'COWBOY_MCP_PATH',    plugin_dir_path( __FILE__ ) );
define( 'COWBOY_MCP_URL',     plugin_dir_url( __FILE__ ) );

/* ── Autoload ─────────────────────────────────────────────── */
require_once COWBOY_MCP_PATH . 'includes/class-mcp-security.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-compat.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-audit-log.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-rollback.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-checkpoint.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-installer.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-doctor.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-auth.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-transport.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-tools.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-resources.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-prompts.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-completion.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-oauth.php';
require_once COWBOY_MCP_PATH . 'admin/class-mcp-admin.php';

/* ── Boot ─────────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    Cowboy_MCP_Audit_Log::init();
    Cowboy_MCP_Rollback::init();
    Cowboy_MCP_Checkpoint::init();
    Cowboy_MCP_Doctor::init();
    Cowboy_MCP_Auth::init();
    Cowboy_MCP_Transport::init();
    Cowboy_MCP_Admin::init();
    Cowboy_MCP_OAuth::init();

    // Activation hooks don't re-run on plugin updates: create any missing
    // tables once per version bump so upgraded installs get the undo journal
    // and checkpoint tables without reactivation. All create_table() calls
    // are idempotent (CREATE TABLE IF NOT EXISTS).
    if ( get_option( 'cowboy_mcp_db_version' ) !== COWBOY_MCP_VERSION ) {
        Cowboy_MCP_Audit_Log::create_table();
        Cowboy_MCP_Rollback::create_table();
        Cowboy_MCP_Checkpoint::create_table();
        global $wpdb;
        $journal = $wpdb->prefix . 'cowboy_mcp_undo_journal';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $journal ) ) === $journal ) {
            update_option( 'cowboy_mcp_db_version', COWBOY_MCP_VERSION, false );
        }
    }
});

/* ── Cron resilience: re-schedule if cron event was lost ── */
add_action( 'init', function () {
    if ( class_exists( 'Cowboy_MCP_Audit_Log' ) && ! wp_next_scheduled( Cowboy_MCP_Audit_Log::CRON_HOOK ) ) {
        wp_schedule_event( time(), 'daily', Cowboy_MCP_Audit_Log::CRON_HOOK );
    }
});

/* ── Translations ─────────────────────────────────────────── */
// REQUIRED — do not remove. Plugin Check warns that load_plugin_textdomain()
// is "discouraged since 4.6", but that advice assumes translations arrive as
// language packs from translate.wordpress.org. This plugin ships its OWN 12
// catalogs in /languages, and WP_Textdomain_Registry::get_paths_for_domain()
// only ever searches WP_LANG_DIR/plugins, WP_LANG_DIR/themes, and custom_paths
// — and custom_paths is populated *solely* by this call. Drop it and the
// registry returns false for the bundled path, so all 12 locales silently fall
// back to English (verified against WP 7.0; no packs exist for this slug yet).
// Community packs, once they exist, still take precedence over the bundle.
// Hooked on `init` (not earlier) to avoid the WP 6.7 pre-init _doing_it_wrong.
add_action( 'init', static function () {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Load-bearing: bundled /languages catalogs are unreachable without it (see above).
    load_plugin_textdomain( 'cowboy-mcp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});

/* ── Activation ───────────────────────────────────────────── */
register_activation_hook( __FILE__, function () {
    if ( ! get_option( 'cowboy_mcp_api_keys' ) ) {
        update_option( 'cowboy_mcp_api_keys', [] );
    }
    if ( ! get_option( 'cowboy_mcp_settings' ) ) {
        update_option( 'cowboy_mcp_settings', [
            'enabled'          => true,
            'safe_mode'        => true,     // require confirmation for destructive ops
            'allowed_tools'    => 'all',
            'log_requests'     => false,
            'rate_limit'       => 120,      // requests per minute
            'oauth_enabled'    => false,    // OAuth desktop connector OFF by default
            'undo_enabled'           => true,
            'undo_retention_days'    => 7,
            'checkpoint_max'         => 5,
            'auto_checkpoint_wp_cli' => true,
            'auto_checkpoint_updates' => true,
        ]);
    }

    Cowboy_MCP_Audit_Log::create_table();
    Cowboy_MCP_Rollback::create_table();
    Cowboy_MCP_Checkpoint::create_table();
    update_option( 'cowboy_mcp_db_version', COWBOY_MCP_VERSION, false );

    if ( ! wp_next_scheduled( Cowboy_MCP_Audit_Log::CRON_HOOK ) ) {
        wp_schedule_event( time(), 'daily', Cowboy_MCP_Audit_Log::CRON_HOOK );
    }

    // Flag a one-time redirect to the connection page on next admin load.
    set_transient( 'cowboy_mcp_activation_redirect', 1, 60 );

    flush_rewrite_rules();
});

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( Cowboy_MCP_Audit_Log::CRON_HOOK );
    cowboy_mcp_cleanup_transients( false );
    flush_rewrite_rules();
});

/* ── Uninstall ───────────────────────────────────────────── */
register_uninstall_hook( __FILE__, 'cowboy_mcp_uninstall' );

function cowboy_mcp_uninstall(): void {
    // Remove plugin options.
    delete_option( 'cowboy_mcp_api_keys' );
    delete_option( 'cowboy_mcp_settings' );
    delete_option( 'cowboy_mcp_oauth_tokens' );
    delete_option( 'cowboy_mcp_oauth_refresh' );
    delete_option( 'cowboy_mcp_oauth_clients' );
    delete_option( 'cowboy_mcp_db_version' );

    // Remove per-user admin preferences (remembered connection method).
    delete_metadata( 'user', 0, 'cowboy_mcp_conn_method', '', true );
    delete_metadata( 'user', 0, 'cowboy_mcp_conn_client', '', true );

    // Drop audit log table.
    global $wpdb;
    $table = $wpdb->prefix . 'cowboy_mcp_audit_log';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table ) );

    $journal = $wpdb->prefix . 'cowboy_mcp_undo_journal';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $journal ) );

    $checkpoints = $wpdb->prefix . 'cowboy_mcp_checkpoints';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $checkpoints ) );

    // Remove checkpoint, package-backup, and trashed-media files.
    $upload_base = wp_upload_dir()['basedir'] ?? '';
    $cowboy_root = $upload_base . '/cowboy-mcp';

    // Depth-first: the media trash nests uploads-relative paths under
    // trash/<uniqid>/YYYY/MM/, so a single-level file sweep would orphan them.
    $cowboy_mcp_rmtree = static function ( $dir ) use ( &$cowboy_mcp_rmtree ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( glob( $dir . '/*' ) ?: [] as $item ) {
            if ( is_dir( $item ) ) {
                $cowboy_mcp_rmtree( $item );
            } elseif ( is_file( $item ) ) {
                wp_delete_file( $item );
            }
        }
        foreach ( [ $dir . '/.htaccess', $dir . '/index.php' ] as $hidden ) {
            if ( is_file( $hidden ) ) {
                wp_delete_file( $hidden );
            }
        }
        @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    };

    foreach ( [ '/checkpoints', '/backups', '/trash' ] as $sub ) {
        $cowboy_mcp_rmtree( $cowboy_root . $sub );
    }
    $cowboy_mcp_rmtree( $cowboy_root );

    cowboy_mcp_cleanup_transients( true );
}

/**
 * Delete MCP transients from the options table.
 *
 * @param bool $include_new_key Whether to also purge cowboy_mcp_new_key_ transients (uninstall only).
 */
function cowboy_mcp_cleanup_transients( bool $include_new_key ): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->options, '_transient_cowboy_mcp_rl_%', '_transient_timeout_cowboy_mcp_rl_%' ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->options, '_transient_cowboy_mcp_sess_%', '_transient_timeout_cowboy_mcp_sess_%' ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->options, '_transient_cowboy_mcp_oauth_code_%', '_transient_timeout_cowboy_mcp_oauth_code_%' ) );
    if ( $include_new_key ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->options, '_transient_cowboy_mcp_new_key_%', '_transient_timeout_cowboy_mcp_new_key_%' ) );
    }
}
