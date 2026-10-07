<?php
/**
 * Cowboy MCP – repair of values damaged by pre-1.7.1 checkpoint restores.
 *
 * Restores before 1.7.1 wrote $wpdb's per-request % placeholder ({64 hex}) into
 * site data. Detection is automatic and read-only (cron, sites that ever restored);
 * the repair runs only when an administrator clicks Repair, takes a checkpoint
 * first, and is journaled as one undoable batch.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Placeholder_Repair {

	const OPTION      = 'cowboy_mcp_placeholder_repair';
	const SCAN_HOOK   = 'cowboy_mcp_placeholder_scan';
	const REPAIR_HOOK = 'cowboy_mcp_placeholder_repair';
	const BATCH       = 500;
	const SQL_RE      = '[{][0-9a-f]{64}[}]';

	public static function init(): void {
		add_action( self::SCAN_HOOK, [ __CLASS__, 'scan' ] );
		add_action( self::REPAIR_HOOK, [ __CLASS__, 'repair_batch' ] );
	}

	public static function state(): array {
		$s = get_option( self::OPTION, [] );
		return is_array( $s ) ? $s : [];
	}

	private static function save( array $s ): void {
		update_option( self::OPTION, $s, false );
	}

	/** Once per version bump: only sites that ever restored a checkpoint get scanned. */
	public static function on_upgrade(): void {
		if ( self::state() ) {
			return;
		}
		if ( ! self::site_has_restored() ) {
			self::save( [ 'status' => 'not_needed', 'at' => time() ] );
			return;
		}
		self::save( [ 'status' => 'scanning', 'at' => time() ] );
		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::SCAN_HOOK );
		}
	}

	public static function site_has_restored(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$cp = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE trigger_type = %s', $wpdb->prefix . 'cowboy_mcp_checkpoints', 'pre_restore' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$jr = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE tool = %s', $wpdb->prefix . 'cowboy_mcp_undo_journal', 'wp_restore_checkpoint' ) );
		return $cp + $jr > 0;
	}

	/** Damaged rows, at most $limit per column: [table, pk_col, pk_val, col, value, label]. */
	private static function find( int $limit ): array {
		global $wpdb;
		$out = [];
		foreach ( Cowboy_MCP_Checkpoint::placeholder_columns() as $table => $spec ) {
			foreach ( $spec['cols'] as $col ) {
				// %% because this string goes through prepare(); never touch the plugin's own options.
				$extra     = $table === $wpdb->options ? " AND option_name NOT LIKE 'cowboy\\\\_mcp\\\\_%%'" : '';
				$label_col = match ( $table ) {
					$wpdb->options => 'option_name',
					$wpdb->posts   => 'post_title',
					default        => 'meta_key',
				};
				// Identifiers come from placeholder_columns() (core tables), never input.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `{$spec['pk']}` AS pk, `{$col}` AS v, `{$label_col}` AS l FROM `{$table}` WHERE `{$col}` REGEXP %s{$extra} ORDER BY `{$spec['pk']}` LIMIT %d", self::SQL_RE, $limit ), ARRAY_A );
				foreach ( (array) $rows as $r ) {
					if ( ! preg_match( Cowboy_MCP_Checkpoint::PLACEHOLDER_RE, (string) $r['v'] ) ) {
						continue; // REGEXP is case-insensitive on some collations; PHP check is exact.
					}
					$out[] = [ 'table' => $table, 'pk_col' => $spec['pk'], 'pk_val' => (string) $r['pk'], 'col' => $col, 'value' => (string) $r['v'], 'label' => self::label( $table, $col, (string) $r['l'] ) ];
				}
			}
		}
		return $out;
	}

	private static function label( string $table, string $col, string $name ): string {
		global $wpdb;
		return match ( $table ) {
			$wpdb->options  => 'Option: ' . $name,
			$wpdb->posts    => 'Post: “' . $name . '” (' . $col . ')',
			$wpdb->postmeta => 'Post meta: ' . $name,
			$wpdb->termmeta => 'Term meta: ' . $name,
			$wpdb->usermeta => 'User meta: ' . $name,
			default         => 'Comment meta: ' . $name,
		};
	}

	/** Read-only scan; nothing in site data is written. */
	public static function scan(): array {
		$rows = self::find( 100000 );
		$s    = array_merge( self::state(), [
			'status' => $rows ? 'found' : 'clean',
			'count'  => count( $rows ),
			'sample' => array_map( static fn( $r ) => [ 'label' => $r['label'] ], array_slice( $rows, 0, 10 ) ),
			'at'     => time(),
		] );
		self::save( $s );
		return $s;
	}

	/** Repair arrives in a later task; the hook is registered so scheduled events never fatal. */
	public static function repair_batch(): void {}
}
