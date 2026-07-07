<?php
defined( 'ABSPATH' ) || exit;

/**
 * Validate a caller-supplied table name against this site's actual, prefixed tables.
 *
 * wpdb::prepare()'s %i (core 6.2+) safely quotes a value AS an identifier, but it has no
 * way to know whether that identifier is a table this site actually owns — a caller could
 * still name an unrelated table on a shared-database host. This closes that gap with an
 * exact-match check against a live, prefix-scoped SHOW TABLES list; call sites still bind
 * the result through %i for proper identifier quoting.
 */
function cowboy_mcp_validate_table_name( string $table ): string|WP_Error {
    global $wpdb;
    $like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
    $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    if ( ! in_array( $table, $tables, true ) ) {
        return new WP_Error( 'not_found', "Table '{$table}' does not exist or is outside this site's table prefix ({$wpdb->prefix})." );
    }
    return $table;
}

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_db_health_report', '[Database] Run a fixed set of read-only diagnostic checks against core WordPress tables: post revisions, auto-drafts, spam/trashed comments, transient count, autoloaded-options size, orphaned postmeta, and post counts by type/status.', [], [ 'title' => 'Database Health Report', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'revisions'         => [ 'type' => 'integer' ],
                'auto_drafts'       => [ 'type' => 'integer' ],
                'spam_comments'     => [ 'type' => 'integer' ],
                'trashed_comments'  => [ 'type' => 'integer' ],
                'transients'        => [ 'type' => 'integer' ],
                'autoload_options'  => [ 'type' => 'integer' ],
                'autoload_bytes'    => [ 'type' => 'integer' ],
                'orphaned_postmeta' => [ 'type' => 'integer' ],
                'post_counts'       => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_db_list_tables', "[Database] List this site's database tables (matching its table prefix) with row-count estimates and sizes.", [], [ 'title' => 'List Database Tables', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'tables' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_db_show_processlist', '[Database] Show currently running MySQL queries via SHOW FULL PROCESSLIST. Requires the PROCESS privilege — fails gracefully if unavailable on shared hosting.', [], [ 'title' => 'Show Process List', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'processes' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_db_check_table', '[Database] Run MySQL CHECK TABLE on one of this site\'s tables to test for corruption.', [
            'table' => [ 'type' => 'string', 'description' => 'Table name including prefix, e.g. wp_posts.', 'required' => true ],
        ], [ 'title' => 'Check Table', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'table'  => [ 'type' => 'string' ],
                'result' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_db_repair_table', '[Database] Run MySQL REPAIR TABLE to fix a corrupted table.', [
            'table' => [ 'type' => 'string', 'description' => 'Table name including prefix, e.g. wp_posts.', 'required' => true ],
        ], [ 'title' => 'Repair Table', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'table'  => [ 'type' => 'string' ],
                'result' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
            ],
        ]),

    ],
    'handlers' => [
        'wp_db_health_report' => function ( array $a ) {
            global $wpdb;

            // Every query below is a fixed statement the plugin itself wrote, with no
            // caller-supplied SQL text or values — there is nothing to bind via
            // wpdb::prepare(), same as core's own dbDelta()/upgrade queries.
            $revisions  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $drafts     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $spam       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $trashed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $transients = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $autoload_n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $autoload_b = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $orphaned   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id NOT IN ( SELECT ID FROM {$wpdb->posts} )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $counts     = $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) as count FROM {$wpdb->posts} GROUP BY post_type, post_status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            return [
                'revisions'         => $revisions,
                'auto_drafts'       => $drafts,
                'spam_comments'     => $spam,
                'trashed_comments'  => $trashed,
                'transients'        => $transients,
                'autoload_options'  => $autoload_n,
                'autoload_bytes'    => $autoload_b,
                'orphaned_postmeta' => $orphaned,
                'post_counts'       => $counts ?: [],
            ];
        },

        'wp_db_list_tables' => function ( array $a ) {
            global $wpdb;
            $like = $wpdb->esc_like( $wpdb->prefix ) . '%';
            $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $tables = array_map( static fn( $row ) => [
                'name'       => $row['Name'] ?? '',
                'rows'       => isset( $row['Rows'] ) ? (int) $row['Rows'] : null,
                'size_bytes' => isset( $row['Data_length'], $row['Index_length'] ) ? ( (int) $row['Data_length'] + (int) $row['Index_length'] ) : null,
                'engine'     => $row['Engine'] ?? null,
            ], $rows ?: [] );
            return [ 'tables' => $tables ];
        },

        'wp_db_show_processlist' => function ( array $a ) {
            global $wpdb;
            $rows = $wpdb->get_results( 'SHOW FULL PROCESSLIST', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $wpdb->last_error ) {
                return new WP_Error( 'db_error', $wpdb->last_error );
            }
            return [ 'processes' => $rows ?: [] ];
        },

        'wp_db_check_table' => function ( array $a ) {
            global $wpdb;
            $table = cowboy_mcp_validate_table_name( (string) $a['table'] );
            if ( is_wp_error( $table ) ) return $table;

            // $table is already validated above against a live, prefix-scoped SHOW TABLES
            // list; %i (core 6.2+) additionally binds it as a proper quoted identifier via
            // wpdb::prepare() rather than manual string interpolation.
            $result = $wpdb->get_results( $wpdb->prepare( 'CHECK TABLE %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $wpdb->last_error ) {
                return new WP_Error( 'db_error', $wpdb->last_error );
            }
            return [ 'table' => $table, 'result' => $result ?: [] ];
        },

        'wp_db_repair_table' => function ( array $a ) {
            global $wpdb;
            $table = cowboy_mcp_validate_table_name( (string) $a['table'] );
            if ( is_wp_error( $table ) ) return $table;

            // See wp_db_check_table — validated table name, bound via %i.
            $result = $wpdb->get_results( $wpdb->prepare( 'REPAIR TABLE %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $wpdb->last_error ) {
                return new WP_Error( 'db_error', $wpdb->last_error );
            }
            return [ 'table' => $table, 'result' => $result ?: [] ];
        },

    ],
];
