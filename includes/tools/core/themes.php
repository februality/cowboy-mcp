<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_themes', '[Themes] List all installed themes with activation status, version, and metadata.', [], [
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
    ],

    'handlers' => [

        'wp_list_themes' => function ( array $a ): array {
            $all_themes       = wp_get_themes();
            $active_stylesheet = get_stylesheet();

            $themes = [];
            foreach ( $all_themes as $slug => $theme ) {
                $themes[] = [
                    'stylesheet'  => $slug,
                    'name'        => $theme->get( 'Name' ),
                    'version'     => $theme->get( 'Version' ),
                    'description' => $theme->get( 'Description' ),
                    'author'      => $theme->get( 'Author' ),
                    'theme_uri'   => $theme->get( 'ThemeURI' ),
                    'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                    'active'      => $slug === $active_stylesheet,
                ];
            }

            return [
                'themes' => $themes,
                'total'  => count( $themes ),
                'active' => $active_stylesheet,
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

    ],
];
