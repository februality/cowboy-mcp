<?php
/**
 * Cowboy MCP – Abilities domain (inbound half of the Abilities API bridge).
 * Filled in by the abilities-bridge implementation (Task 7).
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_has_ability' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

return [ 'tools' => [], 'handlers' => [] ];
