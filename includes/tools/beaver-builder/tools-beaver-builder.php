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
    ],

    'handlers' => [
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
    ],
];
