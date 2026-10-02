<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Guard — return empty when Beaver Builder is not active.
 * ================================================================ */

if ( ! Cowboy_MCP_Tools::domain_available( __FILE__ ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Helpers
 * ================================================================ */

/** Max nodes accepted by wp_beaver_update_layout. */
const COWBOY_MCP_BEAVER_MAX_NODES = 2000;

/**
 * Settings-form tabs for a module slug, 'row' or 'column' (BB's form id is 'col').
 *
 * @return array|null Tabs array, or null when the type is unknown.
 */
function cowboy_mcp_beaver_form_tabs( string $type ): ?array {
    if ( $type === 'row' || $type === 'column' ) {
        $form = FLBuilderModel::get_settings_form( $type === 'row' ? 'row' : 'col' );
        return is_array( $form ) && isset( $form['tabs'] ) ? (array) $form['tabs'] : null;
    }
    if ( FLBuilderModel::is_module_registered( $type ) ) {
        return (array) FLBuilderModel::$modules[ $type ]->form;
    }
    return null;
}

/** True for a 0..n-1 indexed array (PHP 8.0-safe array_is_list). */
function cowboy_mcp_beaver_is_list( $v ): bool {
    return is_array( $v ) && ( $v === [] || array_keys( $v ) === range( 0, count( $v ) - 1 ) );
}

/** A JSON object as decoded into PHP: an associative array, or [] (an empty {}). */
function cowboy_mcp_beaver_is_object_like( $v ): bool {
    return is_array( $v ) && ( $v === [] || ! cowboy_mcp_beaver_is_list( $v ) );
}

/** Builder flag as BB stores it. */
function cowboy_mcp_beaver_is_enabled( int $post_id ): bool {
    return (bool) get_post_meta( $post_id, '_fl_builder_enabled', true );
}

/** An unpublished editor draft exists and differs from the published layout. */
function cowboy_mcp_beaver_draft_differs( int $post_id ): bool {
    $draft = get_post_meta( $post_id, '_fl_builder_draft', true );
    if ( empty( $draft ) ) {
        return false;
    }
    return serialize( $draft ) !== serialize( get_post_meta( $post_id, '_fl_builder_data', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
}

/**
 * Stored layout (id => stdClass) → list of stdClass in DFS order: roots by
 * position, each node followed by its children; unreachable nodes last.
 * Drops the derived fields BB recomputes on every clean.
 */
function cowboy_mcp_beaver_export_nodes( array $data ): array {
    $nodes = json_decode( (string) wp_json_encode( array_values( $data ) ) );
    if ( ! is_array( $nodes ) ) {
        return [];
    }
    $children = [];
    foreach ( $nodes as $n ) {
        unset( $n->global, $n->dynamic, $n->moduleType ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        if ( ! isset( $n->settings ) || ! is_object( $n->settings ) ) {
            $n->settings = new stdClass();
        }
        $parent                = isset( $n->parent ) && $n->parent !== '' ? (string) $n->parent : '';
        $children[ $parent ][] = $n;
    }
    foreach ( $children as &$kids ) {
        usort( $kids, fn( $x, $y ) => (int) ( $x->position ?? 0 ) <=> (int) ( $y->position ?? 0 ) );
    }
    unset( $kids );
    $out  = [];
    $seen = [];
    $walk = function ( string $parent ) use ( &$walk, &$children, &$out, &$seen ): void {
        foreach ( $children[ $parent ] ?? [] as $n ) {
            $id = (string) $n->node;
            if ( isset( $seen[ $id ] ) ) {
                continue;
            }
            $seen[ $id ] = true;
            $out[]       = $n;
            $walk( $id );
        }
    };
    $walk( '' );
    foreach ( $nodes as $n ) {
        if ( ! isset( $seen[ (string) $n->node ] ) ) {
            $out[] = $n;
        }
    }
    return $out;
}

/** Export list → nested tree for reading (not accepted by update_layout). */
function cowboy_mcp_beaver_summarize( array $nodes ): array {
    $keys = [ 'heading', 'text', 'title', 'content', 'link', 'photo_src', 'alt', 'class', 'id' ];
    $refs = [];
    $root = [];
    // $nodes is DFS-ordered, so a parent is always placed before its children.
    foreach ( $nodes as $n ) {
        $id   = (string) $n->node;
        $item = [ 'node' => $id, 'type' => (string) $n->type ];
        if ( $n->type === 'module' ) {
            $item['module'] = (string) ( $n->settings->type ?? '' );
        }
        $s = [];
        foreach ( $keys as $k ) {
            if ( isset( $n->settings->$k ) && is_scalar( $n->settings->$k ) && $n->settings->$k !== '' ) {
                $v       = wp_strip_all_tags( (string) $n->settings->$k );
                $s[ $k ] = mb_strlen( $v ) > 200 ? mb_substr( $v, 0, 200 ) . '…' : $v;
            }
        }
        if ( $s ) {
            $item['settings'] = $s;
        }
        $item['children'] = [];
        $refs[ $id ]      = $item;
        $parent           = isset( $n->parent ) && $n->parent !== '' ? (string) $n->parent : '';
        if ( $parent !== '' && isset( $refs[ $parent ] ) ) {
            $refs[ $parent ]['children'][] = &$refs[ $id ];
        } else {
            $root[] = &$refs[ $id ];
        }
    }
    return json_decode( (string) wp_json_encode( $root ), true );
}

/** First unsafe fragment (script/iframe tag, inline handler, javascript: URL) in any nested string. */
function cowboy_mcp_beaver_unsafe_match( $value ): ?string {
    if ( is_string( $value ) ) {
        return preg_match( '/<\s*script\b|<\s*iframe\b|<[^>]*\son[a-z]+\s*=|javascript\s*:/i', $value, $m ) ? $m[0] : null;
    }
    if ( is_array( $value ) || is_object( $value ) ) {
        foreach ( (array) $value as $v ) {
            $hit = cowboy_mcp_beaver_unsafe_match( $v );
            if ( $hit !== null ) {
                return $hit;
            }
        }
    }
    return null;
}

/**
 * Validate an agent-supplied flat node list. Nothing is written by this function.
 *
 * @return array{errors: string[], warnings: string[]}
 */
function cowboy_mcp_beaver_validate_nodes( $nodes, bool $allow_unfiltered ): array {
    $errors   = [];
    $warnings = [];
    if ( ! cowboy_mcp_beaver_is_list( $nodes ) || $nodes === [] ) {
        return [ 'errors' => [ 'nodes must be a non-empty array of node objects {node, type, parent, position, settings} — the shape wp_beaver_get_layout returns with summarize=false.' ], 'warnings' => [] ];
    }
    if ( count( $nodes ) > COWBOY_MCP_BEAVER_MAX_NODES ) {
        return [ 'errors' => [ 'nodes has ' . count( $nodes ) . ' entries; the limit is ' . COWBOY_MCP_BEAVER_MAX_NODES . '.' ], 'warnings' => [] ];
    }
    $node_types = [ 'row', 'column-group', 'column', 'module' ];
    $types      = [];
    $parents    = [];
    foreach ( $nodes as $i => $n ) {
        if ( ! is_array( $n ) || cowboy_mcp_beaver_is_list( $n ) ) {
            $errors[] = "nodes[{$i}]: must be an object.";
            continue;
        }
        if ( array_key_exists( 'children', $n ) ) {
            $errors[] = "nodes[{$i}]: has 'children' — that is the read-only summarize=true tree. Send the flat list from wp_beaver_get_layout with summarize=false.";
            continue;
        }
        $id = $n['node'] ?? null;
        if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $id ) ) {
            $errors[] = "nodes[{$i}]: 'node' must be a string of 1-32 letters, digits, _ or -.";
            continue;
        }
        if ( array_key_exists( $id, $types ) ) {
            $errors[] = "node '{$id}': duplicate node id.";
            continue;
        }
        $type = $n['type'] ?? null;
        if ( ! in_array( $type, $node_types, true ) ) {
            $errors[] = "node '{$id}': 'type' must be one of row, column-group, column, module.";
            $type     = null;
        }
        $types[ $id ]   = $type;
        $parent         = $n['parent'] ?? null;
        $parents[ $id ] = ( $parent === '' ) ? null : $parent;
    }
    $allowed_parents = [
        'row'          => [ null ],
        'column-group' => [ 'row', 'column' ],
        'column'       => [ 'column-group' ],
        'module'       => [ 'column', null ],
    ];
    $enabled = (array) FLBuilderModel::get_enabled_modules();
    $checked = [];
    foreach ( $nodes as $n ) {
        if ( ! is_array( $n ) || ! isset( $n['node'] ) || ! is_string( $n['node'] ) || ! array_key_exists( $n['node'], $types ) || array_key_exists( 'children', $n ) || isset( $checked[ $n['node'] ] ) ) {
            continue;
        }
        $id             = $n['node'];
        $checked[ $id ] = true;
        $type           = $types[ $id ];
        $parent         = $parents[ $id ];
        if ( $type !== null ) {
            if ( $parent === null ) {
                if ( ! in_array( null, $allowed_parents[ $type ], true ) ) {
                    $errors[] = "node '{$id}': a {$type} needs a parent (" . implode( ' or ', $allowed_parents[ $type ] ) . ').';
                }
            } elseif ( ! is_string( $parent ) || ! array_key_exists( $parent, $types ) ) {
                $errors[] = "node '{$id}': parent '" . ( is_scalar( $parent ) ? $parent : gettype( $parent ) ) . "' is not in nodes.";
            } elseif ( ! in_array( $types[ $parent ], $allowed_parents[ $type ], true ) ) {
                $errors[] = "node '{$id}': a {$type} cannot sit under a " . ( $types[ $parent ] ?? 'invalid node' ) . '.';
            }
        }
        $settings = $n['settings'] ?? [];
        if ( ! cowboy_mcp_beaver_is_object_like( $settings ) ) {
            $errors[] = "node '{$id}': 'settings' must be an object.";
            $settings = [];
        }
        if ( $type === 'module' ) {
            $slug = $settings['type'] ?? null;
            if ( ! is_string( $slug ) || ! FLBuilderModel::is_module_registered( $slug ) ) {
                $errors[] = "node '{$id}': settings.type '" . ( is_scalar( $slug ) ? $slug : '' ) . "' is not a registered module (see wp_beaver_list_modules).";
            } elseif ( ! in_array( $slug, $enabled, true ) ) {
                $warnings[] = "node '{$id}': module '{$slug}' is disabled in Beaver Builder settings and will not render.";
            } elseif ( $slug === 'html' && ! $allow_unfiltered ) {
                $errors[] = "node '{$id}': the html module writes raw HTML. Pass allow_unfiltered_html: true to permit it.";
            }
        }
        if ( array_key_exists( 'position', $n ) && ( ! is_int( $n['position'] ) || $n['position'] < 0 ) ) {
            $errors[] = "node '{$id}': 'position' must be a non-negative integer.";
        }
        if ( ! $allow_unfiltered ) {
            $hit = cowboy_mcp_beaver_unsafe_match( $settings );
            if ( $hit !== null ) {
                $errors[] = "node '{$id}': settings contain '{$hit}' (script, iframe, inline event handler or javascript: URL). Pass allow_unfiltered_html: true to permit it.";
            }
        }
    }
    // Cycles (e.g. column-group under a column under that same column-group).
    foreach ( array_keys( $parents ) as $start ) {
        $seen = [];
        $cur  = $start;
        for ( $d = 0; $d < 64 && is_string( $cur ) && array_key_exists( $cur, $parents ); $d++ ) {
            if ( isset( $seen[ $cur ] ) ) {
                $errors[] = "node '{$start}': parent chain loops back to '{$cur}'.";
                break;
            }
            $seen[ $cur ] = true;
            $cur          = $parents[ $cur ];
        }
    }
    return [ 'errors' => array_values( array_unique( $errors ) ), 'warnings' => $warnings ];
}

/**
 * Validated list → BB layout data (id => stdClass). Builds fresh objects on
 * every call: BB's slash_settings() mutates nodes in place, so one array must
 * never be handed to two update_layout_data() calls.
 */
function cowboy_mcp_beaver_import_nodes( array $nodes ): array {
    $data      = [];
    $positions = [];
    foreach ( $nodes as $n ) {
        $o = json_decode( (string) wp_json_encode( $n ) );
        unset( $o->global, $o->dynamic, $o->moduleType ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        $o->parent = ( isset( $o->parent ) && $o->parent !== '' ) ? (string) $o->parent : null;
        if ( ! isset( $o->settings ) || ! is_object( $o->settings ) ) {
            $o->settings = new stdClass(); // {} decodes to [] in the args array
        }
        $key = $o->parent ?? '';
        if ( ! isset( $o->position ) ) {
            $o->position = $positions[ $key ] ?? 0;
        }
        $positions[ $key ]         = max( $positions[ $key ] ?? 0, (int) $o->position + 1 );
        $data[ (string) $o->node ] = $o;
    }
    return $data;
}

/** The post a layout write targets, or why it cannot be written. */
function cowboy_mcp_beaver_target_post( int $post_id ): WP_Post|WP_Error {
    $post = get_post( $post_id );
    if ( ! $post || in_array( $post->post_type, [ 'revision', 'attachment', 'nav_menu_item' ], true ) ) {
        return new WP_Error( 'not_found', "Post #{$post_id} not found." );
    }
    if ( $post->post_status === 'trash' ) {
        return new WP_Error( 'post_trashed', "Post #{$post_id} is in the trash. Restore it first." );
    }
    if ( ! in_array( $post->post_type, (array) FLBuilderModel::get_post_types(), true ) ) {
        return new WP_Error( 'post_type_not_enabled', "Beaver Builder is not enabled for post type '{$post->post_type}'. Enable it in Settings → Beaver Builder → Post Types, then retry." );
    }
    return $post;
}

/**
 * Publish a validated node list to a post the way BB's editor Publish does,
 * without editor state: explicit post id, published + draft written (no stale
 * draft), builder flag, asset cache cleared, post_content re-rendered,
 * before/after-save actions fired for BB add-ons and BB's revision hook.
 *
 * @param array      $nodes           Validated agent node list.
 * @param array|null $layout_settings ['css' => …, 'js' => …] subset, or null to keep.
 * @return string[] Warnings.
 */
function cowboy_mcp_beaver_save_layout( int $post_id, array $nodes, ?array $layout_settings ): array {
    $warnings = [];
    FLBuilderModel::set_post_id( $post_id );
    try {
        $settings = FLBuilderModel::get_layout_settings( 'published', $post_id );
        if ( $layout_settings !== null ) {
            $settings = (object) array_merge( (array) $settings, $layout_settings );
        }
        do_action( 'fl_builder_before_save_layout', $post_id, true, cowboy_mcp_beaver_import_nodes( $nodes ), $settings );
        // Draft first, then publish what BB stored, exactly like the editor:
        // BB's own before-update filter stamps a version on new modules only
        // on draft saves, and an unversioned module renders as legacy v1.
        FLBuilderModel::update_layout_data( cowboy_mcp_beaver_import_nodes( $nodes ), 'draft', $post_id );
        $published = unserialize( serialize( FLBuilderModel::get_layout_data( 'draft', $post_id ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- deep copy of trusted in-memory objects
        FLBuilderModel::update_layout_data( $published, 'published', $post_id );
        if ( $layout_settings !== null ) {
            FLBuilderModel::update_layout_settings( $layout_settings, 'published', $post_id );
            FLBuilderModel::update_layout_settings( $layout_settings, 'draft', $post_id );
        }
        update_post_meta( $post_id, '_fl_builder_enabled', true );
        FLBuilderModel::delete_all_asset_cache( $post_id );
        try {
            $html   = (string) FLBuilder::render_editor_content();
            $result = wp_update_post( wp_slash( [ 'ID' => $post_id, 'post_content' => $html ] ), true );
            if ( is_wp_error( $result ) ) {
                $warnings[] = 'post_content_not_refreshed: ' . $result->get_error_message();
            }
        } catch ( \Throwable $e ) {
            $warnings[] = 'post_content_not_refreshed: ' . $e->getMessage();
        }
        do_action( 'fl_builder_after_save_layout', $post_id, true, cowboy_mcp_beaver_import_nodes( $nodes ), $settings );
    } finally {
        FLBuilderModel::reset_post_id();
    }
    return $warnings;
}

/** Dry-run plan for wp_beaver_update_layout: validation + node diff, nothing written. */
function cowboy_mcp_beaver_layout_plan( array $args ): array {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    $post    = cowboy_mcp_beaver_target_post( $post_id );
    if ( is_wp_error( $post ) ) {
        return [ 'valid' => false, 'errors' => [ $post->get_error_code() . ': ' . $post->get_error_message() ] ];
    }
    $allow = ! empty( $args['allow_unfiltered_html'] );
    $v     = cowboy_mcp_beaver_validate_nodes( $args['nodes'] ?? null, $allow );
    if ( ! $allow && ( ! empty( $args['layout_css'] ) || ! empty( $args['layout_js'] ) ) ) {
        $v['errors'][] = 'layout_css/layout_js render unfiltered on the front end. Pass allow_unfiltered_html: true to permit them.';
    }
    $plan = [ 'valid' => ! $v['errors'], 'errors' => $v['errors'], 'warnings' => $v['warnings'], 'would_convert' => ! cowboy_mcp_beaver_is_enabled( $post_id ) ];
    if ( $v['errors'] ) {
        return $plan;
    }
    $old = [];
    foreach ( cowboy_mcp_beaver_export_nodes( FLBuilderModel::get_layout_data( 'published', $post_id ) ) as $n ) {
        $old[ (string) $n->node ] = wp_json_encode( $n );
    }
    $new = [];
    foreach ( cowboy_mcp_beaver_export_nodes( cowboy_mcp_beaver_import_nodes( $args['nodes'] ) ) as $n ) {
        $new[ (string) $n->node ] = wp_json_encode( $n );
    }
    $plan['added']   = array_values( array_map( 'strval', array_keys( array_diff_key( $new, $old ) ) ) );
    $plan['removed'] = array_values( array_map( 'strval', array_keys( array_diff_key( $old, $new ) ) ) );
    $plan['changed'] = array_values( array_map( 'strval', array_keys( array_filter( array_intersect_key( $new, $old ), fn( $j, $id ) => $old[ $id ] !== $j, ARRAY_FILTER_USE_BOTH ) ) ) );
    $plan['node_count']           = count( $new );
    $plan['draft_would_be_lost']  = cowboy_mcp_beaver_draft_differs( $post_id );
    return $plan;
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

$cowboy_mcp_beaver_ro = [
    'readOnlyHint'    => true,
    'destructiveHint' => false,
    'idempotentHint'  => true,
    'openWorldHint'   => false,
];

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_beaver_list_modules', '[Beaver Builder] List registered Beaver Builder module types (slug, name, category, enabled). Use a slug as settings.type of a module node in wp_beaver_update_layout.', [
            'include_disabled' => [ 'type' => 'boolean', 'description' => 'Also list registered modules that are disabled in Beaver Builder settings', 'default' => false ],
        ], [ 'title' => 'List Beaver Builder Modules' ] + $cowboy_mcp_beaver_ro ),

        Cowboy_MCP_Tools::tool( 'wp_beaver_get_module_schema', '[Beaver Builder] Get the settings schema for a module slug, "row" or "column": default settings plus every field (name, type, label, options, default). Use it to build valid node settings.', [
            'type' => [ 'type' => 'string', 'description' => 'Module slug (from wp_beaver_list_modules), "row" or "column"', 'required' => true ],
        ], [ 'title' => 'Get Beaver Builder Module Schema' ] + $cowboy_mcp_beaver_ro ),

        Cowboy_MCP_Tools::tool( 'wp_beaver_list_pages', '[Beaver Builder] List posts/pages built with Beaver Builder, with node/module counts and whether an unpublished editor draft differs from the live layout.', [
            'post_type' => [ 'type' => 'string', 'description' => 'Limit to one post type (default: every Beaver Builder post type)' ],
            'status'    => [ 'type' => 'string', 'description' => 'Post status filter (default: any status except trash)' ],
            'search'    => [ 'type' => 'string', 'description' => 'Search in title/content' ],
            'limit'     => [ 'type' => 'integer', 'description' => 'Max posts (1-100)', 'default' => 20 ],
            'offset'    => [ 'type' => 'integer', 'description' => 'Posts to skip', 'default' => 0 ],
        ], [ 'title' => 'List Beaver Builder Pages' ] + $cowboy_mcp_beaver_ro ),

        Cowboy_MCP_Tools::tool( 'wp_beaver_get_layout', '[Beaver Builder] Get a post\'s Beaver Builder layout. Default: the flat node list {node, type, parent, position, settings} exactly as wp_beaver_update_layout accepts it, plus layout CSS/JS. summarize=true returns a read-only nested overview instead.', [
            'post_id'   => [ 'type' => 'integer', 'description' => 'Post/page ID', 'required' => true ],
            'status'    => [ 'type' => 'string', 'description' => 'published (live layout) or draft (unpublished editor draft)', 'enum' => [ 'published', 'draft' ], 'default' => 'published' ],
            'summarize' => [ 'type' => 'boolean', 'description' => 'Return a nested row → column-group → column → module overview with key text settings', 'default' => false ],
        ], [ 'title' => 'Get Beaver Builder Layout' ] + $cowboy_mcp_beaver_ro ),

        Cowboy_MCP_Tools::tool( 'wp_beaver_update_layout', '[Beaver Builder] Replace a post\'s whole Beaver Builder layout and publish it (undoable). Send the full flat node list (get it with wp_beaver_get_layout, summarize=false, edit, send back). Hierarchy: row → column-group → column → module; modules may also be top-level. Enables the builder on a page that does not use it yet (its post content is replaced by the rendered layout; undo restores it). Overwrites any unpublished editor draft.', [
            'post_id'    => [ 'type' => 'integer', 'description' => 'Post/page ID (its post type must be enabled in Beaver Builder settings)', 'required' => true ],
            'nodes'      => [ 'type' => 'array', 'description' => 'Full flat node list: {node (id, 1-32 of A-Z a-z 0-9 _ -), type (row|column-group|column|module), parent (node id or null), position (int, optional), settings (object; modules need settings.type = a slug from wp_beaver_list_modules)}', 'items' => [ 'type' => 'object' ], 'required' => true ],
            'layout_css' => [ 'type' => 'string', 'description' => 'Replace this layout\'s custom CSS (requires allow_unfiltered_html)' ],
            'layout_js'  => [ 'type' => 'string', 'description' => 'Replace this layout\'s custom JavaScript (requires allow_unfiltered_html)' ],
            'allow_unfiltered_html' => [ 'type' => 'boolean', 'description' => 'Permit the html module, script/iframe/inline-handler/javascript: content and layout CSS/JS. Default false; these render verbatim on the front end and can introduce stored XSS.', 'default' => false ],
        ], [
            'title'           => 'Update Beaver Builder Layout',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [

        'wp_beaver_list_pages' => function ( array $a ): array|WP_Error {
            $types = array_values( array_diff( (array) FLBuilderModel::get_post_types(), [ 'fl-builder-template' ] ) );
            if ( ! empty( $a['post_type'] ) ) {
                $types = [ sanitize_key( (string) $a['post_type'] ) ];
            }
            $limit  = max( 1, min( 100, (int) ( $a['limit'] ?? 20 ) ) );
            $offset = max( 0, (int) ( $a['offset'] ?? 0 ) );
            $q      = new WP_Query( [
                'post_type'      => $types,
                'post_status'    => ! empty( $a['status'] ) ? sanitize_key( (string) $a['status'] ) : [ 'publish', 'draft', 'pending', 'private', 'future' ],
                's'              => isset( $a['search'] ) ? sanitize_text_field( (string) $a['search'] ) : '',
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'meta_query'     => [ [ 'key' => '_fl_builder_enabled', 'value' => '1' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            ] );
            $rows = [];
            foreach ( $q->posts as $p ) {
                $data = get_post_meta( $p->ID, '_fl_builder_data', true );
                $data = is_array( $data ) ? $data : [];
                $mods = 0;
                foreach ( $data as $n ) {
                    if ( is_object( $n ) && ( $n->type ?? '' ) === 'module' ) {
                        $mods++;
                    }
                }
                $rows[] = [
                    'id'                    => (int) $p->ID,
                    'title'                 => $p->post_title,
                    'post_type'             => $p->post_type,
                    'status'                => $p->post_status,
                    'modified'              => $p->post_modified,
                    'node_count'            => count( $data ),
                    'module_count'          => $mods,
                    'has_unpublished_draft' => cowboy_mcp_beaver_draft_differs( (int) $p->ID ),
                ];
            }
            return [ 'total' => (int) $q->found_posts, 'count' => count( $rows ), 'offset' => $offset, 'pages' => $rows ];
        },

        'wp_beaver_get_layout' => function ( array $a ): array|WP_Error {
            $post_id = (int) ( $a['post_id'] ?? 0 );
            $post    = get_post( $post_id );
            if ( ! $post || $post->post_type === 'revision' ) {
                return new WP_Error( 'not_found', "Post #{$post_id} not found." );
            }
            $status = ( $a['status'] ?? 'published' ) === 'draft' ? 'draft' : 'published';
            $data   = FLBuilderModel::get_layout_data( $status, $post_id );
            if ( empty( $data ) ) {
                return new WP_Error( 'not_builder_post', "Post #{$post_id} has no Beaver Builder {$status} layout. Create one with wp_beaver_update_layout." );
            }
            $nodes    = cowboy_mcp_beaver_export_nodes( $data );
            $settings = FLBuilderModel::get_layout_settings( $status, $post_id );
            $out      = [
                'post_id'         => $post_id,
                'title'           => $post->post_title,
                'status'          => $status,
                'builder_enabled' => cowboy_mcp_beaver_is_enabled( $post_id ),
                'node_count'      => count( $nodes ),
                'layout_css'      => (string) ( $settings->css ?? '' ),
                'layout_js'       => (string) ( $settings->js ?? '' ),
            ];
            if ( ! empty( $a['summarize'] ) ) {
                $out['tree'] = cowboy_mcp_beaver_summarize( $nodes );
            } else {
                $out['nodes'] = $nodes;
            }
            return $out;
        },
        'wp_beaver_list_modules' => function ( array $a ): array|WP_Error {
            $enabled = (array) FLBuilderModel::get_enabled_modules();
            $rows    = [];
            foreach ( FLBuilderModel::$modules as $slug => $module ) {
                $is_enabled = in_array( $slug, $enabled, true );
                if ( ! $is_enabled && empty( $a['include_disabled'] ) ) {
                    continue;
                }
                $rows[] = [
                    'slug'     => (string) $slug,
                    'name'     => wp_strip_all_tags( (string) $module->name ),
                    'category' => wp_strip_all_tags( (string) $module->category ),
                    'enabled'  => $is_enabled,
                ];
            }
            usort( $rows, fn( $x, $y ) => strcmp( $x['slug'], $y['slug'] ) );
            return [ 'count' => count( $rows ), 'modules' => $rows ];
        },

        'wp_beaver_get_module_schema' => function ( array $a ): array|WP_Error {
            $type = sanitize_key( (string) ( $a['type'] ?? '' ) );
            $tabs = cowboy_mcp_beaver_form_tabs( $type );
            if ( $tabs === null ) {
                return new WP_Error( 'unknown_module', "Unknown Beaver Builder type '{$type}'. Use a slug from wp_beaver_list_modules, \"row\" or \"column\"." );
            }
            $fields = [];
            foreach ( FLBuilderModel::get_settings_form_fields( $tabs ) as $name => $field ) {
                $row = [
                    'name'  => (string) $name,
                    'type'  => (string) $field['type'],
                    'label' => isset( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : '',
                ];
                if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
                    $row['options'] = array_map( fn( $o ) => is_scalar( $o ) ? wp_strip_all_tags( (string) $o ) : $o, $field['options'] );
                }
                if ( array_key_exists( 'default', $field ) ) {
                    $row['default'] = $field['default'];
                }
                if ( ! empty( $field['multiple'] ) ) {
                    $row['multiple'] = true;
                }
                $fields[] = $row;
            }
            $defaults = in_array( $type, [ 'row', 'column' ], true )
                ? FLBuilderModel::get_settings_form_defaults( $type === 'row' ? 'row' : 'col' )
                : FLBuilderModel::get_module_defaults( $type );
            return [ 'type' => $type, 'defaults' => $defaults, 'fields' => $fields ];
        },

        'wp_beaver_update_layout' => function ( array $a ): array|WP_Error {
            $post_id = (int) ( $a['post_id'] ?? 0 );
            $post    = cowboy_mcp_beaver_target_post( $post_id );
            if ( is_wp_error( $post ) ) {
                return $post;
            }
            $allow = ! empty( $a['allow_unfiltered_html'] );
            $v     = cowboy_mcp_beaver_validate_nodes( $a['nodes'] ?? null, $allow );
            $layout_settings = null;
            foreach ( [ 'layout_css' => 'css', 'layout_js' => 'js' ] as $arg => $key ) {
                if ( array_key_exists( $arg, $a ) ) {
                    $layout_settings         = $layout_settings ?? [];
                    $layout_settings[ $key ] = (string) $a[ $arg ];
                }
            }
            if ( ! $allow && $layout_settings !== null && implode( '', $layout_settings ) !== '' ) {
                $v['errors'][] = 'layout_css/layout_js render unfiltered on the front end. Pass allow_unfiltered_html: true to permit them.';
            }
            if ( $v['errors'] ) {
                $msg = implode( "\n", array_slice( $v['errors'], 0, 20 ) );
                if ( count( $v['errors'] ) > 20 ) {
                    $msg .= "\n… and " . ( count( $v['errors'] ) - 20 ) . ' more.';
                }
                $code = str_contains( $msg, 'allow_unfiltered_html' ) ? 'unfiltered_html_blocked' : 'invalid_layout';
                return new WP_Error( $code, "Layout not saved (nothing was written):\n" . $msg );
            }
            $warnings  = $v['warnings'];
            $converted = ! cowboy_mcp_beaver_is_enabled( $post_id );
            if ( cowboy_mcp_beaver_draft_differs( $post_id ) ) {
                $warnings[] = 'draft_overwritten: an unpublished Beaver Builder editor draft existed and was replaced by this layout (undo restores it).';
            }
            $warnings = array_merge( $warnings, cowboy_mcp_beaver_save_layout( $post_id, $a['nodes'], $layout_settings ) );
            $counts   = [ 'row' => 0, 'column' => 0, 'module' => 0 ];
            foreach ( $a['nodes'] as $n ) {
                if ( isset( $counts[ $n['type'] ] ) ) {
                    $counts[ $n['type'] ]++;
                }
            }
            return [
                'post_id'   => $post_id,
                'nodes'     => count( $a['nodes'] ),
                'rows'      => $counts['row'],
                'columns'   => $counts['column'],
                'modules'   => $counts['module'],
                'converted' => $converted,
                'warnings'  => $warnings,
            ];
        },
    ],
];
