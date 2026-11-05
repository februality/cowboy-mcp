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
}
