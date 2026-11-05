<?php
/**
 * Drafts-only access level: a credential scope that may read everything,
 * create unpublished content and propose changes to live posts as draft copies.
 *
 * @package Cowboy_MCP
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Drafts {

    /** Statuses that count as unpublished. */
    const UNPUBLISHED = [ 'draft', 'pending', 'auto-draft' ];

    /** Statuses a drafts credential may set. */
    const SETTABLE = [ 'draft', 'pending' ];

    /** Tools that create a post (status forced to a settable one by the gate). */
    const CREATE_TOOLS = [ 'wp_create_post' ];

    /** Other non-read-only tools a drafts credential may call. */
    const OTHER_ALLOWED = [ 'wp_upload_media', 'wp_batch_execute', 'wp_undo_change' ];

    /** Write tools that edit an existing post: tool => argument naming the target. */
    const WRITE_TARGETS = [
        'wp_update_post'                      => 'post_id',
        'wp_edit_blocks'                      => 'post_id',
        'wp_restore_revision'                 => 'revision_id',
        'wp_seo_update_meta'                  => 'post_id',
        'wp_acf_update_field'                 => 'object_id',
        'wp_acf_add_row'                      => 'object_id',
        'wp_acf_update_row'                   => 'object_id',
        'wp_beaver_update_layout'             => 'post_id',
        'wp_siteorigin_update_layout'         => 'post_id',
        'wp_siteorigin_edit_layout'           => 'post_id',
        'wp_siteorigin_apply_prebuilt_layout' => 'post_id',
    ];

    /** Read tools that take a target post: tool => argument. */
    const READ_TARGETS = [
        'wp_get_post'                  => 'post_id',
        'wp_list_blocks'               => 'post_id',
        'wp_seo_get_meta'              => 'post_id',
        'wp_acf_get_field'             => 'object_id',
        'wp_acf_get_fields'            => 'object_id',
        'wp_beaver_get_layout'         => 'post_id',
        'wp_siteorigin_get_layout'     => 'post_id',
        'wp_elementor_get_page_content' => 'post_id',
    ];

    /** Post types a drafts credential may never create or edit. */
    const DENIED_TYPES = [
        'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon',
        'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_block', 'attachment', 'revision', 'customize_changeset', 'oembed_cache',
        'user_request', 'wp_font_family', 'wp_font_face',
    ];

    /** Types that are fine while unpublished but unsafe to copy or edit when live. */
    const LIVE_UNSAFE_TYPES = [ 'tribe_events', 'tribe_venue', 'tribe_organizer' ];

    /** Bootstrap. Nothing is registered yet. */
    public static function init(): void {
    }

    /** Whether the active credential is a drafts-only one. */
    public static function active(): bool {
        $scope = Cowboy_MCP_Security::current_scope();
        return null !== $scope && 'drafts' === ( $scope['mode'] ?? '' );
    }

    /**
     * Whether a drafts credential may call a tool at all. Inbound abilities
     * (names with a slash) count only when explicitly read-only.
     */
    public static function tool_allowed( string $name, array $annotations ): bool {
        if ( ! empty( $annotations['readOnlyHint'] ) ) {
            return true;
        }
        if ( str_contains( $name, '/' ) ) {
            return false;
        }
        return isset( self::WRITE_TARGETS[ $name ] )
            || in_array( $name, self::CREATE_TOOLS, true )
            || in_array( $name, self::OTHER_ALLOWED, true );
    }

    /**
     * Judge one call of a drafts credential before it runs.
     *
     * @return array{args: array, ctx: ?array, preview: ?array}|WP_Error
     */
    public static function gate( string $name, array $args ): array|WP_Error {
        $out = [ 'args' => $args, 'ctx' => null, 'preview' => null ];
        if ( ! self::active() ) {
            return $out;
        }

        if ( in_array( $name, self::CREATE_TOOLS, true ) ) {
            $checked = self::gate_create( $args );
            if ( is_wp_error( $checked ) ) {
                return $checked;
            }
            $out['args'] = $checked;
            return $out;
        }

        if ( isset( self::WRITE_TARGETS[ $name ] ) ) {
            $checked = self::gate_target( $name, $args );
            if ( is_wp_error( $checked ) ) {
                return $checked;
            }
            $out['args'] = $checked;
        }
        return $out;
    }

    /** Normalise and judge a requested status (as the handlers read it). */
    private static function gate_status( array $args ): array|WP_Error {
        if ( ! array_key_exists( 'status', $args ) || null === $args['status'] ) {
            return $args;
        }
        $status = is_scalar( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
        if ( ! in_array( $status, self::SETTABLE, true ) ) {
            return new WP_Error( 'drafts_status', 'This credential is drafts-only: status must be draft or pending. A person publishes from wp-admin.' );
        }
        $args['status'] = $status;
        return $args;
    }

    /** Every tag must already exist: creating terms changes the live taxonomy. */
    private static function gate_tags( array $args ): ?WP_Error {
        if ( ! isset( $args['tags'] ) || ! is_array( $args['tags'] ) ) {
            return null;
        }
        foreach ( $args['tags'] as $tag ) {
            $tag = is_scalar( $tag ) ? sanitize_text_field( (string) $tag ) : '';
            if ( '' === $tag || ! term_exists( $tag, 'post_tag' ) ) {
                return new WP_Error( 'drafts_new_tag', 'This credential is drafts-only: tag "' . $tag . '" does not exist and creating terms is not allowed. Use an existing tag or leave tags out.' );
            }
        }
        return null;
    }

    private static function type_error( string $type ): ?WP_Error {
        if ( in_array( $type, self::DENIED_TYPES, true ) ) {
            return new WP_Error( 'drafts_post_type', "This credential is drafts-only: post type {$type} is not available. It is site-wide configuration, not content." );
        }
        return null;
    }

    private static function gate_create( array $args ): array|WP_Error {
        if ( ! array_key_exists( 'status', $args ) || null === $args['status'] ) {
            $args['status'] = 'draft';
        }
        $args = self::gate_status( $args );
        if ( is_wp_error( $args ) ) {
            return $args;
        }
        $type = isset( $args['post_type'] ) && is_scalar( $args['post_type'] ) ? sanitize_key( (string) $args['post_type'] ) : 'post';
        $obj  = post_type_exists( $type ) ? get_post_type_object( $type ) : null;
        if ( ! $obj || empty( $obj->show_ui ) ) {
            return new WP_Error( 'drafts_post_type', "This credential is drafts-only: post type {$type} does not exist or is not editable content. Use post, page or another content type." );
        }
        $err = self::type_error( $type );
        if ( $err ) {
            return $err;
        }
        $err = self::gate_tags( $args );
        return $err ? $err : $args;
    }

    private static function gate_target( string $name, array $args ): array|WP_Error {
        $arg = self::WRITE_TARGETS[ $name ];
        $raw = $args[ $arg ] ?? null;

        if ( 'wp_acf_update_field' === $name || 'wp_acf_add_row' === $name || 'wp_acf_update_row' === $name ) {
            if ( ! is_int( $raw ) && ! ( is_string( $raw ) && ctype_digit( trim( $raw ) ) ) ) {
                return new WP_Error( 'drafts_acf_target', 'This credential is drafts-only: ACF writes to options, terms, users and comments change the live site. Pass the numeric ID of a draft post as object_id.' );
            }
        }
        if ( 'wp_edit_blocks' === $name && ( ! empty( $args['template'] ) || ! empty( $args['template_type'] ) ) ) {
            return new WP_Error( 'drafts_template', 'This credential is drafts-only: block templates are site-wide. Edit a post by post_id instead.' );
        }

        $id   = (int) $raw;
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post ) {
            return $args; // The handler reports not-found.
        }

        if ( 'wp_restore_revision' === $name ) {
            if ( 'revision' !== $post->post_type ) {
                return $args; // The handler refuses a non-revision.
            }
            $parent = $post->post_parent ? get_post( (int) $post->post_parent ) : null;
            if ( ! $parent ) {
                return $args;
            }
            if ( ! in_array( $parent->post_status, self::UNPUBLISHED, true ) ) {
                return new WP_Error( 'drafts_live_revision', 'Restoring a revision of a live post is not possible on a drafts-only connection. Read it with wp_get_revision_diff and apply the change with wp_update_post; it will be saved to a draft copy.' );
            }
            $post = $parent;
        }

        $err = self::type_error( $post->post_type );
        if ( $err ) {
            return $err;
        }
        if ( ! in_array( $post->post_status, self::UNPUBLISHED, true ) ) {
            return new WP_Error( 'drafts_live_target_unsupported', "Post {$post->ID} is live. Draft copies of live posts are not yet supported on this connection." );
        }

        $args = self::gate_status( $args );
        if ( is_wp_error( $args ) ) {
            return $args;
        }
        $err = self::gate_tags( $args );
        return $err ? $err : $args;
    }
}
