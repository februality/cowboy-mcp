<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_update_option', '[Settings] Update a WordPress option value. Protected options (core URLs, admin email, credentials, API keys, role/capability maps, mail relay, and plugin internals) are blocked from modification.', [
            'option_name'  => [ 'type' => 'string', 'description' => 'Option name', 'required' => true ],
            'option_value' => [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ], 'description' => 'New value (string, number, integer, boolean, array, object, or null)', 'required' => true ],
        ], [
            'title'           => 'Update Option',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'updated'     => [ 'type' => 'boolean' ],
                'option_name' => [ 'type' => 'string' ],
            ],
        ]),
    ],
    'handlers' => [
        'wp_update_option' => function ( array $a ) {
            $opt = (string) ( $a['option_name'] ?? '' );
            $hard = Cowboy_MCP_Security::is_hard_protected_option( $opt );
            if ( Cowboy_MCP_Security::is_protected_option( $opt )
                && ( $hard || ! Cowboy_MCP_Security::power_mode_enabled() ) ) {
                $hint = $hard ? '' : ' An administrator can enable Power mode to allow this.';
                return new WP_Error( 'option_blocked', "Option '{$opt}' is protected and cannot be modified via MCP.{$hint}" );
            }

            update_option( $a['option_name'], $a['option_value'] );
            return [ 'updated' => true, 'option_name' => $a['option_name'] ];
        },
    ],
];
