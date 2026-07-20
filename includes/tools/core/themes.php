<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_themes', '[Themes] List all installed themes with activation status, version, metadata, and available updates.', [
            'refresh_updates' => [ 'type' => 'boolean', 'description' => 'Check WordPress.org for fresh update info first (network call, slower). Default false: reads the cached update state.', 'default' => false ],
        ], [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_switch_theme', '[Themes] Switch the active theme.', [
            'stylesheet' => [ 'type' => 'string', 'description' => 'Theme directory name / stylesheet slug (e.g. "twentytwentyfour")', 'required' => true ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_install_theme', '[Themes] Install a theme from the WordPress.org directory by slug. Optionally pin a version or activate (switch to it) after install. Works without WP-CLI or shell access.', [
            'slug'     => [ 'type' => 'string', 'description' => 'WordPress.org theme slug (e.g. "twentytwentyfour")', 'required' => true ],
            'version'  => [ 'type' => 'string', 'description' => 'Specific version to install (default: latest)' ],
            'activate' => [ 'type' => 'boolean', 'description' => 'Switch to the theme after install (default false)', 'default' => false ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => true,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_update_theme', '[Themes] Update an installed theme from WordPress.org — one theme, or all with pending updates (all: true). Takes a file backup and DB checkpoint first, health-checks the site after updating the active theme, and auto-restores the old version if the site breaks. Undoable via wp_undo_change.', [
            'stylesheet' => [ 'type' => 'string', 'description' => 'Theme directory name (e.g. "twentytwentyfour"). Omit when using all: true.' ],
            'all'        => [ 'type' => 'boolean', 'description' => 'Update every theme that has a pending update (default false)', 'default' => false ],
            'version'    => [ 'type' => 'string', 'description' => 'Pin a specific version (downgrades allowed). Only with stylesheet.' ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => true,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_delete_theme', '[Themes] Delete an installed theme (not the active theme or its parent). Files are removed; a backup zip is kept so the deletion is undoable via wp_undo_change.', [
            'stylesheet' => [ 'type' => 'string', 'description' => 'Theme directory name / stylesheet slug (e.g. "twentytwentythree")', 'required' => true ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [

        'wp_list_themes' => function ( array $a ): array {
            if ( ! empty( $a['refresh_updates'] ) ) {
                wp_update_themes();
            }
            $all_themes        = wp_get_themes();
            $active_stylesheet = get_stylesheet();
            $updates           = get_site_transient( 'update_themes' );

            $themes = [];
            foreach ( $all_themes as $slug => $theme ) {
                $upd      = $updates->response[ $slug ] ?? null;
                $themes[] = [
                    'stylesheet'       => $slug,
                    'name'             => $theme->get( 'Name' ),
                    'version'          => $theme->get( 'Version' ),
                    'description'      => $theme->get( 'Description' ),
                    'author'           => $theme->get( 'Author' ),
                    'theme_uri'        => $theme->get( 'ThemeURI' ),
                    'parent'           => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                    'active'           => $slug === $active_stylesheet,
                    'update_available' => $upd !== null,
                    'new_version'      => is_array( $upd ) ? ( $upd['new_version'] ?? null ) : ( $upd->new_version ?? null ),
                ];
            }

            return [
                'themes'            => $themes,
                'total'             => count( $themes ),
                'active'            => $active_stylesheet,
                'updates_available' => count( array_filter( $themes, static fn( $t ) => $t['update_available'] ) ),
            ];
        },

        'wp_switch_theme' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'switch_themes' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the switch_themes capability.' );
            }

            $stylesheet = sanitize_text_field( $a['stylesheet'] );

            // Validate the theme exists.
            $theme = wp_get_theme( $stylesheet );
            if ( ! $theme->exists() ) {
                return new WP_Error( 'not_found', "Theme '{$stylesheet}' is not installed." );
            }

            $previous = get_stylesheet();

            // Already active — no-op.
            if ( $stylesheet === $previous ) {
                return [
                    'switched'            => true,
                    'stylesheet'          => $stylesheet,
                    'name'                => $theme->get( 'Name' ),
                    'previous_stylesheet' => $previous,
                    'already_active'      => true,
                ];
            }

            switch_theme( $stylesheet );

            return [
                'switched'            => true,
                'stylesheet'          => $stylesheet,
                'name'                => $theme->get( 'Name' ),
                'previous_stylesheet' => $previous,
            ];
        },

        'wp_install_theme' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'install_themes' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the install_themes capability.' );
            }
            return Cowboy_MCP_Installer::install(
                'theme',
                sanitize_key( $a['slug'] ?? '' ),
                isset( $a['version'] ) ? sanitize_text_field( (string) $a['version'] ) : null,
                ! empty( $a['activate'] )
            );
        },

        'wp_update_theme' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'update_themes' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the update_themes capability.' );
            }
            if ( ! empty( $a['all'] ) ) {
                if ( isset( $a['version'] ) ) {
                    return new WP_Error( 'invalid_params', 'version cannot be combined with all: true — pin versions one target at a time.' );
                }
                return Cowboy_MCP_Installer::update_all( 'theme' );
            }
            $stylesheet = sanitize_text_field( $a['stylesheet'] ?? '' );
            if ( $stylesheet === '' ) {
                return new WP_Error( 'invalid_params', 'Provide stylesheet, or all: true to update everything with a pending update.' );
            }
            return Cowboy_MCP_Installer::update_one(
                'theme',
                $stylesheet,
                isset( $a['version'] ) ? sanitize_text_field( (string) $a['version'] ) : null
            );
        },

        'wp_delete_theme' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'delete_themes' ) ) {
                return new WP_Error( 'forbidden', 'Current user lacks the delete_themes capability.' );
            }
            return Cowboy_MCP_Installer::delete( 'theme', sanitize_text_field( $a['stylesheet'] ?? '' ) );
        },

    ],
];
