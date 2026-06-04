<?php
defined( 'ABSPATH' ) || exit;

/**
 * Format a WP_Post into a consistent response shape.
 * Superset of resource_single_post(): adds meta, terms, author_id, parent, menu_order.
 */
function cowboy_mcp_format_post( WP_Post $post ): array {
    $taxonomies = get_object_taxonomies( $post->post_type, 'names' );
    $terms      = [];
    foreach ( $taxonomies as $tax ) {
        $post_terms = wp_get_post_terms( $post->ID, $tax, [ 'fields' => 'all' ] );
        if ( ! is_wp_error( $post_terms ) && ! empty( $post_terms ) ) {
            $terms[ $tax ] = array_map( fn( $t ) => [
                'term_id' => $t->term_id,
                'name'    => $t->name,
                'slug'    => $t->slug,
            ], $post_terms );
        }
    }

    return [
        'ID'         => $post->ID,
        'title'      => $post->post_title,
        'slug'       => $post->post_name,
        'status'     => $post->post_status,
        'type'       => $post->post_type,
        'content'    => $post->post_content,
        'excerpt'    => $post->post_excerpt,
        'date'       => $post->post_date,
        'modified'   => $post->post_modified,
        'author'     => get_the_author_meta( 'display_name', $post->post_author ),
        'author_id'  => (int) $post->post_author,
        'parent'     => (int) $post->post_parent,
        'menu_order' => (int) $post->menu_order,
        'permalink'  => get_permalink( $post->ID ),
        'terms'      => $terms,
        'meta'       => get_post_meta( $post->ID ),
    ];
}

/**
 * Apply optional post fields from arguments into a wp_insert_post data array.
 * Uses isset() guards so callers can do partial updates.
 */
function cowboy_mcp_set_post_fields( array &$data, array $a ): void {
    if ( isset( $a['title'] ) )      $data['post_title']   = sanitize_text_field( $a['title'] );
    if ( isset( $a['content'] ) )    $data['post_content'] = wp_kses_post( $a['content'] );
    if ( isset( $a['excerpt'] ) )    $data['post_excerpt'] = sanitize_text_field( $a['excerpt'] );
    if ( isset( $a['status'] ) )     $data['post_status']  = sanitize_key( $a['status'] );
    if ( isset( $a['post_type'] ) )  $data['post_type']    = sanitize_key( $a['post_type'] );
    if ( isset( $a['slug'] ) )       $data['post_name']    = sanitize_title( $a['slug'] );
    if ( isset( $a['author'] ) )     $data['post_author']  = (int) $a['author'];
    if ( isset( $a['parent'] ) )     $data['post_parent']  = (int) $a['parent'];
    if ( isset( $a['menu_order'] ) ) $data['menu_order']   = (int) $a['menu_order'];
}

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_posts', '[Content] List posts, pages, or custom post types with filtering and pagination.', [
            'post_type' => [ 'type' => 'string',  'description' => 'Post type slug (default "post")', 'default' => 'post' ],
            'status'    => [ 'type' => 'string',  'description' => 'Post status: publish, draft, pending, trash, any (default "any")', 'default' => 'any' ],
            'author'    => [ 'type' => 'integer', 'description' => 'Filter by author user ID' ],
            'search'    => [ 'type' => 'string',  'description' => 'Search keyword' ],
            'category'  => [ 'type' => 'string',  'description' => 'Category slug to filter by' ],
            'tag'       => [ 'type' => 'string',  'description' => 'Tag slug to filter by' ],
            'orderby'   => [ 'type' => 'string',  'description' => 'Order by field (default "date")', 'default' => 'date', 'enum' => [ 'date', 'title', 'modified', 'ID', 'menu_order', 'rand' ] ],
            'order'     => [ 'type' => 'string',  'description' => 'Sort direction (default "DESC")', 'default' => 'DESC', 'enum' => [ 'ASC', 'DESC' ] ],
            'per_page'  => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default 20)', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
            'page'      => [ 'type' => 'integer', 'description' => 'Page number (default 1)', 'default' => 1, 'minimum' => 1 ],
        ], [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_get_post', '[Content] Get a single post, page, or custom post type by ID with full details including meta and terms.', [
            'post_id' => [ 'type' => 'integer', 'description' => 'Post ID', 'required' => true ],
        ], [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_create_post', '[Content] Create a new post, page, or custom post type.', [
            'title'      => [ 'type' => 'string',  'description' => 'Post title', 'required' => true ],
            'content'    => [ 'type' => 'string',  'description' => 'Post content (HTML allowed)' ],
            'excerpt'    => [ 'type' => 'string',  'description' => 'Post excerpt' ],
            'status'     => [ 'type' => 'string',  'description' => 'Post status (default "draft")', 'default' => 'draft', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'future' ] ],
            'post_type'  => [ 'type' => 'string',  'description' => 'Post type (default "post")', 'default' => 'post' ],
            'slug'       => [ 'type' => 'string',  'description' => 'Post slug' ],
            'author'     => [ 'type' => 'integer', 'description' => 'Author user ID' ],
            'parent'     => [ 'type' => 'integer', 'description' => 'Parent post ID (for hierarchical types)' ],
            'menu_order' => [ 'type' => 'integer', 'description' => 'Menu order' ],
            'categories' => [ 'type' => 'array',   'description' => 'Category IDs to assign', 'items' => [ 'type' => 'integer' ] ],
            'tags'       => [ 'type' => 'array',   'description' => 'Tag names or slugs to assign', 'items' => [ 'type' => 'string' ] ],
            'meta'       => [ 'type' => 'object',  'description' => 'Post meta key-value pairs to set' ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_update_post', '[Content] Update an existing post, page, or custom post type. Only provided fields are changed.', [
            'post_id'    => [ 'type' => 'integer', 'description' => 'Post ID to update', 'required' => true ],
            'title'      => [ 'type' => 'string',  'description' => 'New title' ],
            'content'    => [ 'type' => 'string',  'description' => 'New content (HTML allowed)' ],
            'excerpt'    => [ 'type' => 'string',  'description' => 'New excerpt' ],
            'status'     => [ 'type' => 'string',  'description' => 'New status', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash' ] ],
            'slug'       => [ 'type' => 'string',  'description' => 'New slug' ],
            'author'     => [ 'type' => 'integer', 'description' => 'New author user ID' ],
            'parent'     => [ 'type' => 'integer', 'description' => 'New parent post ID' ],
            'menu_order' => [ 'type' => 'integer', 'description' => 'New menu order' ],
            'categories' => [ 'type' => 'array',   'description' => 'Category IDs to set (replaces existing)', 'items' => [ 'type' => 'integer' ] ],
            'tags'       => [ 'type' => 'array',   'description' => 'Tag names or slugs to set (replaces existing)', 'items' => [ 'type' => 'string' ] ],
            'meta'       => [ 'type' => 'object',  'description' => 'Post meta key-value pairs to set or update' ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_delete_post', '[Content] Delete or trash a post, page, or custom post type.', [
            'post_id' => [ 'type' => 'integer', 'description' => 'Post ID to delete', 'required' => true ],
            'force'   => [ 'type' => 'boolean', 'description' => 'If true, permanently delete instead of trashing (default false)', 'default' => false ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [

        'wp_list_posts' => function ( array $a ): array {
            $per_page = min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );

            $query_args = [
                'post_type'      => sanitize_key( $a['post_type'] ?? 'post' ),
                'post_status'    => sanitize_key( $a['status'] ?? 'any' ),
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => sanitize_key( $a['orderby'] ?? 'date' ),
                'order'          => strtoupper( $a['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
            ];

            if ( isset( $a['author'] ) )   $query_args['author']        = (int) $a['author'];
            if ( isset( $a['search'] ) )   $query_args['s']             = sanitize_text_field( $a['search'] );
            if ( isset( $a['category'] ) ) $query_args['category_name'] = sanitize_text_field( $a['category'] );
            if ( isset( $a['tag'] ) )      $query_args['tag']           = sanitize_text_field( $a['tag'] );

            $query = new WP_Query( $query_args );

            $posts = array_map( function ( WP_Post $p ) {
                return [
                    'ID'        => $p->ID,
                    'title'     => $p->post_title,
                    'slug'      => $p->post_name,
                    'status'    => $p->post_status,
                    'type'      => $p->post_type,
                    'date'      => $p->post_date,
                    'modified'  => $p->post_modified,
                    'author'    => get_the_author_meta( 'display_name', $p->post_author ),
                    'author_id' => (int) $p->post_author,
                    'permalink' => get_permalink( $p->ID ),
                ];
            }, $query->posts );

            return [
                'posts'         => $posts,
                'total'         => (int) $query->found_posts,
                'pages'         => (int) $query->max_num_pages,
                'page'          => $page,
                'per_page'      => $per_page,
            ];
        },

        'wp_get_post' => function ( array $a ): array|WP_Error {
            $post = get_post( (int) $a['post_id'] );
            if ( ! $post ) {
                return new WP_Error( 'not_found', "Post {$a['post_id']} not found." );
            }
            return cowboy_mcp_format_post( $post );
        },

        'wp_create_post' => function ( array $a ): array|WP_Error {
            $data = [
                'post_status' => 'draft',
                'post_type'   => 'post',
            ];
            if ( isset( $a['author'] ) && ! get_userdata( (int) $a['author'] ) ) {
                return new WP_Error( 'invalid_param', "author {$a['author']} is not an existing user." );
            }
            cowboy_mcp_set_post_fields( $data, $a );

            // wp_slash() to counteract wp_insert_post's wp_unslash().
            $post_id = wp_insert_post( wp_slash( $data ), true );
            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }

            // Set categories.
            if ( isset( $a['categories'] ) && is_array( $a['categories'] ) ) {
                wp_set_post_categories( $post_id, array_map( 'intval', $a['categories'] ) );
            }

            // Set tags.
            if ( isset( $a['tags'] ) && is_array( $a['tags'] ) ) {
                wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $a['tags'] ) );
            }

            // Set meta (block internal/sensitive keys; report what was skipped).
            $rejected_meta = [];
            if ( isset( $a['meta'] ) && is_array( $a['meta'] ) ) {
                foreach ( $a['meta'] as $key => $value ) {
                    $key = sanitize_key( $key );
                    if ( Cowboy_MCP_Security::is_blocked_meta_key( $key ) ) {
                        $rejected_meta[] = $key;
                        continue;
                    }
                    update_post_meta( $post_id, $key, $value );
                }
            }

            $response = cowboy_mcp_format_post( get_post( $post_id ) );
            if ( ! empty( $rejected_meta ) ) {
                $response['rejected_meta_keys'] = $rejected_meta;
            }
            return $response;
        },

        'wp_update_post' => function ( array $a ): array|WP_Error {
            $post_id = (int) $a['post_id'];
            $post    = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'not_found', "Post {$post_id} not found." );
            }

            if ( isset( $a['author'] ) && ! get_userdata( (int) $a['author'] ) ) {
                return new WP_Error( 'invalid_param', "author {$a['author']} is not an existing user." );
            }

            $data = [ 'ID' => $post_id ];
            cowboy_mcp_set_post_fields( $data, $a );

            // Only call wp_update_post if there are fields beyond ID.
            if ( count( $data ) > 1 ) {
                $result = wp_update_post( wp_slash( $data ), true );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }

            // Update categories.
            if ( isset( $a['categories'] ) && is_array( $a['categories'] ) ) {
                wp_set_post_categories( $post_id, array_map( 'intval', $a['categories'] ) );
            }

            // Update tags.
            if ( isset( $a['tags'] ) && is_array( $a['tags'] ) ) {
                wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $a['tags'] ) );
            }

            // Update meta (block internal/sensitive keys; report what was skipped).
            $rejected_meta = [];
            if ( isset( $a['meta'] ) && is_array( $a['meta'] ) ) {
                foreach ( $a['meta'] as $key => $value ) {
                    $key = sanitize_key( $key );
                    if ( Cowboy_MCP_Security::is_blocked_meta_key( $key ) ) {
                        $rejected_meta[] = $key;
                        continue;
                    }
                    update_post_meta( $post_id, $key, $value );
                }
            }

            $response = cowboy_mcp_format_post( get_post( $post_id ) );
            if ( ! empty( $rejected_meta ) ) {
                $response['rejected_meta_keys'] = $rejected_meta;
            }
            return $response;
        },

        'wp_delete_post' => function ( array $a ): array|WP_Error {
            $post_id = (int) $a['post_id'];
            $post    = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'not_found', "Post {$post_id} not found." );
            }

            $force = ! empty( $a['force'] );
            $title = $post->post_title;

            if ( $force ) {
                $result = wp_delete_post( $post_id, true );
            } else {
                $result = wp_trash_post( $post_id );
            }

            if ( ! $result ) {
                return new WP_Error( 'delete_failed', "Failed to delete post {$post_id}." );
            }

            return [
                'deleted'   => true,
                'post_id'   => $post_id,
                'title'     => $title,
                'trashed'   => ! $force,
                'permanent' => $force,
            ];
        },

    ],
];
