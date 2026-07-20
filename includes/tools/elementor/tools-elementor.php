<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Guard — return empty when Elementor is not active.
 * ================================================================ */

if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Helpers
 * ================================================================ */

/**
 * Decode Elementor content from post meta.
 *
 * @return array|WP_Error Parsed element array or error.
 */
function cowboy_mcp_elementor_get_content( int $post_id ): array|WP_Error {
    $raw = get_post_meta( $post_id, '_elementor_data', true );
    if ( empty( $raw ) ) {
        return new WP_Error( 'no_content', "Post #{$post_id} has no Elementor content." );
    }

    $data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_data', "Failed to decode Elementor content for post #{$post_id}." );
    }

    return $data;
}

/**
 * Save Elementor content to post meta and clear CSS cache.
 */
function cowboy_mcp_elementor_save_content( int $post_id, array $data ): bool|WP_Error {
    $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
    if ( $json === false ) {
        return new WP_Error( 'encode_error', 'Failed to encode Elementor data to JSON.' );
    }

    update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

    // Trigger CSS regeneration.
    if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }

    return true;
}

/**
 * Recursively validate that all elements in the tree have elType keys.
 */
function cowboy_mcp_elementor_validate_elements( array $elements, bool $allow_unfiltered = false, string $path = '' ): ?WP_Error {
    foreach ( $elements as $i => $el ) {
        $current_path = $path ? "{$path}[{$i}]" : "index {$i}";
        if ( ! isset( $el['elType'] ) ) {
            return new WP_Error( 'invalid_params', "Element at {$current_path} is missing required 'elType' key." );
        }
        // Raw HTML / custom-code settings render verbatim on the front end (stored XSS).
        // Require an explicit opt-in before writing them.
        if ( ! $allow_unfiltered && ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
            foreach ( [ 'html', 'custom_css', 'custom_code', 'code' ] as $danger ) {
                if ( ! empty( $el['settings'][ $danger ] ) ) {
                    return new WP_Error(
                        'unfiltered_html_blocked',
                        "Element at {$current_path} contains raw '{$danger}' content. Pass allow_unfiltered_html: true to permit it (this writes unfiltered HTML/JS that runs on the front end)."
                    );
                }
            }
        }
        if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
            $nested_error = cowboy_mcp_elementor_validate_elements( $el['elements'], $allow_unfiltered, $current_path );
            if ( $nested_error ) return $nested_error;
        }
    }
    return null;
}

/**
 * Summarize an Elementor element tree for readability.
 * Strips heavy settings, preserves structure.
 */
function cowboy_mcp_elementor_summarize( array $elements, int $depth = 0 ): array {
    $summary = [];
    foreach ( $elements as $el ) {
        $item = [
            'id'     => $el['id'] ?? '',
            'elType' => $el['elType'] ?? 'unknown',
        ];

        if ( ! empty( $el['widgetType'] ) ) {
            $item['widgetType'] = $el['widgetType'];
        }

        // Include a few key settings for context.
        if ( ! empty( $el['settings'] ) ) {
            $key_settings = [];
            $keys_of_interest = [ 'title', 'editor', 'text', 'heading_tag', 'link', 'image', 'html', 'css_classes', '_element_id' ];
            foreach ( $keys_of_interest as $key ) {
                if ( isset( $el['settings'][ $key ] ) ) {
                    $val = $el['settings'][ $key ];
                    // Truncate long text values.
                    if ( is_string( $val ) && strlen( $val ) > 200 ) {
                        $val = substr( $val, 0, 200 ) . '…';
                    }
                    $key_settings[ $key ] = $val;
                }
            }
            if ( ! empty( $key_settings ) ) {
                $item['settings'] = $key_settings;
            }
        }

        if ( ! empty( $el['elements'] ) ) {
            $item['elements'] = cowboy_mcp_elementor_summarize( $el['elements'], $depth + 1 );
        }

        $summary[] = $item;
    }
    return $summary;
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_elementor_list_templates', '[Elementor] List Elementor templates, optionally filtered by type (page, section, popup, header, footer, etc.).', [
            'type'   => [ 'type' => 'string', 'description' => 'Template type filter (e.g. page, section, popup, header, footer, single, archive)' ],
            'limit'  => [ 'type' => 'integer', 'description' => 'Max templates to return', 'default' => 50 ],
            'offset' => [ 'type' => 'integer', 'description' => 'Number of templates to skip', 'default' => 0 ],
        ], [
            'title'           => 'List Elementor Templates',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'total'     => [ 'type' => 'integer' ],
                'count'     => [ 'type' => 'integer' ],
                'offset'    => [ 'type' => 'integer' ],
                'templates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'           => [ 'type' => 'integer' ],
                            'title'        => [ 'type' => 'string' ],
                            'type'         => [ 'type' => 'string' ],
                            'date_created' => [ 'type' => 'string' ],
                            'date_updated' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_elementor_get_template', '[Elementor] Get an Elementor template\'s element structure. Use summarize=true for a simplified overview.', [
            'template_id' => [ 'type' => 'integer', 'description' => 'Template post ID', 'required' => true ],
            'summarize'   => [ 'type' => 'boolean', 'description' => 'Return simplified element tree', 'default' => false ],
        ], [
            'title'           => 'Get Elementor Template',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_elementor_update_template', '[Elementor] Update an Elementor template\'s content. Elements must have valid elType keys. Triggers CSS cache regeneration.', [
            'template_id' => [ 'type' => 'integer', 'description' => 'Template post ID', 'required' => true ],
            'elements'    => [ 'type' => 'array', 'description' => 'Array of Elementor element objects with elType, settings, elements keys', 'required' => true ],
            'allow_unfiltered_html' => [ 'type' => 'boolean', 'description' => 'Permit raw HTML / custom-code element settings (e.g. the HTML widget, custom CSS). Default false; these render verbatim on the front end and can introduce stored XSS.', 'default' => false ],
        ], [
            'title'           => 'Update Elementor Template',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_elementor_get_page_content', '[Elementor] Get Elementor content for any post or page. Only returns content if the post uses the Elementor builder.', [
            'post_id'   => [ 'type' => 'integer', 'description' => 'Post/page ID', 'required' => true ],
            'summarize' => [ 'type' => 'boolean', 'description' => 'Return simplified element tree', 'default' => false ],
        ], [
            'title'           => 'Get Elementor Page Content',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'post_id'   => [ 'type' => 'integer' ],
                'title'     => [ 'type' => 'string' ],
                'post_type' => [ 'type' => 'string' ],
                'elements'  => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
            ],
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_elementor_get_global_styles', '[Elementor] Get global colors and typography from the active Elementor kit.', [], [
            'title'           => 'Get Global Styles',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_elementor_update_global_styles', '[Elementor] Update global colors and/or typography in the active Elementor kit. Triggers CSS cache regeneration.', [
            'colors'     => [ 'type' => 'array', 'description' => 'Array of color objects with _id, title, color keys', 'items' => [ 'type' => 'object' ] ],
            'typography' => [ 'type' => 'array', 'description' => 'Array of typography objects with _id, title, and typography settings', 'items' => [ 'type' => 'object' ] ],
        ], [
            'title'           => 'Update Global Styles',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_elementor_list_widgets', '[Elementor] List all available Elementor widget types with their categories.', [], [
            'title'           => 'List Elementor Widgets',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [
        /* ---------- List Templates ---------- */

        'wp_elementor_list_templates' => function ( array $a ): array|WP_Error {
            $limit  = max( 1, min( 200, (int) ( $a['limit'] ?? 50 ) ) );
            $offset = max( 0, (int) ( $a['offset'] ?? 0 ) );

            $query_args = [
                'post_type'      => 'elementor_library',
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
            ];

            if ( ! empty( $a['type'] ) ) {
                $query_args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    [
                        'taxonomy' => 'elementor_library_type',
                        'field'    => 'slug',
                        'terms'    => sanitize_text_field( $a['type'] ),
                    ],
                ];
            }

            $query     = new WP_Query( $query_args );
            $templates = [];

            foreach ( $query->posts as $post ) {
                $type_terms = wp_get_post_terms( $post->ID, 'elementor_library_type', [ 'fields' => 'slugs' ] );
                $templates[] = [
                    'id'           => $post->ID,
                    'title'        => $post->post_title,
                    'type'         => is_array( $type_terms ) && ! empty( $type_terms ) ? $type_terms[0] : 'unknown',
                    'date_created' => $post->post_date,
                    'date_updated' => $post->post_modified,
                ];
            }

            return [
                'total'     => (int) $query->found_posts,
                'count'     => count( $templates ),
                'offset'    => $offset,
                'templates' => $templates,
            ];
        },

        /* ---------- Get Template ---------- */

        'wp_elementor_get_template' => function ( array $a ): array|WP_Error {
            $template_id = (int) ( $a['template_id'] ?? 0 );
            $post        = get_post( $template_id );
            if ( ! $post || $post->post_type !== 'elementor_library' ) {
                return new WP_Error( 'not_found', "Elementor template #{$template_id} not found." );
            }

            $data = cowboy_mcp_elementor_get_content( $template_id );
            if ( is_wp_error( $data ) ) return $data;

            $summarize = ! empty( $a['summarize'] );
            $type_terms = wp_get_post_terms( $template_id, 'elementor_library_type', [ 'fields' => 'slugs' ] );

            return [
                'template_id' => $template_id,
                'title'       => $post->post_title,
                'type'        => is_array( $type_terms ) && ! empty( $type_terms ) ? $type_terms[0] : 'unknown',
                'elements'    => $summarize ? cowboy_mcp_elementor_summarize( $data ) : $data,
            ];
        },

        /* ---------- Update Template ---------- */

        'wp_elementor_update_template' => function ( array $a ): array|WP_Error {
            $template_id = (int) ( $a['template_id'] ?? 0 );
            $post        = get_post( $template_id );
            if ( ! $post || $post->post_type !== 'elementor_library' ) {
                return new WP_Error( 'not_found', "Elementor template #{$template_id} not found." );
            }

            $elements = $a['elements'] ?? [];
            if ( ! is_array( $elements ) || empty( $elements ) ) {
                return new WP_Error( 'invalid_params', 'elements must be a non-empty array of Elementor element objects.' );
            }

            // Validate that all elements (including nested) have elType, and block raw
            // HTML/custom-code settings unless the caller explicitly opts in.
            $allow_unfiltered = ! empty( $a['allow_unfiltered_html'] );
            $validation_error = cowboy_mcp_elementor_validate_elements( $elements, $allow_unfiltered );
            if ( $validation_error ) {
                return $validation_error;
            }

            $result = cowboy_mcp_elementor_save_content( $template_id, $elements );
            if ( is_wp_error( $result ) ) return $result;

            return [
                'updated'     => true,
                'template_id' => $template_id,
                'title'       => $post->post_title,
            ];
        },

        /* ---------- Get Page Content ---------- */

        'wp_elementor_get_page_content' => function ( array $a ): array|WP_Error {
            $post_id = (int) ( $a['post_id'] ?? 0 );
            $post    = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'not_found', "Post #{$post_id} not found." );
            }

            $edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
            if ( $edit_mode !== 'builder' ) {
                return new WP_Error( 'not_elementor', "Post #{$post_id} is not using the Elementor builder." );
            }

            $data = cowboy_mcp_elementor_get_content( $post_id );
            if ( is_wp_error( $data ) ) return $data;

            $summarize = ! empty( $a['summarize'] );

            return [
                'post_id'   => $post_id,
                'title'     => $post->post_title,
                'post_type' => $post->post_type,
                'elements'  => $summarize ? cowboy_mcp_elementor_summarize( $data ) : $data,
            ];
        },

        /* ---------- Get Global Styles ---------- */

        'wp_elementor_get_global_styles' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->kits_manager ) ) {
                return new WP_Error( 'elementor_error', 'Elementor kits manager not available.' );
            }

            $kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();
            if ( ! $kit_id ) {
                return new WP_Error( 'elementor_error', 'No active Elementor kit found.' );
            }

            $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
            if ( ! is_array( $settings ) ) {
                $settings = [];
            }

            return [
                'kit_id'     => $kit_id,
                'colors'     => $settings['custom_colors'] ?? $settings['system_colors'] ?? [],
                'typography' => $settings['custom_typography'] ?? $settings['system_typography'] ?? [],
            ];
        },

        /* ---------- Update Global Styles ---------- */

        'wp_elementor_update_global_styles' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->kits_manager ) ) {
                return new WP_Error( 'elementor_error', 'Elementor kits manager not available.' );
            }

            $kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();
            if ( ! $kit_id ) {
                return new WP_Error( 'elementor_error', 'No active Elementor kit found.' );
            }

            $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
            if ( ! is_array( $settings ) ) {
                $settings = [];
            }

            $updated = [];

            if ( isset( $a['colors'] ) && is_array( $a['colors'] ) ) {
                $settings['custom_colors'] = $a['colors'];
                $updated[] = 'colors';
            }

            if ( isset( $a['typography'] ) && is_array( $a['typography'] ) ) {
                $settings['custom_typography'] = $a['typography'];
                $updated[] = 'typography';
            }

            if ( empty( $updated ) ) {
                return new WP_Error( 'no_changes', 'Supply at least one of: colors, typography.' );
            }

            update_post_meta( $kit_id, '_elementor_page_settings', $settings );

            // Clear CSS cache.
            if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }

            return [
                'updated' => true,
                'kit_id'  => $kit_id,
                'changed' => $updated,
            ];
        },

        /* ---------- List Widgets ---------- */

        'wp_elementor_list_widgets' => function ( array $a ): array|WP_Error {
            if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
                return new WP_Error( 'elementor_error', 'Elementor widgets manager not available.' );
            }

            $widget_types = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
            $widgets      = [];

            foreach ( $widget_types as $name => $widget ) {
                $widgets[] = [
                    'name'       => $name,
                    'title'      => method_exists( $widget, 'get_title' ) ? $widget->get_title() : $name,
                    'icon'       => method_exists( $widget, 'get_icon' ) ? $widget->get_icon() : '',
                    'categories' => method_exists( $widget, 'get_categories' ) ? $widget->get_categories() : [],
                ];
            }

            return [
                'count'   => count( $widgets ),
                'widgets' => $widgets,
            ];
        },
    ],
];
