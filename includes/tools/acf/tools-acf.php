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
 * Refuse ACF value writes that land on storage the other tools protect.
 *
 * ACF stores a value under the field's NAME whether or not the field is registered,
 * so the name is judged as the raw meta key / option name it becomes:
 *  - options pages and any unrecognised string object id write option `{id}_{name}`
 *    → the option write gate (hard-protected never, the soft list only in Power mode);
 *  - users → no role/capability/level keys and nothing is_blocked_meta_key() refuses;
 *  - posts, terms, comments → is_blocked_meta_key().
 * Next to every value ACF also writes a hidden reference — option `_{id}_{name}`, meta
 * `_{name}` — holding the field key, or '' for a name it does not know (ACF 6.8.10).
 * That row is judged too: the option twin through the same option gate, and on any
 * target an existing twin must already be an ACF reference, never someone else's row
 * (a field named `price` would otherwise blank a product's `_price`).
 */
function cowboy_mcp_acf_write_gate( string $field_name, $object_id ): ?WP_Error {
    $name = $field_name;
    if ( function_exists( 'acf_get_field' ) ) {
        $field = acf_get_field( $field_name );
        if ( is_array( $field ) && ! empty( $field['name'] ) ) {
            $name = (string) $field['name'];
        }
    }

    [ $type, $id ] = cowboy_mcp_acf_decode_target( $object_id );

    if ( 'option' === $type ) {
        $option = $id . '_' . $name;
        $twin   = '_' . $option;
        foreach ( [ $option, $twin ] as $target ) {
            $block = Cowboy_MCP_Security::option_write_block( $target );
            if ( 'invalid' === $block || 'hard' === $block
                || ( 'soft' === $block && ! Cowboy_MCP_Security::power_mode_enabled() ) ) {
                return new WP_Error( 'protected_option', $target === $twin
                    ? "This ACF value would also write its hidden reference to the protected option '{$twin}'."
                    : "This ACF value would be stored in the protected option '{$option}'." );
            }
        }
        foreach ( cowboy_mcp_acf_stored_rows( 'option', 0, $twin ) as $row ) {
            if ( ! cowboy_mcp_acf_is_field_ref( $row[1] ) ) {
                return cowboy_mcp_acf_reference_collision( $twin );
            }
        }
        return null;
    }

    // The meta tables compare keys like the options table (case/accent/width-insensitive,
    // PAD SPACE), so the name is judged trimmed and as every stored key it binds to.
    if ( ! Cowboy_MCP_Security::is_plain_name( $name ) ) {
        return new WP_Error( 'protected_meta', "Field name '{$name}' must be plain printable ASCII." );
    }
    $meta_id = in_array( $type, [ 'post', 'term', 'user', 'comment' ], true ) && is_numeric( $id ) ? (int) $id : 0;
    $keys    = [ trim( $name ) ];
    if ( $meta_id ) {
        $keys = array_unique( array_merge( $keys, array_column( cowboy_mcp_acf_stored_rows( $type, $meta_id, $name ), 0 ) ) );
    }
    // HPOS orders (`woo_order_{id}`): ACF writes through the order object, where an
    // internal key (`_billing_email`, `_order_total`) is a property setter, so neither
    // the value nor its hidden `_{name}` reference may name one; an existing twin must
    // already be an ACF reference, as on the other meta types.
    if ( 'woo_order' === $type && function_exists( 'wc_get_order' ) && is_numeric( $id ) ) {
        $order = wc_get_order( (int) $id );
        if ( $order ) {
            $store    = $order->get_data_store();
            $internal = is_object( $store ) && method_exists( $store, 'get_internal_meta_keys' ) ? (array) $store->get_internal_meta_keys() : [];
            $plain    = trim( $name );
            if ( array_intersect( [ $plain, '_' . $plain, ltrim( $plain, '_' ) ], $internal ) ) {
                return new WP_Error( 'protected_meta', "Field name '{$name}' maps to an order property; use the WooCommerce order tools." );
            }
            foreach ( (array) $order->get_meta( '_' . $plain, false, 'edit' ) as $meta ) {
                if ( ! cowboy_mcp_acf_is_field_ref( is_object( $meta ) ? ( $meta->value ?? null ) : $meta ) ) {
                    return cowboy_mcp_acf_reference_collision( '_' . $plain );
                }
            }
        }
    }
    foreach ( $keys as $key ) {
        if ( Cowboy_MCP_Security::is_blocked_meta_key( $key ) ) {
            return new WP_Error( 'protected_meta', "Field name '{$name}' maps to a protected meta key." );
        }
        if ( 'user' === $type && preg_match( '/(capabilities|user_level)$/i', $key ) ) {
            return new WP_Error( 'protected_meta', "Field name '{$name}' maps to a user's role or capability meta; use wp_update_user to change roles." );
        }
    }
    // Not is_blocked_meta_key() on the twin: every `_{name}` starts with `_`. Every row
    // it binds to must already be an ACF reference (update_metadata() rewrites them all).
    $twin = '_' . trim( $name );
    if ( $meta_id ) {
        foreach ( cowboy_mcp_acf_stored_rows( $type, $meta_id, $twin ) as $row ) {
            if ( ! cowboy_mcp_acf_is_field_ref( $row[1] ) ) {
                return cowboy_mcp_acf_reference_collision( $twin );
            }
        }
    }
    return null;
}

/**
 * ACF's storage target for an object id, as [ type, id ]: `option` (id = the options
 * prefix ACF writes under, `options` for "option"), or a meta type with its numeric id.
 */
function cowboy_mcp_acf_decode_target( $object_id ): array {
    if ( function_exists( 'acf_decode_post_id' ) ) {
        $decoded = acf_decode_post_id( $object_id );
        return [ (string) ( $decoded['type'] ?? 'post' ), $decoded['id'] ?? $object_id ];
    }
    if ( is_int( $object_id ) ) {
        return [ 'post', $object_id ];
    }
    if ( is_string( $object_id ) && preg_match( '/^(user|term|comment|woo_order)_(\d+)$/', $object_id, $m ) ) {
        return [ $m[1], (int) $m[2] ];
    }
    // acf_get_valid_post_id(): "option" is the "options" page.
    return [ 'option', 'option' === $object_id ? 'options' : $object_id ];
}

/**
 * Refuse ACF value reads that would return a secret. get_field() on a name that is
 * not a registered field falls back to the raw meta row / option `{id}_{name}`, so an
 * unregistered name is judged as that storage: no secret-named or secret-bearing
 * options, no meta key is_blocked_meta_key() refuses (internal `_` keys, session
 * tokens, application passwords, anything named like a credential).
 */
function cowboy_mcp_acf_read_gate( string $field_name, $object_id ): ?WP_Error {
    if ( function_exists( 'acf_get_field' ) ) {
        $field = acf_get_field( $field_name );
        if ( is_array( $field ) && ! empty( $field['name'] ) ) {
            return null; // a field an administrator registered
        }
    }
    [ $type, $id ] = cowboy_mcp_acf_decode_target( $object_id );
    if ( 'option' === $type ) {
        $option = $id . '_' . $field_name;
        if ( ! Cowboy_MCP_Security::is_plain_name( $option ) || Cowboy_MCP_Security::is_secret_bearing_option( $option ) ) {
            return new WP_Error( 'protected_option', "'{$field_name}' is not a registered ACF field, and the option it reads ('{$option}') holds credentials." );
        }
        return null;
    }
    if ( ! Cowboy_MCP_Security::is_plain_name( $field_name ) || Cowboy_MCP_Security::is_blocked_meta_key( trim( $field_name ) ) ) {
        return new WP_Error( 'protected_meta', "'{$field_name}' is not a registered ACF field, and the meta key it reads is protected." );
    }
    return null;
}

/**
 * The rows a write to $key binds to, as [ stored key, raw value ] pairs: option rows,
 * or one object's meta rows. Read from the table, never a cache — the collation decides
 * which rows `= %s` matches (see Cowboy_MCP_Security::stored_option_names()).
 */
function cowboy_mcp_acf_stored_rows( string $type, int $id, string $key ): array {
    global $wpdb;
    if ( 'option' === $type ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- collation probe; must hit the table, never the options cache.
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = %s", $key ), ARRAY_N );
    }
    $table = _get_meta_table( $type );
    if ( ! $table ) {
        return [];
    }
    $column = sanitize_key( $type . '_id' );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- collation probe; table and column come from _get_meta_table() / the decoded ACF type.
    return (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$table} WHERE {$column} = %d AND meta_key = %s", $id, $key ), ARRAY_N );
}

/**
 * The write gate for the five ACF write tools, run before the dry-run / safe-mode
 * previews (PRE_DISPATCH) so a preview refuses what the handler refuses. Args unchanged.
 */
function cowboy_mcp_acf_check_args( array $a ): array|WP_Error {
    $field_name = $a['field_name'] ?? '';
    if ( ! is_string( $field_name ) || '' === $field_name ) {
        return $a; // the handler reports the missing field_name
    }
    $gate = cowboy_mcp_acf_write_gate( $field_name, cowboy_mcp_acf_normalize_object_id( (string) ( $a['object_id'] ?? '' ) ) );
    if ( $gate ) {
        return $gate;
    }
    // ACF values reach the page through themes' the_field()/get_field() output,
    // usually unescaped, so script-capable strings need the explicit opt-in.
    if ( array_key_exists( 'value', $a ) && empty( $a['allow_unfiltered_html'] ) ) {
        $risky = false;
        $value = is_array( $a['value'] ) ? $a['value'] : [ $a['value'] ];
        array_walk_recursive( $value, static function ( $v ) use ( &$risky ): void {
            if ( is_string( $v ) && Cowboy_MCP_Security::script_capable_count( $v ) > 0 ) {
                $risky = true;
            }
        } );
        if ( $risky ) {
            return new WP_Error( 'unfiltered_html_blocked', 'The value contains script-capable markup (a script/iframe tag, an inline event handler or a javascript: URL). Pass allow_unfiltered_html: true to permit it.' );
        }
    }
    return $a;
}

/** Whether a stored hidden-reference value is ACF's own: '' or a field key (ACF's own test). */
function cowboy_mcp_acf_is_field_ref( $value ): bool {
    if ( '' === $value ) {
        return true;
    }
    if ( ! is_string( $value ) ) {
        return false;
    }
    return function_exists( 'acf_is_field_key' ) ? (bool) acf_is_field_key( $value ) : str_starts_with( $value, 'field_' );
}

function cowboy_mcp_acf_reference_collision( string $twin ): WP_Error {
    return new WP_Error( 'acf_reference_collision', "ACF stores a hidden reference '{$twin}' next to this value, and that row already holds other data on this object. Use a different field name." );
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

if ( ! Cowboy_MCP_Tools::domain_available( __FILE__ ) ) {
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
            'allow_unfiltered_html' => [ 'type' => 'boolean', 'description' => 'Permit script-capable markup (script/iframe tags, inline event handlers, javascript: URLs) in string values. Off by default: ACF values are printed by themes without filtering.', 'default' => false ],
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
            'allow_unfiltered_html' => [ 'type' => 'boolean', 'description' => 'Permit script-capable markup (script/iframe tags, inline event handlers, javascript: URLs) in string values. Off by default: ACF values are printed by themes without filtering.', 'default' => false ],
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
            'allow_unfiltered_html' => [ 'type' => 'boolean', 'description' => 'Permit script-capable markup (script/iframe tags, inline event handlers, javascript: URLs) in string values. Off by default: ACF values are printed by themes without filtering.', 'default' => false ],
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
            $gate = cowboy_mcp_acf_read_gate( (string) $field_name, $object_id );
            if ( $gate ) {
                return $gate;
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
            $gate = cowboy_mcp_acf_write_gate( $field_name, $object_id );
            if ( $gate ) {
                return $gate;
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
            $gate = cowboy_mcp_acf_write_gate( $field_name, $object_id );
            if ( $gate ) {
                return $gate;
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
            $gate = cowboy_mcp_acf_write_gate( $field_name, $object_id );
            if ( $gate ) {
                return $gate;
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
            $gate = cowboy_mcp_acf_write_gate( $field_name, $object_id );
            if ( $gate ) {
                return $gate;
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
            $gate = cowboy_mcp_acf_write_gate( $field_name, $object_id );
            if ( $gate ) {
                return $gate;
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
