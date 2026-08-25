<?php
defined( 'ABSPATH' ) || exit;

/**
 * Apply common product fields from args onto a WC_Product object.
 * Shared between create and update handlers.
 */
function cowboy_mcp_woo_set_product_fields( WC_Product $product, array $a ): void {
    if ( isset( $a['name'] ) )              $product->set_name( $a['name'] );
    if ( isset( $a['status'] ) )            $product->set_status( $a['status'] );
    if ( isset( $a['regular_price'] ) ) {
        $price = filter_var( $a['regular_price'], FILTER_VALIDATE_FLOAT );
        $product->set_regular_price( $price !== false ? (string) $price : '' );
    }
    if ( isset( $a['sale_price'] ) ) {
        $price = filter_var( $a['sale_price'], FILTER_VALIDATE_FLOAT );
        $product->set_sale_price( $price !== false ? (string) $price : '' );
    }
    if ( isset( $a['sku'] ) )               $product->set_sku( $a['sku'] );
    if ( isset( $a['description'] ) )       $product->set_description( $a['description'] );
    if ( isset( $a['short_description'] ) ) $product->set_short_description( $a['short_description'] );
    if ( isset( $a['manage_stock'] ) )      $product->set_manage_stock( $a['manage_stock'] );
    if ( isset( $a['stock_quantity'] ) )    $product->set_stock_quantity( $a['stock_quantity'] );
    if ( isset( $a['weight'] ) )            $product->set_weight( $a['weight'] );
    if ( isset( $a['length'] ) )            $product->set_length( $a['length'] );
    if ( isset( $a['width'] ) )             $product->set_width( $a['width'] );
    if ( isset( $a['height'] ) )            $product->set_height( $a['height'] );
    if ( isset( $a['virtual'] ) )           $product->set_virtual( $a['virtual'] );
    if ( isset( $a['downloadable'] ) )      $product->set_downloadable( $a['downloadable'] );
    if ( isset( $a['tax_status'] ) )        $product->set_tax_status( $a['tax_status'] );
    if ( isset( $a['tax_class'] ) )         $product->set_tax_class( $a['tax_class'] );
    if ( isset( $a['image_id'] ) )          $product->set_image_id( $a['image_id'] );

    if ( isset( $a['categories'] ) ) {
        $product->set_category_ids( array_map( 'intval', $a['categories'] ) );
    }
    if ( isset( $a['tags'] ) ) {
        $product->set_tag_ids( array_map( 'intval', $a['tags'] ) );
    }
}

/**
 * Format a WC_Product into a standardized array for MCP responses.
 */
function cowboy_mcp_woo_format_product( WC_Product $product ): array {
    $data = [
        'id'                => $product->get_id(),
        'name'              => $product->get_name(),
        'slug'              => $product->get_slug(),
        'type'              => $product->get_type(),
        'status'            => $product->get_status(),
        'sku'               => $product->get_sku(),
        'price'             => $product->get_price(),
        'regular_price'     => $product->get_regular_price(),
        'sale_price'        => $product->get_sale_price(),
        'description'       => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'categories'        => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
        'tags'              => wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] ),
        'manage_stock'      => $product->get_manage_stock(),
        'stock_quantity'    => $product->get_stock_quantity(),
        'stock_status'      => $product->get_stock_status(),
        'weight'            => $product->get_weight(),
        'dimensions'        => [
            'length' => $product->get_length(),
            'width'  => $product->get_width(),
            'height' => $product->get_height(),
        ],
        'virtual'           => $product->get_virtual(),
        'downloadable'      => $product->get_downloadable(),
        'tax_status'        => $product->get_tax_status(),
        'tax_class'         => $product->get_tax_class(),
        'date_created'      => $product->get_date_created()?->date( 'c' ),
        'date_modified'     => $product->get_date_modified()?->date( 'c' ),
        'featured_image'    => wp_get_attachment_url( $product->get_image_id() ) ?: null,
    ];

    if ( $product->is_type( 'variable' ) && $product instanceof WC_Product_Variable ) {
        $data['variations_count'] = count( $product->get_children() );
        $data['attributes']       = array_map( fn( $attr ) => [
            'name'      => $attr->get_name(),
            'options'   => $attr->get_options(),
            'variation' => $attr->get_variation(),
            'visible'   => $attr->get_visible(),
        ], $product->get_attributes() );
    }

    if ( $product->is_type( 'variation' ) ) {
        $data['parent_id']  = $product->get_parent_id();
        $data['attributes'] = $product->get_attributes();
    }

    if ( $product->is_type( 'grouped' ) && $product instanceof WC_Product_Grouped ) {
        $data['children'] = $product->get_children();
    }

    if ( $product->is_type( 'external' ) && $product instanceof WC_Product_External ) {
        $data['product_url'] = $product->get_product_url();
        $data['button_text'] = $product->get_button_text();
    }

    return $data;
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
        Cowboy_MCP_Tools::tool( 'wp_woo_list_products', '[WooCommerce] List WooCommerce products with filtering by status, type, category, tag, SKU, or search term. Supports pagination.', [
            'status'   => [ 'type' => 'string',  'description' => 'Product status: publish, draft, pending, private, trash, any', 'default' => 'any' ],
            'type'     => [ 'type' => 'string',  'description' => 'Product type: simple, variable, grouped, external', ],
            'category' => [ 'type' => 'string',  'description' => 'Category slug to filter by' ],
            'tag'      => [ 'type' => 'string',  'description' => 'Tag slug to filter by' ],
            'sku'      => [ 'type' => 'string',  'description' => 'Exact SKU match' ],
            'search'   => [ 'type' => 'string',  'description' => 'Search keyword' ],
            'per_page' => [ 'type' => 'integer', 'description' => 'Results per page (max 100)', 'default' => 20 ],
            'page'     => [ 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ],
            'orderby'  => [ 'type' => 'string',  'description' => 'Order by: date, title, price, popularity, rating, id', 'default' => 'date' ],
            'order'    => [ 'type' => 'string',  'description' => 'ASC or DESC', 'default' => 'DESC' ],
        ], [
            'title'           => 'List Products',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'products'    => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'                => [ 'type' => 'integer' ],
                            'name'              => [ 'type' => 'string' ],
                            'slug'              => [ 'type' => 'string' ],
                            'type'              => [ 'type' => 'string' ],
                            'status'            => [ 'type' => 'string' ],
                            'sku'               => [ 'type' => 'string' ],
                            'price'             => [ 'type' => 'string' ],
                            'regular_price'     => [ 'type' => 'string' ],
                            'sale_price'        => [ 'type' => 'string' ],
                            'description'       => [ 'type' => 'string' ],
                            'short_description' => [ 'type' => 'string' ],
                            'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'manage_stock'      => [ 'type' => 'boolean' ],
                            'stock_quantity'    => [ 'type' => 'integer' ],
                            'stock_status'      => [ 'type' => 'string' ],
                            'weight'            => [ 'type' => 'string' ],
                            'dimensions'        => [ 'type' => 'object' ],
                            'virtual'           => [ 'type' => 'boolean' ],
                            'downloadable'      => [ 'type' => 'boolean' ],
                            'tax_status'        => [ 'type' => 'string' ],
                            'tax_class'         => [ 'type' => 'string' ],
                            'date_created'      => [ 'type' => 'string' ],
                            'date_modified'     => [ 'type' => 'string' ],
                            'featured_image'    => [ 'type' => 'string' ],
                        ],
                    ],
                ],
                'total'       => [ 'type' => 'integer' ],
                'total_pages' => [ 'type' => 'integer' ],
                'page'        => [ 'type' => 'integer' ],
                'per_page'    => [ 'type' => 'integer' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_get_product', '[WooCommerce] Get full details of a WooCommerce product by ID including type-specific fields.', [
            'product_id' => [ 'type' => 'integer', 'description' => 'Product ID', 'required' => true ],
        ], [
            'title'           => 'Get Product',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'id'                => [ 'type' => 'integer' ],
                'name'              => [ 'type' => 'string' ],
                'slug'              => [ 'type' => 'string' ],
                'type'              => [ 'type' => 'string' ],
                'status'            => [ 'type' => 'string' ],
                'sku'               => [ 'type' => 'string' ],
                'price'             => [ 'type' => 'string' ],
                'regular_price'     => [ 'type' => 'string' ],
                'sale_price'        => [ 'type' => 'string' ],
                'description'       => [ 'type' => 'string' ],
                'short_description' => [ 'type' => 'string' ],
                'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'manage_stock'      => [ 'type' => 'boolean' ],
                'stock_quantity'    => [ 'type' => 'integer' ],
                'stock_status'      => [ 'type' => 'string' ],
                'weight'            => [ 'type' => 'string' ],
                'dimensions'        => [ 'type' => 'object' ],
                'virtual'           => [ 'type' => 'boolean' ],
                'downloadable'      => [ 'type' => 'boolean' ],
                'tax_status'        => [ 'type' => 'string' ],
                'tax_class'         => [ 'type' => 'string' ],
                'date_created'      => [ 'type' => 'string' ],
                'date_modified'     => [ 'type' => 'string' ],
                'featured_image'    => [ 'type' => 'string' ],
            ],
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_create_product', '[WooCommerce] Create a new WooCommerce product (simple, variable, grouped, or external).', [
            'name'              => [ 'type' => 'string',  'description' => 'Product name', 'required' => true ],
            'type'              => [ 'type' => 'string',  'description' => 'Product type: simple, variable, grouped, external', 'default' => 'simple' ],
            'status'            => [ 'type' => 'string',  'description' => 'Product status', 'default' => 'publish' ],
            'regular_price'     => [ 'type' => 'string',  'description' => 'Regular price' ],
            'sale_price'        => [ 'type' => 'string',  'description' => 'Sale price' ],
            'sku'               => [ 'type' => 'string',  'description' => 'SKU' ],
            'description'       => [ 'type' => 'string',  'description' => 'Full description (HTML)' ],
            'short_description' => [ 'type' => 'string',  'description' => 'Short description (HTML)' ],
            'categories'        => [ 'type' => 'array',   'description' => 'Category IDs', 'items' => [ 'type' => 'integer' ] ],
            'tags'              => [ 'type' => 'array',   'description' => 'Tag IDs', 'items' => [ 'type' => 'integer' ] ],
            'manage_stock'      => [ 'type' => 'boolean', 'description' => 'Enable stock management' ],
            'stock_quantity'    => [ 'type' => 'integer', 'description' => 'Stock quantity' ],
            'weight'            => [ 'type' => 'string',  'description' => 'Weight' ],
            'length'            => [ 'type' => 'string',  'description' => 'Length' ],
            'width'             => [ 'type' => 'string',  'description' => 'Width' ],
            'height'            => [ 'type' => 'string',  'description' => 'Height' ],
            'virtual'           => [ 'type' => 'boolean', 'description' => 'Virtual product' ],
            'downloadable'      => [ 'type' => 'boolean', 'description' => 'Downloadable product' ],
            'tax_status'        => [ 'type' => 'string',  'description' => 'Tax status: taxable, shipping, none' ],
            'tax_class'         => [ 'type' => 'string',  'description' => 'Tax class' ],
            'product_url'       => [ 'type' => 'string',  'description' => 'External product URL (external type only)' ],
            'button_text'       => [ 'type' => 'string',  'description' => 'Buy button text (external type only)' ],
            'children'          => [ 'type' => 'array',   'description' => 'Child product IDs (grouped type only)', 'items' => [ 'type' => 'integer' ] ],
            'attributes'        => [ 'type' => 'array',   'description' => 'Product attributes array: [{name, options[], visible, variation}]', 'items' => [ 'type' => 'object' ] ],
            'image_id'          => [ 'type' => 'integer', 'description' => 'Featured image attachment ID' ],
            'meta'              => [ 'type' => 'object',  'description' => 'Key-value meta data to set' ],
        ], [
            'title'           => 'Create Product',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_update_product', '[WooCommerce] Update an existing WooCommerce product. Only supply fields you want to change.', [
            'product_id'        => [ 'type' => 'integer', 'description' => 'Product ID', 'required' => true ],
            'name'              => [ 'type' => 'string',  'description' => 'Product name' ],
            'status'            => [ 'type' => 'string',  'description' => 'Product status' ],
            'regular_price'     => [ 'type' => 'string',  'description' => 'Regular price' ],
            'sale_price'        => [ 'type' => 'string',  'description' => 'Sale price' ],
            'sku'               => [ 'type' => 'string',  'description' => 'SKU' ],
            'description'       => [ 'type' => 'string',  'description' => 'Full description' ],
            'short_description' => [ 'type' => 'string',  'description' => 'Short description' ],
            'categories'        => [ 'type' => 'array',   'description' => 'Category IDs', 'items' => [ 'type' => 'integer' ] ],
            'tags'              => [ 'type' => 'array',   'description' => 'Tag IDs', 'items' => [ 'type' => 'integer' ] ],
            'manage_stock'      => [ 'type' => 'boolean', 'description' => 'Enable stock management' ],
            'stock_quantity'    => [ 'type' => 'integer', 'description' => 'Stock quantity' ],
            'weight'            => [ 'type' => 'string',  'description' => 'Weight' ],
            'length'            => [ 'type' => 'string',  'description' => 'Length' ],
            'width'             => [ 'type' => 'string',  'description' => 'Width' ],
            'height'            => [ 'type' => 'string',  'description' => 'Height' ],
            'virtual'           => [ 'type' => 'boolean', 'description' => 'Virtual product' ],
            'downloadable'      => [ 'type' => 'boolean', 'description' => 'Downloadable product' ],
            'tax_status'        => [ 'type' => 'string',  'description' => 'Tax status' ],
            'tax_class'         => [ 'type' => 'string',  'description' => 'Tax class' ],
            'image_id'          => [ 'type' => 'integer', 'description' => 'Featured image attachment ID' ],
            'meta'              => [ 'type' => 'object',  'description' => 'Key-value meta data to set' ],
        ], [
            'title'           => 'Update Product',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_delete_product', '[WooCommerce] Delete or trash a WooCommerce product.', [
            'product_id' => [ 'type' => 'integer', 'description' => 'Product ID', 'required' => true ],
            'force'      => [ 'type' => 'boolean', 'description' => 'Permanently delete (skip trash)', 'default' => false ],
        ], [
            'title'           => 'Delete Product',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_list_variations', '[WooCommerce] List all variations of a variable product.', [
            'product_id' => [ 'type' => 'integer', 'description' => 'Parent variable product ID', 'required' => true ],
        ], [
            'title'           => 'List Variations',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_create_variation', '[WooCommerce] Create a variation for a variable product with attributes, price, and stock.', [
            'product_id'     => [ 'type' => 'integer', 'description' => 'Parent variable product ID', 'required' => true ],
            'attributes'     => [ 'type' => 'object',  'description' => 'Attribute name-value pairs (e.g. {"Color": "Red", "Size": "Large"})', 'required' => true ],
            'regular_price'  => [ 'type' => 'string',  'description' => 'Regular price' ],
            'sale_price'     => [ 'type' => 'string',  'description' => 'Sale price' ],
            'sku'            => [ 'type' => 'string',  'description' => 'SKU' ],
            'manage_stock'   => [ 'type' => 'boolean', 'description' => 'Enable stock management' ],
            'stock_quantity' => [ 'type' => 'integer', 'description' => 'Stock quantity' ],
            'status'         => [ 'type' => 'string',  'description' => 'Status: publish, private', 'default' => 'publish' ],
            'weight'         => [ 'type' => 'string',  'description' => 'Weight' ],
            'length'         => [ 'type' => 'string',  'description' => 'Length' ],
            'width'          => [ 'type' => 'string',  'description' => 'Width' ],
            'height'         => [ 'type' => 'string',  'description' => 'Height' ],
            'image_id'       => [ 'type' => 'integer', 'description' => 'Image attachment ID' ],
        ], [
            'title'           => 'Create Variation',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_update_variation', '[WooCommerce] Update a product variation. Only supply fields you want to change.', [
            'variation_id'   => [ 'type' => 'integer', 'description' => 'Variation ID', 'required' => true ],
            'attributes'     => [ 'type' => 'object',  'description' => 'Attribute name-value pairs' ],
            'regular_price'  => [ 'type' => 'string',  'description' => 'Regular price' ],
            'sale_price'     => [ 'type' => 'string',  'description' => 'Sale price' ],
            'sku'            => [ 'type' => 'string',  'description' => 'SKU' ],
            'manage_stock'   => [ 'type' => 'boolean', 'description' => 'Enable stock management' ],
            'stock_quantity' => [ 'type' => 'integer', 'description' => 'Stock quantity' ],
            'status'         => [ 'type' => 'string',  'description' => 'Status' ],
            'weight'         => [ 'type' => 'string',  'description' => 'Weight' ],
            'length'         => [ 'type' => 'string',  'description' => 'Length' ],
            'width'          => [ 'type' => 'string',  'description' => 'Width' ],
            'height'         => [ 'type' => 'string',  'description' => 'Height' ],
            'image_id'       => [ 'type' => 'integer', 'description' => 'Image attachment ID' ],
        ], [
            'title'           => 'Update Variation',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_delete_variation', '[WooCommerce] Delete a product variation.', [
            'variation_id' => [ 'type' => 'integer', 'description' => 'Variation ID', 'required' => true ],
            'force'        => [ 'type' => 'boolean', 'description' => 'Permanently delete', 'default' => true ],
        ], [
            'title'           => 'Delete Variation',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_woo_manage_stock', '[WooCommerce] Bulk stock update: set, increase, or decrease stock for one or more products.', [
            'updates' => [ 'type' => 'array', 'description' => 'Array of stock updates: [{product_id, quantity, operation}]', 'required' => true, 'items' => [ 'type' => 'object' ] ],
        ], [
            'title'           => 'Manage Stock',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ]),
    ],

    'handlers' => [
        'wp_woo_list_products' => function ( array $a ): array {
            $per_page = min( (int) ( $a['per_page'] ?? 20 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );

            $args = [
                'status'   => $a['status'] ?? 'any',
                'limit'    => $per_page,
                'page'     => $page,
                'orderby'  => $a['orderby'] ?? 'date',
                'order'    => $a['order'] ?? 'DESC',
                'paginate' => true,
            ];

            if ( ! empty( $a['type'] ) )     $args['type']     = $a['type'];
            if ( ! empty( $a['category'] ) ) $args['category']  = [ $a['category'] ];
            if ( ! empty( $a['tag'] ) )      $args['tag']       = [ $a['tag'] ];
            if ( ! empty( $a['sku'] ) )      $args['sku']       = $a['sku'];
            if ( ! empty( $a['search'] ) )   $args['s']         = $a['search'];

            $results = wc_get_products( $args );

            return [
                'products'    => array_map( 'cowboy_mcp_woo_format_product', $results->products ),
                'total'       => $results->total,
                'total_pages' => $results->max_num_pages,
                'page'        => $page,
                'per_page'    => $per_page,
            ];
        },

        'wp_woo_get_product' => function ( array $a ) {
            $id      = (int) ( $a['product_id'] ?? 0 );
            $product = wc_get_product( $id );

            if ( ! $product ) {
                return new WP_Error( 'not_found', "Product not found: {$id}" );
            }

            return cowboy_mcp_woo_format_product( $product );
        },

        'wp_woo_create_product' => function ( array $a ) {
            $type = $a['type'] ?? 'simple';

            $product = match ( $type ) {
                'variable'  => new WC_Product_Variable(),
                'grouped'   => new WC_Product_Grouped(),
                'external'  => new WC_Product_External(),
                default     => new WC_Product_Simple(),
            };

            cowboy_mcp_woo_set_product_fields( $product, $a );

            // Type-specific fields.
            if ( $product instanceof WC_Product_External ) {
                if ( isset( $a['product_url'] ) )  $product->set_product_url( $a['product_url'] );
                if ( isset( $a['button_text'] ) )  $product->set_button_text( $a['button_text'] );
            }
            if ( $product instanceof WC_Product_Grouped && ! empty( $a['children'] ) ) {
                $product->set_children( array_map( 'intval', $a['children'] ) );
            }

            // Attributes for variable products.
            if ( ! empty( $a['attributes'] ) && is_array( $a['attributes'] ) ) {
                $attrs = [];
                $pos   = 0;
                foreach ( $a['attributes'] as $attr_data ) {
                    $attr = new WC_Product_Attribute();
                    $attr->set_name( $attr_data['name'] ?? '' );
                    $attr->set_options( $attr_data['options'] ?? [] );
                    $attr->set_visible( $attr_data['visible'] ?? true );
                    $attr->set_variation( $attr_data['variation'] ?? false );
                    $attr->set_position( $pos++ );
                    $attrs[] = $attr;
                }
                $product->set_attributes( $attrs );
            }

            $id = $product->save();

            if ( ! $id ) {
                return new WP_Error( 'create_failed', 'Failed to create product.' );
            }

            // Set meta data.
            if ( ! empty( $a['meta'] ) && is_array( $a['meta'] ) ) {
                foreach ( $a['meta'] as $key => $value ) {
                    update_post_meta( $id, sanitize_key( $key ), $value );
                }
            }

            return [
                'created'    => true,
                'product_id' => $id,
                'product'    => cowboy_mcp_woo_format_product( wc_get_product( $id ) ),
            ];
        },

        'wp_woo_update_product' => function ( array $a ) {
            $id      = (int) ( $a['product_id'] ?? 0 );
            $product = wc_get_product( $id );

            if ( ! $product ) {
                return new WP_Error( 'not_found', "Product not found: {$id}" );
            }

            cowboy_mcp_woo_set_product_fields( $product, $a );

            $product->save();

            // Set meta data.
            if ( ! empty( $a['meta'] ) && is_array( $a['meta'] ) ) {
                foreach ( $a['meta'] as $key => $value ) {
                    update_post_meta( $id, sanitize_key( $key ), $value );
                }
            }

            return [
                'updated'    => true,
                'product_id' => $id,
                'product'    => cowboy_mcp_woo_format_product( wc_get_product( $id ) ),
            ];
        },

        'wp_woo_delete_product' => function ( array $a ) {
            $id      = (int) ( $a['product_id'] ?? 0 );
            $force   = (bool) ( $a['force'] ?? false );
            $product = wc_get_product( $id );

            if ( ! $product ) {
                return new WP_Error( 'not_found', "Product not found: {$id}" );
            }

            $result = $product->delete( $force );

            if ( ! $result ) {
                return new WP_Error( 'delete_failed', "Failed to delete product: {$id}" );
            }

            return [
                'deleted'    => true,
                'product_id' => $id,
                'force'      => $force,
            ];
        },

        'wp_woo_list_variations' => function ( array $a ) {
            $id      = (int) ( $a['product_id'] ?? 0 );
            $product = wc_get_product( $id );

            if ( ! $product || ! $product->is_type( 'variable' ) ) {
                return new WP_Error( 'invalid_product', "Product {$id} is not a variable product." );
            }

            $children   = $product->get_children();
            $variations = [];

            foreach ( $children as $child_id ) {
                $variation = wc_get_product( $child_id );
                if ( $variation ) {
                    $variations[] = cowboy_mcp_woo_format_product( $variation );
                }
            }

            return [
                'product_id' => $id,
                'count'      => count( $variations ),
                'variations' => $variations,
            ];
        },

        'wp_woo_create_variation' => function ( array $a ) {
            $parent_id = (int) ( $a['product_id'] ?? 0 );
            $parent    = wc_get_product( $parent_id );

            if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
                return new WP_Error( 'invalid_product', "Product {$parent_id} is not a variable product." );
            }

            $variation = new WC_Product_Variation();
            $variation->set_parent_id( $parent_id );

            if ( isset( $a['status'] ) )         $variation->set_status( $a['status'] );
            if ( isset( $a['regular_price'] ) )  $variation->set_regular_price( $a['regular_price'] );
            if ( isset( $a['sale_price'] ) )     $variation->set_sale_price( $a['sale_price'] );
            if ( isset( $a['sku'] ) )            $variation->set_sku( $a['sku'] );
            if ( isset( $a['manage_stock'] ) )   $variation->set_manage_stock( $a['manage_stock'] );
            if ( isset( $a['stock_quantity'] ) ) $variation->set_stock_quantity( $a['stock_quantity'] );
            if ( isset( $a['weight'] ) )         $variation->set_weight( $a['weight'] );
            if ( isset( $a['length'] ) )         $variation->set_length( $a['length'] );
            if ( isset( $a['width'] ) )          $variation->set_width( $a['width'] );
            if ( isset( $a['height'] ) )         $variation->set_height( $a['height'] );
            if ( isset( $a['image_id'] ) )       $variation->set_image_id( $a['image_id'] );

            if ( ! empty( $a['attributes'] ) && is_array( $a['attributes'] ) ) {
                $attrs = [];
                foreach ( $a['attributes'] as $name => $value ) {
                    $attrs[ sanitize_title( $name ) ] = $value;
                }
                $variation->set_attributes( $attrs );
            }

            $id = $variation->save();

            if ( ! $id ) {
                return new WP_Error( 'create_failed', 'Failed to create variation.' );
            }

            return [
                'created'      => true,
                'variation_id' => $id,
                'parent_id'    => $parent_id,
                'variation'    => cowboy_mcp_woo_format_product( wc_get_product( $id ) ),
            ];
        },

        'wp_woo_update_variation' => function ( array $a ) {
            $id        = (int) ( $a['variation_id'] ?? 0 );
            $variation = wc_get_product( $id );

            if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
                return new WP_Error( 'not_found', "Variation not found: {$id}" );
            }

            if ( isset( $a['regular_price'] ) )  $variation->set_regular_price( $a['regular_price'] );
            if ( isset( $a['sale_price'] ) )     $variation->set_sale_price( $a['sale_price'] );
            if ( isset( $a['sku'] ) )            $variation->set_sku( $a['sku'] );
            if ( isset( $a['manage_stock'] ) )   $variation->set_manage_stock( $a['manage_stock'] );
            if ( isset( $a['stock_quantity'] ) ) $variation->set_stock_quantity( $a['stock_quantity'] );
            if ( isset( $a['status'] ) )         $variation->set_status( $a['status'] );
            if ( isset( $a['weight'] ) )         $variation->set_weight( $a['weight'] );
            if ( isset( $a['length'] ) )         $variation->set_length( $a['length'] );
            if ( isset( $a['width'] ) )          $variation->set_width( $a['width'] );
            if ( isset( $a['height'] ) )         $variation->set_height( $a['height'] );
            if ( isset( $a['image_id'] ) )       $variation->set_image_id( $a['image_id'] );

            if ( isset( $a['attributes'] ) && is_array( $a['attributes'] ) ) {
                $attrs = [];
                foreach ( $a['attributes'] as $name => $value ) {
                    $attrs[ sanitize_title( $name ) ] = $value;
                }
                $variation->set_attributes( $attrs );
            }

            $variation->save();

            return [
                'updated'      => true,
                'variation_id' => $id,
                'variation'    => cowboy_mcp_woo_format_product( wc_get_product( $id ) ),
            ];
        },

        'wp_woo_delete_variation' => function ( array $a ) {
            $id        = (int) ( $a['variation_id'] ?? 0 );
            $force     = (bool) ( $a['force'] ?? true );
            $variation = wc_get_product( $id );

            if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
                return new WP_Error( 'not_found', "Variation not found: {$id}" );
            }

            $result = $variation->delete( $force );

            if ( ! $result ) {
                return new WP_Error( 'delete_failed', "Failed to delete variation: {$id}" );
            }

            return [
                'deleted'      => true,
                'variation_id' => $id,
                'force'        => $force,
            ];
        },

        'wp_woo_manage_stock' => function ( array $a ) {
            $updates = $a['updates'] ?? [];

            if ( empty( $updates ) || ! is_array( $updates ) ) {
                return new WP_Error( 'missing_param', 'updates array is required.' );
            }

            $results = [];

            foreach ( $updates as $update ) {
                $product_id = (int) ( $update['product_id'] ?? 0 );
                $quantity   = (int) ( $update['quantity'] ?? 0 );
                $operation  = $update['operation'] ?? 'set';

                $product = wc_get_product( $product_id );

                if ( ! $product ) {
                    $results[] = [ 'product_id' => $product_id, 'error' => 'Product not found' ];
                    continue;
                }

                $new_stock = match ( $operation ) {
                    'increase' => wc_update_product_stock( $product, $quantity, 'increase' ),
                    'decrease' => wc_update_product_stock( $product, $quantity, 'decrease' ),
                    default    => wc_update_product_stock( $product, $quantity, 'set' ),
                };

                $results[] = [
                    'product_id' => $product_id,
                    'operation'  => $operation,
                    'quantity'   => $quantity,
                    'new_stock'  => $new_stock,
                ];
            }

            return [ 'results' => $results ];
        },
    ],
];
