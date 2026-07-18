<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read the last N lines from a file.
 *
 * @param string $filepath Absolute path to the file.
 * @param int    $lines    Number of lines to read from the end.
 * @return string[] Array of lines (newest last).
 */
function cowboy_mcp_tail_file( string $filepath, int $lines ): array {
	if ( ! is_readable( $filepath ) ) {
		return [];
	}

	// Native read (require-free): no WP_Filesystem/admin include needed.
	$all_lines = @file( $filepath );
	if ( ! is_array( $all_lines ) ) {
		return [];
	}

	// Strip trailing empty element from final newline.
	if ( $all_lines && '' === end( $all_lines ) ) {
		array_pop( $all_lines );
	}

	// Also rtrim each line to remove \r\n artifacts.
	$tail = array_slice( $all_lines, -$lines );
	return array_map( 'rtrim', $tail );
}

/**
 * SSRF protection: resolve hostname and reject private/reserved IPs.
 *
 * @param string $url URL to validate.
 * @return true|WP_Error True if safe, WP_Error if blocked.
 */
function cowboy_mcp_validate_url_ssrf( string $url ): true|WP_Error {
	// Delegate to the shared validator (resolves all A/AAAA records, normalizes
	// IPv4-mapped IPv6, and denies loopback/link-local/ULA/reserved ranges).
	return Cowboy_MCP_Security::validate_url_ssrf( $url );
}

/**
 * Resolve a callback to a human-readable string, with optional source file/line.
 *
 * @param mixed $callback The callback to describe.
 * @return array{name: string, file?: string, line?: int}
 */
function cowboy_mcp_describe_callback( mixed $callback ): array {
	$info = [ 'name' => '(unknown)' ];

	if ( is_string( $callback ) ) {
		$info['name'] = $callback;
		try {
			$ref = str_contains( $callback, '::' )
				? new ReflectionMethod( $callback )
				: new ReflectionFunction( $callback );
			$info['file'] = $ref->getFileName() ?: null;
			$info['line'] = $ref->getStartLine() ?: null;
		} catch ( \ReflectionException $e ) {
			// Ignore — name is enough.
		}
	} elseif ( is_array( $callback ) && count( $callback ) === 2 ) {
		$class  = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		$method = (string) $callback[1];
		$info['name'] = "{$class}::{$method}";
		try {
			$ref = new ReflectionMethod( $class, $method );
			$info['file'] = $ref->getFileName() ?: null;
			$info['line'] = $ref->getStartLine() ?: null;
		} catch ( \ReflectionException $e ) {
			// Ignore.
		}
	} elseif ( $callback instanceof Closure ) {
		$info['name'] = '(closure)';
		try {
			$ref = new ReflectionFunction( $callback );
			$info['file'] = $ref->getFileName() ?: null;
			$info['line'] = $ref->getStartLine() ?: null;
		} catch ( \ReflectionException $e ) {
			// Ignore.
		}
	} elseif ( is_object( $callback ) ) {
		$info['name'] = get_class( $callback ) . '::__invoke';
		try {
			$ref = new ReflectionMethod( $callback, '__invoke' );
			$info['file'] = $ref->getFileName() ?: null;
			$info['line'] = $ref->getStartLine() ?: null;
		} catch ( \ReflectionException $e ) {
			// Ignore.
		}
	}

	// Avoid leaking absolute server paths — report relative to the WordPress root.
	if ( ! empty( $info['file'] ) ) {
		$info['file'] = ltrim( str_replace( Cowboy_MCP_Compat::wp_root(), '', $info['file'] ), '/' );
	}

	// Strip null values.
	return array_filter( $info, fn( $v ) => $v !== null );
}

return [
	'tools' => [
		/* ----------------------------------------------------------------
		 * 1. wp_get_php_error_log
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_get_php_error_log', '[Diagnostics] Read recent PHP error log entries. Checks WP_DEBUG_LOG, ini error_log, and wp-content/debug.log.', [
			'lines' => [ 'type' => 'integer', 'description' => 'Number of lines to read from end of log (default 100, max 500)', 'default' => 100 ],
		], [
			'title'           => 'Get PHP Error Log',
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		] ),

		/* ----------------------------------------------------------------
		 * 2. wp_http_request
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_http_request', '[Diagnostics] Make an outbound HTTP request from WordPress using wp_remote_request(). Blocks requests to private/reserved IPs (SSRF protection).', [
			'url'     => [ 'type' => 'string', 'description' => 'URL to request', 'required' => true ],
			'method'  => [ 'type' => 'string', 'description' => 'HTTP method', 'default' => 'GET', 'enum' => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD' ] ],
			'headers' => [ 'type' => 'object', 'description' => 'Request headers as key-value pairs' ],
			'body'    => [ 'type' => 'string', 'description' => 'Request body' ],
			'timeout' => [ 'type' => 'integer', 'description' => 'Request timeout in seconds (default 15, max 30)', 'default' => 15 ],
		], [
			'title'           => 'HTTP Request',
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
		] ),

		/* ----------------------------------------------------------------
		 * 3. wp_test_email
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_test_email', '[Diagnostics] Send a test email via wp_mail() to verify email configuration.', [
			'to'      => [ 'type' => 'string', 'description' => 'Recipient email address', 'required' => true ],
			'subject' => [ 'type' => 'string', 'description' => 'Email subject', 'default' => 'Cowboy MCP Test Email' ],
			'message' => [ 'type' => 'string', 'description' => 'Email body', 'default' => 'This is a test email sent via Cowboy MCP to verify email configuration.' ],
		], [
			'title'           => 'Test Email',
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
		] ),

		/* ----------------------------------------------------------------
		 * 4. wp_get_hooks
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_get_hooks', '[Diagnostics] List callbacks registered on a specific WordPress action or filter hook with priority, function name, and source location.', [
			'hook' => [ 'type' => 'string', 'description' => "Hook name to inspect (e.g. 'init', 'the_content', 'wp_enqueue_scripts')", 'required' => true ],
		], [
			'title'           => 'Get Hook Callbacks',
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		] ),

		/* ----------------------------------------------------------------
		 * 5. wp_get_transients
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_get_transients', '[Diagnostics] List, search, delete, or clean up WordPress transients.', [
			'action' => [ 'type' => 'string', 'description' => 'Action to perform', 'default' => 'list', 'enum' => [ 'list', 'search', 'delete', 'cleanup' ] ],
			'search' => [ 'type' => 'string', 'description' => 'Search pattern for transient names (used with action=search, supports SQL LIKE wildcards)' ],
			'name'   => [ 'type' => 'string', 'description' => 'Exact transient name to delete (used with action=delete)' ],
			'limit'  => [ 'type' => 'integer', 'description' => 'Max transients to return (default 100, max 500)', 'default' => 100 ],
		], [
			'title'           => 'Manage Transients',
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		] ),

		/* ----------------------------------------------------------------
		 * 6. wp_get_rest_routes
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_get_rest_routes', '[Diagnostics] Discover all registered WordPress REST API endpoints with methods and namespaces.', [
			'namespace' => [ 'type' => 'string', 'description' => "Filter by namespace (e.g. 'wp/v2', 'wc/v3')" ],
			'search'    => [ 'type' => 'string', 'description' => 'Search route patterns' ],
		], [
			'title'           => 'List REST Routes',
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		] ),

		/* ----------------------------------------------------------------
		 * 7. wp_regenerate_thumbnails
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_regenerate_thumbnails', '[Diagnostics] Regenerate image thumbnails for one or more media attachments.', [
			'attachment_id' => [ 'type' => 'integer', 'description' => 'Single attachment ID to regenerate (if omitted, processes a batch)' ],
			'batch_size'    => [ 'type' => 'integer', 'description' => 'Number of attachments to process in batch mode (default 10, max 50)', 'default' => 10 ],
			'offset'        => [ 'type' => 'integer', 'description' => 'Offset for batch processing', 'default' => 0 ],
		], [
			'title'           => 'Regenerate Thumbnails',
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		] ),

		/* ----------------------------------------------------------------
		 * 8. wp_get_rewrite_rules
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_get_rewrite_rules', '[Diagnostics] Inspect WordPress rewrite rules and optionally test a URL path against them.', [
			'test_url' => [ 'type' => 'string', 'description' => 'Test a URL path against rewrite rules to see which rule matches (e.g. "/sample-post/")' ],
			'search'   => [ 'type' => 'string', 'description' => 'Search/filter regex patterns in rewrite rules' ],
		], [
			'title'           => 'Get Rewrite Rules',
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		] ),

		/* ----------------------------------------------------------------
		 * 9. wp_snapshot
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool( 'wp_snapshot', '[Diagnostics] Capture a snapshot of site state (options, plugins, theme, widgets, menus, rewrite rules) for comparison. Returns an MD5 hash for quick diff.', [
			'sections' => [
				'type'        => 'array',
				'description' => 'Sections to include in the snapshot (default: all)',
				'items'       => [ 'type' => 'string', 'enum' => [ 'options', 'plugins', 'theme', 'widgets', 'menus', 'rewrite_rules' ] ],
				'default'     => [ 'options', 'plugins', 'theme', 'widgets', 'menus', 'rewrite_rules' ],
			],
		], [
			'title'           => 'Site Snapshot',
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		] ),

		/* ----------------------------------------------------------------
		 * 10. wp_connection_doctor
		 * ---------------------------------------------------------------- */
		Cowboy_MCP_Tools::tool(
			'wp_connection_doctor',
			'[Diagnostics] Run the MCP connection self-test: config, loopback reachability, OAuth discovery. Returns per-check pass/warn/fail with fingerprinted causes, fix hints, and a plain-text report. The authenticated-path check auto-passes (calling this tool proves it).',
			[],
			[ 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ],
			[
				'summary' => [ 'type' => 'string', 'description' => 'pass | warn | fail' ],
				'counts'  => [ 'type' => 'object', 'description' => 'Totals per status' ],
				'checks'  => [ 'type' => 'array', 'description' => 'Per-check results with fingerprint and fix' ],
				'report'  => [ 'type' => 'string', 'description' => 'Copy-pasteable plain-text report' ],
			]
		),
	],

	'handlers' => [

		/* ----------------------------------------------------------------
		 * 1. wp_get_php_error_log
		 * ---------------------------------------------------------------- */
		'wp_get_php_error_log' => function ( array $a ): array|WP_Error {
			$lines = min( max( (int) ( $a['lines'] ?? 100 ), 1 ), 500 );

			// Determine log path.
			$path = null;
			if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
				$path = WP_DEBUG_LOG;
			}
			if ( ! $path || ! is_file( $path ) ) {
				$ini_log = ini_get( 'error_log' );
				if ( $ini_log && is_file( $ini_log ) ) {
					$path = $ini_log;
				}
			}
			if ( ! $path || ! is_file( $path ) ) {
				$default = Cowboy_MCP_Compat::content_dir() . '/debug.log';
				if ( is_file( $default ) ) {
					$path = $default;
				}
			}

			if ( ! $path || ! is_file( $path ) ) {
				return new WP_Error( 'not_found', 'No PHP error log file found. Checked WP_DEBUG_LOG, ini error_log, and wp-content/debug.log.' );
			}

			if ( ! is_readable( $path ) ) {
				return new WP_Error( 'not_readable', "Error log exists but is not readable: {$path}" );
			}

			$entries = cowboy_mcp_tail_file( $path, $lines );

			// Redact secrets that routinely surface in PHP error logs (DB creds, salts,
			// Bearer/MCP tokens, password=... assignments) before returning them.
			$entries = array_map( function ( $line ) {
				$line = Cowboy_MCP_Security::scrub_secrets( (string) $line );
				return preg_replace(
					'/((?:DB_PASSWORD|DB_USER|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|password|passwd|pwd|secret|api[_-]?key)\b\s*[=:\'"]+\s*)\S+/i',
					'$1[REDACTED]',
					$line
				);
			}, $entries );

			return [
				'path'    => $path,
				'lines'   => count( $entries ),
				'entries' => $entries,
			];
		},

		/* ----------------------------------------------------------------
		 * 2. wp_http_request
		 * ---------------------------------------------------------------- */
		'wp_http_request' => function ( array $a ): array|WP_Error {
			$url = esc_url_raw( $a['url'] );
			if ( empty( $url ) ) {
				return new WP_Error( 'invalid_url', 'A valid URL is required.' );
			}

			// SSRF protection.
			$ssrf_check = cowboy_mcp_validate_url_ssrf( $url );
			if ( is_wp_error( $ssrf_check ) ) {
				return $ssrf_check;
			}

			$method  = strtoupper( $a['method'] ?? 'GET' );
			$timeout = min( max( (int) ( $a['timeout'] ?? 15 ), 1 ), 30 );

			$request_args = [
				'method'      => $method,
				'timeout'     => $timeout,
				'redirection' => 2,
				'sslverify'   => true,
			];

			if ( ! empty( $a['headers'] ) && is_array( $a['headers'] ) ) {
				$request_args['headers'] = array_map( 'sanitize_text_field', $a['headers'] );
			}

			if ( isset( $a['body'] ) ) {
				$request_args['body'] = $a['body'];
			}

			// Use the safe transport so WordPress re-validates each redirect hop against
			// its own SSRF blocklist (defeats DNS-rebinding / 302 → metadata redirects).
			// Power mode uses the unguarded transport so internal addresses are reachable.
			$response = Cowboy_MCP_Security::power_mode_enabled()
				? wp_remote_request( $url, $request_args )
				: wp_safe_remote_request( $url, $request_args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$headers     = wp_remote_retrieve_headers( $response );
			$body        = wp_remote_retrieve_body( $response );

			// Truncate body to 50KB.
			$max_body    = 50 * 1024;
			$truncated   = false;
			if ( strlen( $body ) > $max_body ) {
				$body      = substr( $body, 0, $max_body );
				$truncated = true;
			}

			// Convert headers to plain array.
			$header_array = [];
			if ( $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || $headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) {
				$header_array = $headers->getAll();
			} elseif ( is_array( $headers ) ) {
				$header_array = $headers;
			}

			return [
				'status_code'    => $status_code,
				'headers'        => $header_array,
				'body'           => $body,
				'body_truncated' => $truncated,
				'redirects'      => (int) ( $response['http_response']?->get_response_object()?->redirects ?? 0 ),
			];
		},

		/* ----------------------------------------------------------------
		 * 3. wp_test_email
		 * ---------------------------------------------------------------- */
		'wp_test_email' => function ( array $a ): array|WP_Error {
			$to = sanitize_email( $a['to'] );
			if ( ! is_email( $to ) ) {
				return new WP_Error( 'invalid_email', "Invalid email address: {$a['to']}" );
			}

			$subject = sanitize_text_field( $a['subject'] ?? 'Cowboy MCP Test Email' );
			$message = sanitize_textarea_field( $a['message'] ?? 'This is a test email sent via Cowboy MCP to verify email configuration.' );

			// Capture PHPMailer errors.
			$phpmailer_errors = [];
			$error_handler    = function ( $wp_error ) use ( &$phpmailer_errors ) {
				$phpmailer_errors[] = $wp_error->get_error_message();
			};
			add_action( 'wp_mail_failed', $error_handler );

			$sent = wp_mail( $to, $subject, $message );

			remove_action( 'wp_mail_failed', $error_handler );

			return [
				'sent'             => $sent,
				'to'               => $to,
				'subject'          => $subject,
				'phpmailer_errors' => $phpmailer_errors,
			];
		},

		/* ----------------------------------------------------------------
		 * 4. wp_get_hooks
		 * ---------------------------------------------------------------- */
		'wp_get_hooks' => function ( array $a ): array|WP_Error {
			global $wp_filter;

			$hook = sanitize_text_field( $a['hook'] );
			if ( empty( $hook ) ) {
				return new WP_Error( 'invalid_params', 'Hook name is required.' );
			}

			if ( ! isset( $wp_filter[ $hook ] ) ) {
				return [
					'hook'      => $hook,
					'exists'    => false,
					'callbacks' => [],
				];
			}

			$filter    = $wp_filter[ $hook ];
			$callbacks = [];

			foreach ( $filter->callbacks as $priority => $hooks ) {
				foreach ( $hooks as $id => $data ) {
					$cb_info = cowboy_mcp_describe_callback( $data['function'] );

					$callbacks[] = [
						'priority'      => $priority,
						'function'      => $cb_info['name'],
						'accepted_args' => $data['accepted_args'],
						'file'          => $cb_info['file'] ?? null,
						'line'          => $cb_info['line'] ?? null,
					];
				}
			}

			// Sort by priority.
			usort( $callbacks, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );

			return [
				'hook'      => $hook,
				'exists'    => true,
				'count'     => count( $callbacks ),
				'callbacks' => $callbacks,
			];
		},

		/* ----------------------------------------------------------------
		 * 5. wp_get_transients
		 * ---------------------------------------------------------------- */
		'wp_get_transients' => function ( array $a ): array|WP_Error {
			global $wpdb;

			$action = $a['action'] ?? 'list';
			$limit  = min( max( (int) ( $a['limit'] ?? 100 ), 1 ), 500 );

			switch ( $action ) {
				case 'delete':
					$name = $a['name'] ?? '';
					if ( empty( $name ) ) {
						return new WP_Error( 'invalid_params', 'Transient name is required for delete action.' );
					}
					$name    = sanitize_key( $name );
					$existed = get_transient( $name );
					$deleted = delete_transient( $name );

					return [
						'action_taken' => 'delete',
						'name'         => $name,
						'deleted'      => $deleted,
						'existed'      => $existed !== false,
					];

				case 'cleanup':
					// Delete all expired transients.
					$time = time();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$expired = $wpdb->query( $wpdb->prepare(
						"DELETE a, b FROM {$wpdb->options} a
						 INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, CHAR_LENGTH('_transient_') + 1))
						 WHERE a.option_name LIKE %s
						 AND b.option_value < %d",
						$wpdb->esc_like( '_transient_' ) . '%',
						$time
					) );

					return [
						'action_taken'  => 'cleanup',
						'expired_pairs' => (int) ( $expired / 2 ),  // Each transient = value row + timeout row.
					];

				case 'search':
					$search = $a['search'] ?? '';
					if ( empty( $search ) ) {
						return new WP_Error( 'invalid_params', 'Search pattern is required for search action.' );
					}
					$like = '%' . $wpdb->esc_like( '_transient_' ) . $wpdb->esc_like( $search ) . '%';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT option_name, option_value FROM {$wpdb->options}
						 WHERE option_name LIKE %s
						 AND option_name NOT LIKE %s
						 ORDER BY option_name
						 LIMIT %d",
						$like,
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						$limit
					), ARRAY_A );

					return [
						'action_taken' => 'search',
						'search'       => $search,
						'transients'   => array_map( function ( $row ) use ( $wpdb ) {
							$name    = str_replace( '_transient_', '', $row['option_name'] );
							$timeout = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
								"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
								'_transient_timeout_' . $name
							) );
							$value = maybe_unserialize( $row['option_value'] );

							return [
								'name'    => $name,
								'value'   => Cowboy_MCP_Security::is_sensitive_option( $name )
									? '[REDACTED]'
									: ( is_string( $value ) ? mb_substr( $value, 0, 500 ) : $value ),
								'expires' => $timeout ? gmdate( 'Y-m-d H:i:s', (int) $timeout ) : 'permanent',
							];
						}, $rows ?: [] ),
					];

				case 'list':
				default:
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT option_name, option_value FROM {$wpdb->options}
						 WHERE option_name LIKE %s
						 AND option_name NOT LIKE %s
						 ORDER BY option_name
						 LIMIT %d",
						$wpdb->esc_like( '_transient_' ) . '%',
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						$limit
					), ARRAY_A );

					return [
						'action_taken' => 'list',
						'transients'   => array_map( function ( $row ) use ( $wpdb ) {
							$name    = str_replace( '_transient_', '', $row['option_name'] );
							$timeout = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
								"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
								'_transient_timeout_' . $name
							) );
							$value = maybe_unserialize( $row['option_value'] );

							return [
								'name'    => $name,
								'value'   => Cowboy_MCP_Security::is_sensitive_option( $name )
									? '[REDACTED]'
									: ( is_string( $value ) ? mb_substr( $value, 0, 500 ) : $value ),
								'expires' => $timeout ? gmdate( 'Y-m-d H:i:s', (int) $timeout ) : 'permanent',
							];
						}, $rows ?: [] ),
					];
			}
		},

		/* ----------------------------------------------------------------
		 * 6. wp_get_rest_routes
		 * ---------------------------------------------------------------- */
		'wp_get_rest_routes' => function ( array $a ): array|WP_Error {
			$server = rest_get_server();
			$routes = $server->get_routes();

			$ns_filter     = $a['namespace'] ?? '';
			$search_filter = $a['search'] ?? '';
			$result        = [];

			foreach ( $routes as $pattern => $endpoints ) {
				// Determine namespace from route pattern.
				$route_ns = '';
				$parts    = explode( '/', ltrim( $pattern, '/' ), 3 );
				if ( count( $parts ) >= 2 ) {
					$route_ns = $parts[0] . '/' . $parts[1];
				}

				// Filter by namespace.
				if ( $ns_filter !== '' && $route_ns !== $ns_filter ) {
					continue;
				}

				// Filter by search.
				if ( $search_filter !== '' && stripos( $pattern, $search_filter ) === false ) {
					continue;
				}

				$methods = [];
				foreach ( $endpoints as $endpoint ) {
					if ( isset( $endpoint['methods'] ) ) {
						$methods = array_merge( $methods, array_keys( (array) $endpoint['methods'] ) );
					}
				}
				$methods = array_unique( $methods );

				$result[] = [
					'pattern'   => $pattern,
					'namespace' => $route_ns,
					'methods'   => array_values( $methods ),
				];
			}

			return [
				'count'  => count( $result ),
				'routes' => $result,
			];
		},

		/* ----------------------------------------------------------------
		 * 7. wp_regenerate_thumbnails
		 * ---------------------------------------------------------------- */
		'wp_regenerate_thumbnails' => function ( array $a ): array|WP_Error {
			$results = [];

			if ( ! empty( $a['attachment_id'] ) ) {
				// Single attachment.
				$id   = (int) $a['attachment_id'];
				$post = get_post( $id );
				if ( ! $post || $post->post_type !== 'attachment' ) {
					return new WP_Error( 'not_found', "Attachment {$id} not found." );
				}
				if ( ! wp_attachment_is_image( $id ) ) {
					return new WP_Error( 'not_image', "Attachment {$id} is not an image." );
				}

				$file = get_attached_file( $id );
				if ( ! $file || ! is_file( $file ) ) {
					return new WP_Error( 'file_missing', "Source file not found for attachment {$id}." );
				}

				$metadata = Cowboy_MCP_Compat::generate_attachment_metadata( $id, $file );
				if ( is_wp_error( $metadata ) ) {
					return $metadata;
				}
				wp_update_attachment_metadata( $id, $metadata );

				$sizes = isset( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : [];
				$results[] = [
					'attachment_id' => $id,
					'status'        => 'success',
					'sizes'         => $sizes,
				];
			} else {
				// Batch processing.
				$batch_size = min( max( (int) ( $a['batch_size'] ?? 10 ), 1 ), 50 );
				$offset     = max( (int) ( $a['offset'] ?? 0 ), 0 );

				$query = new WP_Query( [
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'posts_per_page' => $batch_size,
					'offset'         => $offset,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				] );

				foreach ( $query->posts as $id ) {
					$file = get_attached_file( $id );
					if ( ! $file || ! is_file( $file ) ) {
						$results[] = [
							'attachment_id' => $id,
							'status'        => 'error',
							'error'         => 'Source file not found.',
						];
						continue;
					}

					$metadata = Cowboy_MCP_Compat::generate_attachment_metadata( $id, $file );
					if ( is_wp_error( $metadata ) ) {
						$results[] = [
							'attachment_id' => $id,
							'status'        => 'error',
							'error'         => $metadata->get_error_message(),
						];
						continue;
					}
					wp_update_attachment_metadata( $id, $metadata );

					$sizes = isset( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : [];
					$results[] = [
						'attachment_id' => $id,
						'status'        => 'success',
						'sizes'         => $sizes,
					];
				}
			}

			return [
				'processed' => count( $results ),
				'results'   => $results,
			];
		},

		/* ----------------------------------------------------------------
		 * 8. wp_get_rewrite_rules
		 * ---------------------------------------------------------------- */
		'wp_get_rewrite_rules' => function ( array $a ): array {
			global $wp_rewrite;

			$rules = $wp_rewrite->wp_rewrite_rules();
			if ( ! $rules ) {
				$rules = get_option( 'rewrite_rules', [] );
			}
			if ( ! is_array( $rules ) ) {
				$rules = [];
			}

			$test_url = $a['test_url'] ?? '';
			$search   = $a['search'] ?? '';
			$result   = [
				'total_rules' => count( $rules ),
			];

			// Test a URL against rewrite rules.
			if ( $test_url !== '' ) {
				$test_url     = ltrim( sanitize_text_field( $test_url ), '/' );
				$matched_rule = null;
				$matched_query = null;

				foreach ( $rules as $regex => $query ) {
					if ( preg_match( "#^{$regex}#", $test_url, $matches ) ) {
						$matched_rule  = $regex;
						$matched_query = $query;

						// Substitute matched groups into the query.
						foreach ( $matches as $i => $match ) {
							if ( $i > 0 ) {
								$matched_query = str_replace( '$matches[' . $i . ']', $match, $matched_query );
							}
						}
						break;
					}
				}

				$result['test_url']      = $test_url;
				$result['matched_rule']  = $matched_rule;
				$result['matched_query'] = $matched_query;
				$result['matched']       = $matched_rule !== null;
			}

			// Filter/search rules.
			$filtered = [];
			foreach ( $rules as $regex => $query ) {
				if ( $search !== '' && stripos( $regex, $search ) === false && stripos( $query, $search ) === false ) {
					continue;
				}
				$filtered[] = [
					'regex' => $regex,
					'query' => $query,
				];
			}

			$result['rules'] = $filtered;

			return $result;
		},

		/* ----------------------------------------------------------------
		 * 9. wp_snapshot
		 * ---------------------------------------------------------------- */
		'wp_snapshot' => function ( array $a ): array {
			$all_sections = [ 'options', 'plugins', 'theme', 'widgets', 'menus', 'rewrite_rules' ];
			$sections     = $a['sections'] ?? $all_sections;

			// Validate sections.
			$sections = array_intersect( (array) $sections, $all_sections );
			if ( empty( $sections ) ) {
				$sections = $all_sections;
			}

			$snapshot = [];

			if ( in_array( 'options', $sections, true ) ) {
				$option_keys = [
					'blogname', 'blogdescription', 'siteurl', 'home',
					'permalink_structure', 'date_format', 'time_format',
					'timezone_string', 'WPLANG', 'posts_per_page',
					'default_comment_status', 'default_ping_status',
					'blog_public', 'default_role', 'users_can_register',
				];
				$options = [];
				foreach ( $option_keys as $key ) {
					$options[ $key ] = get_option( $key );
				}
				$snapshot['options'] = $options;
			}

			if ( in_array( 'plugins', $sections, true ) ) {
				$active  = get_option( 'active_plugins', [] );
				$plugins = [];
				$all     = Cowboy_MCP_Compat::get_plugins();
				foreach ( $active as $plugin_file ) {
					$data = $all[ $plugin_file ] ?? [];
					$plugins[] = [
						'file'    => $plugin_file,
						'name'    => $data['Name'] ?? $plugin_file,
						'version' => $data['Version'] ?? 'unknown',
					];
				}
				$snapshot['plugins'] = $plugins;
			}

			if ( in_array( 'theme', $sections, true ) ) {
				$theme = wp_get_theme();
				$snapshot['theme'] = [
					'name'       => $theme->get( 'Name' ),
					'slug'       => $theme->get_stylesheet(),
					'version'    => $theme->get( 'Version' ),
					'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
					'template'   => get_template(),
					'stylesheet' => get_stylesheet(),
				];
			}

			if ( in_array( 'widgets', $sections, true ) ) {
				$sidebars_widgets = get_option( 'sidebars_widgets', [] );
				unset( $sidebars_widgets['array_version'] );

				$widget_settings = [];
				foreach ( $sidebars_widgets as $sidebar_id => $widgets ) {
					if ( ! is_array( $widgets ) ) {
						continue;
					}
					$widget_settings[ $sidebar_id ] = $widgets;
				}
				$snapshot['widgets'] = $widget_settings;
			}

			if ( in_array( 'menus', $sections, true ) ) {
				$locations = get_nav_menu_locations();
				$menus     = [];
				foreach ( $locations as $location => $menu_id ) {
					$menu_obj = wp_get_nav_menu_object( $menu_id );
					$menus[ $location ] = [
						'menu_id' => $menu_id,
						'name'    => $menu_obj ? $menu_obj->name : '(unset)',
						'count'   => $menu_obj ? $menu_obj->count : 0,
					];
				}
				$snapshot['menus'] = $menus;
			}

			if ( in_array( 'rewrite_rules', $sections, true ) ) {
				$rules = get_option( 'rewrite_rules', [] );
				$snapshot['rewrite_rules'] = is_array( $rules ) ? count( $rules ) . ' rules' : 'not set';
			}

			// Generate hash for quick comparison.
			$hash = md5( wp_json_encode( $snapshot ) );

			return [
				'hash'      => $hash,
				'timestamp' => gmdate( 'Y-m-d H:i:s' ),
				'sections'  => $snapshot,
			];
		},

		/* ----------------------------------------------------------------
		 * 10. wp_connection_doctor
		 * ---------------------------------------------------------------- */
		'wp_connection_doctor' => function ( array $a ): array {
			$results           = Cowboy_MCP_Doctor::run_checks( [ 'skip_auth_roundtrip' => true ] );
			$results['report'] = Cowboy_MCP_Doctor::render_report( $results );
			return $results;
		},
	],
];
