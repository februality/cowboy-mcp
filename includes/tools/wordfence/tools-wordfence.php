<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Guard — return empty when Wordfence is not active.
 * ================================================================ */

if ( ! class_exists( 'wordfence' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Helpers
 * ================================================================ */

/**
 * Validate an IP address (v4 or v6).
 */
function cowboy_mcp_wordfence_validate_ip( string $ip ): bool {
    return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
}

/**
 * Check if the given IP matches the current request IP (self-lockout prevention).
 */
function cowboy_mcp_wordfence_is_own_ip( string $ip ): bool {
    if ( class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'getIP' ) ) {
        return wfUtils::getIP() === $ip;
    }
    return false;
}

/**
 * Standardize a block row for output.
 */
function cowboy_mcp_wordfence_format_block( object $block ): array {
    $formatted = [
        'id'        => $block->id ?? null,
        'type'      => $block->type ?? 'unknown',
        'reason'    => $block->reason ?? '',
        'permanent' => ! empty( $block->permanent ),
    ];

    // IP blocks store binary IP — convert to readable.
    if ( ! empty( $block->IP ) && class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_ntop' ) ) {
        $formatted['ip'] = wfUtils::inet_ntop( $block->IP );
    } elseif ( ! empty( $block->ip ) ) {
        $formatted['ip'] = $block->ip;
    }

    if ( ! empty( $block->blockedTime ) ) {
        $formatted['blocked_at'] = gmdate( 'Y-m-d H:i:s', (int) $block->blockedTime );
    }
    if ( ! empty( $block->expiredAt ) ) {
        $formatted['expires_at'] = gmdate( 'Y-m-d H:i:s', (int) $block->expiredAt );
    }
    if ( isset( $block->expiredAt ) && (int) $block->expiredAt === 0 ) {
        $formatted['expires_at'] = 'never';
    }

    // Pattern block fields.
    if ( ! empty( $block->ipRange ) )   $formatted['ip_range']   = $block->ipRange;
    if ( ! empty( $block->hostname ) )  $formatted['hostname']   = $block->hostname;
    if ( ! empty( $block->userAgent ) ) $formatted['user_agent'] = $block->userAgent;
    if ( ! empty( $block->referrer ) )  $formatted['referrer']   = $block->referrer;

    return $formatted;
}

/**
 * Standardize a scan issue row for output.
 */
function cowboy_mcp_wordfence_format_issue( array $issue ): array {
    return [
        'id'            => $issue['id'] ?? null,
        'time'          => ! empty( $issue['time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $issue['time'] ) : null,
        'status'        => $issue['status'] ?? 'new',
        'type'          => $issue['type'] ?? 'unknown',
        'severity'      => (int) ( $issue['severity'] ?? 0 ),
        'ignoreP'       => $issue['ignoreP'] ?? '0',
        'ignoreC'       => $issue['ignoreC'] ?? '0',
        'short_msg'     => $issue['shortMsg'] ?? '',
        'long_msg'      => $issue['longMsg'] ?? '',
        'data'          => ! empty( $issue['data'] ) ? ( is_string( $issue['data'] ) ? json_decode( $issue['data'], true ) : $issue['data'] ) : null,
    ];
}

/**
 * Standardize a traffic hit row for output.
 */
function cowboy_mcp_wordfence_format_hit( array $hit ): array {
    $ip = $hit['IP'] ?? '';
    if ( $ip !== '' && class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_ntop' ) ) {
        // IP may be binary.
        $decoded = @wfUtils::inet_ntop( $ip );
        if ( $decoded !== false ) {
            $ip = $decoded;
        }
    }

    return [
        'id'         => $hit['id'] ?? null,
        'attack_log_time' => ! empty( $hit['attackLogTime'] ) ? gmdate( 'Y-m-d H:i:s', (float) $hit['attackLogTime'] ) : null,
        'ctime'      => ! empty( $hit['ctime'] ) ? gmdate( 'Y-m-d H:i:s', (float) $hit['ctime'] ) : null,
        'ip'         => $ip,
        'status_code'=> $hit['statusCode'] ?? null,
        'is_google'  => $hit['isGoogle'] ?? 0,
        'url'        => $hit['URL'] ?? '',
        'referer'    => $hit['referer'] ?? '',
        'user_agent' => $hit['UA'] ?? '',
        'action'     => $hit['action'] ?? '',
        'action_description' => $hit['actionDescription'] ?? '',
    ];
}

/**
 * Map user-facing scan type string to wfScanner constant.
 */
function cowboy_mcp_wordfence_get_scan_type_constant( string $type ): string|WP_Error {
    if ( ! class_exists( 'wfScanner' ) ) {
        return new WP_Error( 'wordfence_error', 'wfScanner class not available.' );
    }

    return match ( $type ) {
        'quick'            => defined( 'wfScanner::SCAN_TYPE_QUICK' ) ? wfScanner::SCAN_TYPE_QUICK : 'quick',
        'limited'          => defined( 'wfScanner::SCAN_TYPE_LIMITED' ) ? wfScanner::SCAN_TYPE_LIMITED : 'limited',
        'standard'         => defined( 'wfScanner::SCAN_TYPE_STANDARD' ) ? wfScanner::SCAN_TYPE_STANDARD : 'standard',
        'high_sensitivity' => defined( 'wfScanner::SCAN_TYPE_HIGH_SENSITIVITY' ) ? wfScanner::SCAN_TYPE_HIGH_SENSITIVITY : 'high-sensitivity',
        default            => new WP_Error( 'invalid_param', "Invalid scan type: {$type}. Allowed: quick, limited, standard, high_sensitivity." ),
    };
}

/* ================================================================
 *  Settings allowlist — keys that may be read/written.
 * ================================================================ */

$cowboy_mcp_wordfence_settings_allowlist = [
    // Firewall.
    'firewallEnabled', 'wafStatus', 'learningModeGracePeriodEnabled', 'learningModeGracePeriod',
    'wafRules', 'disableWAFIPBlocking', 'whitelistedIPs', 'bannedURLs',
    // Brute force.
    'loginSecurityEnabled', 'loginSec_maxFailures', 'loginSec_maxForgotPasswd',
    'loginSec_countFailMins', 'loginSec_lockoutMins', 'loginSec_strongPasswds',
    'loginSec_breachPasswds', 'loginSec_maskLoginErrors',
    // Scanning.
    'scheduleScan', 'scanType', 'scansEnabled_core', 'scansEnabled_coreUnknown',
    'scansEnabled_malware', 'scansEnabled_fileContents', 'scansEnabled_fileContentsGSB',
    'scansEnabled_posts', 'scansEnabled_comments', 'scansEnabled_passwds',
    'scansEnabled_diskSpace', 'scansEnabled_wafStatus', 'scansEnabled_options',
    'scansEnabled_dns', 'scan_include_extra', 'scan_exclude',
    'scansEnabled_oldVersions', 'scansEnabled_suspectedFiles', 'scansEnabled_heartbleed',
    // Blocking.
    'blockFakeBots', 'neverBlockBG', 'maxGlobalRequests', 'maxGlobalRequests_action',
    'maxRequestsCrawlers', 'maxRequestsCrawlers_action', 'max404Crawlers', 'max404Crawlers_action',
    'maxRequestsHumans', 'maxRequestsHumans_action', 'max404Humans', 'max404Humans_action',
    // Notifications.
    'alertOn_update', 'alertOn_scanIssues', 'alertOn_block', 'alertOn_loginLockout',
    'alertOn_lostPasswdForm', 'alertOn_adminLogin', 'alertOn_nonAdminLogin',
    'alert_maxHourly',
    // General.
    'liveTrafficEnabled', 'liveTraf_ignorePublishers', 'autoUpdate',
];

/* Protection-affecting keys: writing these can disable the firewall or brute-force
 * protection, so they require an explicit confirm:true even when safe mode is off. */
$cowboy_mcp_wordfence_guarded = [
    'firewallEnabled', 'wafStatus', 'disableWAFIPBlocking', 'loginSecurityEnabled',
    'loginSec_maxFailures', 'loginSec_maxForgotPasswd', 'loginSec_countFailMins', 'loginSec_lockoutMins',
];

/* ================================================================
 *  Settings category map — for grouped reads.
 * ================================================================ */

$cowboy_mcp_wordfence_settings_categories = [
    'firewall' => [
        'firewallEnabled', 'wafStatus', 'learningModeGracePeriodEnabled', 'learningModeGracePeriod',
        'disableWAFIPBlocking', 'whitelistedIPs', 'bannedURLs',
    ],
    'scanning' => [
        'scheduleScan', 'scanType', 'scansEnabled_core', 'scansEnabled_coreUnknown',
        'scansEnabled_malware', 'scansEnabled_fileContents', 'scansEnabled_fileContentsGSB',
        'scansEnabled_posts', 'scansEnabled_comments', 'scansEnabled_passwds',
        'scansEnabled_diskSpace', 'scansEnabled_wafStatus', 'scansEnabled_options',
        'scansEnabled_dns', 'scan_include_extra', 'scan_exclude',
        'scansEnabled_oldVersions', 'scansEnabled_suspectedFiles', 'scansEnabled_heartbleed',
    ],
    'blocking' => [
        'blockFakeBots', 'neverBlockBG', 'maxGlobalRequests', 'maxGlobalRequests_action',
        'maxRequestsCrawlers', 'maxRequestsCrawlers_action', 'max404Crawlers', 'max404Crawlers_action',
        'maxRequestsHumans', 'maxRequestsHumans_action', 'max404Humans', 'max404Humans_action',
    ],
    'login' => [
        'loginSecurityEnabled', 'loginSec_maxFailures', 'loginSec_maxForgotPasswd',
        'loginSec_countFailMins', 'loginSec_lockoutMins', 'loginSec_strongPasswds',
        'loginSec_breachPasswds', 'loginSec_maskLoginErrors',
    ],
    'notifications' => [
        'alertOn_update', 'alertOn_scanIssues', 'alertOn_block', 'alertOn_loginLockout',
        'alertOn_lostPasswdForm', 'alertOn_adminLogin', 'alertOn_nonAdminLogin',
        'alert_maxHourly',
    ],
];

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [

        /* ── Scanning ──────────────────────────────────────────── */

        Cowboy_MCP_Tools::tool( 'wp_wordfence_start_scan', '[Security] Trigger an asynchronous Wordfence security scan. Poll wp_wordfence_get_scan_status for progress.', [
            'type' => [ 'type' => 'string', 'description' => 'Scan type', 'enum' => [ 'quick', 'limited', 'standard', 'high_sensitivity' ], 'default' => 'standard' ],
        ], [
            'title'           => 'Start Wordfence Scan',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_get_scan_status', '[Security] Get current Wordfence scan status: running state, progress, and last scan results.', [], [
            'title'           => 'Get Scan Status',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_list_scan_issues', '[Security] List Wordfence scan findings. Filter by status (new/ignoreP/ignoreC), type, or severity.', [
            'status'   => [ 'type' => 'string', 'description' => 'Filter by status: new, ignoreP, ignoreC', 'enum' => [ 'new', 'ignoreP', 'ignoreC' ] ],
            'type'     => [ 'type' => 'string', 'description' => 'Filter by issue type (e.g., file, knownfile, database, post)' ],
            'severity' => [ 'type' => 'integer', 'description' => 'Minimum severity level (1-10)' ],
            'limit'    => [ 'type' => 'integer', 'description' => 'Max issues to return', 'default' => 50 ],
            'offset'   => [ 'type' => 'integer', 'description' => 'Number of issues to skip', 'default' => 0 ],
        ], [
            'title'           => 'List Scan Issues',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_resolve_scan_issue', '[Security] Resolve a Wordfence scan issue: mark as permanently ignored (ignoreP), ignored until changed (ignoreC), or delete it.', [
            'issue_id' => [ 'type' => 'integer', 'description' => 'Scan issue ID', 'required' => true ],
            'action'   => [ 'type' => 'string', 'description' => 'Resolution action', 'enum' => [ 'ignoreP', 'ignoreC', 'delete' ], 'required' => true ],
        ], [
            'title'           => 'Resolve Scan Issue',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_delete_scan_issues', '[Security] Bulk delete all resolved/ignored scan issues.', [
            'status' => [ 'type' => 'string', 'description' => 'Delete issues with this status', 'enum' => [ 'ignoreP', 'ignoreC' ], 'required' => true ],
        ], [
            'title'           => 'Delete Scan Issues',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        /* ── Blocking ──────────────────────────────────────────── */

        Cowboy_MCP_Tools::tool( 'wp_wordfence_list_blocks', '[Security] List Wordfence IP, country, and pattern blocks. Filter by type.', [
            'type'   => [ 'type' => 'string', 'description' => 'Filter by block type', 'enum' => [ 'ip', 'country', 'pattern' ] ],
            'limit'  => [ 'type' => 'integer', 'description' => 'Max blocks to return', 'default' => 50 ],
            'offset' => [ 'type' => 'integer', 'description' => 'Number of blocks to skip', 'default' => 0 ],
        ], [
            'title'           => 'List Blocks',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_block_ip', '[Security] Block an IP address. Includes self-lockout prevention. Optionally set a duration in seconds (0 = permanent).', [
            'ip'       => [ 'type' => 'string', 'description' => 'IP address to block (IPv4 or IPv6)', 'required' => true ],
            'reason'   => [ 'type' => 'string', 'description' => 'Reason for blocking', 'default' => 'Blocked via MCP Bridge' ],
            'duration' => [ 'type' => 'integer', 'description' => 'Block duration in seconds (0 = permanent)', 'default' => 0 ],
        ], [
            'title'           => 'Block IP',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_unblock_ip', '[Security] Remove an IP block by IP address or block ID.', [
            'ip'       => [ 'type' => 'string', 'description' => 'IP address to unblock' ],
            'block_id' => [ 'type' => 'integer', 'description' => 'Block ID to remove' ],
        ], [
            'title'           => 'Unblock IP',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_block_country', '[Security] Block countries from login and/or site access. Requires Wordfence Premium.', [
            'countries'  => [ 'type' => 'array', 'description' => 'Array of 2-letter country codes (ISO 3166-1 alpha-2)', 'items' => [ 'type' => 'string' ], 'required' => true ],
            'block_login' => [ 'type' => 'boolean', 'description' => 'Block login from these countries', 'default' => true ],
            'block_site'  => [ 'type' => 'boolean', 'description' => 'Block site access from these countries', 'default' => false ],
        ], [
            'title'           => 'Block Countries',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_block_pattern', '[Security] Create a pattern-based block: IP range, hostname, user agent, or referrer.', [
            'ip_range'   => [ 'type' => 'string', 'description' => 'IP range in CIDR or dash notation (e.g., 192.168.1.0/24 or 192.168.1.1-192.168.1.255)' ],
            'hostname'   => [ 'type' => 'string', 'description' => 'Hostname pattern to block' ],
            'user_agent' => [ 'type' => 'string', 'description' => 'User agent pattern to block' ],
            'referrer'   => [ 'type' => 'string', 'description' => 'Referrer pattern to block' ],
            'reason'     => [ 'type' => 'string', 'description' => 'Reason for blocking', 'default' => 'Pattern blocked via MCP Bridge' ],
        ], [
            'title'           => 'Block Pattern',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        /* ── Firewall ──────────────────────────────────────────── */

        Cowboy_MCP_Tools::tool( 'wp_wordfence_firewall_status', '[Security] Get Wordfence firewall status: mode, WAF status, brute force protection, learning mode, and premium status.', [], [
            'title'           => 'Firewall Status',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_set_firewall_mode', '[Security] Set Wordfence firewall mode: enabled, learning, or disabled.', [
            'mode' => [ 'type' => 'string', 'description' => 'Firewall mode', 'enum' => [ 'enabled', 'learning', 'disabled' ], 'required' => true ],
        ], [
            'title'           => 'Set Firewall Mode',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        /* ── Live Traffic ──────────────────────────────────────── */

        Cowboy_MCP_Tools::tool( 'wp_wordfence_get_live_traffic', '[Security] Get recent Wordfence live traffic hits. Filter by IP, status code, or action.', [
            'ip'          => [ 'type' => 'string', 'description' => 'Filter by IP address' ],
            'status_code' => [ 'type' => 'integer', 'description' => 'Filter by HTTP status code' ],
            'action'      => [ 'type' => 'string', 'description' => 'Filter by action (e.g., loginOK, loginFailValidUsername, blocked:waf)' ],
            'limit'       => [ 'type' => 'integer', 'description' => 'Max hits to return', 'default' => 50 ],
            'offset'      => [ 'type' => 'integer', 'description' => 'Number of hits to skip', 'default' => 0 ],
        ], [
            'title'           => 'Get Live Traffic',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_get_login_attempts', '[Security] Get Wordfence login attempt history. Filter by action (loginOK/loginFailValidUsername/loginFailInvalidUsername) or IP/username.', [
            'action'   => [ 'type' => 'string', 'description' => 'Filter by login action', 'enum' => [ 'loginOK', 'loginFailValidUsername', 'loginFailInvalidUsername' ] ],
            'ip'       => [ 'type' => 'string', 'description' => 'Filter by IP address' ],
            'username' => [ 'type' => 'string', 'description' => 'Filter by username' ],
            'limit'    => [ 'type' => 'integer', 'description' => 'Max entries to return', 'default' => 50 ],
            'offset'   => [ 'type' => 'integer', 'description' => 'Number of entries to skip', 'default' => 0 ],
        ], [
            'title'           => 'Get Login Attempts',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        /* ── Activity & Settings ──────────────────────────────── */

        Cowboy_MCP_Tools::tool( 'wp_wordfence_get_activity_report', '[Security] Get Wordfence activity summary: top blocked IPs, countries, failed logins, and attack counts.', [], [
            'title'           => 'Activity Report',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_get_settings', '[Security] Get Wordfence settings grouped by category: firewall, scanning, blocking, login, notifications. Specify a category or get all.', [
            'category' => [ 'type' => 'string', 'description' => 'Settings category to return', 'enum' => [ 'firewall', 'scanning', 'blocking', 'login', 'notifications' ] ],
        ], [
            'title'           => 'Get Wordfence Settings',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_wordfence_update_settings', '[Security] Update Wordfence settings. Only allowlisted configuration keys are accepted; sensitive keys like apiKey and isPaid are blocked.', [
            'settings' => [ 'type' => 'object', 'description' => 'Key-value pairs of settings to update', 'required' => true ],
        ], [
            'title'           => 'Update Wordfence Settings',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [

        /* ── Scanning ──────────────────────────────────────────── */

        'wp_wordfence_start_scan' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfConfig' ) || ! class_exists( 'wordfence' ) ) {
                return new WP_Error( 'wordfence_error', 'Wordfence classes not available.' );
            }

            $type = $a['type'] ?? 'standard';
            $scan_type = cowboy_mcp_wordfence_get_scan_type_constant( $type );
            if ( is_wp_error( $scan_type ) ) {
                return $scan_type;
            }

            // Check if scan is already running.
            if ( wfConfig::get( 'wf_scanRunning' ) ) {
                return new WP_Error( 'scan_running', 'A scan is already in progress. Use wp_wordfence_get_scan_status to check progress.' );
            }

            // Set scan type before starting.
            if ( class_exists( 'wfScanner' ) ) {
                $scanner = new wfScanner();
                if ( method_exists( $scanner, 'scanType' ) ) {
                    wfConfig::set( 'scanType', $scan_type );
                }
            }

            // Trigger the scan via Wordfence's AJAX callback.
            if ( method_exists( 'wordfence', 'ajax_startScan_callback' ) ) {
                // Buffer output since the callback may echo.
                ob_start();
                try {
                    wordfence::ajax_startScan_callback();
                } catch ( \Throwable $e ) {
                    ob_end_clean();
                    return new WP_Error( 'scan_error', 'Failed to start scan: ' . $e->getMessage() );
                }
                ob_end_clean();
            } else {
                return new WP_Error( 'wordfence_error', 'wordfence::ajax_startScan_callback() not available.' );
            }

            return [
                'triggered'  => true,
                'scan_type'  => $type,
                'message'    => 'Scan started asynchronously. Use wp_wordfence_get_scan_status to poll progress.',
            ];
        },

        'wp_wordfence_get_scan_status' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfConfig' ) ) {
                return new WP_Error( 'wordfence_error', 'wfConfig class not available.' );
            }

            $running        = (bool) wfConfig::get( 'wf_scanRunning' );
            $last_completed = wfConfig::get( 'lastScanCompleted' );
            $last_failed    = wfConfig::get( 'lastScanFailureType' );
            $scan_stage     = wfConfig::get( 'wf_scanStageCount' );

            // Count open issues.
            global $wpdb;
            $issues_table = $wpdb->base_prefix . 'wfIssues';
            $open_count   = 0;
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $issues_table ) ) === $issues_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $open_count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT COUNT(*) FROM %i WHERE status = 'new'",
                    $issues_table
                ) );
            }

            $status = [
                'running'             => $running,
                'last_completed'      => $last_completed ? gmdate( 'Y-m-d H:i:s', (int) $last_completed ) : null,
                'last_failure_type'   => $last_failed ?: null,
                'scan_stage'          => $scan_stage ?: null,
                'open_issues'         => $open_count,
            ];

            // Get current scan summary if available.
            $summary = wfConfig::get( 'wf_summaryItems' );
            if ( $summary ) {
                $decoded = is_string( $summary ) ? json_decode( $summary, true ) : $summary;
                if ( is_array( $decoded ) ) {
                    $status['summary'] = $decoded;
                }
            }

            return $status;
        },

        'wp_wordfence_list_scan_issues' => function ( array $a ): array|WP_Error {
            global $wpdb;
            $table = $wpdb->base_prefix . 'wfIssues';

            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                return new WP_Error( 'wordfence_error', 'Wordfence issues table not found.' );
            }

            $conditions = [];

            if ( ! empty( $a['status'] ) ) {
                $conditions[] = $wpdb->prepare( 'status = %s', sanitize_text_field( $a['status'] ) );
            }
            if ( ! empty( $a['type'] ) ) {
                $conditions[] = $wpdb->prepare( 'type = %s', sanitize_text_field( $a['type'] ) );
            }
            if ( isset( $a['severity'] ) ) {
                $conditions[] = $wpdb->prepare( 'severity >= %d', (int) $a['severity'] );
            }

            $where_sql = ! empty( $conditions ) ? 'WHERE ' . implode( ' AND ', $conditions ) : '';
            $limit     = max( 1, min( 200, (int) ( $a['limit'] ?? 50 ) ) );
            $offset    = max( 0, (int) ( $a['offset'] ?? 0 ) );

            // Count total matching.
            $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table ) . " $where_sql";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total = (int) $wpdb->get_var( $count_sql );

            // Fetch page.
            $select_sql = $wpdb->prepare( "SELECT * FROM %i", $table ) . " $where_sql " . $wpdb->prepare( "ORDER BY severity DESC, time DESC LIMIT %d OFFSET %d", $limit, $offset );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results( $select_sql, ARRAY_A );

            $issues = array_map( 'cowboy_mcp_wordfence_format_issue', $rows ?: [] );

            return [
                'total'  => $total,
                'offset' => $offset,
                'limit'  => $limit,
                'issues' => $issues,
            ];
        },

        'wp_wordfence_resolve_scan_issue' => function ( array $a ): array|WP_Error {
            global $wpdb;
            $table = $wpdb->base_prefix . 'wfIssues';

            $issue_id = (int) ( $a['issue_id'] ?? 0 );
            $action   = $a['action'] ?? '';

            if ( $issue_id <= 0 ) {
                return new WP_Error( 'invalid_param', 'issue_id must be a positive integer.' );
            }
            if ( ! in_array( $action, [ 'ignoreP', 'ignoreC', 'delete' ], true ) ) {
                return new WP_Error( 'invalid_param', 'action must be ignoreP, ignoreC, or delete.' );
            }

            // Verify issue exists.
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id = %d", $table, $issue_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( ! $exists ) {
                return new WP_Error( 'not_found', "Scan issue #{$issue_id} not found." );
            }

            if ( $action === 'delete' ) {
                $wpdb->delete( $table, [ 'id' => $issue_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            } else {
                $wpdb->update( $table, [ 'status' => $action ], [ 'id' => $issue_id ], [ '%s' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            }

            return [
                'resolved'  => true,
                'issue_id'  => $issue_id,
                'action'    => $action,
            ];
        },

        'wp_wordfence_delete_scan_issues' => function ( array $a ): array|WP_Error {
            global $wpdb;
            $table = $wpdb->base_prefix . 'wfIssues';

            $status = $a['status'] ?? '';
            if ( ! in_array( $status, [ 'ignoreP', 'ignoreC' ], true ) ) {
                return new WP_Error( 'invalid_param', 'status must be ignoreP or ignoreC.' );
            }

            $count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COUNT(*) FROM %i WHERE status = %s",
                $table,
                $status
            ) );

            if ( $count === 0 ) {
                return [ 'deleted' => 0, 'status' => $status, 'message' => 'No matching issues to delete.' ];
            }

            $wpdb->delete( $table, [ 'status' => $status ], [ '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            return [
                'deleted' => $count,
                'status'  => $status,
            ];
        },

        /* ── Blocking ──────────────────────────────────────────── */

        'wp_wordfence_list_blocks' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfBlock' ) ) {
                return new WP_Error( 'wordfence_error', 'wfBlock class not available.' );
            }

            $limit  = max( 1, min( 200, (int) ( $a['limit'] ?? 50 ) ) );
            $offset = max( 0, (int) ( $a['offset'] ?? 0 ) );

            // Get all blocks and filter client-side since wfBlock API is limited.
            $type_filter = ! empty( $a['type'] ) ? sanitize_text_field( $a['type'] ) : null;

            $all_blocks = [];
            if ( method_exists( 'wfBlock', 'allBlocks' ) ) {
                $raw = wfBlock::allBlocks();
                foreach ( $raw as $block ) {
                    $formatted = cowboy_mcp_wordfence_format_block( $block );
                    if ( $type_filter === null || $formatted['type'] === $type_filter ) {
                        $all_blocks[] = $formatted;
                    }
                }
            }

            $total  = count( $all_blocks );
            $paged  = array_slice( $all_blocks, $offset, $limit );

            return [
                'total'  => $total,
                'offset' => $offset,
                'limit'  => $limit,
                'blocks' => $paged,
            ];
        },

        'wp_wordfence_block_ip' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfBlock' ) ) {
                return new WP_Error( 'wordfence_error', 'wfBlock class not available.' );
            }

            $ip = $a['ip'] ?? '';
            if ( ! cowboy_mcp_wordfence_validate_ip( $ip ) ) {
                return new WP_Error( 'invalid_param', 'Invalid IP address.' );
            }

            // Self-lockout prevention.
            if ( cowboy_mcp_wordfence_is_own_ip( $ip ) ) {
                return new WP_Error( 'self_lockout', 'Cannot block your own IP address. This would lock you out.' );
            }

            $reason   = sanitize_text_field( $a['reason'] ?? 'Blocked via MCP Bridge' );
            $duration = (int) ( $a['duration'] ?? 0 );

            if ( method_exists( 'wfBlock', 'createIP' ) ) {
                $params = [
                    'reason'    => $reason,
                    'permanent' => ( $duration === 0 ),
                ];
                if ( $duration > 0 ) {
                    $params['expiration'] = time() + $duration;
                }
                wfBlock::createIP( $ip, $reason, $params['permanent'], $duration > 0 ? $params['expiration'] : false );
            } else {
                return new WP_Error( 'wordfence_error', 'wfBlock::createIP() not available.' );
            }

            return [
                'blocked'    => true,
                'ip'         => $ip,
                'reason'     => $reason,
                'duration'   => $duration === 0 ? 'permanent' : "{$duration} seconds",
            ];
        },

        'wp_wordfence_unblock_ip' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfBlock' ) ) {
                return new WP_Error( 'wordfence_error', 'wfBlock class not available.' );
            }

            $ip       = $a['ip'] ?? '';
            $block_id = isset( $a['block_id'] ) ? (int) $a['block_id'] : 0;

            if ( $ip === '' && $block_id <= 0 ) {
                return new WP_Error( 'invalid_param', 'Provide either ip or block_id.' );
            }

            if ( $ip !== '' ) {
                if ( ! cowboy_mcp_wordfence_validate_ip( $ip ) ) {
                    return new WP_Error( 'invalid_param', 'Invalid IP address.' );
                }
                if ( method_exists( 'wfBlock', 'unblockIP' ) ) {
                    wfBlock::unblockIP( $ip );
                } else {
                    return new WP_Error( 'wordfence_error', 'wfBlock::unblockIP() not available.' );
                }
                return [ 'unblocked' => true, 'ip' => $ip ];
            }

            if ( method_exists( 'wfBlock', 'removeBlockIDs' ) ) {
                wfBlock::removeBlockIDs( [ $block_id ] );
            } else {
                return new WP_Error( 'wordfence_error', 'wfBlock::removeBlockIDs() not available.' );
            }

            return [ 'unblocked' => true, 'block_id' => $block_id ];
        },

        'wp_wordfence_block_country' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfConfig' ) ) {
                return new WP_Error( 'wordfence_error', 'wfConfig class not available.' );
            }

            // Premium check.
            if ( ! wfConfig::get( 'isPaid' ) ) {
                return new WP_Error( 'premium_required', 'Country blocking requires Wordfence Premium.' );
            }

            $countries = $a['countries'] ?? [];
            if ( ! is_array( $countries ) || empty( $countries ) ) {
                return new WP_Error( 'invalid_param', 'countries must be a non-empty array of 2-letter country codes.' );
            }

            // Validate country codes (2-char alpha).
            $valid_countries = [];
            foreach ( $countries as $code ) {
                $code = strtoupper( sanitize_text_field( $code ) );
                if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
                    $valid_countries[] = $code;
                }
            }

            if ( empty( $valid_countries ) ) {
                return new WP_Error( 'invalid_param', 'No valid 2-letter country codes provided.' );
            }

            $block_login = $a['block_login'] ?? true;
            $block_site  = $a['block_site'] ?? false;

            // Merge with existing blocked countries.
            $existing = wfConfig::get( 'cbl_countries', '' );
            $existing_arr = $existing ? explode( ',', $existing ) : [];
            $merged = array_unique( array_merge( $existing_arr, $valid_countries ) );

            wfConfig::set( 'cbl_countries', implode( ',', $merged ) );

            if ( $block_login ) {
                wfConfig::set( 'cbl_loginFormBlocked', 1 );
            }
            if ( $block_site ) {
                wfConfig::set( 'cbl_restOfSiteBlocked', 1 );
            }

            return [
                'blocked'       => true,
                'countries'     => $valid_countries,
                'block_login'   => (bool) $block_login,
                'block_site'    => (bool) $block_site,
                'total_blocked' => count( $merged ),
            ];
        },

        'wp_wordfence_block_pattern' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfBlock' ) ) {
                return new WP_Error( 'wordfence_error', 'wfBlock class not available.' );
            }

            $ip_range   = sanitize_text_field( $a['ip_range'] ?? '' );
            $hostname   = sanitize_text_field( $a['hostname'] ?? '' );
            $user_agent = sanitize_text_field( $a['user_agent'] ?? '' );
            $referrer   = sanitize_text_field( $a['referrer'] ?? '' );
            $reason     = sanitize_text_field( $a['reason'] ?? 'Pattern blocked via MCP Bridge' );

            if ( $ip_range === '' && $hostname === '' && $user_agent === '' && $referrer === '' ) {
                return new WP_Error( 'invalid_param', 'At least one pattern field is required: ip_range, hostname, user_agent, or referrer.' );
            }

            if ( method_exists( 'wfBlock', 'createPattern' ) ) {
                wfBlock::createPattern( $reason, $ip_range, $hostname, $user_agent, $referrer );
            } else {
                return new WP_Error( 'wordfence_error', 'wfBlock::createPattern() not available.' );
            }

            return [
                'blocked'    => true,
                'type'       => 'pattern',
                'ip_range'   => $ip_range ?: null,
                'hostname'   => $hostname ?: null,
                'user_agent' => $user_agent ?: null,
                'referrer'   => $referrer ?: null,
                'reason'     => $reason,
            ];
        },

        /* ── Firewall ──────────────────────────────────────────── */

        'wp_wordfence_firewall_status' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfConfig' ) ) {
                return new WP_Error( 'wordfence_error', 'wfConfig class not available.' );
            }

            $status = [
                'firewall_enabled'      => (bool) wfConfig::get( 'firewallEnabled', false ),
                'waf_status'            => wfConfig::get( 'wafStatus', 'disabled' ),
                'learning_mode'         => (bool) wfConfig::get( 'learningModeGracePeriodEnabled', false ),
                'learning_mode_until'   => null,
                'brute_force_enabled'   => (bool) wfConfig::get( 'loginSecurityEnabled', false ),
                'max_login_failures'    => (int) wfConfig::get( 'loginSec_maxFailures', 20 ),
                'lockout_duration_mins' => (int) wfConfig::get( 'loginSec_lockoutMins', 5 ),
                'is_premium'            => (bool) wfConfig::get( 'isPaid', false ),
                'block_fake_bots'       => (bool) wfConfig::get( 'blockFakeBots', false ),
            ];

            $grace_period = wfConfig::get( 'learningModeGracePeriod' );
            if ( $grace_period ) {
                $status['learning_mode_until'] = gmdate( 'Y-m-d H:i:s', (int) $grace_period );
            }

            // WAF rules count if available.
            if ( class_exists( 'wfWAF' ) && method_exists( 'wfWAF', 'getInstance' ) ) {
                try {
                    $waf = wfWAF::getInstance();
                    if ( $waf && method_exists( $waf, 'getRules' ) ) {
                        $rules = $waf->getRules();
                        $status['waf_rules_count'] = is_array( $rules ) ? count( $rules ) : 0;
                    }
                } catch ( \Throwable $e ) {
                    // WAF may not be initialized in REST context.
                }
            }

            return $status;
        },

        'wp_wordfence_set_firewall_mode' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'wfConfig' ) ) {
                return new WP_Error( 'wordfence_error', 'wfConfig class not available.' );
            }

            $mode = $a['mode'] ?? '';

            $waf_value = match ( $mode ) {
                'enabled'  => 'enabled',
                'learning' => 'learning-mode',
                'disabled' => 'disabled',
                default    => null,
            };

            if ( $waf_value === null ) {
                return new WP_Error( 'invalid_param', 'mode must be enabled, learning, or disabled.' );
            }

            wfConfig::set( 'wafStatus', $waf_value );

            if ( $mode === 'enabled' ) {
                wfConfig::set( 'firewallEnabled', 1 );
            } elseif ( $mode === 'disabled' ) {
                wfConfig::set( 'firewallEnabled', 0 );
            }

            return [
                'updated'    => true,
                'mode'       => $mode,
                'waf_status' => $waf_value,
            ];
        },

        /* ── Live Traffic ──────────────────────────────────────── */

        'wp_wordfence_get_live_traffic' => function ( array $a ): array|WP_Error {
            global $wpdb;
            $table = $wpdb->base_prefix . 'wfHits';

            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                return new WP_Error( 'wordfence_error', 'Wordfence hits table not found. Live traffic may be disabled.' );
            }

            $conditions = [];

            if ( ! empty( $a['ip'] ) ) {
                if ( ! cowboy_mcp_wordfence_validate_ip( $a['ip'] ) ) {
                    return new WP_Error( 'invalid_param', 'Invalid IP address.' );
                }
                // Wordfence stores IPs as binary.
                if ( class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_pton' ) ) {
                    $conditions[] = $wpdb->prepare( 'IP = %s', wfUtils::inet_pton( $a['ip'] ) );
                }
            }

            if ( isset( $a['status_code'] ) ) {
                $conditions[] = $wpdb->prepare( 'statusCode = %d', (int) $a['status_code'] );
            }

            if ( ! empty( $a['action'] ) ) {
                $conditions[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $a['action'] ) );
            }

            $where_sql = ! empty( $conditions ) ? 'WHERE ' . implode( ' AND ', $conditions ) : '';
            $limit     = max( 1, min( 200, (int) ( $a['limit'] ?? 50 ) ) );
            $offset    = max( 0, (int) ( $a['offset'] ?? 0 ) );

            $select_sql = $wpdb->prepare( "SELECT * FROM %i", $table ) . " $where_sql " . $wpdb->prepare( "ORDER BY attackLogTime DESC LIMIT %d OFFSET %d", $limit, $offset );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results( $select_sql, ARRAY_A );

            $hits = array_map( 'cowboy_mcp_wordfence_format_hit', $rows ?: [] );

            return [
                'limit'  => $limit,
                'offset' => $offset,
                'hits'   => $hits,
            ];
        },

        'wp_wordfence_get_login_attempts' => function ( array $a ): array|WP_Error {
            global $wpdb;
            $table = $wpdb->base_prefix . 'wfLogins';

            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                return new WP_Error( 'wordfence_error', 'Wordfence logins table not found.' );
            }

            $conditions = [];

            if ( ! empty( $a['action'] ) ) {
                $conditions[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $a['action'] ) );
            }

            if ( ! empty( $a['ip'] ) ) {
                if ( ! cowboy_mcp_wordfence_validate_ip( $a['ip'] ) ) {
                    return new WP_Error( 'invalid_param', 'Invalid IP address.' );
                }
                if ( class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_pton' ) ) {
                    $conditions[] = $wpdb->prepare( 'IP = %s', wfUtils::inet_pton( $a['ip'] ) );
                }
            }

            if ( ! empty( $a['username'] ) ) {
                $conditions[] = $wpdb->prepare( 'username = %s', sanitize_text_field( $a['username'] ) );
            }

            $where_sql = ! empty( $conditions ) ? 'WHERE ' . implode( ' AND ', $conditions ) : '';
            $limit     = max( 1, min( 200, (int) ( $a['limit'] ?? 50 ) ) );
            $offset    = max( 0, (int) ( $a['offset'] ?? 0 ) );

            $select_sql = $wpdb->prepare( "SELECT * FROM %i", $table ) . " $where_sql " . $wpdb->prepare( "ORDER BY ctime DESC LIMIT %d OFFSET %d", $limit, $offset );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results( $select_sql, ARRAY_A );

            $logins = [];
            foreach ( $rows ?: [] as $row ) {
                $ip = $row['IP'] ?? '';
                if ( $ip !== '' && class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_ntop' ) ) {
                    $decoded = @wfUtils::inet_ntop( $ip );
                    if ( $decoded !== false ) {
                        $ip = $decoded;
                    }
                }

                $logins[] = [
                    'id'       => $row['id'] ?? null,
                    'ctime'    => ! empty( $row['ctime'] ) ? gmdate( 'Y-m-d H:i:s', (float) $row['ctime'] ) : null,
                    'ip'       => $ip,
                    'username' => $row['username'] ?? '',
                    'action'   => $row['action'] ?? '',
                    'fail'     => (bool) ( $row['fail'] ?? false ),
                    'user_id'  => $row['userID'] ?? null,
                ];
            }

            return [
                'limit'  => $limit,
                'offset' => $offset,
                'logins' => $logins,
            ];
        },

        /* ── Activity & Settings ──────────────────────────────── */

        'wp_wordfence_get_activity_report' => function ( array $a ): array|WP_Error {
            // Try the wfActivityReport class first.
            if ( class_exists( 'wfActivityReport' ) ) {
                try {
                    $report = new wfActivityReport();
                    if ( method_exists( $report, 'getFullReport' ) ) {
                        $full = $report->getFullReport();
                        return is_array( $full ) ? $full : [ 'report' => $full ];
                    }
                } catch ( \Throwable $e ) {
                    // Fall through to manual aggregation.
                }
            }

            // Manual aggregation fallback.
            global $wpdb;
            $result = [];

            // Blocked attacks in last 7 days from wfHits.
            $hits_table = $wpdb->base_prefix . 'wfHits';
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $hits_table ) ) === $hits_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $seven_days_ago = time() - ( 7 * DAY_IN_SECONDS );
                $result['attacks_blocked_7d'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT COUNT(*) FROM %i WHERE action = 'blocked:waf' AND attackLogTime > %f",
                    $hits_table,
                    (float) $seven_days_ago
                ) );

                // Top blocked IPs.
                $top_ips = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT IP, COUNT(*) as cnt FROM %i WHERE action LIKE %s AND attackLogTime > %f GROUP BY IP ORDER BY cnt DESC LIMIT 10",
                    $hits_table,
                    'blocked%',
                    (float) $seven_days_ago
                ), ARRAY_A );

                $result['top_blocked_ips'] = [];
                foreach ( $top_ips ?: [] as $row ) {
                    $ip = $row['IP'] ?? '';
                    if ( $ip !== '' && class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_ntop' ) ) {
                        $decoded = @wfUtils::inet_ntop( $ip );
                        if ( $decoded !== false ) {
                            $ip = $decoded;
                        }
                    }
                    $result['top_blocked_ips'][] = [ 'ip' => $ip, 'count' => (int) $row['cnt'] ];
                }
            }

            // Failed logins in last 7 days from wfLogins.
            $logins_table = $wpdb->base_prefix . 'wfLogins';
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $logins_table ) ) === $logins_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $seven_days_ago = time() - ( 7 * DAY_IN_SECONDS );
                $result['failed_logins_7d'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT COUNT(*) FROM %i WHERE fail = 1 AND ctime > %f",
                    $logins_table,
                    (float) $seven_days_ago
                ) );
                $result['successful_logins_7d'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT COUNT(*) FROM %i WHERE fail = 0 AND ctime > %f",
                    $logins_table,
                    (float) $seven_days_ago
                ) );
            }

            return $result;
        },

        'wp_wordfence_get_settings' => function ( array $a ) use ( $cowboy_mcp_wordfence_settings_categories ): array|WP_Error {
            if ( ! class_exists( 'wfConfig' ) ) {
                return new WP_Error( 'wordfence_error', 'wfConfig class not available.' );
            }

            $category = $a['category'] ?? null;

            if ( $category !== null ) {
                if ( ! isset( $cowboy_mcp_wordfence_settings_categories[ $category ] ) ) {
                    return new WP_Error( 'invalid_param', "Invalid category: {$category}. Allowed: " . implode( ', ', array_keys( $cowboy_mcp_wordfence_settings_categories ) ) );
                }
                $keys = $cowboy_mcp_wordfence_settings_categories[ $category ];
                $settings = [];
                foreach ( $keys as $key ) {
                    $settings[ $key ] = wfConfig::get( $key );
                }
                return [ 'category' => $category, 'settings' => $settings ];
            }

            // Return all categories.
            $all = [];
            foreach ( $cowboy_mcp_wordfence_settings_categories as $cat => $keys ) {
                $all[ $cat ] = [];
                foreach ( $keys as $key ) {
                    $all[ $cat ][ $key ] = wfConfig::get( $key );
                }
            }

            return [ 'settings' => $all ];
        },

        'wp_wordfence_update_settings' => function ( array $a ) use ( $cowboy_mcp_wordfence_settings_allowlist, $cowboy_mcp_wordfence_guarded ): array|WP_Error {
            if ( ! class_exists( 'wfConfig' ) ) {
                return new WP_Error( 'wordfence_error', 'wfConfig class not available.' );
            }

            $settings = $a['settings'] ?? [];
            if ( ! is_array( $settings ) || empty( $settings ) ) {
                return new WP_Error( 'invalid_param', 'settings must be a non-empty object of key-value pairs.' );
            }

            $allowed       = [];
            $rejected      = [];
            $needs_confirm = [];

            foreach ( $settings as $key => $value ) {
                $key = sanitize_text_field( $key );
                // Only allowlisted, scalar values may be written.
                if ( ! in_array( $key, $cowboy_mcp_wordfence_settings_allowlist, true ) || ! is_scalar( $value ) ) {
                    $rejected[] = $key;
                    continue;
                }
                // Protection-affecting keys need explicit confirm, even with safe mode off.
                if ( in_array( $key, $cowboy_mcp_wordfence_guarded, true ) && empty( $a['confirm'] ) ) {
                    $needs_confirm[] = $key;
                    continue;
                }
                $allowed[ $key ] = $value;
            }

            // Abort without applying anything if protection-affecting keys lack confirmation.
            if ( ! empty( $needs_confirm ) ) {
                return new WP_Error(
                    'confirmation_required',
                    'These settings can disable Wordfence protections and require confirm: true: ' . implode( ', ', $needs_confirm ) . '.'
                );
            }

            if ( empty( $allowed ) ) {
                return new WP_Error( 'no_changes', 'No valid settings keys provided. Rejected keys: ' . implode( ', ', $rejected ) );
            }

            $updated = [];
            foreach ( $allowed as $key => $value ) {
                wfConfig::set( $key, $value );
                $updated[ $key ] = $value;
            }

            $result = [
                'updated'  => true,
                'changes'  => $updated,
            ];
            if ( ! empty( $rejected ) ) {
                $result['rejected_keys'] = $rejected;
            }

            return $result;
        },
    ],
];
