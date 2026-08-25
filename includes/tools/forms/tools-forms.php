<?php
defined( 'ABSPATH' ) || exit;

/* ================================================================
 *  Guard — return empty when no supported form plugin active.
 * ================================================================ */

if ( ! Cowboy_MCP_Tools::domain_available( __FILE__ ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

/* ================================================================
 *  Helpers
 * ================================================================ */

/**
 * Detect which form plugin is active.
 * Priority: WPForms > Gravity Forms > Contact Form 7.
 */
function cowboy_mcp_forms_get_provider(): ?array {
    if ( function_exists( 'wpforms' ) ) {
        $version     = defined( 'WPFORMS_VERSION' ) ? WPFORMS_VERSION : 'unknown';
        $has_entries = is_object( wpforms() ) && isset( wpforms()->entry );
        return [ 'provider' => 'wpforms', 'version' => $version, 'has_entries' => $has_entries ];
    }

    if ( class_exists( 'GFAPI' ) ) {
        $version = class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'get_version_info' )
            ? GFCommon::$version ?? 'unknown'
            : 'unknown';
        return [ 'provider' => 'gravity-forms', 'version' => $version, 'has_entries' => true ];
    }

    if ( class_exists( 'WPCF7_ContactForm' ) ) {
        $version = defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : 'unknown';
        return [ 'provider' => 'cf7', 'version' => $version, 'has_entries' => false ];
    }

    return null;
}

/* ================================================================
 *  Tool definitions & handlers
 * ================================================================ */

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_forms_get_provider', '[Forms] Detect which form plugin is active (WPForms, Gravity Forms, or Contact Form 7), its version, and whether it supports entry storage.', [], [
            'title'           => 'Get Forms Provider',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),
    ],

    'handlers' => [
        'wp_forms_get_provider' => function ( array $a ): array {
            $provider = cowboy_mcp_forms_get_provider();
            return $provider ?? [ 'provider' => 'none', 'version' => null, 'has_entries' => false ];
        },
    ],
];
