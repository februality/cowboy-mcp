<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Guard — return empty when no supported SEO plugin active.
 * ================================================================ */

if ( ! class_exists( 'WPSEO_Options' ) && ! defined( 'RANK_MATH_VERSION' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Helpers
 * ================================================================ */

/**
 * Detect which SEO plugin is active.
 * Priority: Yoast SEO > Rank Math.
 */
function cowboy_mcp_seo_get_provider(): ?array {
    if ( class_exists( 'WPSEO_Options' ) ) {
        $version = defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : 'unknown';
        return [ 'provider' => 'yoast', 'version' => $version ];
    }

    if ( defined( 'RANK_MATH_VERSION' ) ) {
        return [ 'provider' => 'rank-math', 'version' => RANK_MATH_VERSION ];
    }

    return null;
}

/**
 * Canonical SEO field → provider meta key + encoding, for the active provider.
 *
 * Types: absent = plain text meta; 'url' = URL meta with optional attachment-id
 * 'companion' key; 'flag' = boolean stored as $def['on'] when set, row deleted
 * when cleared; 'rm_robots' = boolean token inside the single rank_math_robots
 * array. 'twitter_custom' marks Rank Math twitter fields that only render when
 * rank_math_twitter_use_facebook is 'off'.
 */
function cowboy_mcp_seo_field_map(): array {
    $provider = cowboy_mcp_seo_get_provider();
    if ( ( $provider['provider'] ?? '' ) === 'yoast' ) {
        return [
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
        ];
    }
    if ( ( $provider['provider'] ?? '' ) === 'rank-math' ) {
        return [
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
        ];
    }
    return [];
}

/** All canonical fields for a post: booleans always bool, text/url null when unset. */
function cowboy_mcp_seo_read_fields( int $post_id ): array {
    $fields = [];
    foreach ( cowboy_mcp_seo_field_map() as $name => $def ) {
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
}

/** Provider-computed scores (read-only; Rank Math has no readability score). */
function cowboy_mcp_seo_read_scores( int $post_id ): array {
    $provider = cowboy_mcp_seo_get_provider();
    if ( ( $provider['provider'] ?? '' ) === 'yoast' ) {
        $seo  = get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );
        $read = get_post_meta( $post_id, '_yoast_wpseo_content_score', true );
        return [
            'seo_score'         => $seo === '' ? null : (int) $seo,
            'readability_score' => $read === '' ? null : (int) $read,
        ];
    }
    $seo = get_post_meta( $post_id, 'rank_math_seo_score', true );
    return [
        'seo_score'         => $seo === '' ? null : (int) $seo,
        'readability_score' => null,
    ];
}

/**
 * Write one canonical field. $value is already validated: bool for flag/robots
 * fields, sanitized string for the rest ('' = clear the override so the
 * provider template resumes).
 */
function cowboy_mcp_seo_write_field( int $post_id, string $field, string|bool $value ): void {
    $def  = cowboy_mcp_seo_field_map()[ $field ];
    $type = $def['type'] ?? 'text';

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

    // text / url
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
        $att_id = attachment_url_to_postid( $value );
        if ( $att_id ) {
            update_post_meta( $post_id, $def['companion'], (string) $att_id );
        } else {
            delete_post_meta( $post_id, $def['companion'] );
        }
    }
    if ( ! empty( $def['twitter_custom'] ) ) {
        update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'off' );
    }
}

/**
 * Yoast ≥14 serves frontend meta from its indexables table, rebuilt on post
 * save — not on direct postmeta writes. Rebuild it explicitly; Rank Math reads
 * postmeta at render time so there is nothing to refresh. Never fatal: a false
 * return surfaces as provider_cache_refreshed in the tool response.
 */
function cowboy_mcp_seo_refresh_provider_cache( int $post_id ): bool {
    $provider = cowboy_mcp_seo_get_provider();
    if ( ( $provider['provider'] ?? '' ) !== 'yoast' ) {
        return true;
    }
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

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_seo_get_provider', '[SEO] Detect which SEO plugin is active (Yoast SEO or Rank Math) and its version.', [], [
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
            ],
        ] ),
        Cowboy_MCP_Tools::tool( 'wp_seo_get_meta', '[SEO] Read a post\'s SEO meta: title, description, focus keyword, robots, canonical URL, OpenGraph/Twitter overrides, cornerstone flag, plus provider scores. Unified across Yoast SEO and Rank Math (Yoast wins if both are active). Text fields are null when no per-post override is set.', [
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
    ],

    'handlers' => [
        'wp_seo_get_provider' => function ( array $a ): array {
            $provider = cowboy_mcp_seo_get_provider();
            return $provider ?? [ 'provider' => 'none', 'version' => null ];
        },
        'wp_seo_get_meta' => function ( array $a ) {
            if ( ! current_user_can( 'edit_posts' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot read post SEO meta.' );
            }
            $post_id = (int) $a['post_id'];
            if ( ! get_post( $post_id ) ) {
                return new WP_Error( 'not_found', "Post {$post_id} not found." );
            }
            return [
                'provider' => cowboy_mcp_seo_get_provider()['provider'],
                'post_id'  => $post_id,
                'fields'   => cowboy_mcp_seo_read_fields( $post_id ),
                'scores'   => cowboy_mcp_seo_read_scores( $post_id ),
            ];
        },
    ],
];
