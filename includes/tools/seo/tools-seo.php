<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Guard — return empty when no supported SEO plugin active.
 * ================================================================ */

if ( ! Cowboy_MCP_Tools::domain_available( __FILE__ ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Provider detection — precedence Yoast > Rank Math > AIOSEO > SEOPress
 * ================================================================ */

/** Every active supported SEO plugin, in precedence order. */
function cowboy_mcp_seo_active_providers(): array {
    $out = [];
    if ( class_exists( 'WPSEO_Options' ) ) {
        $out[] = [ 'provider' => 'yoast', 'version' => defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : 'unknown' ];
    }
    if ( defined( 'RANK_MATH_VERSION' ) ) {
        $out[] = [ 'provider' => 'rank-math', 'version' => (string) RANK_MATH_VERSION ];
    }
    if ( defined( 'AIOSEO_VERSION' ) ) {
        $out[] = [ 'provider' => 'aioseo', 'version' => (string) AIOSEO_VERSION ];
    }
    if ( defined( 'SEOPRESS_VERSION' ) ) {
        $out[] = [ 'provider' => 'seopress', 'version' => (string) SEOPRESS_VERSION ];
    }
    return $out;
}

/** The provider Cowboy reads/writes (first by precedence), or null. */
function cowboy_mcp_seo_get_provider(): ?array {
    return cowboy_mcp_seo_active_providers()[0] ?? null;
}

/** Canonical field → value type ('text' | 'url' | 'bool'). */
const COWBOY_MCP_SEO_FIELDS = [
    'title' => 'text', 'description' => 'text', 'focus_keyword' => 'text',
    'noindex' => 'bool', 'nofollow' => 'bool', 'canonical_url' => 'url',
    'og_title' => 'text', 'og_description' => 'text', 'og_image' => 'url',
    'twitter_title' => 'text', 'twitter_description' => 'text', 'twitter_image' => 'url',
    'cornerstone' => 'bool',
];

require_once __DIR__ . '/adapters.php';

/**
 * Meta-map adapter (Yoast, Rank Math, SEOPress). $map: canonical field →
 * ['key' => meta key, 'type' => 'flag'|'rm_robots'|'url'|absent(text), 'on' => stored
 * value for flags, 'token' => rank_math_robots token, 'companion' => attachment-id
 * key, 'twitter_custom' => Rank Math use_facebook switch, 'extra_clear' => keys
 * deleted whenever the field changes]. Fields missing from $map are unsupported.
 */
function cowboy_mcp_seo_meta_adapter( string $slug, array $map, callable $scores, callable $refresh ): array {
    $read = static function ( int $post_id ) use ( $map ): array {
        $fields = [];
        foreach ( COWBOY_MCP_SEO_FIELDS as $name => $vtype ) {
            if ( ! isset( $map[ $name ] ) ) {
                $fields[ $name ] = null; // unsupported by this provider
                continue;
            }
            $def = $map[ $name ];
            switch ( $def['type'] ?? 'text' ) {
                case 'flag':
                    $fields[ $name ] = get_post_meta( $post_id, $def['key'], true ) === $def['on'];
                    break;
                case 'rm_robots':
                    $robots          = get_post_meta( $post_id, $def['key'], true );
                    $fields[ $name ] = is_array( $robots ) && in_array( $def['token'], $robots, true );
                    break;
                default:
                    $raw             = get_post_meta( $post_id, $def['key'], true );
                    $fields[ $name ] = ( $raw === '' || $raw === false ) ? null : (string) $raw;
            }
        }
        return $fields;
    };

    $write_one = static function ( int $post_id, string $field, string|bool $value ) use ( $map ): void {
        $def  = $map[ $field ];
        $type = $def['type'] ?? 'text';
        foreach ( (array) ( $def['extra_clear'] ?? [] ) as $k ) {
            delete_post_meta( $post_id, $k );
        }
        if ( $type === 'flag' ) {
            if ( $value ) {
                update_post_meta( $post_id, $def['key'], $def['on'] );
            } else {
                delete_post_meta( $post_id, $def['key'] );
            }
            return;
        }
        if ( $type === 'rm_robots' ) {
            // One shared array — touch only our token, keep noarchive/nosnippet/etc.
            $robots = get_post_meta( $post_id, $def['key'], true );
            $robots = is_array( $robots ) ? array_values( array_diff( $robots, [ $def['token'] ] ) ) : [];
            if ( $value ) {
                $robots[] = $def['token'];
            }
            if ( $robots ) {
                update_post_meta( $post_id, $def['key'], $robots );
            } else {
                delete_post_meta( $post_id, $def['key'] );
            }
            return;
        }
        if ( $value === '' ) {
            delete_post_meta( $post_id, $def['key'] );
            if ( ! empty( $def['companion'] ) ) {
                delete_post_meta( $post_id, $def['companion'] );
            }
            return;
        }
        update_post_meta( $post_id, $def['key'], $value );
        if ( ! empty( $def['companion'] ) ) {
            // Keep the attachment-id companion in sync — a stale id outranks the URL.
            $att_id = attachment_url_to_postid( (string) $value );
            if ( $att_id ) {
                update_post_meta( $post_id, $def['companion'], (string) $att_id );
            } else {
                delete_post_meta( $post_id, $def['companion'] );
            }
        }
        if ( ! empty( $def['twitter_custom'] ) ) {
            update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'off' );
        }
    };

    return [
        'provider'   => $slug,
        'supports'   => array_keys( $map ),
        'read'       => $read,
        'write_many' => static function ( int $post_id, array $writes ) use ( $write_one ): array {
            foreach ( $writes as $field => $value ) {
                $write_one( $post_id, $field, $value );
            }
            return [];
        },
        'scores'     => $scores,
        'refresh'    => $refresh,
    ];
}

/** Adapter for the active provider, or null when none. */
function cowboy_mcp_seo_adapter(): ?array {
    $provider = cowboy_mcp_seo_get_provider()['provider'] ?? null;
    return match ( $provider ) {
        'yoast'     => cowboy_mcp_seo_meta_adapter( 'yoast', [
            'title'               => [ 'key' => '_yoast_wpseo_title' ],
            'description'         => [ 'key' => '_yoast_wpseo_metadesc' ],
            'focus_keyword'       => [ 'key' => '_yoast_wpseo_focuskw' ],
            'noindex'             => [ 'key' => '_yoast_wpseo_meta-robots-noindex', 'type' => 'flag', 'on' => '1' ],
            'nofollow'            => [ 'key' => '_yoast_wpseo_meta-robots-nofollow', 'type' => 'flag', 'on' => '1' ],
            'canonical_url'       => [ 'key' => '_yoast_wpseo_canonical', 'type' => 'url' ],
            'og_title'            => [ 'key' => '_yoast_wpseo_opengraph-title' ],
            'og_description'      => [ 'key' => '_yoast_wpseo_opengraph-description' ],
            'og_image'            => [ 'key' => '_yoast_wpseo_opengraph-image', 'type' => 'url', 'companion' => '_yoast_wpseo_opengraph-image-id' ],
            'twitter_title'       => [ 'key' => '_yoast_wpseo_twitter-title' ],
            'twitter_description' => [ 'key' => '_yoast_wpseo_twitter-description' ],
            'twitter_image'       => [ 'key' => '_yoast_wpseo_twitter-image', 'type' => 'url', 'companion' => '_yoast_wpseo_twitter-image-id' ],
            'cornerstone'         => [ 'key' => '_yoast_wpseo_is_cornerstone', 'type' => 'flag', 'on' => '1' ],
        ], 'cowboy_mcp_seo_yoast_scores', 'cowboy_mcp_seo_yoast_refresh' ),
        'rank-math' => cowboy_mcp_seo_meta_adapter( 'rank-math', [
            'title'               => [ 'key' => 'rank_math_title' ],
            'description'         => [ 'key' => 'rank_math_description' ],
            'focus_keyword'       => [ 'key' => 'rank_math_focus_keyword' ],
            'noindex'             => [ 'key' => 'rank_math_robots', 'type' => 'rm_robots', 'token' => 'noindex' ],
            'nofollow'            => [ 'key' => 'rank_math_robots', 'type' => 'rm_robots', 'token' => 'nofollow' ],
            'canonical_url'       => [ 'key' => 'rank_math_canonical_url', 'type' => 'url' ],
            'og_title'            => [ 'key' => 'rank_math_facebook_title' ],
            'og_description'      => [ 'key' => 'rank_math_facebook_description' ],
            'og_image'            => [ 'key' => 'rank_math_facebook_image', 'type' => 'url', 'companion' => 'rank_math_facebook_image_id' ],
            'twitter_title'       => [ 'key' => 'rank_math_twitter_title', 'twitter_custom' => true ],
            'twitter_description' => [ 'key' => 'rank_math_twitter_description', 'twitter_custom' => true ],
            'twitter_image'       => [ 'key' => 'rank_math_twitter_image', 'type' => 'url', 'companion' => 'rank_math_twitter_image_id', 'twitter_custom' => true ],
            'cornerstone'         => [ 'key' => 'rank_math_pillar_content', 'type' => 'flag', 'on' => 'on' ],
        ], 'cowboy_mcp_seo_rank_math_scores', '__return_true' ),
        'seopress'  => function_exists( 'cowboy_mcp_seo_seopress_adapter' ) ? cowboy_mcp_seo_seopress_adapter() : null,
        'aioseo'    => function_exists( 'cowboy_mcp_seo_aioseo_adapter' ) ? cowboy_mcp_seo_aioseo_adapter() : null,
        default     => null,
    };
}

function cowboy_mcp_seo_yoast_scores( int $post_id ): array {
    $seo  = get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );
    $read = get_post_meta( $post_id, '_yoast_wpseo_content_score', true );
    return [ 'seo_score' => $seo === '' ? null : (int) $seo, 'readability_score' => $read === '' ? null : (int) $read ];
}

function cowboy_mcp_seo_rank_math_scores( int $post_id ): array {
    $seo = get_post_meta( $post_id, 'rank_math_seo_score', true );
    return [ 'seo_score' => $seo === '' ? null : (int) $seo, 'readability_score' => null ];
}

/**
 * Yoast ≥14 serves frontend meta from its indexables table, rebuilt on post
 * save — not on direct postmeta writes. Rebuild it explicitly. Never fatal: a
 * false return surfaces as provider_cache_refreshed in the tool response.
 */
function cowboy_mcp_seo_yoast_refresh( int $post_id ): bool {
    if ( ! function_exists( 'YoastSEO' ) ) {
        return false;
    }
    try {
        $container = YoastSEO()->classes;
        $builder   = $container->get( 'Yoast\WP\SEO\Builders\Indexable_Builder' );
        if ( $builder && method_exists( $builder, 'build_for_id_and_type' ) ) {
            $builder->build_for_id_and_type( $post_id, 'post' );
            return true;
        }
        $repo = $container->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
        if ( $repo && $builder && method_exists( $repo, 'find_by_id_and_type' ) && method_exists( $builder, 'build' ) ) {
            $indexable = $repo->find_by_id_and_type( $post_id, 'post', false );
            if ( $indexable ) {
                $builder->build( $indexable );
                return true;
            }
        }
        return false;
    } catch ( \Throwable $e ) {
        return false;
    }
}

/** Canonical fields for a post via the active adapter. */
function cowboy_mcp_seo_read_fields( int $post_id ): array {
    $adapter = cowboy_mcp_seo_adapter();
    return $adapter ? ( $adapter['read'] )( $post_id ) : [];
}

/** Provider-computed scores via the active adapter. */
function cowboy_mcp_seo_read_scores( int $post_id ): array {
    $adapter = cowboy_mcp_seo_adapter();
    return $adapter ? ( $adapter['scores'] )( $post_id ) : [ 'seo_score' => null, 'readability_score' => null ];
}

/**
 * Fixed audit rules. A missing custom title is deliberately not an issue —
 * title templates always render one; description templates rarely produce
 * good output, so a missing description is. Title length is only judged when
 * a custom title is set and contains no template variables ('%').
 */
function cowboy_mcp_seo_audit_issues( WP_Post $post, array $fields ): array {
    $issues = [];

    $desc = $fields['description'];
    if ( $desc === null || $desc === '' ) {
        $issues[] = [ 'code' => 'missing_description', 'severity' => 'warning', 'message' => 'No meta description set.' ];
    } else {
        $len = mb_strlen( $desc );
        if ( $len > 160 || $len < 50 ) {
            $issues[] = [ 'code' => 'description_length', 'severity' => 'warning', 'message' => "Meta description is {$len} characters (recommended 50-160)." ];
        }
    }

    if ( $fields['focus_keyword'] === null || $fields['focus_keyword'] === '' ) {
        $issues[] = [ 'code' => 'missing_focus_keyword', 'severity' => 'warning', 'message' => 'No focus keyword set.' ];
    }

    $title = $fields['title'];
    if ( $title !== null && strpos( $title, '%' ) === false && mb_strlen( $title ) > 60 ) {
        $issues[] = [ 'code' => 'title_length', 'severity' => 'warning', 'message' => 'Custom SEO title is ' . mb_strlen( $title ) . ' characters (recommended at most 60).' ];
    }

    if ( $fields['noindex'] && $post->post_status === 'publish' ) {
        $issues[] = [ 'code' => 'noindex_on_published', 'severity' => 'notice', 'message' => 'Post is published but set to noindex.' ];
    }

    return $issues;
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_seo_get_provider', '[SEO] Detect which SEO plugin is active (Yoast SEO, Rank Math, All in One SEO or SEOPress), its version, and whether several are active at once.', [], [
            'title'           => 'Get SEO Provider',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'provider' => [ 'type' => 'string' ],
                'version'  => [ 'type' => 'string' ],
                'active_providers' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'conflict' => [ 'type' => 'boolean' ],
                'warning'  => [ 'type' => 'string' ],
            ],
        ] ),
        Cowboy_MCP_Tools::tool( 'wp_seo_get_meta', '[SEO] Read a post\'s SEO meta: title, description, focus keyword, robots, canonical URL, OpenGraph/Twitter overrides, cornerstone flag, plus provider scores. Unified across Yoast SEO, Rank Math, All in One SEO and SEOPress (precedence Yoast > Rank Math > AIOSEO > SEOPress when several are active). Text fields are null when no per-post override is set.', [
            'post_id' => [ 'type' => 'integer', 'description' => 'Post ID', 'required' => true ],
        ], [
            'title'           => 'Get SEO Meta',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'provider' => [ 'type' => 'string' ],
                'post_id'  => [ 'type' => 'integer' ],
                'fields'   => [ 'type' => 'object' ],
                'scores'   => [ 'type' => 'object' ],
            ],
        ] ),
        Cowboy_MCP_Tools::tool( 'wp_seo_update_meta', '[SEO] Update a post\'s SEO meta. Only provided fields change. Empty string (text/URL fields) or false (booleans) clears the per-post override so the provider template resumes. Works with Yoast SEO, Rank Math, All in One SEO and SEOPress; Rank Math focus_keyword accepts comma-separated multiple keywords. Undoable via wp_undo_change.', [
            'post_id'             => [ 'type' => 'integer', 'description' => 'Post ID', 'required' => true ],
            'title'               => [ 'type' => 'string',  'description' => 'SEO title override; provider template variables allowed ("" clears)' ],
            'description'         => [ 'type' => 'string',  'description' => 'Meta description ("" clears)' ],
            'focus_keyword'       => [ 'type' => 'string',  'description' => 'Focus keyword; comma-separated multiples on Rank Math ("" clears)' ],
            'noindex'             => [ 'type' => 'boolean', 'description' => 'Exclude from search engines (false restores the site default)' ],
            'nofollow'            => [ 'type' => 'boolean', 'description' => 'Mark outgoing links nofollow (false restores the site default)' ],
            'canonical_url'       => [ 'type' => 'string',  'description' => 'Canonical URL override ("" clears)' ],
            'og_title'            => [ 'type' => 'string',  'description' => 'OpenGraph/Facebook title override ("" clears)' ],
            'og_description'      => [ 'type' => 'string',  'description' => 'OpenGraph/Facebook description override ("" clears)' ],
            'og_image'            => [ 'type' => 'string',  'description' => 'OpenGraph/Facebook image URL ("" clears)' ],
            'twitter_title'       => [ 'type' => 'string',  'description' => 'X/Twitter title override ("" clears)' ],
            'twitter_description' => [ 'type' => 'string',  'description' => 'X/Twitter description override ("" clears)' ],
            'twitter_image'       => [ 'type' => 'string',  'description' => 'X/Twitter image URL ("" clears)' ],
            'cornerstone'         => [ 'type' => 'boolean', 'description' => 'Mark as cornerstone (Yoast) / pillar (Rank Math, AIOSEO) content; not available on SEOPress' ],
        ], [
            'title'           => 'Update SEO Meta',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'updated'                  => [ 'type' => 'boolean' ],
                'provider_cache_refreshed' => [ 'type' => 'boolean' ],
                'provider'                 => [ 'type' => 'string' ],
                'post_id'                  => [ 'type' => 'integer' ],
                'fields'                   => [ 'type' => 'object' ],
                'scores'                   => [ 'type' => 'object' ],
                'unsupported_fields'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'failed_fields'            => [ 'type' => 'object' ],
                'warning'                  => [ 'type' => 'string' ],
            ],
        ] ),
        Cowboy_MCP_Tools::tool( 'wp_seo_audit', '[SEO] Audit posts for SEO issues: missing or badly sized meta descriptions, missing focus keywords, over-length custom titles, noindex on published posts. Paginated scan; the summary covers the scanned page only — iterate pages for a full-site audit.', [
            'post_type'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Post types to scan (default: post, page)' ],
            'post_status' => [ 'type' => 'string',  'description' => 'Post status to scan (default publish)', 'default' => 'publish' ],
            'only_issues' => [ 'type' => 'boolean', 'description' => 'Return only posts with at least one issue (default true)', 'default' => true ],
            'per_page'    => [ 'type' => 'integer', 'description' => 'Posts scanned per page, max 100 (default 50)', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            'page'        => [ 'type' => 'integer', 'description' => 'Page number (default 1)', 'default' => 1, 'minimum' => 1 ],
        ], [
            'title'           => 'SEO Audit',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'posts'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'summary'  => [ 'type' => 'object' ],
                'total'    => [ 'type' => 'integer' ],
                'pages'    => [ 'type' => 'integer' ],
                'page'     => [ 'type' => 'integer' ],
                'per_page' => [ 'type' => 'integer' ],
            ],
        ] ),
    ],

    'handlers' => [
        'wp_seo_get_provider' => function ( array $a ): array {
            $active   = cowboy_mcp_seo_active_providers();
            $provider = $active[0] ?? [ 'provider' => 'none', 'version' => null ];
            $out      = $provider + [
                'active_providers' => array_column( $active, 'provider' ),
                'conflict'         => count( $active ) > 1,
            ];
            if ( $out['conflict'] ) {
                $out['warning'] = 'Several SEO plugins are active (' . implode( ', ', $out['active_providers'] ) . '); Cowboy reads and writes ' . $provider['provider'] . ' only. Running more than one SEO plugin usually duplicates meta tags.';
            }
            return $out;
        },
        'wp_seo_get_meta' => function ( array $a ) {
            if ( ! current_user_can( 'edit_posts' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot read post SEO meta.' );
            }
            $post_id = (int) $a['post_id'];
            if ( ! get_post( $post_id ) ) {
                return new WP_Error( 'not_found', "Post {$post_id} not found." );
            }
            $adapter = cowboy_mcp_seo_adapter();
            if ( ! $adapter ) {
                return new WP_Error( 'provider_api_unavailable', 'No supported SEO plugin API is available.' );
            }
            return [
                'provider' => $adapter['provider'],
                'post_id'  => $post_id,
                'fields'   => cowboy_mcp_seo_read_fields( $post_id ),
                'scores'   => cowboy_mcp_seo_read_scores( $post_id ),
            ];
        },
        'wp_seo_update_meta' => function ( array $a ) {
            $post_id = (int) $a['post_id'];
            if ( ! get_post( $post_id ) ) {
                return new WP_Error( 'not_found', "Post {$post_id} not found." );
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot edit this post.' );
            }

            $adapter = cowboy_mcp_seo_adapter();
            if ( ! $adapter ) {
                return new WP_Error( 'provider_api_unavailable', 'No supported SEO plugin API is available.' );
            }
            $provided = array_intersect_key( $a, COWBOY_MCP_SEO_FIELDS );
            if ( ! $provided ) {
                return new WP_Error( 'invalid_params', 'Provide at least one SEO field to update.' );
            }

            // Validate everything before writing anything: an error after a
            // partial write would discard the undo journal entry while leaving
            // real changes behind.
            $writes = [];
            foreach ( $provided as $field => $value ) {
                $vtype = COWBOY_MCP_SEO_FIELDS[ $field ];
                if ( $vtype === 'bool' ) {
                    $writes[ $field ] = (bool) $value;
                } elseif ( $vtype === 'url' ) {
                    $value = trim( (string) $value );
                    if ( $value !== '' ) {
                        $value = esc_url_raw( $value );
                        if ( $value === '' ) {
                            return new WP_Error( 'invalid_params', "{$field} is not a valid URL." );
                        }
                    }
                    $writes[ $field ] = $value;
                } else {
                    // sanitize_text_field() would strip the %xx octets inside
                    // Yoast/Rank Math template variables (%%category%%, %date%);
                    // keep its other guarantees, skip the octet stripping.
                    $clean = wp_check_invalid_utf8( (string) $value );
                    $clean = wp_strip_all_tags( $clean );
                    $writes[ $field ] = trim( preg_replace( '/[\r\n\t ]+/', ' ', $clean ) );
                }
            }

            $unsupported = array_values( array_diff( array_keys( $writes ), $adapter['supports'] ) );
            $writes      = array_diff_key( $writes, array_flip( $unsupported ) );
            $failed      = $writes ? ( $adapter['write_many'] )( $post_id, $writes ) : [];

            $out = [
                'updated'                  => (bool) $writes && ! $failed,
                'provider_cache_refreshed' => (bool) ( $adapter['refresh'] )( $post_id ),
                'provider'                 => $adapter['provider'],
                'post_id'                  => $post_id,
                'fields'                   => ( $adapter['read'] )( $post_id ),
                'scores'                   => ( $adapter['scores'] )( $post_id ),
            ];
            if ( $unsupported ) {
                $out['unsupported_fields'] = $unsupported;
                $out['warning']            = $adapter['provider'] . ' has no per-post setting for: ' . implode( ', ', $unsupported ) . '.';
            }
            if ( $failed ) {
                $out['failed_fields'] = $failed;
            }
            if ( ! $writes && $unsupported ) {
                return new WP_Error( 'unsupported_field', $out['warning'] );
            }
            return $out;
        },
        'wp_seo_audit' => function ( array $a ) {
            if ( ! current_user_can( 'edit_posts' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot audit posts.' );
            }
            $per_page = min( max( (int) ( $a['per_page'] ?? 50 ), 1 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );
            $types    = array_values( array_filter( array_map( 'sanitize_key', (array) ( $a['post_type'] ?? [ 'post', 'page' ] ) ) ) );
            $status   = sanitize_key( $a['post_status'] ?? 'publish' );
            $only     = ! isset( $a['only_issues'] ) || ! empty( $a['only_issues'] );

            $query = new WP_Query( [
                'post_type'      => $types ?: [ 'post', 'page' ],
                'post_status'    => $status,
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ] );

            $posts   = [];
            $by_code = [];
            $with    = 0;
            foreach ( $query->posts as $post ) {
                $issues = cowboy_mcp_seo_audit_issues( $post, cowboy_mcp_seo_read_fields( $post->ID ) );
                if ( $issues ) {
                    $with++;
                    foreach ( $issues as $issue ) {
                        $by_code[ $issue['code'] ] = ( $by_code[ $issue['code'] ] ?? 0 ) + 1;
                    }
                }
                if ( $issues || ! $only ) {
                    $posts[] = [
                        'post_id' => (int) $post->ID,
                        'title'   => $post->post_title,
                        'url'     => get_permalink( $post ),
                        'issues'  => $issues,
                    ];
                }
            }

            return [
                'posts'    => $posts,
                'summary'  => [ 'scanned' => count( $query->posts ), 'with_issues' => $with, 'by_code' => $by_code ],
                'total'    => (int) $query->found_posts,
                'pages'    => (int) $query->max_num_pages,
                'page'     => $page,
                'per_page' => $per_page,
            ];
        },
    ],
];
