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
		$checks = [];
		foreach ( self::config_checks() as $c ) {
			$checks[] = $c;
		}
		// Loopback checks appended in Task 2; environment in Task 2.
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
			'environment' => [],
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

		// https
		$out[] = self::result(
			'https',
			'Site uses HTTPS',
			$is_https ? 'pass' : ( $oauth_on ? 'fail' : 'warn' ),
			$is_https ? 'Site address uses https.' : 'Site address uses plain http.',
			[ 'home_url: ' . home_url() ],
			null,
			$is_https ? null : 'MCP connectors (claude.ai, ChatGPT) require a public HTTPS address. Install an SSL certificate and update the Site Address.'
		);

		// public_hostname
		$local = self::hostname_is_local( $host );
		$out[] = self::result(
			'public_hostname',
			'Hostname publicly reachable',
			$local ? 'fail' : 'pass',
			$local ? "Hostname '{$host}' is local or resolves to a private address - remote clients cannot reach it." : "Hostname '{$host}' looks publicly resolvable.",
			[ 'host: ' . $host ],
			$local ? 'local_host' : null,
			$local ? 'Remote MCP clients need a public hostname. On local dev, use a tunnel (e.g. your host\'s preview URL) or test with Claude Code on the same machine.' : null
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

		return $out;
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
	public static function ajax_run(): void {}
	public static function cli_run( array $args, array $assoc_args ): void {}
}
