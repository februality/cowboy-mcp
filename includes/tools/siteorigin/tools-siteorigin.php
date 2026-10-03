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
];

return [ 'tools' => $cowboy_mcp_so_tools, 'handlers' => $cowboy_mcp_so_handlers ];
