<?php
/**
 * SiteOrigin domain helpers. Loaded by tools-siteorigin.php only when Page Builder is active.
 */
defined( 'ABSPATH' ) || exit;

const COWBOY_MCP_SO_MAX_ROWS    = 200;
const COWBOY_MCP_SO_MAX_CELLS   = 12;
const COWBOY_MCP_SO_MAX_WIDGETS = 500;
const COWBOY_MCP_SO_BLOCK       = 'siteorigin-panels/layout-block';

/** True for a 0..n-1 indexed array (PHP 8.0-safe array_is_list). */
function cowboy_mcp_siteorigin_is_list( $v ): bool {
    return is_array( $v ) && ( $v === [] || array_keys( $v ) === range( 0, count( $v ) - 1 ) );
}

/** A JSON object as decoded into PHP: an associative array, or [] (an empty {}). */
function cowboy_mcp_siteorigin_is_object_like( $v ): bool {
    return is_array( $v ) && ( $v === [] || ! cowboy_mcp_siteorigin_is_list( $v ) );
}

/** Editable post or a coded error. */
function cowboy_mcp_siteorigin_post( int $post_id ): WP_Post|WP_Error {
    $post = $post_id > 0 ? get_post( $post_id ) : null;
    if ( ! $post || $post->post_type === 'revision' || wp_is_post_autosave( $post ) ) {
        return new WP_Error( 'not_found', "not_found: post #{$post_id} does not exist." );
    }
    if ( $post->post_status === 'trash' ) {
        return new WP_Error( 'post_trashed', "post_trashed: post #{$post_id} is in the trash." );
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return new WP_Error( 'forbidden', "forbidden: you cannot edit post #{$post_id}." );
    }
    return $post;
}

/** SiteOrigin's own legacy normaliser only (priority-5 siteorigin_panels_data callback) — no third-party filters. */
function cowboy_mcp_siteorigin_normalize_stored( $raw ): ?array {
    if ( ! is_array( $raw ) || $raw === [] ) {
        return null;
    }
    if ( method_exists( 'SiteOrigin_Panels', 'single' ) && method_exists( 'SiteOrigin_Panels', 'process_panels_data' ) ) {
        $raw = SiteOrigin_Panels::single()->process_panels_data( $raw );
    }
    return is_array( $raw ) ? $raw : null;
}

/**
 * Raw stored layouts of a post, indexed exactly like SiteOrigin's seam:
 * meta layout + qualifying top-level Layout Blocks (block_index => parse_blocks key).
 */
function cowboy_mcp_siteorigin_stored( WP_Post $post ): array {
    $meta   = cowboy_mcp_siteorigin_normalize_stored( get_post_meta( $post->ID, 'panels_data', true ) );
    $blocks = [];
    if ( class_exists( 'SiteOrigin_Panels_AI_Exposure' ) && method_exists( 'SiteOrigin_Panels_AI_Exposure', 'get_qualifying_block_layouts' ) ) {
        $parsed = parse_blocks( (string) $post->post_content );
        foreach ( SiteOrigin_Panels_AI_Exposure::single()->get_qualifying_block_layouts( $post ) as $entry ) {
            $raw = $parsed[ $entry['block_key'] ]['attrs']['panelsData'] ?? null;
            $pd  = cowboy_mcp_siteorigin_normalize_stored( $raw );
            if ( $pd !== null ) {
                $blocks[ (int) $entry['block_index'] ] = [ 'key' => $entry['block_key'], 'panels_data' => $pd ];
            }
        }
    }
    $untargetable = ! $blocks && has_block( COWBOY_MCP_SO_BLOCK, $post );
    return [ 'meta' => $meta, 'blocks' => $blocks, 'untargetable_block' => $untargetable ];
}

/** SiteOrigin's public read shape { post_id, source, layouts[] } (filtered, as the front end sees it). */
function cowboy_mcp_siteorigin_read( int $post_id ): array|WP_Error {
    if ( class_exists( 'SiteOrigin_Panels_AI_Exposure' ) && method_exists( 'SiteOrigin_Panels_AI_Exposure', 'read_layouts' ) ) {
        $r = SiteOrigin_Panels_AI_Exposure::single()->read_layouts( $post_id );
        return is_wp_error( $r ) ? new WP_Error( 'not_found', "not_found: post #{$post_id} does not exist." ) : $r;
    }
    if ( ! get_post( $post_id ) ) {
        return new WP_Error( 'not_found', "not_found: post #{$post_id} does not exist." );
    }
    $meta = get_post_meta( $post_id, 'panels_data', true );
    $meta = is_array( $meta ) && $meta ? apply_filters( 'siteorigin_panels_data', $meta, $post_id ) : [];
    return [
        'post_id' => $post_id,
        'source'  => $meta ? 'meta' : 'none',
        'layouts' => $meta ? [ [ 'storage' => 'meta', 'block_index' => null, 'panels_data' => $meta ] ] : [],
    ];
}

/**
 * panels_data → row/cell/widget model. Cells attach to rows and widgets to cells in
 * array order (SiteOrigin ignores grid_cells.index), so appending keeps a stable sort.
 *
 * @return array{0: ?array, 1: string[]}
 */
function cowboy_mcp_siteorigin_to_model( $pd ): array {
    if ( ! is_array( $pd ) ) {
        return [ null, [ 'invalid_structure: panels_data must be an object with widgets, grids and grid_cells lists' ] ];
    }
    $errors = [];
    foreach ( [ 'widgets', 'grids', 'grid_cells' ] as $k ) {
        if ( ! cowboy_mcp_siteorigin_is_list( $pd[ $k ] ?? [] ) ) {
            $errors[] = "invalid_structure: {$k} must be a list";
        }
    }
    if ( $errors ) {
        return [ null, $errors ];
    }
    $grids   = $pd['grids'] ?? [];
    $cells   = $pd['grid_cells'] ?? [];
    $widgets = $pd['widgets'] ?? [];
    if ( count( $grids ) > COWBOY_MCP_SO_MAX_ROWS ) {
        $errors[] = 'too_large: at most ' . COWBOY_MCP_SO_MAX_ROWS . ' rows';
    }
    if ( count( $widgets ) > COWBOY_MCP_SO_MAX_WIDGETS ) {
        $errors[] = 'too_large: at most ' . COWBOY_MCP_SO_MAX_WIDGETS . ' widgets';
    }
    $rows = [];
    foreach ( $grids as $r => $g ) {
        if ( ! is_array( $g ) ) {
            $errors[] = "invalid_structure: grids[{$r}] must be an object";
            continue;
        }
        $extra = $g;
        unset( $extra['cells'], $extra['style'] );
        $rows[ $r ] = [ 'style' => is_array( $g['style'] ?? null ) ? $g['style'] : [], 'extra' => $extra, 'cells' => [] ];
    }
    foreach ( $cells as $i => $c ) {
        $g = is_array( $c ) && isset( $c['grid'] ) && is_numeric( $c['grid'] ) ? (int) $c['grid'] : -1;
        if ( ! isset( $rows[ $g ] ) ) {
            $errors[] = "invalid_structure: grid_cells[{$i}].grid does not reference a row";
            continue;
        }
        $w = $c['weight'] ?? null;
        if ( ! is_numeric( $w ) || (float) $w <= 0 ) {
            $errors[] = "invalid_structure: grid_cells[{$i}].weight must be a number > 0";
            continue;
        }
        $extra = $c;
        unset( $extra['grid'], $extra['index'], $extra['weight'], $extra['style'] );
        $rows[ $g ]['cells'][] = [ 'weight' => (float) $w, 'style' => is_array( $c['style'] ?? null ) ? $c['style'] : [], 'extra' => $extra, 'widgets' => [] ];
    }
    foreach ( $rows as $r => $row ) {
        $n = count( $row['cells'] );
        if ( $n === 0 ) {
            $errors[] = "invalid_structure: row {$r} has no cells";
        } elseif ( $n > COWBOY_MCP_SO_MAX_CELLS ) {
            $errors[] = "too_large: row {$r} has {$n} cells (max " . COWBOY_MCP_SO_MAX_CELLS . ')';
        }
    }
    foreach ( $widgets as $i => $w ) {
        if ( ! cowboy_mcp_siteorigin_is_object_like( $w ) || ! is_array( $w['panels_info'] ?? null ) ) {
            $errors[] = "invalid_structure: widgets[{$i}] must be an object with panels_info";
            continue;
        }
        $g = is_numeric( $w['panels_info']['grid'] ?? null ) ? (int) $w['panels_info']['grid'] : -1;
        $c = is_numeric( $w['panels_info']['cell'] ?? null ) ? (int) $w['panels_info']['cell'] : -1;
        if ( ! isset( $rows[ $g ]['cells'][ $c ] ) ) {
            $errors[] = "invalid_structure: widgets[{$i}] panels_info.grid/cell ({$g}/{$c}) does not reference a cell";
            continue;
        }
        $rows[ $g ]['cells'][ $c ]['widgets'][] = $w;
    }
    return [ $errors ? null : array_values( $rows ), $errors ];
}

/** Model → canonical panels_data; renumbers grid/cell/id, drops computed keys. */
function cowboy_mcp_siteorigin_from_model( array $rows ): array {
    $grids   = [];
    $cells   = [];
    $widgets = [];
    $id      = 0;
    foreach ( array_values( $rows ) as $r => $row ) {
        $grids[] = array_merge( $row['extra'], [ 'cells' => count( $row['cells'] ), 'style' => $row['style'] ] );
        foreach ( array_values( $row['cells'] ) as $c => $cell ) {
            $cells[] = array_merge( $cell['extra'], [ 'grid' => $r, 'index' => $c, 'weight' => $cell['weight'], 'style' => $cell['style'] ] );
            foreach ( $cell['widgets'] as $w ) {
                $w['panels_info'] = array_merge( $w['panels_info'], [ 'grid' => $r, 'cell' => $c, 'id' => $id++ ] );
                unset( $w['panels_info']['raw'], $w['panels_info']['cell_index'], $w['panels_info']['widget_index'] );
                $widgets[] = $w;
            }
        }
    }
    return [ 'widgets' => $widgets, 'grids' => $grids, 'grid_cells' => $cells ];
}

/** Read-only overview: rows → cells → widgets with key text, plus how many widgets lack a widget_id. */
function cowboy_mcp_siteorigin_summarize( array $pd ): array {
    [ $rows ] = cowboy_mcp_siteorigin_to_model( $pd );
    if ( $rows === null ) {
        return [ 'rows' => [], 'missing_widget_ids' => 0, 'malformed' => true ];
    }
    $trunc   = static fn( $s ) => mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) ), 0, 200 );
    $missing = 0;
    $out     = [];
    foreach ( $rows as $r => $row ) {
        $cells = [];
        foreach ( $row['cells'] as $c => $cell ) {
            $ws = [];
            foreach ( $cell['widgets'] as $w ) {
                $wid = (string) ( $w['panels_info']['widget_id'] ?? '' );
                if ( $wid === '' ) {
                    $missing++;
                }
                $item = [ 'widget_id' => $wid, 'class' => (string) ( $w['panels_info']['class'] ?? '' ), 'label' => (string) ( $w['panels_info']['label'] ?? '' ) ];
                foreach ( [ 'title', 'headline', 'text', 'content', 'url' ] as $k ) {
                    if ( isset( $w[ $k ] ) && is_scalar( $w[ $k ] ) && (string) $w[ $k ] !== '' ) {
                        $item[ $k ] = $trunc( $w[ $k ] );
                    }
                }
                $ws[] = $item;
            }
            $cells[] = [ 'cell' => $c, 'weight' => $cell['weight'], 'widgets' => $ws ];
        }
        $out[] = [ 'row' => $r, 'style_keys' => array_keys( $row['style'] ), 'cells' => $cells ];
    }
    return [ 'rows' => $out, 'missing_widget_ids' => $missing ];
}

/** The registered widget object for a PHP class name, or null. */
function cowboy_mcp_siteorigin_widget_object( string $class ): ?WP_Widget {
    global $wp_widget_factory;
    $class = ltrim( $class, '\\' );
    if ( $class === '' || ! isset( $wp_widget_factory ) || ! is_array( $wp_widget_factory->widgets ?? null ) ) {
        return null;
    }
    if ( ( $wp_widget_factory->widgets[ $class ] ?? null ) instanceof WP_Widget ) {
        return $wp_widget_factory->widgets[ $class ];
    }
    foreach ( $wp_widget_factory->widgets as $w ) {
        if ( $w instanceof WP_Widget && get_class( $w ) === $class ) {
            return $w;
        }
    }
    return null;
}

/**
 * Widgets Bundle widgets (active and inactive) keyed by folder id. The class name of an
 * inactive widget is read from its own siteorigin_widget_register() call — the file is
 * never included, so nothing activates.
 */
function cowboy_mcp_siteorigin_bundle_widgets( bool $refresh = false ): array {
    static $cache = null;
    if ( $cache !== null && ! $refresh ) {
        return $cache;
    }
    $cache = [];
    if ( ! class_exists( 'SiteOrigin_Widgets_Bundle' ) ) {
        return $cache;
    }
    wp_cache_delete( 'active_widgets', 'siteorigin_widgets' );
    foreach ( (array) SiteOrigin_Widgets_Bundle::single()->get_widgets_list() as $w ) {
        $id      = (string) ( $w['ID'] ?? '' );   // the list is keyed by file path; ID is the folder id
        if ( $id === '' ) {
            continue;
        }
        $class   = null;
        $id_base = null;
        $file    = (string) ( $w['File'] ?? '' );
        if ( $file !== '' && is_readable( $file ) ) {
            $src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( preg_match( '/siteorigin_widget_register\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*__FILE__\s*,\s*[\'"]([^\'"]+)[\'"]/', $src, $m ) ) {
                $id_base = $m[1];
                $class   = ltrim( $m[2], '\\' );
            }
        }
        $cache[ (string) $id ] = [
            'id'          => (string) $id,
            'name'        => wp_strip_all_tags( (string) ( $w['Name'] ?? $id ) ),
            'description' => wp_strip_all_tags( (string) ( $w['Description'] ?? '' ) ),
            'active'      => ! empty( $w['Active'] ),
            'class'       => $class,
            'id_base'     => $id_base,
        ];
    }
    return $cache;
}

/** 'active' (registered now) | 'inactive_bundle' (a Widgets Bundle widget switched off) | 'unknown'. */
function cowboy_mcp_siteorigin_class_status( string $class ): string {
    $class = ltrim( $class, '\\' );
    if ( cowboy_mcp_siteorigin_widget_object( $class ) ) {
        return 'active';
    }
    foreach ( cowboy_mcp_siteorigin_bundle_widgets() as $w ) {
        if ( $w['class'] === $class && ! $w['active'] ) {
            return 'inactive_bundle';
        }
    }
    return 'unknown';
}

/** WP_Error for a class that is not 'active'. */
function cowboy_mcp_siteorigin_class_error( string $class, string $where = '' ): WP_Error {
    $prefix = $where !== '' ? "{$where}: " : '';
    if ( cowboy_mcp_siteorigin_class_status( $class ) === 'inactive_bundle' ) {
        return new WP_Error( 'widget_inactive', "{$prefix}widget_inactive: '{$class}' is an inactive Widgets Bundle widget — activate it with wp_siteorigin_set_widgets_active first." );
    }
    return new WP_Error( 'unknown_widget', "{$prefix}unknown_widget: '{$class}' is not a registered widget class (see wp_siteorigin_list_widgets)." );
}

/** Fallback schema when the bundle's Widget Describer (1.75.0+) is absent: flatten form_options(). */
function cowboy_mcp_siteorigin_flatten_form( array $fields ): array {
    $out = [];
    foreach ( $fields as $name => $f ) {
        if ( ! is_array( $f ) || in_array( $f['type'] ?? '', [ 'html', 'error', 'presets', 'builder' ], true ) ) {
            continue;
        }
        $item = [ 'type' => (string) ( $f['type'] ?? 'text' ), 'label' => wp_strip_all_tags( (string) ( $f['label'] ?? $name ) ) ];
        foreach ( [ 'default', 'options', 'description', 'min', 'max', 'units', 'multiple' ] as $k ) {
            if ( array_key_exists( $k, $f ) ) {
                $item[ $k ] = $f[ $k ];
            }
        }
        if ( is_array( $f['fields'] ?? null ) ) {
            $item['fields'] = cowboy_mcp_siteorigin_flatten_form( $f['fields'] );
        }
        $out[ (string) $name ] = $item;
    }
    return $out;
}

/** Instance keys the bundle keeps but the Describer omits: toggle on/off state and tinymce editor mode. */
function cowboy_mcp_siteorigin_companion_keys( array $fields, string $path = '' ): array {
    $out = [];
    foreach ( $fields as $name => $f ) {
        if ( ! is_array( $f ) ) {
            continue;
        }
        $p    = $path === '' ? (string) $name : "{$path}.{$name}";
        $type = (string) ( $f['type'] ?? '' );
        if ( $type === 'tinymce' ) {
            $out[] = [ 'key' => ( $path === '' ? '' : "{$path}." ) . "{$name}_selected_editor", 'values' => [ 'tinymce', 'html' ] ];
        }
        if ( $type === 'toggle' ) {
            $out[] = [ 'key' => "{$p}.so_field_container_state", 'values' => [ 'open', 'closed' ] ];
        }
        if ( is_array( $f['fields'] ?? null ) && $type !== 'repeater' ) {
            $out = array_merge( $out, cowboy_mcp_siteorigin_companion_keys( $f['fields'], $p ) );
        }
    }
    return $out;
}

/** Registered style fields for row|cell|widget, mirroring SiteOrigin_Panels_Styles_Admin::render_styles_fields(). */
function cowboy_mcp_siteorigin_style_fields( string $level, int $post_id ): array {
    $fields = apply_filters( 'siteorigin_panels_' . $level . '_style_fields', [], $post_id, [] );
    $fields = apply_filters( 'siteorigin_panels_general_style_fields', $fields, $post_id, [] );
    $out    = [];
    foreach ( (array) $fields as $name => $f ) {
        if ( ! is_array( $f ) ) {
            continue;
        }
        $item = [ 'name' => (string) $name, 'type' => (string) ( $f['type'] ?? 'text' ), 'label' => wp_strip_all_tags( (string) ( $f['name'] ?? $f['label'] ?? $name ) ), 'group' => (string) ( $f['group'] ?? '' ) ];
        foreach ( [ 'options', 'default', 'description', 'multiple', 'alpha' ] as $k ) {
            if ( array_key_exists( $k, $f ) ) {
                $item[ $k ] = $k === 'description' ? wp_strip_all_tags( (string) $f[ $k ] ) : $f[ $k ];
            }
        }
        if ( is_array( $f['fields'] ?? null ) ) {
            $item['sub_fields']  = array_keys( $f['fields'] );
            $item['stored_as'] = array_map( static fn( $sub ) => "{$name}_{$sub}", array_keys( $f['fields'] ) );
        }
        $out[] = $item;
    }
    return $out;
}
