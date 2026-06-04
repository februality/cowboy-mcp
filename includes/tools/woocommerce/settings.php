<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  WooCommerce guard — return empty arrays when WC is not active.
 * ================================================================ */

if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_woo_get_tax_rates', '[WooCommerce] List tax rates by tax class with pagination.', [
            'tax_class' => [ 'type' => 'string',  'description' => 'Tax class slug (empty string for standard)', 'default' => '' ],
            'per_page'  => [ 'type' => 'integer', 'description' => 'Results per page (max 100)', 'default' => 50 ],
            'page'      => [ 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ],
        ], [
            'title'           => 'Get Tax Rates',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'tax_class'   => [ 'type' => 'string' ],
                'rates'       => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'total'       => [ 'type' => 'integer' ],
                'total_pages' => [ 'type' => 'integer' ],
                'page'        => [ 'type' => 'integer' ],
                'per_page'    => [ 'type' => 'integer' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_create_tax_rate', '[WooCommerce] Create a new WooCommerce tax rate.', [
            'country'   => [ 'type' => 'string',  'description' => 'Country code (2-letter)', 'required' => true ],
            'state'     => [ 'type' => 'string',  'description' => 'State code', 'default' => '' ],
            'postcode'  => [ 'type' => 'string',  'description' => 'Postcode(s), semicolon-separated', 'default' => '' ],
            'city'      => [ 'type' => 'string',  'description' => 'City name(s), semicolon-separated', 'default' => '' ],
            'rate'      => [ 'type' => 'string',  'description' => 'Tax rate percentage (e.g. "20.0000")', 'required' => true ],
            'name'      => [ 'type' => 'string',  'description' => 'Tax rate name (e.g. "VAT")', 'required' => true ],
            'priority'  => [ 'type' => 'integer', 'description' => 'Priority', 'default' => 1 ],
            'compound'  => [ 'type' => 'boolean', 'description' => 'Compound tax', 'default' => false ],
            'shipping'  => [ 'type' => 'boolean', 'description' => 'Apply to shipping', 'default' => true ],
            'tax_class' => [ 'type' => 'string',  'description' => 'Tax class slug (empty for standard)', 'default' => '' ],
        ], [
            'title'           => 'Create Tax Rate',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_list_shipping_zones', '[WooCommerce] List all WooCommerce shipping zones with their methods.', [], [
            'title'           => 'List Shipping Zones',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'zones' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'        => [ 'type' => 'integer' ],
                            'name'      => [ 'type' => 'string' ],
                            'locations' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                            'methods'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                        ],
                    ],
                ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_list_payment_gateways', '[WooCommerce] List all registered payment gateways with their status and settings.', [], [
            'title'           => 'List Payment Gateways',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'gateways' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'           => [ 'type' => 'string' ],
                            'title'        => [ 'type' => 'string' ],
                            'description'  => [ 'type' => 'string' ],
                            'enabled'      => [ 'type' => 'boolean' ],
                            'method_title' => [ 'type' => 'string' ],
                            'supports'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        ],
                    ],
                ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_get_setting', '[WooCommerce] Read a WooCommerce setting (any woocommerce_* option).', [
            'key' => [ 'type' => 'string', 'description' => 'Option key (with or without woocommerce_ prefix)', 'required' => true ],
        ], [
            'title'           => 'Get WooCommerce Setting',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_update_setting', '[WooCommerce] Update a WooCommerce setting (restricted to woocommerce_* options).', [
            'key'   => [ 'type' => 'string', 'description' => 'Option key (with or without woocommerce_ prefix)', 'required' => true ],
            'value' => [ 'description' => 'New value', 'required' => true ],
        ], [
            'title'           => 'Update WooCommerce Setting',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),
    ],

    'handlers' => [
        'wp_woo_get_tax_rates' => function ( array $a ): array {
            global $wpdb;

            $per_page  = min( (int) ( $a['per_page'] ?? 50 ), 100 );
            $page      = max( (int) ( $a['page'] ?? 1 ), 1 );
            $offset    = ( $page - 1 ) * $per_page;
            $tax_class = $a['tax_class'] ?? '';

            $tax_rates_table = $wpdb->prefix . 'woocommerce_tax_rates';
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE tax_rate_class = %s", $tax_rates_table, $tax_class ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rates = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE tax_rate_class = %s ORDER BY tax_rate_order ASC LIMIT %d OFFSET %d",
                    $tax_rates_table,
                    $tax_class,
                    $per_page,
                    $offset
                ),
                ARRAY_A
            );

            return [
                'tax_class'   => $tax_class ?: 'standard',
                'rates'       => $rates ?: [],
                'total'       => $total,
                'total_pages' => (int) ceil( $total / $per_page ),
                'page'        => $page,
                'per_page'    => $per_page,
            ];
        },

        'wp_woo_create_tax_rate' => function ( array $a ) {
            $tax_rate = [
                'tax_rate_country'  => strtoupper( sanitize_text_field( $a['country'] ?? '' ) ),
                'tax_rate_state'    => sanitize_text_field( $a['state'] ?? '' ),
                'tax_rate'          => sanitize_text_field( $a['rate'] ?? '0' ),
                'tax_rate_name'     => sanitize_text_field( $a['name'] ?? '' ),
                'tax_rate_priority' => (int) ( $a['priority'] ?? 1 ),
                'tax_rate_compound' => ! empty( $a['compound'] ) ? 1 : 0,
                'tax_rate_shipping' => ( $a['shipping'] ?? true ) ? 1 : 0,
                'tax_rate_order'    => 0,
                'tax_rate_class'    => sanitize_text_field( $a['tax_class'] ?? '' ),
            ];

            $rate_id = WC_Tax::_insert_tax_rate( $tax_rate );

            // Set postcodes and cities if provided.
            if ( ! empty( $a['postcode'] ) ) {
                WC_Tax::_update_tax_rate_postcodes( $rate_id, $a['postcode'] );
            }
            if ( ! empty( $a['city'] ) ) {
                WC_Tax::_update_tax_rate_cities( $rate_id, $a['city'] );
            }

            return [
                'created' => true,
                'rate_id' => $rate_id,
                'rate'    => $tax_rate,
            ];
        },

        'wp_woo_list_shipping_zones' => function ( array $a ): array {
            $raw_zones = WC_Shipping_Zones::get_zones();
            $zones     = [];

            // Add "Rest of the World" zone (ID 0).
            $zone_zero = new WC_Shipping_Zone( 0 );
            $zones[]   = [
                'id'        => 0,
                'name'      => $zone_zero->get_zone_name(),
                'locations' => [],
                'methods'   => array_map( fn( $m ) => [
                    'id'        => $m->id,
                    'instance'  => $m->instance_id,
                    'title'     => $m->title,
                    'enabled'   => $m->enabled,
                ], $zone_zero->get_shipping_methods() ),
            ];

            foreach ( $raw_zones as $zone_data ) {
                $zone    = new WC_Shipping_Zone( $zone_data['id'] );
                $zones[] = [
                    'id'        => $zone_data['id'],
                    'name'      => $zone_data['zone_name'],
                    'locations' => array_map( fn( $loc ) => [
                        'code' => $loc->code,
                        'type' => $loc->type,
                    ], $zone->get_zone_locations() ),
                    'methods'   => array_map( fn( $m ) => [
                        'id'        => $m->id,
                        'instance'  => $m->instance_id,
                        'title'     => $m->title,
                        'enabled'   => $m->enabled,
                    ], $zone->get_shipping_methods() ),
                ];
            }

            return [ 'zones' => $zones ];
        },

        'wp_woo_list_payment_gateways' => function ( array $a ): array {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $result   = [];

            foreach ( $gateways as $gateway ) {
                $result[] = [
                    'id'          => $gateway->id,
                    'title'       => $gateway->get_title(),
                    'description' => $gateway->get_description(),
                    'enabled'     => $gateway->enabled === 'yes',
                    'method_title'=> $gateway->get_method_title(),
                    'supports'    => $gateway->supports ?? [],
                ];
            }

            return [ 'gateways' => $result ];
        },

        'wp_woo_get_setting' => function ( array $a ) {
            $key = $a['key'] ?? '';

            if ( empty( $key ) ) {
                return new WP_Error( 'missing_param', 'key is required.' );
            }

            // Ensure woocommerce_ prefix.
            if ( strpos( $key, 'woocommerce_' ) !== 0 ) {
                $key = 'woocommerce_' . $key;
            }

            // Payment-gateway groups (woocommerce_*_settings) and other secret-bearing
            // options carry live API/secret keys — never expose them.
            if ( Cowboy_MCP_Security::is_sensitive_option( $key ) ) {
                return new WP_Error( 'protected', "Setting '{$key}' is protected and cannot be read via MCP." );
            }

            $value = get_option( $key, null );

            if ( $value === null ) {
                return new WP_Error( 'not_found', "Setting not found: {$key}" );
            }

            return [
                'key'   => $key,
                'value' => $value,
            ];
        },

        'wp_woo_update_setting' => function ( array $a ) {
            $key = $a['key'] ?? '';

            if ( empty( $key ) ) {
                return new WP_Error( 'missing_param', 'key is required.' );
            }
            if ( ! array_key_exists( 'value', $a ) ) {
                return new WP_Error( 'missing_param', 'value is required.' );
            }

            // Ensure woocommerce_ prefix.
            if ( strpos( $key, 'woocommerce_' ) !== 0 ) {
                $key = 'woocommerce_' . $key;
            }

            // Block writes to payment-gateway/secret option groups (e.g. swapping a
            // gateway's secret key or endpoint to an attacker-controlled value).
            if ( Cowboy_MCP_Security::is_protected_option( $key )
                && ( Cowboy_MCP_Security::is_hard_protected_option( $key )
                     || ! Cowboy_MCP_Security::power_mode_enabled() ) ) {
                return new WP_Error( 'protected', "Setting '{$key}' is protected and cannot be modified via MCP." );
            }

            update_option( $key, $a['value'] );

            return [
                'updated' => true,
                'key'     => $key,
                'value'   => get_option( $key ),
            ];
        },
    ],
];
