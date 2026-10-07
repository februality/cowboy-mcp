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
	const BATCH_BYTES = 2097152; // 2 MB of values per batch (always at least one row)
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
		if ( $cp + $jr > 0 ) {
			return true;
		}
		// Restores older than the checkpoint/journal retention still leave audit rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$au = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s OR ( event = %s AND tool = %s )', $wpdb->prefix . 'cowboy_mcp_audit_log', 'admin_restore_checkpoint', 'tool_call', 'wp_restore_checkpoint' ) );
		if ( $au > 0 ) {
			return true;
		}
		// …and a damaged permalink/base option is direct evidence on its own.
		foreach ( [ 'permalink_structure', 'category_base', 'tag_base' ] as $opt ) {
			if ( preg_match( Cowboy_MCP_Checkpoint::PLACEHOLDER_RE, (string) get_option( $opt, '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Damaged rows, at most $limit per column and — after the first row — no more than
	 * $max_bytes of values in total: [table, pk_col, pk_val, col, value, label]. Lengths
	 * are read first and values fetched one row at a time, so a batch never holds more
	 * than the byte budget (plus one oversized row) in memory.
	 */
	private static function find( int $limit, int $max_bytes = PHP_INT_MAX ): array {
		global $wpdb;
		$out   = [];
		$bytes = 0;
		foreach ( Cowboy_MCP_Checkpoint::placeholder_columns() as $table => $spec ) {
			foreach ( $spec['cols'] as $col ) {
				// %% because this string goes through prepare(); never touch the plugin's own options.
				$extra     = $table === $wpdb->options ? " AND option_name NOT LIKE 'cowboy\\\\_mcp\\\\_%%'" : '';
				$label_col = self::label_column( $table );
				// Identifiers come from placeholder_columns() (core tables), never input.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `{$spec['pk']}` AS pk, LENGTH(`{$col}`) AS n, `{$label_col}` AS l FROM `{$table}` WHERE `{$col}` REGEXP %s{$extra} ORDER BY `{$spec['pk']}` LIMIT %d", self::SQL_RE, $limit ), ARRAY_A );
				foreach ( (array) $rows as $r ) {
					if ( $out && $bytes + (int) $r['n'] > $max_bytes ) {
						return $out;
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$v = (string) $wpdb->get_var( $wpdb->prepare( "SELECT `{$col}` FROM `{$table}` WHERE `{$spec['pk']}` = %s", $r['pk'] ) );
					if ( ! preg_match( Cowboy_MCP_Checkpoint::PLACEHOLDER_RE, $v ) ) {
						continue; // REGEXP is case-insensitive on some collations; PHP check is exact.
					}
					$bytes += strlen( $v );
					$out[]  = [ 'table' => $table, 'pk_col' => $spec['pk'], 'pk_val' => (string) $r['pk'], 'col' => $col, 'value' => $v, 'label' => self::label( $table, $col, (string) $r['l'] ) ];
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
	/** Counts per column in SQL and samples pk + label only: never loads the values. */
	public static function scan(): array {
		global $wpdb;
		$count  = 0;
		$sample = [];
		foreach ( Cowboy_MCP_Checkpoint::placeholder_columns() as $table => $spec ) {
			foreach ( $spec['cols'] as $col ) {
				$extra     = $table === $wpdb->options ? " AND option_name NOT LIKE 'cowboy\\\\_mcp\\\\_%%'" : '';
				$label_col = self::label_column( $table );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` REGEXP %s{$extra}", self::SQL_RE ) );
				if ( count( $sample ) < 10 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `{$label_col}` AS l FROM `{$table}` WHERE `{$col}` REGEXP %s{$extra} ORDER BY `{$spec['pk']}` LIMIT %d", self::SQL_RE, 10 - count( $sample ) ), ARRAY_A );
					foreach ( (array) $rows as $r ) {
						$sample[] = [ 'label' => self::label( $table, $col, (string) $r['l'] ) ];
					}
				}
			}
		}
		$s = array_merge( self::state(), [
			'status' => $count ? 'found' : 'clean',
			'count'  => $count,
			'sample' => $sample,
			'at'     => time(),
		] );
		self::save( $s );
		return $s;
	}

	private static function label_column( string $table ): string {
		global $wpdb;
		return match ( $table ) {
			$wpdb->options => 'option_name',
			$wpdb->posts   => 'post_title',
			default        => 'meta_key',
		};
	}

	/** Re-arm a cron event that was lost (e.g. WP-Cron cleared) while a scan/repair is pending. */
	public static function ensure_scheduled(): void {
		$status = self::state()['status'] ?? '';
		$hook   = 'scanning' === $status ? self::SCAN_HOOK : ( 'repairing' === $status ? self::REPAIR_HOOK : '' );
		if ( $hook && ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + 60, $hook );
		}
	}

	/** Admin clicked Repair: checkpoint first, then batches (first one inline, rest via cron). */
	public static function start(): array|WP_Error {
		$s = self::state();
		if ( 'repairing' === ( $s['status'] ?? '' ) ) {
			return new WP_Error( 'repair_running', 'A repair is already running.' );
		}
		if ( ! self::find( 1 ) ) {
			self::save( array_merge( $s, [ 'status' => 'clean', 'count' => 0, 'sample' => [] ] ) );
			return new WP_Error( 'nothing_to_repair', 'No damaged values found.' );
		}
		$cp = Cowboy_MCP_Checkpoint::create( 'Before placeholder repair', 'pre_repair' );
		if ( is_wp_error( $cp ) ) {
			return new WP_Error( 'repair_failed', 'Could not take the safety checkpoint; nothing was changed. ' . $cp->get_error_message() );
		}
		$s = array_merge( $s, [ 'status' => 'repairing', 'repaired' => 0, 'batch_id' => wp_generate_uuid4(), 'checkpoint_id' => (int) $cp['checkpoint_id'], 'at' => time() ] );
		unset( $s['dismissed'], $s['done_dismissed'], $s['error'] );
		self::save( $s );
		self::repair_batch();
		return self::state();
	}

	/** One batch: fix up to BATCH rows per column, journal them, reschedule until none are left. */
	public static function repair_batch(): void {
		global $wpdb;
		$s = self::state();
		if ( 'repairing' !== ( $s['status'] ?? '' ) ) {
			return;
		}
		$rows = self::find( self::BATCH, self::BATCH_BYTES );
		if ( ! $rows ) {
			wp_cache_flush();
			$s['status'] = 'done';
			$s['at']     = time();
			self::save( $s );
			Cowboy_MCP_Auth::log( 'placeholder_repair_done', [ 'key_id' => 'admin', 'tool' => 'placeholder_repair', 'args' => [ 'repaired' => (int) $s['repaired'] ] ] );
			return;
		}
		$journal = [];
		foreach ( $rows as $r ) {
			$new = preg_replace( Cowboy_MCP_Checkpoint::PLACEHOLDER_RE, '%', $r['value'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( false !== $wpdb->update( $r['table'], [ $r['col'] => $new ], [ $r['pk_col'] => $r['pk_val'] ] ) ) {
				$journal[] = [ 'table' => $r['table'], 'col' => $r['col'], 'pk_col' => $r['pk_col'], 'pk_val' => $r['pk_val'], 'old' => $r['value'], 'new' => $new ];
				if ( $r['table'] === $wpdb->posts ) {
					clean_post_cache( (int) $r['pk_val'] );
				}
			}
		}
		if ( ! $journal ) { // every update failed: stop rather than loop forever
			$s['status'] = 'found';
			self::save( $s );
			return;
		}
		Cowboy_MCP_Rollback::$batch_id = $s['batch_id'];
		$jid = Cowboy_MCP_Rollback::insert_row( [
			'tool'         => 'placeholder_repair',
			'action'       => 'update',
			'object_type'  => 'db_rows',
			'object_id'    => 'placeholder_repair',
			'object_label' => 'Repaired ' . count( $journal ) . ' values damaged by an earlier checkpoint restore',
			'key_id'       => 'admin',
			'before_state' => [ 'rows' => $journal ],
			'after_hash'   => Cowboy_MCP_Rollback::state_hash( [ 'values' => array_column( $journal, 'new' ) ] ),
		] );
		Cowboy_MCP_Rollback::$batch_id = null;
		$journal_row = $jid ? Cowboy_MCP_Rollback::get_row( $jid ) : null;
		if ( ! $journal_row || Cowboy_MCP_Rollback::STATUS_ACTIVE !== ( $journal_row['status'] ?? '' ) ) {
			// No undo point for this batch: put every value back and stop.
			self::revert( $journal );
			if ( $jid ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->delete( $wpdb->prefix . 'cowboy_mcp_undo_journal', [ 'id' => $jid ], [ '%d' ] );
			}
			$s['status'] = 'found';
			$s['error']  = 'journal_failed';
			$s['at']     = time();
			self::save( $s );
			Cowboy_MCP_Auth::log( 'placeholder_repair_failed', [ 'key_id' => 'admin', 'tool' => 'placeholder_repair', 'args' => [ 'reason' => $jid ? 'undo point not recorded (before-state too large)' : 'undo journal write failed: ' . $wpdb->last_error, 'reverted' => count( $journal ), 'repaired' => (int) $s['repaired'] ] ] );
			return;
		}
		$s['repaired'] = (int) $s['repaired'] + count( $journal );
		self::save( $s );
		wp_schedule_single_event( time() + 5, self::REPAIR_HOOK );
	}

	/** Write the captured old values back (a batch whose undo point could not be recorded). */
	private static function revert( array $journal ): void {
		global $wpdb;
		foreach ( $journal as $j ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->update( $j['table'], [ $j['col'] => $j['old'] ], [ $j['pk_col'] => $j['pk_val'] ] );
			if ( $j['table'] === $wpdb->posts ) {
				clean_post_cache( (int) $j['pk_val'] );
			}
		}
		wp_cache_flush();
	}
}
