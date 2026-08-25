<?php
defined( 'ABSPATH' ) || exit;

/**
 * Validate date range params and return WC-prefixed statuses.
 *
 * @return array{date_after: string, date_before: string, wc_statuses: string[]}|WP_Error
 */
function cowboy_mcp_woo_validate_date_range( array $a, array $default_statuses = [ 'completed', 'processing' ] ): array|WP_Error {
    $date_after  = sanitize_text_field( $a['date_after'] ?? '' );
    $date_before = sanitize_text_field( $a['date_before'] ?? '' );

    if ( empty( $date_after ) || empty( $date_before ) ) {
        return new WP_Error( 'missing_param', 'date_after and date_before are required.' );
    }

    $statuses    = $a['status'] ?? $default_statuses;
    $wc_statuses = array_map( fn( $s ) => 'wc-' . ltrim( $s, 'wc-' ), $statuses );

    return [
        'date_after'  => $date_after,
        'date_before' => $date_before,
        'statuses'    => $statuses,
        'wc_statuses' => $wc_statuses,
    ];
}

/**
 * Detect whether WooCommerce High-Performance Order Storage (HPOS) is enabled.
 */
function cowboy_mcp_woo_is_hpos_enabled(): bool {
    if ( ! class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
        return false;
    }
    return Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
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
        Cowboy_MCP_Tools::tool( 'wp_woo_report_sales', '[WooCommerce] Aggregate sales totals for a date range: revenue, orders count, items sold, refunds, tax, shipping.', [
            'date_after'  => [ 'type' => 'string', 'description' => 'Start date (YYYY-MM-DD)', 'required' => true ],
            'date_before' => [ 'type' => 'string', 'description' => 'End date (YYYY-MM-DD)', 'required' => true ],
            'status'      => [ 'type' => 'array',  'description' => 'Order statuses to include', 'items' => [ 'type' => 'string' ], 'default' => [ 'completed', 'processing' ] ],
        ], [
            'title'           => 'Sales Report',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'date_after'  => [ 'type' => 'string' ],
                'date_before' => [ 'type' => 'string' ],
                'statuses'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'totals'      => [
                    'type' => 'object',
                    'properties' => [
                        'total_revenue'  => [ 'type' => 'number' ],
                        'total_orders'   => [ 'type' => 'integer' ],
                        'total_items'    => [ 'type' => 'integer' ],
                        'total_refunds'  => [ 'type' => 'number' ],
                        'total_tax'      => [ 'type' => 'number' ],
                        'total_shipping' => [ 'type' => 'number' ],
                        'total_discount' => [ 'type' => 'number' ],
                        'average_order'  => [ 'type' => 'number' ],
                    ],
                ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_report_top_products', '[WooCommerce] Top-selling products by quantity or revenue for a date range.', [
            'date_after'  => [ 'type' => 'string',  'description' => 'Start date (YYYY-MM-DD)', 'required' => true ],
            'date_before' => [ 'type' => 'string',  'description' => 'End date (YYYY-MM-DD)', 'required' => true ],
            'orderby'     => [ 'type' => 'string',  'description' => 'Rank by: quantity or revenue', 'default' => 'quantity' ],
            'limit'       => [ 'type' => 'integer', 'description' => 'Number of products to return', 'default' => 10 ],
        ], [
            'title'           => 'Top Products Report',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'date_after'  => [ 'type' => 'string' ],
                'date_before' => [ 'type' => 'string' ],
                'orderby'     => [ 'type' => 'string' ],
                'products'    => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id'     => [ 'type' => 'integer' ],
                            'product_name'   => [ 'type' => 'string' ],
                            'total_quantity' => [ 'type' => 'integer' ],
                            'total_revenue'  => [ 'type' => 'number' ],
                        ],
                    ],
                ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_report_orders_by_status', '[WooCommerce] Order counts grouped by status.', [], [
            'title'           => 'Orders by Status Report',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'statuses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => [ 'type' => 'string' ],
                            'label'  => [ 'type' => 'string' ],
                            'count'  => [ 'type' => 'integer' ],
                        ],
                    ],
                ],
                'total' => [ 'type' => 'integer' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_report_revenue_by_date', '[WooCommerce] Daily revenue breakdown for a date range.', [
            'date_after'  => [ 'type' => 'string', 'description' => 'Start date (YYYY-MM-DD)', 'required' => true ],
            'date_before' => [ 'type' => 'string', 'description' => 'End date (YYYY-MM-DD)', 'required' => true ],
            'status'      => [ 'type' => 'array',  'description' => 'Order statuses to include', 'items' => [ 'type' => 'string' ], 'default' => [ 'completed', 'processing' ] ],
        ], [
            'title'           => 'Revenue by Date Report',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'date_after'  => [ 'type' => 'string' ],
                'date_before' => [ 'type' => 'string' ],
                'statuses'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'days'        => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date'    => [ 'type' => 'string' ],
                            'revenue' => [ 'type' => 'number' ],
                            'orders'  => [ 'type' => 'integer' ],
                            'items'   => [ 'type' => 'integer' ],
                        ],
                    ],
                ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_report_customer_stats', '[WooCommerce] Customer aggregate statistics and top spenders.', [
            'limit' => [ 'type' => 'integer', 'description' => 'Number of top spenders to return', 'default' => 10 ],
        ], [
            'title'           => 'Customer Stats Report',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'total_customers'  => [ 'type' => 'integer' ],
                'paying_customers' => [ 'type' => 'integer' ],
                'top_spenders'     => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'           => [ 'type' => 'integer' ],
                            'name'         => [ 'type' => 'string' ],
                            'email'        => [ 'type' => 'string' ],
                            'total_spent'  => [ 'type' => 'number' ],
                            'orders_count' => [ 'type' => 'integer' ],
                        ],
                    ],
                ],
            ],
        ]),
    ],

    'handlers' => [
        'wp_woo_report_sales' => function ( array $a ) {
            $range = cowboy_mcp_woo_validate_date_range( $a );
            if ( is_wp_error( $range ) ) return $range;

            $orders = wc_get_orders( [
                'status'       => $range['wc_statuses'],
                'date_after'   => $range['date_after'],
                'date_before'  => $range['date_before'],
                'limit'        => -1,
                'return'       => 'objects',
            ] );

            $totals = [
                'total_revenue'  => 0,
                'total_orders'   => 0,
                'total_items'    => 0,
                'total_refunds'  => 0,
                'total_tax'      => 0,
                'total_shipping' => 0,
                'total_discount' => 0,
                'average_order'  => 0,
            ];

            foreach ( $orders as $order ) {
                $totals['total_revenue']  += (float) $order->get_total();
                $totals['total_orders']   += 1;
                $totals['total_items']    += $order->get_item_count();
                $totals['total_tax']      += (float) $order->get_total_tax();
                $totals['total_shipping'] += (float) $order->get_shipping_total();
                $totals['total_discount'] += (float) $order->get_discount_total();

                foreach ( $order->get_refunds() as $refund ) {
                    $totals['total_refunds'] += (float) $refund->get_amount();
                }
            }

            if ( $totals['total_orders'] > 0 ) {
                $totals['average_order'] = round( $totals['total_revenue'] / $totals['total_orders'], 2 );
            }

            // Round monetary values.
            foreach ( [ 'total_revenue', 'total_refunds', 'total_tax', 'total_shipping', 'total_discount' ] as $key ) {
                $totals[ $key ] = round( $totals[ $key ], 2 );
            }

            return [
                'date_after'  => $range['date_after'],
                'date_before' => $range['date_before'],
                'statuses'    => $range['statuses'],
                'totals'      => $totals,
            ];
        },

        'wp_woo_report_top_products' => function ( array $a ) {
            global $wpdb;

            $range = cowboy_mcp_woo_validate_date_range( $a );
            if ( is_wp_error( $range ) ) return $range;

            $date_after  = $range['date_after'];
            $date_before = $range['date_before'];

            $orderby     = ( $a['orderby'] ?? 'quantity' ) === 'revenue' ? 'revenue' : 'quantity';
            $limit       = min( (int) ( $a['limit'] ?? 10 ), 100 );

            $hpos = cowboy_mcp_woo_is_hpos_enabled();

            $order_col       = $orderby === 'revenue' ? 'total_revenue' : 'total_quantity';
            $items_table     = $wpdb->prefix . 'woocommerce_order_items';
            $itemmeta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

            if ( $hpos ) {
                $orders_table = $wpdb->prefix . 'wc_orders';
                $query = $wpdb->prepare(
                    "SELECT oi.order_item_name AS product_name,
                            oim_pid.meta_value AS product_id,
                            SUM( oim_qty.meta_value ) AS total_quantity,
                            SUM( oim_total.meta_value ) AS total_revenue
                     FROM %i oi
                     INNER JOIN %i o ON o.id = oi.order_id
                     INNER JOIN %i oim_pid ON oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key = '_product_id'
                     INNER JOIN %i oim_qty ON oim_qty.order_item_id = oi.order_item_id AND oim_qty.meta_key = '_qty'
                     INNER JOIN %i oim_total ON oim_total.order_item_id = oi.order_item_id AND oim_total.meta_key = '_line_total'
                     WHERE oi.order_item_type = 'line_item'
                       AND o.status IN ('wc-completed', 'wc-processing')
                       AND o.date_created_gmt >= %s
                       AND o.date_created_gmt <= %s
                     GROUP BY oim_pid.meta_value
                     ORDER BY %i DESC
                     LIMIT %d",
                    $items_table,
                    $orders_table,
                    $itemmeta_table,
                    $itemmeta_table,
                    $itemmeta_table,
                    $date_after . ' 00:00:00',
                    $date_before . ' 23:59:59',
                    $order_col,
                    $limit
                );
            } else {
                $query = $wpdb->prepare(
                    "SELECT oi.order_item_name AS product_name,
                            oim_pid.meta_value AS product_id,
                            SUM( oim_qty.meta_value ) AS total_quantity,
                            SUM( oim_total.meta_value ) AS total_revenue
                     FROM %i oi
                     INNER JOIN %i p ON p.ID = oi.order_id
                     INNER JOIN %i oim_pid ON oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key = '_product_id'
                     INNER JOIN %i oim_qty ON oim_qty.order_item_id = oi.order_item_id AND oim_qty.meta_key = '_qty'
                     INNER JOIN %i oim_total ON oim_total.order_item_id = oi.order_item_id AND oim_total.meta_key = '_line_total'
                     WHERE oi.order_item_type = 'line_item'
                       AND p.post_status IN ('wc-completed', 'wc-processing')
                       AND p.post_date >= %s
                       AND p.post_date <= %s
                     GROUP BY oim_pid.meta_value
                     ORDER BY %i DESC
                     LIMIT %d",
                    $items_table,
                    $wpdb->posts,
                    $itemmeta_table,
                    $itemmeta_table,
                    $itemmeta_table,
                    $date_after . ' 00:00:00',
                    $date_before . ' 23:59:59',
                    $order_col,
                    $limit
                );
            }

            $results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

            $products = array_map( fn( $row ) => [
                'product_id'     => (int) $row['product_id'],
                'product_name'   => $row['product_name'],
                'total_quantity' => (int) $row['total_quantity'],
                'total_revenue'  => round( (float) $row['total_revenue'], 2 ),
            ], $results ?: [] );

            return [
                'date_after'  => $date_after,
                'date_before' => $date_before,
                'orderby'     => $orderby,
                'products'    => $products,
            ];
        },

        'wp_woo_report_orders_by_status' => function ( array $a ): array {
            $statuses = wc_get_order_statuses();
            $counts   = [];

            foreach ( $statuses as $slug => $label ) {
                $count = wc_orders_count( $slug );
                $counts[] = [
                    'status' => $slug,
                    'label'  => $label,
                    'count'  => (int) $count,
                ];
            }

            $total = array_sum( array_column( $counts, 'count' ) );

            return [
                'statuses' => $counts,
                'total'    => $total,
            ];
        },

        'wp_woo_report_revenue_by_date' => function ( array $a ) {
            $range = cowboy_mcp_woo_validate_date_range( $a );
            if ( is_wp_error( $range ) ) return $range;

            $orders = wc_get_orders( [
                'status'       => $range['wc_statuses'],
                'date_after'   => $range['date_after'],
                'date_before'  => $range['date_before'],
                'limit'        => -1,
                'return'       => 'objects',
                'orderby'      => 'date',
                'order'        => 'ASC',
            ] );

            $daily = [];

            foreach ( $orders as $order ) {
                $date = $order->get_date_created()?->date( 'Y-m-d' );
                if ( ! $date ) continue;

                if ( ! isset( $daily[ $date ] ) ) {
                    $daily[ $date ] = [
                        'date'     => $date,
                        'revenue'  => 0,
                        'orders'   => 0,
                        'items'    => 0,
                    ];
                }

                $daily[ $date ]['revenue'] += (float) $order->get_total();
                $daily[ $date ]['orders']  += 1;
                $daily[ $date ]['items']   += $order->get_item_count();
            }

            // Round revenue.
            $days = array_values( array_map( function( $day ) {
                $day['revenue'] = round( $day['revenue'], 2 );
                return $day;
            }, $daily ) );

            return [
                'date_after'  => $range['date_after'],
                'date_before' => $range['date_before'],
                'statuses'    => $range['statuses'],
                'days'        => $days,
            ];
        },

        'wp_woo_report_customer_stats' => function ( array $a ): array {
            $limit = min( (int) ( $a['limit'] ?? 10 ), 100 );

            // Overall customer stats.
            $total_customers = (int) ( new WP_User_Query( [
                'role'   => 'customer',
                'number' => 0,
                'count_total' => true,
                'fields' => 'ID',
            ] ) )->get_total();

            // Paying customers (have at least 1 order) — HPOS-aware.
            global $wpdb;
            if ( cowboy_mcp_woo_is_hpos_enabled() ) {
                $paying_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->prepare( "SELECT COUNT( DISTINCT customer_id ) FROM %i WHERE customer_id > %d", $wpdb->prefix . 'wc_orders', 0 )
                );
            } else {
                $paying_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->prepare( "SELECT COUNT( DISTINCT meta_value ) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value > %d", '_customer_user', 0 )
                );
            }

            // Top spenders — use WC Analytics lookup table (indexed total_spend).
            $lookup_table  = $wpdb->prefix . 'wc_customer_lookup';
            $has_lookup    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            if ( $has_lookup ) {
                $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    'SELECT user_id, first_name, last_name, email, total_spend, orders_count
                     FROM %i
                     WHERE user_id > 0
                     ORDER BY total_spend DESC
                     LIMIT %d',
                    $lookup_table,
                    $limit
                ) );

                $top_spenders = [];
                foreach ( $rows as $row ) {
                    $top_spenders[] = [
                        'id'           => (int) $row->user_id,
                        'name'         => trim( $row->first_name . ' ' . $row->last_name ),
                        'email'        => $row->email,
                        'total_spent'  => round( (float) $row->total_spend, 2 ),
                        'orders_count' => (int) $row->orders_count,
                    ];
                }
            } else {
                // Fallback for very old WC without analytics tables — direct SQL avoids meta_key PHPCS flag.
                $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT u.ID AS user_id
                     FROM {$wpdb->users} u
                     INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = '_money_spent'
                     INNER JOIN {$wpdb->usermeta} cap ON u.ID = cap.user_id AND cap.meta_key = %s AND cap.meta_value LIKE %s
                     ORDER BY um.meta_value + 0 DESC
                     LIMIT %d",
                    $wpdb->prefix . 'capabilities',
                    '%"customer"%',
                    $limit
                ) );

                $top_spenders = [];
                foreach ( $rows as $row ) {
                    try {
                        $customer       = new WC_Customer( (int) $row->user_id );
                        $top_spenders[] = [
                            'id'           => (int) $row->user_id,
                            'name'         => $customer->get_first_name() . ' ' . $customer->get_last_name(),
                            'email'        => $customer->get_email(),
                            'total_spent'  => (float) $customer->get_total_spent(),
                            'orders_count' => (int) $customer->get_order_count(),
                        ];
                    } catch ( \Exception $e ) {
                        continue;
                    }
                }
            }

            return [
                'total_customers'  => $total_customers,
                'paying_customers' => $paying_count,
                'top_spenders'     => $top_spenders,
            ];
        },
    ],
];
