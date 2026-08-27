<?php
/**
 * Feedback prompt.
 *
 * After the plugin has done real work on the site (≥ MIN_CALLS completed
 * tool calls, the first ≥ MIN_AGE_DAYS ago) ask the admin once how it is
 * going and route the answer: 👍 → the WordPress.org review form,
 * 👎 → a new WordPress.org support topic. Dismissible; snoozes for
 * SNOOZE_DAYS; retires after MAX_ASKS snoozes. No data leaves the site —
 * the only thing recorded is which route, if any, was taken.
 *
 * @package Cowboy_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cowboy_MCP_Feedback {

    const OPTION         = 'cowboy_mcp_feedback';          // hard-protected by prefix: agents cannot reset it via the API
    const GATE_TRANSIENT = 'cowboy_mcp_feedback_gate';     // 12 h negative cache for usage_met()
    const MIN_CALLS      = 25;
    const MIN_AGE_DAYS   = 3;
    const SNOOZE_DAYS    = 30;
    const MAX_ASKS       = 3;
    const REVIEW_URL     = 'https://wordpress.org/support/plugin/cowboy-mcp/reviews/#new-post';
    const SUPPORT_URL    = 'https://wordpress.org/support/plugin/cowboy-mcp/#new-post';
    const SCREENS        = [ 'dashboard', 'plugins', 'settings_page_cowboy-mcp' ];
    const DECISIONS      = [ 'later', 'review', 'support', 'already' ];

    public static function init(): void {
        add_action( 'wp_ajax_cowboy_mcp_feedback', [ __CLASS__, 'ajax_decide' ] );
    }

    /* ── State ─────────────────────────────────────────────── */

    /**
     * Site-wide prompt state, merged over defaults.
     *
     * @return array{status: string, shown: int, next_at: int, outcome: string|null, updated: int}
     */
    public static function state(): array {
        $stored = get_option( self::OPTION, [] );
        return array_merge(
            [
                'status'  => 'pending',   // pending | snoozed | done
                'shown'   => 0,           // snoozes recorded so far
                'next_at' => 0,           // unix; earliest re-ask while snoozed
                'outcome' => null,        // review | support | already | expired
                'updated' => 0,
            ],
            is_array( $stored ) ? $stored : []
        );
    }

    private static function save_state( array $state ): void {
        $state['updated'] = time();
        update_option( self::OPTION, $state );
    }

    /**
     * Apply one decision (spec §7). Unknown decisions are rejected.
     */
    public static function decide( string $decision ): bool {
        if ( ! in_array( $decision, self::DECISIONS, true ) ) {
            return false;
        }
        $state = self::state();
        if ( 'later' === $decision ) {
            $state['shown'] = (int) $state['shown'] + 1;
            if ( $state['shown'] >= self::MAX_ASKS ) {
                $state['status']  = 'done';
                $state['outcome'] = 'expired';
                $state['next_at'] = 0;
            } else {
                $state['status']  = 'snoozed';
                $state['next_at'] = time() + self::SNOOZE_DAYS * DAY_IN_SECONDS;
            }
        } else {
            $state['status']  = 'done';
            $state['outcome'] = $decision;
            $state['next_at'] = 0;
        }
        self::save_state( $state );
        return true;
    }

    /* ── Gate ──────────────────────────────────────────────── */

    /**
     * Site-level half of the gate: credentials, state, usage. No screen or
     * capability checks, so it is testable from WP-CLI.
     */
    public static function should_ask(): bool {
        if ( ! Cowboy_MCP_Auth::site_has_credentials() ) {
            return false;
        }
        $state = self::state();
        if ( 'done' === $state['status'] ) {
            return false;
        }
        if ( 'snoozed' === $state['status'] && time() < (int) $state['next_at'] ) {
            return false;
        }
        return self::usage_met();
    }

    /**
     * Full gate for the current admin request: capability + screen + should_ask().
     */
    public static function is_due(): bool {
        if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
            return false;
        }
        return self::should_ask();
    }

    /**
     * ≥ MIN_CALLS completed tool calls (tool_call rows minus tool_error rows,
     * gateway meta-tools excluded) whose earliest row is ≥ MIN_AGE_DAYS old.
     * A negative result is cached for 12 hours so admin loads stay cheap.
     */
    public static function usage_met(): bool {
        if ( get_transient( self::GATE_TRANSIENT ) ) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cowboy_mcp_audit_log';

        // One indexed aggregate over the plugin's own audit table; identifiers
        // and literals are fixed by the plugin, the only variable is an int.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT SUM( event = 'tool_call' ) - SUM( event = 'tool_error' ) AS completed, MIN( timestamp ) <= ( NOW() - INTERVAL %d DAY ) AS old_enough FROM %i WHERE event IN ( 'tool_call', 'tool_error' ) AND tool NOT IN ( 'cowboy_run', 'cowboy_discover' )",
                self::MIN_AGE_DAYS,
                $table
            ),
            ARRAY_A
        );
        // phpcs:enable

        $met = (int) ( $row['completed'] ?? 0 ) >= self::MIN_CALLS && ! empty( $row['old_enough'] );
        if ( ! $met ) {
            set_transient( self::GATE_TRANSIENT, 1, 12 * HOUR_IN_SECONDS );
        }
        return $met;
    }

    public static function doctor_url(): string {
        return admin_url( 'options-general.php?page=cowboy-mcp&tab=connection#cowboy-doctor' );
    }

    /* ── AJAX ──────────────────────────────────────────────── */

    public static function ajax_decide(): void {
        check_ajax_referer( 'cowboy_mcp_feedback' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( null, 403 );
        }
        $decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
        if ( ! self::decide( $decision ) ) {
            wp_send_json_error( null, 400 );
        }
        wp_send_json_success();
    }
}
