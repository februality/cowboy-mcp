<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_site_info', '[System] Get comprehensive site information: WP version, active theme/plugins, PHP info, permalink structure, etc.', [], [ 'title' => 'Site Info', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'name'               => [ 'type' => 'string' ],
                'description'        => [ 'type' => 'string' ],
                'url'                => [ 'type' => 'string' ],
                'admin_url'          => [ 'type' => 'string' ],
                'wp_version'         => [ 'type' => 'string' ],
                'php_version'        => [ 'type' => 'string' ],
                'mysql_version'      => [ 'type' => 'string' ],
                'multisite'          => [ 'type' => 'boolean' ],
                'permalink_structure'=> [ 'type' => 'string' ],
                'timezone'           => [ 'type' => 'string' ],
                'language'           => [ 'type' => 'string' ],
                'active_theme'       => [ 'type' => 'object', 'properties' => [
                    'name' => [ 'type' => 'string' ], 'slug' => [ 'type' => 'string' ],
                    'version' => [ 'type' => 'string' ], 'parent' => [ 'type' => 'string' ],
                ] ],
                'active_plugins'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'post_types'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'taxonomies'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'uploads_dir'        => [ 'type' => 'string' ],
                'uploads_url'        => [ 'type' => 'string' ],
                'debug_mode'         => [ 'type' => 'boolean' ],
                'memory_limit'       => [ 'type' => 'string' ],
                'max_upload_size'    => [ 'type' => 'string' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_cli', '[System] Execute a WP-CLI command on the server. Blocked commands: db drop, db reset, db import, db export, site empty, core download, eval, eval-file, config set, config create, shell, package install. In safe mode, only known-safe commands run without confirm: true. When auto-checkpointing is enabled, a DB checkpoint is taken automatically before mutating commands (response includes checkpoint_id); read commands (list/get/search/info/...) skip it.', [
            'command' => [ 'type' => 'string', 'description' => 'WP-CLI command without "wp" prefix (e.g. "cache flush", "rewrite flush", "plugin list")', 'required' => true ],
        ], [ 'title' => 'WP-CLI Command', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ], [
            'type' => 'object',
            'properties' => [
                'command' => [ 'type' => 'string', 'description' => 'The full command that was executed' ],
                'output'  => [ 'type' => 'string', 'description' => 'Command output' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_search_replace', '[System] Search and replace text across post content in the database. Updates in batches to avoid locking.', [
            'search'    => [ 'type' => 'string',  'description' => 'Text to search for', 'required' => true ],
            'replace'   => [ 'type' => 'string',  'description' => 'Replacement text', 'required' => true ],
            'post_type' => [ 'type' => 'string',  'description' => 'Limit to post type', 'default' => 'any' ],
            'dry_run'   => [ 'type' => 'boolean', 'description' => 'Preview without making changes', 'default' => true ],
            'limit'     => [ 'type' => 'integer', 'description' => 'Max posts to update per call (default 1000)', 'default' => 1000 ],
        ], [ 'title' => 'Search and Replace', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ]),

    ],
    'handlers' => [
        'wp_site_info' => function ( array $a = [] ): array {
            $theme = wp_get_theme();

            return [
                'name'               => get_bloginfo( 'name' ),
                'description'        => get_bloginfo( 'description' ),
                'url'                => home_url(),
                'admin_url'          => admin_url(),
                'wp_version'         => get_bloginfo( 'version' ),
                'php_version'        => phpversion(),
                'mysql_version'      => $GLOBALS['wpdb']->db_version(),
                'multisite'          => is_multisite(),
                'permalink_structure'=> get_option( 'permalink_structure' ),
                'timezone'           => wp_timezone_string(),
                'language'           => get_locale(),
                'active_theme'       => [
                    'name'    => $theme->get( 'Name' ),
                    'slug'    => $theme->get_stylesheet(),
                    'version' => $theme->get( 'Version' ),
                    'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                ],
                'active_plugins'     => array_values( get_option( 'active_plugins', [] ) ),
                'post_types'         => array_keys( get_post_types( [ 'public' => true ] ) ),
                'taxonomies'         => array_keys( get_taxonomies( [ 'public' => true ] ) ),
                'uploads_dir'        => wp_upload_dir()['basedir'],
                'uploads_url'        => wp_upload_dir()['baseurl'],
                'debug_mode'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
                'memory_limit'       => WP_MEMORY_LIMIT,
                'max_upload_size'    => size_format( wp_max_upload_size() ),
            ];
        },

        'wp_cli' => function ( array $a ) {
            // Check if WP-CLI is available.
            $wp_cli = shell_exec( 'which wp 2>/dev/null' );
            if ( empty( $wp_cli ) ) {
                return new WP_Error( 'wp_cli_missing', 'WP-CLI is not installed on this server.' );
            }

            $command  = $a['command'] ?? '';
            $settings = Cowboy_MCP_Tools::get_settings();

            // All wp_cli safety gates (global flags, the always-blocked subcommand list,
            // the cowboy_mcp_ option-write guard, the db query|search SQL blocklist, and
            // the safe-mode allowlist) are evaluated in Cowboy_MCP_Security::wp_cli_gate().
            // They match against a tokenized argv-style word sequence rather than the raw
            // string — shell_exec() runs this through /bin/sh -c, which strips quotes
            // before WP-CLI ever sees them, so raw-string prefix/regex matching against
            // the quoted command is bypassable (e.g. `option update 'cowboy_mcp_settings'
            // x` reads as identical to the unquoted form to the shell, but not to a
            // str_starts_with() check). See cli_tokens()/wp_cli_gate() for the full
            // rationale.
            $gate = Cowboy_MCP_Security::wp_cli_gate(
                $command,
                Cowboy_MCP_Security::power_mode_enabled(),
                ! empty( $settings['safe_mode'] ),
                ! empty( $a['confirm'] )
            );
            if ( null !== $gate['code'] ) {
                $error_data = 'confirmation_required' === $gate['code']
                    ? [ 'suggestion' => 'Safe commands include: cache, cron, db query, export, help, plugin list, post list, theme list, etc.' ]
                    : [];
                return new WP_Error( $gate['code'], $gate['message'], $error_data );
            }

            // Auto-checkpoint before mutating-looking commands (fail-open).
            // INVARIANT: no error/early-return path may exist between this call and the
            // handler's final return — commit() must run for this call so it clears
            // Cowboy_MCP_Rollback::$last_checkpoint_id; a post-checkpoint early return
            // would leak the id into a later call's journal row within the same request.
            $checkpoint_id = class_exists( 'Cowboy_MCP_Checkpoint' )
                ? Cowboy_MCP_Checkpoint::maybe_auto_checkpoint( $command )
                : null;

            // In Power mode the caller may pass their own --path; don't append a second one.
            $has_path = (bool) preg_match( '/(^|\s)--path(=|\s)/i', $command );
            $wp_path  = Cowboy_MCP_Compat::wp_root();
            $full     = $has_path
                ? sprintf( 'wp %s --allow-root 2>&1', escapeshellcmd( $command ) )
                : sprintf( 'wp %s --path=%s --allow-root 2>&1', escapeshellcmd( $command ), escapeshellarg( $wp_path ) );
            $output   = shell_exec( $full );

            $response = [
                'command' => 'wp ' . $command,
                'output'  => $output ?: '(no output)',
            ];
            if ( $checkpoint_id !== null ) {
                $response['checkpoint_id']   = $checkpoint_id;
                $response['checkpoint_note'] = 'A DB checkpoint was taken before this command. Restore with wp_restore_checkpoint if it went wrong.';
            }
            return $response;
        },

        'wp_search_replace' => function ( array $a ) {
            global $wpdb;

            $search  = $a['search'];
            $replace = $a['replace'];
            $dry_run = $a['dry_run'] ?? true;
            $type    = $a['post_type'] ?? 'any';

            $like = '%' . $wpdb->esc_like( $search ) . '%';

            $conditions = [ $wpdb->prepare( 'post_content LIKE %s', $like ) ];
            if ( $type !== 'any' ) {
                $conditions[] = $wpdb->prepare( 'post_type = %s', $type );
            }
            $where_sql = 'WHERE ' . implode( ' AND ', $conditions );

            $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM %i", $wpdb->posts ) . " $where_sql";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $count = (int) $wpdb->get_var( $count_sql );
            $limit = min( max( (int) ( $a['limit'] ?? 1000 ), 1 ), 10000 );

            $updated = 0;
            if ( ! $dry_run && $count > 0 ) {
                // Capture the affected rows first (row-level undo), then update those
                // exact IDs in batches. LIMIT applies to the capture query.
                $select_sql = $wpdb->prepare( "SELECT ID, post_content FROM %i", $wpdb->posts )
                    . " $where_sql " . $wpdb->prepare( "ORDER BY ID LIMIT %d", $limit );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $targets = $wpdb->get_results( $select_sql, ARRAY_A ) ?: [];

                foreach ( array_chunk( array_column( $targets, 'ID' ), 500 ) as $chunk ) {
                    $ids_sql    = implode( ',', array_map( 'intval', $chunk ) );
                    $update_sql = $wpdb->prepare(
                        "UPDATE %i SET post_content = REPLACE(post_content, %s, %s)",
                        $wpdb->posts, $search, $replace
                    ) . " WHERE ID IN ({$ids_sql})";
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $rows = $wpdb->query( $update_sql );
                    if ( $rows === false ) break;
                    $updated += $rows;
                }
                foreach ( array_column( $targets, 'ID' ) as $pid ) {
                    clean_post_cache( (int) $pid );
                }

                if ( class_exists( 'Cowboy_MCP_Rollback' ) ) {
                    // Re-read actual post-update content so the journal reflects reality,
                    // not a predicted str_replace() — a broken chunk loop must not lie
                    // about rows it never reached.
                    $target_ids = array_map( 'intval', array_column( $targets, 'ID' ) );
                    $after_map  = [];
                    if ( $target_ids ) {
                        $ids_sql   = implode( ',', $target_ids );
                        $after_sql = $wpdb->prepare( "SELECT ID, post_content FROM %i", $wpdb->posts )
                            . " WHERE ID IN ({$ids_sql})";
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                        $after_rows = $wpdb->get_results( $after_sql, ARRAY_A ) ?: [];
                        foreach ( $after_rows as $r ) {
                            $after_map[ (int) $r['ID'] ] = $r['post_content'];
                        }
                    }

                    Cowboy_MCP_Rollback::add_rows( array_map( fn( $r ) => [
                        'table'  => $wpdb->posts,
                        'pk_col' => 'ID',
                        'pk_val' => (int) $r['ID'],
                        'col'    => 'post_content',
                        'old'    => $r['post_content'],
                        'new'    => $after_map[ (int) $r['ID'] ] ?? $r['post_content'],
                    ], $targets ) );
                }
            }

            return [
                'search'         => $search,
                'replace'        => $replace,
                'matched_posts'  => $count,
                'updated_posts'  => $updated,
                'limit'          => $limit,
                'dry_run'        => $dry_run,
                'executed'       => ! $dry_run,
            ];
        },

    ],
];
