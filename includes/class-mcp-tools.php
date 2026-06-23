<?php
/**
 * Cowboy MCP – Tools (Orchestrator)
 *
 * Slim registry that lazily loads domain-specific tool files from includes/tools/.
 * Preserves the public API used by transport, admin, and resources classes.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Tools {

    /** Tool name prefixes, ordered from most-specific to least-specific. */
    private const TOOL_PREFIXES = [
        'wp_woo_', 'wp_acf_', 'wp_seo_',
        'wp_forms_', 'wp_cache_', 'wp_elementor_', 'wp_wordfence_', 'cowboy_mcp_', 'wp_',
    ];

    /** Domain files to load from includes/tools/. */
    private const DOMAIN_FILES = [
        'core/options.php',
        'core/files.php',
        'core/database.php',
        'core/system.php',
        'core/site-health.php',
        'core/users.php',
        'core/media.php',
        'core/batch.php',
        'core/diagnostics.php',
        'core/posts.php',
        'core/plugins.php',
        'core/themes.php',
        'core/taxonomies.php',
        'core/comments.php',
        'acf/tools-acf.php',
        'woocommerce/products.php',
        'woocommerce/orders.php',
        'woocommerce/customers.php',
        'woocommerce/coupons.php',
        'woocommerce/settings.php',
        'woocommerce/reports.php',
        'seo/tools-seo.php',
        'forms/tools-forms.php',
        'cache/tools-cache.php',
        'elementor/tools-elementor.php',
        'wordfence/tools-wordfence.php',
    ];

    /** Map from domain file path to category name. */
    private const CATEGORY_MAP = [
        'core/options.php'            => 'options',
        'core/files.php'              => 'files',
        'core/database.php'           => 'database',
        'core/system.php'             => 'system',
        'core/site-health.php'        => 'system',
        'core/users.php'              => 'users',
        'core/media.php'              => 'media',
        'core/batch.php'              => 'batch',
        'core/diagnostics.php'        => 'diagnostics',
        'core/posts.php'              => 'content',
        'core/plugins.php'            => 'plugins',
        'core/themes.php'             => 'themes',
        'core/taxonomies.php'         => 'taxonomies',
        'core/comments.php'           => 'comments',
        'acf/tools-acf.php'                 => 'acf',
        'woocommerce/products.php'          => 'woocommerce',
        'woocommerce/orders.php'            => 'woocommerce',
        'woocommerce/customers.php'         => 'woocommerce',
        'woocommerce/coupons.php'           => 'woocommerce',
        'woocommerce/settings.php'          => 'woocommerce',
        'woocommerce/reports.php'           => 'woocommerce',
        'seo/tools-seo.php'                 => 'seo',
        'forms/tools-forms.php'             => 'forms',
        'cache/tools-cache.php'             => 'cache',
        'elementor/tools-elementor.php'     => 'elementor',
        'wordfence/tools-wordfence.php'     => 'wordfence',
    ];

    /** Human-readable category descriptions for gateway instructions. */
    private const CATEGORY_DESCRIPTIONS = [
        'options'        => 'update WordPress options (with sensitive-option blocklist)',
        'files'          => 'read, write, list, delete files in wp-content',
        'database'       => 'raw SQL queries and writes (with safety blocklists)',
        'system'         => 'site info, WP-CLI, search-replace, site health',
        'users'          => 'delete users (with self-delete protection)',
        'media'          => 'upload media (with SSRF protection)',
        'batch'          => 'multi-tool sequencing, audit log retrieval',
        'diagnostics'    => 'error logs, HTTP requests, email testing, hooks, transients, REST routes, thumbnails, rewrite rules, site snapshots',
        'content'        => 'create, read, update, delete posts, pages, and custom post types',
        'plugins'        => 'list, activate, and deactivate plugins',
        'themes'         => 'list themes and switch the active theme',
        'taxonomies'     => 'list, create, update, and delete taxonomy terms',
        'comments'       => 'list, create, update, and delete comments',
        'acf'            => 'ACF field groups, field CRUD, repeater operations',
        'woocommerce'    => 'products, orders, customers, coupons, settings, reports',
        'seo'            => 'SEO provider detection',
        'forms'          => 'form provider detection',
        'cache'          => 'provider detect, flush, preload, settings',
        'elementor'      => 'templates, page content, global styles, widgets',
        'wordfence'      => 'scan, blocks, firewall, live traffic, activity, settings',
    ];

    /** @var array<array> Cached tool definitions. */
    private static array $tools = [];

    /** @var array<string, array> Tool definitions indexed by name for O(1) lookup. */
    private static array $tool_map = [];

    /** @var array<string, callable> Cached handler map. */
    private static array $handlers = [];

    /** @var array<string, string> Tool name → category mapping. */
    private static array $tool_categories = [];

    /** @var bool Whether domain files have been loaded. */
    private static bool $loaded = false;

    /** @var bool Whether dry_run params have been injected. */
    private static bool $dry_run_injected = false;

    /** @var array|null Cached settings to avoid repeated get_option() calls. */
    private static ?array $settings_cache = null;

    /**
     * Get plugin settings, cached for the duration of the request.
     */
    public static function get_settings(): array {
        if ( self::$settings_cache === null ) {
            self::$settings_cache = get_option( 'cowboy_mcp_settings', [] );
        }
        return self::$settings_cache;
    }

    /**
     * Write a structured JSON log line when log_requests is enabled.
     * Delegates to Cowboy_MCP_Auth::log() with key context merged in.
     */
    private static function mcp_log( string $event, array $context = [] ): void {
        Cowboy_MCP_Auth::log( $event, array_merge( Cowboy_MCP_Auth::$current_key_context, $context ) );
    }

    /* ================================================================
     *  PUBLIC API (unchanged signatures)
     * ================================================================ */

    /**
     * Return the tool list for tools/list.
     */
    public static function list_tools( array $params ): array {
        return [ 'tools' => self::gateway_tool_definitions() ];
    }

    /**
     * Dispatch a tools/call request.
     */
    public static function call_tool( array $params ): array|WP_Error {
        self::load_domains();

        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        /**
         * Filter whether a specific tool is allowed to execute.
         *
         * @param bool   $allowed Whether the tool is allowed.
         * @param string $name    Tool name.
         * @param array  $args    Tool arguments.
         */
        if ( ! apply_filters( 'cowboy_mcp_tool_allowed', true, $name, $args ) ) {
            return new WP_Error( 'tool_blocked', "Tool blocked by filter: {$name}", [ 'code' => -32603 ] );
        }

        // Gateway meta-tool: cowboy_run delegates to the inner tool.
        if ( $name === 'cowboy_run' ) {
            $inner_tool = $args['tool'] ?? '';
            if ( empty( $inner_tool ) ) {
                return new WP_Error( 'invalid_params', 'Missing required argument: tool', [ 'code' => -32602 ] );
            }
            if ( $inner_tool === 'cowboy_run' || $inner_tool === 'cowboy_discover' ) {
                return new WP_Error( 'invalid_params', 'Cannot invoke gateway meta-tools through cowboy_run.', [ 'code' => -32602 ] );
            }
            self::mcp_log( 'tool_call', [ 'tool' => 'cowboy_run', 'inner_tool' => $inner_tool ] );
            return self::call_tool( [
                'name'      => $inner_tool,
                'arguments' => $args['arguments'] ?? [],
            ] );
        }

        // Gateway meta-tool: cowboy_discover searches/browses tools.
        if ( $name === 'cowboy_discover' ) {
            self::mcp_log( 'tool_call', [ 'tool' => 'cowboy_discover', 'args' => $args ] );
            $result = self::handle_discover_tools( $args );
            if ( is_wp_error( $result ) ) {
                self::mcp_log( 'tool_error', [ 'tool' => 'cowboy_discover', 'error' => $result->get_error_message() ] );
                return $result;
            }
            $text = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            return [ 'content' => [[ 'type' => 'text', 'text' => $text ]] ];
        }

        // Allowlist gate — the outer boundary for concrete tools, applied BEFORE
        // dry-run/safe-mode so a disabled tool returns "disabled" rather than leaking a
        // preview. Meta-tools (cowboy_run/cowboy_discover) are handled above; an inner
        // tool invoked through cowboy_run is re-checked when call_tool() recurses.
        $settings = self::get_settings();
        $allowed  = $settings['allowed_tools'] ?? 'all';
        if ( $allowed !== 'all' && is_array( $allowed ) && ! in_array( $name, $allowed, true ) ) {
            return new WP_Error( 'tool_disabled', "Tool is disabled: {$name}", [ 'code' => -32603 ] );
        }

        $handler = self::$handlers[ $name ] ?? null;
        if ( ! $handler ) {
            return new WP_Error( 'unknown_tool', "Tool not found: {$name}", [ 'code' => -32602 ] );
        }

        // Validate required arguments against the tool's inputSchema.
        $tool_def = self::$tool_map[ $name ] ?? null;
        if ( $tool_def ) {
            $required = $tool_def['inputSchema']['required'] ?? [];
            $missing  = array_diff( $required, array_keys( $args ) );
            if ( ! empty( $missing ) ) {
                return new WP_Error(
                    'invalid_params',
                    'Missing required argument(s): ' . implode( ', ', $missing ),
                    [ 'code' => -32602 ]
                );
            }
        }

        // Dry-run intercept: preview what the tool would do without executing.
        if ( ! empty( $args['dry_run'] ) ) {
            $annotations = self::$tool_map[ $name ]['annotations'] ?? [];
            if ( ! empty( $annotations['readOnlyHint'] ) ) {
                return new WP_Error( 'invalid_params', 'dry_run is not applicable to read-only tools.' );
            }
            return self::generate_dry_run_preview( $name, $args );
        }

        // Safe mode: require confirmation for destructive operations.
        if ( ! empty( $settings['safe_mode'] ) ) {
            $annotations = self::$tool_map[ $name ]['annotations'] ?? [];
            if ( ! empty( $annotations['destructiveHint'] ) && empty( $args['confirm'] ) ) {
                $preview = self::generate_dry_run_preview( $name, $args );
                $preview_text = $preview['content'][0]['text'] ?? '';
                return [
                    'content' => [
                        [ 'type' => 'text', 'text' => wp_json_encode( [
                            'confirmation_required' => true,
                            'tool'                  => $name,
                            'message'               => "Safe mode is ON. This tool is destructive and requires explicit confirmation. Resend with confirm: true to execute.",
                            'preview'               => json_decode( $preview_text, true ),
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ],
                    ],
                    'isError' => true,
                ];
            }
        }

        // Queue MCP log notification if session has a log level.
        if ( class_exists( 'Cowboy_MCP_Transport' ) && method_exists( 'Cowboy_MCP_Transport', 'queue_notification' ) ) {
            Cowboy_MCP_Transport::queue_notification( 'info', "Executing tool: {$name}" );
        }

        self::mcp_log( 'tool_call', [ 'tool' => $name, 'args' => $args, 'power_mode' => Cowboy_MCP_Security::power_mode_enabled() ] );

        try {
            $result = call_user_func( $handler, $args );
            if ( is_wp_error( $result ) ) {
                self::mcp_log( 'tool_error', [ 'tool' => $name, 'error' => $result->get_error_message() ] );

                // Enhanced error response with suggestions.
                $suggestion = $result->get_error_data()['suggestion']
                              ?? self::get_error_suggestion( $name, $result->get_error_code() );
                $text = 'Error: ' . $result->get_error_message();
                if ( $suggestion ) {
                    $text .= "\nSuggestion: " . $suggestion;
                }

                if ( class_exists( 'Cowboy_MCP_Transport' ) && method_exists( 'Cowboy_MCP_Transport', 'queue_notification' ) ) {
                    Cowboy_MCP_Transport::queue_notification( 'warning', "Tool error: {$result->get_error_message()}" );
                }

                return [
                    'content' => [
                        [ 'type' => 'text', 'text' => $text ],
                    ],
                    'isError' => true,
                ];
            }
            $text = is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            if ( $text === false ) {
                $text = '(Tool result could not be encoded as JSON.)';
            }
            $response = [
                'content' => [
                    [ 'type' => 'text', 'text' => $text ],
                ],
            ];

            // Add structuredContent when tool has an outputSchema and result is structured data.
            if ( ! is_string( $result ) && ! empty( self::$tool_map[ $name ]['outputSchema'] ) ) {
                $response['structuredContent'] = $result;
            }

            return $response;
        } catch ( \Throwable $e ) {
            self::mcp_log( 'tool_exception', [ 'tool' => $name, 'exception' => $e->getMessage() ] );
            return [
                'content' => [
                    [ 'type' => 'text', 'text' => 'Exception: ' . $e->getMessage() ],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * Public proxy for site info — called by Cowboy_MCP_Resources.
     */
    public static function tool_site_info(): array {
        self::load_domains();
        $handler = self::$handlers['wp_site_info'] ?? null;
        return $handler ? $handler( [] ) : [];
    }

    /* ================================================================
     *  TOOL DEFINITION HELPER
     * ================================================================ */

    /**
     * Build a single MCP tool definition array.
     * Public so domain files can call Cowboy_MCP_Tools::tool().
     */
    public static function tool( string $name, string $description, array $properties, array $annotations = [], array $output_schema = [] ): array {
        $required     = [];
        $schema_props = [];

        // JSON Schema keywords to forward from property definitions.
        $schema_keywords = [
            'type', 'description', 'default', 'items', 'enum',
            'minimum', 'maximum', 'minItems', 'maxItems',
            'format', 'examples', 'properties',
        ];

        foreach ( $properties as $prop_name => $prop ) {
            $entry = [];
            foreach ( $schema_keywords as $kw ) {
                if ( isset( $prop[ $kw ] ) ) {
                    $entry[ $kw ] = $prop[ $kw ];
                }
            }
            if ( ! isset( $entry['type'] ) ) {
                $entry['type'] = 'string';
            }
            // Forward nested `required` as array (JSON Schema), distinct from
            // the boolean `required` which marks this property as top-level required.
            if ( isset( $prop['required'] ) && is_array( $prop['required'] ) ) {
                $entry['required'] = $prop['required'];
            }
            $schema_props[ $prop_name ] = $entry;

            if ( ! empty( $prop['required'] ) && $prop['required'] === true ) {
                $required[] = $prop_name;
            }
        }

        $input_schema = [
            'type'       => 'object',
            'properties' => empty( $schema_props ) ? (object) [] : $schema_props,
        ];
        if ( ! empty( $required ) ) {
            $input_schema['required'] = $required;
        }

        $tool = [
            'name'        => $name,
            'description' => $description,
            'inputSchema' => $input_schema,
        ];

        if ( $annotations ) {
            $tool['annotations'] = $annotations;
        }

        if ( $output_schema ) {
            $tool['outputSchema'] = $output_schema;
        }

        return $tool;
    }

    /* ================================================================
     *  DRY-RUN PREVIEW
     * ================================================================ */

    /**
     * Generate a dry-run preview for a tool call without executing.
     */
    private static function generate_dry_run_preview( string $name, array $args ): array {
        // Strip prefix to extract action and resource.
        $stripped = $name;
        foreach ( self::TOOL_PREFIXES as $prefix ) {
            if ( str_starts_with( $stripped, $prefix ) ) {
                $stripped = substr( $stripped, strlen( $prefix ) );
                break;
            }
        }

        $parts    = explode( '_', $stripped, 2 );
        $action   = $parts[0] ?? 'execute';
        $resource = str_replace( '_', ' ', $parts[1] ?? 'resource' );

        // Find an ID-like argument for the description.
        $id = null;
        foreach ( $args as $key => $val ) {
            if ( $key === 'dry_run' ) continue;
            if ( preg_match( '/_id$|^id$/', $key ) && is_numeric( $val ) ) {
                $id = (int) $val;
                break;
            }
        }

        $filtered_args = $args;
        unset( $filtered_args['dry_run'] );

        $description = "Would {$action} {$resource}";
        if ( $id ) {
            $description .= " #{$id}";
        }
        $description .= ' with the provided parameters.';

        return [
            'content' => [[ 'type' => 'text', 'text' => wp_json_encode( [
                'dry_run'     => true,
                'tool'        => $name,
                'action'      => $action,
                'resource'    => $resource,
                'description' => $description,
                'parameters'  => $filtered_args,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ]],
        ];
    }

    /* ================================================================
     *  ERROR SUGGESTIONS
     * ================================================================ */

    /**
     * Get a contextual suggestion for an error code.
     */
    private static function get_error_suggestion( string $tool_name, string $error_code ): ?string {
        return match ( $error_code ) {
            'not_found'      => 'Verify the ID exists. Use wp_cli to list resources (e.g. "wp post list", "wp user list").',
            'invalid_params' => 'Check the tool definition for required parameters and valid types.',
            'option_blocked' => 'This option is protected. Use wp_cli with "option get <name>" to read it instead.',
            'path_escape'    => 'File paths must be relative to wp-content/. Example: themes/mytheme/style.css',
            'read_only'      => 'Use wp_db_write for INSERT/UPDATE/DELETE queries.',
            'blocked'        => 'This SQL operation is blocked for safety. Use WordPress functions instead.',
            'tool_blocked'   => 'This tool has been blocked by a filter. Check with the site administrator.',
            'tool_disabled'  => 'This tool is disabled in Cowboy MCP settings.',
            'confirmation_required' => 'Safe mode is ON. Add confirm: true to the arguments to execute this destructive tool.',
            default          => null,
        };
    }

    /* ================================================================
     *  GATEWAY MODE
     * ================================================================ */

    /**
     * Return the 2 gateway meta-tool definitions.
     */
    private static function gateway_tool_definitions(): array {
        return [
            self::tool(
                'cowboy_discover',
                "Search or browse available WordPress tools by keyword or category. Returns matching MCP tool definitions with name, description, inputSchema, and annotations.\n\nExamples:\n- cowboy_discover(query=\"login failures\") → finds wp_wordfence_update_settings\n- cowboy_discover(query=\"error log\") → finds wp_get_php_error_log\n- cowboy_discover(category=\"wordfence\") → lists all Wordfence tools\n- cowboy_discover(query=\"settings\", category=\"woocommerce\") → WooCommerce settings tools",
                [
                    'query' => [
                        'type'        => 'string',
                        'description' => "Keyword search, e.g. 'wordfence settings' or 'create post'",
                    ],
                    'category' => [
                        'type'        => 'string',
                        'description' => 'Browse all tools in a category',
                        'enum'        => array_keys( self::CATEGORY_DESCRIPTIONS ),
                    ],
                ],
                [
                    'readOnlyHint'    => true,
                    'destructiveHint' => false,
                    'idempotentHint'  => true,
                    'openWorldHint'   => false,
                ]
            ),
            self::tool(
                'cowboy_run',
                "Execute a WordPress tool by name with arguments. Use cowboy_discover first to find the right tool and its inputSchema.\n\nExamples:\n- cowboy_run(tool=\"wp_cli\", arguments={command: \"post list --format=json --posts_per_page=5\"})\n- cowboy_run(tool=\"wp_wordfence_update_settings\", arguments={settings: {maxFailures: 8}})\n- cowboy_run(tool=\"wp_get_hooks\", arguments={hook: \"init\"})",
                [
                    'tool' => [
                        'type'        => 'string',
                        'description' => 'Tool name from cowboy_discover results',
                        'required'    => true,
                    ],
                    'arguments' => [
                        'type'        => 'object',
                        'description' => "Tool arguments per the tool's inputSchema",
                    ],
                ],
                [
                    'readOnlyHint'    => false,
                    'destructiveHint' => false,
                    'idempotentHint'  => false,
                    'openWorldHint'   => true,
                ]
            ),
        ];
    }

    /**
     * Handle the cowboy_discover meta-tool: search/browse tools.
     */
    private static function handle_discover_tools( array $args ): array|WP_Error {
        $query    = trim( $args['query'] ?? '' );
        $category = $args['category'] ?? '';

        if ( $query === '' && $category === '' ) {
            return new WP_Error( 'invalid_params', 'Provide a query, a category, or both.', [ 'code' => -32602 ] );
        }

        $all_tools = self::get_tool_definitions();

        // Filter by category if specified.
        if ( $category !== '' ) {
            if ( ! isset( self::CATEGORY_DESCRIPTIONS[ $category ] ) ) {
                return new WP_Error( 'invalid_params', "Unknown category: {$category}", [ 'code' => -32602 ] );
            }
            $all_tools = array_filter( $all_tools, function ( $tool ) use ( $category ) {
                return ( self::$tool_categories[ $tool['name'] ] ?? '' ) === $category;
            } );
        }

        // If no query, return all tools in the category.
        if ( $query === '' ) {
            return [ 'tools' => array_values( $all_tools ) ];
        }

        // Score and rank by keyword relevance.
        $query_lower = strtolower( $query );
        $words       = preg_split( '/[\s_]+/', $query_lower, -1, PREG_SPLIT_NO_EMPTY );
        $scored      = [];

        foreach ( $all_tools as $tool ) {
            $score      = 0;
            $name_lower = strtolower( $tool['name'] );
            $name_words = explode( '_', $name_lower );
            $desc_lower = strtolower( $tool['description'] ?? '' );
            $desc_words = preg_split( '/[\s,.\-:;()\[\]]+/', $desc_lower, -1, PREG_SPLIT_NO_EMPTY );

            // Exact name match.
            if ( $query_lower === $name_lower ) {
                $score += 100;
            }

            foreach ( $words as $w ) {
                if ( in_array( $w, $name_words, true ) ) {
                    $score += 10;
                }
                if ( in_array( $w, $desc_words, true ) ) {
                    $score += 3;
                }
            }

            if ( $score > 0 ) {
                $scored[] = [ 'score' => $score, 'tool' => $tool ];
            }
        }

        // Sort by score descending, then by name for stability.
        usort( $scored, function ( $a, $b ) {
            return $b['score'] <=> $a['score'] ?: strcmp( $a['tool']['name'], $b['tool']['name'] );
        } );

        // Return top 10.
        $results = array_map( fn( $s ) => $s['tool'], array_slice( $scored, 0, 10 ) );
        return [ 'tools' => $results ];
    }

    /**
     * Return a full tool catalog for the wordpress://tools/catalog resource.
     * Includes category breakdown, tool names, and workflow instructions.
     */
    public static function get_tool_catalog(): array {
        self::load_domains();

        // Build category → tool names map.
        $categories = [];
        foreach ( self::$tool_categories as $tool_name => $cat ) {
            $categories[ $cat ]['tool_names'][] = $tool_name;
        }
        foreach ( $categories as $cat => &$info ) {
            $info['count']       = count( $info['tool_names'] );
            $info['description'] = self::CATEGORY_DESCRIPTIONS[ $cat ] ?? '';
        }
        unset( $info );

        $total = count( self::$tools );

        $workflow = 'Call cowboy_discover to search tools by keyword or category, then cowboy_run to execute them. '
            . "Example: cowboy_discover(query='login failures') → finds wp_wordfence_update_settings → "
            . "cowboy_run(tool='wp_wordfence_update_settings', arguments={settings: {maxFailures: 8}})";

        return [
            'tool_mode'  => 'gateway',
            'total'      => $total,
            'workflow'    => $workflow,
            'categories' => $categories,
        ];
    }

    /**
     * Return gateway catalog: category → count + description (for instructions).
     */
    public static function get_gateway_catalog(): array {
        self::load_domains();
        $counts = [];
        foreach ( self::$tool_categories as $cat ) {
            $counts[ $cat ] = ( $counts[ $cat ] ?? 0 ) + 1;
        }
        $catalog = [];
        foreach ( self::CATEGORY_DESCRIPTIONS as $cat => $desc ) {
            if ( isset( $counts[ $cat ] ) ) {
                $catalog[ $cat ] = [
                    'count'       => $counts[ $cat ],
                    'description' => $desc,
                ];
            }
        }
        return $catalog;
    }

    /* ================================================================
     *  DOMAIN LOADING
     * ================================================================ */

    private static function get_tool_definitions(): array {
        self::load_domains();

        // Inject dry_run and confirm parameters into non-read-only tools.
        if ( ! self::$dry_run_injected ) {
            self::$dry_run_injected = true;
            foreach ( self::$tools as &$tool ) {
                $ro = $tool['annotations']['readOnlyHint'] ?? false;
                if ( ! $ro ) {
                    // Convert stdClass to array only when injecting properties.
                    // tool() uses (object)[] for JSON Schema compliance (serializes as {}),
                    // but array syntax on stdClass is a fatal error in PHP 8.0+.
                    if ( $tool['inputSchema']['properties'] instanceof \stdClass ) {
                        $tool['inputSchema']['properties'] = [];
                    }
                    $tool['inputSchema']['properties']['dry_run'] = [
                        'type'        => 'boolean',
                        'description' => 'If true, preview what this tool would do without making changes.',
                    ];
                }
                $destructive = $tool['annotations']['destructiveHint'] ?? false;
                if ( $destructive ) {
                    $tool['inputSchema']['properties']['confirm'] = [
                        'type'        => 'boolean',
                        'description' => 'Required when safe mode is ON. Set to true to confirm execution of this destructive operation.',
                    ];
                }
            }
            unset( $tool );
        }

        /**
         * Filter the full list of MCP tool definitions.
         *
         * @param array $tools Array of tool definition arrays.
         */
        return apply_filters( 'cowboy_mcp_tools', self::$tools );
    }

    /**
     * Load all domain files once and cache tools + handlers.
     */
    private static function load_domains(): void {
        if ( self::$loaded ) {
            return;
        }
        self::$loaded = true;

        $dir = COWBOY_MCP_PATH . 'includes/tools/';

        foreach ( self::DOMAIN_FILES as $file ) {
            $domain   = require $dir . $file;
            $category = self::CATEGORY_MAP[ $file ] ?? 'system';

            if ( ! empty( $domain['tools'] ) ) {
                array_push( self::$tools, ...$domain['tools'] );
                foreach ( $domain['tools'] as $tool_def ) {
                    self::$tool_map[ $tool_def['name'] ]        = $tool_def;
                    self::$tool_categories[ $tool_def['name'] ] = $category;
                }
            }
            if ( ! empty( $domain['handlers'] ) ) {
                self::$handlers = array_merge( self::$handlers, $domain['handlers'] );
            }
        }
    }
}
