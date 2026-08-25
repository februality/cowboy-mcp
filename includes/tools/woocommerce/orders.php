<?php
defined( 'ABSPATH' ) || exit;

/**
 * Set billing/shipping address fields on a WC_Order or WC_Customer.
 * Eliminates duplicate foreach-setter pattern across orders and customers.
 */
function cowboy_mcp_woo_set_address_fields( $object, string $prefix, array $data ): void {
    $fields = [ 'first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'company' ];
    foreach ( $fields as $field ) {
        if ( isset( $data[ $field ] ) ) {
            $setter = "set_{$prefix}_{$field}";
            if ( method_exists( $object, $setter ) ) {
                $object->$setter( $data[ $field ] );
            }
        }
    }
}

/**
 * Format a WC_Order into a standardized array for MCP responses.
 */
function cowboy_mcp_woo_format_order( WC_Order $order ): array {
    $line_items = [];
    foreach ( $order->get_items() as $item ) {
        $line_items[] = [
            'id'           => $item->get_id(),
            'product_id'   => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'name'         => $item->get_name(),
            'quantity'     => $item->get_quantity(),
            'subtotal'     => $item->get_subtotal(),
            'total'        => $item->get_total(),
            'tax'          => $item->get_total_tax(),
            'sku'          => $item->get_product() ? $item->get_product()->get_sku() : '',
        ];
    }

    $coupon_lines = [];
    foreach ( $order->get_items( 'coupon' ) as $coupon ) {
        $coupon_lines[] = [
            'code'     => $coupon->get_code(),
            'discount' => $coupon->get_discount(),
            'tax'      => $coupon->get_discount_tax(),
        ];
    }

    $fee_lines = [];
    foreach ( $order->get_items( 'fee' ) as $fee ) {
        $fee_lines[] = [
            'name'  => $fee->get_name(),
            'total' => $fee->get_total(),
            'tax'   => $fee->get_total_tax(),
        ];
    }

    $shipping_lines = [];
    foreach ( $order->get_items( 'shipping' ) as $shipping ) {
        $shipping_lines[] = [
            'method_title' => $shipping->get_method_title(),
            'method_id'    => $shipping->get_method_id(),
            'total'        => $shipping->get_total(),
        ];
    }

    $refunds = [];
    foreach ( $order->get_refunds() as $refund ) {
        $refunds[] = [
            'id'     => $refund->get_id(),
            'amount' => $refund->get_amount(),
            'reason' => $refund->get_reason(),
            'date'   => $refund->get_date_created()?->date( 'c' ),
        ];
    }

    return [
        'id'               => $order->get_id(),
        'status'           => $order->get_status(),
        'currency'         => $order->get_currency(),
        'total'            => $order->get_total(),
        'subtotal'         => $order->get_subtotal(),
        'total_tax'        => $order->get_total_tax(),
        'shipping_total'   => $order->get_shipping_total(),
        'discount_total'   => $order->get_discount_total(),
        'payment_method'   => $order->get_payment_method(),
        'payment_title'    => $order->get_payment_method_title(),
        'transaction_id'   => $order->get_transaction_id(),
        'customer_id'      => $order->get_customer_id(),
        'billing'          => [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        ],
        'shipping'         => [
            'first_name' => $order->get_shipping_first_name(),
            'last_name'  => $order->get_shipping_last_name(),
            'address_1'  => $order->get_shipping_address_1(),
            'address_2'  => $order->get_shipping_address_2(),
            'city'       => $order->get_shipping_city(),
            'state'      => $order->get_shipping_state(),
            'postcode'   => $order->get_shipping_postcode(),
            'country'    => $order->get_shipping_country(),
        ],
        'line_items'       => $line_items,
        'coupon_lines'     => $coupon_lines,
        'fee_lines'        => $fee_lines,
        'shipping_lines'   => $shipping_lines,
        'refunds'          => $refunds,
        'customer_note'    => $order->get_customer_note(),
        'date_created'     => $order->get_date_created()?->date( 'c' ),
        'date_modified'    => $order->get_date_modified()?->date( 'c' ),
        'date_completed'   => $order->get_date_completed()?->date( 'c' ),
        'date_paid'        => $order->get_date_paid()?->date( 'c' ),
    ];
}

/* ================================================================
 *  WooCommerce guard — return empty arrays when WC is not active.
 * ================================================================ */

if ( ! Cowboy_MCP_Tools::domain_available( __FILE__ ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_woo_list_orders', '[WooCommerce] List WooCommerce orders with filtering by status, customer, date range. Supports pagination.', [
            'status'     => [ 'type' => 'string',  'description' => 'Order status: pending, processing, on-hold, completed, cancelled, refunded, failed, any', 'default' => 'any' ],
            'customer'   => [ 'type' => 'integer', 'description' => 'Customer user ID' ],
            'date_after' => [ 'type' => 'string',  'description' => 'Orders after this date (YYYY-MM-DD)' ],
            'date_before'=> [ 'type' => 'string',  'description' => 'Orders before this date (YYYY-MM-DD)' ],
            'search'     => [ 'type' => 'string',  'description' => 'Search by order number, email, name' ],
            'per_page'   => [ 'type' => 'integer', 'description' => 'Results per page (max 100)', 'default' => 20 ],
            'page'       => [ 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ],
            'orderby'    => [ 'type' => 'string',  'description' => 'Order by: date, id, total', 'default' => 'date' ],
            'order'      => [ 'type' => 'string',  'description' => 'ASC or DESC', 'default' => 'DESC' ],
        ], [
            'title'           => 'List Orders',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'orders'      => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'               => [ 'type' => 'integer' ],
                            'status'           => [ 'type' => 'string' ],
                            'currency'         => [ 'type' => 'string' ],
                            'total'            => [ 'type' => 'string' ],
                            'subtotal'         => [ 'type' => 'string' ],
                            'total_tax'        => [ 'type' => 'string' ],
                            'shipping_total'   => [ 'type' => 'string' ],
                            'discount_total'   => [ 'type' => 'string' ],
                            'payment_method'   => [ 'type' => 'string' ],
                            'payment_title'    => [ 'type' => 'string' ],
                            'transaction_id'   => [ 'type' => 'string' ],
                            'customer_id'      => [ 'type' => 'integer' ],
                            'billing'          => [ 'type' => 'object' ],
                            'shipping'         => [ 'type' => 'object' ],
                            'line_items'       => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                            'coupon_lines'     => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                            'fee_lines'        => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                            'shipping_lines'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                            'refunds'          => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                            'customer_note'    => [ 'type' => 'string' ],
                            'date_created'     => [ 'type' => 'string' ],
                            'date_modified'    => [ 'type' => 'string' ],
                            'date_completed'   => [ 'type' => 'string' ],
                            'date_paid'        => [ 'type' => 'string' ],
                        ],
                    ],
                ],
                'total'       => [ 'type' => 'integer' ],
                'total_pages' => [ 'type' => 'integer' ],
                'page'        => [ 'type' => 'integer' ],
                'per_page'    => [ 'type' => 'integer' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_get_order', '[WooCommerce] Get full details of a WooCommerce order by ID including line items, addresses, and payment info.', [
            'order_id' => [ 'type' => 'integer', 'description' => 'Order ID', 'required' => true ],
        ], [
            'title'           => 'Get Order',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'id'               => [ 'type' => 'integer' ],
                'status'           => [ 'type' => 'string' ],
                'currency'         => [ 'type' => 'string' ],
                'total'            => [ 'type' => 'string' ],
                'subtotal'         => [ 'type' => 'string' ],
                'total_tax'        => [ 'type' => 'string' ],
                'shipping_total'   => [ 'type' => 'string' ],
                'discount_total'   => [ 'type' => 'string' ],
                'payment_method'   => [ 'type' => 'string' ],
                'payment_title'    => [ 'type' => 'string' ],
                'transaction_id'   => [ 'type' => 'string' ],
                'customer_id'      => [ 'type' => 'integer' ],
                'billing'          => [ 'type' => 'object' ],
                'shipping'         => [ 'type' => 'object' ],
                'line_items'       => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'coupon_lines'     => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'fee_lines'        => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'shipping_lines'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'refunds'          => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'customer_note'    => [ 'type' => 'string' ],
                'date_created'     => [ 'type' => 'string' ],
                'date_modified'    => [ 'type' => 'string' ],
                'date_completed'   => [ 'type' => 'string' ],
                'date_paid'        => [ 'type' => 'string' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_create_order', '[WooCommerce] Create a new WooCommerce order with line items, addresses, and optional coupons.', [
            'status'        => [ 'type' => 'string',  'description' => 'Order status', 'default' => 'pending' ],
            'customer_id'   => [ 'type' => 'integer', 'description' => 'Customer user ID (0 for guest)' ],
            'line_items'    => [ 'type' => 'array',   'description' => 'Line items: [{product_id, quantity, variation_id?}]', 'required' => true, 'items' => [ 'type' => 'object' ] ],
            'billing'       => [ 'type' => 'object',  'description' => 'Billing address fields: {first_name, last_name, email, phone, address_1, city, state, postcode, country}' ],
            'shipping'      => [ 'type' => 'object',  'description' => 'Shipping address fields' ],
            'coupons'       => [ 'type' => 'array',   'description' => 'Coupon codes to apply', 'items' => [ 'type' => 'string' ] ],
            'payment_method'=> [ 'type' => 'string',  'description' => 'Payment method ID' ],
            'customer_note' => [ 'type' => 'string',  'description' => 'Customer-facing note' ],
            'meta'          => [ 'type' => 'object',  'description' => 'Key-value meta data' ],
        ], [
            'title'           => 'Create Order',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_update_order', '[WooCommerce] Update an existing WooCommerce order. Supports status change, address update, and meta.', [
            'order_id'       => [ 'type' => 'integer', 'description' => 'Order ID', 'required' => true ],
            'status'         => [ 'type' => 'string',  'description' => 'New order status' ],
            'billing'        => [ 'type' => 'object',  'description' => 'Billing address fields to update' ],
            'shipping'       => [ 'type' => 'object',  'description' => 'Shipping address fields to update' ],
            'customer_note'  => [ 'type' => 'string',  'description' => 'Customer note' ],
            'payment_method' => [ 'type' => 'string',  'description' => 'Payment method ID' ],
            'transaction_id' => [ 'type' => 'string',  'description' => 'Transaction ID' ],
            'meta'           => [ 'type' => 'object',  'description' => 'Key-value meta data to set' ],
        ], [
            'title'           => 'Update Order',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_delete_order', '[WooCommerce] Delete or trash a WooCommerce order.', [
            'order_id' => [ 'type' => 'integer', 'description' => 'Order ID', 'required' => true ],
            'force'    => [ 'type' => 'boolean', 'description' => 'Permanently delete (skip trash)', 'default' => false ],
        ], [
            'title'           => 'Delete Order',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_create_refund', '[WooCommerce] Create a refund for an order with optional line item specifics.', [
            'order_id'   => [ 'type' => 'integer', 'description' => 'Order ID', 'required' => true ],
            'amount'     => [ 'type' => 'string',  'description' => 'Refund amount (omit to refund full order)' ],
            'reason'     => [ 'type' => 'string',  'description' => 'Refund reason' ],
            'line_items' => [ 'type' => 'array',   'description' => 'Specific line items to refund: [{item_id, qty, refund_total}]', 'items' => [ 'type' => 'object' ] ],
        ], [
            'title'           => 'Create Refund',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_list_order_notes', '[WooCommerce] List all notes for an order (customer and private).', [
            'order_id' => [ 'type' => 'integer', 'description' => 'Order ID', 'required' => true ],
            'type'     => [ 'type' => 'string',  'description' => 'Filter: customer, internal, any', 'default' => 'any' ],
        ], [
            'title'           => 'List Order Notes',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_add_order_note', '[WooCommerce] Add a note to an order (customer-visible or private/internal).', [
            'order_id'    => [ 'type' => 'integer', 'description' => 'Order ID', 'required' => true ],
            'note'        => [ 'type' => 'string',  'description' => 'Note content', 'required' => true ],
            'is_customer' => [ 'type' => 'boolean', 'description' => 'Send as customer note (visible to customer)', 'default' => false ],
        ], [
            'title'           => 'Add Order Note',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),
    ],

    'handlers' => [
        'wp_woo_list_orders' => function ( array $a ): array {
            $per_page = min( (int) ( $a['per_page'] ?? 20 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );

            $args = [
                'limit'    => $per_page,
                'page'     => $page,
                'orderby'  => $a['orderby'] ?? 'date',
                'order'    => $a['order'] ?? 'DESC',
                'paginate' => true,
            ];

            if ( ! empty( $a['status'] ) && $a['status'] !== 'any' ) {
                $args['status'] = $a['status'];
            }
            if ( ! empty( $a['customer'] ) )    $args['customer_id'] = (int) $a['customer'];
            if ( ! empty( $a['date_after'] ) )  $args['date_after']  = $a['date_after'];
            if ( ! empty( $a['date_before'] ) ) $args['date_before'] = $a['date_before'];
            if ( ! empty( $a['search'] ) )      $args['s']           = $a['search'];

            $results = wc_get_orders( $args );

            return [
                'orders'      => array_map( 'cowboy_mcp_woo_format_order', $results->orders ),
                'total'       => $results->total,
                'total_pages' => $results->max_num_pages,
                'page'        => $page,
                'per_page'    => $per_page,
            ];
        },

        'wp_woo_get_order' => function ( array $a ) {
            $id    = (int) ( $a['order_id'] ?? 0 );
            $order = wc_get_order( $id );

            if ( ! $order ) {
                return new WP_Error( 'not_found', "Order not found: {$id}" );
            }

            return cowboy_mcp_woo_format_order( $order );
        },

        'wp_woo_create_order' => function ( array $a ) {
            $order = wc_create_order( [
                'customer_id' => (int) ( $a['customer_id'] ?? 0 ),
                'status'      => $a['status'] ?? 'pending',
            ] );

            if ( is_wp_error( $order ) ) {
                return $order;
            }

            // Add line items.
            foreach ( $a['line_items'] ?? [] as $item ) {
                $product_id   = (int) ( $item['product_id'] ?? 0 );
                $quantity     = (int) ( $item['quantity'] ?? 1 );
                $variation_id = (int) ( $item['variation_id'] ?? 0 );

                $product = wc_get_product( $variation_id ?: $product_id );
                if ( ! $product ) {
                    continue;
                }

                $order->add_product( $product, $quantity );
            }

            // Set addresses.
            if ( ! empty( $a['billing'] ) && is_array( $a['billing'] ) ) {
                cowboy_mcp_woo_set_address_fields( $order, 'billing', $a['billing'] );
            }
            if ( ! empty( $a['shipping'] ) && is_array( $a['shipping'] ) ) {
                cowboy_mcp_woo_set_address_fields( $order, 'shipping', $a['shipping'] );
            }

            if ( isset( $a['payment_method'] ) )  $order->set_payment_method( $a['payment_method'] );
            if ( isset( $a['customer_note'] ) )    $order->set_customer_note( $a['customer_note'] );

            // Apply coupons.
            if ( ! empty( $a['coupons'] ) && is_array( $a['coupons'] ) ) {
                foreach ( $a['coupons'] as $code ) {
                    $order->apply_coupon( sanitize_text_field( $code ) );
                }
            }

            $order->calculate_totals();
            $order->save();

            // Set meta data.
            if ( ! empty( $a['meta'] ) && is_array( $a['meta'] ) ) {
                foreach ( $a['meta'] as $key => $value ) {
                    $order->update_meta_data( sanitize_key( $key ), $value );
                }
                $order->save_meta_data();
            }

            return [
                'created'  => true,
                'order_id' => $order->get_id(),
                'order'    => cowboy_mcp_woo_format_order( $order ),
            ];
        },

        'wp_woo_update_order' => function ( array $a ) {
            $id    = (int) ( $a['order_id'] ?? 0 );
            $order = wc_get_order( $id );

            if ( ! $order ) {
                return new WP_Error( 'not_found', "Order not found: {$id}" );
            }

            if ( isset( $a['status'] ) )         $order->set_status( $a['status'] );
            if ( isset( $a['customer_note'] ) )   $order->set_customer_note( $a['customer_note'] );
            if ( isset( $a['payment_method'] ) )  $order->set_payment_method( $a['payment_method'] );
            if ( isset( $a['transaction_id'] ) )  $order->set_transaction_id( $a['transaction_id'] );

            if ( ! empty( $a['billing'] ) && is_array( $a['billing'] ) ) {
                cowboy_mcp_woo_set_address_fields( $order, 'billing', $a['billing'] );
            }
            if ( ! empty( $a['shipping'] ) && is_array( $a['shipping'] ) ) {
                cowboy_mcp_woo_set_address_fields( $order, 'shipping', $a['shipping'] );
            }

            // Set meta data.
            if ( ! empty( $a['meta'] ) && is_array( $a['meta'] ) ) {
                foreach ( $a['meta'] as $key => $value ) {
                    $order->update_meta_data( sanitize_key( $key ), $value );
                }
                $order->save_meta_data();
            }

            $order->save();

            return [
                'updated'  => true,
                'order_id' => $id,
                'order'    => cowboy_mcp_woo_format_order( wc_get_order( $id ) ),
            ];
        },

        'wp_woo_delete_order' => function ( array $a ) {
            $id    = (int) ( $a['order_id'] ?? 0 );
            $force = (bool) ( $a['force'] ?? false );
            $order = wc_get_order( $id );

            if ( ! $order ) {
                return new WP_Error( 'not_found', "Order not found: {$id}" );
            }

            $result = $order->delete( $force );

            if ( ! $result ) {
                return new WP_Error( 'delete_failed', "Failed to delete order: {$id}" );
            }

            return [
                'deleted'  => true,
                'order_id' => $id,
                'force'    => $force,
            ];
        },

        'wp_woo_create_refund' => function ( array $a ) {
            $order_id = (int) ( $a['order_id'] ?? 0 );
            $order    = wc_get_order( $order_id );

            if ( ! $order ) {
                return new WP_Error( 'not_found', "Order not found: {$order_id}" );
            }

            $refund_args = [
                'order_id' => $order_id,
                'reason'   => $a['reason'] ?? '',
            ];

            if ( isset( $a['amount'] ) ) {
                $amount = floatval( $a['amount'] );
                $max    = (float) $order->get_total() - (float) $order->get_total_refunded();
                if ( $amount <= 0 || $amount > $max ) {
                    return new WP_Error( 'invalid_param', "Refund amount must be greater than 0 and no more than the refundable total ({$max})." );
                }
                $refund_args['amount'] = $amount;
            }

            if ( ! empty( $a['line_items'] ) && is_array( $a['line_items'] ) ) {
                $line_items = [];
                foreach ( $a['line_items'] as $item ) {
                    $item_id = (int) ( $item['item_id'] ?? 0 );
                    if ( $item_id ) {
                        $line_items[ $item_id ] = [
                            'qty'          => (int) ( $item['qty'] ?? 0 ),
                            'refund_total' => floatval( $item['refund_total'] ?? 0 ),
                        ];
                    }
                }
                $refund_args['line_items'] = $line_items;
            }

            $refund = wc_create_refund( $refund_args );

            if ( is_wp_error( $refund ) ) {
                return $refund;
            }

            return [
                'created'   => true,
                'refund_id' => $refund->get_id(),
                'order_id'  => $order_id,
                'amount'    => $refund->get_amount(),
                'reason'    => $refund->get_reason(),
            ];
        },

        'wp_woo_list_order_notes' => function ( array $a ) {
            $order_id = (int) ( $a['order_id'] ?? 0 );
            $order    = wc_get_order( $order_id );

            if ( ! $order ) {
                return new WP_Error( 'not_found', "Order not found: {$order_id}" );
            }

            $type_filter = $a['type'] ?? 'any';

            $args = [ 'order_id' => $order_id ];
            if ( $type_filter === 'customer' ) {
                $args['type'] = 'customer';
            } elseif ( $type_filter === 'internal' ) {
                $args['type'] = 'internal';
            }

            $notes = wc_get_order_notes( $args );

            $formatted = array_map( fn( $note ) => [
                'id'            => $note->id,
                'content'       => $note->content,
                'date_created'  => $note->date_created->date( 'c' ),
                'customer_note' => $note->customer_note,
                'added_by'      => $note->added_by,
            ], $notes );

            return [
                'order_id' => $order_id,
                'count'    => count( $formatted ),
                'notes'    => $formatted,
            ];
        },

        'wp_woo_add_order_note' => function ( array $a ) {
            $order_id = (int) ( $a['order_id'] ?? 0 );
            $order    = wc_get_order( $order_id );

            if ( ! $order ) {
                return new WP_Error( 'not_found', "Order not found: {$order_id}" );
            }

            $note        = $a['note'] ?? '';
            $is_customer = (bool) ( $a['is_customer'] ?? false );

            if ( empty( $note ) ) {
                return new WP_Error( 'missing_param', 'note is required.' );
            }

            $note_id = $order->add_order_note( $note, $is_customer ? 1 : 0 );

            return [
                'added'       => true,
                'note_id'     => $note_id,
                'order_id'    => $order_id,
                'is_customer' => $is_customer,
            ];
        },
    ],
];
