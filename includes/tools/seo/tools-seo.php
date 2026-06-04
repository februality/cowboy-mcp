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
    ],

    'handlers' => [
        'wp_seo_get_provider' => function ( array $a ): array {
            $provider = cowboy_mcp_seo_get_provider();
            return $provider ?? [ 'provider' => 'none', 'version' => null ];
        },
    ],
];
