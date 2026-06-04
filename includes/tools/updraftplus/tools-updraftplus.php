<?php
defined( 'ABSPATH' ) || exit;

/**
 * Safely return the global UpdraftPlus instance or null.
 */
function cowboy_mcp_updraft_get_instance(): ?object {
    global $updraftplus;
    return ( $updraftplus instanceof UpdraftPlus ) ? $updraftplus : null;
}

/**
 * Ensure UpdraftPlus_Admin is loaded (not auto-loaded in REST context).
 */
function cowboy_mcp_updraft_ensure_admin_loaded(): bool {
    if ( class_exists( 'UpdraftPlus_Admin' ) ) return true;
    $dir  = defined( 'UPDRAFTPLUS_DIR' ) ? UPDRAFTPLUS_DIR : dirname( ( new ReflectionClass( 'UpdraftPlus' ) )->getFileName() );
    $file = $dir . '/admin.php';
    if ( file_exists( $file ) ) {
        require_once $file;
        return class_exists( 'UpdraftPlus_Admin' );
    }
    return false;
}

/**
 * Normalize a backup history entry for consistent output.
 */
function cowboy_mcp_updraft_format_backup( array $backup, int $timestamp ): array {
    $entities = [];
    foreach ( [ 'db', 'plugins', 'themes', 'uploads', 'others' ] as $entity ) {
        if ( ! empty( $backup[ $entity ] ) ) {
            $entities[] = $entity;
        }
    }

    return [
        'timestamp' => $timestamp,
        'date'      => gmdate( 'Y-m-d H:i:s', $timestamp ),
        'nonce'     => $backup['nonce'] ?? '',
        'entities'  => $entities,
        'service'   => $backup['service'] ?? 'none',
        'label'     => $backup['label'] ?? '',
    ];
}

/* ================================================================
 *  UpdraftPlus guard — return empty arrays when not active.
 * ================================================================ */

if ( ! class_exists( 'UpdraftPlus' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

$cowboy_mcp_updraft_allowed_intervals = [ 'manual', 'every4hours', 'every8hours', 'twicedaily', 'daily', 'weekly', 'fortnightly', 'monthly' ];

return [
    'tools' => [
        /* ---------- List & Get ---------- */

        Cowboy_MCP_Tools::tool( 'wp_updraft_list_backups', '[Backups] List available UpdraftPlus backups with timestamps, entities, and storage destinations.', [
            'limit'  => [ 'type' => 'integer', 'description' => 'Max backups to return', 'default' => 20 ],
            'offset' => [ 'type' => 'integer', 'description' => 'Number of backups to skip', 'default' => 0 ],
        ], [
            'title'           => 'List Backups',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'total'   => [ 'type' => 'integer' ],
                'offset'  => [ 'type' => 'integer' ],
                'limit'   => [ 'type' => 'integer' ],
                'backups' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'timestamp' => [ 'type' => 'integer' ],
                            'date'      => [ 'type' => 'string' ],
                            'nonce'     => [ 'type' => 'string' ],
                            'entities'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'service'   => [ 'type' => 'string' ],
                            'label'     => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_updraft_get_backup', '[Backups] Get details of a specific backup by timestamp.', [
            'timestamp' => [ 'type' => 'integer', 'description' => 'Backup timestamp', 'required' => true ],
        ], [
            'title'           => 'Get Backup',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'timestamp' => [ 'type' => 'integer' ],
                'date'      => [ 'type' => 'string' ],
                'nonce'     => [ 'type' => 'string' ],
                'entities'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'service'   => [ 'type' => 'string' ],
                'label'     => [ 'type' => 'string' ],
            ],
        ] ),

        /* ---------- Trigger & Status ---------- */

        Cowboy_MCP_Tools::tool( 'wp_updraft_trigger_backup', '[Backups] Trigger a new UpdraftPlus backup. Returns a nonce immediately — backup runs asynchronously. Poll wp_updraft_backup_status for progress.', [
            'type'  => [ 'type' => 'string', 'description' => 'What to back up', 'enum' => [ 'all', 'files', 'database' ], 'default' => 'all' ],
            'label' => [ 'type' => 'string', 'description' => 'Optional label for the backup' ],
        ], [
            'title'           => 'Trigger Backup',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_updraft_backup_status', '[Backups] Check whether an UpdraftPlus backup is currently running and its progress.', [], [
            'title'           => 'Backup Status',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'running' => [ 'type' => 'boolean' ],
                'nonce'   => [ 'type' => 'string' ],
                'started' => [ 'type' => 'integer' ],
                'stage'   => [ 'type' => 'string' ],
            ],
        ] ),

        /* ---------- Restore & Delete ---------- */

        Cowboy_MCP_Tools::tool( 'wp_updraft_restore_backup', '[Backups] NOT YET SUPPORTED — programmatic restore is not implemented and returns an error. Use the UpdraftPlus admin UI to restore. Listed for discoverability only.', [
            'timestamp' => [ 'type' => 'integer', 'description' => 'Backup timestamp to restore', 'required' => true ],
            'entities'  => [ 'type' => 'array', 'description' => 'Entities to restore (db, plugins, themes, uploads, others). Defaults to all.', 'items' => [ 'type' => 'string' ] ],
            'confirm'   => [ 'type' => 'boolean', 'description' => 'Must be true to proceed', 'required' => true ],
        ], [
            'title'           => 'Restore Backup',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_updraft_delete_backup', '[Backups] Delete a backup set. Requires confirm: true.', [
            'timestamp'     => [ 'type' => 'integer', 'description' => 'Backup timestamp to delete', 'required' => true ],
            'delete_remote' => [ 'type' => 'boolean', 'description' => 'Also delete from remote storage', 'default' => false ],
            'confirm'       => [ 'type' => 'boolean', 'description' => 'Must be true to proceed', 'required' => true ],
        ], [
            'title'           => 'Delete Backup',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        /* ---------- Settings & Schedules ---------- */

        Cowboy_MCP_Tools::tool( 'wp_updraft_get_settings', '[Backups] Get current UpdraftPlus configuration (schedule intervals, retention, remote storage, backup directory).', [], [
            'title'           => 'Get Backup Settings',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_updraft_get_log', '[Backups] Read tail of an UpdraftPlus backup log by nonce.', [
            'nonce'      => [ 'type' => 'string', 'description' => 'Backup nonce (alphanumeric)', 'required' => true ],
            'tail_lines' => [ 'type' => 'integer', 'description' => 'Number of lines from end', 'default' => 100 ],
        ], [
            'title'           => 'Get Backup Log',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_updraft_get_schedules', '[Backups] Get current UpdraftPlus backup schedules and next scheduled run times.', [], [
            'title'           => 'Get Backup Schedules',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_updraft_update_schedules', '[Backups] Update UpdraftPlus backup schedule intervals and retention counts.', [
            'file_interval'     => [ 'type' => 'string', 'description' => 'File backup interval', 'enum' => [ 'manual', 'every4hours', 'every8hours', 'twicedaily', 'daily', 'weekly', 'fortnightly', 'monthly' ] ],
            'database_interval' => [ 'type' => 'string', 'description' => 'Database backup interval', 'enum' => [ 'manual', 'every4hours', 'every8hours', 'twicedaily', 'daily', 'weekly', 'fortnightly', 'monthly' ] ],
            'file_retain'       => [ 'type' => 'integer', 'description' => 'Number of file backups to retain' ],
            'database_retain'   => [ 'type' => 'integer', 'description' => 'Number of database backups to retain' ],
        ], [
            'title'           => 'Update Backup Schedules',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [
        /* ---------- List & Get ---------- */

        'wp_updraft_list_backups' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
                return new WP_Error( 'updraft_error', 'UpdraftPlus_Backup_History class not available.' );
            }

            $history = UpdraftPlus_Backup_History::get_history();
            krsort( $history ); // newest first

            $limit  = max( 1, (int) ( $a['limit'] ?? 20 ) );
            $offset = max( 0, (int) ( $a['offset'] ?? 0 ) );

            $backups = [];
            $i = 0;
            foreach ( $history as $ts => $backup ) {
                if ( $i < $offset ) { $i++; continue; }
                if ( count( $backups ) >= $limit ) break;
                $backups[] = cowboy_mcp_updraft_format_backup( $backup, (int) $ts );
                $i++;
            }

            return [
                'total'   => count( $history ),
                'offset'  => $offset,
                'limit'   => $limit,
                'backups' => $backups,
            ];
        },

        'wp_updraft_get_backup' => function ( array $a ): array|WP_Error {
            $timestamp = (int) ( $a['timestamp'] ?? 0 );
            if ( $timestamp <= 0 ) {
                return new WP_Error( 'missing_param', 'timestamp is required and must be positive.' );
            }

            if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
                return new WP_Error( 'updraft_error', 'UpdraftPlus_Backup_History class not available.' );
            }

            $history = UpdraftPlus_Backup_History::get_history();
            if ( ! isset( $history[ $timestamp ] ) ) {
                return new WP_Error( 'not_found', "No backup found with timestamp {$timestamp}." );
            }

            return cowboy_mcp_updraft_format_backup( $history[ $timestamp ], $timestamp );
        },

        /* ---------- Trigger & Status ---------- */

        'wp_updraft_trigger_backup' => function ( array $a ): array|WP_Error {
            $updraft = cowboy_mcp_updraft_get_instance();
            if ( ! $updraft ) {
                return new WP_Error( 'updraft_error', 'UpdraftPlus instance not available.' );
            }

            $type  = $a['type'] ?? 'all';
            $label = isset( $a['label'] ) ? sanitize_text_field( $a['label'] ) : '';

            $backup_files    = in_array( $type, [ 'all', 'files' ], true );
            $backup_database = in_array( $type, [ 'all', 'database' ], true );

            $nonce = $updraft->boot_backup( $backup_files, $backup_database, false, false, false, $label );

            if ( is_wp_error( $nonce ) ) {
                return $nonce;
            }

            return [
                'triggered' => true,
                'nonce'     => $nonce,
                'type'      => $type,
                'label'     => $label,
                'message'   => 'Backup started asynchronously. Use wp_updraft_backup_status to poll progress.',
            ];
        },

        'wp_updraft_backup_status' => function ( array $a ): array|WP_Error {
            $updraft = cowboy_mcp_updraft_get_instance();
            if ( ! $updraft ) {
                return new WP_Error( 'updraft_error', 'UpdraftPlus instance not available.' );
            }

            $nonce = $updraft->nonce ?? '';
            $running = $updraft->is_backup_running();

            $status = [
                'running' => ! empty( $running ),
                'nonce'   => $nonce,
            ];

            if ( ! empty( $running ) && is_array( $running ) ) {
                $status['started']  = $running['run_started'] ?? null;
                $status['stage']    = $running['stage'] ?? 'unknown';
            }

            return $status;
        },

        /* ---------- Restore & Delete ---------- */

        'wp_updraft_restore_backup' => function ( array $a ): array|WP_Error {
            // NOTE: This previously built restore options, set the `updraft_restore_in_progress`
            // option, and returned restore_initiated:true — but it never invoked any UpdraftPlus
            // restore routine, so it falsely reported success while doing nothing. Until a correct,
            // supported restore invocation is implemented, fail loudly rather than mislead callers
            // (and avoid leaving a stray updraft_restore_in_progress flag behind).
            return new WP_Error(
                'not_implemented',
                'Programmatic restore is not yet supported via MCP. Use the UpdraftPlus admin UI to restore a backup.'
            );
        },

        'wp_updraft_delete_backup' => function ( array $a ): array|WP_Error {
            if ( empty( $a['confirm'] ) || $a['confirm'] !== true ) {
                return new WP_Error( 'confirmation_required', 'You must pass confirm: true to delete a backup.' );
            }

            $timestamp = (int) ( $a['timestamp'] ?? 0 );
            if ( $timestamp <= 0 ) {
                return new WP_Error( 'missing_param', 'timestamp is required and must be positive.' );
            }

            if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
                return new WP_Error( 'updraft_error', 'UpdraftPlus_Backup_History class not available.' );
            }

            $history = UpdraftPlus_Backup_History::get_history();
            if ( ! isset( $history[ $timestamp ] ) ) {
                return new WP_Error( 'not_found', "No backup found with timestamp {$timestamp}." );
            }

            if ( ! cowboy_mcp_updraft_ensure_admin_loaded() ) {
                return new WP_Error( 'updraft_error', 'Could not load UpdraftPlus_Admin class required for delete.' );
            }

            $delete_remote = ! empty( $a['delete_remote'] );
            $backup        = $history[ $timestamp ];
            $nonce         = $backup['nonce'] ?? '';

            // Use UpdraftPlus_Admin to delete the backup set.
            $updraft_admin = new UpdraftPlus_Admin();
            $updraft_admin->delete_set( [
                'nonce'         => $nonce,
                'timestamp'     => $timestamp,
                'delete_remote' => $delete_remote,
            ] );

            return [
                'deleted'        => true,
                'timestamp'      => $timestamp,
                'delete_remote'  => $delete_remote,
            ];
        },

        /* ---------- Settings & Schedules ---------- */

        'wp_updraft_get_settings' => function ( array $a ): array {
            return [
                'file_interval'     => get_option( 'updraft_interval', 'manual' ),
                'database_interval' => get_option( 'updraft_interval_database', 'manual' ),
                'file_retain'       => (int) get_option( 'updraft_retain', 2 ),
                'database_retain'   => (int) get_option( 'updraft_retain_db', 2 ),
                'service'           => get_option( 'updraft_service', 'none' ),
                'directory'         => get_option( 'updraft_dir', '' ),
                'include_files'     => array_filter( [
                    get_option( 'updraft_include_plugins', true ) ? 'plugins' : null,
                    get_option( 'updraft_include_themes', true ) ? 'themes' : null,
                    get_option( 'updraft_include_uploads', true ) ? 'uploads' : null,
                    get_option( 'updraft_include_others', true ) ? 'others' : null,
                ] ),
                'email_reports'     => get_option( 'updraft_email', '' ),
                'split_every_mb'    => (int) get_option( 'updraft_split_every', 400 ),
            ];
        },

        'wp_updraft_get_log' => function ( array $a ): string|WP_Error {
            $nonce = $a['nonce'] ?? '';
            if ( $nonce === '' ) {
                return new WP_Error( 'missing_param', 'nonce is required.' );
            }

            // Sanitize nonce to alphanumeric only to prevent path traversal.
            $nonce = preg_replace( '/[^a-z0-9]/i', '', $nonce );
            if ( $nonce === '' ) {
                return new WP_Error( 'invalid_param', 'nonce must contain alphanumeric characters.' );
            }

            $updraft = cowboy_mcp_updraft_get_instance();
            if ( ! $updraft ) {
                return new WP_Error( 'updraft_error', 'UpdraftPlus instance not available.' );
            }

            $backup_dir = $updraft->backups_dir_location();
            if ( ! $backup_dir || ! is_dir( $backup_dir ) ) {
                return new WP_Error( 'updraft_error', 'Backup directory not found.' );
            }

            $log_file = $backup_dir . '/log.' . $nonce . '.txt';

            // Verify the resolved path is within the backup directory.
            $real_backup_dir = realpath( $backup_dir );
            $real_log_file   = realpath( $log_file );
            if ( $real_log_file === false || $real_backup_dir === false || strpos( $real_log_file, $real_backup_dir ) !== 0 ) {
                return new WP_Error( 'invalid_path', 'Log file path is invalid or outside backup directory.' );
            }

            if ( ! file_exists( $real_log_file ) ) {
                return new WP_Error( 'not_found', "No log file found for nonce: {$nonce}" );
            }

            $tail_lines = max( 1, (int) ( $a['tail_lines'] ?? 100 ) );
            $lines      = file( $real_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

            if ( $lines === false ) {
                return new WP_Error( 'read_error', 'Failed to read log file.' );
            }

            $tail = array_slice( $lines, -$tail_lines );
            return implode( "\n", $tail );
        },

        'wp_updraft_get_schedules' => function ( array $a ): array {
            $file_interval = get_option( 'updraft_interval', 'manual' );
            $db_interval   = get_option( 'updraft_interval_database', 'manual' );

            $next_file_backup = wp_next_scheduled( 'updraft_backup' );
            $next_db_backup   = wp_next_scheduled( 'updraft_backup_database' );

            return [
                'file_interval'     => $file_interval,
                'database_interval' => $db_interval,
                'file_retain'       => (int) get_option( 'updraft_retain', 2 ),
                'database_retain'   => (int) get_option( 'updraft_retain_db', 2 ),
                'next_file_backup'  => $next_file_backup ? gmdate( 'Y-m-d H:i:s', $next_file_backup ) : null,
                'next_db_backup'    => $next_db_backup ? gmdate( 'Y-m-d H:i:s', $next_db_backup ) : null,
            ];
        },

        'wp_updraft_update_schedules' => function ( array $a ) use ( $cowboy_mcp_updraft_allowed_intervals ): array|WP_Error {
            $updated = [];

            if ( isset( $a['file_interval'] ) ) {
                if ( ! in_array( $a['file_interval'], $cowboy_mcp_updraft_allowed_intervals, true ) ) {
                    return new WP_Error( 'invalid_param', 'Invalid file_interval. Allowed: ' . implode( ', ', $cowboy_mcp_updraft_allowed_intervals ) );
                }
                update_option( 'updraft_interval', $a['file_interval'] );
                wp_clear_scheduled_hook( 'updraft_backup' );
                if ( $a['file_interval'] !== 'manual' ) {
                    wp_schedule_event( time(), $a['file_interval'], 'updraft_backup' );
                }
                $updated['file_interval'] = $a['file_interval'];
            }

            if ( isset( $a['database_interval'] ) ) {
                if ( ! in_array( $a['database_interval'], $cowboy_mcp_updraft_allowed_intervals, true ) ) {
                    return new WP_Error( 'invalid_param', 'Invalid database_interval. Allowed: ' . implode( ', ', $cowboy_mcp_updraft_allowed_intervals ) );
                }
                update_option( 'updraft_interval_database', $a['database_interval'] );
                wp_clear_scheduled_hook( 'updraft_backup_database' );
                if ( $a['database_interval'] !== 'manual' ) {
                    wp_schedule_event( time(), $a['database_interval'], 'updraft_backup_database' );
                }
                $updated['database_interval'] = $a['database_interval'];
            }

            if ( isset( $a['file_retain'] ) ) {
                $retain = max( 0, (int) $a['file_retain'] );
                update_option( 'updraft_retain', $retain );
                $updated['file_retain'] = $retain;
            }

            if ( isset( $a['database_retain'] ) ) {
                $retain = max( 0, (int) $a['database_retain'] );
                update_option( 'updraft_retain_db', $retain );
                $updated['database_retain'] = $retain;
            }

            if ( empty( $updated ) ) {
                return new WP_Error( 'no_changes', 'No valid schedule parameters provided.' );
            }

            return [
                'updated'  => true,
                'changes'  => $updated,
            ];
        },
    ],
];
