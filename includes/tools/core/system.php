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

        Cowboy_MCP_Tools::tool( 'wp_cli', '[System] Execute a WP-CLI command on the server. Blocked commands: db drop, db reset, db import, db export, site empty, core download, eval, eval-file, config set, config create, shell, package install. In safe mode, only known-safe commands run without confirm: true.', [
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

            $command = $a['command'] ?? '';

            // Reject WP-CLI global flags that load/execute code or change context.
            // `--require=<file>` alone is arbitrary PHP execution that would bypass the
            // eval/shell blocklist; `--path` is supplied by us below. Power mode lifts this.
            if ( ! Cowboy_MCP_Security::power_mode_enabled()
                && preg_match( '/(^|\s)--(require|exec|ssh|http|user|path|config|debug)(=|\s|$)/i', $command ) ) {
                return new WP_Error( 'blocked', 'Global WP-CLI flags (--require, --exec, --ssh, --http, --user, --path, --config) are not allowed.' );
            }

            // Always-blocked commands (regardless of safe mode). Internal whitespace is
            // normalized so e.g. `config  set` cannot dodge the prefix match.
            $blocked = [
                'db drop', 'db reset', 'db import', 'db export',
                'site empty', 'core download',
                'eval', 'eval-file',
                'config set', 'config create',
                'shell', 'package install',
            ];
            $lower_cmd = preg_replace( '/\s+/', ' ', strtolower( trim( $command ) ) );
            if ( ! Cowboy_MCP_Security::power_mode_enabled() ) {
                foreach ( $blocked as $b ) {
                    if ( str_starts_with( $lower_cmd, $b ) ) {
                        return new WP_Error( 'blocked', "Command '{$b}' is blocked for safety. An administrator can enable Power mode to allow this." );
                    }
                }
            }

            // `db query`/`db search` run arbitrary SQL via shell_exec with no guardrails of
            // their own. Apply the same blocklist/secret-table checks used elsewhere so the
            // wp_cli escape hatch can't run blocked or credential-touching SQL.
            if ( preg_match( '/^db\s+(query|search)\b(.*)$/i', $lower_cmd, $m ) ) {
                $sql = Cowboy_MCP_Security::normalize_sql( trim( $m[2], " \t\"'" ) );
                $why = Cowboy_MCP_Security::sql_blocked_reason( $sql );
                if ( $why && ! Cowboy_MCP_Security::power_mode_enabled() ) {
                    return new WP_Error( 'blocked', "SQL operation blocked: {$why}." );
                }
                // Credential/secret access is never lifted, even in Power mode.
                if ( Cowboy_MCP_Security::sql_touches_secret( $sql ) ) {
                    return new WP_Error( 'blocked', 'Query references credential/secret data and is blocked.' );
                }
            }

            // Safe mode: only allow known-safe command prefixes unless confirm: true.
            $settings = Cowboy_MCP_Tools::get_settings();
            if ( ! empty( $settings['safe_mode'] ) && empty( $a['confirm'] ) ) {
                $safe_prefixes = [
                    'cache', 'cron', 'db size', 'export',
                    'help', 'media', 'menu', 'option get', 'option list',
                    'plugin list', 'plugin status', 'post list', 'post get',
                    'rewrite', 'role list', 'sidebar', 'taxonomy', 'term',
                    'theme list', 'theme status', 'transient', 'user list', 'user get',
                    'widget',
                ];
                $is_safe = false;
                foreach ( $safe_prefixes as $prefix ) {
                    if ( str_starts_with( $lower_cmd, $prefix ) ) {
                        $is_safe = true;
                        break;
                    }
                }
                if ( ! $is_safe ) {
                    return new WP_Error(
                        'confirmation_required',
                        "Safe mode is ON. WP-CLI command '{$command}' is not in the safe allowlist. Resend with confirm: true to execute.",
                        [ 'suggestion' => 'Safe commands include: cache, cron, db query, export, help, plugin list, post list, theme list, etc.' ]
                    );
                }
            }

            // In Power mode the caller may pass their own --path; don't append a second one.
            $has_path = (bool) preg_match( '/(^|\s)--path(=|\s)/i', $command );
            $wp_path  = Cowboy_MCP_Compat::wp_root();
            $full     = $has_path
                ? sprintf( 'wp %s --allow-root 2>&1', escapeshellcmd( $command ) )
                : sprintf( 'wp %s --path=%s --allow-root 2>&1', escapeshellcmd( $command ), escapeshellarg( $wp_path ) );
            $output   = shell_exec( $full );

            return [
                'command' => 'wp ' . $command,
                'output'  => $output ?: '(no output)',
            ];
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
                // Batch updates to avoid long-running table locks.
                $batch_size = 500;
                $remaining  = min( $count, $limit );
                while ( $remaining > 0 ) {
                    $batch      = min( $batch_size, $remaining );
                    $update_sql = $wpdb->prepare( "UPDATE %i SET post_content = REPLACE(post_content, %s, %s)", $wpdb->posts, $search, $replace ) . " $where_sql " . $wpdb->prepare( "LIMIT %d", $batch );
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $rows = $wpdb->query( $update_sql );
                    if ( $rows === false || $rows === 0 ) break;
                    $updated   += $rows;
                    $remaining -= $rows;
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
