<?php
defined( 'ABSPATH' ) || exit;

/** Shape a WP_User for tool output. $full adds profile meta and the privilege flag. */
function cowboy_mcp_format_user( WP_User $u, bool $full = false ): array {
    $out = [
        'ID'           => (int) $u->ID,
        'user_login'   => $u->user_login,
        'user_email'   => $u->user_email,
        'display_name' => $u->display_name,
        'roles'        => array_values( (array) $u->roles ),
        'registered'   => $u->user_registered,
    ];
    if ( $full ) {
        $out['first_name']    = get_user_meta( $u->ID, 'first_name', true );
        $out['last_name']     = get_user_meta( $u->ID, 'last_name', true );
        $out['nickname']      = $u->nickname;
        $out['url']           = $u->user_url;
        $out['description']   = $u->description;
        $out['locale']        = get_user_meta( $u->ID, 'locale', true );
        $out['post_count']    = (int) count_user_posts( $u->ID );
        $out['is_privileged'] = Cowboy_MCP_Security::user_is_privileged( $u );
    }
    return $out;
}

/**
 * Shared write gate for wp_create_user / wp_update_user.
 * $target is null on create. Returns a WP_Error to block, or null to allow.
 */
function cowboy_mcp_user_write_gate( array $a, ?WP_User $target ): ?WP_Error {
    $power = Cowboy_MCP_Security::power_mode_enabled();

    // Bootstrap invariant: the agent can never re-role, re-key, or redirect the
    // recovery address of its OWN account. Power mode does not lift this.
    if ( $target !== null && (int) $target->ID === get_current_user_id() ) {
        foreach ( [ 'role', 'password', 'email' ] as $field ) {
            if ( isset( $a[ $field ] ) && $a[ $field ] !== '' ) {
                return new WP_Error(
                    'self_modify',
                    "Cannot change your own {$field} through the MCP endpoint. Power mode does not lift this restriction."
                );
            }
        }
    }

    if ( isset( $a['password'] ) && $a['password'] !== '' && ! $power ) {
        return new WP_Error(
            'power_mode_required',
            'Setting a user password requires Power mode (Settings > Cowboy MCP > Settings).'
        );
    }

    if ( isset( $a['role'] ) && $a['role'] !== '' ) {
        $role = sanitize_key( $a['role'] );
        if ( ! get_role( $role ) ) {
            return new WP_Error( 'invalid_role', "Role '{$role}' does not exist on this site." );
        }
        if ( Cowboy_MCP_Security::role_is_privileged( $role ) && ! $power ) {
            return new WP_Error(
                'power_mode_required',
                "Granting the privileged role '{$role}' requires Power mode (Settings > Cowboy MCP > Settings)."
            );
        }
    }

    // Redirecting a privileged user's email hands over their password-reset link,
    // which is the same takeover power as setting their password outright.
    if ( $target !== null && isset( $a['email'] ) && $a['email'] !== ''
        && strtolower( $a['email'] ) !== strtolower( $target->user_email )
        && Cowboy_MCP_Security::user_is_privileged( $target ) && ! $power ) {
        return new WP_Error(
            'power_mode_required',
            "Changing a privileged user's email requires Power mode: the password-reset link would follow the new address."
        );
    }

    return null;
}

/** Whether applying $new_role to $target would leave the site with no administrator. */
function cowboy_mcp_user_demotes_last_admin( WP_User $target, string $new_role ): bool {
    if ( ! in_array( 'administrator', (array) $target->roles, true ) ) {
        return false;
    }
    if ( $new_role === 'administrator' ) {
        return false;
    }
    $admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ] );
    return count( $admins ) <= 1;
}

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_users', '[Users] List WordPress users with filtering and pagination.', [
            'search'   => [ 'type' => 'string',  'description' => 'Search across login, email, display name, and URL' ],
            'role'     => [ 'type' => 'string',  'description' => 'Filter by role slug (e.g. editor, subscriber)' ],
            'orderby'  => [ 'type' => 'string',  'description' => 'Sort field (default ID)', 'default' => 'ID', 'enum' => [ 'ID', 'login', 'display_name', 'registered', 'post_count' ] ],
            'per_page' => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default 50)', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            'page'     => [ 'type' => 'integer', 'description' => 'Page number (default 1)', 'default' => 1, 'minimum' => 1 ],
        ], [
            'title'           => 'List Users',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_get_user', '[Users] Get one WordPress user by ID, login, or email.', [
            'user_id' => [ 'type' => 'integer', 'description' => 'User ID' ],
            'login'   => [ 'type' => 'string',  'description' => 'Username (alternative to user_id)' ],
            'email'   => [ 'type' => 'string',  'description' => 'Email address (alternative to user_id)' ],
        ], [
            'title'           => 'Get User',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_create_user', '[Users] Create a WordPress user. Granting a privileged role (one that can manage settings, plugins, themes, or other users) or setting a password requires Power mode. On multisite this adds the user to the CURRENT site only.', [
            'user_login'        => [ 'type' => 'string',  'description' => 'Username', 'required' => true ],
            'user_email'        => [ 'type' => 'string',  'description' => 'Email address', 'required' => true ],
            'role'              => [ 'type' => 'string',  'description' => "Role slug; defaults to the site's default_role" ],
            'password'          => [ 'type' => 'string',  'description' => 'Password (Power mode only). Omit to auto-generate and email a set-password link.' ],
            'first_name'        => [ 'type' => 'string',  'description' => 'First name' ],
            'last_name'         => [ 'type' => 'string',  'description' => 'Last name' ],
            'display_name'      => [ 'type' => 'string',  'description' => 'Public display name' ],
            'nickname'          => [ 'type' => 'string',  'description' => 'Nickname' ],
            'url'               => [ 'type' => 'string',  'description' => 'Website URL' ],
            'description'       => [ 'type' => 'string',  'description' => 'Biographical info' ],
            'send_notification' => [ 'type' => 'boolean', 'description' => 'Email the new user a set-password link (default true)', 'default' => true ],
        ], [
            'title'           => 'Create User',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_update_user', '[Users] Update a WordPress user. Only provided fields change. `role` REPLACES all existing roles. Granting a privileged role, setting a password, or changing a privileged user\'s email requires Power mode. You can never change your own role, password, or email through this endpoint.', [
            'user_id'      => [ 'type' => 'integer', 'description' => 'User ID to update', 'required' => true ],
            'email'        => [ 'type' => 'string',  'description' => 'New email address' ],
            'role'         => [ 'type' => 'string',  'description' => 'New role slug (replaces all current roles)' ],
            'password'     => [ 'type' => 'string',  'description' => 'New password (Power mode only)' ],
            'display_name' => [ 'type' => 'string',  'description' => 'Public display name' ],
            'first_name'   => [ 'type' => 'string',  'description' => 'First name' ],
            'last_name'    => [ 'type' => 'string',  'description' => 'Last name' ],
            'nickname'     => [ 'type' => 'string',  'description' => 'Nickname' ],
            'url'          => [ 'type' => 'string',  'description' => 'Website URL' ],
            'description'  => [ 'type' => 'string',  'description' => 'Biographical info' ],
            'locale'       => [ 'type' => 'string',  'description' => 'User locale (e.g. en_US), or empty string for site default' ],
        ], [
            'title'           => 'Update User',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_delete_user', '[Users] Delete a WordPress user. On multisite this removes the user from the CURRENT site only.', [
            'user_id'     => [ 'type' => 'integer', 'description' => 'User ID to delete', 'required' => true ],
            'reassign_to' => [ 'type' => 'integer', 'description' => 'User ID to reassign content to' ],
        ], [
            'title'           => 'Delete User',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),
    ],
    'handlers' => [

        'wp_list_users' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'list_users' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot list users.' );
            }
            $per_page = min( max( (int) ( $a['per_page'] ?? 50 ), 1 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );

            $query_args = [
                'number'  => $per_page,
                'paged'   => $page,
                'orderby' => sanitize_key( $a['orderby'] ?? 'ID' ),
                'order'   => 'ASC',
            ];
            if ( ! empty( $a['search'] ) ) {
                $query_args['search'] = '*' . sanitize_text_field( $a['search'] ) . '*';
            }
            if ( ! empty( $a['role'] ) ) {
                $query_args['role'] = sanitize_key( $a['role'] );
            }

            $query = new WP_User_Query( $query_args );
            $total = (int) $query->get_total();

            return [
                'users'    => array_map( fn( WP_User $u ) => cowboy_mcp_format_user( $u ), $query->get_results() ),
                'total'    => $total,
                'pages'    => (int) ceil( $total / $per_page ),
                'page'     => $page,
                'per_page' => $per_page,
            ];
        },

        'wp_get_user' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'list_users' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot read users.' );
            }
            $user = false;
            if ( ! empty( $a['user_id'] ) ) {
                $user = get_userdata( (int) $a['user_id'] );
            } elseif ( ! empty( $a['login'] ) ) {
                $user = get_user_by( 'login', sanitize_user( $a['login'] ) );
            } elseif ( ! empty( $a['email'] ) ) {
                $user = get_user_by( 'email', sanitize_email( $a['email'] ) );
            } else {
                return new WP_Error( 'invalid_params', 'Provide one of user_id, login, or email.' );
            }
            if ( ! $user ) {
                return new WP_Error( 'not_found', 'User not found.' );
            }
            return cowboy_mcp_format_user( $user, true );
        },

        'wp_create_user' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'create_users' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot create users.' );
            }
            $gate = cowboy_mcp_user_write_gate( $a, null );
            if ( $gate !== null ) {
                return $gate;
            }

            $login = sanitize_user( (string) $a['user_login'], true );
            if ( $login === '' ) {
                return new WP_Error( 'invalid_params', 'user_login is empty after sanitization.' );
            }
            if ( username_exists( $login ) ) {
                return new WP_Error( 'exists', "Username '{$login}' already exists." );
            }
            $email = sanitize_email( (string) $a['user_email'] );
            if ( ! is_email( $email ) ) {
                return new WP_Error( 'invalid_email', 'user_email is not a valid email address.' );
            }
            if ( email_exists( $email ) ) {
                return new WP_Error( 'exists', "Email '{$email}' is already registered." );
            }

            $userdata = [
                'user_login' => $login,
                'user_email' => $email,
                'user_pass'  => ( isset( $a['password'] ) && $a['password'] !== '' )
                    ? (string) $a['password']
                    : wp_generate_password( 24, true, true ),
                'role'       => ( isset( $a['role'] ) && $a['role'] !== '' )
                    ? sanitize_key( $a['role'] )
                    : (string) get_option( 'default_role', 'subscriber' ),
            ];
            foreach ( [ 'first_name', 'last_name', 'display_name', 'nickname' ] as $field ) {
                if ( isset( $a[ $field ] ) ) {
                    $userdata[ $field ] = sanitize_text_field( $a[ $field ] );
                }
            }
            if ( isset( $a['url'] ) ) {
                $userdata['user_url'] = esc_url_raw( $a['url'] );
            }
            if ( isset( $a['description'] ) ) {
                $userdata['description'] = sanitize_textarea_field( $a['description'] );
            }

            $user_id = wp_insert_user( wp_slash( $userdata ) );
            if ( is_wp_error( $user_id ) ) {
                return $user_id;
            }

            $notify = ! isset( $a['send_notification'] ) || ! empty( $a['send_notification'] );
            if ( $notify ) {
                wp_new_user_notification( $user_id, null, 'user' );
            }

            return [ 'created' => true, 'user_id' => (int) $user_id, 'notified' => $notify ]
                + cowboy_mcp_format_user( get_userdata( $user_id ), true );
        },

        'wp_update_user' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'edit_users' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot edit users.' );
            }
            $user_id = (int) $a['user_id'];
            $user    = get_userdata( $user_id );
            if ( ! $user ) {
                return new WP_Error( 'not_found', "User {$user_id} not found." );
            }
            $gate = cowboy_mcp_user_write_gate( $a, $user );
            if ( $gate !== null ) {
                return $gate;
            }

            $data = [ 'ID' => $user_id ];

            if ( isset( $a['role'] ) && $a['role'] !== '' ) {
                $role = sanitize_key( $a['role'] );
                // Demoting the only administrator locks the site out exactly like
                // deleting them, so it gets the same hard block.
                if ( cowboy_mcp_user_demotes_last_admin( $user, $role ) ) {
                    return new WP_Error( 'last_admin', 'Cannot demote the last administrator — the site would be left with no admin account.' );
                }
                $data['role'] = $role;
            }
            if ( isset( $a['email'] ) && $a['email'] !== '' ) {
                $email = sanitize_email( $a['email'] );
                if ( ! is_email( $email ) ) {
                    return new WP_Error( 'invalid_email', 'email is not a valid email address.' );
                }
                $existing = email_exists( $email );
                if ( $existing && (int) $existing !== $user_id ) {
                    return new WP_Error( 'exists', "Email '{$email}' is already registered to another user." );
                }
                $data['user_email'] = $email;
            }
            if ( isset( $a['password'] ) && $a['password'] !== '' ) {
                $data['user_pass'] = (string) $a['password'];
            }
            foreach ( [ 'display_name', 'first_name', 'last_name', 'nickname' ] as $field ) {
                if ( isset( $a[ $field ] ) ) {
                    $data[ $field ] = sanitize_text_field( $a[ $field ] );
                }
            }
            if ( isset( $a['url'] ) ) {
                $data['user_url'] = esc_url_raw( $a['url'] );
            }
            if ( isset( $a['description'] ) ) {
                $data['description'] = sanitize_textarea_field( $a['description'] );
            }
            if ( isset( $a['locale'] ) ) {
                $data['locale'] = sanitize_text_field( $a['locale'] );
            }

            if ( count( $data ) === 1 ) {
                return new WP_Error( 'invalid_params', 'No fields provided to update.' );
            }

            $result = wp_update_user( wp_slash( $data ) );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return [ 'updated' => true, 'user_id' => $user_id ]
                + cowboy_mcp_format_user( get_userdata( $user_id ), true );
        },

        'wp_delete_user' => function ( array $a ) {
            $user_id = (int) $a['user_id'];

            if ( $user_id === get_current_user_id() ) {
                return new WP_Error( 'self_delete', 'Cannot delete the current authenticated user.' );
            }

            $user = get_userdata( $user_id );
            if ( ! $user ) {
                return new WP_Error( 'not_found', "User {$user_id} not found." );
            }

            // Prevent deleting the last administrator (permanent site lockout).
            if ( in_array( 'administrator', (array) $user->roles, true ) ) {
                $admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ] );
                if ( count( $admins ) <= 1 ) {
                    return new WP_Error( 'last_admin', 'Cannot delete the last administrator account.' );
                }
            }

            $reassign = isset( $a['reassign_to'] ) ? (int) $a['reassign_to'] : null;
            if ( $reassign !== null && ! get_userdata( $reassign ) ) {
                return new WP_Error( 'invalid_reassign', "reassign_to user {$reassign} does not exist." );
            }
            $result = Cowboy_MCP_Compat::delete_user( $user_id, $reassign );

            if ( ! $result ) {
                return new WP_Error( 'delete_failed', "Failed to delete user {$user_id}." );
            }
            return [ 'deleted' => true, 'user_id' => $user_id, 'reassigned_to' => $reassign ];
        },
    ],
];
