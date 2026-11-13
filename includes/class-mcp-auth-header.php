<?php
/**
 * Does the Authorization header reach WordPress?
 *
 * Some servers (Apache running PHP as CGI/FastCGI, some proxies) drop it before PHP sees it.
 * Apps then sign in fine, but every call arrives without the token and is refused. The probe
 * calls this site's own endpoint with a token that is meant to fail: the answer "invalid
 * token" means the header arrived, "missing header" means something removed it. No real
 * credential is made or sent.
 *
 * Runs when it matters, never on its own schedule: the Connections tab (after the page has
 * loaded, when the stored answer is old), the daily audit-log cron (same staleness rule), and
 * the Doctor (whose signed-in self-test records what it found). An update or turning browser
 * sign-in on makes the stored answer stale.
 *
 * @package Cowboy_MCP
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Auth_Header {

	/** {result: passes|stripped|unknown, at: int, ver: string}. Not autoloaded: read on the Connections tab only. */
	const OPTION = 'cowboy_mcp_auth_header';
	const NONCE  = 'cowboy_mcp_auth_header_check';
	/** A stored answer older than this is checked again. */
	const STALE_SECONDS = 3 * DAY_IN_SECONDS;
	const TIMEOUT       = 8;

	public static function state(): array {
		$s = get_option( self::OPTION, [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'result' => in_array( $s['result'] ?? '', [ 'passes', 'stripped', 'unknown' ], true ) ? $s['result'] : '',
			'at'     => (int) ( $s['at'] ?? 0 ),
			'ver'    => (string) ( $s['ver'] ?? '' ),
		];
	}

	public static function stripped(): bool {
		return 'stripped' === self::state()['result'];
	}

	/** No answer yet, an old one, or one from another plugin version. */
	public static function is_stale(): bool {
		$s = self::state();
		return '' === $s['result'] || $s['at'] <= time() - self::STALE_SECONDS || COWBOY_MCP_VERSION !== $s['ver'];
	}

	public static function record( string $result ): void {
		update_option( self::OPTION, [ 'result' => $result, 'at' => time(), 'ver' => COWBOY_MCP_VERSION ], false );
	}

	/** Make the next Connections tab load check again (browser sign-in was just turned on). */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/** Probe and store. Returns the result. */
	public static function refresh(): string {
		$result = self::probe();
		self::record( $result );
		return $result;
	}

	/** Daily cron (Cowboy_MCP_Audit_Log::CRON_HOOK). */
	public static function maybe_refresh(): void {
		if ( self::is_stale() ) {
			self::refresh();
		}
	}

	/**
	 * One POST to this site's endpoint with a well-formed but unknown sign-in token.
	 * 'passes' = refused as an invalid token (the header arrived), 'stripped' = refused as a
	 * missing header, 'unknown' = anything else (server off, loopback blocked, a firewall page).
	 * The request carries a self-check marker, so the endpoint neither logs it nor counts it
	 * against this server's IP. One same-host redirect is followed by hand, keeping the header.
	 */
	public static function probe(): string {
		$marker = Cowboy_MCP_OAuth_Address::mint_marker();
		try {
			$url  = rest_url( 'cowboy-mcp/v1/endpoint' );
			$args = [
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => [
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json, text/event-stream',
					'Authorization' => 'Bearer cmcp_at_headercheck.' . bin2hex( random_bytes( 16 ) ),
					Cowboy_MCP_OAuth_Address::SELF_CHECK_HEADER => $marker,
				],
				'body'        => (string) wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ] ),
			];
			$r = wp_remote_post( $url, $args );
			$location = is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_header( $r, 'location' );
			if ( '' !== $location && in_array( (int) wp_remote_retrieve_response_code( $r ), [ 301, 302, 307, 308 ], true ) ) {
				$next = WP_Http::make_absolute_url( $location, $url );
				if ( strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
					$r = wp_remote_post( $next, $args );
				}
			}
			if ( is_wp_error( $r ) ) {
				return 'unknown';
			}
			return self::classify( (int) wp_remote_retrieve_response_code( $r ), json_decode( (string) wp_remote_retrieve_body( $r ), true ) );
		} finally {
			Cowboy_MCP_OAuth_Address::drop_marker( $marker );
		}
	}

	/** Reads the endpoint's own 401 messages (Cowboy_MCP_Auth::evaluate_request()). */
	public static function classify( int $code, $json ): string {
		if ( 401 !== $code || ! is_array( $json ) || 'mcp_unauthorized' !== ( $json['code'] ?? '' ) ) {
			return 'unknown';
		}
		$message = (string) ( $json['message'] ?? '' );
		if ( str_starts_with( $message, 'Missing or invalid Authorization header' ) ) {
			return 'stripped';
		}
		if ( str_starts_with( $message, 'Invalid or expired access token' ) || str_starts_with( $message, 'OAuth connector is not enabled' ) ) {
			return 'passes';
		}
		return 'unknown';
	}
}
