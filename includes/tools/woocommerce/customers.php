<?php
defined( 'ABSPATH' ) || exit;

/**
 * Format a WC_Customer into a standardized array for MCP responses.
 */
function cowboy_mcp_woo_format_customer( WC_Customer $customer ): array {
    return [
        'id'              => $customer->get_id(),
        'email'           => $customer->get_email(),
        'first_name'      => $customer->get_first_name(),
        'last_name'       => $customer->get_last_name(),
        'display_name'    => $customer->get_display_name(),
        'username'        => $customer->get_username(),
        'role'            => $customer->get_role(),
        'date_created'    => $customer->get_date_created()?->date( 'c' ),
        'date_modified'   => $customer->get_date_modified()?->date( 'c' ),
        'billing'         => [
            'first_name' => $customer->get_billing_first_name(),
            'last_name'  => $customer->get_billing_last_name(),
            'email'      => $customer->get_billing_email(),
            'phone'      => $customer->get_billing_phone(),
            'address_1'  => $customer->get_billing_address_1(),
            'address_2'  => $customer->get_billing_address_2(),
            'city'       => $customer->get_billing_city(),
            'state'      => $customer->get_billing_state(),
            'postcode'   => $customer->get_billing_postcode(),
            'country'    => $customer->get_billing_country(),
            'company'    => $customer->get_billing_company(),
        ],
        'shipping'        => [
            'first_name' => $customer->get_shipping_first_name(),
            'last_name'  => $customer->get_shipping_last_name(),
            'address_1'  => $customer->get_shipping_address_1(),
            'address_2'  => $customer->get_shipping_address_2(),
            'city'       => $customer->get_shipping_city(),
            'state'      => $customer->get_shipping_state(),
            'postcode'   => $customer->get_shipping_postcode(),
            'country'    => $customer->get_shipping_country(),
            'company'    => $customer->get_shipping_company(),
        ],
        'is_paying'       => $customer->get_is_paying_customer(),
        'orders_count'    => $customer->get_order_count(),
        'total_spent'     => $customer->get_total_spent(),
    ];
}

/* ================================================================
 *  WooCommerce guard — return empty arrays when WC is not active.
 * ================================================================ */

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Customer' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_woo_list_customers', '[WooCommerce] List WooCommerce customers with search and pagination.', [
            'search'   => [ 'type' => 'string',  'description' => 'Search by name, email, or username' ],
            'role'     => [ 'type' => 'string',  'description' => 'WordPress role filter', 'default' => 'customer' ],
            'per_page' => [ 'type' => 'integer', 'description' => 'Results per page (max 100)', 'default' => 20 ],
            'page'     => [ 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ],
            'orderby'  => [ 'type' => 'string',  'description' => 'Order by: name, id, email, registered_date', 'default' => 'name' ],
            'order'    => [ 'type' => 'string',  'description' => 'ASC or DESC', 'default' => 'ASC' ],
        ], [
            'title'           => 'List Customers',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'customers'   => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'            => [ 'type' => 'integer' ],
                            'email'         => [ 'type' => 'string' ],
                            'first_name'    => [ 'type' => 'string' ],
                            'last_name'     => [ 'type' => 'string' ],
                            'display_name'  => [ 'type' => 'string' ],
                            'username'      => [ 'type' => 'string' ],
                            'role'          => [ 'type' => 'string' ],
                            'date_created'  => [ 'type' => 'string' ],
                            'date_modified' => [ 'type' => 'string' ],
                            'billing'       => [ 'type' => 'object' ],
                            'shipping'      => [ 'type' => 'object' ],
                            'is_paying'     => [ 'type' => 'boolean' ],
                            'orders_count'  => [ 'type' => 'integer' ],
                            'total_spent'   => [ 'type' => 'string' ],
                        ],
                    ],
                ],
                'total'       => [ 'type' => 'integer' ],
                'total_pages' => [ 'type' => 'integer' ],
                'page'        => [ 'type' => 'integer' ],
                'per_page'    => [ 'type' => 'integer' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_get_customer', '[WooCommerce] Get full details of a WooCommerce customer by ID including addresses and order stats.', [
            'customer_id' => [ 'type' => 'integer', 'description' => 'Customer (user) ID', 'required' => true ],
        ], [
            'title'           => 'Get Customer',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'id'            => [ 'type' => 'integer' ],
                'email'         => [ 'type' => 'string' ],
                'first_name'    => [ 'type' => 'string' ],
                'last_name'     => [ 'type' => 'string' ],
                'display_name'  => [ 'type' => 'string' ],
                'username'      => [ 'type' => 'string' ],
                'role'          => [ 'type' => 'string' ],
                'date_created'  => [ 'type' => 'string' ],
                'date_modified' => [ 'type' => 'string' ],
                'billing'       => [ 'type' => 'object' ],
                'shipping'      => [ 'type' => 'object' ],
                'is_paying'     => [ 'type' => 'boolean' ],
                'orders_count'  => [ 'type' => 'integer' ],
                'total_spent'   => [ 'type' => 'string' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_create_customer', '[WooCommerce] Create a new WooCommerce customer with email, name, and addresses.', [
            'email'      => [ 'type' => 'string', 'description' => 'Email address', 'required' => true ],
            'first_name' => [ 'type' => 'string', 'description' => 'First name' ],
            'last_name'  => [ 'type' => 'string', 'description' => 'Last name' ],
            'username'   => [ 'type' => 'string', 'description' => 'Username (auto-generated from email if omitted)' ],
            'password'   => [ 'type' => 'string', 'description' => 'Password (auto-generated if omitted)' ],
            'billing'    => [ 'type' => 'object', 'description' => 'Billing address: {first_name, last_name, email, phone, address_1, city, state, postcode, country}' ],
            'shipping'   => [ 'type' => 'object', 'description' => 'Shipping address fields' ],
        ], [
            'title'           => 'Create Customer',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_update_customer', '[WooCommerce] Update a WooCommerce customer. Only supply fields you want to change.', [
            'customer_id' => [ 'type' => 'integer', 'description' => 'Customer ID', 'required' => true ],
            'email'       => [ 'type' => 'string',  'description' => 'Email address' ],
            'first_name'  => [ 'type' => 'string',  'description' => 'First name' ],
            'last_name'   => [ 'type' => 'string',  'description' => 'Last name' ],
            'billing'     => [ 'type' => 'object',  'description' => 'Billing address fields to update' ],
            'shipping'    => [ 'type' => 'object',  'description' => 'Shipping address fields to update' ],
        ], [
            'title'           => 'Update Customer',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_get_customer_orders', '[WooCommerce] Get order history for a specific customer.', [
            'customer_id' => [ 'type' => 'integer', 'description' => 'Customer ID', 'required' => true ],
            'status'      => [ 'type' => 'string',  'description' => 'Order status filter', 'default' => 'any' ],
            'per_page'    => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 20 ],
            'page'        => [ 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ],
        ], [
            'title'           => 'Get Customer Orders',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_get_customer_meta', '[WooCommerce] Get raw user meta for a WooCommerce customer.', [
            'customer_id' => [ 'type' => 'integer', 'description' => 'Customer (user) ID', 'required' => true ],
            'meta_key'    => [ 'type' => 'string',  'description' => 'Specific meta key (omit for all meta)' ], // phpcs:ignore WordPress.DB.SlowDBQuery
        ], [
            'title'           => 'Get Customer Meta',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),
    ],

    'handlers' => [
        'wp_woo_list_customers' => function ( array $a ): array {
            $per_page = min( (int) ( $a['per_page'] ?? 20 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );

            $args = [
                'role'    => $a['role'] ?? 'customer',
                'number'  => $per_page,
                'paged'   => $page,
                'orderby' => $a['orderby'] ?? 'name',
                'order'   => $a['order'] ?? 'ASC',
            ];

            if ( ! empty( $a['search'] ) ) {
                $args['search']         = '*' . sanitize_text_field( $a['search'] ) . '*';
                $args['search_columns'] = [ 'user_login', 'user_email', 'user_nicename', 'display_name' ];
            }

            $query     = new WP_User_Query( $args );
            $users     = $query->get_results();
            $total     = $query->get_total();
            $customers = [];

            foreach ( $users as $user ) {
                try {
                    $customer    = new WC_Customer( $user->ID );
                    $customers[] = cowboy_mcp_woo_format_customer( $customer );
                } catch ( \Exception $e ) {
                    Cowboy_MCP_Auth::log( 'tool_error', [
                        'tool'    => 'wp_woo_list_customers',
                        'error'   => "Failed to load customer {$user->ID}: " . $e->getMessage(),
                    ] );
                    continue;
                }
            }

            return [
                'customers'   => $customers,
                'total'       => $total,
                'total_pages' => (int) ceil( $total / $per_page ),
                'page'        => $page,
                'per_page'    => $per_page,
            ];
        },

        'wp_woo_get_customer' => function ( array $a ) {
            $id = (int) ( $a['customer_id'] ?? 0 );

            try {
                $customer = new WC_Customer( $id );
            } catch ( \Exception $e ) {
                return new WP_Error( 'not_found', "Customer not found: {$id}" );
            }

            if ( ! $customer->get_id() ) {
                return new WP_Error( 'not_found', "Customer not found: {$id}" );
            }

            return cowboy_mcp_woo_format_customer( $customer );
        },

        'wp_woo_create_customer' => function ( array $a ) {
            $email    = sanitize_email( $a['email'] ?? '' );
            $username = $a['username'] ?? '';
            $password = $a['password'] ?? wp_generate_password();

            if ( empty( $email ) || ! is_email( $email ) ) {
                return new WP_Error( 'invalid_email', 'A valid email address is required.' );
            }

            $customer = new WC_Customer();
            $customer->set_email( $email );

            if ( ! empty( $username ) ) {
                $customer->set_username( $username );
            } else {
                $customer->set_username( wc_create_new_customer_username( $email ) );
            }

            $customer->set_password( $password );

            if ( isset( $a['first_name'] ) ) $customer->set_first_name( $a['first_name'] );
            if ( isset( $a['last_name'] ) )  $customer->set_last_name( $a['last_name'] );

            if ( ! empty( $a['billing'] ) && is_array( $a['billing'] ) ) {
                cowboy_mcp_woo_set_address_fields( $customer, 'billing', $a['billing'] );
            }
            if ( ! empty( $a['shipping'] ) && is_array( $a['shipping'] ) ) {
                cowboy_mcp_woo_set_address_fields( $customer, 'shipping', $a['shipping'] );
            }

            $id = $customer->save();

            if ( ! $id ) {
                return new WP_Error( 'create_failed', 'Failed to create customer.' );
            }

            return [
                'created'     => true,
                'customer_id' => $id,
                'customer'    => cowboy_mcp_woo_format_customer( new WC_Customer( $id ) ),
            ];
        },

        'wp_woo_update_customer' => function ( array $a ) {
            $id = (int) ( $a['customer_id'] ?? 0 );

            try {
                $customer = new WC_Customer( $id );
            } catch ( \Exception $e ) {
                return new WP_Error( 'not_found', "Customer not found: {$id}" );
            }

            if ( ! $customer->get_id() ) {
                return new WP_Error( 'not_found', "Customer not found: {$id}" );
            }

            if ( isset( $a['email'] ) )      $customer->set_email( sanitize_email( $a['email'] ) );
            if ( isset( $a['first_name'] ) ) $customer->set_first_name( $a['first_name'] );
            if ( isset( $a['last_name'] ) )  $customer->set_last_name( $a['last_name'] );

            if ( ! empty( $a['billing'] ) && is_array( $a['billing'] ) ) {
                cowboy_mcp_woo_set_address_fields( $customer, 'billing', $a['billing'] );
            }
            if ( ! empty( $a['shipping'] ) && is_array( $a['shipping'] ) ) {
                cowboy_mcp_woo_set_address_fields( $customer, 'shipping', $a['shipping'] );
            }

            $customer->save();

            return [
                'updated'     => true,
                'customer_id' => $id,
                'customer'    => cowboy_mcp_woo_format_customer( new WC_Customer( $id ) ),
            ];
        },

        'wp_woo_get_customer_orders' => function ( array $a ) {
            $id       = (int) ( $a['customer_id'] ?? 0 );
            $per_page = min( (int) ( $a['per_page'] ?? 20 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );

            $args = [
                'customer_id' => $id,
                'limit'       => $per_page,
                'page'        => $page,
                'paginate'    => true,
            ];

            if ( ! empty( $a['status'] ) && $a['status'] !== 'any' ) {
                $args['status'] = $a['status'];
            }

            $results = wc_get_orders( $args );

            return [
                'customer_id' => $id,
                'orders'      => array_map( 'cowboy_mcp_woo_format_order', $results->orders ),
                'total'       => $results->total,
                'total_pages' => $results->max_num_pages,
                'page'        => $page,
                'per_page'    => $per_page,
            ];
        },

        'wp_woo_get_customer_meta' => function ( array $a ) {
            $id       = (int) ( $a['customer_id'] ?? 0 );
            $meta_key = $a['meta_key'] ?? '';

            $user = get_userdata( $id );
            if ( ! $user ) {
                return new WP_Error( 'not_found', "Customer not found: {$id}" );
            }

            if ( ! empty( $meta_key ) ) {
                $hard_secret = (bool) preg_match( '/^(user_pass|user_activation_key|session_tokens)$/i', $meta_key );
                $value       = ( $hard_secret || Cowboy_MCP_Security::is_sensitive_option( $meta_key ) )
                    ? '[REDACTED]'
                    : get_user_meta( $id, $meta_key, true );
                return [
                    'customer_id' => $id,
                    'meta_key'    => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
                    'value'       => $value,
                ];
            }

            $all_meta = get_user_meta( $id );
            $filtered = [];
            foreach ( $all_meta as $key => $values ) {
                $filtered[ $key ] = count( $values ) === 1 ? $values[0] : $values;
            }
            // Defense-in-depth: mask session tokens, password hashes and other
            // secret-named user meta before returning it over MCP.
            $filtered = Cowboy_MCP_Security::redact_meta( $filtered );

            return [
                'customer_id' => $id,
                'meta'        => $filtered,
            ];
        },
    ],
];
