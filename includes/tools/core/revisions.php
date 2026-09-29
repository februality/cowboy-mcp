<?php
defined( 'ABSPATH' ) || exit;

/** Max bytes of unified diff returned per field. */
const COWBOY_MCP_REVISION_DIFF_CAP = 61440;

/**
 * Revisioned values of $source (a post or one of its revisions), keyed like
 * _wp_post_revision_fields() plus "meta:<key>" for revisioned meta (6.4+).
 * $parent decides the field list so a post and its revisions compare 1:1.
 */
function cowboy_mcp_revision_values( WP_Post $source, WP_Post $parent ): array {
	$values = [];
	foreach ( array_keys( _wp_post_revision_fields( $parent ) ) as $field ) {
		$values[ $field ] = (string) $source->$field;
	}
	if ( function_exists( 'wp_post_revision_meta_keys' ) ) {
		foreach ( wp_post_revision_meta_keys( $parent->post_type ) as $key ) {
			if ( isset( $values[ $key ] ) ) {
				continue; // Already a plain revision field (e.g. footnotes on 6.x+).
			}
			$v = get_post_meta( $source->ID, $key, true );
			$values[ 'meta:' . $key ] = is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v );
		}
	}
	return $values;
}

/** Unified diff (old → new), "" when equal. Uses core's bundled Text_Diff. */
function cowboy_mcp_revision_unified_diff( string $old, string $new ): string {
	if ( $old === $new ) {
		return '';
	}
	if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
		require_once Cowboy_MCP_Compat::wp_root() . WPINC . '/wp-diff.php';
	}
	if ( ! class_exists( 'Cowboy_MCP_Unified_Diff_Renderer', false ) ) {
		require_once __DIR__ . '/revisions-diff.php';
	}
	$split = static fn( string $s ): array => explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $s ) );
	$diff  = new Text_Diff( 'auto', [ $split( $old ), $split( $new ) ] );
	return (string) ( new Cowboy_MCP_Unified_Diff_Renderer() )->render( $diff );
}

/** Revision + parent, or a WP_Error explaining why not. */
function cowboy_mcp_revision_resolve( int $revision_id ): array|WP_Error {
	$revision = wp_get_post_revision( $revision_id );
	if ( ! $revision instanceof WP_Post ) {
		return new WP_Error( 'revision_not_found', "Revision {$revision_id} not found." );
	}
	$parent = get_post( (int) $revision->post_parent );
	if ( ! $parent instanceof WP_Post ) {
		return new WP_Error( 'revision_not_found', "Revision {$revision_id} has no parent post." );
	}
	return [ 'revision' => $revision, 'parent' => $parent ];
}

/** Guard shared by all three tools. */
function cowboy_mcp_revision_check_post( WP_Post $post ): ?WP_Error {
	if ( ! post_type_supports( $post->post_type, 'revisions' ) ) {
		return new WP_Error( 'revisions_not_supported', "Post type '{$post->post_type}' does not support revisions." );
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return new WP_Error( 'forbidden', 'The authenticated user cannot edit this post.' );
	}
	return null;
}

/** Why a restore must be refused, or null. */
function cowboy_mcp_revision_restore_refusal( WP_Post $rev, WP_Post $parent ): ?WP_Error {
	$err = cowboy_mcp_revision_check_post( $parent );
	if ( $err ) {
		return $err;
	}
	if ( $parent->post_status === 'trash' ) {
		return new WP_Error( 'parent_trashed', "Post {$parent->ID} is in the trash; untrash it before restoring a revision." );
	}
	if ( wp_is_post_autosave( $rev ) && (int) $rev->post_author !== get_current_user_id() ) {
		return new WP_Error( 'autosave_other_user', "Revision {$rev->ID} is another user's autosave; restoring it would publish their unsaved draft." );
	}
	return null;
}

/** Dry-run plan for wp_restore_revision (called from generate_dry_run_preview). */
function cowboy_mcp_revision_plan( int $revision_id ): array {
	$res = cowboy_mcp_revision_resolve( $revision_id );
	if ( is_wp_error( $res ) ) {
		return [ 'would_fail' => $res->get_error_code(), 'reason' => $res->get_error_message() ];
	}
	$refusal = cowboy_mcp_revision_restore_refusal( $res['revision'], $res['parent'] );
	if ( $refusal ) {
		return [ 'would_fail' => $refusal->get_error_code(), 'reason' => $refusal->get_error_message() ];
	}
	$old = cowboy_mcp_revision_values( $res['revision'], $res['parent'] );
	$cur = cowboy_mcp_revision_values( $res['parent'], $res['parent'] );
	return [
		'post_id'        => (int) $res['parent']->ID,
		'revision_id'    => $revision_id,
		'changed_fields' => array_keys( array_diff_assoc( $old, $cur ) ),
		'undoable'       => true,
	];
}

return [
	'tools' => [
		Cowboy_MCP_Tools::tool( 'wp_list_revisions', '[Content] List a post\'s revisions (newest first, autosaves included and flagged) with which fields differ from the current post. Use wp_get_revision_diff to see the changes and wp_restore_revision to roll back.', [
			'post_id'  => [ 'type' => 'integer', 'description' => 'Post ID', 'required' => true ],
			'page'     => [ 'type' => 'integer', 'description' => 'Page number (default 1)', 'default' => 1, 'minimum' => 1 ],
			'per_page' => [ 'type' => 'integer', 'description' => 'Revisions per page, max 100 (default 20)', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
		], [ 'title' => 'List Revisions', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ], [
			'type'       => 'object',
			'properties' => [
				'post_id'   => [ 'type' => 'integer' ],
				'post_type' => [ 'type' => 'string' ],
				'total'     => [ 'type' => 'integer' ],
				'page'      => [ 'type' => 'integer' ],
				'revisions' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
		] ),
		Cowboy_MCP_Tools::tool( 'wp_get_revision_diff', '[Content] Unified diff (git-style -/+ lines) between a revision and the current post, or another revision of the same post (compare_to). Only changed fields are returned; identical=true when nothing differs. Each field is capped at 60 KB (listed in truncated).', [
			'revision_id' => [ 'type' => 'integer', 'description' => 'Revision ID (the "old" side)', 'required' => true ],
			'compare_to'  => [ 'type' => 'integer', 'description' => 'Another revision ID of the same post (the "new" side). Default: the current post.' ],
		], [ 'title' => 'Get Revision Diff', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ], [
			'type'       => 'object',
			'properties' => [
				'revision_id' => [ 'type' => 'integer' ],
				'post_id'     => [ 'type' => 'integer' ],
				'compare_to'  => [ 'type' => [ 'string', 'integer' ] ],
				'identical'   => [ 'type' => 'boolean' ],
				'fields'      => [ 'type' => 'object' ],
				'truncated'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		] ),
		Cowboy_MCP_Tools::tool( 'wp_restore_revision', '[Content] Restore a post to one of its revisions (title, content, excerpt and revisioned meta such as footnotes). The current state is kept as a revision, and the restore is undoable via wp_undo_change.', [
			'revision_id' => [ 'type' => 'integer', 'description' => 'Revision ID to restore (from wp_list_revisions)', 'required' => true ],
		], [ 'title' => 'Restore Revision', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ], [
			'type'       => 'object',
			'properties' => [
				'post_id'         => [ 'type' => 'integer' ],
				'restored_from'   => [ 'type' => 'integer' ],
				'new_revision_id' => [ 'type' => [ 'integer', 'null' ] ],
				'title'           => [ 'type' => 'string' ],
			],
		] ),
	],

	'handlers' => [
		'wp_list_revisions' => function ( array $a ) {
			$post = get_post( (int) $a['post_id'] );
			if ( ! $post instanceof WP_Post || $post->post_type === 'revision' ) {
				return new WP_Error( 'not_found', "Post {$a['post_id']} not found." );
			}
			$err = cowboy_mcp_revision_check_post( $post );
			if ( $err ) {
				return $err;
			}
			$per_page = min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 100 );
			$page     = max( (int) ( $a['page'] ?? 1 ), 1 );
			$all_ids  = array_map( 'intval', array_values( wp_get_post_revisions( $post->ID, [ 'fields' => 'ids', 'check_enabled' => false ] ) ) );
			$slice    = array_slice( $all_ids, ( $page - 1 ) * $per_page, $per_page );
			$current  = cowboy_mcp_revision_values( $post, $post );
			$rows     = [];
			foreach ( $slice as $rid ) {
				$rev    = get_post( $rid );
				$values = cowboy_mcp_revision_values( $rev, $post );
				$rows[] = [
					'id'             => (int) $rev->ID,
					'date_gmt'       => $rev->post_date_gmt,
					'author'         => [ 'id' => (int) $rev->post_author, 'name' => get_the_author_meta( 'display_name', $rev->post_author ) ],
					'is_autosave'    => (bool) wp_is_post_autosave( $rev ),
					'changed_fields' => array_keys( array_diff_assoc( $values, $current ) ),
				];
			}
			return [ 'post_id' => (int) $post->ID, 'post_type' => $post->post_type, 'total' => count( $all_ids ), 'page' => $page, 'revisions' => $rows ];
		},

		'wp_get_revision_diff' => function ( array $a ) {
			$res = cowboy_mcp_revision_resolve( (int) $a['revision_id'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			[ 'revision' => $rev, 'parent' => $parent ] = $res;
			$err = cowboy_mcp_revision_check_post( $parent );
			if ( $err ) {
				return $err;
			}
			$target = $parent;
			if ( ! empty( $a['compare_to'] ) ) {
				$other = cowboy_mcp_revision_resolve( (int) $a['compare_to'] );
				if ( is_wp_error( $other ) ) {
					return $other;
				}
				if ( (int) $other['parent']->ID !== (int) $parent->ID ) {
					return new WP_Error( 'revision_mismatch', "Revision {$a['compare_to']} is not a revision of the same post as {$rev->ID}." );
				}
				$target = $other['revision'];
			}
			$old       = cowboy_mcp_revision_values( $rev, $parent );
			$new       = cowboy_mcp_revision_values( $target, $parent );
			$fields    = [];
			$truncated = [];
			foreach ( $old as $key => $value ) {
				$diff = cowboy_mcp_revision_unified_diff( $value, $new[ $key ] ?? '' );
				if ( $diff === '' ) {
					continue;
				}
				if ( strlen( $diff ) > COWBOY_MCP_REVISION_DIFF_CAP ) {
					$diff        = mb_strcut( $diff, 0, COWBOY_MCP_REVISION_DIFF_CAP, 'UTF-8' );
					$truncated[] = $key;
				}
				$fields[ $key ] = $diff;
			}
			return [
				'revision_id' => (int) $rev->ID,
				'post_id'     => (int) $parent->ID,
				'compare_to'  => $target === $parent ? 'current' : (int) $target->ID,
				'identical'   => $fields === [],
				'fields'      => $fields,
				'truncated'   => $truncated,
			];
		},
		'wp_restore_revision' => function ( array $a ) {
			$res = cowboy_mcp_revision_resolve( (int) $a['revision_id'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			[ 'revision' => $rev, 'parent' => $parent ] = $res;
			$refusal = cowboy_mcp_revision_restore_refusal( $rev, $parent );
			if ( $refusal ) {
				return $refusal;
			}
			$restored = wp_restore_post_revision( $rev->ID );
			if ( ! $restored || is_wp_error( $restored ) ) {
				return new WP_Error( 'restore_failed', "Could not restore revision {$rev->ID}." );
			}
			$latest = array_key_first( wp_get_post_revisions( $parent->ID, [ 'posts_per_page' => 1, 'check_enabled' => false ] ) );
			return [
				'post_id'         => (int) $parent->ID,
				'restored_from'   => (int) $rev->ID,
				'new_revision_id' => $latest ? (int) $latest : null,
				'title'           => get_post( $parent->ID )->post_title,
			];
		},
	],
];
