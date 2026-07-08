<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_batch_execute', '[System] Execute multiple tools sequentially in a single request. Each call runs in order; errors do not stop subsequent calls. Recursive batch calls are not allowed. Max 50 calls per batch.', [
            'calls' => [
                'type'        => 'array',
                'description' => 'Array of tool calls to execute sequentially',
                'required'    => true,
                'items'       => [
                    'type' => 'object',
                    'properties' => [
                        'name'      => [ 'type' => 'string', 'description' => 'Tool name to call (e.g. "wp_site_info")' ],
                        'arguments' => [ 'type' => 'object', 'description' => 'Arguments to pass to the tool' ],
                    ],
                    'required' => [ 'name', 'arguments' ],
                ],
            ],
        ], [
            'title'           => 'Batch Execute Tools',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'cowboy_mcp_get_audit_log', '[System] Query the MCP audit log. Returns timestamped entries for tool calls, errors, and auth events.', [
            'event'     => [ 'type' => 'string',  'description' => 'Filter by event type (e.g. tool_call, tool_error, auth_invalid_key)' ],
            'tool'      => [ 'type' => 'string',  'description' => 'Filter by tool name' ],
            'key_id'    => [ 'type' => 'string',  'description' => 'Filter by API key ID' ],
            'date_from' => [ 'type' => 'string',  'description' => 'Start date (YYYY-MM-DD)' ],
            'date_to'   => [ 'type' => 'string',  'description' => 'End date (YYYY-MM-DD)' ],
            'per_page'  => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 50 ],
            'page'      => [ 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ],
        ], [
            'title'           => 'Get Audit Log',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ], [
            'type' => 'object',
            'properties' => [
                'total'   => [ 'type' => 'integer' ],
                'page'    => [ 'type' => 'integer' ],
                'entries' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'         => [ 'type' => 'integer' ],
                            'event'      => [ 'type' => 'string' ],
                            'tool'       => [ 'type' => 'string' ],
                            'key_id'     => [ 'type' => 'string' ],
                            'context'    => [ 'type' => 'object' ],
                            'created_at' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
        ]),
    ],
    'handlers' => [
        'wp_batch_execute' => function ( array $a ) {
            // Request-scoped guard: blocks ANY nested batch, including one smuggled in
            // via cowboy_run (whose sub-call name is "cowboy_run", not "wp_batch_execute").
            static $in_batch = false;
            if ( $in_batch ) {
                return new WP_Error( 'recursive_batch', 'Nested batch execution is not allowed.' );
            }

            $calls   = $a['calls'] ?? [];
            $max     = 50;
            $results = [];

            if ( count( $calls ) > $max ) {
                return new WP_Error( 'too_many_calls', "Batch limited to {$max} calls." );
            }

            $in_batch = true;
            $batch_uuid = null;
            if ( class_exists( 'Cowboy_MCP_Rollback' ) ) {
                $batch_uuid                    = wp_generate_uuid4();
                Cowboy_MCP_Rollback::$batch_id = $batch_uuid;
            }
            try {
                foreach ( $calls as $i => $call ) {
                    $name = $call['name'] ?? '';
                    $args = $call['arguments'] ?? [];

                    // Prevent recursive batch calls (direct by name).
                    if ( $name === 'wp_batch_execute' ) {
                        $results[] = [ 'index' => $i, 'tool' => $name, 'error' => 'Recursive batch not allowed.' ];
                        continue;
                    }

                    $result = Cowboy_MCP_Tools::call_tool([
                        'name'      => $name,
                        'arguments' => $args,
                    ]);

                    $results[] = [
                        'index'  => $i,
                        'tool'   => $name,
                        'result' => $result,
                    ];
                }
            } finally {
                $in_batch = false;
                if ( class_exists( 'Cowboy_MCP_Rollback' ) ) {
                    Cowboy_MCP_Rollback::$batch_id = null;
                }
            }

            return [ 'results' => $results, 'total' => count( $results ), 'batch_id' => $batch_uuid ];
        },

        'cowboy_mcp_get_audit_log' => function ( array $a ) {
            if ( ! class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
                return new WP_Error( 'unavailable', 'Audit log is not available.' );
            }
            return Cowboy_MCP_Audit_Log::query( $a );
        },
    ],
];
