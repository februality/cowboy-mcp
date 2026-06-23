<?php
defined( 'ABSPATH' ) || exit;

return [
	'tools' => [
		Cowboy_MCP_Tools::tool( 'wp_site_health', '[System] Run WordPress Site Health checks and retrieve debug information.', [
			'section'  => [ 'type' => 'string', 'description' => 'Which data set to return', 'enum' => [ 'tests', 'info', 'all' ], 'default' => 'tests' ],
			'category' => [ 'type' => 'string', 'description' => 'Filter info sections by key (e.g. "wp-server", "wp-database"). Only applies when section is "info" or "all".' ],
		], [
			'title'           => 'Site Health',
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		], [
			'type'       => 'object',
			'properties' => [
				'tests'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'test'        => [ 'type' => 'string' ],
							'label'       => [ 'type' => 'string' ],
							'status'      => [ 'type' => 'string' ],
							'badge'       => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
						],
					],
				],
				'summary' => [
					'type'       => 'object',
					'properties' => [
						'good'        => [ 'type' => 'integer' ],
						'recommended' => [ 'type' => 'integer' ],
						'critical'    => [ 'type' => 'integer' ],
					],
				],
				'info'    => [ 'type' => 'object' ],
			],
		] ),
	],
	'handlers' => [
		'wp_site_health' => function ( array $a = [] ): array|WP_Error {
			$section  = $a['section'] ?? 'tests';
			$category = $a['category'] ?? null;

			$result = [];

			// --- Tests ---
			// Computed from core APIs + update transients (Cowboy_MCP_Compat), so no
			// wp-admin/includes/class-wp-site-health.php is required in REST context.
			if ( $section === 'tests' || $section === 'all' ) {
				$st                = Cowboy_MCP_Compat::site_health_tests();
				$result['tests']   = $st['tests'];
				$result['summary'] = $st['summary'];
			}

			// --- Debug Info ---
			// Assembled from core APIs (Cowboy_MCP_Compat::debug_data), replacing
			// wp-admin/includes/class-wp-debug-data.php.
			if ( $section === 'info' || $section === 'all' ) {
				$info = Cowboy_MCP_Compat::debug_data();

				// Filter by category if specified.
				if ( $category ) {
					if ( ! isset( $info[ $category ] ) ) {
						return new WP_Error(
							'invalid_category',
							"Unknown info category: {$category}. Available: " . implode( ', ', array_keys( $info ) )
						);
					}
					$info = [ $category => $info[ $category ] ];
				}

				// Flatten any non-string field values for readability.
				foreach ( $info as $section_key => &$section_data ) {
					if ( ! isset( $section_data['fields'] ) ) {
						continue;
					}
					foreach ( $section_data['fields'] as $field_key => &$field ) {
						if ( isset( $field['value'] ) && ! is_string( $field['value'] ) ) {
							$field['value'] = wp_json_encode( $field['value'] );
						}
					}
					unset( $field );
				}
				unset( $section_data );

				$result['info'] = $info;
			}

			return $result;
		},
	],
];
