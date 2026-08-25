<?php
/**
 * Cowboy MCP – Abilities domain (inbound half of the Abilities API bridge).
 *
 * Exposes abilities registered by OTHER plugins and core as typed Cowboy tools
 * in the `abilities` category. Tool name = ability name (namespace/name); the
 * slash makes provenance obvious and cannot collide with wp_* tools. Each call
 * runs the ability's own permission callback as the current user (the first
 * administrator for API keys, the consenting admin for OAuth) and is journaled
 * as not undoable — Cowboy has no before-state capture for foreign abilities.
 *
 * Loaded by Cowboy_MCP_Tools::maybe_load_abilities_domain() — never while
 * wp_abilities_api_init is running, so the registry is complete when we
 * enumerate it. Core functions used here are 6.9+ and guarded below.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_has_ability' ) ) {
    return [ 'tools' => [], 'handlers' => [] ];
}

if ( ! function_exists( 'cowboy_mcp_ability_properties' ) ) {
    /**
     * Map an ability's input schema to Cowboy_MCP_Tools::tool() properties.
     *
     * @return array{0: array, 1: string} [ properties, shape ] with shape 'none' | 'object' | 'wrapped'.
     */
    function cowboy_mcp_ability_properties( array $schema ): array {
        if ( $schema === [] ) {
            return [ [], 'none' ];
        }
        $is_object = ( ( $schema['type'] ?? '' ) === 'object' ) || isset( $schema['properties'] );
        if ( ! $is_object ) {
            // Non-object schema (string, array, integer...): wrap it as `input`, unwrap at call time.
            $prop             = $schema;
            $prop['required'] = ! array_key_exists( 'default', $schema );
            if ( empty( $prop['description'] ) ) {
                $prop['description'] = 'Input for this ability (its schema is not an object).';
            }
            return [ [ 'input' => $prop ], 'wrapped' ];
        }
        $props    = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
        $required = array_flip( array_map( 'strval', (array) ( $schema['required'] ?? [] ) ) );
        $out      = [];
        foreach ( $props as $key => $def ) {
            if ( ! is_array( $def ) ) {
                continue;
            }
            if ( isset( $required[ (string) $key ] ) ) {
                $def['required'] = true;                       // tool(): boolean required => top-level required[]
            } elseif ( isset( $def['required'] ) && ! is_array( $def['required'] ) ) {
                unset( $def['required'] );                     // a scalar `required` inside a property is not JSON Schema
            }
            $out[ (string) $key ] = $def;
        }
        return [ $out, 'object' ];
    }
}

$cowboy_mcp_ability_tools    = [];
$cowboy_mcp_ability_handlers = [];

foreach ( wp_get_abilities() as $cowboy_mcp_ability ) {
    if ( ! is_object( $cowboy_mcp_ability ) || ! method_exists( $cowboy_mcp_ability, 'get_name' ) ) {
        continue;
    }
    $cowboy_mcp_name = (string) $cowboy_mcp_ability->get_name();
    $cowboy_mcp_ns   = explode( '/', $cowboy_mcp_name, 2 )[0];
    // Our own abilities would loop; mcp-adapter's execute-ability would bypass scope.
    if ( in_array( $cowboy_mcp_ns, [ 'cowboy-mcp', 'mcp-adapter' ], true ) ) {
        continue;
    }
    $cowboy_mcp_meta = (array) $cowboy_mcp_ability->get_meta();
    $cowboy_mcp_exposed = ( ( $cowboy_mcp_meta['public'] ?? false ) === true )
        || ( ( $cowboy_mcp_meta['show_in_rest'] ?? false ) === true )
        || ( ( $cowboy_mcp_meta['mcp']['public'] ?? false ) === true );
    if ( ! $cowboy_mcp_exposed ) {
        continue;   // internal by its author's intent
    }
    $cowboy_mcp_ann     = is_array( $cowboy_mcp_meta['annotations'] ?? null ) ? $cowboy_mcp_meta['annotations'] : [];
    $cowboy_mcp_ro      = ( $cowboy_mcp_ann['readonly'] ?? null ) === true;      // security boundary: explicit true only
    $cowboy_mcp_destr   = ( $cowboy_mcp_ann['destructive'] ?? null ) === true;
    $cowboy_mcp_idem    = ( $cowboy_mcp_ann['idempotent'] ?? null ) === true;
    [ $cowboy_mcp_props, $cowboy_mcp_shape ] = cowboy_mcp_ability_properties( (array) $cowboy_mcp_ability->get_input_schema() );

    $cowboy_mcp_label = trim( (string) $cowboy_mcp_ability->get_label() );
    if ( $cowboy_mcp_label === '' ) {
        $cowboy_mcp_label = $cowboy_mcp_name;
    }
    $cowboy_mcp_desc = $cowboy_mcp_label . ' — ' . trim( (string) $cowboy_mcp_ability->get_description() )
        . " [Ability registered by {$cowboy_mcp_ns}; runs that plugin's own permission check]";
    if ( ! $cowboy_mcp_ro ) {
        $cowboy_mcp_desc .= ' Not undoable by Cowboy.';
    }

    $cowboy_mcp_ability_tools[] = Cowboy_MCP_Tools::tool( $cowboy_mcp_name, $cowboy_mcp_desc, $cowboy_mcp_props, [
        'title'           => $cowboy_mcp_label,
        'readOnlyHint'    => $cowboy_mcp_ro,
        'destructiveHint' => $cowboy_mcp_destr,
        'idempotentHint'  => $cowboy_mcp_idem,
        'openWorldHint'   => false,
    ] );

    $cowboy_mcp_ability_handlers[ $cowboy_mcp_name ] = static function ( array $args ) use ( $cowboy_mcp_name, $cowboy_mcp_shape ) {
        unset( $args['dry_run'], $args['confirm'] );   // Cowboy-injected; would fail additionalProperties:false
        if ( ! wp_has_ability( $cowboy_mcp_name ) ) {   // get_registered() would _doing_it_wrong() on an unknown name
            return new WP_Error( 'ability_missing', "Ability {$cowboy_mcp_name} is no longer registered.", [ 'suggestion' => 'Call cowboy_discover(category="abilities") to refresh the list of abilities.' ] );
        }
        $ability = wp_get_ability( $cowboy_mcp_name );
        if ( ! $ability ) {
            return new WP_Error( 'ability_missing', "Ability {$cowboy_mcp_name} is no longer registered.", [ 'suggestion' => 'Call cowboy_discover(category="abilities") to refresh the list of abilities.' ] );
        }
        if ( 'none' === $cowboy_mcp_shape && $args !== [] ) {
            return new WP_Error( 'invalid_params', "Ability {$cowboy_mcp_name} takes no input." );
        }
        // Mirrors WP_Ability::validate_input(): no schema -> only null is valid; object
        // schema -> [] validates, null only to trigger a declared default; wrapped -> unwrap.
        $input = match ( $cowboy_mcp_shape ) {
            'none'    => null,
            'wrapped' => $args['input'] ?? null,
            default   => ( $args === [] && array_key_exists( 'default', (array) $ability->get_input_schema() ) ) ? null : $args,
        };
        return $ability->execute( $input );   // data or WP_Error; call_tool() encodes / formats either
    };
}
unset( $cowboy_mcp_ability, $cowboy_mcp_name, $cowboy_mcp_ns, $cowboy_mcp_meta, $cowboy_mcp_exposed, $cowboy_mcp_ann, $cowboy_mcp_ro, $cowboy_mcp_destr, $cowboy_mcp_idem, $cowboy_mcp_props, $cowboy_mcp_shape, $cowboy_mcp_label, $cowboy_mcp_desc );

return [ 'tools' => $cowboy_mcp_ability_tools, 'handlers' => $cowboy_mcp_ability_handlers ];
