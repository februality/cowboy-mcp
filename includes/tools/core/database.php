<?php
defined( 'ABSPATH' ) || exit;

/**
 * Replace {prefix} placeholder with the actual WordPress table prefix.
 */
function cowboy_mcp_prepare_sql( string $sql ): string {
    global $wpdb;
    return str_replace( '{prefix}', $wpdb->prefix, $sql );
}

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_db_query', '[Database] Run a read-only SQL query against the WordPress database. Supports SELECT, SHOW, DESCRIBE, and EXPLAIN. Returns up to 100 rows. Use {prefix} as the table prefix placeholder (e.g. SELECT * FROM {prefix}posts).', [
            'sql' => [ 'type' => 'string', 'description' => 'SQL query. Use {prefix} as table prefix placeholder (e.g. "SELECT * FROM {prefix}posts WHERE post_status = \'publish\' LIMIT 10").', 'required' => true ],
        ], [ 'title' => 'Database Query', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'rows' => [ 'type' => 'integer' ],
                'data' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_db_write', '[Database] Run a write SQL query (INSERT, UPDATE, DELETE, CREATE TABLE). Blocked operations: DROP DATABASE, DROP TABLE, TRUNCATE, ALTER TABLE, RENAME TABLE, CREATE TRIGGER/PROCEDURE/FUNCTION, LOAD DATA, GRANT, REVOKE, INTO OUTFILE/DUMPFILE. Use {prefix} for table prefix.', [
            'sql' => [ 'type' => 'string', 'description' => 'SQL write query. Use {prefix} as table prefix placeholder.', 'required' => true ],
        ], [ 'title' => 'Database Write', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'affected_rows' => [ 'type' => 'integer', 'description' => 'Number of rows affected by the query' ],
                'insert_id'     => [ 'type' => 'integer', 'description' => 'Auto-increment ID from the last INSERT' ],
            ],
        ]),

    ],
    'handlers' => [
        'wp_db_query' => function ( array $a ) {
            global $wpdb;
            $sql  = cowboy_mcp_prepare_sql( $a['sql'] );
            $norm = Cowboy_MCP_Security::normalize_sql( $sql );

            // Safety: only allow SELECT / SHOW / DESCRIBE / EXPLAIN (checked on the
            // comment-stripped form so a leading /* */ cannot hide the real verb).
            $first_word = strtoupper( strtok( $norm, " \t\n\r" ) );
            if ( ! in_array( $first_word, [ 'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN' ], true ) ) {
                return new WP_Error( 'read_only', 'wp_db_query only allows SELECT/SHOW/DESCRIBE/EXPLAIN. Use wp_db_write for mutations.' );
            }

            // Even a SELECT can write/exfiltrate (e.g. INTO OUTFILE) — apply the blocklist
            // unless Power mode lifts it. The credential-touch check below ALWAYS applies.
            $blocked = Cowboy_MCP_Security::sql_blocked_reason( $norm );
            if ( $blocked && ! Cowboy_MCP_Security::power_mode_enabled() ) {
                return new WP_Error( 'blocked', "{$blocked} is blocked for safety. An administrator can enable Power mode to allow this." );
            }

            // Block direct reads of credential/secret data sources.
            if ( Cowboy_MCP_Security::sql_touches_secret( $norm ) ) {
                return new WP_Error( 'blocked', 'Query references protected credential data (password hashes or API keys) and is blocked.' );
            }

            // Append LIMIT only when the query has no top-level LIMIT clause.
            if ( ! preg_match( '/\bLIMIT\s+\d/i', $norm ) ) {
                $sql .= ' LIMIT 100';
            }

            $results = $wpdb->get_results( $sql, ARRAY_A ) ?? []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( $wpdb->last_error ) {
                return new WP_Error( 'db_error', $wpdb->last_error );
            }
            // Defense in depth: mask secret-named columns / sensitive option rows.
            $results = Cowboy_MCP_Security::redact_columns( $results );
            return [ 'rows' => count( $results ), 'data' => $results ];
        },

        'wp_db_write' => function ( array $a ) {
            global $wpdb;
            $sql  = cowboy_mcp_prepare_sql( $a['sql'] );
            $norm = Cowboy_MCP_Security::normalize_sql( $sql );

            // Block dangerous operations on the comment-stripped form so they can't be
            // hidden with inline comments (e.g. DROP/**/TABLE) — unless Power mode lifts it.
            $blocked = Cowboy_MCP_Security::sql_blocked_reason( $norm );
            if ( $blocked && ! Cowboy_MCP_Security::power_mode_enabled() ) {
                return new WP_Error( 'blocked', "{$blocked} is blocked for safety. An administrator can enable Power mode to allow this." );
            }

            $result = $wpdb->query( $sql );     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( $wpdb->last_error ) {
                return new WP_Error( 'db_error', $wpdb->last_error );
            }
            return [ 'affected_rows' => $result, 'insert_id' => $wpdb->insert_id ];
        },

    ],
];
