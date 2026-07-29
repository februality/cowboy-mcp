<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Gutenberg / FSE tools.
 *  Tier 1 (block editing, block types, patterns) always registers —
 *  the block editor is core since WP 5.0. Tier 2 (templates, template
 *  parts, global styles, navigation) registers only when the active
 *  theme is a block theme; handlers re-check at call time because the
 *  theme can switch mid-session.
 * ================================================================ */

/* ================================================================
 *  Helpers — tree read side
 * ================================================================ */

/**
 * Parse block markup into a tree, stripping top-level whitespace-only
 * freeform nodes (parser artifacts between block comments) so paths match
 * the visible tree. Inner blocks never contain such nodes — within a block
 * the parser assigns stray text to innerContent strings, not innerBlocks.
 */
function cowboy_mcp_gutenberg_parse( string $content ): array {
    return array_values( array_filter(
        parse_blocks( $content ),
        fn( array $b ) => ! ( $b['blockName'] === null && trim( $b['innerHTML'] ) === '' )
    ) );
}

/**
 * Summarize a parsed block tree with dot-path addresses.
 * $full = false → attrs_preview (scalars whole, arrays/objects as "[…]")
 * and content_preview (200 chars); $full = true → complete attrs + content.
 */
function cowboy_mcp_gutenberg_summarize( array $blocks, bool $full = false, string $prefix = '' ): array {
    $out = [];
    foreach ( array_values( $blocks ) as $i => $b ) {
        $path = $prefix === '' ? (string) $i : "{$prefix}.{$i}";
        $item = [
            'path' => $path,
            'name' => $b['blockName'] ?? 'core/freeform',
        ];
        $attrs = is_array( $b['attrs'] ?? null ) ? $b['attrs'] : [];
        if ( $full ) {
            $item['attrs']   = $attrs;
            $item['content'] = $b['innerHTML'];
        } else {
            if ( $attrs !== [] ) {
                $preview = [];
                foreach ( $attrs as $k => $v ) {
                    $preview[ $k ] = ( is_scalar( $v ) || $v === null ) ? $v : '[…]';
                }
                $item['attrs_preview'] = $preview;
            }
            $text = trim( $b['innerHTML'] );
            if ( $text !== '' ) {
                $item['content_preview'] = strlen( $text ) > 200 ? substr( $text, 0, 200 ) . '…' : $text;
            }
        }
        $item['has_children'] = ! empty( $b['innerBlocks'] );
        if ( ! empty( $b['innerBlocks'] ) ) {
            $item['children'] = cowboy_mcp_gutenberg_summarize( $b['innerBlocks'], $full, $path );
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * Resolve the block-content target from tool args.
 * Accepts post_id (any post). Task 4 extends this to template ids
 * ("theme//slug") with optional override materialization.
 *
 * @return array|WP_Error {post_id, template, title, kind, content, materialized}
 */
function cowboy_mcp_gutenberg_resolve_target( array $a, bool $materialize = false ): array|WP_Error {
    $has_post = isset( $a['post_id'] );
    $has_tpl  = ! empty( $a['template'] );
    if ( $has_post === $has_tpl ) {
        return new WP_Error( 'invalid_params', 'Provide exactly one of: post_id, template.' );
    }
    if ( $has_tpl ) {
        return new WP_Error( 'invalid_params', 'Template targets are not available yet.' );
    }
    $post = get_post( (int) $a['post_id'] );
    if ( ! $post ) {
        return new WP_Error( 'not_found', "Post #{$a['post_id']} not found." );
    }
    return [
        'post_id'      => $post->ID,
        'template'     => null,
        'title'        => $post->post_title,
        'kind'         => $post->post_type,
        'content'      => (string) $post->post_content,
        'materialized' => false,
    ];
}

/* ================================================================
 *  Helpers — tree edit side
 * ================================================================ */

/** Walk a dot-path; null when it does not exist. */
function cowboy_mcp_gutenberg_get_node( array $tree, string $path ): ?array {
    $node = null;
    $blocks = $tree;
    foreach ( explode( '.', $path ) as $i ) {
        $i = (int) $i;
        if ( ! isset( $blocks[ $i ] ) ) {
            return null;
        }
        $node   = $blocks[ $i ];
        $blocks = $node['innerBlocks'] ?? [];
    }
    return $node;
}

/** Replace the node at $path, returning the new tree. $path must exist. */
function cowboy_mcp_gutenberg_set_node( array $tree, string $path, array $node ): array {
    $indexes = explode( '.', $path );
    $i       = (int) array_shift( $indexes );
    if ( $indexes === [] ) {
        $tree[ $i ] = $node;
        return $tree;
    }
    $tree[ $i ]['innerBlocks'] = cowboy_mcp_gutenberg_set_node( $tree[ $i ]['innerBlocks'], implode( '.', $indexes ), $node );
    return $tree;
}

/**
 * Stored-XSS gate (Elementor precedent): raw script-capable content requires
 * an explicit allow_unfiltered_html opt-in. Returns the offending reason or null.
 */
function cowboy_mcp_gutenberg_unfiltered_reason( string $content ): ?string {
    if ( preg_match( '/<script/i', $content ) )   return 'a <script> tag';
    if ( preg_match( '/<iframe/i', $content ) )   return 'an <iframe> tag';
    if ( preg_match( '/\son[a-z]+\s*=/i', $content ) ) return 'an inline event handler attribute';
    return null;
}

/**
 * Sanitize one agent-supplied content string per the kses policy:
 * gate script-capable content behind allow_unfiltered_html, else kses it.
 * $context names the op/path for the error message.
 */
function cowboy_mcp_gutenberg_filter_content( string $content, bool $allow_unfiltered, string $context ): string|WP_Error {
    if ( $allow_unfiltered ) {
        return $content;
    }
    $reason = cowboy_mcp_gutenberg_unfiltered_reason( $content );
    if ( $reason !== null ) {
        return new WP_Error( 'unfiltered_html_blocked', "Content at {$context} contains {$reason}. Pass allow_unfiltered_html: true to permit it (this writes markup that runs on the front end)." );
    }
    return wp_kses_post( $content );
}

/** Recursive core/html scan for parsed markup specs. */
function cowboy_mcp_gutenberg_contains_html_block( array $blocks ): bool {
    foreach ( $blocks as $b ) {
        if ( ( $b['blockName'] ?? '' ) === 'core/html' ) {
            return true;
        }
        if ( ! empty( $b['innerBlocks'] ) && cowboy_mcp_gutenberg_contains_html_block( $b['innerBlocks'] ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Apply wp_kses_post() to a parsed block tree's HTML content only — each
 * node's innerHTML and the string entries of its innerContent — recursing
 * into innerBlocks. Attrs and block-delimiter comments are never touched:
 * running kses over a whole serialized-markup string runs its comment
 * handling over the "<!-- wp:name {json} -->" delimiters too, which
 * corrupts CSS custom-property names (e.g. var(--wp--preset--…)) and JSON
 * punctuation inside them. Reusable for other full-content write paths.
 */
function cowboy_mcp_gutenberg_kses_tree( array $blocks ): array {
    foreach ( $blocks as &$b ) {
        if ( is_string( $b['innerHTML'] ?? null ) && $b['innerHTML'] !== '' ) {
            $b['innerHTML'] = wp_kses_post( $b['innerHTML'] );
        }
        if ( is_array( $b['innerContent'] ?? null ) ) {
            foreach ( $b['innerContent'] as &$part ) {
                if ( is_string( $part ) && $part !== '' ) {
                    $part = wp_kses_post( $part );
                }
            }
            unset( $part );
        }
        if ( ! empty( $b['innerBlocks'] ) ) {
            $b['innerBlocks'] = cowboy_mcp_gutenberg_kses_tree( $b['innerBlocks'] );
        }
    }
    unset( $b );
    return $blocks;
}

/**
 * Build a parse-tree node from a block spec.
 * Structured form {name, attrs?, content?, inner_blocks?} emits no wrapper
 * markup — for blocks whose save output wraps children in HTML (core/group,
 * core/columns), prefer the {markup} form with full serialized block markup.
 */
function cowboy_mcp_gutenberg_build_block( array $spec, bool $allow_unfiltered, string $context = 'block' ): array|WP_Error {
    if ( ! empty( $spec['markup'] ) && is_string( $spec['markup'] ) ) {
        $markup = $spec['markup'];
        if ( ! $allow_unfiltered ) {
            $reason = cowboy_mcp_gutenberg_unfiltered_reason( $markup );
            if ( $reason !== null ) {
                return new WP_Error( 'unfiltered_html_blocked', "Content at {$context} contains {$reason}. Pass allow_unfiltered_html: true to permit it (this writes markup that runs on the front end)." );
            }
        }
        $parsed = cowboy_mcp_gutenberg_parse( $markup );
        if ( count( $parsed ) !== 1 ) {
            return new WP_Error( 'invalid_block', "markup at {$context} must contain exactly one root block, got " . count( $parsed ) . '.' );
        }
        if ( ! $allow_unfiltered && cowboy_mcp_gutenberg_contains_html_block( $parsed ) ) {
            return new WP_Error( 'unfiltered_html_blocked', "markup at {$context} contains a core/html block. Pass allow_unfiltered_html: true to permit it." );
        }
        // kses the parsed tree's content strings, never the raw markup —
        // see cowboy_mcp_gutenberg_kses_tree() docblock for why.
        if ( ! $allow_unfiltered ) {
            $parsed = cowboy_mcp_gutenberg_kses_tree( $parsed );
        }
        return $parsed[0];
    }

    $name = $spec['name'] ?? '';
    if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/', $name ) ) {
        return new WP_Error( 'invalid_block', "Block spec at {$context} needs a valid name (namespace/block) or a markup string." );
    }
    if ( $name === 'core/html' && ! $allow_unfiltered ) {
        return new WP_Error( 'unfiltered_html_blocked', "Block spec at {$context} is a core/html block. Pass allow_unfiltered_html: true to permit it." );
    }
    $attrs   = is_array( $spec['attrs'] ?? null ) ? $spec['attrs'] : [];
    $content = (string) ( $spec['content'] ?? '' );
    $has_kids = ! empty( $spec['inner_blocks'] ) && is_array( $spec['inner_blocks'] );

    if ( $has_kids && $content !== '' ) {
        return new WP_Error( 'invalid_block', "Block spec at {$context}: provide either content or inner_blocks, not both (use markup for wrapper HTML around children)." );
    }

    if ( $has_kids ) {
        $children = [];
        $ic       = [];
        foreach ( array_values( $spec['inner_blocks'] ) as $j => $child_spec ) {
            $child = cowboy_mcp_gutenberg_build_block( (array) $child_spec, $allow_unfiltered, "{$context}.inner_blocks[{$j}]" );
            if ( is_wp_error( $child ) ) {
                return $child;
            }
            if ( $ic !== [] ) {
                $ic[] = "\n\n";
            }
            $ic[]       = null;
            $children[] = $child;
        }
        return [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => '', 'innerContent' => $ic ];
    }

    if ( $content !== '' ) {
        $content = cowboy_mcp_gutenberg_filter_content( $content, $allow_unfiltered, $context );
        if ( is_wp_error( $content ) ) {
            return $content;
        }
    }
    return [
        'blockName'    => $name,
        'attrs'        => $attrs,
        'innerBlocks'  => [],
        'innerHTML'    => $content,
        'innerContent' => $content !== '' ? [ $content ] : [],
    ];
}

/**
 * Validate + index an operations array against the pre-call tree.
 * All paths refer to that tree (snapshot addressing). Returns the op index
 * consumed by cowboy_mcp_gutenberg_apply_ops(), plus human-readable
 * per-op descriptions (dry-run preview) and unregistered-name warnings.
 */
function cowboy_mcp_gutenberg_index_ops( array $tree, array $ops, bool $allow_unfiltered ): array|WP_Error {
    $index = [
        'updates'      => [], // path => [ {attrs?, content?} … ] in op order
        'deletes'      => [], // path => true
        'replaces'     => [], // path => node
        'ins_before'   => [], // anchor path => [ nodes ]
        'ins_after'    => [], // anchor path => [ nodes ]
        'prepend'      => [], // parent path => [ nodes ]
        'append'       => [], // parent path => [ nodes ]
        'moves'        => [], // [ {from, to, position} ]
        'descriptions' => [],
        'warnings'     => [],
    ];
    $removed = []; // delete/replace/move-from roots for conflict checks

    $valid_path = fn( $p ) => is_string( $p ) && preg_match( '/^\d+(\.\d+)*$/', $p ) === 1;
    $positions  = [ 'before', 'after', 'first_child', 'last_child' ];
    $registry   = WP_Block_Type_Registry::get_instance();

    if ( $ops === [] ) {
        return new WP_Error( 'invalid_params', 'operations must be a non-empty array.' );
    }

    foreach ( array_values( $ops ) as $n => $op ) {
        if ( ! is_array( $op ) || empty( $op['op'] ) ) {
            return new WP_Error( 'invalid_params', "operations[{$n}] needs an op key (update|insert|replace|delete|move)." );
        }
        $kind = $op['op'];
        $ctx  = "operations[{$n}]";

        $need_node = function ( string $path ) use ( $tree, $ctx, $valid_path ): array|WP_Error {
            if ( ! $valid_path( $path ) ) {
                return new WP_Error( 'invalid_path', "{$ctx}: '{$path}' is not a valid dot-path." );
            }
            $node = cowboy_mcp_gutenberg_get_node( $tree, $path );
            if ( $node === null ) {
                return new WP_Error( 'invalid_path', "{$ctx}: no block at path {$path}." );
            }
            return $node;
        };

        switch ( $kind ) {
            case 'update':
                $path = (string) ( $op['path'] ?? '' );
                $node = $need_node( $path );
                if ( is_wp_error( $node ) ) return $node;
                $has_attrs   = isset( $op['attrs'] ) && is_array( $op['attrs'] );
                $has_content = isset( $op['content'] ) && is_string( $op['content'] );
                if ( ! $has_attrs && ! $has_content ) {
                    return new WP_Error( 'invalid_params', "{$ctx}: update needs attrs and/or content." );
                }
                if ( $has_content ) {
                    if ( ! empty( $node['innerBlocks'] ) ) {
                        return new WP_Error( 'has_children', "{$ctx}: block at {$path} has child blocks; edit children at their own paths (content is leaf-only)." );
                    }
                    if ( ( $node['blockName'] ?? '' ) === 'core/html' && ! $allow_unfiltered ) {
                        return new WP_Error( 'unfiltered_html_blocked', "{$ctx}: block at {$path} is core/html. Pass allow_unfiltered_html: true to edit its content." );
                    }
                    $filtered = cowboy_mcp_gutenberg_filter_content( $op['content'], $allow_unfiltered, "{$ctx} (path {$path})" );
                    if ( is_wp_error( $filtered ) ) return $filtered;
                    $op['content'] = $filtered;
                }
                $index['updates'][ $path ][] = [
                    'attrs'   => $has_attrs ? $op['attrs'] : null,
                    'content' => $has_content ? $op['content'] : null,
                ];
                $index['descriptions'][] = 'update ' . ( $node['blockName'] ?? 'core/freeform' ) . " at {$path}";
                break;

            case 'delete':
                $path = (string) ( $op['path'] ?? '' );
                $node = $need_node( $path );
                if ( is_wp_error( $node ) ) return $node;
                if ( isset( $removed[ $path ] ) ) {
                    return new WP_Error( 'op_conflict', "{$ctx}: path {$path} is removed by two operations." );
                }
                $index['deletes'][ $path ] = true;
                $removed[ $path ]          = $kind;
                $index['descriptions'][]   = 'delete ' . ( $node['blockName'] ?? 'core/freeform' ) . " at {$path}";
                break;

            case 'replace':
                $path = (string) ( $op['path'] ?? '' );
                $node = $need_node( $path );
                if ( is_wp_error( $node ) ) return $node;
                $new = cowboy_mcp_gutenberg_build_block( (array) ( $op['block'] ?? [] ), $allow_unfiltered, "{$ctx}.block" );
                if ( is_wp_error( $new ) ) return $new;
                if ( isset( $index['replaces'][ $path ] ) ) {
                    return new WP_Error( 'op_conflict', "{$ctx}: path {$path} is replaced twice." );
                }
                if ( isset( $removed[ $path ] ) ) {
                    return new WP_Error( 'op_conflict', "{$ctx}: path {$path} is removed by two operations." );
                }
                $index['replaces'][ $path ] = $new;
                $removed[ $path ]           = $kind;
                if ( ! $registry->is_registered( $new['blockName'] ) ) {
                    $index['warnings'][] = "Block type '{$new['blockName']}' is not registered on the server (client-only blocks are fine).";
                }
                $index['descriptions'][] = 'replace ' . ( $node['blockName'] ?? 'core/freeform' ) . " at {$path} with {$new['blockName']}";
                break;

            case 'insert':
                $path = (string) ( $op['path'] ?? '' );
                $pos  = (string) ( $op['position'] ?? '' );
                if ( ! in_array( $pos, $positions, true ) ) {
                    return new WP_Error( 'invalid_params', "{$ctx}: position must be one of before, after, first_child, last_child." );
                }
                $node = $need_node( $path );
                if ( is_wp_error( $node ) ) return $node;
                $new = cowboy_mcp_gutenberg_build_block( (array) ( $op['block'] ?? [] ), $allow_unfiltered, "{$ctx}.block" );
                if ( is_wp_error( $new ) ) return $new;
                $slot = match ( $pos ) {
                    'before'      => 'ins_before',
                    'after'       => 'ins_after',
                    'first_child' => 'prepend',
                    'last_child'  => 'append',
                };
                $index[ $slot ][ $path ][] = $new;
                if ( ! $registry->is_registered( $new['blockName'] ) ) {
                    $index['warnings'][] = "Block type '{$new['blockName']}' is not registered on the server (client-only blocks are fine).";
                }
                $index['descriptions'][] = "insert {$new['blockName']} {$pos} {$path}";
                break;

            case 'move':
                $from = (string) ( $op['from_path'] ?? '' );
                $to   = (string) ( $op['to_path'] ?? '' );
                $pos  = (string) ( $op['position'] ?? '' );
                if ( ! in_array( $pos, $positions, true ) ) {
                    return new WP_Error( 'invalid_params', "{$ctx}: position must be one of before, after, first_child, last_child." );
                }
                $node = $need_node( $from );
                if ( is_wp_error( $node ) ) return $node;
                $anchor = $need_node( $to );
                if ( is_wp_error( $anchor ) ) return $anchor;
                if ( $to === $from || str_starts_with( $to, $from . '.' ) ) {
                    return new WP_Error( 'op_conflict', "{$ctx}: cannot move a block into its own subtree ({$from} → {$to})." );
                }
                if ( isset( $removed[ $from ] ) ) {
                    return new WP_Error( 'op_conflict', "{$ctx}: path {$from} is removed by two operations." );
                }
                $index['moves'][]  = [ 'from' => $from, 'to' => $to, 'position' => $pos ];
                $removed[ $from ]  = $kind;
                $index['descriptions'][] = 'move ' . ( $node['blockName'] ?? 'core/freeform' ) . " from {$from} to {$pos} {$to}";
                break;

            default:
                return new WP_Error( 'invalid_params', "{$ctx}: unknown op '{$kind}'." );
        }
    }

    /* Conflict pass — every referenced path vs removed subtree roots.
     * Exact-path rules: update/insert-anchor/insert-parent/move-anchor on a
     * DELETED or REPLACED path conflicts. Replace spares only siblings
     * (insert-anchor, move-anchor) — the replacement keeps their position —
     * NOT children (insert-parent/prepend/append): rebuild emits the
     * replacement node wholesale and never consults prepend/append for that
     * path, so a child insert there would be silently dropped.
     * A move removal is different in kind: update is exempt against it at
     * both the exact path and any strict descendant, because Phase 1 writes
     * land in the tree before Phase 2 captures the move's source subtree —
     * the update rides along with the relocated block. Every other ref kind
     * still conflicts inside (or at) a moved subtree; rebuild never walks
     * into the captured node, so an anchor there would be lost.
     * A move's own source path is exempt against its own removal entry
     * (it's not "removed by someone else") but still conflicts, exact or
     * descendant, against any OTHER removal — this catches nested moves
     * (moving both a subtree and one of its descendants) and moves that
     * would "rescue" a block out of a subtree deleted/replaced elsewhere.
     * A delete/replace path is likewise checked as a 'removal target' ref,
     * but only on strict-descendant matches — a delete/replace can only
     * ever exactly match ITS OWN entry in $removed (a different op at the
     * same exact path is already rejected by the write-time double-removal
     * guard above), so exact matches are not meaningful here. No exemptions
     * apply: a delete/replace nested inside ANY other removed subtree
     * (move, delete, or replace) would otherwise validate cleanly and
     * silently no-op — Phase 2 captures a moved subtree raw (bypassing
     * $index['deletes']/['replaces'] entirely) and rebuild() never re-walks
     * a captured, dropped, or replaced node to apply a nested removal.
     * One accepted side effect: previously-redundant batches like
     * delete "0" + delete "0.1" now also conflict — the inner op's target
     * is removed by the outer op, so it no longer resolves under snapshot
     * addressing; this is intended strictness, not a regression.
     * Descendant rules otherwise: anything strictly inside any removed
     * subtree conflicts unless specifically exempted above.
     * All path/root comparisons are cast to string — $removed and the
     * index arrays are keyed by path, and PHP auto-casts purely-numeric
     * string keys ("0") to int, so a bare === would silently miss exact
     * matches at numeric top-level paths. */
    $refs = []; // [ [path, kind] ]
    foreach ( array_keys( $index['updates'] ) as $p )    $refs[] = [ $p, 'update' ];
    foreach ( array_keys( $index['ins_before'] ) as $p ) $refs[] = [ $p, 'insert anchor' ];
    foreach ( array_keys( $index['ins_after'] ) as $p )  $refs[] = [ $p, 'insert anchor' ];
    foreach ( array_keys( $index['prepend'] ) as $p )    $refs[] = [ $p, 'insert parent' ];
    foreach ( array_keys( $index['append'] ) as $p )     $refs[] = [ $p, 'insert parent' ];
    foreach ( $index['moves'] as $m )                    $refs[] = [ $m['to'], 'move anchor' ];
    foreach ( $index['moves'] as $m )                    $refs[] = [ $m['from'], 'move source' ];
    foreach ( array_keys( $index['deletes'] ) as $p )    $refs[] = [ $p, 'removal target' ];
    foreach ( array_keys( $index['replaces'] ) as $p )   $refs[] = [ $p, 'removal target' ];

    foreach ( $refs as [ $p, $what ] ) {
        foreach ( $removed as $root => $rkind ) {
            $is_exact      = (string) $p === (string) $root;
            $is_descendant = str_starts_with( (string) $p, $root . '.' );
            if ( $what === 'removal target' ) {
                if ( $is_descendant ) {
                    return new WP_Error( 'op_conflict', "Operation conflict: {$what} at {$p} targets a subtree removed by a {$rkind} at {$root}." );
                }
                continue;
            }
            if ( ! $is_exact && ! $is_descendant ) {
                continue;
            }
            $exempt = ( $what === 'update' && $rkind === 'move' )
                || ( $what === 'move source' && $is_exact )
                || ( $is_exact && $rkind === 'replace' && in_array( $what, [ 'insert anchor', 'move anchor' ], true ) );
            if ( ! $exempt ) {
                return new WP_Error( 'op_conflict', "Operation conflict: {$what} at {$p} targets a subtree removed by a {$rkind} at {$root}." );
            }
        }
    }

    return $index;
}

/**
 * Rebuild a container's innerContent after its child COUNT changed:
 * keep head/tail wrapper HTML, emit one null per child separated by
 * blank lines. Inter-child HTML (whitespace in valid markup) is dropped.
 * A childless block with non-wrapper content cannot take children.
 */
function cowboy_mcp_gutenberg_reindex_inner_content( array $b, array $new_children ): array|WP_Error {
    $old  = is_array( $b['innerContent'] ?? null ) ? $b['innerContent'] : [];
    $head = ( isset( $old[0] ) && is_string( $old[0] ) ) ? $old[0] : null;
    $last = $old !== [] ? $old[ array_key_last( $old ) ] : null;
    $tail = ( count( $old ) > 1 && is_string( $last ) ) ? $last : null;

    if ( empty( $b['innerBlocks'] ) && count( $old ) === 1 && is_string( $old[0] ) ) {
        // Childless: only a pure wrapper (open tag + close tag) can be split.
        $html = trim( $old[0] );
        if ( $html === '' ) {
            $head = null;
            $tail = null;
        } elseif ( preg_match( '/^(<[a-zA-Z][^>]*>)\s*(<\/[a-zA-Z][^>]*>)$/s', $html, $m ) ) {
            $head = $m[1];
            $tail = $m[2];
        } else {
            return new WP_Error( 'invalid_path', "Block '" . ( $b['blockName'] ?? 'core/freeform' ) . "' has content but no child blocks; cannot insert children into it. Replace it with full markup instead." );
        }
    }

    $ic = [];
    if ( $head !== null ) {
        $ic[] = $head;
    }
    foreach ( array_values( $new_children ) as $j => $unused ) {
        if ( $j > 0 ) {
            $ic[] = "\n\n";
        }
        $ic[] = null;
    }
    if ( $tail !== null ) {
        $ic[] = $tail;
    }
    $b['innerBlocks']  = array_values( $new_children );
    $b['innerContent'] = $ic;
    $b['innerHTML']    = ( $head ?? '' ) . ( $tail ?? '' );
    return $b;
}

/**
 * Apply a validated op index to the tree. Phases: updates in place →
 * capture moved nodes (with updates applied) and convert moves into
 * delete+insert entries → one functional rebuild.
 */
function cowboy_mcp_gutenberg_apply_ops( array $tree, array $index ): array|WP_Error {
    // Phase 1: updates (paths still valid — nothing structural has run).
    foreach ( $index['updates'] as $path => $changes ) {
        $node = cowboy_mcp_gutenberg_get_node( $tree, $path );
        foreach ( $changes as $c ) {
            if ( $c['attrs'] !== null ) {
                $attrs = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : [];
                foreach ( $c['attrs'] as $k => $v ) {
                    if ( $v === null ) {
                        unset( $attrs[ $k ] );
                    } else {
                        $attrs[ $k ] = $v;
                    }
                }
                $node['attrs'] = $attrs;
            }
            if ( $c['content'] !== null ) {
                $node['innerHTML']    = $c['content'];
                $node['innerContent'] = $c['content'] !== '' ? [ $c['content'] ] : [];
            }
        }
        $tree = cowboy_mcp_gutenberg_set_node( $tree, $path, $node );
    }

    // Phase 2: moves become delete@from + insert@to of the captured node.
    foreach ( $index['moves'] as $m ) {
        $node = cowboy_mcp_gutenberg_get_node( $tree, $m['from'] );
        $index['deletes'][ $m['from'] ] = true;
        $slot = match ( $m['position'] ) {
            'before'      => 'ins_before',
            'after'       => 'ins_after',
            'first_child' => 'prepend',
            'last_child'  => 'append',
        };
        $index[ $slot ][ $m['to'] ][] = $node;
    }

    // Phase 3: single functional rebuild against original paths.
    return cowboy_mcp_gutenberg_rebuild( $tree, '', $index );
}

/** Recursive rebuild consulting the op index by each node's ORIGINAL path. */
function cowboy_mcp_gutenberg_rebuild( array $blocks, string $prefix, array $index ): array|WP_Error {
    $result = [];
    foreach ( array_values( $blocks ) as $i => $b ) {
        $path = $prefix === '' ? (string) $i : "{$prefix}.{$i}";

        foreach ( $index['ins_before'][ $path ] ?? [] as $n ) {
            $result[] = $n;
        }

        if ( isset( $index['deletes'][ $path ] ) ) {
            // dropped (conflict pass guarantees no anchors under it)
        } elseif ( isset( $index['replaces'][ $path ] ) ) {
            $result[] = $index['replaces'][ $path ];
        } else {
            $kids_changed = isset( $index['prepend'][ $path ] ) || isset( $index['append'][ $path ] );
            if ( ! empty( $b['innerBlocks'] ) || $kids_changed ) {
                $new_children = cowboy_mcp_gutenberg_rebuild( $b['innerBlocks'] ?? [], $path, $index );
                if ( is_wp_error( $new_children ) ) {
                    return $new_children;
                }
                $new_children = array_merge(
                    $index['prepend'][ $path ] ?? [],
                    $new_children,
                    $index['append'][ $path ] ?? []
                );
                if ( count( $new_children ) !== count( $b['innerBlocks'] ?? [] ) ) {
                    $b = cowboy_mcp_gutenberg_reindex_inner_content( $b, $new_children );
                    if ( is_wp_error( $b ) ) {
                        return $b;
                    }
                } else {
                    $b['innerBlocks'] = $new_children;
                }
            }
            $result[] = $b;
        }

        foreach ( $index['ins_after'][ $path ] ?? [] as $n ) {
            $result[] = $n;
        }
    }
    return $result;
}

/* ================================================================
 *  Helpers — patterns
 * ================================================================ */

/** Fetch + validate a user pattern (wp_block post). */
function cowboy_mcp_gutenberg_get_user_pattern( int $id ): WP_Post|WP_Error {
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== 'wp_block' ) {
        return new WP_Error( 'not_found', "User pattern #{$id} not found (wp_block post)." );
    }
    return $post;
}

/**
 * Run agent-supplied pattern/template content through the XSS gate + kses
 * policy. The gate inspects the raw string; kses is applied per-node via
 * cowboy_mcp_gutenberg_kses_tree() so block delimiter comments survive.
 */
function cowboy_mcp_gutenberg_filter_full_content( string $content, bool $allow_unfiltered, string $context ): string|WP_Error {
    $parsed = cowboy_mcp_gutenberg_parse( $content );
    if ( ! $allow_unfiltered ) {
        if ( cowboy_mcp_gutenberg_contains_html_block( $parsed ) ) {
            return new WP_Error( 'unfiltered_html_blocked', "{$context} contains a core/html block. Pass allow_unfiltered_html: true to permit it." );
        }
        $reason = cowboy_mcp_gutenberg_unfiltered_reason( $content );
        if ( $reason !== null ) {
            return new WP_Error( 'unfiltered_html_blocked', "{$context} contains {$reason}. Pass allow_unfiltered_html: true to permit it (this writes markup that runs on the front end)." );
        }
        $parsed = cowboy_mcp_gutenberg_kses_tree( $parsed );
    }
    return serialize_blocks( $parsed );
}

/* ================================================================
 *  Tool definitions & handlers (built up as arrays so Tier 2 can be
 *  appended conditionally at the bottom of the file).
 * ================================================================ */

$cowboy_gutenberg_tools = [
    Cowboy_MCP_Tools::tool( 'wp_list_blocks', '[Gutenberg] Parse a post\'s content into a block tree with dot-path addresses (e.g. "1.0.2") for use with wp_edit_blocks. Returns a content_hash for optimistic concurrency. Works on any post type, including patterns (wp_block) and navigation (wp_navigation).', [
        'post_id' => [ 'type' => 'integer', 'description' => 'Post ID (provide exactly one of post_id / template)' ],
        'full'    => [ 'type' => 'boolean', 'description' => 'Return complete attrs and uncut content instead of previews', 'default' => false ],
    ], [
        'title'           => 'List Blocks',
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    ], [
        'type'       => 'object',
        'properties' => [
            'target'             => [ 'type' => 'object' ],
            'is_classic_content' => [ 'type' => 'boolean' ],
            'content_hash'       => [ 'type' => 'string' ],
            'blocks'             => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
        ],
    ] ),

    Cowboy_MCP_Tools::tool( 'wp_list_block_types', '[Gutenberg] List block types registered on the server (name, title, category). Pass name for full detail including the attribute schema. Client-only third-party blocks may not appear here — that does not make them invalid in content.', [
        'name'     => [ 'type' => 'string', 'description' => 'Full detail for one block type (e.g. "core/heading")' ],
        'category' => [ 'type' => 'string', 'description' => 'Filter list by registry category (e.g. text, media, design)' ],
        'search'   => [ 'type' => 'string', 'description' => 'Keyword filter on name and title' ],
    ], [
        'title'           => 'List Block Types',
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    ] ),

    Cowboy_MCP_Tools::tool( 'wp_edit_blocks', '[Gutenberg] Apply a batch of surgical block operations to a post. All paths refer to the tree as returned by wp_list_blocks BEFORE this call (snapshot addressing); the batch is all-or-nothing. Ops: {op:"update", path, attrs?, content?} (attrs shallow-merge, null deletes a key; content replaces inner HTML, leaf blocks only — note attrs are NOT re-rendered into HTML, so for HTML-sourced attributes like heading level update content too), {op:"insert", path, position: before|after|first_child|last_child, block}, {op:"replace", path, block}, {op:"delete", path}, {op:"move", from_path, to_path, position}. A block is {name, attrs?, content?, inner_blocks?} or {markup: "<!-- wp:… -->…"} — prefer markup for blocks with wrapper HTML (group, columns). Pass expected_hash from wp_list_blocks to fail fast if content changed since you read it.', [
        'post_id'               => [ 'type' => 'integer', 'description' => 'Post ID (provide exactly one of post_id / template)' ],
        'operations'            => [ 'type' => 'array', 'description' => 'Operation objects, applied atomically', 'items' => [ 'type' => 'object' ], 'required' => true ],
        'expected_hash'         => [ 'type' => 'string', 'description' => 'content_hash from wp_list_blocks; errors with content_conflict on mismatch' ],
        'allow_unfiltered_html' => [ 'type' => 'boolean', 'description' => 'Permit core/html blocks and script-capable content, and skip kses filtering. Default false; such markup runs on the front end (stored XSS risk).', 'default' => false ],
    ], [
        'title'           => 'Edit Blocks',
        'readOnlyHint'    => false,
        'destructiveHint' => false,
        'idempotentHint'  => false,
        'openWorldHint'   => false,
    ] ),

    Cowboy_MCP_Tools::tool( 'wp_list_patterns', '[Gutenberg] List block patterns: PHP-registered patterns (read-only, keyed by name) and user patterns (wp_block posts, keyed by numeric id, synced or unsynced).', [
        'source'   => [ 'type' => 'string', 'description' => 'Filter by source', 'enum' => [ 'all', 'registered', 'user' ], 'default' => 'all' ],
        'category' => [ 'type' => 'string', 'description' => 'Pattern category slug filter' ],
        'search'   => [ 'type' => 'string', 'description' => 'Keyword filter on name/title' ],
        'per_page' => [ 'type' => 'integer', 'description' => 'User patterns per page, max 100 (default 50)', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
        'page'     => [ 'type' => 'integer', 'description' => 'User patterns page number', 'default' => 1, 'minimum' => 1 ],
    ], [
        'title'           => 'List Patterns',
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    ] ),

    Cowboy_MCP_Tools::tool( 'wp_get_pattern', '[Gutenberg] Get one pattern with content and block tree. Pass name for a registered pattern (e.g. "core/quote") or id for a user pattern.', [
        'name' => [ 'type' => 'string', 'description' => 'Registered pattern name (provide exactly one of name / id)' ],
        'id'   => [ 'type' => 'integer', 'description' => 'User pattern (wp_block) post ID' ],
    ], [
        'title'           => 'Get Pattern',
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    ] ),

    Cowboy_MCP_Tools::tool( 'wp_create_pattern', '[Gutenberg] Create a user pattern (wp_block post). Synced patterns update everywhere they are used; unsynced patterns are copied on insert.', [
        'title'                 => [ 'type' => 'string', 'description' => 'Pattern title', 'required' => true ],
        'content'               => [ 'type' => 'string', 'description' => 'Block markup', 'required' => true ],
        'synced'                => [ 'type' => 'boolean', 'description' => 'Synced (default true) vs unsynced', 'default' => true ],
        'categories'            => [ 'type' => 'array', 'description' => 'Pattern category names/slugs (created if missing; needs WP 6.5+)', 'items' => [ 'type' => 'string' ] ],
        'allow_unfiltered_html' => [ 'type' => 'boolean', 'description' => 'Permit core/html blocks and script-capable content; skips kses. Default false.', 'default' => false ],
    ], [
        'title'           => 'Create Pattern',
        'readOnlyHint'    => false,
        'destructiveHint' => false,
        'idempotentHint'  => false,
        'openWorldHint'   => false,
    ] ),

    Cowboy_MCP_Tools::tool( 'wp_update_pattern', '[Gutenberg] Update a user pattern. Registered (PHP) patterns are read-only. Only provided fields change; categories replace the existing set.', [
        'id'                    => [ 'type' => 'integer', 'description' => 'User pattern (wp_block) post ID', 'required' => true ],
        'title'                 => [ 'type' => 'string', 'description' => 'New title' ],
        'content'               => [ 'type' => 'string', 'description' => 'New block markup' ],
        'synced'                => [ 'type' => 'boolean', 'description' => 'Change sync status' ],
        'categories'            => [ 'type' => 'array', 'description' => 'Pattern category names/slugs (replaces existing)', 'items' => [ 'type' => 'string' ] ],
        'allow_unfiltered_html' => [ 'type' => 'boolean', 'description' => 'Permit core/html blocks and script-capable content; skips kses. Default false.', 'default' => false ],
    ], [
        'title'           => 'Update Pattern',
        'readOnlyHint'    => false,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    ] ),

    Cowboy_MCP_Tools::tool( 'wp_delete_pattern', '[Gutenberg] Delete a user pattern. The response reports posts still referencing a synced pattern (their wp:block refs would break).', [
        'id'    => [ 'type' => 'integer', 'description' => 'User pattern (wp_block) post ID', 'required' => true ],
        'force' => [ 'type' => 'boolean', 'description' => 'Permanently delete instead of trashing (default false)', 'default' => false ],
    ], [
        'title'           => 'Delete Pattern',
        'readOnlyHint'    => false,
        'destructiveHint' => true,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    ] ),
];

$cowboy_gutenberg_handlers = [

    'wp_list_blocks' => function ( array $a ): array|WP_Error {
        $target = cowboy_mcp_gutenberg_resolve_target( $a );
        if ( is_wp_error( $target ) ) {
            return $target;
        }
        $tree = cowboy_mcp_gutenberg_parse( $target['content'] );
        return [
            'target' => [
                'post_id'  => $target['post_id'],
                'template' => $target['template'],
                'title'    => $target['title'],
                'kind'     => $target['kind'],
            ],
            'is_classic_content' => $target['content'] !== '' && ! has_blocks( $target['content'] ),
            'content_hash'       => md5( $target['content'] ),
            'blocks'             => cowboy_mcp_gutenberg_summarize( $tree, ! empty( $a['full'] ) ),
        ];
    },

    'wp_list_block_types' => function ( array $a ): array|WP_Error {
        $registry = WP_Block_Type_Registry::get_instance();

        if ( ! empty( $a['name'] ) ) {
            $bt = $registry->get_registered( sanitize_text_field( $a['name'] ) );
            if ( ! $bt ) {
                return new WP_Error( 'not_found', "Block type '{$a['name']}' is not registered on the server. Client-only blocks are not listed here but remain valid in content." );
            }
            return [
                'name'             => $bt->name,
                'title'            => (string) $bt->title,
                'category'         => $bt->category,
                'description'      => (string) $bt->description,
                'attributes'       => $bt->attributes ?: [],
                'supports'         => $bt->supports ?: [],
                'parent'           => $bt->parent,
                'ancestor'         => $bt->ancestor,
                'provides_context' => $bt->provides_context ?: [],
                'uses_context'     => $bt->uses_context ?: [],
            ];
        }

        $category = isset( $a['category'] ) ? sanitize_key( $a['category'] ) : '';
        $search   = isset( $a['search'] ) ? strtolower( sanitize_text_field( $a['search'] ) ) : '';
        $types    = [];
        foreach ( $registry->get_all_registered() as $bt ) {
            if ( $category !== '' && $bt->category !== $category ) {
                continue;
            }
            if ( $search !== '' && stripos( $bt->name, $search ) === false && stripos( (string) $bt->title, $search ) === false ) {
                continue;
            }
            $types[] = [
                'name'     => $bt->name,
                'title'    => (string) $bt->title,
                'category' => $bt->category,
            ];
        }
        return [ 'count' => count( $types ), 'block_types' => $types ];
    },

    'wp_edit_blocks' => function ( array $a ): array|WP_Error {
        $target = cowboy_mcp_gutenberg_resolve_target( $a, true );
        if ( is_wp_error( $target ) ) {
            return $target;
        }

        if ( ! empty( $a['expected_hash'] ) && md5( $target['content'] ) !== $a['expected_hash'] ) {
            return new WP_Error( 'content_conflict', 'Content changed since you read it (content_hash mismatch). Re-read with wp_list_blocks and recompute paths.' );
        }

        $ops = $a['operations'] ?? [];
        if ( ! is_array( $ops ) ) {
            return new WP_Error( 'invalid_params', 'operations must be an array of operation objects.' );
        }
        $allow = ! empty( $a['allow_unfiltered_html'] );
        $tree  = cowboy_mcp_gutenberg_parse( $target['content'] );

        $index = cowboy_mcp_gutenberg_index_ops( $tree, $ops, $allow );
        if ( is_wp_error( $index ) ) {
            return $index;
        }
        $new_tree = cowboy_mcp_gutenberg_apply_ops( $tree, $index );
        if ( is_wp_error( $new_tree ) ) {
            return $new_tree;
        }

        $new_content = serialize_blocks( $new_tree );
        $result      = wp_update_post( wp_slash( [ 'ID' => $target['post_id'], 'post_content' => $new_content ] ), true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [
            'updated'          => true,
            'post_id'          => $target['post_id'],
            'target'           => [
                'post_id'  => $target['post_id'],
                'template' => $target['template'],
                'title'    => $target['title'],
                'kind'     => $target['kind'],
            ],
            'override_created' => $target['materialized'],
            'operations'       => $index['descriptions'],
            'warnings'         => $index['warnings'],
            'content_hash'     => md5( $new_content ),
            'blocks'           => cowboy_mcp_gutenberg_summarize( cowboy_mcp_gutenberg_parse( $new_content ) ),
        ];
    },

    'wp_list_patterns' => function ( array $a ): array {
        $source   = in_array( $a['source'] ?? 'all', [ 'all', 'registered', 'user' ], true ) ? ( $a['source'] ?? 'all' ) : 'all';
        $category = isset( $a['category'] ) ? sanitize_title( $a['category'] ) : '';
        $search   = isset( $a['search'] ) ? sanitize_text_field( $a['search'] ) : '';
        $patterns = [];

        if ( $source !== 'user' ) {
            foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
                if ( $category !== '' && ! in_array( $category, $p['categories'] ?? [], true ) ) {
                    continue;
                }
                if ( $search !== '' && stripos( $p['name'], $search ) === false && stripos( $p['title'] ?? '', $search ) === false ) {
                    continue;
                }
                $patterns[] = [
                    'name'           => $p['name'],
                    'title'          => $p['title'] ?? $p['name'],
                    'source'         => 'registered',
                    'categories'     => array_values( $p['categories'] ?? [] ),
                    'description'    => $p['description'] ?? '',
                    'viewport_width' => $p['viewportWidth'] ?? null,
                ];
            }
        }

        $total_user = 0;
        if ( $source !== 'registered' ) {
            $per_page = min( max( (int) ( $a['per_page'] ?? 50 ), 1 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );
            $q_args   = [
                'post_type'      => 'wp_block',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ];
            if ( $search !== '' ) {
                $q_args['s'] = $search;
            }
            if ( $category !== '' && taxonomy_exists( 'wp_pattern_category' ) ) {
                $q_args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    [ 'taxonomy' => 'wp_pattern_category', 'field' => 'slug', 'terms' => $category ],
                ];
            }
            $q          = new WP_Query( $q_args );
            $total_user = (int) $q->found_posts;
            foreach ( $q->posts as $post ) {
                $cats = [];
                if ( taxonomy_exists( 'wp_pattern_category' ) ) {
                    $terms = wp_get_post_terms( $post->ID, 'wp_pattern_category', [ 'fields' => 'slugs' ] );
                    $cats  = is_wp_error( $terms ) ? [] : $terms;
                }
                $patterns[] = [
                    'id'         => $post->ID,
                    'title'      => $post->post_title,
                    'source'     => 'user',
                    'synced'     => get_post_meta( $post->ID, 'wp_pattern_sync_status', true ) !== 'unsynced',
                    'categories' => $cats,
                ];
            }
        }

        return [ 'count' => count( $patterns ), 'total_user_patterns' => $total_user, 'patterns' => $patterns ];
    },

    'wp_get_pattern' => function ( array $a ): array|WP_Error {
        $has_name = ! empty( $a['name'] );
        $has_id   = isset( $a['id'] );
        if ( $has_name === $has_id ) {
            return new WP_Error( 'invalid_params', 'Provide exactly one of: name (registered), id (user pattern).' );
        }

        if ( $has_name ) {
            $p = WP_Block_Patterns_Registry::get_instance()->get_registered( sanitize_text_field( $a['name'] ) );
            if ( ! $p ) {
                return new WP_Error( 'not_found', "Registered pattern '{$a['name']}' not found." );
            }
            return [
                'name'       => $p['name'],
                'title'      => $p['title'] ?? $p['name'],
                'source'     => 'registered',
                'categories' => array_values( $p['categories'] ?? [] ),
                'content'    => $p['content'],
                'blocks'     => cowboy_mcp_gutenberg_summarize( cowboy_mcp_gutenberg_parse( $p['content'] ) ),
            ];
        }

        $post = cowboy_mcp_gutenberg_get_user_pattern( (int) $a['id'] );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        $cats = [];
        if ( taxonomy_exists( 'wp_pattern_category' ) ) {
            $terms = wp_get_post_terms( $post->ID, 'wp_pattern_category', [ 'fields' => 'slugs' ] );
            $cats  = is_wp_error( $terms ) ? [] : $terms;
        }
        return [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'source'       => 'user',
            'synced'       => get_post_meta( $post->ID, 'wp_pattern_sync_status', true ) !== 'unsynced',
            'categories'   => $cats,
            'content'      => $post->post_content,
            'content_hash' => md5( $post->post_content ),
            'blocks'       => cowboy_mcp_gutenberg_summarize( cowboy_mcp_gutenberg_parse( $post->post_content ) ),
        ];
    },

    'wp_create_pattern' => function ( array $a ): array|WP_Error {
        $allow   = ! empty( $a['allow_unfiltered_html'] );
        $content = cowboy_mcp_gutenberg_filter_full_content( (string) $a['content'], $allow, 'content' );
        if ( is_wp_error( $content ) ) {
            return $content;
        }
        $post_id = wp_insert_post( wp_slash( [
            'post_type'    => 'wp_block',
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field( $a['title'] ),
            'post_content' => $content,
        ] ), true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        $synced = ! isset( $a['synced'] ) || ! empty( $a['synced'] );
        if ( ! $synced ) {
            update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
        }
        if ( ! empty( $a['categories'] ) && is_array( $a['categories'] ) && taxonomy_exists( 'wp_pattern_category' ) ) {
            wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', $a['categories'] ), 'wp_pattern_category' );
        }
        return [ 'created' => true, 'id' => $post_id, 'title' => get_the_title( $post_id ), 'synced' => $synced ];
    },

    'wp_update_pattern' => function ( array $a ): array|WP_Error {
        $post = cowboy_mcp_gutenberg_get_user_pattern( (int) $a['id'] );
        if ( is_wp_error( $post ) ) {
            // A registered name passed as id is the common mistake; be explicit.
            if ( ! empty( $a['id'] ) && ! is_numeric( $a['id'] ) ) {
                return new WP_Error( 'registered_pattern_readonly', 'Registered (PHP) patterns are read-only; only user patterns (numeric id) can be updated.' );
            }
            return $post;
        }
        $allow   = ! empty( $a['allow_unfiltered_html'] );
        $changed = [];

        $data = [ 'ID' => $post->ID ];
        if ( isset( $a['title'] ) ) {
            $data['post_title'] = sanitize_text_field( $a['title'] );
            $changed[]          = 'title';
        }
        if ( isset( $a['content'] ) ) {
            $content = cowboy_mcp_gutenberg_filter_full_content( (string) $a['content'], $allow, 'content' );
            if ( is_wp_error( $content ) ) {
                return $content;
            }
            $data['post_content'] = $content;
            $changed[]            = 'content';
        }
        if ( count( $data ) > 1 ) {
            $result = wp_update_post( wp_slash( $data ), true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }
        if ( isset( $a['synced'] ) ) {
            if ( empty( $a['synced'] ) ) {
                update_post_meta( $post->ID, 'wp_pattern_sync_status', 'unsynced' );
            } else {
                delete_post_meta( $post->ID, 'wp_pattern_sync_status' );
            }
            $changed[] = 'synced';
        }
        if ( isset( $a['categories'] ) && is_array( $a['categories'] ) && taxonomy_exists( 'wp_pattern_category' ) ) {
            wp_set_object_terms( $post->ID, array_map( 'sanitize_text_field', $a['categories'] ), 'wp_pattern_category' );
            $changed[] = 'categories';
        }
        if ( $changed === [] ) {
            return new WP_Error( 'invalid_params', 'Provide at least one of: title, content, synced, categories.' );
        }
        return [ 'updated' => true, 'id' => $post->ID, 'changed' => $changed ];
    },

    'wp_delete_pattern' => function ( array $a ): array|WP_Error {
        $post = cowboy_mcp_gutenberg_get_user_pattern( (int) $a['id'] );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        global $wpdb;
        // wp:block refs serialize as {"ref":N} (only attr) or {"ref":N, when more follow.
        $like_a = '%' . $wpdb->esc_like( 'wp:block {"ref":' . $post->ID . '}' ) . '%';
        $like_b = '%' . $wpdb->esc_like( 'wp:block {"ref":' . $post->ID . ',' ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $ref_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND post_type NOT IN ('revision','attachment') AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT 11",
            $like_a,
            $like_b
        ) );

        $has_more = count( $ref_ids ) > 10;
        $ref_ids  = array_slice( $ref_ids, 0, 10 );

        $force = ! empty( $a['force'] );
        $title = $post->post_title;
        $ok    = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
        if ( ! $ok ) {
            return new WP_Error( 'delete_failed', "Failed to delete pattern #{$post->ID}." );
        }
        return [
            'deleted'       => true,
            'id'            => $post->ID,
            'title'         => $title,
            'trashed'       => ! $force,
            'referenced_by' => [
                'count'    => count( $ref_ids ),
                'post_ids' => array_map( 'intval', $ref_ids ),
                'has_more' => $has_more,
                'note'     => $ref_ids ? 'These posts contain wp:block refs to this pattern; the refs render empty until the pattern is restored (wp_undo_change).' : null,
            ],
        ];
    },
];

/* ================================================================
 *  Tier 2 — appended only on block themes (Tasks 4-5).
 * ================================================================ */

return [ 'tools' => $cowboy_gutenberg_tools, 'handlers' => $cowboy_gutenberg_handlers ];
