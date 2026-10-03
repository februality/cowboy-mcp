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

/** Where a write lands — mirrors SiteOrigin_Panels_Abilities::layout_update() routing. */
function cowboy_mcp_siteorigin_target( WP_Post $post, ?int $block_index ): array|WP_Error {
    $stored = cowboy_mcp_siteorigin_stored( $post );
    $id     = (int) $post->ID;
    $meta_target = static function () use ( $post, $stored, $id ) {
        $types = function_exists( 'siteorigin_panels_setting' ) ? (array) siteorigin_panels_setting( 'post-types' ) : [];
        if ( ! in_array( $post->post_type, $types, true ) ) {
            return new WP_Error( 'post_type_not_enabled', "post_type_not_enabled: Page Builder is not enabled for post type '{$post->post_type}' (see wp_siteorigin_get_settings post-types)." );
        }
        return [ 'storage' => 'meta', 'block_index' => null, 'current' => $stored['meta'] ?? [] ];
    };
    if ( $block_index === null && $stored['meta'] !== null ) {
        return $meta_target();
    }
    if ( $stored['blocks'] ) {
        $n = count( $stored['blocks'] );
        if ( $block_index === null ) {
            if ( $n > 1 ) {
                return new WP_Error( 'block_ambiguous', "block_ambiguous: post #{$id} has {$n} Layout Blocks; pass block_index 0-" . ( $n - 1 ) . '.' );
            }
            $block_index = 0;
        }
        if ( ! isset( $stored['blocks'][ $block_index ] ) ) {
            return new WP_Error( 'block_ambiguous', "block_ambiguous: post #{$id} has no Layout Block {$block_index}; valid 0-" . ( $n - 1 ) . '.' );
        }
        return [ 'storage' => 'block', 'block_index' => $block_index, 'current' => $stored['blocks'][ $block_index ]['panels_data'] ];
    }
    if ( $stored['untargetable_block'] ) {
        return new WP_Error( 'unsupported', "unsupported: post #{$id} has a Layout Block nested in another block or without layout data; SiteOrigin cannot target it." );
    }
    if ( $block_index !== null ) {
        return new WP_Error( 'block_ambiguous', "block_ambiguous: post #{$id} has no Layout Block; omit block_index." );
    }
    return $meta_target();
}

/**
 * Validate + normalise a candidate panels_data (all-or-nothing).
 *
 * @return array{data: ?array, errors: string[], warnings: string[], generated: string[]}
 */
function cowboy_mcp_siteorigin_prepare( $pd ): array {
    [ $rows, $errors ] = cowboy_mcp_siteorigin_to_model( $pd );
    if ( $rows === null ) {
        return [ 'data' => null, 'errors' => $errors, 'warnings' => [], 'generated' => [] ];
    }
    $warnings  = [];
    $generated = [];
    $seen      = [];
    foreach ( $rows as $r => &$row ) {
        $sum = 0.0;
        foreach ( $row['cells'] as $c => &$cell ) {
            $sum += $cell['weight'];
            foreach ( $cell['widgets'] as $k => &$w ) {
                $addr  = "row {$r} cell {$c} widget {$k}";
                $class = ltrim( (string) ( $w['panels_info']['class'] ?? '' ), '\\' );
                $w['panels_info']['class'] = $class;
                if ( cowboy_mcp_siteorigin_class_status( $class ) !== 'active' ) {
                    $errors[] = cowboy_mcp_siteorigin_class_error( $class, $addr )->get_error_message();
                }
                $wid = (string) ( $w['panels_info']['widget_id'] ?? '' );
                if ( $wid === '' ) {
                    $wid                            = wp_generate_uuid4();
                    $w['panels_info']['widget_id']  = $wid;
                    $generated[]                    = $wid;
                }
                if ( isset( $seen[ $wid ] ) ) {
                    $errors[] = "{$addr}: duplicate_widget_id {$wid}";
                }
                $seen[ $wid ] = true;
            }
            unset( $w );
        }
        unset( $cell );
        if ( $sum < 0.99 || $sum > 1.01 ) {
            $warnings[] = "row {$r}: weights_not_normalised (cell weights sum to " . round( $sum, 4 ) . '; Page Builder expects 1)';
        }
    }
    unset( $row );
    $data = cowboy_mcp_siteorigin_from_model( $rows );
    if ( ! $errors && $data['grids'] && class_exists( 'SiteOrigin_Panels_Admin' ) && method_exists( 'SiteOrigin_Panels_Admin', 'decode_panels_data' )
        && SiteOrigin_Panels_Admin::decode_panels_data( (string) wp_json_encode( $data ) ) === null ) {
        $errors[] = 'invalid_structure: Page Builder rejected the layout structure';
    }
    return [ 'data' => $errors ? null : $data, 'errors' => $errors, 'warnings' => $warnings, 'generated' => $generated ];
}

/** Widgets indexed by widget_id, without positional/computed panels_info keys. */
function cowboy_mcp_siteorigin_widget_index( array $pd ): array {
    $out = [];
    foreach ( (array) ( $pd['widgets'] ?? [] ) as $w ) {
        $id = is_array( $w ) ? (string) ( $w['panels_info']['widget_id'] ?? '' ) : '';
        if ( $id === '' ) {
            continue;
        }
        unset( $w['panels_info']['id'], $w['panels_info']['raw'], $w['panels_info']['cell_index'], $w['panels_info']['widget_index'] );
        $out[ $id ] = $w;
    }
    return $out;
}

function cowboy_mcp_siteorigin_diff( array $old, array $new ): array {
    $a       = cowboy_mcp_siteorigin_widget_index( $old );
    $b       = cowboy_mcp_siteorigin_widget_index( $new );
    $changed = [];
    foreach ( array_intersect_key( $b, $a ) as $id => $w ) {
        if ( wp_json_encode( $w ) !== wp_json_encode( $a[ $id ] ) ) {
            $changed[] = (string) $id;
        }
    }
    return [
        'rows_before'     => count( (array) ( $old['grids'] ?? [] ) ),
        'rows_after'      => count( (array) ( $new['grids'] ?? [] ) ),
        'widgets_added'   => array_map( 'strval', array_keys( array_diff_key( $b, $a ) ) ),
        'widgets_removed' => array_map( 'strval', array_keys( array_diff_key( $a, $b ) ) ),
        'widgets_changed' => $changed,
    ];
}

/** Widgets whose instance changes under SiteOrigin's forced kses floor (pure — no update() calls). */
function cowboy_mcp_siteorigin_would_strip( array $pd ): array {
    if ( ! class_exists( 'SiteOrigin_Panels_Admin' ) || ! method_exists( 'SiteOrigin_Panels_Admin', 'kses_deep' ) ) {
        return [];
    }
    $out = [];
    foreach ( (array) ( $pd['widgets'] ?? [] ) as $w ) {
        $inst = $w;
        unset( $inst['panels_info'] );
        $floored = SiteOrigin_Panels_Admin::kses_deep( $inst );
        $keys    = [];
        foreach ( $inst as $k => $v ) {
            if ( ( $floored[ $k ] ?? null ) !== $v ) {
                $keys[] = (string) $k;
            }
        }
        if ( $keys ) {
            $out[] = [ 'widget_id' => (string) ( $w['panels_info']['widget_id'] ?? '' ), 'keys' => $keys ];
        }
    }
    return $out;
}

/** Shared by handlers and dry-run: resolve post + target, build the candidate layout, validate it. */
function cowboy_mcp_siteorigin_build( string $tool, array $a ): array|WP_Error {
    $post = cowboy_mcp_siteorigin_post( (int) ( $a['post_id'] ?? 0 ) );
    if ( is_wp_error( $post ) ) {
        return $post;
    }
    $bi     = isset( $a['block_index'] ) && $a['block_index'] !== null ? (int) $a['block_index'] : null;
    $target = cowboy_mcp_siteorigin_target( $post, $bi );
    if ( is_wp_error( $target ) ) {
        return $target;
    }
    $current    = is_array( $target['current'] ) ? $target['current'] : [];
    $op_results = [];
    switch ( $tool ) {
        case 'wp_siteorigin_update_layout':
            $input = $a['panels_data'] ?? null;
            if ( ! is_array( $input ) ) {
                return new WP_Error( 'invalid_structure', 'invalid_structure: panels_data must be an object with widgets, grids and grid_cells lists.' );
            }
            break;
        case 'wp_siteorigin_edit_layout':
            $r = cowboy_mcp_siteorigin_apply_ops( $current, $a['ops'] ?? null );
            if ( is_wp_error( $r ) ) {
                return $r;
            }
            $input      = $r['panels_data'];
            $op_results = $r['results'];
            break;
        default:
            return new WP_Error( 'invalid_tool', "invalid_tool: {$tool} is not a SiteOrigin layout writer." );
    }
    $prep = cowboy_mcp_siteorigin_prepare( $input );
    return [ 'post' => $post, 'target' => $target, 'current' => $current, 'op_results' => $op_results ] + $prep;
}

/** Persist through SiteOrigin's own sanitized seam (kses floor forced, copy-content refreshed). */
function cowboy_mcp_siteorigin_persist( int $post_id, array $data, ?int $block_index ): array|WP_Error {
    if ( class_exists( 'SiteOrigin_Panels_Revisions' ) ) {
        SiteOrigin_Panels_Revisions::single();   // wp-admin-only by default: makes REST revisions carry panels_data
    }
    $input = [ 'post_id' => $post_id, 'panels_data' => $data ];
    if ( $block_index !== null ) {
        $input['block_index'] = $block_index;
    }
    $r = SiteOrigin_Panels_Abilities::single()->layout_update( $input );
    if ( is_wp_error( $r ) ) {
        return $r;
    }
    if ( empty( $r['updated'] ) ) {
        $code = match ( (string) ( $r['source'] ?? '' ) ) {
            'block-ambiguous' => 'block_ambiguous',
            'unsupported'     => 'unsupported',
            default           => 'siteorigin_write_failed',
        };
        return new WP_Error( $code, "{$code}: SiteOrigin refused the write: " . (string) ( $r['message'] ?? 'unknown reason' ) );
    }
    return $r;
}

/** After a write: which widgets SiteOrigin's sanitizer changed (keys), so the agent sees stripped content. */
function cowboy_mcp_siteorigin_sanitizer_warnings( int $post_id, ?int $block_index, array $sent ): array {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return [];
    }
    $stored = cowboy_mcp_siteorigin_stored( $post );
    $now    = $block_index === null ? ( $stored['meta'] ?? [] ) : ( $stored['blocks'][ $block_index ]['panels_data'] ?? [] );
    $a      = cowboy_mcp_siteorigin_widget_index( $sent );
    $b      = cowboy_mcp_siteorigin_widget_index( $now );
    $out    = [];
    foreach ( $a as $id => $w ) {
        if ( ! isset( $b[ $id ] ) ) {
            $out[] = [ 'widget_id' => (string) $id, 'dropped' => true ];
            continue;
        }
        $keys = [];
        foreach ( array_unique( array_merge( array_keys( $w ), array_keys( $b[ $id ] ) ) ) as $k ) {
            if ( in_array( $k, [ 'panels_info', '_sow_form_id', '_sow_form_timestamp' ], true ) ) {
                continue;
            }
            if ( wp_json_encode( $w[ $k ] ?? null ) !== wp_json_encode( $b[ $id ][ $k ] ?? null ) ) {
                $keys[] = (string) $k;
            }
        }
        if ( $keys ) {
            $out[] = [ 'widget_id' => (string) $id, 'keys_changed' => $keys ];
        }
    }
    return $out;
}

/** Dry-run plan for every layout writer. */
function cowboy_mcp_siteorigin_layout_plan( string $tool, array $a ): array {
    $b = cowboy_mcp_siteorigin_build( $tool, $a );
    if ( is_wp_error( $b ) ) {
        return [ 'valid' => false, 'errors' => [ $b->get_error_message() ] ];
    }
    $plan = [
        'valid'       => ! $b['errors'],
        'errors'      => $b['errors'],
        'warnings'    => $b['warnings'],
        'storage'     => $b['target']['storage'],
        'block_index' => $b['target']['block_index'],
        'op_results'  => $b['op_results'],
    ];
    if ( $b['data'] !== null ) {
        $plan += cowboy_mcp_siteorigin_diff( $b['current'], $b['data'] );
        $plan['widget_ids_generated'] = $b['generated'];
        $plan['would_strip']          = cowboy_mcp_siteorigin_would_strip( $b['data'] );
    }
    return $plan;
}

/** Handler body shared by update_layout / edit_layout / apply_prebuilt_layout. */
function cowboy_mcp_siteorigin_write_layout( string $tool, array $a ): array|WP_Error {
    $b = cowboy_mcp_siteorigin_build( $tool, $a );
    if ( is_wp_error( $b ) ) {
        return $b;
    }
    if ( $b['errors'] ) {
        return new WP_Error( 'invalid_layout', implode( ' | ', $b['errors'] ) );
    }
    $post_id = (int) $b['post']->ID;
    $bi      = $b['target']['block_index'];
    $r       = cowboy_mcp_siteorigin_persist( $post_id, $b['data'], $bi );
    if ( is_wp_error( $r ) ) {
        return $r;
    }
    $warnings = $b['warnings'];
    foreach ( cowboy_mcp_siteorigin_sanitizer_warnings( $post_id, $bi, $b['data'] ) as $w ) {
        $warnings[] = $w;
    }
    $out = [
        'post_id'              => $post_id,
        'storage'              => $b['target']['storage'],
        'block_index'          => $bi,
        'rows'                 => count( $b['data']['grids'] ),
        'widgets'              => count( $b['data']['widgets'] ),
        'widget_ids_generated' => $b['generated'],
        'warnings'             => $warnings,
    ];
    if ( $b['op_results'] ) {
        $out['op_results'] = $b['op_results'];
    }
    return $out;
}

/**
 * Apply addressed ops with snapshot addressing: every row/cell/widget address refers to
 * the layout before the call. Validation and conflicts are checked for all ops first
 * (nothing applied on error); then updates, then widget moves/adds/deletes, then rows.
 * Positions (add_widget/move_widget position, add_row position, move_row to) are indexes
 * in the list as it stands when that op runs, clamped to the end.
 */
function cowboy_mcp_siteorigin_apply_ops( array $current, $ops ): array|WP_Error {
    if ( ! cowboy_mcp_siteorigin_is_list( $ops ) || count( $ops ) < 1 || count( $ops ) > 50 ) {
        return new WP_Error( 'invalid_ops', 'invalid_ops: ops must be a list of 1-50 operations.' );
    }
    $base = $current ?: [ 'widgets' => [], 'grids' => [], 'grid_cells' => [] ];
    [ $rows, $errs ] = cowboy_mcp_siteorigin_to_model( $base );
    if ( $rows === null ) {
        return new WP_Error( 'invalid_structure', 'invalid_structure: the stored layout is malformed: ' . implode( '; ', $errs ) );
    }
    $where = [];
    foreach ( $rows as $r => $row ) {
        foreach ( $row['cells'] as $c => $cell ) {
            foreach ( $cell['widgets'] as $k => $w ) {
                $id = (string) ( $w['panels_info']['widget_id'] ?? '' );
                if ( $id !== '' ) {
                    $where[ $id ] = [ $r, $c, $k ];
                }
            }
        }
    }
    $nrows   = count( $rows );
    $row_ok  = static fn( $r ) => is_int( $r ) && $r >= 0 && $r < $nrows;
    $cell_ok = static fn( $r, $c ) => $row_ok( $r ) && is_int( $c ) && isset( $rows[ $r ]['cells'][ $c ] );
    $num_ok  = static fn( $v ) => is_numeric( $v ) && (float) $v > 0;
    $known   = [ 'add_row', 'update_row', 'move_row', 'delete_row', 'add_widget', 'update_widget', 'move_widget', 'delete_widget' ];
    $errors  = [];
    $del_rows = [];
    $touch_r  = [];
    $touch_w  = [];

    // Pass 1 — shape + addresses.
    foreach ( $ops as $i => $op ) {
        $t = is_array( $op ) ? (string) ( $op['op'] ?? '' ) : '';
        if ( ! in_array( $t, $known, true ) ) {
            $errors[] = "ops[{$i}]: unknown op '{$t}'";
            continue;
        }
        switch ( $t ) {
            case 'update_row':
            case 'move_row':
            case 'delete_row':
                $r = $op['row'] ?? null;
                if ( ! $row_ok( $r ) ) {
                    $errors[] = "ops[{$i}]: row out of range (0-" . ( $nrows - 1 ) . ')';
                    break;
                }
                if ( isset( $touch_r[ $r ][ $t ] ) ) {
                    $errors[] = "ops[{$i}]: op_conflict — row {$r} already has a {$t} op";
                }
                $touch_r[ $r ][ $t ] = $i;
                if ( $t === 'delete_row' ) {
                    $del_rows[ $r ] = $i;
                }
                if ( $t === 'move_row' && ! ( is_int( $op['to'] ?? null ) && $op['to'] >= 0 ) ) {
                    $errors[] = "ops[{$i}]: to must be an integer >= 0";
                }
                if ( $t === 'update_row' && isset( $op['weights'] ) ) {
                    $ws = $op['weights'];
                    if ( ! cowboy_mcp_siteorigin_is_list( $ws ) || count( $ws ) !== count( $rows[ $r ]['cells'] ) || count( array_filter( $ws, $num_ok ) ) !== count( $ws ) ) {
                        $errors[] = "ops[{$i}]: weights must list one number > 0 per cell (" . count( $rows[ $r ]['cells'] ) . ')';
                    }
                }
                if ( $t === 'update_row' && isset( $op['style'] ) && ! cowboy_mcp_siteorigin_is_object_like( $op['style'] ) ) {
                    $errors[] = "ops[{$i}]: style must be an object";
                }
                break;
            case 'add_row':
                $cells = $op['cells'] ?? [ 1 ];
                if ( ! cowboy_mcp_siteorigin_is_list( $cells ) || count( $cells ) < 1 || count( $cells ) > COWBOY_MCP_SO_MAX_CELLS || count( array_filter( $cells, $num_ok ) ) !== count( $cells ) ) {
                    $errors[] = "ops[{$i}]: cells must list 1-" . COWBOY_MCP_SO_MAX_CELLS . ' weights > 0';
                }
                break;
            case 'add_widget':
                if ( ! $cell_ok( $op['row'] ?? null, $op['cell'] ?? null ) ) {
                    $errors[] = "ops[{$i}]: row/cell does not exist";
                }
                if ( ! is_string( $op['class'] ?? null ) || $op['class'] === '' ) {
                    $errors[] = "ops[{$i}]: class is required";
                }
                if ( isset( $op['instance'] ) && ! cowboy_mcp_siteorigin_is_object_like( $op['instance'] ) ) {
                    $errors[] = "ops[{$i}]: instance must be an object";
                }
                break;
            default: // update_widget / move_widget / delete_widget
                $id = (string) ( $op['widget_id'] ?? '' );
                if ( $id === '' ) {
                    $errors[] = "ops[{$i}]: widget_id_required";
                    break;
                }
                if ( ! isset( $where[ $id ] ) ) {
                    $errors[] = "ops[{$i}]: widget {$id} not found (widgets without a widget_id must be backfilled with wp_siteorigin_update_layout first: widget_id_required)";
                    break;
                }
                if ( isset( $touch_w[ $id ][ $t ] ) ) {
                    $errors[] = "ops[{$i}]: op_conflict — widget {$id} already has a {$t} op";
                }
                $touch_w[ $id ][ $t ] = $i;
                if ( $t === 'move_widget' && ! $cell_ok( $op['row'] ?? null, $op['cell'] ?? null ) ) {
                    $errors[] = "ops[{$i}]: target row/cell does not exist";
                }
                if ( $t === 'update_widget' && isset( $op['instance'] ) && ! cowboy_mcp_siteorigin_is_object_like( $op['instance'] ) ) {
                    $errors[] = "ops[{$i}]: instance must be an object";
                }
                break;
        }
    }
    // Pass 2 — conflicts.
    foreach ( $touch_w as $id => $ts ) {
        if ( isset( $ts['delete_widget'] ) && count( $ts ) > 1 ) {
            $errors[] = "op_conflict: widget {$id} is deleted and also targeted by " . implode( '/', array_diff( array_keys( $ts ), [ 'delete_widget' ] ) );
        }
        if ( isset( $del_rows[ $where[ $id ][0] ] ) ) {
            $errors[] = "op_conflict: widget {$id} is in row {$where[ $id ][0]}, which is deleted";
        }
    }
    foreach ( $touch_r as $r => $ts ) {
        if ( isset( $ts['delete_row'] ) && count( $ts ) > 1 ) {
            $errors[] = "op_conflict: row {$r} is deleted and also targeted by " . implode( '/', array_diff( array_keys( $ts ), [ 'delete_row' ] ) );
        }
    }
    foreach ( $ops as $i => $op ) {
        if ( in_array( $op['op'] ?? '', [ 'add_widget', 'move_widget' ], true ) && is_int( $op['row'] ?? null ) && isset( $del_rows[ $op['row'] ] ) ) {
            $errors[] = "ops[{$i}]: op_conflict — target row {$op['row']} is deleted";
        }
    }
    if ( $errors ) {
        return new WP_Error( 'invalid_ops', implode( ' | ', array_unique( $errors ) ) );
    }

    // Pass 3 — in-place updates (pre-call addresses are still valid).
    foreach ( $ops as $op ) {
        if ( $op['op'] === 'update_widget' ) {
            [ $r, $c, $k ] = $where[ $op['widget_id'] ];
            $w = $rows[ $r ]['cells'][ $c ]['widgets'][ $k ];
            foreach ( (array) ( $op['instance'] ?? [] ) as $key => $v ) {
                if ( $key === 'panels_info' ) {
                    continue;
                }
                if ( $v === null ) {
                    unset( $w[ $key ] );
                } else {
                    $w[ $key ] = $v;
                }
            }
            if ( isset( $op['style'] ) && is_array( $op['style'] ) ) {
                $w['panels_info']['style'] = array_merge( (array) ( $w['panels_info']['style'] ?? [] ), $op['style'] );
            }
            $rows[ $r ]['cells'][ $c ]['widgets'][ $k ] = $w;
        } elseif ( $op['op'] === 'update_row' ) {
            $r = $op['row'];
            if ( isset( $op['style'] ) && is_array( $op['style'] ) ) {
                $rows[ $r ]['style'] = array_merge( $rows[ $r ]['style'], $op['style'] );
            }
            foreach ( (array) ( $op['weights'] ?? [] ) as $c => $wt ) {
                $rows[ $r ]['cells'][ $c ]['weight'] = (float) $wt;
            }
        }
    }
    // Pass 4 — widgets out (delete/move), then in (move/add) in op order.
    $moved = [];
    foreach ( $ops as $op ) {
        if ( in_array( $op['op'], [ 'delete_widget', 'move_widget' ], true ) ) {
            [ $r, $c, $k ] = $where[ $op['widget_id'] ];
            if ( $op['op'] === 'move_widget' ) {
                $moved[ $op['widget_id'] ] = $rows[ $r ]['cells'][ $c ]['widgets'][ $k ];
            }
            $rows[ $r ]['cells'][ $c ]['widgets'][ $k ] = null;
        }
    }
    foreach ( $rows as $r => $row ) {
        foreach ( $row['cells'] as $c => $cell ) {
            $rows[ $r ]['cells'][ $c ]['widgets'] = array_values( array_filter( $cell['widgets'], static fn( $w ) => $w !== null ) );
        }
    }
    $results = [];
    foreach ( $ops as $i => $op ) {
        if ( $op['op'] !== 'add_widget' && $op['op'] !== 'move_widget' ) {
            continue;
        }
        if ( $op['op'] === 'add_widget' ) {
            $w = (array) ( $op['instance'] ?? [] );
            unset( $w['panels_info'] );
            $id               = wp_generate_uuid4();
            $w['panels_info'] = [ 'class' => ltrim( (string) $op['class'], '\\' ), 'widget_id' => $id, 'style' => is_array( $op['style'] ?? null ) ? $op['style'] : [] ];
            $results[]        = [ 'op' => $i, 'widget_id' => $id ];
        } else {
            $w = $moved[ $op['widget_id'] ];
        }
        $list = $rows[ $op['row'] ]['cells'][ $op['cell'] ]['widgets'];
        $pos  = is_int( $op['position'] ?? null ) ? max( 0, min( $op['position'], count( $list ) ) ) : count( $list );
        array_splice( $list, $pos, 0, [ $w ] );
        $rows[ $op['row'] ]['cells'][ $op['cell'] ]['widgets'] = $list;
    }
    // Pass 5 — rows: drop deleted, then move/add in op order.
    $order = [];
    foreach ( array_keys( $rows ) as $r ) {
        if ( ! isset( $del_rows[ $r ] ) ) {
            $order[] = $r;
        }
    }
    $added = [];
    foreach ( $ops as $i => $op ) {
        if ( $op['op'] === 'move_row' ) {
            $order = array_values( array_filter( $order, static fn( $k ) => $k !== $op['row'] ) );
            array_splice( $order, min( $op['to'], count( $order ) ), 0, [ $op['row'] ] );
        } elseif ( $op['op'] === 'add_row' ) {
            $key           = "n{$i}";
            $added[ $key ] = [
                'style' => is_array( $op['style'] ?? null ) ? $op['style'] : [],
                'extra' => [],
                'cells' => array_map( static fn( $wt ) => [ 'weight' => (float) $wt, 'style' => [], 'extra' => [], 'widgets' => [] ], array_values( $op['cells'] ?? [ 1 ] ) ),
            ];
            $pos = is_int( $op['position'] ?? null ) ? max( 0, min( $op['position'], count( $order ) ) ) : count( $order );
            array_splice( $order, $pos, 0, [ $key ] );
        }
    }
    $final = [];
    foreach ( $order as $idx => $k ) {
        $final[] = is_int( $k ) ? $rows[ $k ] : $added[ $k ];
        if ( ! is_int( $k ) ) {
            $results[] = [ 'op' => (int) substr( $k, 1 ), 'row_added_at' => $idx ];
        }
    }
    return [ 'panels_data' => cowboy_mcp_siteorigin_from_model( $final ), 'results' => $results ];
}
