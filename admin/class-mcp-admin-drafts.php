<?php
/**
 * Admin UI for drafts-only connections: Posts-list labels for draft copies and
 * their originals, and the notices on the post edit screen.
 *
 * @package Cowboy_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cowboy_MCP_Admin_Drafts {

    public static function init(): void {
        if ( ! is_admin() ) {
            return;
        }
        add_filter( 'display_post_states', [ __CLASS__, 'post_states' ], 10, 2 );
        add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
        add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'block_editor_notices' ] );
    }

    /**
     * "Draft copy of: <title>" on a copy, "Has a draft copy" on an original.
     *
     * @param array<string,string> $states
     * @return array<string,string>
     */
    public static function post_states( $states, $post ) {
        if ( ! is_array( $states ) || ! ( $post instanceof WP_Post ) ) {
            return $states;
        }
        $original_id = Cowboy_MCP_Drafts::original_of( (int) $post->ID );
        if ( $original_id > 0 ) {
            $original = get_post( $original_id );
            if ( $original && current_user_can( 'read_post', $original->ID ) ) {
                /* translators: %s: title of the live post this draft copy was made from */
                $states['cowboy_mcp_draft_copy'] = sprintf( __( 'Draft copy of: %s', 'cowboy-mcp' ), esc_html( get_the_title( $original ) ) );
            } else {
                $states['cowboy_mcp_draft_copy'] = __( 'Draft copy', 'cowboy-mcp' );
            }
        } elseif ( null !== Cowboy_MCP_Drafts::find_copy( (int) $post->ID ) ) {
            $states['cowboy_mcp_has_copy'] = __( 'Has a draft copy', 'cowboy-mcp' );
        }
        return $states;
    }

    /** The post being edited on post.php, when the current user may edit it. */
    private static function screen_post(): ?WP_Post {
        global $pagenow;
        if ( 'post.php' !== $pagenow ) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
        $post_id = absint( wp_unslash( $_GET['post'] ?? 0 ) );
        if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
            return null;
        }
        $post = get_post( $post_id );
        return $post instanceof WP_Post ? $post : null;
    }

    /**
     * Notices on the classic post edit screen (post.php) for users who can edit the post.
     * The block editor hides admin_notices output, so there block_editor_notices() shows them.
     */
    public static function notices(): void {
        $post = self::screen_post();
        if ( ! $post ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
            return;
        }
        $post_id = (int) $post->ID;

        $original_id = Cowboy_MCP_Drafts::original_of( $post_id );
        if ( $original_id > 0 ) {
            self::copy_notice( $post, $original_id );
            return;
        }
        $copy = Cowboy_MCP_Drafts::find_copy( $post_id );
        if ( $copy && current_user_can( 'edit_post', $copy->ID ) ) {
            $link = '<a href="' . esc_url( get_edit_post_link( $copy->ID, 'raw' ) ) . '">' . esc_html__( 'Open the draft copy', 'cowboy-mcp' ) . '</a>';
            /* translators: %s: link "Open the draft copy" */
            $text = sprintf( esc_html__( 'An AI connection prepared changes to this post in a draft copy. %s', 'cowboy-mcp' ), $link );
            echo '<div class="notice notice-info"><p>' . wp_kses( $text, [ 'a' => [ 'href' => [] ] ] ) . '</p></div>';
        }
    }

    private static function copy_notice( WP_Post $copy, int $original_id ): void {
        $original = get_post( $original_id );
        if ( ! $original ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'The original post no longer exists.', 'cowboy-mcp' ) . '</p></div>';
            return;
        }
        $by = (string) get_post_meta( $copy->ID, Cowboy_MCP_Drafts::META_COPY_BY, true );
        if ( '' === $by ) {
            $by = __( 'an AI connection', 'cowboy-mcp' );
        }
        $title = get_the_title( $original );
        if ( current_user_can( 'edit_post', $original->ID ) ) {
            $title_html = '<a href="' . esc_url( get_edit_post_link( $original->ID, 'raw' ) ) . '">' . esc_html( $title ) . '</a>';
        } else {
            $title_html = esc_html( $title );
        }
        /* translators: 1: title of the original post (a link), 2: name of the AI connection that prepared the copy */
        $text = sprintf( esc_html__( 'This is a draft copy of %1$s, prepared by %2$s. Review it, then copy the changes into the original and delete this copy.', 'cowboy-mcp' ), '%%ORIGINAL%%', esc_html( $by ) );
        $text = str_replace( '%%ORIGINAL%%', $title_html, $text );

        if ( '1' === (string) get_post_meta( $copy->ID, '_dp_is_rewrite_republish_copy', true ) ) {
            $text .= ' ' . esc_html__( 'Use Republish to replace the original.', 'cowboy-mcp' );
        }
        $at = (string) get_post_meta( $copy->ID, Cowboy_MCP_Drafts::META_COPY_AT, true );
        $changed = '';
        if ( '' !== $at && $original->post_modified_gmt > $at ) {
            $changed = '<p>' . esc_html__( 'The original has changed since this copy was made.', 'cowboy-mcp' ) . '</p>';
        }
        echo '<div class="notice notice-info"><p>' . wp_kses( $text, [ 'a' => [ 'href' => [] ] ] ) . '</p>' . wp_kses_post( $changed ) . '</div>';
    }

    /** The same notices in the block editor, through its notices store. */
    public static function block_editor_notices(): void {
        $post = self::screen_post();
        if ( ! $post ) {
            return;
        }
        $notices = self::plain_notices( $post );
        if ( ! $notices ) {
            return;
        }
        $handle = 'cowboy-mcp-drafts-notice';
        wp_register_script( $handle, false, [ 'wp-data', 'wp-notices', 'wp-dom-ready' ], COWBOY_MCP_VERSION, true );
        wp_enqueue_script( $handle );
        $data = wp_json_encode( $notices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        wp_add_inline_script( $handle, 'wp.domReady(function(){var n=' . $data . ';n.forEach(function(x,i){wp.data.dispatch("core/notices").createNotice("warning",x.text,{id:"cowboy-mcp-drafts-"+i,isDismissible:true,actions:x.actions});});});' );
    }

    /** A title as plain text (no entities or tags), for the notices store. */
    private static function plain_title( WP_Post $post ): string {
        $title = trim( wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ) );
        return '' !== $title ? $title : '#' . $post->ID;
    }

    /**
     * The notices for $post as plain text plus link actions: the same strings as the
     * classic notices, with each link moved into an action button.
     *
     * @return array<int,array{text:string,actions:array<int,array{url:string,label:string}>}>
     */
    private static function plain_notices( WP_Post $post ): array {
        $original_id = Cowboy_MCP_Drafts::original_of( (int) $post->ID );
        if ( $original_id > 0 ) {
            $original = get_post( $original_id );
            if ( ! $original ) {
                return [ [ 'text' => __( 'The original post no longer exists.', 'cowboy-mcp' ), 'actions' => [] ] ];
            }
            $by = (string) get_post_meta( $post->ID, Cowboy_MCP_Drafts::META_COPY_BY, true );
            if ( '' === $by ) {
                $by = __( 'an AI connection', 'cowboy-mcp' );
            }
            $title = self::plain_title( $original );
            /* translators: 1: title of the original post (a link), 2: name of the AI connection that prepared the copy */
            $text = sprintf( __( 'This is a draft copy of %1$s, prepared by %2$s. Review it, then copy the changes into the original and delete this copy.', 'cowboy-mcp' ), $title, $by );
            if ( '1' === (string) get_post_meta( $post->ID, '_dp_is_rewrite_republish_copy', true ) ) {
                $text .= ' ' . __( 'Use Republish to replace the original.', 'cowboy-mcp' );
            }
            $at = (string) get_post_meta( $post->ID, Cowboy_MCP_Drafts::META_COPY_AT, true );
            if ( '' !== $at && $original->post_modified_gmt > $at ) {
                $text .= ' ' . __( 'The original has changed since this copy was made.', 'cowboy-mcp' );
            }
            $actions = current_user_can( 'edit_post', $original->ID )
                ? [ [ 'url' => (string) get_edit_post_link( $original->ID, 'raw' ), 'label' => $title ] ]
                : [];
            return [ [ 'text' => $text, 'actions' => $actions ] ];
        }
        $copy = Cowboy_MCP_Drafts::find_copy( (int) $post->ID );
        if ( ! $copy || ! current_user_can( 'edit_post', $copy->ID ) ) {
            return [];
        }
        /* translators: %s: link "Open the draft copy" */
        $text = sprintf( __( 'An AI connection prepared changes to this post in a draft copy. %s', 'cowboy-mcp' ), '' );
        return [ [
            'text'    => trim( (string) preg_replace( '/\s+/u', ' ', $text ) ),
            'actions' => [ [ 'url' => (string) get_edit_post_link( $copy->ID, 'raw' ), 'label' => __( 'Open the draft copy', 'cowboy-mcp' ) ] ],
        ] ];
    }
}
