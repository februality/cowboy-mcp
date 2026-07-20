<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_plugins', '[Plugins] List installed plugins with activation status, version, metadata, and available updates.', [
            'status'          => [ 'type' => 'string', 'description' => 'Filter by status: active, inactive, or all (default "all")', 'default' => 'all', 'enum' => [ 'active', 'inactive', 'all' ] ],
            'refresh_updates' => [ 'type' => 'boolean', 'description' => 'Check WordPress.org for fresh update info first (network call, slower). Default false: reads the cached update state.', 'default' => false ],
        ], [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_activate_plugin', '[Plugins] Activate an installed plugin.', [
            'plugin_file' => [ 'type' => 'string', 'description' => 'Plugin file path relative to plugins directory (e.g. "akismet/akismet.php" or "hello.php")', 'required' => true ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_deactivate_plugin', '[Plugins] Deactivate an active plugin.', [
            'plugin_file' => [ 'type' => 'string', 'description' => 'Plugin file path relative to plugins directory (e.g. "akismet/akismet.php" or "hello.php")', 'required' => true ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_install_plugin', '[Plugins] Install a plugin from the WordPress.org directory by slug. Optionally pin a version or activate after install. Works without WP-CLI or shell access.', [
            'slug'     => [ 'type' => 'string', 'description' => 'WordPress.org plugin slug (e.g. "akismet")', 'required' => true ],
            'version'  => [ 'type' => 'string', 'description' => 'Specific version to install (default: latest)' ],
            'activate' => [ 'type' => 'boolean', 'description' => 'Activate after install (default false)', 'default' => false ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => true,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_update_plugin', '[Plugins] Update an installed plugin from WordPress.org — one plugin, or all with pending updates (all: true). Takes a file backup and DB checkpoint first, health-checks the site after updating an active plugin, and auto-restores the old version if the site breaks. Undoable via wp_undo_change. On large sites prefer a few plugins per call (PHP time limits).', [
            'plugin_file' => [ 'type' => 'string', 'description' => 'Plugin file path (e.g. "akismet/akismet.php"). Omit when using all: true.' ],
            'all'         => [ 'type' => 'boolean', 'description' => 'Update every plugin that has a pending update (default false)', 'default' => false ],
            'version'     => [ 'type' => 'string', 'description' => 'Pin a specific version (downgrades allowed). Only with plugin_file.' ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => true,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_delete_plugin', '[Plugins] Delete an inactive plugin. Files are removed; a backup zip is kept so the deletion is undoable via wp_undo_change.', [
            'plugin_file' => [ 'type' => 'string', 'description' => 'Plugin file path relative to plugins directory (e.g. "akismet/akismet.php")', 'required' => true ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [

        'wp_list_plugins' => function ( array $a ): array {
            if ( ! empty( $a['refresh_updates'] ) ) {
                wp_update_plugins();
            }
            $all_plugins    = Cowboy_MCP_Compat::get_plugins();
            $active_plugins = get_option( 'active_plugins', [] );
            $status_filter  = $a['status'] ?? 'all';
            $updates        = get_site_transient( 'update_plugins' );

            $plugins = [];
            foreach ( $all_plugins as $file => $data ) {
                $is_active = in_array( $file, $active_plugins, true );

                if ( $status_filter === 'active' && ! $is_active ) continue;
                if ( $status_filter === 'inactive' && $is_active ) continue;

                $upd       = $updates->response[ $file ] ?? null;
                $plugins[] = [
                    'file'             => $file,
                    'name'             => $data['Name'] ?? '',
                    'version'          => $data['Version'] ?? '',
                    'description'      => $data['Description'] ?? '',
                    'author'           => $data['Author'] ?? '',
                    'plugin_uri'       => $data['PluginURI'] ?? '',
                    'active'           => $is_active,
                    'update_available' => $upd !== null,
                    'new_version'      => $upd->new_version ?? null,
                ];
            }

            return [
                'plugins'           => $plugins,
                'total'             => count( $plugins ),
                'updates_available' => count( array_filter( $plugins, static fn( $p ) => $p['update_available'] ) ),
            ];
        },

        'wp_activate_plugin' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the activate_plugins capability.' );
            }

            $plugin_file = sanitize_text_field( $a['plugin_file'] );

            // Verify plugin exists.
            $all_plugins = Cowboy_MCP_Compat::get_plugins();
            if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                return new WP_Error( 'not_found', "Plugin '{$plugin_file}' is not installed." );
            }

            // Check if already active.
            if ( Cowboy_MCP_Compat::is_plugin_active( $plugin_file ) ) {
                return [
                    'activated'   => true,
                    'plugin_file' => $plugin_file,
                    'name'        => $all_plugins[ $plugin_file ]['Name'] ?? '',
                    'already_active' => true,
                ];
            }

            $result = Cowboy_MCP_Compat::activate_plugin( $plugin_file );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return [
                'activated'   => true,
                'plugin_file' => $plugin_file,
                'name'        => $all_plugins[ $plugin_file ]['Name'] ?? '',
            ];
        },

        'wp_deactivate_plugin' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the activate_plugins capability.' );
            }

            $plugin_file = sanitize_text_field( $a['plugin_file'] );

            // Verify plugin exists.
            $all_plugins = Cowboy_MCP_Compat::get_plugins();
            if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                return new WP_Error( 'not_found', "Plugin '{$plugin_file}' is not installed." );
            }

            // Check if already inactive.
            if ( ! Cowboy_MCP_Compat::is_plugin_active( $plugin_file ) ) {
                return [
                    'deactivated'    => true,
                    'plugin_file'    => $plugin_file,
                    'name'           => $all_plugins[ $plugin_file ]['Name'] ?? '',
                    'already_inactive' => true,
                ];
            }

            Cowboy_MCP_Compat::deactivate_plugin( $plugin_file );

            return [
                'deactivated' => true,
                'plugin_file' => $plugin_file,
                'name'        => $all_plugins[ $plugin_file ]['Name'] ?? '',
            ];
        },

        'wp_install_plugin' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'install_plugins' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the install_plugins capability.' );
            }
            return Cowboy_MCP_Installer::install(
                'plugin',
                sanitize_key( $a['slug'] ?? '' ),
                isset( $a['version'] ) ? sanitize_text_field( (string) $a['version'] ) : null,
                ! empty( $a['activate'] )
            );
        },

        'wp_update_plugin' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'update_plugins' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the update_plugins capability.' );
            }
            if ( ! empty( $a['all'] ) ) {
                return Cowboy_MCP_Installer::update_all( 'plugin' );
            }
            $plugin_file = sanitize_text_field( $a['plugin_file'] ?? '' );
            if ( $plugin_file === '' ) {
                return new WP_Error( 'invalid_params', 'Provide plugin_file, or all: true to update everything with a pending update.' );
            }
            return Cowboy_MCP_Installer::update_one(
                'plugin',
                $plugin_file,
                isset( $a['version'] ) ? sanitize_text_field( (string) $a['version'] ) : null
            );
        },

        'wp_delete_plugin' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'delete_plugins' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the delete_plugins capability.' );
            }
            return Cowboy_MCP_Installer::delete( 'plugin', sanitize_text_field( $a['plugin_file'] ?? '' ) );
        },

    ],
];
