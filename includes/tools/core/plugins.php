<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_plugins', '[Plugins] List installed plugins with activation status, version, and metadata.', [
            'status' => [ 'type' => 'string', 'description' => 'Filter by status: active, inactive, or all (default "all")', 'default' => 'all', 'enum' => [ 'active', 'inactive', 'all' ] ],
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
    ],

    'handlers' => [

        'wp_list_plugins' => function ( array $a ): array {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $all_plugins    = get_plugins();
            $active_plugins = get_option( 'active_plugins', [] );
            $status_filter  = $a['status'] ?? 'all';

            $plugins = [];
            foreach ( $all_plugins as $file => $data ) {
                $is_active = in_array( $file, $active_plugins, true );

                if ( $status_filter === 'active' && ! $is_active ) continue;
                if ( $status_filter === 'inactive' && $is_active ) continue;

                $plugins[] = [
                    'file'        => $file,
                    'name'        => $data['Name'] ?? '',
                    'version'     => $data['Version'] ?? '',
                    'description' => $data['Description'] ?? '',
                    'author'      => $data['Author'] ?? '',
                    'plugin_uri'  => $data['PluginURI'] ?? '',
                    'active'      => $is_active,
                ];
            }

            return [
                'plugins' => $plugins,
                'total'   => count( $plugins ),
            ];
        },

        'wp_activate_plugin' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the activate_plugins capability.' );
            }

            if ( ! function_exists( 'activate_plugin' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugin_file = sanitize_text_field( $a['plugin_file'] );

            // Verify plugin exists.
            $all_plugins = get_plugins();
            if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                return new WP_Error( 'not_found', "Plugin '{$plugin_file}' is not installed." );
            }

            // Check if already active.
            if ( is_plugin_active( $plugin_file ) ) {
                return [
                    'activated'   => true,
                    'plugin_file' => $plugin_file,
                    'name'        => $all_plugins[ $plugin_file ]['Name'] ?? '',
                    'already_active' => true,
                ];
            }

            $result = activate_plugin( $plugin_file );
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

            if ( ! function_exists( 'deactivate_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugin_file = sanitize_text_field( $a['plugin_file'] );

            // Verify plugin exists.
            $all_plugins = get_plugins();
            if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                return new WP_Error( 'not_found', "Plugin '{$plugin_file}' is not installed." );
            }

            // Check if already inactive.
            if ( ! is_plugin_active( $plugin_file ) ) {
                return [
                    'deactivated'    => true,
                    'plugin_file'    => $plugin_file,
                    'name'           => $all_plugins[ $plugin_file ]['Name'] ?? '',
                    'already_inactive' => true,
                ];
            }

            deactivate_plugins( $plugin_file );

            return [
                'deactivated' => true,
                'plugin_file' => $plugin_file,
                'name'        => $all_plugins[ $plugin_file ]['Name'] ?? '',
            ];
        },

    ],
];
