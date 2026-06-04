<?php
/**
 * Cowboy MCP – Resources
 *
 * Exposes WordPress data as MCP resources that Claude can browse.
 * Resources are read-only data sources: site config, theme files, etc.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Resources {

    private const TEMPLATES = [
        [
            'uriTemplate'  => 'wordpress://posts/{id}',
            'name'         => 'Single Post',
            'description'  => 'Get a WordPress post by ID.',
            'mimeType'     => 'application/json',
        ],
        [
            'uriTemplate'  => 'wordpress://options/{name}',
            'name'         => 'Single Option',
            'description'  => 'Get a WordPress option by name.',
            'mimeType'     => 'application/json',
        ],
        [
            'uriTemplate'  => 'wordpress://plugins/{slug}',
            'name'         => 'Single Plugin',
            'description'  => 'Get plugin details by slug (folder/file.php format, use -- instead of /).',
            'mimeType'     => 'application/json',
        ],
        [
            'uriTemplate'  => 'wordpress://users/{id}',
            'name'         => 'Single User',
            'description'  => 'Get a WordPress user by ID.',
            'mimeType'     => 'application/json',
        ],
    ];

    /**
     * Handle resources/list.
     */
    public static function list_resources( array $params ): array {
        $resources = [
            [
                'uri'         => 'wordpress://site/info',
                'name'        => 'Site Information',
                'description' => 'WordPress installation details, active theme/plugins, PHP version, etc.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'wordpress://site/options',
                'name'        => 'Core Options',
                'description' => 'Key WordPress options (blogname, blogdescription, permalink_structure, etc.)',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'wordpress://theme/current',
                'name'        => 'Current Theme Files',
                'description' => 'Directory listing of the active theme.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'wordpress://theme/functions',
                'name'        => 'Theme functions.php',
                'description' => 'Contents of the active theme\'s functions.php file.',
                'mimeType'    => 'text/x-php',
            ],
            [
                'uri'         => 'wordpress://theme/style',
                'name'        => 'Theme style.css',
                'description' => 'Contents of the active theme\'s style.css file.',
                'mimeType'    => 'text/css',
            ],
            [
                'uri'         => 'wordpress://database/schema',
                'name'        => 'Database Schema',
                'description' => 'All database tables with column definitions.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'wordpress://content/post-types',
                'name'        => 'Registered Post Types',
                'description' => 'All registered post types with their config.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'wordpress://content/taxonomies',
                'name'        => 'Registered Taxonomies',
                'description' => 'All registered taxonomies.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'wordpress://site/htaccess',
                'name'        => '.htaccess',
                'description' => 'Root .htaccess file (Apache sites only).',
                'mimeType'    => 'text/plain',
            ],
            [
                'uri'         => 'wordpress://site/wp-config-summary',
                'name'        => 'wp-config summary',
                'description' => 'Non-sensitive constants defined in wp-config.php.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'wordpress://tools/catalog',
                'name'        => 'Tool Catalog',
                'description' => 'Complete catalog of all available MCP tools organized by category with usage examples. Read this to discover what actions you can perform.',
                'mimeType'    => 'application/json',
            ],
        ];

        // Conditionally add WooCommerce resources.
        if ( class_exists( 'WooCommerce' ) ) {
            $resources[] = [
                'uri'         => 'woocommerce://store/info',
                'name'        => 'WooCommerce Store Info',
                'description' => 'Currency, units, tax config, payment gateways, store address.',
                'mimeType'    => 'application/json',
            ];
            $resources[] = [
                'uri'         => 'woocommerce://products/schema',
                'name'        => 'WooCommerce Product Schema',
                'description' => 'Product types, attributes, categories, tags, stock statuses.',
                'mimeType'    => 'application/json',
            ];
            $resources[] = [
                'uri'         => 'woocommerce://shipping/zones',
                'name'        => 'WooCommerce Shipping Zones',
                'description' => 'Shipping zones with locations and methods.',
                'mimeType'    => 'application/json',
            ];
        }

        // Conditionally add Wordfence resources.
        if ( class_exists( 'wordfence' ) ) {
            $resources[] = [
                'uri'         => 'wordfence://scan/status',
                'name'        => 'Wordfence Scan Status',
                'description' => 'Current scan running state, last completion time, and open issue count.',
                'mimeType'    => 'application/json',
            ];
            $resources[] = [
                'uri'         => 'wordfence://firewall/status',
                'name'        => 'Wordfence Firewall Status',
                'description' => 'Firewall mode, WAF status, brute force protection, and premium status.',
                'mimeType'    => 'application/json',
            ];
            $resources[] = [
                'uri'         => 'wordfence://activity/summary',
                'name'        => 'Wordfence Activity Summary',
                'description' => '7-day summary of attacks blocked and failed logins.',
                'mimeType'    => 'application/json',
            ];
        }

        // Conditionally add UpdraftPlus resource.
        if ( class_exists( 'UpdraftPlus' ) ) {
            $resources[] = [
                'uri'         => 'updraftplus://backup/history',
                'name'        => 'UpdraftPlus Backup History',
                'description' => 'Recent backup history with timestamps, entities, and storage destinations.',
                'mimeType'    => 'application/json',
            ];
        }

        return [ 'resources' => $resources ];
    }

    /**
     * Handle resources/templates/list.
     */
    public static function list_resource_templates( array $params ): array {
        return [ 'resourceTemplates' => self::TEMPLATES ];
    }

    /**
     * Handle resources/read.
     */
    public static function read_resource( array $params ): array|WP_Error {
        $uri = $params['uri'] ?? '';

        $content = match ( $uri ) {
            'wordpress://site/info'              => self::resource_site_info(),
            'wordpress://site/options'           => self::resource_core_options(),
            'wordpress://theme/current'          => self::resource_theme_files(),
            'wordpress://theme/functions'        => self::resource_theme_file( 'functions.php' ),
            'wordpress://theme/style'            => self::resource_theme_file( 'style.css' ),
            'wordpress://database/schema'        => self::resource_db_schema(),
            'wordpress://content/post-types'     => self::resource_post_types(),
            'wordpress://content/taxonomies'     => self::resource_taxonomies(),
            'wordpress://site/htaccess'          => self::resource_htaccess(),
            'wordpress://site/wp-config-summary' => self::resource_wp_config_summary(),
            'wordpress://tools/catalog'          => self::resource_tool_catalog(),
            'woocommerce://store/info'           => class_exists( 'WooCommerce' ) ? self::resource_woo_store_info() : null,
            'woocommerce://products/schema'      => class_exists( 'WooCommerce' ) ? self::resource_woo_product_schema() : null,
            'woocommerce://shipping/zones'       => class_exists( 'WooCommerce' ) ? self::resource_woo_shipping_zones() : null,
            'updraftplus://backup/history'        => class_exists( 'UpdraftPlus' ) ? self::resource_updraft_backup_history() : null,
            'wordfence://scan/status'            => class_exists( 'wordfence' ) ? self::resource_wordfence_scan_status() : null,
            'wordfence://firewall/status'        => class_exists( 'wordfence' ) ? self::resource_wordfence_firewall_status() : null,
            'wordfence://activity/summary'       => class_exists( 'wordfence' ) ? self::resource_wordfence_activity_summary() : null,
            default => null,
        };

        if ( $content === null ) {
            $content = self::resolve_template_uri( $uri );
        }

        if ( $content === null ) {
            return new WP_Error( 'resource_not_found', "Unknown resource: {$uri}", [ 'code' => -32602 ] );
        }

        $text = is_string( $content ) ? $content : wp_json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        return [
            'contents' => [
                [
                    'uri'      => $uri,
                    'mimeType' => is_string( $content ) ? 'text/plain' : 'application/json',
                    'text'     => $text,
                ],
            ],
        ];
    }

    /* ── Resource implementations ──────────────────────────── */

    private static function resource_site_info(): array {
        return Cowboy_MCP_Tools::tool_site_info();
    }

    private static function resource_core_options(): array {
        $keys = [
            'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
            'permalink_structure', 'date_format', 'time_format', 'timezone_string',
            'WPLANG', 'posts_per_page', 'default_category', 'default_post_format',
            'show_on_front', 'page_on_front', 'page_for_posts',
            'template', 'stylesheet', 'current_theme',
            'users_can_register', 'default_role',
            'blog_public',
        ];
        $options = [];
        foreach ( $keys as $key ) {
            $options[ $key ] = get_option( $key );
        }
        return $options;
    }

    private static function resource_theme_files(): array {
        $theme_dir = get_stylesheet_directory();
        $files     = [];
        $iterator  = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $count = 0;
        foreach ( $iterator as $item ) {
            if ( ++$count > 300 ) break;
            $relative = str_replace( $theme_dir . '/', '', $item->getPathname() );
            $files[]  = [
                'path'     => $relative,
                'type'     => $item->isDir() ? 'directory' : 'file',
                'size'     => $item->isFile() ? $item->getSize() : null,
            ];
        }
        return [ 'theme' => get_stylesheet(), 'files' => $files ];
    }

    private static function resource_theme_file( string $filename ): string {
        $path = get_stylesheet_directory() . '/' . $filename;
        if ( ! file_exists( $path ) ) {
            return "(File not found: {$filename})";
        }
        return file_get_contents( $path );
    }

    private static function resource_db_schema(): array {
        global $wpdb;

        // Single query against information_schema instead of N queries (one per table).
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION",
            ARRAY_A
        );

        $schema = [];
        foreach ( $rows ?? [] as $row ) {
            $schema[ $row['TABLE_NAME'] ][] = [
                'field'   => $row['COLUMN_NAME'],
                'type'    => $row['COLUMN_TYPE'],
                'null'    => $row['IS_NULLABLE'],
                'key'     => $row['COLUMN_KEY'],
                'default' => $row['COLUMN_DEFAULT'],
            ];
        }
        return $schema;
    }

    private static function resource_post_types(): array {
        $types = get_post_types( [], 'objects' );
        $result = [];
        foreach ( $types as $slug => $pt ) {
            $result[ $slug ] = [
                'label'       => $pt->label,
                'public'      => $pt->public,
                'hierarchical'=> $pt->hierarchical,
                'has_archive' => $pt->has_archive,
                'supports'    => get_all_post_type_supports( $slug ),
                'taxonomies'  => get_object_taxonomies( $slug ),
                'rest_base'   => $pt->rest_base ?: $slug,
            ];
        }
        return $result;
    }

    private static function resource_taxonomies(): array {
        $taxes = get_taxonomies( [], 'objects' );
        $result = [];
        foreach ( $taxes as $slug => $tax ) {
            $result[ $slug ] = [
                'label'        => $tax->label,
                'public'       => $tax->public,
                'hierarchical' => $tax->hierarchical,
                'object_types' => $tax->object_type,
                'rest_base'    => $tax->rest_base ?: $slug,
            ];
        }
        return $result;
    }

    private static function resource_htaccess(): string {
        $path = ABSPATH . '.htaccess';
        if ( ! file_exists( $path ) ) {
            return '(.htaccess not found — server may use nginx)';
        }
        return file_get_contents( $path );
    }

    private static function resource_wp_config_summary(): array {
        // Return non-sensitive configuration constants.
        $constants = [
            'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY',
            'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT',
            'AUTOSAVE_INTERVAL', 'WP_POST_REVISIONS',
            'EMPTY_TRASH_DAYS', 'DISALLOW_FILE_EDIT',
            'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN',
            'WP_CACHE', 'COMPRESS_CSS', 'COMPRESS_SCRIPTS',
            'CONCATENATE_SCRIPTS', 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR',
            'UPLOADS', 'WP_DEFAULT_THEME',
        ];

        $values = [];
        foreach ( $constants as $c ) {
            $values[ $c ] = defined( $c ) ? constant( $c ) : '(not defined)';
        }
        return $values;
    }

    /* ── Tool catalog resource ─────────────────────────────── */

    private static function resource_tool_catalog(): array {
        return Cowboy_MCP_Tools::get_tool_catalog();
    }

    /* ── UpdraftPlus resource (conditional) ─────────────────── */

    private static function resource_updraft_backup_history(): array {
        if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
            return [ 'error' => 'UpdraftPlus_Backup_History class not available.' ];
        }

        $history = UpdraftPlus_Backup_History::get_history();
        krsort( $history );

        $backups = [];
        $count   = 0;
        foreach ( $history as $ts => $backup ) {
            if ( ++$count > 50 ) break;

            $entities = [];
            foreach ( [ 'db', 'plugins', 'themes', 'uploads', 'others' ] as $entity ) {
                if ( ! empty( $backup[ $entity ] ) ) {
                    $entities[] = $entity;
                }
            }

            $backups[] = [
                'timestamp' => (int) $ts,
                'date'      => gmdate( 'Y-m-d H:i:s', (int) $ts ),
                'nonce'     => $backup['nonce'] ?? '',
                'entities'  => $entities,
                'service'   => $backup['service'] ?? 'none',
                'label'     => $backup['label'] ?? '',
            ];
        }

        return [
            'total'   => count( $history ),
            'showing' => count( $backups ),
            'backups' => $backups,
        ];
    }

    /* ── Wordfence resources (conditional) ─────────────────── */

    private static function resource_wordfence_scan_status(): array {
        if ( ! class_exists( 'wfConfig' ) ) {
            return [ 'error' => 'wfConfig class not available.' ];
        }

        global $wpdb;
        $issues_table = $wpdb->base_prefix . 'wfIssues';
        $open_count   = 0;
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $issues_table ) ) === $issues_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $open_count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COUNT(*) FROM %i WHERE status = 'new'",
                $issues_table
            ) );
        }

        $last_completed = wfConfig::get( 'lastScanCompleted' );

        return [
            'running'        => (bool) wfConfig::get( 'wf_scanRunning' ),
            'last_completed' => $last_completed ? gmdate( 'Y-m-d H:i:s', (int) $last_completed ) : null,
            'open_issues'    => $open_count,
        ];
    }

    private static function resource_wordfence_firewall_status(): array {
        if ( ! class_exists( 'wfConfig' ) ) {
            return [ 'error' => 'wfConfig class not available.' ];
        }

        return [
            'firewall_enabled'      => (bool) wfConfig::get( 'firewallEnabled', false ),
            'waf_status'            => wfConfig::get( 'wafStatus', 'disabled' ),
            'learning_mode'         => (bool) wfConfig::get( 'learningModeGracePeriodEnabled', false ),
            'brute_force_enabled'   => (bool) wfConfig::get( 'loginSecurityEnabled', false ),
            'is_premium'            => (bool) wfConfig::get( 'isPaid', false ),
        ];
    }

    private static function resource_wordfence_activity_summary(): array {
        global $wpdb;
        $result = [ 'period' => '7 days' ];

        $seven_days_ago = time() - ( 7 * DAY_IN_SECONDS );

        $hits_table = $wpdb->base_prefix . 'wfHits';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $hits_table ) ) === $hits_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $result['attacks_blocked'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COUNT(*) FROM %i WHERE action LIKE %s AND attackLogTime > %f",
                $hits_table,
                'blocked%',
                (float) $seven_days_ago
            ) );
        }

        $logins_table = $wpdb->base_prefix . 'wfLogins';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $logins_table ) ) === $logins_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $result['failed_logins'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COUNT(*) FROM %i WHERE fail = 1 AND ctime > %f",
                $logins_table,
                (float) $seven_days_ago
            ) );
            $result['successful_logins'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COUNT(*) FROM %i WHERE fail = 0 AND ctime > %f",
                $logins_table,
                (float) $seven_days_ago
            ) );
        }

        return $result;
    }

    /* ── WooCommerce resources (conditional) ───────────────── */

    private static function resource_woo_store_info(): array {
        $gateways = [];
        foreach ( WC()->payment_gateways()->payment_gateways() as $gw ) {
            $gateways[] = [
                'id'      => $gw->id,
                'title'   => $gw->get_title(),
                'enabled' => $gw->enabled === 'yes',
            ];
        }

        return [
            'currency'          => get_woocommerce_currency(),
            'currency_symbol'   => get_woocommerce_currency_symbol(),
            'currency_position' => get_option( 'woocommerce_currency_pos' ),
            'thousand_sep'      => get_option( 'woocommerce_price_thousand_sep' ),
            'decimal_sep'       => get_option( 'woocommerce_price_decimal_sep' ),
            'num_decimals'      => get_option( 'woocommerce_price_num_decimals' ),
            'weight_unit'       => get_option( 'woocommerce_weight_unit' ),
            'dimension_unit'    => get_option( 'woocommerce_dimension_unit' ),
            'tax_enabled'       => wc_tax_enabled(),
            'tax_display_shop'  => get_option( 'woocommerce_tax_display_shop' ),
            'tax_display_cart'  => get_option( 'woocommerce_tax_display_cart' ),
            'prices_include_tax'=> get_option( 'woocommerce_prices_include_tax' ),
            'calc_taxes'        => get_option( 'woocommerce_calc_taxes' ),
            'store_address'     => [
                'address_1' => get_option( 'woocommerce_store_address' ),
                'address_2' => get_option( 'woocommerce_store_address_2' ),
                'city'      => get_option( 'woocommerce_store_city' ),
                'postcode'  => get_option( 'woocommerce_store_postcode' ),
                'country'   => get_option( 'woocommerce_default_country' ),
            ],
            'payment_gateways'  => $gateways,
            'woocommerce_version' => WC()->version,
        ];
    }

    private static function resource_woo_product_schema(): array {
        // Product types.
        $product_types = wc_get_product_types();

        // Global attributes.
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        $attributes = array_map( fn( $attr ) => [
            'id'       => $attr->attribute_id,
            'name'     => $attr->attribute_name,
            'label'    => $attr->attribute_label,
            'type'     => $attr->attribute_type,
            'orderby'  => $attr->attribute_orderby,
            'has_archives' => (bool) $attr->attribute_public,
        ], $attribute_taxonomies );

        // Product categories.
        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'number'     => 200,
        ] );
        $cats = is_array( $categories ) ? array_map( fn( $t ) => [
            'id'     => $t->term_id,
            'name'   => $t->name,
            'slug'   => $t->slug,
            'parent' => $t->parent,
            'count'  => $t->count,
        ], $categories ) : [];

        // Product tags.
        $tags = get_terms( [
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
            'number'     => 200,
        ] );
        $tag_list = is_array( $tags ) ? array_map( fn( $t ) => [
            'id'    => $t->term_id,
            'name'  => $t->name,
            'slug'  => $t->slug,
            'count' => $t->count,
        ], $tags ) : [];

        // Stock statuses.
        $stock_statuses = wc_get_product_stock_status_options();

        return [
            'product_types'  => $product_types,
            'attributes'     => array_values( $attributes ),
            'categories'     => $cats,
            'tags'           => $tag_list,
            'stock_statuses' => $stock_statuses,
        ];
    }

    private static function resource_woo_shipping_zones(): array {
        $raw_zones = WC_Shipping_Zones::get_zones();
        $zones     = [];

        // Zone 0 = "Rest of the World".
        $zone_zero = new WC_Shipping_Zone( 0 );
        $zones[]   = [
            'id'        => 0,
            'name'      => $zone_zero->get_zone_name(),
            'locations' => [],
            'methods'   => array_map( fn( $m ) => [
                'id'      => $m->id,
                'title'   => $m->title,
                'enabled' => $m->enabled,
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
                    'id'      => $m->id,
                    'title'   => $m->title,
                    'enabled' => $m->enabled,
                ], $zone->get_shipping_methods() ),
            ];
        }

        return [ 'zones' => $zones ];
    }

    /* ── Resource template resolver ───────────────────────── */

    private static function resolve_template_uri( string $uri ) {
        // wordpress://posts/{id} — numeric ID only
        if ( preg_match( '#^wordpress://posts/(\d+)$#', $uri, $m ) ) {
            return self::resource_single_post( (int) $m[1] );
        }
        // wordpress://options/{name} — alphanumeric, underscores, hyphens
        if ( preg_match( '#^wordpress://options/([a-zA-Z0-9_-]+)$#', $uri, $m ) ) {
            return self::resource_single_option( $m[1] );
        }
        // wordpress://plugins/{slug} — use -- as separator instead of /
        if ( preg_match( '#^wordpress://plugins/([a-zA-Z0-9_.@-]+)$#', $uri, $m ) ) {
            return self::resource_single_plugin( $m[1] );
        }
        // wordpress://users/{id} — numeric ID only
        if ( preg_match( '#^wordpress://users/(\d+)$#', $uri, $m ) ) {
            return self::resource_single_user( (int) $m[1] );
        }
        return null;
    }

    /* ── Single-resource implementations (template-backed) ── */

    private static function resource_single_post( int $id ) {
        $post = get_post( $id );
        if ( ! $post ) return null;
        return [
            'ID'        => $post->ID,
            'title'     => $post->post_title,
            'slug'      => $post->post_name,
            'status'    => $post->post_status,
            'type'      => $post->post_type,
            'content'   => $post->post_content,
            'excerpt'   => $post->post_excerpt,
            'date'      => $post->post_date,
            'modified'  => $post->post_modified,
            'author'    => get_the_author_meta( 'display_name', $post->post_author ),
            'permalink' => get_permalink( $id ),
        ];
    }

    private static function resource_single_option( string $name ) {
        // Never disclose secrets (the plugin's own keys, payment-gateway secrets, etc.).
        if ( Cowboy_MCP_Security::is_sensitive_option( $name ) ) {
            return null;
        }
        $value = get_option( $name, '__MCP_NOT_FOUND__' );
        if ( $value === '__MCP_NOT_FOUND__' ) return null;
        return [ 'option_name' => $name, 'value' => $value ];
    }

    private static function resource_single_plugin( string $slug ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Convert -- back to / for plugin file path lookup
        $file = str_replace( '--', '/', $slug );
        $all  = get_plugins();
        if ( ! isset( $all[ $file ] ) ) return null;
        $data   = $all[ $file ];
        $active = in_array( $file, get_option( 'active_plugins', [] ), true );
        return [
            'file'        => $file,
            'name'        => $data['Name'],
            'version'     => $data['Version'],
            'active'      => $active,
            'description' => wp_strip_all_tags( $data['Description'] ),
            'author'      => $data['Author'],
            'uri'         => $data['PluginURI'] ?? '',
        ];
    }

    private static function resource_single_user( int $id ) {
        $user = get_userdata( $id );
        if ( ! $user ) return null;
        return [
            'ID'           => $user->ID,
            'login'        => $user->user_login,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'roles'        => $user->roles,
            'registered'   => $user->user_registered,
        ];
    }
}
