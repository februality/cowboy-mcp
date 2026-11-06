<?php
/**
 * Which browser sign-in server the site advertises: the root one (/.well-known/ at the
 * host root) or the one under /wp-json. This class only holds the stored decision.
 *
 * @package Cowboy_MCP
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_OAuth_Address {

	const OPTION              = 'cowboy_mcp_oauth_address';
	const SITE_AUTHORIZE_PATH = '/cowboy-mcp-oauth/site-authorize';

	/** Request header the self-check sends; its value is a per-run secret (MARKER_PREFIX transient). */
	const SELF_CHECK_HEADER = 'X-Cowboy-MCP-Self-Check';
	/** Options row held while a self-check runs (value "<expiry>|<token>", inserted with INSERT IGNORE). */
	const LOCK = 'cowboy_mcp_oauth_address_lock';
	/** Single cron event scheduled once after an update (VERSION_OPTION differs from the plugin version). */
	const UPGRADE_HOOK   = 'cowboy_mcp_oauth_address_check';
	const VERSION_OPTION = 'cowboy_mcp_oauth_address_ver';
	/** Transient: time of the last root discovery document served to a real (unmarked) request. */
	const ROOT_FETCH_TRANSIENT = 'cowboy_mcp_signin_root_fetch';
	/** Transient name prefix of a live self-check marker value. */
	const MARKER_PREFIX = 'cowboy_mcp_signin_self_check_';

	const LOCK_SECONDS      = 60;
	const STALE_SECONDS     = HOUR_IN_SECONDS;
	const REAL_TRAFFIC_DAYS = 7;
	const SWITCH_BACK_RUNS  = 3;
	const SWITCH_BACK_SPAN  = DAY_IN_SECONDS;
	/** How long a sighting of Cloudflare in front of the site is remembered (cron runs see no CF-Ray). */
	const CDN_MEMORY = 30 * DAY_IN_SECONDS;
	/** Self-check User-Agent: what the MCP TypeScript SDK sends (Node's fetch), so UA rules treat it like an app. */
	const USER_AGENT = 'node';

	private static function defaults(): array {
		return [
			'override'      => 'auto',
			'mode'          => 'root',
			'reason'        => 'default',
			'checked_at'    => 0,
			'switched_at'   => 0,
			'root_seen_at'  => 0,
			'root_ok_since' => 0,
			'root_ok_runs'  => 0,
			'site_ok_at'    => 0,
			'unreachable'   => false,
			'last'          => [],
			'retry'         => null,
			'cdn_seen_at'   => 0,
		];
	}

	/**
	 * Stored state merged over defaults.
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Merge changes into the freshly read option (so stale copies do not drop each other's fields).
	 * The stored row always carries every key.
	 */
	public static function save( array $changes ): void {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		update_option( self::OPTION, array_merge( self::defaults(), $stored, $changes ), false );
	}

	/**
	 * Path of home_url() without a trailing slash; '' at the host root.
	 */
	public static function home_path(): string {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	public static function pretty_permalinks(): bool {
		return '' !== (string) get_option( 'permalink_structure', '' );
	}

	/**
	 * Issuer of the server under /wp-json; null without pretty permalinks.
	 */
	public static function site_issuer(): ?string {
		if ( ! self::pretty_permalinks() ) {
			return null;
		}
		return untrailingslashit( rest_url( 'cowboy-mcp/v1' ) );
	}

	/**
	 * Decision as array [ 'root'|'site', reason ].
	 */
	private static function decide(): array {
		if ( ! self::pretty_permalinks() ) {
			return [ 'root', 'plain_permalinks' ];
		}
		if ( '' !== self::home_path() ) {
			return [ 'site', 'subfolder' ];
		}
		$state = self::state();
		$forced = apply_filters( 'cowboy_mcp_oauth_address', null );
		if ( 'root' === $forced || 'site' === $forced ) {
			return [ $forced, 'filter' ];
		}
		if ( 'root' === $state['override'] || 'site' === $state['override'] ) {
			return [ $state['override'], 'override' ];
		}
		$mode   = 'site' === $state['mode'] ? 'site' : 'root';
		$reason = in_array( $state['reason'], [ 'blocked', 'unblocked', 'default' ], true ) ? $state['reason'] : 'default';
		return [ $mode, $reason ];
	}

	public static function advertised(): string {
		return self::decide()[0];
	}

	public static function reason(): string {
		return self::decide()[1];
	}

	public static function advertised_issuer(): string {
		if ( 'site' === self::advertised() ) {
			$site = self::site_issuer();
			if ( null !== $site ) {
				return $site;
			}
		}
		return Cowboy_MCP_OAuth::issuer();
	}

	public static function set_override( string $override ): bool {
		if ( ! in_array( $override, [ 'auto', 'root', 'site' ], true ) ) {
			return false;
		}
		self::save( [ 'override' => $override ] );
		return true;
	}

	/* ── Self-check ────────────────────────────────────────── */

	/**
	 * Class one fetch: 'works' = HTTP 200 JSON whose $key equals $value; 'blocked' = any
	 * other HTTP answer (403, 404, HTML, wrong JSON); 'unknown' = no answer (WP_Error:
	 * timeout, refused, DNS, TLS), which never changes anything.
	 */
	public static function classify( array|WP_Error $response, string $key, string $value ): string {
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'blocked';
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $json ) && ( $json[ $key ] ?? null ) === $value ? 'works' : 'blocked';
	}

	/**
	 * Fetch the root PRM, the root AS document and the site server's openid-configuration
	 * the way an app would, and (with $store) apply the switching rules. Never runs inside
	 * an app request. Skipped (all 'unknown', nothing written) while browser sign-in is off
	 * and on hosts host_looks_local() flags - ADVISORY: that only skips the check, so those
	 * sites stay on the root server. With $store = false nothing is written (the agent-run
	 * Doctor); the marker transient lives only while the fetches run.
	 *
	 * @return array{root_pr:string,root_as:string,site:string,mode:string,switched:bool}
	 */
	public static function run_self_check( bool $store = true ): array {
		$out = [ 'root_pr' => 'unknown', 'root_as' => 'unknown', 'site' => 'unknown' ];
		if ( ! self::check_allowed() ) {
			return $out + [ 'mode' => self::advertised(), 'switched' => false ];
		}
		$lock = null;
		if ( $store ) {
			$lock = self::acquire_lock();
			if ( null === $lock ) {
				// Another run is in progress: report what it last stored, fetch nothing.
				$last = self::state()['last'];
				foreach ( array_keys( $out ) as $k ) {
					if ( is_array( $last ) && in_array( $last[ $k ] ?? null, [ 'works', 'blocked', 'unknown' ], true ) ) {
						$out[ $k ] = $last[ $k ];
					}
				}
				return $out + [ 'mode' => self::advertised(), 'switched' => false ];
			}
		}
		try {
			$marker  = self::mint_marker();
			$issuer  = Cowboy_MCP_OAuth::issuer();
			$targets = [
				'root_pr' => [ $issuer . '/.well-known/oauth-protected-resource', 'resource', Cowboy_MCP_OAuth::resource_url() ],
				'root_as' => [ $issuer . '/.well-known/oauth-authorization-server', 'issuer', $issuer ],
			];
			$site = self::site_issuer();
			if ( null !== $site ) {
				$targets['site'] = [ $site . '/.well-known/openid-configuration', 'issuer', $site ];
			} else {
				$out['site'] = 'blocked'; // plain permalinks: there is no site server
			}
			$responses = [];
			foreach ( $targets as $k => [ $url, $key, $value ] ) {
				$responses[ $k ] = self::fetch( $url, $marker );
				$out[ $k ]       = self::classify( $responses[ $k ], $key, $value );
			}
			// A blocked root is confirmed by one retry a moment later (a hiccup is not a block).
			$retry = array_filter( [ 'root_pr', 'root_as' ], static fn( $k ) => 'blocked' === $out[ $k ] );
			if ( $retry ) {
				sleep( 2 );
				foreach ( $retry as $k ) {
					[ $url, $key, $value ] = $targets[ $k ];
					$responses[ $k ]       = self::fetch( $url, $marker );
					$out[ $k ]             = self::classify( $responses[ $k ], $key, $value );
				}
			}
			self::drop_marker( $marker );

			// Many hosts resolve their own name internally, so the check may never cross the
			// CDN that blocks apps. Cloudflare in front of the site but absent from a root
			// answer: that "works" proves nothing.
			$headers = [];
			foreach ( $responses as $r ) {
				if ( ! is_wp_error( $r ) ) {
					$headers = array_merge( $headers, array_change_key_case( wp_remote_retrieve_headers( $r )->getAll(), CASE_LOWER ) );
				}
			}
			$cdn_now = Cowboy_MCP_Doctor::cdn_expected( $headers );
			$state   = self::state();
			$cdn     = $cdn_now ?? ( (int) $state['cdn_seen_at'] > time() - self::CDN_MEMORY ? 'cloudflare' : null );
			if ( null !== $cdn ) {
				foreach ( [ 'root_pr', 'root_as' ] as $k ) {
					if ( 'works' === $out[ $k ] && '' === (string) wp_remote_retrieve_header( $responses[ $k ], 'cf-ray' ) ) {
						$out[ $k ] = 'unknown';
					}
				}
			}

			$switched = false;
			if ( $store ) {
				if ( null !== $cdn_now ) {
					self::save( [ 'cdn_seen_at' => time() ] );
				}
				$switched = self::apply( self::worse( $out['root_pr'], $out['root_as'] ), $out['site'], 'self', [ 'root_pr' => $out['root_pr'], 'root_as' => $out['root_as'] ] );
			}
			return $out + [ 'mode' => self::advertised(), 'switched' => $switched ];
		} finally {
			if ( null !== $lock ) {
				self::release_lock( $lock );
			}
		}
	}

	/**
	 * Fetch only the site server's openid-configuration (marked like the self-check) and
	 * record site_ok_at when it works. For callers that need a fresh site-server result
	 * without re-testing the root documents.
	 */
	public static function check_site(): string {
		$site = self::site_issuer();
		if ( ! self::check_allowed() ) {
			return 'unknown';
		}
		if ( null === $site ) {
			return 'blocked';
		}
		$marker = self::mint_marker();
		$result = self::classify( self::fetch( $site . '/.well-known/openid-configuration', $marker ), 'issuer', $site );
		self::drop_marker( $marker );
		if ( 'works' === $result ) {
			self::save( [ 'site_ok_at' => time() ] );
		}
		return $result;
	}

	/**
	 * Apply one observation. $root = the worse of the two root documents; $site = the site
	 * server; $source = self|browser|abandoned. Only an automatic decision (step 4: pretty
	 * permalinks, WordPress at the domain root, no override or filter) is ever switched;
	 * the counters and `unreachable` are kept up to date either way. 'unknown' never
	 * changes the mode or the streak. Returns true when the advertised server changed.
	 */
	public static function apply( string $root, string $site, string $source, array $detail = [] ): bool {
		$now     = time();
		$state   = self::state();
		$changes = [
			'checked_at' => $now,
			'last'       => array_merge( [ 'root' => $root, 'site' => $site, 'source' => $source, 'at' => $now ], $detail ),
		];
		if ( 'works' === $site ) {
			$changes['site_ok_at'] = $now;
		}
		if ( 'blocked' === $root && 'blocked' === $site ) {
			$changes['unreachable'] = true;
		} elseif ( 'works' === $root || 'works' === $site ) {
			$changes['unreachable'] = false;
		}

		$mode = 'site' === $state['mode'] ? 'site' : 'root';
		$to   = null;
		if ( 'root' === $mode ) {
			// Switch at once: the owner is usually mid-connection when the first check runs.
			if ( 'blocked' === $root && 'works' === $site && (int) $state['root_seen_at'] < $now - self::REAL_TRAFFIC_DAYS * DAY_IN_SECONDS ) {
				$to = 'site';
			}
		} elseif ( 'works' === $root ) {
			$runs  = (int) $state['root_ok_runs'];
			$since = $runs > 0 ? (int) $state['root_ok_since'] : $now;
			$runs++;
			$changes['root_ok_runs']  = $runs;
			$changes['root_ok_since'] = $since;
			if ( $runs >= self::SWITCH_BACK_RUNS && $now - $since >= self::SWITCH_BACK_SPAN ) {
				$to = 'root';
			}
		} elseif ( 'blocked' === $root ) {
			$changes['root_ok_runs']  = 0;
			$changes['root_ok_since'] = 0;
		}

		$switched = null !== $to && self::is_automatic();
		if ( $switched ) {
			$reason = 'site' === $to ? 'blocked' : 'unblocked';
			$changes += [ 'mode' => $to, 'reason' => $reason, 'switched_at' => $now ];
			$changes['root_ok_runs']  = 0;
			$changes['root_ok_since'] = 0;
		}
		self::save( $changes );
		if ( $switched ) {
			Cowboy_MCP_Auth::log( 'oauth_address_switched', [ 'args' => [ 'from' => $mode, 'to' => $to, 'reason' => $changes['reason'], 'source' => $source ] ] );
		}
		return $switched;
	}

	/**
	 * Real-traffic signal: a root discovery document was just served. Ignored for the
	 * self-check's own (marked) fetches. The transient is always refreshed; root_seen_at
	 * at most once an hour.
	 */
	public static function note_root_fetch(): void {
		if ( self::is_self_check_request() ) {
			return;
		}
		$now = time();
		set_transient( self::ROOT_FETCH_TRANSIENT, $now, HOUR_IN_SECONDS );
		$state = self::state();
		if ( (int) $state['root_seen_at'] <= $now - HOUR_IN_SECONDS ) {
			$changes = [ 'root_seen_at' => $now ];
			if ( Cowboy_MCP_Doctor::request_via_cloudflare() ) {
				$changes['cdn_seen_at'] = $now; // advisory: remembered so later checks know a CDN fronts the site
			}
			self::save( $changes );
		}
	}

	/** Daily (audit-log cron): re-check when the last check is over an hour old. */
	public static function maybe_check(): void {
		if ( (int) self::state()['checked_at'] <= time() - self::STALE_SECONDS ) {
			self::run_self_check();
		}
	}

	/** The single post-update check (UPGRADE_HOOK). */
	public static function run_upgrade_check(): void {
		self::run_self_check();
	}

	/** On init: once per plugin version, while browser sign-in is on, schedule one background check. */
	public static function maybe_schedule_upgrade_check(): void {
		if ( ! Cowboy_MCP_OAuth::is_enabled() || get_option( self::VERSION_OPTION ) === COWBOY_MCP_VERSION ) {
			return;
		}
		update_option( self::VERSION_OPTION, COWBOY_MCP_VERSION, true ); // read on every init while sign-in is on
		if ( ! wp_next_scheduled( self::UPGRADE_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::UPGRADE_HOOK );
		}
	}

	/** True when the request carries a live self-check marker value. */
	public static function is_self_check_request(): bool {
		$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', self::SELF_CHECK_HEADER ) );
		$value = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $value ) && false !== get_transient( self::MARKER_PREFIX . $value );
	}

	/* ── Internals ─────────────────────────────────────────── */

	/** The check needs the documents (sign-in on) and a host apps can reach (not local; advisory). */
	private static function check_allowed(): bool {
		if ( ! Cowboy_MCP_OAuth::is_enabled() ) {
			return false;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return ! Cowboy_MCP_Security::host_looks_local( $host ) || (bool) apply_filters( 'cowboy_mcp_oauth_address_check_local', false );
	}

	/** step 4 of the decision: nothing above the stored result decides. */
	private static function is_automatic(): bool {
		return in_array( self::reason(), [ 'blocked', 'unblocked', 'default' ], true );
	}

	private static function worse( string $a, string $b ): string {
		foreach ( [ 'blocked', 'unknown' ] as $bad ) {
			if ( $bad === $a || $bad === $b ) {
				return $bad;
			}
		}
		return 'works';
	}

	/**
	 * GET a public URL of this site like an app: app-like User-Agent, the marker header,
	 * same-host redirects followed by hand. The URL is always built here from
	 * home_url()/rest_url() (never caller input), so it is not SSRF-validated: a host's
	 * public name may resolve to a private address from inside its own network.
	 */
	private static function fetch( string $url, string $marker ): array|WP_Error {
		$args = [
			'timeout'     => 10,
			'redirection' => 0,
			'user-agent'  => self::USER_AGENT,
			'headers'     => [ 'Accept' => 'application/json', self::SELF_CHECK_HEADER => $marker ],
		];
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		for ( $hop = 0; ; $hop++ ) {
			$r = wp_remote_get( $url, $args );
			if ( is_wp_error( $r ) || $hop >= 3 || ! in_array( (int) wp_remote_retrieve_response_code( $r ), [ 301, 302, 303, 307, 308 ], true ) ) {
				return $r;
			}
			$location = (string) wp_remote_retrieve_header( $r, 'location' );
			$next     = '' === $location ? '' : WP_Http::make_absolute_url( $location, $url );
			if ( '' === $next || strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) ) !== $host ) {
				return $r; // a redirect off the site is an answer an app could not use: blocked
			}
			$url = $next;
		}
	}

	private static function mint_marker(): string {
		$value = bin2hex( random_bytes( 16 ) );
		set_transient( self::MARKER_PREFIX . $value, 1, 2 * MINUTE_IN_SECONDS );
		return $value;
	}

	private static function drop_marker( string $value ): void {
		delete_transient( self::MARKER_PREFIX . $value );
	}

	/**
	 * Take the lock with an atomic insert-if-absent (INSERT IGNORE on the options table's
	 * unique option_name), so two processes can never both hold it, with or without a
	 * persistent object cache. An expired holder is replaced by a conditional delete.
	 * Returns the holder token, or null when another run holds it.
	 */
	private static function acquire_lock(): ?string {
		global $wpdb;
		$token = ( time() + self::LOCK_SECONDS ) . '|' . bin2hex( random_bytes( 8 ) );
		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- atomic lock row; never cached.
			if ( 1 === (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::LOCK, $token, 'no' ) ) ) {
				return $token;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- atomic lock row; never cached.
			$held = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK ) );
			if ( '' !== $held && (int) strtok( $held, '|' ) > time() ) {
				return null;
			}
			// Expired (or unreadable): remove exactly that value, then try once more.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- atomic lock row; never cached.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::LOCK, $held ) );
		}
		return null;
	}

	private static function release_lock( string $token ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- atomic lock row; never cached.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::LOCK, $token ) );
	}
}
