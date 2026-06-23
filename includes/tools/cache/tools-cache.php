<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Guard — return empty when no supported cache plugin active.
 * ================================================================ */

if ( ! function_exists( 'rocket_clean_domain' )
     && ! class_exists( 'LiteSpeed_Cache_API' )
     && ! defined( 'LSCWP_V' )
     && ! defined( 'W3TC_VERSION' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Helpers
 * ================================================================ */

/**
 * Detect which cache plugin is active.
 * Priority: WP Rocket > LiteSpeed Cache > W3 Total Cache.
 */
function cowboy_mcp_cache_get_provider(): ?array {
    if ( function_exists( 'rocket_clean_domain' ) ) {
        $version = defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : 'unknown';
        return [ 'provider' => 'wp-rocket', 'version' => $version ];
    }

    if ( class_exists( 'LiteSpeed_Cache_API' ) || defined( 'LSCWP_V' ) ) {
        $version = defined( 'LSCWP_V' ) ? LSCWP_V : 'unknown';
        return [ 'provider' => 'litespeed', 'version' => $version ];
    }

    if ( defined( 'W3TC_VERSION' ) ) {
        return [ 'provider' => 'w3tc', 'version' => W3TC_VERSION ];
    }

    return null;
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_cache_get_provider', '[Cache] Detect which cache plugin is active (WP Rocket, LiteSpeed Cache, or W3 Total Cache) and its version.', [], [
            'title'           => 'Get Cache Provider',
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

        Cowboy_MCP_Tools::tool( 'wp_cache_flush', '[Cache] Flush/purge the page cache. Scope: all (entire site), post (single post by ID), or home (front page only).', [
            'scope'   => [ 'type' => 'string', 'description' => 'Cache scope to flush', 'enum' => [ 'all', 'post', 'home' ], 'default' => 'all' ],
            'post_id' => [ 'type' => 'integer', 'description' => 'Post ID — required when scope is "post"' ],
        ], [
            'title'           => 'Flush Cache',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_cache_preload', '[Cache] Trigger cache preload/warmup. Supported by WP Rocket and LiteSpeed Cache. W3 Total Cache does not expose a preload API.', [], [
            'title'           => 'Preload Cache',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_cache_get_settings', '[Cache] Read the active cache plugin\'s configuration settings.', [], [
            'title'           => 'Get Cache Settings',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'provider' => [ 'type' => 'string' ],
                'version'  => [ 'type' => 'string' ],
                'settings' => [ 'type' => 'object' ],
            ],
        ] ),
    ],

    'handlers' => [
        /* ---------- Get Provider ---------- */

        'wp_cache_get_provider' => function ( array $a ): array {
            $provider = cowboy_mcp_cache_get_provider();
            return $provider ?? [ 'provider' => 'none', 'version' => null ];
        },

        /* ---------- Flush ---------- */

        'wp_cache_flush' => function ( array $a ): array|WP_Error {
            $provider = cowboy_mcp_cache_get_provider();
            if ( ! $provider ) {
                return new WP_Error( 'no_provider', 'No supported cache plugin detected.' );
            }

            $scope   = $a['scope'] ?? 'all';
            $post_id = isset( $a['post_id'] ) ? (int) $a['post_id'] : 0;

            if ( $scope === 'post' && $post_id <= 0 ) {
                return new WP_Error( 'invalid_params', 'post_id is required when scope is "post".' );
            }

            if ( $scope === 'post' && ! get_post( $post_id ) ) {
                return new WP_Error( 'not_found', "Post #{$post_id} not found." );
            }

            match ( $provider['provider'] ) {
                'wp-rocket' => match ( $scope ) {
                    'all'  => rocket_clean_domain(),
                    'post' => function_exists( 'rocket_clean_post' ) ? rocket_clean_post( $post_id ) : rocket_clean_domain(),
                    'home' => function_exists( 'rocket_clean_home' ) ? rocket_clean_home() : rocket_clean_domain(),
                },
                'litespeed' => match ( $scope ) {
                    'all'  => method_exists( 'LiteSpeed_Cache_API', 'purge_all' )
                              ? LiteSpeed_Cache_API::purge_all()
                              : do_action( 'litespeed_purge_all' ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                    'post' => method_exists( 'LiteSpeed_Cache_API', 'purge_post' )
                              ? LiteSpeed_Cache_API::purge_post( $post_id )
                              : do_action( 'litespeed_purge_post', $post_id ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                    'home' => do_action( 'litespeed_purge_url', home_url( '/' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                },
                'w3tc' => match ( $scope ) {
                    'all'  => function_exists( 'w3tc_flush_all' ) ? w3tc_flush_all() : null,
                    'post' => function_exists( 'w3tc_flush_post' ) ? w3tc_flush_post( $post_id ) : null,
                    'home' => function_exists( 'w3tc_flush_url' ) ? w3tc_flush_url( home_url( '/' ) ) : ( function_exists( 'w3tc_flush_all' ) ? w3tc_flush_all() : null ),
                },
                default => null,
            };

            return [
                'flushed'  => true,
                'scope'    => $scope,
                'post_id'  => $scope === 'post' ? $post_id : null,
                'provider' => $provider['provider'],
            ];
        },

        /* ---------- Preload ---------- */

        'wp_cache_preload' => function ( array $a ): array|WP_Error {
            $provider = cowboy_mcp_cache_get_provider();
            if ( ! $provider ) {
                return new WP_Error( 'no_provider', 'No supported cache plugin detected.' );
            }

            return match ( $provider['provider'] ) {
                'wp-rocket' => (function () {
                    if ( function_exists( 'run_rocket_bot' ) ) {
                        run_rocket_bot();
                    } elseif ( function_exists( 'rocket_preload_cache' ) ) {
                        rocket_preload_cache();
                    } else {
                        return new WP_Error( 'unsupported', 'WP Rocket preload function not available in this version.' );
                    }
                    return [
                        'preload_triggered' => true,
                        'provider'          => 'wp-rocket',
                        'message'           => 'Cache preload triggered. This runs asynchronously.',
                    ];
                })(),
                'litespeed' => (function () {
                    do_action( 'litespeed_crawl_trigger' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                    return [
                        'preload_triggered' => true,
                        'provider'          => 'litespeed',
                        'message'           => 'LiteSpeed crawler trigger dispatched.',
                    ];
                })(),
                'w3tc' => new WP_Error( 'unsupported', 'W3 Total Cache does not expose a preload API.' ),
                default => new WP_Error( 'unsupported', "Preload not supported for provider: {$provider['provider']}" ),
            };
        },

        /* ---------- Get Settings ---------- */

        'wp_cache_get_settings' => function ( array $a ): array|WP_Error {
            $provider = cowboy_mcp_cache_get_provider();
            if ( ! $provider ) {
                return new WP_Error( 'no_provider', 'No supported cache plugin detected.' );
            }

            $settings = match ( $provider['provider'] ) {
                'wp-rocket' => get_option( 'wp_rocket_settings', [] ),
                'litespeed' => (function () {
                    global $wpdb;
                    $like = $wpdb->esc_like( 'litespeed.conf' ) . '%';
                    $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d", $like, 200 ),
                        ARRAY_A
                    );
                    $settings = [];
                    foreach ( $rows as $row ) {
                        $key = str_replace( 'litespeed.conf.', '', $row['option_name'] );
                        $settings[ $key ] = $row['option_value'];
                    }
                    return $settings;
                })(),
                'w3tc' => get_option( 'w3tc_config', [] ),
                default => [],
            };

            return [
                'provider' => $provider['provider'],
                'version'  => $provider['version'],
                'settings' => $settings,
            ];
        },
    ],
];
