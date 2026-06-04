<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_comments', '[Comments] List comments with filtering and pagination.', [
            'post_id'      => [ 'type' => 'integer', 'description' => 'Filter by post ID' ],
            'status'       => [ 'type' => 'string',  'description' => 'Comment status: approve, hold, spam, trash, all (default "all")', 'default' => 'all', 'enum' => [ 'approve', 'hold', 'spam', 'trash', 'all' ] ],
            'author_email' => [ 'type' => 'string',  'description' => 'Filter by author email' ],
            'per_page'     => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default 20)', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
            'page'         => [ 'type' => 'integer', 'description' => 'Page number (default 1)', 'default' => 1, 'minimum' => 1 ],
        ], [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_create_comment', '[Comments] Create a new comment on a post.', [
            'post_id'      => [ 'type' => 'integer', 'description' => 'Post ID to comment on', 'required' => true ],
            'content'      => [ 'type' => 'string',  'description' => 'Comment content', 'required' => true ],
            'author'       => [ 'type' => 'string',  'description' => 'Comment author name' ],
            'author_email' => [ 'type' => 'string',  'description' => 'Comment author email' ],
            'author_url'   => [ 'type' => 'string',  'description' => 'Comment author URL' ],
            'parent'       => [ 'type' => 'integer', 'description' => 'Parent comment ID for threaded replies' ],
            'status'       => [ 'type' => 'string',  'description' => 'Optional moderation override (approve, hold, spam). Omit to let the site\'s moderation policy, disallowed-keys list and anti-spam decide.', 'enum' => [ 'approve', 'hold', 'spam' ] ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_update_comment', '[Comments] Update an existing comment. Only provided fields are changed.', [
            'comment_id'   => [ 'type' => 'integer', 'description' => 'Comment ID to update', 'required' => true ],
            'content'      => [ 'type' => 'string',  'description' => 'New comment content' ],
            'author'       => [ 'type' => 'string',  'description' => 'New author name' ],
            'author_email' => [ 'type' => 'string',  'description' => 'New author email' ],
            'author_url'   => [ 'type' => 'string',  'description' => 'New author URL' ],
            'status'       => [ 'type' => 'string',  'description' => 'New status', 'enum' => [ 'approve', 'hold', 'spam', 'trash' ] ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_delete_comment', '[Comments] Delete or trash a comment.', [
            'comment_id' => [ 'type' => 'integer', 'description' => 'Comment ID to delete', 'required' => true ],
            'force'      => [ 'type' => 'boolean', 'description' => 'If true, permanently delete instead of trashing (default false)', 'default' => false ],
        ], [
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [

        'wp_list_comments' => function ( array $a ): array {
            $per_page = min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );
            $offset   = ( $page - 1 ) * $per_page;

            $comment_args = [
                'number' => $per_page,
                'offset' => $offset,
                'status' => $a['status'] ?? 'all',
            ];

            if ( isset( $a['post_id'] ) )      $comment_args['post_id']      = (int) $a['post_id'];
            if ( isset( $a['author_email'] ) )  $comment_args['author_email'] = sanitize_email( $a['author_email'] );

            $comments = get_comments( $comment_args );

            // Separate count query for total.
            $count_args = $comment_args;
            unset( $count_args['number'], $count_args['offset'] );
            $count_args['count'] = true;
            $total = (int) get_comments( $count_args );

            $formatted = array_map( fn( WP_Comment $c ) => [
                'comment_id'   => (int) $c->comment_ID,
                'post_id'      => (int) $c->comment_post_ID,
                'author'       => $c->comment_author,
                'author_email' => $c->comment_author_email,
                'author_url'   => $c->comment_author_url,
                'content'      => $c->comment_content,
                'date'         => $c->comment_date,
                'status'       => wp_get_comment_status( $c ),
                'parent'       => (int) $c->comment_parent,
                'type'         => $c->comment_type ?: 'comment',
            ], $comments );

            return [
                'comments' => $formatted,
                'total'    => $total,
                'pages'    => (int) ceil( $total / $per_page ),
                'page'     => $page,
                'per_page' => $per_page,
            ];
        },

        'wp_create_comment' => function ( array $a ): array|WP_Error {
            $post_id = (int) $a['post_id'];
            $post    = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'not_found', "Post {$a['post_id']} not found." );
            }
            if ( ! comments_open( $post_id ) ) {
                return new WP_Error( 'closed', "Comments are closed for post {$post_id}." );
            }
            if ( isset( $a['author_email'] ) && ! is_email( $a['author_email'] ) ) {
                return new WP_Error( 'invalid_param', 'author_email is not a valid email address.' );
            }

            $comment_data = [
                'comment_post_ID' => $post_id,
                'comment_content' => wp_kses_post( $a['content'] ),
            ];

            if ( isset( $a['author'] ) )       $comment_data['comment_author']       = sanitize_text_field( $a['author'] );
            if ( isset( $a['author_email'] ) )  $comment_data['comment_author_email'] = sanitize_email( $a['author_email'] );
            if ( isset( $a['author_url'] ) )    $comment_data['comment_author_url']   = esc_url_raw( $a['author_url'] );
            if ( isset( $a['parent'] ) )        $comment_data['comment_parent']       = (int) $a['parent'];

            // Route through wp_new_comment (not wp_insert_comment) so the disallowed-keys
            // list, flood checks, Akismet and the site's moderation policy all run and
            // decide the initial approval — rather than blindly auto-approving.
            $comment_id = wp_new_comment( $comment_data, true );
            if ( is_wp_error( $comment_id ) ) {
                return $comment_id;
            }
            if ( ! $comment_id ) {
                return new WP_Error( 'create_failed', 'Failed to create comment.' );
            }

            // An explicit status is treated as a deliberate moderator override.
            if ( isset( $a['status'] ) && in_array( $a['status'], [ 'approve', 'hold', 'spam', 'trash' ], true ) ) {
                wp_set_comment_status( $comment_id, $a['status'] );
            }

            $comment = get_comment( $comment_id );
            return [
                'comment_id'   => (int) $comment->comment_ID,
                'post_id'      => (int) $comment->comment_post_ID,
                'author'       => $comment->comment_author,
                'author_email' => $comment->comment_author_email,
                'content'      => $comment->comment_content,
                'date'         => $comment->comment_date,
                'status'       => wp_get_comment_status( $comment ),
            ];
        },

        'wp_update_comment' => function ( array $a ): array|WP_Error {
            $comment_id = (int) $a['comment_id'];
            $comment    = get_comment( $comment_id );
            if ( ! $comment ) {
                return new WP_Error( 'not_found', "Comment {$comment_id} not found." );
            }

            $comment_data = [ 'comment_ID' => $comment_id ];

            if ( isset( $a['content'] ) )      $comment_data['comment_content']      = wp_kses_post( $a['content'] );
            if ( isset( $a['author'] ) )       $comment_data['comment_author']       = sanitize_text_field( $a['author'] );
            if ( isset( $a['author_email'] ) ) $comment_data['comment_author_email'] = sanitize_email( $a['author_email'] );
            if ( isset( $a['author_url'] ) )   $comment_data['comment_author_url']   = esc_url_raw( $a['author_url'] );

            // Map status string to WP's numeric approved field.
            if ( isset( $a['status'] ) ) {
                $status_map = [ 'approve' => 1, 'hold' => 0, 'spam' => 'spam', 'trash' => 'trash' ];
                $comment_data['comment_approved'] = $status_map[ $a['status'] ] ?? 1;
            }

            if ( count( $comment_data ) <= 1 ) {
                return new WP_Error( 'invalid_params', 'No fields provided to update.' );
            }

            $result = wp_update_comment( $comment_data, true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            if ( $result === 0 ) {
                return new WP_Error( 'update_failed', "Failed to update comment {$comment_id}." );
            }

            $updated = get_comment( $comment_id );
            return [
                'comment_id'   => (int) $updated->comment_ID,
                'post_id'      => (int) $updated->comment_post_ID,
                'author'       => $updated->comment_author,
                'author_email' => $updated->comment_author_email,
                'content'      => $updated->comment_content,
                'date'         => $updated->comment_date,
                'status'       => wp_get_comment_status( $updated ),
            ];
        },

        'wp_delete_comment' => function ( array $a ): array|WP_Error {
            $comment_id = (int) $a['comment_id'];
            $comment    = get_comment( $comment_id );
            if ( ! $comment ) {
                return new WP_Error( 'not_found', "Comment {$comment_id} not found." );
            }

            $force = ! empty( $a['force'] );

            $result = wp_delete_comment( $comment_id, $force );
            if ( ! $result ) {
                return new WP_Error( 'delete_failed', "Failed to delete comment {$comment_id}." );
            }

            return [
                'deleted'    => true,
                'comment_id' => $comment_id,
                'trashed'    => ! $force,
                'permanent'  => $force,
            ];
        },

    ],
];
