<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_delete_user', '[Users] Delete a WordPress user.', [
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

            require_once ABSPATH . 'wp-admin/includes/user.php';

            $reassign = isset( $a['reassign_to'] ) ? (int) $a['reassign_to'] : null;
            if ( $reassign !== null && ! get_userdata( $reassign ) ) {
                return new WP_Error( 'invalid_reassign', "reassign_to user {$reassign} does not exist." );
            }
            $result = wp_delete_user( $user_id, $reassign );

            if ( ! $result ) {
                return new WP_Error( 'delete_failed', "Failed to delete user {$user_id}." );
            }
            return [ 'deleted' => true, 'user_id' => $user_id, 'reassigned_to' => $reassign ];
        },
    ],
];
