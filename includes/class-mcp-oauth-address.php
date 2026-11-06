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

	/**
	 * Stored state merged over defaults.
	 */
	public static function state(): array {
		$defaults = [
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
		];
		$stored = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Merge changes into the freshly read option (so stale copies do not drop each other's fields).
	 */
	public static function save( array $changes ): void {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		update_option( self::OPTION, array_merge( $stored, $changes ), false );
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
}
