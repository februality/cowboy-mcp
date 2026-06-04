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

			// Load admin dependencies required by Site Health.
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
			require_once ABSPATH . 'wp-admin/includes/update.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$result = [];

			// --- Tests ---
			if ( $section === 'tests' || $section === 'all' ) {
				$health     = WP_Site_Health::get_instance();
				$all_tests  = $health->get_tests();
				$test_results = [];
				$summary    = [ 'good' => 0, 'recommended' => 0, 'critical' => 0 ];

				// Run direct tests.
				foreach ( $all_tests['direct'] ?? [] as $test ) {
					$test_results[] = cowboy_mcp_run_site_health_test( $test );
				}

				// Run async tests the same way (they have callables in REST context).
				foreach ( $all_tests['async'] ?? [] as $test ) {
					if ( isset( $test['test'] ) && is_callable( [ $health, 'get_test_' . $test['test'] ] ) ) {
						$test['callback'] = [ $health, 'get_test_' . $test['test'] ];
						$test_results[]   = cowboy_mcp_run_site_health_test( $test );
					}
				}

				// Build summary counts.
				foreach ( $test_results as $t ) {
					match ( $t['status'] ) {
						'good'        => $summary['good']++,
						'recommended' => $summary['recommended']++,
						'critical'    => $summary['critical']++,
						default       => null,
					};
				}

				$result['tests']   = $test_results;
				$result['summary'] = $summary;
			}

			// --- Debug Info ---
			if ( $section === 'info' || $section === 'all' ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';

				WP_Debug_Data::check_for_updates();
				$info = WP_Debug_Data::debug_data();

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

				// Flatten field values to simple strings for readability.
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

/**
 * Execute a single Site Health test callable and return a normalized result.
 */
function cowboy_mcp_run_site_health_test( array $test ): array {
	$callback = $test['callback'] ?? ( $test['test'] ?? null );

	if ( ! is_callable( $callback ) ) {
		return [
			'test'        => $test['label'] ?? $test['test'] ?? 'unknown',
			'label'       => $test['label'] ?? 'Unknown test',
			'status'      => 'error',
			'badge'       => '',
			'description' => 'Test callback is not callable.',
		];
	}

	try {
		$r = call_user_func( $callback );

		return [
			'test'        => $r['test'] ?? $test['test'] ?? 'unknown',
			'label'       => $r['label'] ?? '',
			'status'      => $r['status'] ?? 'unknown',
			'badge'       => $r['badge']['label'] ?? '',
			'description' => wp_strip_all_tags( $r['description'] ?? '' ),
		];
	} catch ( \Throwable $e ) {
		return [
			'test'        => $test['test'] ?? 'unknown',
			'label'       => $test['label'] ?? 'Unknown test',
			'status'      => 'error',
			'badge'       => '',
			'description' => 'Exception: ' . $e->getMessage(),
		];
	}
}
