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
];

/* ================================================================
 *  Tier 2 — appended only on block themes (Tasks 4-5).
 * ================================================================ */

return [ 'tools' => $cowboy_gutenberg_tools, 'handlers' => $cowboy_gutenberg_handlers ];
