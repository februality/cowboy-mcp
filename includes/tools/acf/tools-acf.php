<?php
defined( 'ABSPATH' ) || exit;

/**
 * Normalize ACF's polymorphic object ID.
 *
 * Numeric strings → int (post ID). Everything else passes through
 * for ACF's built-in formats: "user_2", "term_3", "option", etc.
 */
function cowboy_mcp_acf_normalize_object_id( string $id ) {
    return ctype_digit( $id ) ? (int) $id : $id;
}

/**
 * Recursively format a field definition for schema discovery.
 *
 * Expands sub_fields (repeater/group) and layouts (flexible content)
 * so the agent can understand the full structure.
 */
function cowboy_mcp_acf_format_field( array $field ): array {
    $out = [
        'key'           => $field['key'] ?? '',
        'name'          => $field['name'] ?? '',
        'label'         => $field['label'] ?? '',
        'type'          => $field['type'] ?? '',
        'required'      => ! empty( $field['required'] ),
    ];

    if ( isset( $field['choices'] ) && $field['choices'] )      $out['choices']       = $field['choices'];
    if ( isset( $field['default_value'] ) )                      $out['default_value'] = $field['default_value'];
    if ( isset( $field['min'] ) )                                $out['min']           = $field['min'];
    if ( isset( $field['max'] ) )                                $out['max']           = $field['max'];
    if ( isset( $field['return_format'] ) )                      $out['return_format'] = $field['return_format'];

    // Repeater / Group sub-fields.
    if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
        $out['sub_fields'] = array_map( 'cowboy_mcp_acf_format_field', $field['sub_fields'] );
    }

    // Flexible Content layouts.
    if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
        $out['layouts'] = array_map( function ( array $layout ): array {
            $l = [
                'key'   => $layout['key'] ?? '',
                'name'  => $layout['name'] ?? '',
                'label' => $layout['label'] ?? '',
            ];
            if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
                $l['sub_fields'] = array_map( 'cowboy_mcp_acf_format_field', $layout['sub_fields'] );
            }
            return $l;
        }, $field['layouts'] );
    }

    return $out;
}

/* ================================================================
 *  ACF guard — return empty arrays when ACF is not active.
 * ================================================================ */

if ( ! function_exists( 'acf_get_field_groups' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        /* ---------- Schema Discovery ---------- */

        Cowboy_MCP_Tools::tool( 'wp_acf_get_field_groups', '[ACF] List all ACF field groups with location rules and status. Returns key, title, active state, location rules, position, style, and menu_order.', [], [
            'title'           => 'List ACF Field Groups',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'key'        => [ 'type' => 'string' ],
                    'title'      => [ 'type' => 'string' ],
                    'active'     => [ 'type' => 'boolean' ],
                    'location'   => [ 'type' => 'array', 'items' => [ 'type' => 'array' ] ],
                    'position'   => [ 'type' => 'string' ],
                    'style'      => [ 'type' => 'string' ],
                    'menu_order' => [ 'type' => 'integer' ],
                ],
            ],
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_acf_get_field_group_fields', '[ACF] List all fields in an ACF field group with types, keys, and sub-field structure. Recursively expands repeater, group, and flexible content fields.', [
            'group_key' => [ 'type' => 'string', 'description' => 'Field group key (e.g. "group_abc123") or numeric post ID', 'required' => true ],
        ], [
            'title'           => 'Get ACF Field Group Fields',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'group_key' => [ 'type' => 'string' ],
                'count'     => [ 'type' => 'integer' ],
                'fields'    => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key'      => [ 'type' => 'string' ],
                            'name'     => [ 'type' => 'string' ],
                            'label'    => [ 'type' => 'string' ],
                            'type'     => [ 'type' => 'string' ],
                            'required' => [ 'type' => 'boolean' ],
                        ],
                    ],
                ],
            ],
        ] ),

        /* ---------- Field CRUD ---------- */

        Cowboy_MCP_Tools::tool( 'wp_acf_get_field', '[ACF] Read a single ACF field value for a post, user, term, or options page.', [
            'field_name'   => [ 'type' => 'string',  'description' => 'Field name or field key', 'required' => true ],
            'object_id'    => [ 'type' => 'string',  'description' => 'Target object: post ID ("123"), user ("user_2"), term ("term_3"), option ("option")', 'required' => true ],
            'format_value' => [ 'type' => 'boolean', 'description' => 'Apply ACF formatting to the value', 'default' => true ],
        ], [
            'title'           => 'Get ACF Field',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_acf_get_fields', '[ACF] Read all ACF field values for an object (post, user, term, or options page).', [
            'object_id'    => [ 'type' => 'string',  'description' => 'Target object: post ID ("123"), user ("user_2"), term ("term_3"), option ("option")', 'required' => true ],
            'format_value' => [ 'type' => 'boolean', 'description' => 'Apply ACF formatting to values', 'default' => true ],
        ], [
            'title'           => 'Get ACF Fields',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_acf_update_field', '[ACF] Create or update an ACF field value. Use field keys (field_xxx) for first writes to ensure correct field type linkage.', [
            'field_name' => [ 'type' => 'string', 'description' => 'Field name or field key (keys preferred for first writes)', 'required' => true ],
            'value'      => [ 'description' => 'New value — type depends on field type (string, number, array, etc.)', 'required' => true ],
            'object_id'  => [ 'type' => 'string', 'description' => 'Target object: post ID ("123"), user ("user_2"), term ("term_3"), option ("option")', 'required' => true ],
        ], [
            'title'           => 'Update ACF Field',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_acf_delete_field', '[ACF] Delete an ACF field value from a post, user, term, or options page.', [
            'field_name' => [ 'type' => 'string', 'description' => 'Field name or field key', 'required' => true ],
            'object_id'  => [ 'type' => 'string', 'description' => 'Target object: post ID ("123"), user ("user_2"), term ("term_3"), option ("option")', 'required' => true ],
        ], [
            'title'           => 'Delete ACF Field',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        /* ---------- Repeater Row Operations ---------- */

        Cowboy_MCP_Tools::tool( 'wp_acf_add_row', '[ACF] Append a row to an ACF repeater or flexible content field. Returns the new 1-based row number.', [
            'field_name' => [ 'type' => 'string', 'description' => 'Repeater/flex field name or key', 'required' => true ],
            'value'      => [ 'type' => 'object', 'description' => 'Sub-field key-value pairs for the new row', 'required' => true ],
            'object_id'  => [ 'type' => 'string', 'description' => 'Target object: post ID ("123"), user ("user_2"), term ("term_3"), option ("option")', 'required' => true ],
        ], [
            'title'           => 'Add ACF Row',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_acf_update_row', '[ACF] Update an existing row in an ACF repeater or flexible content field.', [
            'field_name' => [ 'type' => 'string',  'description' => 'Repeater/flex field name or key', 'required' => true ],
            'row_number' => [ 'type' => 'integer', 'description' => 'Row number (1-based)', 'required' => true ],
            'value'      => [ 'type' => 'object',  'description' => 'Sub-field key-value pairs to update', 'required' => true ],
            'object_id'  => [ 'type' => 'string',  'description' => 'Target object: post ID ("123"), user ("user_2"), term ("term_3"), option ("option")', 'required' => true ],
        ], [
            'title'           => 'Update ACF Row',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_acf_delete_row', '[ACF] Delete a row from an ACF repeater or flexible content field.', [
            'field_name' => [ 'type' => 'string',  'description' => 'Repeater/flex field name or key', 'required' => true ],
            'row_number' => [ 'type' => 'integer', 'description' => 'Row number (1-based)', 'required' => true ],
            'object_id'  => [ 'type' => 'string',  'description' => 'Target object: post ID ("123"), user ("user_2"), term ("term_3"), option ("option")', 'required' => true ],
        ], [
            'title'           => 'Delete ACF Row',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [
        /* ---------- Schema Discovery ---------- */

        'wp_acf_get_field_groups' => function ( array $a ): array {
            $groups = acf_get_field_groups();
            return array_map( fn( array $g ) => [
                'key'        => $g['key'] ?? '',
                'title'      => $g['title'] ?? '',
                'active'     => ! empty( $g['active'] ),
                'location'   => $g['location'] ?? [],
                'position'   => $g['position'] ?? 'normal',
                'style'      => $g['style'] ?? 'default',
                'menu_order' => $g['menu_order'] ?? 0,
            ], $groups );
        },

        'wp_acf_get_field_group_fields' => function ( array $a ) {
            $group_key = $a['group_key'] ?? '';
            if ( $group_key === '' ) {
                return new WP_Error( 'missing_param', 'group_key is required.' );
            }

            // Accept either a group key string or a numeric post ID.
            $parent = ctype_digit( (string) $group_key ) ? (int) $group_key : $group_key;
            $fields = acf_get_fields( $parent );

            if ( $fields === false || $fields === null ) {
                return new WP_Error( 'not_found', "No field group found for: {$group_key}" );
            }

            return [
                'group_key' => $group_key,
                'count'     => count( $fields ),
                'fields'    => array_map( 'cowboy_mcp_acf_format_field', $fields ),
            ];
        },

        /* ---------- Field CRUD ---------- */

        'wp_acf_get_field' => function ( array $a ) {
            $field_name   = $a['field_name'] ?? '';
            $object_id    = cowboy_mcp_acf_normalize_object_id( $a['object_id'] ?? '' );
            $format_value = $a['format_value'] ?? true;

            if ( $field_name === '' ) {
                return new WP_Error( 'missing_param', 'field_name is required.' );
            }

            $value = get_field( $field_name, $object_id, $format_value );

            // Disambiguate null/false (valid values) from "field not found".
            if ( $value === null || $value === false ) {
                $field_obj = get_field_object( $field_name, $object_id, false, false );
                if ( ! $field_obj ) {
                    return new WP_Error( 'not_found', "Field '{$field_name}' not found on object '{$a['object_id']}'." );
                }
            }

            return [
                'field_name' => $field_name,
                'object_id'  => $a['object_id'],
                'value'      => $value,
            ];
        },

        'wp_acf_get_fields' => function ( array $a ) {
            $object_id    = cowboy_mcp_acf_normalize_object_id( $a['object_id'] ?? '' );
            $format_value = $a['format_value'] ?? true;

            $fields = get_fields( $object_id, $format_value );

            if ( ! is_array( $fields ) ) {
                $fields = [];
            }

            return [
                'object_id' => $a['object_id'],
                'count'     => count( $fields ),
                'fields'    => $fields,
            ];
        },

        'wp_acf_update_field' => function ( array $a ) {
            $field_name = $a['field_name'] ?? '';
            $object_id  = cowboy_mcp_acf_normalize_object_id( $a['object_id'] ?? '' );

            if ( $field_name === '' ) {
                return new WP_Error( 'missing_param', 'field_name is required.' );
            }
            if ( ! array_key_exists( 'value', $a ) ) {
                return new WP_Error( 'missing_param', 'value is required.' );
            }

            $result = update_field( $field_name, $a['value'], $object_id );

            if ( $result === false ) {
                return new WP_Error( 'update_failed', "Failed to update field '{$field_name}' on object '{$a['object_id']}'." );
            }

            return [
                'updated'    => true,
                'field_name' => $field_name,
                'object_id'  => $a['object_id'],
            ];
        },

        'wp_acf_delete_field' => function ( array $a ) {
            $field_name = $a['field_name'] ?? '';
            $object_id  = cowboy_mcp_acf_normalize_object_id( $a['object_id'] ?? '' );

            if ( $field_name === '' ) {
                return new WP_Error( 'missing_param', 'field_name is required.' );
            }

            $result = delete_field( $field_name, $object_id );

            if ( $result === false ) {
                return new WP_Error( 'delete_failed', "Failed to delete field '{$field_name}' on object '{$a['object_id']}'." );
            }

            return [
                'deleted'    => true,
                'field_name' => $field_name,
                'object_id'  => $a['object_id'],
            ];
        },

        /* ---------- Repeater Row Operations ---------- */

        'wp_acf_add_row' => function ( array $a ) {
            $field_name = $a['field_name'] ?? '';
            $value      = $a['value'] ?? [];
            $object_id  = cowboy_mcp_acf_normalize_object_id( $a['object_id'] ?? '' );

            if ( $field_name === '' ) {
                return new WP_Error( 'missing_param', 'field_name is required.' );
            }

            $row_number = add_row( $field_name, $value, $object_id );

            if ( $row_number === false ) {
                return new WP_Error( 'add_row_failed', "Failed to add row to '{$field_name}' on object '{$a['object_id']}'." );
            }

            return [
                'added'      => true,
                'field_name' => $field_name,
                'object_id'  => $a['object_id'],
                'row_number' => $row_number,
            ];
        },

        'wp_acf_update_row' => function ( array $a ) {
            $field_name = $a['field_name'] ?? '';
            $row_number = (int) ( $a['row_number'] ?? 0 );
            $value      = $a['value'] ?? [];
            $object_id  = cowboy_mcp_acf_normalize_object_id( $a['object_id'] ?? '' );

            if ( $field_name === '' ) {
                return new WP_Error( 'missing_param', 'field_name is required.' );
            }
            if ( $row_number < 1 ) {
                return new WP_Error( 'invalid_param', 'row_number must be >= 1.' );
            }

            $result = update_row( $field_name, $row_number, $value, $object_id );

            if ( $result === false ) {
                return new WP_Error( 'update_row_failed', "Failed to update row {$row_number} of '{$field_name}' on object '{$a['object_id']}'." );
            }

            return [
                'updated'    => true,
                'field_name' => $field_name,
                'object_id'  => $a['object_id'],
                'row_number' => $row_number,
            ];
        },

        'wp_acf_delete_row' => function ( array $a ) {
            $field_name = $a['field_name'] ?? '';
            $row_number = (int) ( $a['row_number'] ?? 0 );
            $object_id  = cowboy_mcp_acf_normalize_object_id( $a['object_id'] ?? '' );

            if ( $field_name === '' ) {
                return new WP_Error( 'missing_param', 'field_name is required.' );
            }
            if ( $row_number < 1 ) {
                return new WP_Error( 'invalid_param', 'row_number must be >= 1.' );
            }

            $result = delete_row( $field_name, $row_number, $object_id );

            if ( $result === false ) {
                return new WP_Error( 'delete_row_failed', "Failed to delete row {$row_number} of '{$field_name}' on object '{$a['object_id']}'." );
            }

            return [
                'deleted'    => true,
                'field_name' => $field_name,
                'object_id'  => $a['object_id'],
                'row_number' => $row_number,
            ];
        },
    ],
];
