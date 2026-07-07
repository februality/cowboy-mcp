<?php
/**
 * Cowboy MCP – Prompts
 *
 * Implements prompts/list and prompts/get for guided WordPress workflows.
 * Each prompt encodes multi-step expertise that an AI agent can follow.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Prompts {

    /**
     * Handle prompts/list — return all available prompts.
     */
    public static function list_prompts( array $params ): array {
        $prompts = [];
        foreach ( self::definitions() as $name => $def ) {
            $prompt = [
                'name'        => $name,
                'description' => $def['description'],
            ];
            if ( ! empty( $def['arguments'] ) ) {
                $prompt['arguments'] = array_map( function ( $a ) {
                    // Strip internal 'completions' key — not part of the MCP prompt argument spec.
                    unset( $a['completions'] );
                    return $a;
                }, $def['arguments'] );
            }
            $prompts[] = $prompt;
        }
        return [ 'prompts' => $prompts ];
    }

    /**
     * Return the completion values for a prompt argument, or an empty array if none.
     *
     * @param string $prompt Prompt name.
     * @param string $arg    Argument name.
     * @return array List of allowed/suggested values.
     */
    public static function get_argument_completions( string $prompt, string $arg ): array {
        $defs = self::definitions();
        if ( ! isset( $defs[ $prompt ] ) ) return [];
        foreach ( $defs[ $prompt ]['arguments'] ?? [] as $a ) {
            if ( ( $a['name'] ?? '' ) === $arg ) {
                return $a['completions'] ?? [];
            }
        }
        return [];
    }

    /**
     * Handle prompts/get — return a prompt with rendered messages.
     */
    public static function get_prompt( array $params ): array|WP_Error {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];
        $defs = self::definitions();

        if ( ! isset( $defs[ $name ] ) ) {
            return new WP_Error(
                'prompt_not_found',
                "Unknown prompt: {$name}",
                [ 'code' => -32602 ]
            );
        }

        $def      = $defs[ $name ];
        $messages = ( $def['render'] )( $args );

        return [
            'description' => $def['description'],
            'messages'    => $messages,
        ];
    }

    /* ── Prompt definitions ──────────────────────────────────── */

    private static function definitions(): array {
        static $defs = null;
        if ( $defs !== null ) return $defs;

        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url();

        $defs = [

            /* ── 1. Site Audit ──────────────────────────────── */
            'wordpress-site-audit' => [
                'description' => 'Guided health, security, performance, and SEO audit of the WordPress site.',
                'arguments'   => [
                    [
                        'name'        => 'focus',
                        'description' => 'Area to focus on: security, seo, performance, content, plugins, theme, database, or all.',
                        'required'    => false,
                        'completions' => [ 'security', 'seo', 'performance', 'content', 'plugins', 'theme', 'database', 'all' ],
                    ],
                ],
                'render' => function ( array $args ) use ( $site_name, $site_url ): array {
                    $focus = $args['focus'] ?? 'all';
                    $focus_instruction = $focus === 'all'
                        ? 'Perform a comprehensive audit covering all areas below.'
                        : "Focus primarily on **{$focus}**, but note critical issues in other areas.";

                    return [
                        [
                            'role'    => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Audit the WordPress site \"{$site_name}\" at {$site_url}.\n"
                                    . "\n"
                                    . "{$focus_instruction}\n"
                                    . "\n"
                                    . "Follow these steps in order:\n"
                                    . "\n"
                                    . "## Step 1: Gather Site Context\n"
                                    . "- Read the `wordpress://site/info` resource for WordPress version, PHP version, active theme, and plugins.\n"
                                    . "- Read `wordpress://site/wp-config-summary` for configuration constants.\n"
                                    . "- Use `wp_site_info` tool for runtime details.\n"
                                    . "\n"
                                    . "## Step 2: Security Review\n"
                                    . "- Check WordPress and PHP versions against known vulnerabilities.\n"
                                    . "- Review `DISALLOW_FILE_EDIT` and `FORCE_SSL_ADMIN` constants.\n"
                                    . "- List plugins and check for outdated versions with `wp_cli` (command: `plugin list --format=json`).\n"
                                    . "- Check user accounts: look for default \"admin\" username, users with admin role who shouldn't have it.\n"
                                    . "- Review `.htaccess` via `wordpress://site/htaccess` resource.\n"
                                    . "\n"
                                    . "## Step 3: Performance Review\n"
                                    . "- Check `WP_CACHE`, `COMPRESS_CSS`, `COMPRESS_SCRIPTS`, `CONCATENATE_SCRIPTS` constants.\n"
                                    . "- Review `WP_MEMORY_LIMIT` and `WP_MAX_MEMORY_LIMIT`.\n"
                                    . "- Check `WP_POST_REVISIONS` and `AUTOSAVE_INTERVAL` settings.\n"
                                    . "- Use `wp_db_health_report` to count post revisions, transients, and spam comments.\n"
                                    . "- Check for caching plugin presence.\n"
                                    . "\n"
                                    . "## Step 4: SEO Review\n"
                                    . "- Check `blog_public` option (search engine visibility).\n"
                                    . "- Verify `permalink_structure` is SEO-friendly (not plain).\n"
                                    . "- Check for SEO plugin (Yoast or Rank Math) presence.\n"
                                    . "- Review `show_on_front`, `page_on_front` settings.\n"
                                    . "\n"
                                    . "## Step 5: Content Health\n"
                                    . "- Check for draft/pending/trash posts piling up.\n"
                                    . "- Look for orphaned postmeta via database query.\n"
                                    . "- Verify media library organization.\n"
                                    . "\n"
                                    . "## Step 6: Report\n"
                                    . "Present findings in a structured report with:\n"
                                    . "- **Critical** issues requiring immediate action\n"
                                    . "- **Warnings** that should be addressed soon\n"
                                    . "- **Recommendations** for best practices\n"
                                    . "- **Passed** checks that are correctly configured\n"
                                    . "\n"
                                    . "For each issue, provide specific remediation steps using available tools.",
                            ],
                        ],
                    ];
                },
            ],

            /* ── 2. Content Migration ──────────────────────── */
            'content-migration' => [
                'description' => 'Step-by-step content import with validation and rollback guidance.',
                'arguments'   => [
                    [
                        'name'        => 'source_format',
                        'description' => 'Import format: csv, json, xml, wordpress-export, or markdown.',
                        'required'    => true,
                        'completions' => [ 'csv', 'json', 'xml', 'wordpress-export', 'markdown' ],
                    ],
                ],
                'render' => function ( array $args ) use ( $site_name ): array {
                    $format = $args['source_format'] ?? 'csv';

                    return [
                        [
                            'role'    => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Guide me through importing content into \"{$site_name}\" from **{$format}** format.\n"
                                    . "\n"
                                    . "Follow this workflow:\n"
                                    . "\n"
                                    . "## Step 1: Pre-Migration Backup\n"
                                    . "- Recommend creating a backup before proceeding.\n"
                                    . "- Record current post counts by type/status using `wp_db_health_report` (see its `post_counts` field).\n"
                                    . "\n"
                                    . "## Step 2: Analyze Source Data\n"
                                    . "- Ask me to share the source file or describe its structure.\n"
                                    . "- Map source fields to WordPress fields (title, content, excerpt, categories, tags, custom fields).\n"
                                    . "- Identify any data transformations needed.\n"
                                    . "\n"
                                    . "## Step 3: Plan the Import\n"
                                    . "- Determine the target post type and status for imported content.\n"
                                    . "- Plan taxonomy term creation if categories/tags need to be created first.\n"
                                    . "- Identify any media/image URLs that need to be downloaded.\n"
                                    . "\n"
                                    . "## Step 4: Execute Import\n"
                                    . "- Create taxonomy terms first with `wp_cli` (command: `term create`) if needed.\n"
                                    . "- Import posts one at a time or in small batches using `wp_cli` with command `post create`.\n"
                                    . "- For each post, set meta data with `wp_cli` (command: `post meta update`) as needed.\n"
                                    . "- Download and attach media with `wp_upload_media` for any image URLs.\n"
                                    . "- Report progress after each batch.\n"
                                    . "\n"
                                    . "## Step 5: Validate\n"
                                    . "- Compare post counts before and after.\n"
                                    . "- Spot-check 3-5 imported posts for correct field mapping.\n"
                                    . "- Verify taxonomy assignments.\n"
                                    . "- Check for broken media references.\n"
                                    . "\n"
                                    . "## Step 6: Report\n"
                                    . "Provide a migration summary: total imported, any failures, validation results, and next steps.\n"
                                    . "\n"
                                    . "If anything goes wrong, provide guidance on rolling back using the pre-migration backup.",
                            ],
                        ],
                    ];
                },
            ],

            /* ── 3. SEO Optimization ──────────────────────── */
            'seo-optimization' => [
                'description' => 'Analyze and optimize SEO across the WordPress site.',
                'arguments'   => [
                    [
                        'name'        => 'focus',
                        'description' => 'SEO area: on-page, technical, content, schema, or all.',
                        'required'    => false,
                        'completions' => [ 'on-page', 'technical', 'content', 'schema', 'all' ],
                    ],
                ],
                'render' => function ( array $args ) use ( $site_name, $site_url ): array {
                    $focus = $args['focus'] ?? 'all';

                    return [
                        [
                            'role'    => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Optimize SEO for \"{$site_name}\" at {$site_url}. Focus: **{$focus}**.\n"
                                    . "\n"
                                    . "## Step 1: SEO Plugin Check\n"
                                    . "- Check if Yoast SEO or Rank Math is active via `wp_cli` (command: `plugin list --format=json`).\n"
                                    . "- If an SEO plugin is active, use `wp_seo_*` tools for meta management.\n"
                                    . "- If no SEO plugin, recommend installing one and focus on what's possible with core tools.\n"
                                    . "\n"
                                    . "## Step 2: Technical SEO\n"
                                    . "- Verify `permalink_structure` is not plain (`?p=123`). Recommend `/%postname%/` or similar.\n"
                                    . "- Check `blog_public` option -- ensure search engines are not blocked.\n"
                                    . "- Review `.htaccess` for redirect rules.\n"
                                    . "- Check if XML sitemaps are generated (SEO plugin or WP core sitemaps).\n"
                                    . "- Verify the site has `FORCE_SSL_ADMIN` / HTTPS.\n"
                                    . "\n"
                                    . "## Step 3: On-Page SEO Audit\n"
                                    . "- Query recent published posts: `wp_cli` with command `post list --post_status=publish --posts_per_page=20 --format=json`.\n"
                                    . "- For each post, check:\n"
                                    . "  - Title length (50-60 chars ideal)\n"
                                    . "  - Has an excerpt/meta description set\n"
                                    . "  - URL slug is descriptive and concise\n"
                                    . "  - Content length (thin content < 300 words)\n"
                                    . "- If SEO plugin is active, use `wp_seo_get_meta` to check focus keywords, SEO scores.\n"
                                    . "\n"
                                    . "## Step 4: Content Optimization\n"
                                    . "- Identify posts with missing or short meta descriptions.\n"
                                    . "- Find posts without featured images.\n"
                                    . "- Check for duplicate titles.\n"
                                    . "- Identify pages with very thin content.\n"
                                    . "\n"
                                    . "## Step 5: Schema Markup\n"
                                    . "- If SEO plugin supports schema, review current schema settings via `wp_cli` (command: `post meta list <id> --format=json` filtered for schema keys).\n"
                                    . "- Recommend appropriate schema types for the site's content.\n"
                                    . "\n"
                                    . "## Step 6: Action Plan\n"
                                    . "Present a prioritized list of SEO improvements:\n"
                                    . "1. **Critical** -- Blocking indexing or causing SEO penalties\n"
                                    . "2. **High Impact** -- Quick wins with significant SEO benefit\n"
                                    . "3. **Medium** -- Improvements for incremental gains\n"
                                    . "4. **Low Priority** -- Nice-to-have optimizations\n"
                                    . "\n"
                                    . "For each item, provide the specific tool call to fix it.",
                            ],
                        ],
                    ];
                },
            ],

            /* ── 4. WooCommerce Store Setup ─────────────── */
            'woocommerce-store-setup' => [
                'description' => 'Guided WooCommerce store configuration and optimization.',
                'arguments'   => [
                    [
                        'name'        => 'focus',
                        'description' => 'Setup area: products, payments, shipping, taxes, emails, or all.',
                        'required'    => false,
                        'completions' => [ 'products', 'payments', 'shipping', 'taxes', 'emails', 'all' ],
                    ],
                ],
                'render' => function ( array $args ) use ( $site_name ): array {
                    $focus = $args['focus'] ?? 'all';

                    return [
                        [
                            'role'    => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Configure the WooCommerce store on \"{$site_name}\". Focus: **{$focus}**.\n"
                                    . "\n"
                                    . "**Prerequisite:** Verify WooCommerce is active with `wp_cli` (command: `plugin list --format=json`). If not active, stop and inform the user.\n"
                                    . "\n"
                                    . "## Step 1: Current Store Status\n"
                                    . "- Read `woocommerce://store/info` resource for current configuration.\n"
                                    . "- Read `woocommerce://products/schema` for product types and attributes.\n"
                                    . "- Read `woocommerce://shipping/zones` for shipping configuration.\n"
                                    . "- Use `wp_woo_sales_report` to check if the store has existing orders.\n"
                                    . "\n"
                                    . "## Step 2: Store Basics (if focus is \"all\")\n"
                                    . "- Review currency, weight/dimension units, store address.\n"
                                    . "- Check tax calculation settings.\n"
                                    . "- Verify payment gateways are configured.\n"
                                    . "\n"
                                    . "## Step 3: Products Setup\n"
                                    . "- Review existing product types and categories with `wp_woo_list_products`.\n"
                                    . "- Check product attributes configuration.\n"
                                    . "- Verify stock management settings.\n"
                                    . "- Identify products missing key data (images, descriptions, prices, SKUs).\n"
                                    . "\n"
                                    . "## Step 4: Payments\n"
                                    . "- List payment gateways with `wp_woo_list_payment_gateways`.\n"
                                    . "- Verify at least one gateway is enabled and configured.\n"
                                    . "- Review gateway settings for test/live mode.\n"
                                    . "\n"
                                    . "## Step 5: Shipping\n"
                                    . "- Review shipping zones and methods.\n"
                                    . "- Verify shipping rates are configured for target markets.\n"
                                    . "- Check for free shipping thresholds.\n"
                                    . "\n"
                                    . "## Step 6: Taxes\n"
                                    . "- Check if tax calculation is enabled.\n"
                                    . "- Review tax rates with `wp_woo_list_tax_rates`.\n"
                                    . "- Verify tax display settings (inclusive/exclusive).\n"
                                    . "\n"
                                    . "## Step 7: Summary & Recommendations\n"
                                    . "Present a store readiness checklist:\n"
                                    . "- [ ] Store address configured\n"
                                    . "- [ ] Currency and units set\n"
                                    . "- [ ] At least one payment gateway active\n"
                                    . "- [ ] Shipping zones configured\n"
                                    . "- [ ] Tax settings reviewed\n"
                                    . "- [ ] Products have required fields\n"
                                    . "- [ ] Email notifications configured\n"
                                    . "\n"
                                    . "Flag any blockers preventing the store from going live.",
                            ],
                        ],
                    ];
                },
            ],

            /* ── 5. Troubleshoot Issue ──────────────────── */
            'troubleshoot-issue' => [
                'description' => 'Diagnostic workflow for common WordPress problems.',
                'arguments'   => [
                    [
                        'name'        => 'symptom',
                        'description' => 'The symptom: white-screen, slow-loading, error-500, redirect-loop, plugin-conflict, database-error, memory-limit, or permission-denied.',
                        'required'    => true,
                        'completions' => [ 'white-screen', 'slow-loading', 'error-500', 'redirect-loop', 'plugin-conflict', 'database-error', 'memory-limit', 'permission-denied' ],
                    ],
                ],
                'render' => function ( array $args ) use ( $site_name ): array {
                    $symptom = $args['symptom'] ?? 'error-500';

                    $diagnostic_steps = match ( $symptom ) {
                        'white-screen' => "## Symptom-Specific Diagnosis: White Screen of Death (WSOD)\n"
                            . "\n"
                            . "1. **Check WP_DEBUG**: Read `wordpress://site/wp-config-summary` -- is `WP_DEBUG` enabled?\n"
                            . "   - If not, recommend enabling it to see the actual error.\n"
                            . "2. **Check PHP error log**: Use `wp_read_file` on the PHP error log path if accessible.\n"
                            . "3. **Memory limit**: Check `WP_MEMORY_LIMIT` -- increase to at least `256M` if low.\n"
                            . "4. **Plugin conflict**: Deactivate all plugins with `wp_cli` (command: `plugin deactivate <slug>`) one by one.\n"
                            . "   - Start with recently updated/installed plugins.\n"
                            . "   - Reactivate one at a time to isolate the conflict.\n"
                            . "5. **Theme issue**: Try switching to a default theme (Twenty Twenty-Four) with `wp_cli` (command: `theme activate twentytwentyfour`).",
                        'slow-loading' => "## Symptom-Specific Diagnosis: Slow Loading\n"
                            . "\n"
                            . "1. **Database health**: Run `wp_db_health_report` -- check `revisions` for excessive revisions, and `autoload_options`/`autoload_bytes` for autoloaded options bloat.\n"
                            . "2. **Plugin audit**: List plugins with `wp_cli` (command: `plugin list --format=json`) -- look for known performance-heavy plugins.\n"
                            . "3. **Cron jobs**: Check with `wp_cli` (command: `cron event list --format=json`) for excessive scheduled events.\n"
                            . "4. **Object cache**: Check if `WP_CACHE` is enabled and an object cache plugin is active.\n"
                            . "5. **Query analysis**: Use `wp_db_show_processlist` to check for slow queries.",
                        'error-500' => "## Symptom-Specific Diagnosis: 500 Internal Server Error\n"
                            . "\n"
                            . "1. **Check .htaccess**: Read `wordpress://site/htaccess` -- look for syntax errors or corrupted rules.\n"
                            . "   - Try regenerating with `wp_update_option` on `permalink_structure` (re-save same value).\n"
                            . "2. **PHP version**: Check PHP version in `wp_site_info` -- ensure compatibility.\n"
                            . "3. **Memory limit**: Check `WP_MEMORY_LIMIT` in wp-config summary.\n"
                            . "4. **Plugin conflict**: Deactivate plugins starting with most recently changed.\n"
                            . "5. **File permissions**: Use `wp_list_files` on key directories to check for permission issues.\n"
                            . "6. **PHP error log**: Check for fatal errors via `wp_read_file` on error log.",
                        'redirect-loop' => "## Symptom-Specific Diagnosis: Redirect Loop\n"
                            . "\n"
                            . "1. **URL mismatch**: Use `wp_cli` (command: `option get siteurl` and `option get home`) to check both values -- they should match.\n"
                            . "2. **SSL conflict**: Check if `FORCE_SSL_ADMIN` is set but SSL isn't properly configured.\n"
                            . "3. **.htaccess rules**: Read `wordpress://site/htaccess` for conflicting redirect rules.\n"
                            . "4. **Plugin redirects**: Check for redirect/cache plugins that might be misconfigured.\n"
                            . "5. **WordPress Address**: Verify `siteurl` and `home` use the same protocol (both http or both https).",
                        'plugin-conflict' => "## Symptom-Specific Diagnosis: Plugin Conflict\n"
                            . "\n"
                            . "1. **List all plugins**: Use `wp_cli` (command: `plugin list --format=json`) to see all active plugins with versions.\n"
                            . "2. **Recent changes**: Ask the user what was recently installed, updated, or changed.\n"
                            . "3. **Binary search**: Deactivate half the plugins, test, then narrow down:\n"
                            . "   - Use `wp_cli` (command: `plugin deactivate <slug>`) for each plugin in the test group.\n"
                            . "   - If issue resolves, reactivate half of the deactivated group.\n"
                            . "   - Continue until the conflicting plugin is isolated.\n"
                            . "4. **Check for duplicate functionality**: Look for multiple plugins doing the same thing (caching, SEO, security).\n"
                            . "5. **Version compatibility**: Cross-reference plugin versions with WordPress version.",
                        'database-error' => "## Symptom-Specific Diagnosis: Database Error\n"
                            . "\n"
                            . "1. **Connection test**: Call `wp_db_list_tables` -- if it returns data with no `db_error`, connectivity is fine.\n"
                            . "2. **Table status**: Run `wp_db_list_tables` to check all tables are present.\n"
                            . "3. **Table repair**: Use `wp_db_check_table` on suspected tables.\n"
                            . "4. **Corruption check**: Run `wp_db_repair_table` on corrupted tables.\n"
                            . "5. **Options table**: Check autoload data size via `wp_db_health_report` (`autoload_bytes`) -- bloated `wp_options` is a common issue.",
                        'memory-limit' => "## Symptom-Specific Diagnosis: Memory Limit Exhaustion\n"
                            . "\n"
                            . "1. **Current limits**: Read `wordpress://site/wp-config-summary` -- check `WP_MEMORY_LIMIT` and `WP_MAX_MEMORY_LIMIT`.\n"
                            . "2. **PHP limit**: Check `memory_limit` in `wp_site_info` PHP info.\n"
                            . "3. **Heavy plugins**: List plugins with `wp_cli` (command: `plugin list --format=json`) -- identify resource-heavy ones.\n"
                            . "4. **Increase limits**: Recommend setting `WP_MEMORY_LIMIT` to `256M` and `WP_MAX_MEMORY_LIMIT` to `512M`.\n"
                            . "5. **Find the cause**: The memory limit is a symptom -- identify which plugin or theme is consuming excessive memory.",
                        'permission-denied' => "## Symptom-Specific Diagnosis: Permission Denied\n"
                            . "\n"
                            . "1. **File permissions**: Use `wp_list_files` on `wp-content/` to check directory permissions.\n"
                            . "2. **Ownership**: Check if WordPress can write to `wp-content/uploads/` using `wp_write_file` test.\n"
                            . "3. **DISALLOW_FILE_EDIT**: Check `wordpress://site/wp-config-summary` -- is file editing disabled?\n"
                            . "4. **DISALLOW_FILE_MODS**: If set to true, plugin/theme installs will fail.\n"
                            . "5. **User capabilities**: Check if the authenticated user has the right role with `wp_cli` (command: `user list --format=json`).",
                        default => "## General Diagnosis\n\nGather site info and describe the specific symptom for targeted troubleshooting.",
                    };

                    return [
                        [
                            'role'    => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Troubleshoot an issue on \"{$site_name}\". Reported symptom: **{$symptom}**.\n"
                                    . "\n"
                                    . "## Step 1: Gather Baseline Info\n"
                                    . "- Read `wordpress://site/info` for WordPress version, PHP version, active plugins, theme.\n"
                                    . "- Read `wordpress://site/wp-config-summary` for configuration constants.\n"
                                    . "- Note any recent changes the user reports.\n"
                                    . "\n"
                                    . "{$diagnostic_steps}\n"
                                    . "\n"
                                    . "## Resolution Steps\n"
                                    . "After identifying the root cause:\n"
                                    . "1. Explain what's wrong and why in plain language.\n"
                                    . "2. Propose a fix with the specific tool calls needed.\n"
                                    . "3. Ask for confirmation before making any changes.\n"
                                    . "4. After applying the fix, verify the issue is resolved.\n"
                                    . "5. Recommend preventive measures to avoid recurrence.",
                            ],
                        ],
                    ];
                },
            ],

            /* ── 6. Bulk Content Update ────────────────── */
            'bulk-content-update' => [
                'description' => 'Guided batch content operations with preview and safety controls.',
                'arguments'   => [
                    [
                        'name'        => 'post_type',
                        'description' => 'The post type to operate on (e.g. post, page, product).',
                        'required'    => false,
                        // completions intentionally omitted — dynamic via get_post_type_names()
                    ],
                ],
                'render' => function ( array $args ) use ( $site_name ): array {
                    $post_type = $args['post_type'] ?? 'post';

                    return [
                        [
                            'role'    => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Guide me through a bulk content update on \"{$site_name}\" for post type: **{$post_type}**.\n"
                                    . "\n"
                                    . "## Step 1: Scope the Update\n"
                                    . "Ask me what I want to change. Common operations:\n"
                                    . "- Update status (draft -> publish, publish -> draft)\n"
                                    . "- Change categories or tags\n"
                                    . "- Update meta fields\n"
                                    . "- Search and replace in content\n"
                                    . "- Add/remove content patterns\n"
                                    . "- Update authors\n"
                                    . "\n"
                                    . "## Step 2: Identify Affected Posts\n"
                                    . "- Use `wp_cli` (command: `post list` with appropriate filters and `--format=json`) to find matching posts.\n"
                                    . "- Show me the count and a sample of 5 posts that would be affected.\n"
                                    . "- Confirm the scope before proceeding.\n"
                                    . "\n"
                                    . "## Step 3: Preview Changes\n"
                                    . "- For the first 3 posts, show a before/after preview of the proposed changes.\n"
                                    . "- If using search-replace, use `wp_search_replace` with `dry_run: true` first.\n"
                                    . "- Confirm I want to proceed.\n"
                                    . "\n"
                                    . "## Step 4: Execute in Batches\n"
                                    . "- Process posts in batches of 10-20.\n"
                                    . "- After each batch, report progress: \"{N} of {total} updated.\"\n"
                                    . "- If any individual update fails, log it and continue with the rest.\n"
                                    . "- Use the appropriate tool for each operation type:\n"
                                    . "  - `wp_cli` with command `post update` for post fields\n"
                                    . "  - `wp_cli` with command `post meta update` for meta\n"
                                    . "  - `wp_search_replace` for content patterns\n"
                                    . "\n"
                                    . "## Step 5: Verification\n"
                                    . "- Re-query the affected posts to confirm changes applied.\n"
                                    . "- Spot-check 3 posts in detail.\n"
                                    . "- Report final results: total updated, any failures, verification status.\n"
                                    . "\n"
                                    . "**Safety:** Always confirm before executing. If more than 50 posts are affected, ask me to confirm in writing that I want to proceed with the bulk operation.",
                            ],
                        ],
                    ];
                },
            ],

            /* ── 7. Security Hardening ─────────────────── */
            'security-hardening' => [
                'description' => 'Review and apply WordPress security best practices.',
                'arguments'   => [
                    [
                        'name'        => 'level',
                        'description' => 'Hardening level: basic, intermediate, advanced, or audit-only.',
                        'required'    => false,
                        'completions' => [ 'basic', 'intermediate', 'advanced', 'audit-only' ],
                    ],
                ],
                'render' => function ( array $args ) use ( $site_name, $site_url ): array {
                    $level = $args['level'] ?? 'basic';

                    return [
                        [
                            'role'    => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Perform security hardening on \"{$site_name}\" at {$site_url}. Level: **{$level}**.\n"
                                    . "\n"
                                    . "## Step 1: Security Audit\n"
                                    . "Gather current security posture:\n"
                                    . "- Read `wordpress://site/info` -- WordPress version, PHP version.\n"
                                    . "- Read `wordpress://site/wp-config-summary` -- check security-related constants.\n"
                                    . "- Read `wordpress://site/htaccess` -- review server-level rules.\n"
                                    . "- Use `wp_cli` (command: `plugin list --format=json`) -- check for security plugins, outdated plugins.\n"
                                    . "- Use `wp_cli` (command: `user list --format=json`) -- review user accounts and roles.\n"
                                    . "\n"
                                    . "## Step 2: Basic Hardening (all levels)\n"
                                    . "Check and recommend:\n"
                                    . "- [ ] WordPress core is up to date\n"
                                    . "- [ ] All plugins are up to date\n"
                                    . "- [ ] All themes are up to date\n"
                                    . "- [ ] No default \"admin\" username exists\n"
                                    . "- [ ] `users_can_register` is disabled (unless intentional)\n"
                                    . "- [ ] `default_role` is \"subscriber\" (not editor/admin)\n"
                                    . "- [ ] `blog_public` is intentionally set\n"
                                    . "- [ ] Strong admin passwords (cannot verify, but remind)\n"
                                    . "\n"
                                    . "## Step 3: Intermediate Hardening\n"
                                    . "- [ ] `DISALLOW_FILE_EDIT` should be `true` -- prevents theme/plugin editing from dashboard\n"
                                    . "- [ ] `FORCE_SSL_ADMIN` should be `true`\n"
                                    . "- [ ] Remove inactive themes (keep one default as fallback)\n"
                                    . "- [ ] Remove inactive plugins\n"
                                    . "- [ ] Database table prefix is not the default `wp_`\n"
                                    . "- [ ] XML-RPC is disabled if not needed (check .htaccess or plugin)\n"
                                    . "- [ ] Review REST API exposure\n"
                                    . "\n"
                                    . "## Step 4: Advanced Hardening\n"
                                    . "- [ ] `DISALLOW_FILE_MODS` -- prevents all file modifications\n"
                                    . "- [ ] Limit login attempts (check for plugin)\n"
                                    . "- [ ] Two-factor authentication (check for plugin)\n"
                                    . "- [ ] Security headers in .htaccess (X-Content-Type-Options, X-Frame-Options, etc.)\n"
                                    . "- [ ] Directory listing disabled\n"
                                    . "- [ ] wp-config.php moved above web root or protected\n"
                                    . "- [ ] Regular backup schedule configured\n"
                                    . "\n"
                                    . "## Step 5: Report\n"
                                    . "Present findings as a security scorecard:\n"
                                    . "- **Score**: X/Y checks passed\n"
                                    . "- **Critical**: Issues requiring immediate action\n"
                                    . "- **Recommended**: Important improvements\n"
                                    . "- **Optional**: Advanced hardening measures\n"
                                    . "\n"
                                    . "For **audit-only** level: report findings without making changes.\n"
                                    . "For other levels: propose specific changes and ask for confirmation before applying each one.",
                            ],
                        ],
                    ];
                },
            ],

            /* ── 8. Performance Optimization ──────────── */
            'performance-optimization' => [
                'description' => 'Identify and fix WordPress performance bottlenecks.',
                'arguments'   => [
                    [
                        'name'        => 'area',
                        'description' => 'Focus area: database, caching, assets, images, queries, or all.',
                        'required'    => false,
                        'completions' => [ 'database', 'caching', 'assets', 'images', 'queries', 'all' ],
                    ],
                ],
                'render' => function ( array $args ) use ( $site_name ): array {
                    $area = $args['area'] ?? 'all';

                    return [
                        [
                            'role'    => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Optimize performance for \"{$site_name}\". Focus area: **{$area}**.\n"
                                    . "\n"
                                    . "## Step 1: Performance Baseline\n"
                                    . "- Read `wordpress://site/info` for PHP version, memory limits.\n"
                                    . "- Read `wordpress://site/wp-config-summary` for cache and debug constants.\n"
                                    . "- Use `wp_cli` (command: `plugin list --format=json`) to identify caching and performance plugins.\n"
                                    . "\n"
                                    . "## Step 2: Database Optimization\n"
                                    . "Call `wp_db_health_report` for a single diagnostic snapshot: post revisions, auto-drafts, spam/trashed comments, transient count, autoloaded-options size, and orphaned postmeta. If queries themselves seem slow, also check `wp_db_show_processlist`.\n"
                                    . "\n"
                                    . "Report findings and recommend cleanup actions.\n"
                                    . "\n"
                                    . "## Step 3: Caching Review\n"
                                    . "- Is `WP_CACHE` defined and true?\n"
                                    . "- Is an object cache plugin active (Redis, Memcached)?\n"
                                    . "- Is a page cache plugin active (WP Rocket, LiteSpeed, W3TC)?\n"
                                    . "- If cache tools available, use `wp_cache_detect_provider` and `wp_cache_get_settings`.\n"
                                    . "\n"
                                    . "## Step 4: Asset Optimization\n"
                                    . "- Check `COMPRESS_CSS` and `COMPRESS_SCRIPTS` constants.\n"
                                    . "- Check `CONCATENATE_SCRIPTS` setting.\n"
                                    . "- Review active plugins for CSS/JS optimization features.\n"
                                    . "\n"
                                    . "## Step 5: Image Optimization\n"
                                    . "- Query for unoptimized large images:\n"
                                    . "  `SELECT COUNT(*) FROM wp_posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'`\n"
                                    . "- Check if image optimization plugin is active.\n"
                                    . "- Verify thumbnail sizes are appropriate.\n"
                                    . "\n"
                                    . "## Step 6: Cron Review\n"
                                    . "- Use `wp_cli` (command: `cron event list --format=json`) to identify excessive or stuck cron jobs.\n"
                                    . "- Look for cron events running too frequently.\n"
                                    . "\n"
                                    . "## Step 7: Action Plan\n"
                                    . "Present findings with estimated impact:\n"
                                    . "- **High Impact** -- Changes that will make the biggest difference\n"
                                    . "- **Medium Impact** -- Worthwhile optimizations\n"
                                    . "- **Low Impact** -- Minor improvements\n"
                                    . "\n"
                                    . "For each item, provide the specific tool call or configuration change.\n"
                                    . "Include estimated cleanup numbers (e.g., \"Delete 5,432 post revisions to save ~50MB\").",
                            ],
                        ],
                    ];
                },
            ],

        ];

        return $defs;
    }
}
