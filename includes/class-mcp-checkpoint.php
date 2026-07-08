<?php
/**
 * Cowboy MCP – DB Checkpoints
 *
 * Pure-PHP, prefix-scoped SQL dumps for opaque operations (wp_cli) and
 * session-level insurance. No mysqldump/shell dependency. Restore imports
 * into temp-prefixed tables and swaps atomically via RENAME TABLE (Task 12).
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Checkpoint {

	const TEMP_PREFIX = '_cmcp_restore_';
	const OLD_PREFIX  = '_cmcp_old_';

	/** Max bytes per generated INSERT statement (~256 KB — far under max_allowed_packet). */
	const MAX_STMT = 262144;

	/** Rows fetched per SELECT while dumping. */
	const CHUNK_ROWS = 500;

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cowboy_mcp_checkpoints';
	}

	public static function init(): void {
		add_action( Cowboy_MCP_Audit_Log::CRON_HOOK, [ __CLASS__, 'cron_prune' ] );
	}

	public static function cron_prune(): void {
		self::prune_excess();
		// pre_restore checkpoints are exempt from the count cap but expire by age.
		$days = (int) ( Cowboy_MCP_Tools::get_settings()['undo_retention_days'] ?? 7 );
		foreach ( self::list_all() as $cp ) {
			if ( $cp['trigger_type'] === 'pre_restore'
				&& strtotime( $cp['created'] ) < time() - $days * DAY_IN_SECONDS ) {
				self::delete( (int) $cp['id'] );
			}
		}
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			label VARCHAR(255) DEFAULT NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'manual',
			file VARCHAR(255) NOT NULL,
			size_bytes BIGINT UNSIGNED DEFAULT 0,
			tables_count INT UNSIGNED DEFAULT 0,
			tables LONGTEXT DEFAULT NULL,
			wp_version VARCHAR(20) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_created (created)
		) {$charset};";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $sql );
	}

	/* ── Storage directory (web-denied) ────────────────────── */

	public static function dir(): string|WP_Error {
		$base = wp_upload_dir()['basedir'] ?? '';
		if ( $base === '' ) {
			return new WP_Error( 'checkpoint_failed', 'Uploads directory unavailable.' );
		}
		$dir = $base . '/cowboy-mcp/checkpoints';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'checkpoint_failed', 'Could not create checkpoint directory.' );
		}
		// Deny web access (Apache 2.2 + 2.4 directives) + directory-listing guard.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/* ── Table selection ───────────────────────────────────── */

	/** Live prefix-scoped tables, excluding the plugin's own bookkeeping tables. */
	public static function site_tables(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$own    = [
			$wpdb->prefix . 'cowboy_mcp_audit_log',
			$wpdb->prefix . 'cowboy_mcp_undo_journal',
			$wpdb->prefix . 'cowboy_mcp_checkpoints',
		];
		return array_values( array_filter( $tables, function ( $t ) use ( $own ) {
			if ( in_array( $t, $own, true ) ) {
				return false;
			}
			// Never dump leftover temp/old tables from a previous restore.
			return ! str_starts_with( $t, self::TEMP_PREFIX ) && ! str_starts_with( $t, self::OLD_PREFIX );
		} ) );
	}

	/* ── Create (dump) ─────────────────────────────────────── */

	public static function create( string $label = '', string $trigger = 'manual' ): array|WP_Error {
		global $wpdb;
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$tables = self::site_tables();
		if ( empty( $tables ) ) {
			return new WP_Error( 'checkpoint_failed', 'No site tables found to dump.' );
		}

		$fname = sprintf( 'checkpoint-%s-%s.sql.gz', gmdate( 'Ymd-His' ), strtolower( wp_generate_password( 16, false ) ) );
		$path  = $dir . '/' . $fname;
		$gz    = gzopen( $path, 'wb6' );
		if ( ! $gz ) {
			return new WP_Error( 'checkpoint_failed', 'Could not open checkpoint file for writing.' );
		}

		$counts = [];
		try {
			foreach ( $tables as $t ) {
				$counts[ $t ] = self::dump_table( $gz, $t );
			}
		} catch ( \Throwable $e ) {
			gzclose( $gz );
			wp_delete_file( $path );
			return new WP_Error( 'checkpoint_failed', 'Dump failed: ' . $e->getMessage() );
		}
		gzclose( $gz );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( self::table(), [
			'label'        => substr( $label !== '' ? $label : 'Checkpoint', 0, 255 ),
			'trigger_type' => substr( $trigger, 0, 20 ),
			'file'         => $fname,
			'size_bytes'   => filesize( $path ) ?: 0,
			'tables_count' => count( $tables ),
			'tables'       => wp_json_encode( $counts ),
			'wp_version'   => get_bloginfo( 'version' ),
		] );
		$id = (int) $wpdb->insert_id;

		if ( $trigger !== 'pre_restore' ) {
			self::prune_excess();
		}

		return [
			'checkpoint_id' => $id,
			'label'         => $label !== '' ? $label : 'Checkpoint',
			'trigger'       => $trigger,
			'size_bytes'    => filesize( $path ) ?: 0,
			'tables_count'  => count( $tables ),
		];
	}

	/**
	 * Dump one table: DROP + single-line CREATE + batched multi-row INSERTs.
	 * esc_sql() escapes \n and \r, so every generated statement is one line —
	 * the restore reader is line-based. Returns the row count.
	 */
	private static function dump_table( $gz, string $table ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		if ( ! $create || empty( $create[1] ) ) {
			throw new \RuntimeException( "SHOW CREATE TABLE failed for {$table}" );
		}
		gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );
		gzwrite( $gz, preg_replace( '/\s*\n\s*/', ' ', $create[1] ) . ";\n" );

		$offset = 0;
		$total  = 0;
		$cols   = null;
		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$table}` LIMIT %d OFFSET %d", self::CHUNK_ROWS, $offset
			), ARRAY_A );
			if ( empty( $rows ) ) {
				break;
			}
			if ( $cols === null ) {
				$cols = '`' . implode( '`,`', array_keys( $rows[0] ) ) . '`';
			}
			$head   = "INSERT INTO `{$table}` ({$cols}) VALUES ";
			$stmt   = '';
			foreach ( $rows as $row ) {
				$vals = [];
				foreach ( $row as $v ) {
					$vals[] = $v === null ? 'NULL' : "'" . esc_sql( (string) $v ) . "'";
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
				if ( $stmt !== '' && strlen( $head . $stmt . ',' . $tuple ) > self::MAX_STMT ) {
					gzwrite( $gz, $head . $stmt . ";\n" );
					$stmt = '';
				}
				$stmt .= ( $stmt === '' ? '' : ',' ) . $tuple;
			}
			if ( $stmt !== '' ) {
				gzwrite( $gz, $head . $stmt . ";\n" );
			}
			$total  += count( $rows );
			$offset += self::CHUNK_ROWS;
		}
		return $total;
	}

	/* ── Restore ───────────────────────────────────────────── */

	/** Options whose LIVE values survive a restore (bootstrap invariant). */
	private const PRESERVED_OPTIONS = [
		'cowboy_mcp_api_keys',
		'cowboy_mcp_settings',
		'cowboy_mcp_oauth_tokens',
		'cowboy_mcp_oauth_refresh',
		'cowboy_mcp_oauth_clients',
	];

	public static function restore( int $id, string $actor = 'mcp' ): array|WP_Error {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', "Checkpoint #{$id} not found." );
		}
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$path = $dir . '/' . $row['file'];
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'checkpoint_failed', 'Checkpoint file is missing on disk.' );
		}
		$tables = array_keys( (array) json_decode( (string) $row['tables'], true ) );
		if ( empty( $tables ) ) {
			return new WP_Error( 'checkpoint_failed', 'Checkpoint has no recorded table list.' );
		}

		// 1. Safety net: checkpoint the CURRENT state first, so the restore is reversible.
		$pre = self::create( "Pre-restore safety checkpoint (before restoring #{$id})", 'pre_restore' );
		if ( is_wp_error( $pre ) ) {
			return new WP_Error( 'checkpoint_failed', 'Could not take the pre-restore checkpoint; aborting. ' . $pre->get_error_message() );
		}

		// 2. Snapshot live credential/settings options (re-applied after the swap).
		$preserved = [];
		foreach ( self::PRESERVED_OPTIONS as $opt ) {
			$preserved[ $opt ] = get_option( $opt, '__cmcp_absent__' );
		}

		// 3. Import into temp-prefixed tables.
		self::drop_prefixed( self::TEMP_PREFIX );
		$imported = self::import_as_temp( $path, $tables );
		if ( is_wp_error( $imported ) ) {
			self::drop_prefixed( self::TEMP_PREFIX );
			return $imported;
		}

		// 4. Verify row counts against the recorded manifest.
		$expected = (array) json_decode( (string) $row['tables'], true );
		foreach ( $expected as $t => $count ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$actual = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . self::TEMP_PREFIX . $t . '`' );
			if ( $actual !== (int) $count ) {
				self::drop_prefixed( self::TEMP_PREFIX );
				return new WP_Error( 'checkpoint_failed', "Row-count mismatch on {$t} ({$actual} vs {$count}); originals untouched." );
			}
		}

		// 5. Atomic multi-table swap.
		$live  = self::site_tables();
		$pairs = [];
		foreach ( $tables as $t ) {
			if ( in_array( $t, $live, true ) ) {
				$pairs[] = '`' . $t . '` TO `' . self::OLD_PREFIX . $t . '`';
			}
			$pairs[] = '`' . self::TEMP_PREFIX . $t . '` TO `' . $t . '`';
		}
		self::drop_prefixed( self::OLD_PREFIX );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		$swapped = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) );
		if ( $swapped === false ) {
			self::drop_prefixed( self::TEMP_PREFIX );
			return new WP_Error( 'checkpoint_failed', 'RENAME TABLE swap failed; originals untouched. ' . $wpdb->last_error );
		}

		// 6. Cleanup + cache flush + bootstrap invariant.
		self::drop_prefixed( self::OLD_PREFIX );
		wp_cache_flush();
		foreach ( $preserved as $opt => $val ) {
			if ( '__cmcp_absent__' === $val ) {
				delete_option( $opt );
			} else {
				update_option( $opt, $val );
			}
		}

		// Ledger entry: the restore itself (reversible via the pre-restore checkpoint).
		if ( class_exists( 'Cowboy_MCP_Rollback' ) ) {
			Cowboy_MCP_Rollback::insert_row( [
				'tool'                => 'wp_restore_checkpoint',
				'action'              => 'update',
				'object_type'         => 'checkpoint',
				'object_id'           => (string) $id,
				'object_label'        => 'Database restore from checkpoint #' . $id,
				'key_id'              => $actor,
				'status'              => Cowboy_MCP_Rollback::STATUS_NOT_UNDOABLE,
				'not_undoable_reason' => 'Whole-DB restore. To reverse it, restore pre-restore checkpoint #' . $pre['checkpoint_id'] . '.',
			] );
		}

		return [
			'restored'                  => true,
			'checkpoint_id'             => $id,
			'tables_count'              => count( $tables ),
			'pre_restore_checkpoint_id' => (int) $pre['checkpoint_id'],
		];
	}

	/** Stream the dump, rewriting table names to the temp prefix, executing line by line. */
	private static function import_as_temp( string $path, array $tables ): true|WP_Error {
		global $wpdb;
		$gz = gzopen( $path, 'rb' );
		if ( ! $gz ) {
			return new WP_Error( 'checkpoint_failed', 'Could not open checkpoint file.' );
		}
		// Longest names first so `wp_posts` never partially rewrites `wp_postsX`.
		usort( $tables, fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		$search  = array_map( fn( $t ) => "`{$t}`", $tables );
		$replace = array_map( fn( $t ) => '`' . self::TEMP_PREFIX . $t . '`', $tables );
		$temp_map = [];
		foreach ( $tables as $t ) {
			$temp_map[ $t ] = self::TEMP_PREFIX . $t;
		}

		while ( ! gzeof( $gz ) ) {
			// Statements are single-line; MAX_STMT bounds multi-row INSERTs but a single
			// oversized value can exceed it. If a line ever exceeds this buffer, the split
			// fragment fails as invalid SQL below — a caught error, not silent corruption.
			$line = gzgets( $gz, 64 * 1024 * 1024 );
			if ( $line === false ) {
				break;
			}
			$stmt = trim( $line );
			if ( $stmt === '' ) {
				continue;
			}
			if ( str_starts_with( $stmt, 'INSERT INTO ' ) || str_starts_with( $stmt, 'DROP TABLE IF EXISTS ' ) ) {
				// Data-bearing statements: rewrite only the leading identifier — a
				// backticked table name inside a row VALUE must never be touched.
				// Fail closed on an unmapped identifier: a tampered dump could smuggle
				// an unrecognized table reference that would otherwise execute as-is.
				$unknown = false;
				$stmt = preg_replace_callback(
					'/^(INSERT INTO|DROP TABLE IF EXISTS) `([^`]+)`/',
					function ( $m ) use ( $temp_map, &$unknown ) {
						if ( ! isset( $temp_map[ $m[2] ] ) ) {
							$unknown = true;
							return $m[0];
						}
						return $m[1] . ' `' . $temp_map[ $m[2] ] . '`';
					},
					$stmt
				);
				if ( $unknown ) {
					gzclose( $gz );
					return new WP_Error( 'checkpoint_failed', 'Checkpoint contains an unrecognized table reference; aborting restore (originals untouched).' );
				}
			} else {
				// Schema statements (CREATE TABLE): whole-statement rewrite so any
				// self-references (e.g. FK REFERENCES) follow the temp prefix too.
				$stmt = str_replace( $search, $replace, $stmt );
			}
			// Generated by our own dump_table(); table names rewritten to temp prefix.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
			if ( $wpdb->query( rtrim( $stmt, ';' ) ) === false ) {
				gzclose( $gz );
				return new WP_Error( 'checkpoint_failed', 'Import failed: ' . $wpdb->last_error );
			}
		}
		gzclose( $gz );
		return true;
	}

	/** Drop all tables carrying one of our work prefixes. */
	private static function drop_prefixed( string $prefix ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		foreach ( $tables as $t ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		}
	}

	/* ── Listing / deletion / pruning ──────────────────────── */

	public static function list_all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', self::table() ), ARRAY_A ) ?: [];
		foreach ( $rows as &$r ) {
			unset( $r['file'] ); // never expose storage paths
			$r['tables'] = json_decode( (string) $r['tables'], true );
		}
		unset( $r );
		return $rows;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function delete( int $id ): true|WP_Error {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', "Checkpoint #{$id} not found." );
		}
		$dir = self::dir();
		if ( ! is_wp_error( $dir ) && ! empty( $row['file'] ) && file_exists( $dir . '/' . $row['file'] ) ) {
			wp_delete_file( $dir . '/' . $row['file'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		return true;
	}

	/** Enforce checkpoint_max (oldest first); pre_restore checkpoints are exempt. */
	public static function prune_excess(): int {
		$max     = max( 1, (int) ( Cowboy_MCP_Tools::get_settings()['checkpoint_max'] ?? 5 ) );
		$rows    = array_filter( self::list_all(), fn( $r ) => $r['trigger_type'] !== 'pre_restore' );
		$excess  = array_slice( array_values( $rows ), $max ); // list_all is newest-first
		$deleted = 0;
		foreach ( $excess as $r ) {
			if ( ! is_wp_error( self::delete( (int) $r['id'] ) ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}

	/* ── wp_cli auto-checkpoint heuristic (used by Task 13) ── */

	/**
	 * Take a checkpoint before a mutating-looking wp_cli command. Fail-open:
	 * a checkpoint failure is audit-logged but never blocks the command.
	 */
	public static function maybe_auto_checkpoint( string $command ): ?int {
		if ( empty( Cowboy_MCP_Tools::get_settings()['auto_checkpoint_wp_cli'] ?? true ) ) {
			return null;
		}
		if ( self::command_is_read_only( $command ) ) {
			return null;
		}
		$result = self::create( 'Before wp_cli: ' . substr( $command, 0, 180 ), 'auto_wp_cli' );
		if ( is_wp_error( $result ) ) {
			Cowboy_MCP_Auth::log( 'checkpoint_failed', [ 'tool' => 'wp_cli', 'error' => $result->get_error_message() ] );
			return null;
		}
		if ( class_exists( 'Cowboy_MCP_Rollback' ) ) {
			Cowboy_MCP_Rollback::$last_checkpoint_id = (int) $result['checkpoint_id'];
		}
		return (int) $result['checkpoint_id'];
	}

	/**
	 * Read-verb allowlist. Unknown verbs → NOT read-only (checkpoint; fail safe).
	 * The subcommand is the first non-flag token after the command name.
	 */
	public static function command_is_read_only( string $command ): bool {
		$read   = [ 'list', 'get', 'search', 'info', 'count', 'path', 'help', 'version', 'status', 'check', 'size', 'exists', 'is-active', 'is-installed', 'verify-checksums' ];
		$tokens = preg_split( '/\s+/', strtolower( trim( $command ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( empty( $tokens ) ) {
			return true;
		}
		if ( in_array( $tokens[0], [ 'help', 'cli' ], true ) ) {
			return true;
		}
		$count = count( $tokens );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( str_starts_with( $tokens[ $i ], '--' ) ) {
				continue;
			}
			return in_array( $tokens[ $i ], $read, true );
		}
		return false; // single-token command → assume mutating
	}
}
