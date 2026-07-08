<?php
/**
 * Plugin Name: Cowboy MCP
 * Plugin URI:  https://cowboymcp.com
 * Description: Exposes your WordPress site as a Model Context Protocol (MCP) server so AI coding agents like Claude Code can read, edit, and manage everything on the site.
 * Version:     1.4.1
 * Author:      februality
 * Author URI:  https://profiles.wordpress.org/februality/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cowboy-mcp
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

define( 'COWBOY_MCP_VERSION', '1.4.1' );
define( 'COWBOY_MCP_PATH',    plugin_dir_path( __FILE__ ) );
define( 'COWBOY_MCP_URL',     plugin_dir_url( __FILE__ ) );

/* ── Autoload ─────────────────────────────────────────────── */
require_once COWBOY_MCP_PATH . 'includes/class-mcp-security.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-compat.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-audit-log.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-rollback.php';
require_once COWBOY_MCP_PATH . 'includes/class-mcp-checkpoint.php';
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
    Cowboy_MCP_Auth::init();
    Cowboy_MCP_Transport::init();
    Cowboy_MCP_Admin::init();
    Cowboy_MCP_OAuth::init();
});

/* ── Cron resilience: re-schedule if cron event was lost ── */
add_action( 'init', function () {
    if ( class_exists( 'Cowboy_MCP_Audit_Log' ) && ! wp_next_scheduled( Cowboy_MCP_Audit_Log::CRON_HOOK ) ) {
        wp_schedule_event( time(), 'daily', Cowboy_MCP_Audit_Log::CRON_HOOK );
    }
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
        ]);
    }

    Cowboy_MCP_Audit_Log::create_table();
    Cowboy_MCP_Rollback::create_table();
    Cowboy_MCP_Checkpoint::create_table();

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

    // Remove checkpoint files.
    $cp_dir = ( wp_upload_dir()['basedir'] ?? '' ) . '/cowboy-mcp/checkpoints';
    if ( is_dir( $cp_dir ) ) {
        foreach ( array_merge( glob( $cp_dir . '/*' ) ?: [], [ $cp_dir . '/.htaccess', $cp_dir . '/index.php' ] ) as $f ) {
            if ( is_file( $f ) ) {
                wp_delete_file( $f );
            }
        }
        @rmdir( $cp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @rmdir( dirname( $cp_dir ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

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
