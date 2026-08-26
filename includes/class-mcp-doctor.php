<?php
/**
 * Connection Doctor - self-test engine diagnosing MCP connection failures.
 *
 * Shared by three surfaces: admin AJAX (Connection tab), WP-CLI, and the
 * wp_connection_doctor MCP tool. See docs spec 2026-07-18-connection-doctor.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Doctor {

	public static function init(): void {
		add_action( 'wp_ajax_cowboy_mcp_doctor', [ __CLASS__, 'ajax_run' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cowboy-mcp doctor', [ __CLASS__, 'cli_run' ] );
		}
	}

	/**
	 * Run the full check suite.
	 *
	 * @param array $opts { skip_auth_roundtrip?: bool }  The MCP tool sets
	 *              skip_auth_roundtrip (reaching the tool proves the authed path).
	 */
	public static function run_checks( array $opts = [] ): array {
		$checks      = [];
		$env_headers = [];

		// Group 1: config checks
		try {
			foreach ( self::config_checks() as $c ) {
				$checks[] = $c;
			}
		} catch ( Throwable $e ) {
			$checks[] = self::result( 'engine_error', 'Doctor internal error', 'error', $e->getMessage() );
		}

		// Group 2: loopback checks
		try {
			$lb          = self::loopback_checks( $opts );
			$env_headers = $lb['env_headers'];
			foreach ( $lb['checks'] as $c ) {
				$checks[] = $c;
			}
		} catch ( Throwable $e ) {
			$checks[] = self::result( 'engine_error', 'Doctor internal error', 'error', $e->getMessage() );
		}

		$counts = [ 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'error' => 0 ];
		foreach ( $checks as $c ) {
			++$counts[ $c['status'] ];
		}
		$summary = $counts['fail'] > 0 || $counts['error'] > 0 ? 'fail' : ( $counts['warn'] > 0 ? 'warn' : 'pass' );

		Cowboy_MCP_Audit_Log::log( 'doctor_run', [ 'summary' => $summary, 'counts' => $counts ] );

		return [
			'summary'     => $summary,
			'counts'      => $counts,
			'checks'      => $checks,
			'environment' => self::environment( $env_headers ),
		];
	}

	/** Build one normalized check row. */
	private static function result( string $id, string $label, string $status, string $detail, array $evidence = [], ?string $fingerprint = null, ?string $fix = null ): array {
		return compact( 'id', 'label', 'status', 'detail', 'evidence', 'fingerprint', 'fix' );
	}

	/** Group 1: configuration checks - no HTTP requests. */
	private static function config_checks(): array {
		$out      = [];
		$settings = Cowboy_MCP_Tools::get_settings();
		$oauth_on = ! empty( $settings['oauth_enabled'] );
		$host     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$is_https = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );

		// server_enabled
		$on    = ! empty( $settings['enabled'] );
		$out[] = self::result(
			'server_enabled',
			'MCP server enabled',
			$on ? 'pass' : 'fail',
			$on ? 'The MCP server is turned on.' : 'The MCP server is disabled - all requests are rejected.',
			[],
			null,
			$on ? null : 'Enable the server under Settings > Cowboy MCP > Settings.'
		);

		// Local-dev awareness reframes the https/public_hostname checks below.
		// ADVISORY ONLY (display copy + statuses) — never gates any behavior.
		$local = self::hostname_is_local( $host );

		// https
		if ( $is_https ) {
			$out[] = self::result( 'https', 'Site uses HTTPS', 'pass', 'Site address uses https.', [ 'home_url: ' . home_url() ] );
		} elseif ( $local && ! $oauth_on ) {
			// Plain http on a local dev site is normal; terminal clients on the
			// same machine do not need TLS. Cloud-connector intent (oauth_on)
			// keeps the hard requirement.
			$out[] = self::result( 'https', 'Site uses HTTPS', 'pass', 'Site address uses plain http - normal for a local development site.', [ 'home_url: ' . home_url() ] );
		} else {
			$out[] = self::result(
				'https',
				'Site uses HTTPS',
				$oauth_on ? 'fail' : 'warn',
				'Site address uses plain http.',
				[ 'home_url: ' . home_url() ],
				null,
				'MCP connectors (claude.ai, ChatGPT) require a public HTTPS address. Install an SSL certificate and update the Site Address.'
			);
		}

		// public_hostname
		$out[] = self::result(
			'public_hostname',
			'Hostname publicly reachable',
			$local ? 'warn' : 'pass',
			$local ? "Hostname '{$host}' is local or resolves to a private address - terminal AI tools on this machine can connect, but cloud clients (claude.ai, ChatGPT) cannot reach it." : "Hostname '{$host}' looks publicly resolvable.",
			[ 'host: ' . $host ],
			$local ? 'local_host' : null,
			$local ? 'Terminal clients (Claude Code, Cursor, Codex...) work locally as-is, and Claude Desktop can connect through the local bridge on the Connection tab. claude.ai and ChatGPT need a public URL (tunnel or staging).' : null
		);

		// permalinks
		$pretty = (string) get_option( 'permalink_structure' );
		$out[]  = self::result(
			'permalinks',
			'Pretty permalinks enabled',
			'' !== $pretty ? 'pass' : 'warn',
			'' !== $pretty ? 'Permalink structure set.' : 'Plain permalinks - /wp-json/ may 404 on some servers.',
			[],
			'' === $pretty ? 'plain_permalinks' : null,
			'' !== $pretty ? null : 'Set any pretty structure under Settings > Permalinks. API-key clients can fall back to ?rest_route=, but OAuth discovery needs /wp-json/ to resolve.'
		);

		// api_keys
		$has_keys = count( Cowboy_MCP_Auth::list_keys() ) > 0;
		$out[]    = self::result(
			'api_keys',
			'At least one API key exists',
			$has_keys ? 'pass' : 'warn',
			$has_keys ? 'API key(s) present.' : 'No API keys generated yet.',
			[],
			null,
			$has_keys ? null : 'Generate a key on the Connection tab, or use the OAuth Desktop Connector instead.'
		);

		// oauth_prereqs
		if ( $oauth_on ) {
			$ok    = $is_https && ! $local;
			$out[] = self::result(
				'oauth_prereqs',
				'OAuth connector prerequisites',
				$ok ? 'pass' : 'fail',
				$ok ? 'Desktop Connector prerequisites met (public HTTPS).' : 'Desktop Connector is enabled but the site is not public HTTPS.',
				[],
				null,
				$ok ? null : 'The Desktop Connector requires a public HTTPS hostname. Fix the checks above or disable the connector.'
			);
		} else {
			$out[] = self::result( 'oauth_prereqs', 'OAuth connector prerequisites', 'skip', 'Desktop Connector is disabled - connector checks skipped.' );
		}

		// rest_blockers
		$known   = [
			'better-wp-security/better-wp-security.php' => [ 'Solid Security', 'allow the cowboy-mcp/v1 REST namespace in its REST API settings' ],
			'disable-json-api/disable-json-api.php'     => [ 'Disable REST API', 'allow the cowboy-mcp/v1 namespace in the plugin settings' ],
			'wp-rest-api-controller/wp-rest-api-controller.php' => [ 'REST API Controller', 'ensure the cowboy-mcp/v1 namespace is not disabled' ],
		];
		$active  = (array) get_option( 'active_plugins', [] );
		$matched = array_intersect_key( $known, array_flip( $active ) );
		if ( $matched ) {
			$names = implode( ', ', array_column( $matched, 0 ) );
			$fixes = implode( ' ', array_map( fn( $m ) => ucfirst( $m[0] ) . ': ' . $m[1] . '.', $matched ) );
			$out[] = self::result( 'rest_blockers', 'REST-blocking plugins', 'warn', "Plugin(s) that can disable REST routes are active: {$names}.", [], 'rest_blocker_plugin', $fixes );
		} else {
			$out[] = self::result( 'rest_blockers', 'REST-blocking plugins', 'pass', 'No known REST-disabling security plugin active.' );
		}

		// abilities_bridge — informational; never warn/fail.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$out[] = self::result( 'abilities_bridge', 'WordPress Abilities API bridge', 'skip', 'Abilities API needs WordPress 6.9 or newer (this site runs ' . get_bloginfo( 'version' ) . ').' );
		} elseif ( empty( $settings['abilities_expose'] ?? true ) && empty( $settings['abilities_consume'] ?? true ) ) {
			$out[] = self::result( 'abilities_bridge', 'WordPress Abilities API bridge', 'skip', 'Bridge disabled in Settings.' );
		} else {
			$stats = Cowboy_MCP_Abilities::doctor_stats();
			// enabled() covers both the outbound switch and the server master switch:
			// with either off, "0 registered (0 withheld)" would read like a fault.
			$detail = Cowboy_MCP_Abilities::enabled()
				? sprintf(
					'Outbound: %d tools registered as cowboy-mcp/* abilities (%d withheld by allowed_tools/Power mode). Inbound: %d abilities from other plugins available as tools.',
					$stats['registered'],
					$stats['withheld'],
					$stats['inbound']
				)
				: sprintf(
					'Outbound: switched off. Inbound: %d abilities from other plugins available as tools.',
					$stats['inbound']
				);
			$out[] = self::result( 'abilities_bridge', 'WordPress Abilities API bridge', 'pass', $detail, $stats['evidence'] );
		}

		return $out;
	}

	/**
	 * Loopback request against the site's OWN configured URL.
	 * Deliberately bypasses Cowboy_MCP_Security::validate_url_ssrf(): a host's
	 * public name may resolve to a private IP from inside its own network. The
	 * URL is always built here from rest_url()/home_url() - never caller input.
	 */
	private static function loopback( string $method, string $url, array $args = [] ): array|WP_Error {
		return wp_remote_request( $url, array_merge( [
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 2,
			'headers'     => [ 'Accept' => 'application/json, text/event-stream' ],
		], $args ) );
	}

	private static function initialize_body(): string {
		return (string) wp_json_encode( [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => '2025-06-18',
				'capabilities'    => (object) [],
				'clientInfo'      => [ 'name' => 'cowboy-doctor', 'version' => COWBOY_MCP_VERSION ],
			],
		] );
	}

	/** Group 2: loopback HTTP checks. Returns checks plus response headers for the environment block. */
	private static function loopback_checks( array $opts ): array {
		$checks     = [];
		$env_headers = [];
		$settings   = Cowboy_MCP_Tools::get_settings();
		$oauth_on   = ! empty( $settings['oauth_enabled'] );
		$endpoint   = rest_url( 'cowboy-mcp/v1/endpoint' );

		// loopback_rest_index
		$r = self::loopback( 'GET', rest_url() );
		if ( is_wp_error( $r ) ) {
			$checks[] = self::result( 'loopback_rest_index', 'REST API reachable (loopback)', 'fail', 'Could not reach the REST API from the server: ' . $r->get_error_message(), [], null, 'The server cannot request its own public URL. Check DNS/firewall from the host, or ask your host about loopback requests.' );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $r );
			$body = (string) wp_remote_retrieve_body( $r );
			$json = json_decode( $body, true );
			$ok   = 200 === $code && is_array( $json ) && in_array( 'cowboy-mcp/v1', (array) ( $json['namespaces'] ?? [] ), true );
			[ $fp, $fix ] = $ok ? [ null, null ] : self::classify( $code, wp_remote_retrieve_headers( $r )->getAll(), $body );
			$checks[] = self::result( 'loopback_rest_index', 'REST API reachable (loopback)', $ok ? 'pass' : 'fail', $ok ? 'REST index responds and lists the cowboy-mcp/v1 namespace.' : "REST index did not return the expected JSON (HTTP {$code}).", [ 'HTTP ' . $code ], $fp, $ok ? null : ( $fix ?? 'The REST API may be disabled or blocked. See the evidence line.' ) );
			$env_headers = wp_remote_retrieve_headers( $r )->getAll();
		}

		// loopback_endpoint_unauth - expect clean 401 JSON
		$r = self::loopback( 'POST', $endpoint, [ 'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream' ], 'body' => self::initialize_body() ] );
		if ( is_wp_error( $r ) ) {
			$checks[] = self::result( 'loopback_endpoint_unauth', 'MCP endpoint answers POST (loopback)', 'fail', 'POST failed: ' . $r->get_error_message() );
		} else {
			$code    = (int) wp_remote_retrieve_response_code( $r );
			$headers = wp_remote_retrieve_headers( $r )->getAll();
			$body    = (string) wp_remote_retrieve_body( $r );
			$clean   = 401 === $code && null !== json_decode( $body, true ) && "\xEF\xBB\xBF" !== substr( $body, 0, 3 ) && '' !== ltrim( $body ) && '{' === ltrim( $body )[0];
			$breadcrumb_missing = $oauth_on && empty( $headers['www-authenticate'] );
			[ $fp, $fix ] = $clean ? [ null, null ] : self::classify( $code, $headers, $body );
			$status = $clean ? ( $breadcrumb_missing ? 'warn' : 'pass' ) : 'fail';
			$detail = $clean
				? ( $breadcrumb_missing ? 'Endpoint returns clean 401 JSON but the WWW-Authenticate OAuth breadcrumb header is missing (a proxy may be stripping it).' : 'Endpoint returns a clean 401 JSON challenge - auth layer reachable.' )
				: "Expected a JSON 401 challenge, got HTTP {$code}.";
			$evidence = [ 'HTTP ' . $code, 'content-type: ' . ( $headers['content-type'] ?? '?' ), 'body: ' . Cowboy_MCP_Security::scrub_secrets( substr( $body, 0, 200 ) ) ];
			$checks[] = self::result( 'loopback_endpoint_unauth', 'MCP endpoint answers POST (loopback)', $status, $detail, $evidence, $fp, $fix );
		}

		// loopback_endpoint_auth - ephemeral key round-trip
		if ( ! empty( $opts['skip_auth_roundtrip'] ) ) {
			$checks[] = self::result( 'loopback_endpoint_auth', 'Authenticated MCP handshake', 'pass', 'You reached this tool through the authenticated endpoint - the path is proven working.' );
		} else {
			$minted = Cowboy_MCP_Auth::generate_key( 'Connection Doctor self-test (auto-revoked)' );
			Cowboy_MCP_Audit_Log::log( 'doctor_key_minted', [ 'key_id' => $minted['id'] ] );
			try {
				$r = self::loopback( 'POST', $endpoint, [ 'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $minted['key'] ], 'body' => self::initialize_body() ] );
				if ( is_wp_error( $r ) ) {
					$checks[] = self::result( 'loopback_endpoint_auth', 'Authenticated MCP handshake', 'fail', 'POST failed: ' . $r->get_error_message() );
				} else {
					$code = (int) wp_remote_retrieve_response_code( $r );
					$json = json_decode( (string) wp_remote_retrieve_body( $r ), true );
					$ok   = 200 === $code && isset( $json['result']['serverInfo'] );
					$checks[] = self::result( 'loopback_endpoint_auth', 'Authenticated MCP handshake', $ok ? 'pass' : 'fail', $ok ? 'Full initialize handshake succeeded with a self-test key.' : "Handshake failed (HTTP {$code}).", [ 'HTTP ' . $code ], null, $ok ? null : 'Auth or dispatch layer problem - check the Logs tab for the matching error.' );
				}
			} finally {
				Cowboy_MCP_Auth::revoke_key( $minted['id'] );
				Cowboy_MCP_Audit_Log::log( 'doctor_key_revoked', [ 'key_id' => $minted['id'] ] );
			}
		}

		// well_known_* (OAuth discovery at site root)
		foreach ( [ 'well_known_pr' => '/.well-known/oauth-protected-resource', 'well_known_as' => '/.well-known/oauth-authorization-server' ] as $id => $path ) {
			if ( ! $oauth_on ) {
				$checks[] = self::result( $id, 'OAuth discovery: ' . $path, 'skip', 'Desktop Connector disabled - skipped.' );
				continue;
			}
			$r = self::loopback( 'GET', home_url( $path ) );
			if ( is_wp_error( $r ) ) {
				$checks[] = self::result( $id, 'OAuth discovery: ' . $path, 'fail', 'Request failed: ' . $r->get_error_message() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$json = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			$want = 'well_known_pr' === $id ? 'resource' : 'issuer';
			$ok   = 200 === $code && isset( $json[ $want ] );
			[ $fp, $fix ] = $ok ? [ null, null ] : self::classify( $code, wp_remote_retrieve_headers( $r )->getAll(), (string) wp_remote_retrieve_body( $r ) );
			$checks[] = self::result( $id, 'OAuth discovery: ' . $path, $ok ? 'pass' : 'fail', $ok ? 'Discovery document served correctly.' : "Expected JSON with '{$want}', got HTTP {$code}.", [ 'HTTP ' . $code ], $fp, $ok ? null : ( $fix ?? 'A cache plugin or server rule is likely intercepting root .well-known paths - exclude them from caching/rewrites.' ) );
		}

		return [ 'checks' => $checks, 'env_headers' => $env_headers ];
	}

	/**
	 * Declarative fingerprint table - shared verbatim with admin JS, so keep
	 * matchers to: status list, one header regex, one body regex (all optional,
	 * all present ones must match). First match wins. English on purpose.
	 */
	public static function fingerprints_for_js(): array {
		return [
			[ 'id' => 'cloudflare_challenge', 'status' => [ 403, 503 ], 'header' => [ 'server', '/cloudflare/i' ], 'body_regex' => '/just a moment|cf-chl|challenge-platform/i', 'fix' => 'Cloudflare is challenging the request. Add a WAF skip rule (or disable Bot Fight Mode) for /wp-json/cowboy-mcp/* and /.well-known/oauth-*.' ],
			[ 'id' => 'modsecurity', 'status' => [ 403, 406 ], 'header' => null, 'body_regex' => '/mod_security|modsecurity|not acceptable/i', 'fix' => 'A web application firewall (likely ModSecurity) is blocking JSON POSTs. Ask your host to allowlist POST /wp-json/cowboy-mcp/v1/endpoint.' ],
			[ 'id' => 'basic_auth', 'status' => [ 401 ], 'header' => [ 'www-authenticate', '/basic/i' ], 'body_regex' => null, 'fix' => 'Server-level Basic Auth answers before WordPress. Exclude /wp-json/cowboy-mcp/ and /.well-known/ from Basic Auth - MCP clients cannot send two Authorization headers.' ],
			[ 'id' => 'dirty_json', 'status' => null, 'header' => null, 'body_regex' => '/^(\xEF\xBB\xBF|\s+)[\{\[]/', 'fix' => 'The JSON response has junk before it (BOM, whitespace, or a PHP notice). Set WP_DEBUG_DISPLAY to false and deactivate plugins one by one to find the one printing output.' ],
			[ 'id' => 'html_error_page', 'status' => null, 'header' => null, 'body_regex' => '/^\s*<(!doctype|html)/i', 'fix' => 'An HTML page came back where JSON was expected - commonly a maintenance page, a login redirect, or a cache plugin. Exclude /wp-json/cowboy-mcp/ from page caching.' ],
		];
	}

	/** Match a response against the table. Returns [fingerprint_id|null, fix|null]. */
	private static function classify( int $code, array $headers, string $body ): array {
		$headers = array_change_key_case( $headers, CASE_LOWER );
		foreach ( self::fingerprints_for_js() as $fp ) {
			if ( $fp['status'] && ! in_array( $code, $fp['status'], true ) ) {
				continue;
			}
			if ( $fp['header'] ) {
				[ $name, $rx ] = $fp['header'];
				$val = $headers[ $name ] ?? '';
				$val = is_array( $val ) ? implode( ',', $val ) : $val;
				if ( ! preg_match( $rx, $val ) ) {
					continue;
				}
			}
			if ( $fp['body_regex'] && ! preg_match( $fp['body_regex'], $body ) ) {
				continue;
			}
			return [ $fp['id'], $fp['fix'] ];
		}
		return [ null, null ];
	}

	/** Plain-text copy-paste report. English on purpose (support threads + AI agents). */
	public static function render_report( array $results ): string {
		$e     = $results['environment'];
		$chain = $e['proxy_headers']['server'] ?? '';
		$lines = [
			'=== Cowboy MCP Connection Doctor - ' . gmdate( 'Y-m-d H:i' ) . ' UTC ===',
			sprintf( 'Site: %s | WP %s | PHP %s | Cowboy MCP %s | Server: %s', home_url(), $e['wp'] ?? '?', $e['php'] ?? '?', $e['plugin'] ?? '?', $chain ?: ( $e['server_software'] ?? '?' ) ),
			sprintf( 'Summary: %d failed, %d warnings, %d passed, %d skipped', $results['counts']['fail'] + $results['counts']['error'], $results['counts']['warn'], $results['counts']['pass'], $results['counts']['skip'] ),
			'',
		];
		foreach ( $results['checks'] as $c ) {
			$tag = '[' . strtoupper( 'error' === $c['status'] ? 'FAIL' : $c['status'] ) . ']';
			if ( 'skip' === $c['status'] ) {
				$lines[] = "{$tag} {$c['label']}";
				continue;
			}
			$lines[] = "{$tag} {$c['label']} - {$c['detail']}";
			if ( 'pass' === $c['status'] ) {
				continue;   // passing checks carry counts worth reading; evidence and fixes belong to problems
			}
			foreach ( $c['evidence'] as $ev ) {
				$lines[] = '       ' . $ev;
			}
			if ( $c['fingerprint'] ) {
				$lines[] = '       Fingerprint: ' . $c['fingerprint'];
			}
			if ( $c['fix'] ) {
				$lines[] = '       Fix: ' . $c['fix'];
			}
			$lines[] = '';
		}
		if ( isset( $e['shell_exec'] ) && ! $e['shell_exec'] ) {
			$lines[] = 'Note: shell_exec is disabled on this host - the wp_cli tool will not work (all native tools are unaffected).';
		}
		if ( false !== stripos( $chain, 'litespeed' ) ) {
			$lines[] = 'Note: LiteSpeed server detected - if LiteSpeed Cache is active, exclude /wp-json/cowboy-mcp/ from caching.';
		}
		$lines[] = '';
		$lines[] = 'Need help? Start a topic at https://wordpress.org/support/plugin/cowboy-mcp/ and paste this report.';
		return implode( "\n", $lines );
	}

	/** Group 3: environment context (not pass/fail). */
	private static function environment( array $env_headers ): array {
		$proxy = [];
		foreach ( [ 'server', 'cf-ray', 'x-litespeed-cache', 'x-cache', 'via' ] as $h ) {
			if ( isset( $env_headers[ $h ] ) ) {
				$proxy[ $h ] = is_array( $env_headers[ $h ] ) ? implode( ',', $env_headers[ $h ] ) : $env_headers[ $h ];
			}
		}
		return [
			'wp'              => get_bloginfo( 'version' ),
			'php'             => PHP_VERSION,
			'plugin'          => COWBOY_MCP_VERSION,
			'server_software' => sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? 'unknown' ) ),
			'proxy_headers'   => $proxy,
			'shell_exec'      => function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ),
		];
	}

	/** True when a hostname is local-only or resolves to a private/reserved IP. */
	private static function hostname_is_local( string $host ): bool {
		if ( '' === $host ) {
			return true;
		}
		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) || preg_match( '/\.(localhost|local|test|internal|invalid|example)$/', $host ) ) {
			return true;
		}
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host . '.' );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false; // Unresolvable from here - do not condemn; loopback checks will tell.
		}
		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	// AJAX + CLI adapters implemented in Tasks 4 and 7.
	public static function ajax_run(): void {
		check_ajax_referer( 'cowboy_mcp_doctor' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$settings = Cowboy_MCP_Tools::get_settings();
		$results  = self::run_checks();
		wp_send_json_success( [
			'results'      => $results,
			'report'       => self::render_report( $results ),
			'fingerprints' => self::fingerprints_for_js(),
			'probes'       => [
				'endpoint'   => rest_url( 'cowboy-mcp/v1/endpoint' ),
				'well_known' => ! empty( $settings['oauth_enabled'] )
					? [ home_url( '/.well-known/oauth-protected-resource' ), home_url( '/.well-known/oauth-authorization-server' ) ]
					: [],
			],
		] );
	}
	public static function cli_run( array $args, array $assoc_args ): void {
		$results = self::run_checks();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::print_value( $results, [ 'format' => 'json' ] );
		} else {
			\WP_CLI\Utils\format_items( 'table', array_map( static fn( $c ) => [
				'status' => $c['status'],
				'check'  => $c['label'],
				'detail' => $c['detail'],
			], $results['checks'] ), [ 'status', 'check', 'detail' ] );
			\WP_CLI::log( "\n" . self::render_report( $results ) );
		}
		if ( 'fail' === $results['summary'] ) {
			\WP_CLI::halt( 1 );
		}
	}
}
