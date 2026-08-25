<?php
defined( 'ABSPATH' ) || exit;

/**
 * Apply optional coupon fields from args onto a WC_Coupon object.
 * Shared between create and update handlers.
 */
function cowboy_mcp_woo_set_coupon_fields( WC_Coupon $coupon, array $a ): void {
    if ( isset( $a['description'] ) )                 $coupon->set_description( $a['description'] );
    if ( isset( $a['date_expires'] ) )                $coupon->set_date_expires( $a['date_expires'] );
    if ( isset( $a['individual_use'] ) )              $coupon->set_individual_use( $a['individual_use'] );
    if ( isset( $a['usage_limit'] ) )                 $coupon->set_usage_limit( $a['usage_limit'] );
    if ( isset( $a['usage_limit_per_user'] ) )        $coupon->set_usage_limit_per_user( $a['usage_limit_per_user'] );
    if ( isset( $a['limit_usage_to_x_items'] ) )      $coupon->set_limit_usage_to_x_items( $a['limit_usage_to_x_items'] );
    if ( isset( $a['free_shipping'] ) )               $coupon->set_free_shipping( $a['free_shipping'] );
    if ( isset( $a['exclude_sale_items'] ) )          $coupon->set_exclude_sale_items( $a['exclude_sale_items'] );
    if ( isset( $a['minimum_amount'] ) )              $coupon->set_minimum_amount( $a['minimum_amount'] );
    if ( isset( $a['maximum_amount'] ) )              $coupon->set_maximum_amount( $a['maximum_amount'] );
    if ( isset( $a['product_ids'] ) )                 $coupon->set_product_ids( $a['product_ids'] );
    if ( isset( $a['excluded_product_ids'] ) )        $coupon->set_excluded_product_ids( $a['excluded_product_ids'] );
    if ( isset( $a['product_categories'] ) )          $coupon->set_product_categories( $a['product_categories'] );
    if ( isset( $a['excluded_product_categories'] ) ) $coupon->set_excluded_product_categories( $a['excluded_product_categories'] );
    if ( isset( $a['email_restrictions'] ) )          $coupon->set_email_restrictions( $a['email_restrictions'] );
}

/**
 * Format a WC_Coupon into a standardized array for MCP responses.
 */
function cowboy_mcp_woo_format_coupon( WC_Coupon $coupon ): array {
    return [
        'id'                         => $coupon->get_id(),
        'code'                       => $coupon->get_code(),
        'description'                => $coupon->get_description(),
        'discount_type'              => $coupon->get_discount_type(),
        'amount'                     => $coupon->get_amount(),
        'date_expires'               => $coupon->get_date_expires()?->date( 'c' ),
        'date_created'               => $coupon->get_date_created()?->date( 'c' ),
        'date_modified'              => $coupon->get_date_modified()?->date( 'c' ),
        'usage_count'                => $coupon->get_usage_count(),
        'usage_limit'                => $coupon->get_usage_limit(),
        'usage_limit_per_user'       => $coupon->get_usage_limit_per_user(),
        'limit_usage_to_x_items'     => $coupon->get_limit_usage_to_x_items(),
        'individual_use'             => $coupon->get_individual_use(),
        'free_shipping'              => $coupon->get_free_shipping(),
        'exclude_sale_items'         => $coupon->get_exclude_sale_items(),
        'minimum_amount'             => $coupon->get_minimum_amount(),
        'maximum_amount'             => $coupon->get_maximum_amount(),
        'product_ids'                => $coupon->get_product_ids(),
        'excluded_product_ids'       => $coupon->get_excluded_product_ids(),
        'product_categories'         => $coupon->get_product_categories(),
        'excluded_product_categories'=> $coupon->get_excluded_product_categories(),
        'email_restrictions'         => $coupon->get_email_restrictions(),
        'used_by'                    => $coupon->get_used_by(),
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
        Cowboy_MCP_Tools::tool( 'wp_woo_list_coupons', '[WooCommerce] List WooCommerce coupons with search and pagination.', [
            'search'   => [ 'type' => 'string',  'description' => 'Search by coupon code' ],
            'per_page' => [ 'type' => 'integer', 'description' => 'Results per page (max 100)', 'default' => 20 ],
            'page'     => [ 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ],
        ], [
            'title'           => 'List Coupons',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'coupons'     => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'                          => [ 'type' => 'integer' ],
                            'code'                        => [ 'type' => 'string' ],
                            'description'                 => [ 'type' => 'string' ],
                            'discount_type'               => [ 'type' => 'string' ],
                            'amount'                      => [ 'type' => 'string' ],
                            'date_expires'                => [ 'type' => 'string' ],
                            'date_created'                => [ 'type' => 'string' ],
                            'date_modified'               => [ 'type' => 'string' ],
                            'usage_count'                 => [ 'type' => 'integer' ],
                            'usage_limit'                 => [ 'type' => 'integer' ],
                            'usage_limit_per_user'        => [ 'type' => 'integer' ],
                            'limit_usage_to_x_items'      => [ 'type' => 'integer' ],
                            'individual_use'              => [ 'type' => 'boolean' ],
                            'free_shipping'               => [ 'type' => 'boolean' ],
                            'exclude_sale_items'          => [ 'type' => 'boolean' ],
                            'minimum_amount'              => [ 'type' => 'string' ],
                            'maximum_amount'              => [ 'type' => 'string' ],
                            'product_ids'                 => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                            'excluded_product_ids'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                            'product_categories'          => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                            'excluded_product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                            'email_restrictions'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'used_by'                     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        ],
                    ],
                ],
                'total'       => [ 'type' => 'integer' ],
                'total_pages' => [ 'type' => 'integer' ],
                'page'        => [ 'type' => 'integer' ],
                'per_page'    => [ 'type' => 'integer' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_get_coupon', '[WooCommerce] Get full details of a WooCommerce coupon by ID or code.', [
            'coupon' => [ 'type' => 'string', 'description' => 'Coupon ID (numeric) or coupon code', 'required' => true ],
        ], [
            'title'           => 'Get Coupon',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'id'                          => [ 'type' => 'integer' ],
                'code'                        => [ 'type' => 'string' ],
                'description'                 => [ 'type' => 'string' ],
                'discount_type'               => [ 'type' => 'string' ],
                'amount'                      => [ 'type' => 'string' ],
                'date_expires'                => [ 'type' => 'string' ],
                'date_created'                => [ 'type' => 'string' ],
                'date_modified'               => [ 'type' => 'string' ],
                'usage_count'                 => [ 'type' => 'integer' ],
                'usage_limit'                 => [ 'type' => 'integer' ],
                'usage_limit_per_user'        => [ 'type' => 'integer' ],
                'limit_usage_to_x_items'      => [ 'type' => 'integer' ],
                'individual_use'              => [ 'type' => 'boolean' ],
                'free_shipping'               => [ 'type' => 'boolean' ],
                'exclude_sale_items'          => [ 'type' => 'boolean' ],
                'minimum_amount'              => [ 'type' => 'string' ],
                'maximum_amount'              => [ 'type' => 'string' ],
                'product_ids'                 => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'excluded_product_ids'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'product_categories'          => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'excluded_product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'email_restrictions'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'used_by'                     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_create_coupon', '[WooCommerce] Create a new WooCommerce coupon with discount type, amount, and usage restrictions.', [
            'code'                        => [ 'type' => 'string',  'description' => 'Coupon code', 'required' => true ],
            'discount_type'               => [ 'type' => 'string',  'description' => 'Type: fixed_cart, percent, fixed_product', 'default' => 'fixed_cart' ],
            'amount'                      => [ 'type' => 'string',  'description' => 'Discount amount', 'required' => true ],
            'description'                 => [ 'type' => 'string',  'description' => 'Coupon description' ],
            'date_expires'                => [ 'type' => 'string',  'description' => 'Expiry date (YYYY-MM-DD)' ],
            'individual_use'              => [ 'type' => 'boolean', 'description' => 'Cannot be combined with other coupons' ],
            'usage_limit'                 => [ 'type' => 'integer', 'description' => 'Total usage limit' ],
            'usage_limit_per_user'        => [ 'type' => 'integer', 'description' => 'Per-customer usage limit' ],
            'limit_usage_to_x_items'      => [ 'type' => 'integer', 'description' => 'Max items coupon applies to' ],
            'free_shipping'               => [ 'type' => 'boolean', 'description' => 'Grant free shipping' ],
            'exclude_sale_items'          => [ 'type' => 'boolean', 'description' => 'Exclude sale items' ],
            'minimum_amount'              => [ 'type' => 'string',  'description' => 'Minimum cart total' ],
            'maximum_amount'              => [ 'type' => 'string',  'description' => 'Maximum cart total' ],
            'product_ids'                 => [ 'type' => 'array',   'description' => 'Allowed product IDs', 'items' => [ 'type' => 'integer' ] ],
            'excluded_product_ids'        => [ 'type' => 'array',   'description' => 'Excluded product IDs', 'items' => [ 'type' => 'integer' ] ],
            'product_categories'          => [ 'type' => 'array',   'description' => 'Allowed category IDs', 'items' => [ 'type' => 'integer' ] ],
            'excluded_product_categories' => [ 'type' => 'array',   'description' => 'Excluded category IDs', 'items' => [ 'type' => 'integer' ] ],
            'email_restrictions'          => [ 'type' => 'array',   'description' => 'Allowed customer emails', 'items' => [ 'type' => 'string' ] ],
        ], [
            'title'           => 'Create Coupon',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_update_coupon', '[WooCommerce] Update an existing WooCommerce coupon. Only supply fields you want to change.', [
            'coupon_id'                   => [ 'type' => 'integer', 'description' => 'Coupon ID', 'required' => true ],
            'code'                        => [ 'type' => 'string',  'description' => 'Coupon code' ],
            'discount_type'               => [ 'type' => 'string',  'description' => 'Discount type' ],
            'amount'                      => [ 'type' => 'string',  'description' => 'Discount amount' ],
            'description'                 => [ 'type' => 'string',  'description' => 'Coupon description' ],
            'date_expires'                => [ 'type' => 'string',  'description' => 'Expiry date (YYYY-MM-DD)' ],
            'individual_use'              => [ 'type' => 'boolean', 'description' => 'Cannot be combined with other coupons' ],
            'usage_limit'                 => [ 'type' => 'integer', 'description' => 'Total usage limit' ],
            'usage_limit_per_user'        => [ 'type' => 'integer', 'description' => 'Per-customer usage limit' ],
            'limit_usage_to_x_items'      => [ 'type' => 'integer', 'description' => 'Max items coupon applies to' ],
            'free_shipping'               => [ 'type' => 'boolean', 'description' => 'Grant free shipping' ],
            'exclude_sale_items'          => [ 'type' => 'boolean', 'description' => 'Exclude sale items' ],
            'minimum_amount'              => [ 'type' => 'string',  'description' => 'Minimum cart total' ],
            'maximum_amount'              => [ 'type' => 'string',  'description' => 'Maximum cart total' ],
            'product_ids'                 => [ 'type' => 'array',   'description' => 'Allowed product IDs', 'items' => [ 'type' => 'integer' ] ],
            'excluded_product_ids'        => [ 'type' => 'array',   'description' => 'Excluded product IDs', 'items' => [ 'type' => 'integer' ] ],
            'product_categories'          => [ 'type' => 'array',   'description' => 'Allowed category IDs', 'items' => [ 'type' => 'integer' ] ],
            'excluded_product_categories' => [ 'type' => 'array',   'description' => 'Excluded category IDs', 'items' => [ 'type' => 'integer' ] ],
            'email_restrictions'          => [ 'type' => 'array',   'description' => 'Allowed customer emails', 'items' => [ 'type' => 'string' ] ],
        ], [
            'title'           => 'Update Coupon',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_delete_coupon', '[WooCommerce] Delete or trash a WooCommerce coupon.', [
            'coupon_id' => [ 'type' => 'integer', 'description' => 'Coupon ID', 'required' => true ],
            'force'     => [ 'type' => 'boolean', 'description' => 'Permanently delete (skip trash)', 'default' => false ],
        ], [
            'title'           => 'Delete Coupon',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),
    ],

    'handlers' => [
        'wp_woo_list_coupons' => function ( array $a ): array {
            $per_page = min( (int) ( $a['per_page'] ?? 20 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );

            $query_args = [
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ];

            if ( ! empty( $a['search'] ) ) {
                $query_args['s'] = $a['search'];
            }

            $query   = new WP_Query( $query_args );
            $coupons = [];

            foreach ( $query->posts as $post ) {
                try {
                    $coupon    = new WC_Coupon( $post->ID );
                    $coupons[] = cowboy_mcp_woo_format_coupon( $coupon );
                } catch ( \Exception $e ) {
                    continue;
                }
            }

            return [
                'coupons'     => $coupons,
                'total'       => $query->found_posts,
                'total_pages' => $query->max_num_pages,
                'page'        => $page,
                'per_page'    => $per_page,
            ];
        },

        'wp_woo_get_coupon' => function ( array $a ) {
            $input = $a['coupon'] ?? '';

            if ( empty( $input ) ) {
                return new WP_Error( 'missing_param', 'coupon (ID or code) is required.' );
            }

            try {
                $coupon = new WC_Coupon( ctype_digit( (string) $input ) ? (int) $input : $input );
            } catch ( \Exception $e ) {
                return new WP_Error( 'not_found', "Coupon not found: {$input}" );
            }

            if ( ! $coupon->get_id() ) {
                return new WP_Error( 'not_found', "Coupon not found: {$input}" );
            }

            return cowboy_mcp_woo_format_coupon( $coupon );
        },

        'wp_woo_create_coupon' => function ( array $a ) {
            $code = sanitize_text_field( $a['code'] ?? '' );

            if ( empty( $code ) ) {
                return new WP_Error( 'missing_param', 'code is required.' );
            }

            $coupon = new WC_Coupon();
            $coupon->set_code( $code );
            $coupon->set_discount_type( $a['discount_type'] ?? 'fixed_cart' );
            $coupon->set_amount( $a['amount'] ?? '0' );
            cowboy_mcp_woo_set_coupon_fields( $coupon, $a );

            $id = $coupon->save();

            if ( ! $id ) {
                return new WP_Error( 'create_failed', 'Failed to create coupon.' );
            }

            return [
                'created'   => true,
                'coupon_id' => $id,
                'coupon'    => cowboy_mcp_woo_format_coupon( new WC_Coupon( $id ) ),
            ];
        },

        'wp_woo_update_coupon' => function ( array $a ) {
            $id = (int) ( $a['coupon_id'] ?? 0 );

            try {
                $coupon = new WC_Coupon( $id );
            } catch ( \Exception $e ) {
                return new WP_Error( 'not_found', "Coupon not found: {$id}" );
            }

            if ( ! $coupon->get_id() ) {
                return new WP_Error( 'not_found', "Coupon not found: {$id}" );
            }

            if ( isset( $a['code'] ) )          $coupon->set_code( sanitize_text_field( $a['code'] ) );
            if ( isset( $a['discount_type'] ) ) $coupon->set_discount_type( $a['discount_type'] );
            if ( isset( $a['amount'] ) )        $coupon->set_amount( $a['amount'] );
            cowboy_mcp_woo_set_coupon_fields( $coupon, $a );

            $coupon->save();

            return [
                'updated'   => true,
                'coupon_id' => $id,
                'coupon'    => cowboy_mcp_woo_format_coupon( new WC_Coupon( $id ) ),
            ];
        },

        'wp_woo_delete_coupon' => function ( array $a ) {
            $id    = (int) ( $a['coupon_id'] ?? 0 );
            $force = (bool) ( $a['force'] ?? false );

            try {
                $coupon = new WC_Coupon( $id );
            } catch ( \Exception $e ) {
                return new WP_Error( 'not_found', "Coupon not found: {$id}" );
            }

            if ( ! $coupon->get_id() ) {
                return new WP_Error( 'not_found', "Coupon not found: {$id}" );
            }

            $result = $coupon->delete( $force );

            if ( ! $result ) {
                return new WP_Error( 'delete_failed', "Failed to delete coupon: {$id}" );
            }

            return [
                'deleted'   => true,
                'coupon_id' => $id,
                'force'     => $force,
            ];
        },
    ],
];
