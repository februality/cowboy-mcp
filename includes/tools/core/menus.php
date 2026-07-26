<?php
defined( 'ABSPATH' ) || exit;

/**
 * Build the wp_update_nav_menu_item() argument array for one item node.
 * Shared by the wp_set_menu_items handler AND Cowboy_MCP_Rollback's menu restore,
 * so both write items through exactly one code path.
 *
 * $node accepts the tool's nested shape and the rollback snapshot's flat shape:
 * title, url, type, object, object_id, target, classes[], description, attr_title, xfn.
 */
function cowboy_mcp_menu_item_args( array $node, int $parent, int $position ): array {
    $type = sanitize_key( $node['type'] ?? 'custom' );
    $args = [
        'menu-item-title'       => sanitize_text_field( (string) ( $node['title'] ?? '' ) ),
        'menu-item-parent-id'   => $parent,
        'menu-item-position'    => $position,
        'menu-item-type'        => $type,
        'menu-item-status'      => 'publish',
        'menu-item-target'      => sanitize_text_field( (string) ( $node['target'] ?? '' ) ),
        'menu-item-description' => sanitize_text_field( (string) ( $node['description'] ?? '' ) ),
        'menu-item-attr-title'  => sanitize_text_field( (string) ( $node['attr_title'] ?? '' ) ),
        'menu-item-xfn'         => sanitize_text_field( (string) ( $node['xfn'] ?? '' ) ),
        'menu-item-classes'     => is_array( $node['classes'] ?? null )
            ? implode( ' ', array_map( 'sanitize_html_class', $node['classes'] ) )
            : sanitize_text_field( (string) ( $node['classes'] ?? '' ) ),
    ];
    if ( $type === 'custom' ) {
        $args['menu-item-url'] = esc_url_raw( (string) ( $node['url'] ?? '' ) );
    } else {
        $args['menu-item-object']    = sanitize_key( (string) ( $node['object'] ?? '' ) );
        $args['menu-item-object-id'] = (int) ( $node['object_id'] ?? 0 );
    }
    return $args;
}

/** Nested tree of a menu's items, children under `children`. */
function cowboy_mcp_menu_items_tree( int $menu_id ): array {
    $items     = wp_get_nav_menu_items( $menu_id ) ?: [];
    $by_parent = [];
    foreach ( $items as $item ) {
        $by_parent[ (int) $item->menu_item_parent ][] = $item;
    }
    $build = function ( int $parent ) use ( &$build, $by_parent ): array {
        $out = [];
        foreach ( $by_parent[ $parent ] ?? [] as $item ) {
            $out[] = [
                'db_id'       => (int) $item->db_id,
                'title'       => $item->title,
                'url'         => $item->url,
                'type'        => $item->type,
                'object'      => $item->object,
                'object_id'   => (int) $item->object_id,
                'target'      => $item->target,
                'classes'     => array_values( array_filter( (array) $item->classes ) ),
                'description' => $item->description,
                'attr_title'  => $item->attr_title,
                'xfn'         => $item->xfn,
                'children'    => $build( (int) $item->db_id ),
            ];
        }
        return $out;
    };
    return $build( 0 );
}

/**
 * Assign $menu_id to exactly $locations, releasing any other location it held.
 * Returns ['assigned' => [...], 'unknown' => [...]].
 */
function cowboy_mcp_menu_set_locations( int $menu_id, array $locations ): array {
    $registered = array_keys( get_registered_nav_menus() );
    $current    = get_nav_menu_locations();
    $assigned   = [];
    $unknown    = [];

    foreach ( $locations as $location ) {
        $location = sanitize_key( $location );
        if ( ! in_array( $location, $registered, true ) ) {
            $unknown[] = $location;
            continue;
        }
        $current[ $location ] = $menu_id;
        $assigned[]           = $location;
    }
    foreach ( $current as $location => $held_by ) {
        if ( (int) $held_by === $menu_id && ! in_array( $location, $assigned, true ) ) {
            $current[ $location ] = 0;
        }
    }
    set_theme_mod( 'nav_menu_locations', $current );

    return [ 'assigned' => $assigned, 'unknown' => $unknown ];
}

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_menus', '[Menus] List classic navigation menus, registered theme locations, and their assignments. If is_block_theme is true the active theme renders navigation from wp_navigation posts instead — edit those with wp_list_posts / wp_update_post (post_type: wp_navigation).', [], [
            'title'           => 'List Menus',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_create_menu', '[Menus] Create a classic navigation menu, optionally assigning it to theme locations.', [
            'name'      => [ 'type' => 'string', 'description' => 'Menu name', 'required' => true ],
            'locations' => [ 'type' => 'array',  'description' => 'Theme location slugs to assign this menu to', 'items' => [ 'type' => 'string' ] ],
        ], [
            'title'           => 'Create Menu',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_update_menu', '[Menus] Rename a menu and/or set which theme locations it occupies. Passing `locations` REPLACES the menu\'s location assignments.', [
            'menu_id'   => [ 'type' => 'integer', 'description' => 'Menu ID', 'required' => true ],
            'name'      => [ 'type' => 'string',  'description' => 'New menu name' ],
            'locations' => [ 'type' => 'array',   'description' => 'Theme location slugs this menu should occupy (replaces current assignments)', 'items' => [ 'type' => 'string' ] ],
        ], [
            'title'           => 'Update Menu',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_delete_menu', '[Menus] Delete a classic navigation menu and all of its items.', [
            'menu_id' => [ 'type' => 'integer', 'description' => 'Menu ID', 'required' => true ],
        ], [
            'title'           => 'Delete Menu',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_list_menu_items', '[Menus] Get a menu\'s items as a nested tree (children under `children`).', [
            'menu_id' => [ 'type' => 'integer', 'description' => 'Menu ID', 'required' => true ],
        ], [
            'title'           => 'List Menu Items',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_set_menu_items', '[Menus] Replace a menu\'s entire item list with the supplied nested tree. This DELETES all existing items and re-creates them, so menu-item IDs change. Read the current tree with wp_list_menu_items, modify it, and send the whole tree back. Each item: title, type (custom|post_type|taxonomy), url (custom only), object + object_id (post_type/taxonomy), target, classes[], description, attr_title, xfn, children[].', [
            'menu_id' => [ 'type' => 'integer', 'description' => 'Menu ID', 'required' => true ],
            'items'   => [ 'type' => 'array',   'description' => 'Nested menu item tree', 'required' => true, 'items' => [ 'type' => 'object' ] ],
        ], [
            'title'           => 'Set Menu Items',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [

        'wp_list_menus' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'edit_theme_options' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot manage menus.' );
            }
            $locations = get_nav_menu_locations();
            $menus     = [];
            foreach ( wp_get_nav_menus() as $menu ) {
                $at = [];
                foreach ( $locations as $location => $held_by ) {
                    if ( (int) $held_by === (int) $menu->term_id ) {
                        $at[] = $location;
                    }
                }
                $menus[] = [
                    'menu_id'    => (int) $menu->term_id,
                    'name'       => $menu->name,
                    'slug'       => $menu->slug,
                    'item_count' => (int) $menu->count,
                    'locations'  => $at,
                ];
            }
            $registered = [];
            foreach ( get_registered_nav_menus() as $slug => $label ) {
                $registered[] = [
                    'location'    => $slug,
                    'description' => $label,
                    'menu_id'     => (int) ( $locations[ $slug ] ?? 0 ),
                ];
            }
            $is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

            return [
                'menus'          => $menus,
                'locations'      => $registered,
                'is_block_theme' => $is_block_theme,
                'note'           => $is_block_theme
                    ? 'This theme is a block theme and renders navigation from wp_navigation posts, not these classic menus. Use wp_list_posts / wp_update_post with post_type: wp_navigation to edit the live navigation.'
                    : null,
            ];
        },

        'wp_create_menu' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'edit_theme_options' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot manage menus.' );
            }
            $name = sanitize_text_field( (string) $a['name'] );
            if ( $name === '' ) {
                return new WP_Error( 'invalid_params', 'name is empty after sanitization.' );
            }
            if ( wp_get_nav_menu_object( $name ) ) {
                return new WP_Error( 'exists', "A menu named '{$name}' already exists." );
            }
            $menu_id = wp_create_nav_menu( $name );
            if ( is_wp_error( $menu_id ) ) {
                return $menu_id;
            }
            $applied = [ 'assigned' => [], 'unknown' => [] ];
            if ( ! empty( $a['locations'] ) && is_array( $a['locations'] ) ) {
                $applied = cowboy_mcp_menu_set_locations( (int) $menu_id, $a['locations'] );
            }
            return [
                'created'           => true,
                'menu_id'           => (int) $menu_id,
                'name'              => $name,
                'locations'         => $applied['assigned'],
                'unknown_locations' => $applied['unknown'],
            ];
        },

        'wp_update_menu' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'edit_theme_options' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot manage menus.' );
            }
            $menu_id = (int) $a['menu_id'];
            $menu    = wp_get_nav_menu_object( $menu_id );
            if ( ! $menu ) {
                return new WP_Error( 'not_found', "Menu {$menu_id} not found." );
            }
            if ( ! isset( $a['name'] ) && ! isset( $a['locations'] ) ) {
                return new WP_Error( 'invalid_params', 'Provide name and/or locations.' );
            }
            if ( isset( $a['name'] ) && $a['name'] !== '' ) {
                $result = wp_update_nav_menu_object( $menu_id, [ 'menu-name' => sanitize_text_field( $a['name'] ) ] );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }
            $applied = [ 'assigned' => [], 'unknown' => [] ];
            if ( isset( $a['locations'] ) && is_array( $a['locations'] ) ) {
                $applied = cowboy_mcp_menu_set_locations( $menu_id, $a['locations'] );
            }
            $menu = wp_get_nav_menu_object( $menu_id );
            return [
                'updated'           => true,
                'menu_id'           => $menu_id,
                'name'              => $menu->name,
                'locations'         => $applied['assigned'],
                'unknown_locations' => $applied['unknown'],
            ];
        },

        'wp_delete_menu' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'edit_theme_options' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot manage menus.' );
            }
            $menu_id = (int) $a['menu_id'];
            $menu    = wp_get_nav_menu_object( $menu_id );
            if ( ! $menu ) {
                return new WP_Error( 'not_found', "Menu {$menu_id} not found." );
            }
            $name   = $menu->name;
            $result = wp_delete_nav_menu( $menu_id );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            if ( $result === false ) {
                return new WP_Error( 'delete_failed', "Failed to delete menu {$menu_id}." );
            }
            return [ 'deleted' => true, 'menu_id' => $menu_id, 'name' => $name ];
        },

        'wp_list_menu_items' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'edit_theme_options' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot manage menus.' );
            }
            $menu_id = (int) $a['menu_id'];
            if ( ! wp_get_nav_menu_object( $menu_id ) ) {
                return new WP_Error( 'not_found', "Menu {$menu_id} not found." );
            }
            return [ 'menu_id' => $menu_id, 'items' => cowboy_mcp_menu_items_tree( $menu_id ) ];
        },

        'wp_set_menu_items' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'edit_theme_options' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot manage menus.' );
            }
            $menu_id = (int) $a['menu_id'];
            if ( ! wp_get_nav_menu_object( $menu_id ) ) {
                return new WP_Error( 'not_found', "Menu {$menu_id} not found." );
            }
            if ( ! isset( $a['items'] ) || ! is_array( $a['items'] ) ) {
                return new WP_Error( 'invalid_params', 'items must be an array.' );
            }

            foreach ( wp_get_nav_menu_items( $menu_id ) ?: [] as $existing ) {
                wp_delete_post( (int) $existing->db_id, true );
            }

            // Single global position counter: menu_order is site-wide within the
            // menu, not per-level, so a per-branch counter produces duplicates.
            $position = 0;
            $created  = 0;
            $walk     = function ( array $nodes, int $parent ) use ( &$walk, $menu_id, &$position, &$created ): ?WP_Error {
                foreach ( $nodes as $node ) {
                    if ( ! is_array( $node ) ) {
                        return new WP_Error( 'invalid_params', 'Each menu item must be an object.' );
                    }
                    $db_id = wp_update_nav_menu_item( $menu_id, 0, cowboy_mcp_menu_item_args( $node, $parent, ++$position ) );
                    if ( is_wp_error( $db_id ) ) {
                        return $db_id;
                    }
                    ++$created;
                    if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                        $error = $walk( $node['children'], (int) $db_id );
                        if ( $error !== null ) {
                            return $error;
                        }
                    }
                }
                return null;
            };
            $error = $walk( $a['items'], 0 );
            if ( $error !== null ) {
                return $error;
            }

            return [
                'updated'       => true,
                'menu_id'       => $menu_id,
                'items_created' => $created,
                'items'         => cowboy_mcp_menu_items_tree( $menu_id ),
            ];
        },
    ],
];
