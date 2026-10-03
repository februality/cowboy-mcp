<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Guard — return empty when SiteOrigin Page Builder is not active.
 * ================================================================ */

if ( ! Cowboy_MCP_Tools::domain_available( __FILE__ ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

require_once __DIR__ . '/helpers.php';

$cowboy_mcp_so_features = Cowboy_MCP_Tools::siteorigin_features();

$cowboy_mcp_so_ro = [
    'readOnlyHint'    => true,
    'destructiveHint' => false,
    'idempotentHint'  => true,
    'openWorldHint'   => false,
];
$cowboy_mcp_so_rw = [
    'readOnlyHint'    => false,
    'destructiveHint' => false,
    'idempotentHint'  => true,
    'openWorldHint'   => false,
];

$cowboy_mcp_so_tools = [
    Cowboy_MCP_Tools::tool( 'wp_siteorigin_list_pages', '[SiteOrigin] List posts/pages that have a SiteOrigin Page Builder layout (classic panels_data or Layout Blocks), with row/widget counts and storage source.', [
        'post_type' => [ 'type' => 'string', 'description' => 'Limit to one post type (default: any)' ],
        'status'    => [ 'type' => 'string', 'description' => 'Post status filter (default: any status except trash)' ],
        'search'    => [ 'type' => 'string', 'description' => 'Search in title/content' ],
        'limit'     => [ 'type' => 'integer', 'description' => 'Max posts (1-100)', 'default' => 20 ],
        'offset'    => [ 'type' => 'integer', 'description' => 'Posts to skip', 'default' => 0 ],
    ], [ 'title' => 'List SiteOrigin Pages' ] + $cowboy_mcp_so_ro ),

    Cowboy_MCP_Tools::tool( 'wp_siteorigin_get_layout', '[SiteOrigin] Get a post\'s Page Builder layout(s). Default: { source, layouts: [ { storage: meta|block, block_index, panels_data } ] } where panels_data = { widgets[], grids[], grid_cells[] } exactly as wp_siteorigin_update_layout accepts it (each widget = its instance settings + panels_info { class, widget_id, style }). summarize=true returns a read-only rows → cells → widgets overview with widget_ids for wp_siteorigin_edit_layout.', [
        'post_id'     => [ 'type' => 'integer', 'description' => 'Post/page ID', 'required' => true ],
        'block_index' => [ 'type' => 'integer', 'description' => 'Only this Layout Block (0-based among the post\'s Layout Blocks)' ],
        'summarize'   => [ 'type' => 'boolean', 'description' => 'Return the nested overview instead of raw panels_data', 'default' => false ],
    ], [ 'title' => 'Get SiteOrigin Layout' ] + $cowboy_mcp_so_ro ),

    Cowboy_MCP_Tools::tool( 'wp_siteorigin_list_widgets', '[SiteOrigin] List widget classes usable in Page Builder layouts (panels_info.class). Includes core and third-party WP_Widget classes and Widgets Bundle widgets. include_inactive=true also lists switched-off Widgets Bundle widgets (active:false) — activate them with wp_siteorigin_set_widgets_active before using them.', [
        'include_inactive' => [ 'type' => 'boolean', 'description' => 'Also list inactive Widgets Bundle widgets', 'default' => false ],
    ], [ 'title' => 'List SiteOrigin Widgets' ] + $cowboy_mcp_so_ro ),

    Cowboy_MCP_Tools::tool( 'wp_siteorigin_get_widget_schema', '[SiteOrigin] Get the settings schema for a widget class. Widgets Bundle widgets return a JSON Schema of their instance keys (keys not in the schema are removed by the widget on save) plus companion_keys. Core/third-party WP_Widget classes have no machine-readable form: schema is null — copy the keys of an existing instance from wp_siteorigin_get_layout.', [
        'class' => [ 'type' => 'string', 'description' => 'Widget PHP class (from wp_siteorigin_list_widgets)', 'required' => true ],
    ], [ 'title' => 'Get SiteOrigin Widget Schema' ] + $cowboy_mcp_so_ro ),

    Cowboy_MCP_Tools::tool( 'wp_siteorigin_get_style_fields', '[SiteOrigin] List the style fields available for rows, cells or widgets (row grids[].style, grid_cells[].style, widget panels_info.style). Toggle fields are stored flat as {toggle}_{sub}. Unknown style keys are dropped by Page Builder on save.', [
        'level'   => [ 'type' => 'string', 'description' => 'row, cell or widget', 'enum' => [ 'row', 'cell', 'widget' ], 'required' => true ],
        'post_id' => [ 'type' => 'integer', 'description' => 'Optional post context (some add-ons vary fields per post)' ],
    ], [ 'title' => 'Get SiteOrigin Style Fields' ] + $cowboy_mcp_so_ro ),

    Cowboy_MCP_Tools::tool( 'wp_siteorigin_list_prebuilt_layouts', '[SiteOrigin] List prebuilt layouts registered on this site (by the theme or plugins via SiteOrigin\'s prebuilt-layouts filter). Apply one with wp_siteorigin_apply_prebuilt_layout layout_id, or copy any post\'s layout with source_post_id. The remote SiteOrigin layout directory is not used.', [], [ 'title' => 'List SiteOrigin Prebuilt Layouts' ] + $cowboy_mcp_so_ro ),

    Cowboy_MCP_Tools::tool( 'wp_siteorigin_get_settings', '[SiteOrigin] Get Page Builder\'s site-wide settings (merged over defaults) and the settable fields (id, section, type, label, options).', [], [ 'title' => 'Get SiteOrigin Settings' ] + $cowboy_mcp_so_ro ),

    Cowboy_MCP_Tools::tool( 'wp_siteorigin_update_settings', '[SiteOrigin] Update Page Builder\'s site-wide settings (partial merge; undoable). Keys and allowed values come from wp_siteorigin_get_settings fields; unknown keys are refused.', [
        'settings' => [ 'type' => 'object', 'description' => 'Setting id → new value', 'required' => true ],
    ], [ 'title' => 'Update SiteOrigin Settings' ] + $cowboy_mcp_so_rw ),
];

$cowboy_mcp_so_handlers = [

    'wp_siteorigin_list_pages' => function ( array $a ): array|WP_Error {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $meta_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", 'panels_data' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $block_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_content LIKE %s", '%' . $wpdb->esc_like( '<!-- wp:' . COWBOY_MCP_SO_BLOCK ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = array_values( array_unique( array_map( 'intval', array_merge( (array) $meta_ids, (array) $block_ids ) ) ) );
        $limit  = max( 1, min( 100, (int) ( $a['limit'] ?? 20 ) ) );
        $offset = max( 0, (int) ( $a['offset'] ?? 0 ) );
        if ( ! $ids ) {
            return [ 'total' => 0, 'count' => 0, 'offset' => $offset, 'pages' => [] ];
        }
        $q = new WP_Query( [
            'post__in'       => $ids,
            'post_type'      => ! empty( $a['post_type'] ) ? sanitize_key( (string) $a['post_type'] ) : 'any',
            'post_status'    => ! empty( $a['status'] ) ? sanitize_key( (string) $a['status'] ) : [ 'publish', 'draft', 'pending', 'private', 'future' ],
            's'              => isset( $a['search'] ) ? sanitize_text_field( (string) $a['search'] ) : '',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ] );
        $rows = [];
        foreach ( $q->posts as $p ) {
            $read = cowboy_mcp_siteorigin_read( (int) $p->ID );
            if ( is_wp_error( $read ) || $read['source'] === 'none' ) {
                continue;
            }
            $r = 0;
            $w = 0;
            foreach ( $read['layouts'] as $l ) {
                $r += count( (array) ( $l['panels_data']['grids'] ?? [] ) );
                $w += count( (array) ( $l['panels_data']['widgets'] ?? [] ) );
            }
            $rows[] = [
                'id'           => (int) $p->ID,
                'title'        => $p->post_title,
                'post_type'    => $p->post_type,
                'status'       => $p->post_status,
                'modified'     => $p->post_modified,
                'source'       => $read['source'],
                'row_count'    => $r,
                'widget_count' => $w,
            ];
        }
        return [ 'total' => (int) $q->found_posts, 'count' => count( $rows ), 'offset' => $offset, 'pages' => $rows ];
    },

    'wp_siteorigin_get_layout' => function ( array $a ): array|WP_Error {
        $post_id = (int) ( $a['post_id'] ?? 0 );
        $read    = cowboy_mcp_siteorigin_read( $post_id );
        if ( is_wp_error( $read ) ) {
            return $read;
        }
        if ( $read['source'] === 'none' ) {
            return new WP_Error( 'not_builder_post', "not_builder_post: post #{$post_id} has no SiteOrigin layout. Create one with wp_siteorigin_update_layout." );
        }
        $layouts = $read['layouts'];
        if ( isset( $a['block_index'] ) ) {
            $bi      = (int) $a['block_index'];
            $layouts = array_values( array_filter( $layouts, static fn( $l ) => $l['storage'] === 'block' && (int) $l['block_index'] === $bi ) );
            if ( ! $layouts ) {
                return new WP_Error( 'block_ambiguous', "block_ambiguous: post #{$post_id} has no Layout Block with block_index {$bi}." );
            }
        }
        if ( ! empty( $a['summarize'] ) ) {
            foreach ( $layouts as &$l ) {
                $l = [ 'storage' => $l['storage'], 'block_index' => $l['block_index'] ] + cowboy_mcp_siteorigin_summarize( (array) $l['panels_data'] );
            }
            unset( $l );
        }
        return [ 'post_id' => $post_id, 'source' => $read['source'], 'layouts' => $layouts ];
    },

    'wp_siteorigin_list_widgets' => function ( array $a ): array|WP_Error {
        global $wp_widget_factory;
        $rows   = [];
        $seen   = [];
        $is_sow = class_exists( 'SiteOrigin_Widget' );
        foreach ( (array) ( $wp_widget_factory->widgets ?? [] ) as $w ) {
            if ( ! $w instanceof WP_Widget ) {
                continue;
            }
            $opts                       = (array) $w->widget_options;
            $seen[ get_class( $w ) ]    = true;
            $rows[]                     = [
                'class'       => get_class( $w ),
                'id_base'     => (string) $w->id_base,
                'title'       => wp_strip_all_tags( (string) $w->name ),
                'description' => wp_strip_all_tags( (string) ( $opts['description'] ?? '' ) ),
                'groups'      => array_values( (array) ( $opts['panels_groups'] ?? [] ) ),
                'bundle'      => $is_sow && $w instanceof SiteOrigin_Widget,
                'active'      => true,
            ];
        }
        if ( ! empty( $a['include_inactive'] ) ) {
            foreach ( cowboy_mcp_siteorigin_bundle_widgets() as $b ) {
                if ( $b['active'] || ( $b['class'] && isset( $seen[ $b['class'] ] ) ) ) {
                    continue;
                }
                $rows[] = [ 'id' => $b['id'], 'class' => $b['class'], 'id_base' => $b['id_base'], 'title' => $b['name'], 'description' => $b['description'], 'groups' => [], 'bundle' => true, 'active' => false ];
            }
        }
        usort( $rows, static fn( $x, $y ) => strcmp( (string) $x['class'], (string) $y['class'] ) );
        return [ 'count' => count( $rows ), 'widgets' => $rows ];
    },

    'wp_siteorigin_get_widget_schema' => function ( array $a ): array|WP_Error {
        $class = ltrim( (string) ( $a['class'] ?? '' ), '\\' );
        $w     = cowboy_mcp_siteorigin_widget_object( $class );
        if ( ! $w ) {
            return cowboy_mcp_siteorigin_class_error( $class );
        }
        if ( class_exists( 'SiteOrigin_Widget' ) && $w instanceof SiteOrigin_Widget ) {
            $form = (array) $w->form_options();
            if ( class_exists( 'SiteOrigin_Widgets_Widget_Describer' ) ) {
                $schema = SiteOrigin_Widgets_Widget_Describer::single()->get_schema( $w );
                $source = 'describer';
            } else {
                $schema = cowboy_mcp_siteorigin_flatten_form( $form );
                $source = 'form_options';
            }
            return [
                'class'          => $class,
                'id_base'        => (string) $w->id_base,
                'source'         => $source,
                'schema'         => $schema,
                'companion_keys' => cowboy_mcp_siteorigin_companion_keys( $form ),
                'note'           => 'Instance keys not in the schema are removed by the widget on save.',
            ];
        }
        return [
            'class'   => $class,
            'id_base' => (string) $w->id_base,
            'schema'  => null,
            'note'    => 'No machine-readable form for this widget; instance keys are widget-specific — inspect an existing instance with wp_siteorigin_get_layout.',
        ];
    },

    'wp_siteorigin_get_style_fields' => function ( array $a ): array|WP_Error {
        $level = (string) ( $a['level'] ?? '' );
        if ( ! in_array( $level, [ 'row', 'cell', 'widget' ], true ) ) {
            return new WP_Error( 'invalid_level', 'invalid_level: level must be row, cell or widget.' );
        }
        $fields = cowboy_mcp_siteorigin_style_fields( $level, (int) ( $a['post_id'] ?? 0 ) );
        return [ 'level' => $level, 'count' => count( $fields ), 'fields' => $fields ];
    },

    'wp_siteorigin_list_prebuilt_layouts' => function ( array $a ): array|WP_Error {
        $rows = [];
        foreach ( cowboy_mcp_siteorigin_prebuilt_layouts() as $id => $l ) {
            if ( ! is_array( $l ) ) {
                continue;
            }
            $rows[] = [
                'id'          => (string) $id,
                'name'        => wp_strip_all_tags( (string) ( $l['name'] ?? $id ) ),
                'description' => wp_strip_all_tags( (string) ( $l['description'] ?? '' ) ),
                'rows'        => count( (array) ( $l['grids'] ?? [] ) ),
                'widgets'     => count( (array) ( $l['widgets'] ?? [] ) ),
                'applicable'  => ! empty( $l['grids'] ),
            ];
        }
        return [ 'count' => count( $rows ), 'layouts' => $rows ];
    },

    'wp_siteorigin_get_settings' => function ( array $a ): array|WP_Error {
        $fields = [];
        foreach ( cowboy_mcp_siteorigin_settings_fields() as $id => $f ) {
            $row = [ 'id' => $id, 'section' => $f['section'], 'type' => (string) ( $f['type'] ?? '' ), 'label' => wp_strip_all_tags( (string) ( $f['label'] ?? $id ) ) ];
            if ( is_array( $f['options'] ?? null ) ) {
                $row['options'] = array_map( 'strval', array_keys( $f['options'] ) );
            }
            $fields[] = $row;
        }
        return [ 'settings' => (object) SiteOrigin_Panels_Settings::single()->get(), 'fields' => $fields ];
    },

    'wp_siteorigin_update_settings' => function ( array $a ): array|WP_Error {
        $check = cowboy_mcp_siteorigin_settings_check( $a['settings'] ?? null );
        if ( $check['errors'] ) {
            return new WP_Error( 'invalid_settings', implode( ' | ', $check['errors'] ) );
        }
        $stored = get_option( 'siteorigin_panels_settings', [] );
        $merged = array_merge( is_array( $stored ) ? $stored : [], $check['values'] );
        update_option( 'siteorigin_panels_settings', $merged );
        do_action( 'siteorigin_panels_save_settings', $merged );
        SiteOrigin_Panels_Settings::single()->clear_cache();
        return [ 'changed' => $check['changed'] ];
    },
];

if ( $cowboy_mcp_so_features['seam'] ) {
    $cowboy_mcp_so_tools[] = Cowboy_MCP_Tools::tool( 'wp_siteorigin_update_layout', '[SiteOrigin] Replace a post\'s whole Page Builder layout (undoable). Send panels_data { widgets[], grids[], grid_cells[] } as returned by wp_siteorigin_get_layout (summarize=false), edited. Widgets attach to cells by panels_info.grid/cell; missing panels_info.widget_id values are generated and returned. Written through SiteOrigin\'s own sanitizer: scripts, iframes and other unsafe HTML are always stripped (reported in warnings). An empty layout (no rows, no widgets) clears it. Storage follows SiteOrigin: the classic layout unless block_index targets a Layout Block. For small changes prefer wp_siteorigin_edit_layout.', [
        'post_id'     => [ 'type' => 'integer', 'description' => 'Post/page ID', 'required' => true ],
        'panels_data' => [ 'type' => 'object', 'description' => '{ widgets: [ { …instance settings, panels_info: { class, grid, cell, widget_id?, style? } } ], grids: [ { cells, style? } ], grid_cells: [ { grid, weight, style? } ] }', 'required' => true ],
        'block_index' => [ 'type' => 'integer', 'description' => 'Target this Layout Block (0-based, from wp_siteorigin_get_layout). Required when the post has several.' ],
    ], [ 'title' => 'Update SiteOrigin Layout' ] + $cowboy_mcp_so_rw );
    $cowboy_mcp_so_handlers['wp_siteorigin_update_layout'] = static fn( array $a ): array|WP_Error => cowboy_mcp_siteorigin_write_layout( 'wp_siteorigin_update_layout', $a );
    $cowboy_mcp_so_tools[] = Cowboy_MCP_Tools::tool( 'wp_siteorigin_apply_prebuilt_layout', '[SiteOrigin] Apply a prebuilt layout (layout_id) or copy another post\'s layout (source_post_id, optional source_block_index) onto a post (undoable). mode=replace (default) replaces the layout; append adds the rows after the existing ones. Widget ids are regenerated; unsafe HTML is stripped.', [
        'post_id'            => [ 'type' => 'integer', 'description' => 'Target post/page ID', 'required' => true ],
        'layout_id'          => [ 'type' => 'string', 'description' => 'Prebuilt layout id from wp_siteorigin_list_prebuilt_layouts' ],
        'source_post_id'     => [ 'type' => 'integer', 'description' => 'Copy the layout of this post instead' ],
        'source_block_index' => [ 'type' => 'integer', 'description' => 'Copy this Layout Block of the source post (default: its classic layout, else its first Layout Block)' ],
        'mode'               => [ 'type' => 'string', 'enum' => [ 'replace', 'append' ], 'default' => 'replace', 'description' => 'replace or append' ],
        'block_index'        => [ 'type' => 'integer', 'description' => 'Target Layout Block of the target post (0-based)' ],
    ], [ 'title' => 'Apply SiteOrigin Prebuilt Layout' ] + array_merge( $cowboy_mcp_so_rw, [ 'idempotentHint' => false ] ) );
    $cowboy_mcp_so_handlers['wp_siteorigin_apply_prebuilt_layout'] = static fn( array $a ): array|WP_Error => cowboy_mcp_siteorigin_write_layout( 'wp_siteorigin_apply_prebuilt_layout', $a );
    $cowboy_mcp_so_tools[] = Cowboy_MCP_Tools::tool( 'wp_siteorigin_edit_layout', '[SiteOrigin] Edit a Page Builder layout with addressed operations (undoable, all-or-nothing). Addresses refer to the layout BEFORE this call (get them from wp_siteorigin_get_layout summarize=true): rows by 0-based row, cells by row+cell, widgets by widget_id. Ops: add_row {position?, cells (weights, default [1]), style?}; update_row {row, style? (merged), weights? (one per cell)}; move_row {row, to}; delete_row {row}; add_widget {row, cell, position?, class, instance, style?} (returns the new widget_id); update_widget {widget_id, instance? (shallow merge, null deletes a key), style? (merged)}; move_widget {widget_id, row, cell, position?}; delete_widget {widget_id}. Order of effect: updates, widget moves/adds/deletes, then row moves/adds/deletes; positions index the list at that point. Conflicting ops (e.g. update + delete of one widget, ops inside a deleted row) fail with op_conflict. Unsafe HTML is stripped as in wp_siteorigin_update_layout.', [
        'post_id'     => [ 'type' => 'integer', 'description' => 'Post/page ID', 'required' => true ],
        'ops'         => [ 'type' => 'array', 'description' => '1-50 operation objects, each with an op field', 'items' => [ 'type' => 'object' ], 'required' => true ],
        'block_index' => [ 'type' => 'integer', 'description' => 'Target this Layout Block (0-based). Required when the post has several.' ],
    ], [ 'title' => 'Edit SiteOrigin Layout' ] + array_merge( $cowboy_mcp_so_rw, [ 'idempotentHint' => false ] ) );
    $cowboy_mcp_so_handlers['wp_siteorigin_edit_layout'] = static fn( array $a ): array|WP_Error => cowboy_mcp_siteorigin_write_layout( 'wp_siteorigin_edit_layout', $a );
}

if ( $cowboy_mcp_so_features['bundle'] ) {
    $cowboy_mcp_so_tools[] = Cowboy_MCP_Tools::tool( 'wp_siteorigin_set_widgets_active', '[SiteOrigin] Activate or deactivate SiteOrigin Widgets Bundle widgets by folder id (e.g. button, accordion; see wp_siteorigin_list_widgets include_inactive=true). Undoable. Deactivating warns which posts use the widget.', [
        'widgets' => [ 'type' => 'array', 'description' => '1-30 widget folder ids', 'items' => [ 'type' => 'string' ], 'required' => true ],
        'active'  => [ 'type' => 'boolean', 'description' => 'true = activate, false = deactivate', 'required' => true ],
    ], [ 'title' => 'Set SiteOrigin Widgets Active' ] + $cowboy_mcp_so_rw );
    $cowboy_mcp_so_handlers['wp_siteorigin_set_widgets_active'] = static function ( array $a ): array|WP_Error {
        $plan = cowboy_mcp_siteorigin_activation_plan( $a['widgets'] ?? null, $a['active'] ?? null );
        if ( $plan['errors'] ) {
            return new WP_Error( 'invalid_args', implode( ' | ', $plan['errors'] ) );
        }
        $bundle = SiteOrigin_Widgets_Bundle::single();
        foreach ( $plan['changes'] as $c ) {
            if ( $c['to'] ) {
                $bundle->activate_widget( $c['widget'], false );   // false: do not include the widget file in this request
            } else {
                $bundle->deactivate_widget( $c['widget'] );
            }
        }
        wp_cache_delete( 'active_widgets', 'siteorigin_widgets' );
        delete_transient( 'siteorigin_panels_widgets' );
        return [ 'changes' => $plan['changes'], 'warnings' => $plan['warnings'] ];
    };
}

return [ 'tools' => $cowboy_mcp_so_tools, 'handlers' => $cowboy_mcp_so_handlers ];
