<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_terms', '[Taxonomies] List taxonomy terms with filtering and pagination.', [
            'taxonomy'   => [ 'type' => 'string',  'description' => 'Taxonomy slug (e.g. category, post_tag, or custom)', 'required' => true ],
            'search'     => [ 'type' => 'string',  'description' => 'Search keyword' ],
            'parent'     => [ 'type' => 'integer', 'description' => 'Parent term ID (0 for top-level only)' ],
            'hide_empty' => [ 'type' => 'boolean', 'description' => 'Hide terms with no posts (default false)', 'default' => false ],
            'per_page'   => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default 50)', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            'page'       => [ 'type' => 'integer', 'description' => 'Page number (default 1)', 'default' => 1, 'minimum' => 1 ],
        ], [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_create_term', '[Taxonomies] Create a new taxonomy term.', [
            'taxonomy'    => [ 'type' => 'string',  'description' => 'Taxonomy slug', 'required' => true ],
            'name'        => [ 'type' => 'string',  'description' => 'Term name', 'required' => true ],
            'slug'        => [ 'type' => 'string',  'description' => 'Term slug' ],
            'description' => [ 'type' => 'string',  'description' => 'Term description' ],
            'parent'      => [ 'type' => 'integer', 'description' => 'Parent term ID' ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_update_term', '[Taxonomies] Update an existing taxonomy term. Only provided fields are changed.', [
            'taxonomy'    => [ 'type' => 'string',  'description' => 'Taxonomy slug', 'required' => true ],
            'term_id'     => [ 'type' => 'integer', 'description' => 'Term ID to update', 'required' => true ],
            'name'        => [ 'type' => 'string',  'description' => 'New term name' ],
            'slug'        => [ 'type' => 'string',  'description' => 'New term slug' ],
            'description' => [ 'type' => 'string',  'description' => 'New term description' ],
            'parent'      => [ 'type' => 'integer', 'description' => 'New parent term ID' ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_delete_term', '[Taxonomies] Delete a taxonomy term.', [
            'taxonomy' => [ 'type' => 'string',  'description' => 'Taxonomy slug', 'required' => true ],
            'term_id'  => [ 'type' => 'integer', 'description' => 'Term ID to delete', 'required' => true ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [

        'wp_list_terms' => function ( array $a ): array|WP_Error {
            $taxonomy = sanitize_key( $a['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                return new WP_Error( 'invalid_params', "Taxonomy '{$taxonomy}' does not exist." );
            }

            $per_page = min( max( (int) ( $a['per_page'] ?? 50 ), 1 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );
            $offset   = ( $page - 1 ) * $per_page;

            $term_args = [
                'taxonomy'   => $taxonomy,
                'number'     => $per_page,
                'offset'     => $offset,
                'hide_empty' => ! empty( $a['hide_empty'] ),
            ];

            if ( isset( $a['search'] ) ) $term_args['search']     = sanitize_text_field( $a['search'] );
            if ( isset( $a['parent'] ) ) $term_args['parent']     = (int) $a['parent'];

            $terms = get_terms( $term_args );
            if ( is_wp_error( $terms ) ) {
                return $terms;
            }

            // Separate count query for total.
            $count_args = $term_args;
            unset( $count_args['number'], $count_args['offset'] );
            $count_args['fields'] = 'count';
            $total = (int) get_terms( $count_args );

            $formatted = array_map( fn( WP_Term $t ) => [
                'term_id'     => $t->term_id,
                'name'        => $t->name,
                'slug'        => $t->slug,
                'description' => $t->description,
                'parent'      => $t->parent,
                'count'       => $t->count,
            ], $terms );

            return [
                'terms'    => $formatted,
                'total'    => $total,
                'pages'    => (int) ceil( $total / $per_page ),
                'page'     => $page,
                'per_page' => $per_page,
            ];
        },

        'wp_create_term' => function ( array $a ): array|WP_Error {
            $taxonomy = sanitize_key( $a['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                return new WP_Error( 'invalid_params', "Taxonomy '{$taxonomy}' does not exist." );
            }

            $term_args = [];
            if ( isset( $a['slug'] ) )        $term_args['slug']        = sanitize_title( $a['slug'] );
            if ( isset( $a['description'] ) ) $term_args['description'] = sanitize_text_field( $a['description'] );
            if ( isset( $a['parent'] ) )      $term_args['parent']      = (int) $a['parent'];

            $result = wp_insert_term( sanitize_text_field( $a['name'] ), $taxonomy, $term_args );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $term = get_term( $result['term_id'], $taxonomy );
            return [
                'term_id'     => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent'      => $term->parent,
                'taxonomy'    => $term->taxonomy,
            ];
        },

        'wp_update_term' => function ( array $a ): array|WP_Error {
            $taxonomy = sanitize_key( $a['taxonomy'] );
            $term_id  = (int) $a['term_id'];

            if ( ! taxonomy_exists( $taxonomy ) ) {
                return new WP_Error( 'invalid_params', "Taxonomy '{$taxonomy}' does not exist." );
            }

            $existing = get_term( $term_id, $taxonomy );
            if ( ! $existing || is_wp_error( $existing ) ) {
                return new WP_Error( 'not_found', "Term {$term_id} not found in taxonomy '{$taxonomy}'." );
            }

            $term_args = [];
            if ( isset( $a['name'] ) )        $term_args['name']        = sanitize_text_field( $a['name'] );
            if ( isset( $a['slug'] ) )        $term_args['slug']        = sanitize_title( $a['slug'] );
            if ( isset( $a['description'] ) ) $term_args['description'] = sanitize_text_field( $a['description'] );
            if ( isset( $a['parent'] ) )      $term_args['parent']      = (int) $a['parent'];

            if ( empty( $term_args ) ) {
                return new WP_Error( 'invalid_params', 'No fields provided to update.' );
            }

            $result = wp_update_term( $term_id, $taxonomy, $term_args );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $term = get_term( $result['term_id'], $taxonomy );
            return [
                'term_id'     => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent'      => $term->parent,
                'taxonomy'    => $term->taxonomy,
            ];
        },

        'wp_delete_term' => function ( array $a ): array|WP_Error {
            $taxonomy = sanitize_key( $a['taxonomy'] );
            $term_id  = (int) $a['term_id'];

            if ( ! taxonomy_exists( $taxonomy ) ) {
                return new WP_Error( 'invalid_params', "Taxonomy '{$taxonomy}' does not exist." );
            }

            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                return new WP_Error( 'not_found', "Term {$term_id} not found in taxonomy '{$taxonomy}'." );
            }

            $name   = $term->name;
            $result = wp_delete_term( $term_id, $taxonomy );

            if ( is_wp_error( $result ) ) {
                return $result;
            }
            if ( $result === false ) {
                return new WP_Error( 'delete_failed', "Failed to delete term {$term_id}." );
            }

            return [
                'deleted'  => true,
                'term_id'  => $term_id,
                'name'     => $name,
                'taxonomy' => $taxonomy,
            ];
        },

    ],
];
