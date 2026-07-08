<?php
/**
 * Cowboy MCP – Rollback / Undo journal
 *
 * Captures full before-state snapshots of every introspectable mutation at the
 * dispatch chokepoint, and restores them wholesale on undo. Snapshots, not diffs:
 * restore correctness is independent of current state; after_hash is the advisory
 * conflict rail. See docs spec 2026-07-07-rollback-undo-design.md.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Rollback {

	const STATUS_ACTIVE       = 'active';
	const STATUS_UNDONE       = 'undone';
	const STATUS_NOT_UNDOABLE = 'not_undoable';

	/** Sentinel after_hash meaning "object absent after the change" (deletes). */
	const ABSENT_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

	/** Max gz-compressed before-state blob size (16 MB). Larger → not_undoable row. */
	const MAX_BLOB = 16777216;

	/** @var string|null Batch UUID set by wp_batch_execute around its loop. */
	public static ?string $batch_id = null;

	/** @var int|null Checkpoint id set by Cowboy_MCP_Checkpoint::maybe_auto_checkpoint. */
	public static ?int $last_checkpoint_id = null;

	/** @var array|null Current capture handle (between begin() and commit()/discard()). */
	private static ?array $pending = null;

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cowboy_mcp_undo_journal';
	}

	/** Register the prune callback on the existing daily cron. */
	public static function init(): void {
		add_action( Cowboy_MCP_Audit_Log::CRON_HOOK, [ __CLASS__, 'cron_prune' ] );
	}

	public static function cron_prune(): void {
		$days = (int) ( Cowboy_MCP_Tools::get_settings()['undo_retention_days'] ?? 7 );
		self::prune( max( 1, $days ) );
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			key_id VARCHAR(64) DEFAULT NULL,
			key_label VARCHAR(255) DEFAULT NULL,
			session_id VARCHAR(64) DEFAULT NULL,
			batch_id VARCHAR(36) DEFAULT NULL,
			tool VARCHAR(100) NOT NULL,
			action VARCHAR(20) NOT NULL,
			object_type VARCHAR(32) NOT NULL,
			object_id VARCHAR(191) NOT NULL DEFAULT '',
			object_label VARCHAR(255) DEFAULT NULL,
			before_state LONGBLOB DEFAULT NULL,
			after_hash CHAR(64) DEFAULT NULL,
			status VARCHAR(12) NOT NULL DEFAULT 'active',
			not_undoable_reason VARCHAR(255) DEFAULT NULL,
			undo_of BIGINT UNSIGNED DEFAULT NULL,
			undone_at DATETIME DEFAULT NULL,
			undone_by VARCHAR(64) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_object (object_type, object_id(64)),
			KEY idx_batch (batch_id),
			KEY idx_status (status),
			KEY idx_session (session_id)
		) {$charset};";
		// Direct idempotent DDL, trusted identifiers — same pattern as the audit log table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $sql );
	}

	/* ── State hashing ─────────────────────────────────────── */

	/** Recursively key-sort so hashes are order-independent. */
	private static function canonical( mixed $state ): mixed {
		if ( is_array( $state ) ) {
			ksort( $state );
			foreach ( $state as &$v ) {
				$v = self::canonical( $v );
			}
			unset( $v );
		}
		return $state;
	}

	/** sha256 of canonical JSON; null (absent object) → ABSENT_HASH sentinel. */
	public static function state_hash( ?array $state ): string {
		if ( $state === null ) {
			return self::ABSENT_HASH;
		}
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $state ) ) );
	}

	/* ── Row IO ────────────────────────────────────────────── */

	/**
	 * Insert a journal row. $data keys: tool, action, object_type, object_id,
	 * object_label, before_state (?array — encoded+gzipped here), after_hash,
	 * status, not_undoable_reason, undo_of. Auth/session/batch context added here.
	 * Fail-open: returns null on any failure.
	 */
	public static function insert_row( array $data ): ?int {
		global $wpdb;
		try {
			$blob = null;
			if ( isset( $data['before_state'] ) && $data['before_state'] !== null ) {
				$blob = gzcompress( (string) wp_json_encode( $data['before_state'] ), 6 );
				if ( $blob === false ) {
					return null;
				}
				if ( strlen( $blob ) > self::MAX_BLOB ) {
					$data['status']              = self::STATUS_NOT_UNDOABLE;
					$data['not_undoable_reason'] = 'Before-state exceeded the 16 MB compressed cap.';
					$blob                        = null;
				}
			}
			$ctx = class_exists( 'Cowboy_MCP_Auth' ) ? Cowboy_MCP_Auth::$current_key_context : [];
			$row = [
				'key_id'              => substr( (string) ( $data['key_id'] ?? $ctx['key_id'] ?? '' ), 0, 64 ) ?: null,
				'key_label'           => substr( (string) ( $ctx['key_label'] ?? '' ), 0, 255 ) ?: null,
				'session_id'          => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_MCP_SESSION_ID'] ?? '' ) ), 0, 64 ) ?: null,
				'batch_id'            => self::$batch_id,
				'tool'                => substr( (string) $data['tool'], 0, 100 ),
				'action'              => substr( (string) $data['action'], 0, 20 ),
				'object_type'         => substr( (string) $data['object_type'], 0, 32 ),
				'object_id'           => substr( (string) $data['object_id'], 0, 191 ),
				'object_label'        => isset( $data['object_label'] ) ? substr( (string) $data['object_label'], 0, 255 ) : null,
				'before_state'        => $blob,
				'after_hash'          => $data['after_hash'] ?? null,
				'status'              => $data['status'] ?? self::STATUS_ACTIVE,
				'not_undoable_reason' => isset( $data['not_undoable_reason'] ) ? substr( (string) $data['not_undoable_reason'], 0, 255 ) : null,
				'undo_of'             => $data['undo_of'] ?? null,
			];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert( self::table(), $row );
			return $ok ? (int) $wpdb->insert_id : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Fetch one raw row (before_state decoded to array) or null. */
	public static function get_row( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['before_state'] = self::decode_blob( $row['before_state'] );
		return $row;
	}

	private static function decode_blob( ?string $blob ): ?array {
		if ( $blob === null || $blob === '' ) {
			return null;
		}
		$json = @gzuncompress( $blob );
		if ( $json === false ) {
			return null;
		}
		$state = json_decode( $json, true );
		return is_array( $state ) ? $state : null;
	}

	/* ── Query (scrubbed listing) ──────────────────────────── */

	/**
	 * Filters: object_type, object_id, tool, batch_id, session_id, status,
	 * date_from, date_to (YYYY-MM-DD), per_page (<=200), page.
	 * before_state values are REDACTED and truncated — raw blobs never leave the server.
	 */
	public static function query( array $filters = [] ): array {
		global $wpdb;
		$conditions = [];
		$per_page   = max( 1, min( 200, (int) ( $filters['per_page'] ?? 50 ) ) );
		$page       = max( 1, (int) ( $filters['page'] ?? 1 ) );
		foreach ( [ 'object_type', 'object_id', 'tool', 'batch_id', 'session_id', 'status' ] as $col ) {
			if ( ! empty( $filters[ $col ] ) ) {
				$conditions[] = $wpdb->prepare( "{$col} = %s", $filters[ $col ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = $wpdb->prepare( 'timestamp >= %s', $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = $wpdb->prepare( 'timestamp <= %s', $filters['date_to'] . ' 23:59:59' );
		}
		$where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

		$count_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) . " {$where}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $count_sql );

		$select_sql = $wpdb->prepare( 'SELECT * FROM %i', self::table() ) . " {$where} "
			. $wpdb->prepare( 'ORDER BY id DESC LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $select_sql, ARRAY_A ) ?: [];

		foreach ( $rows as &$row ) {
			$state = self::decode_blob( $row['before_state'] );
			$row['before_state'] = $state === null ? null : self::preview_state( $state );
			unset( $row['after_hash'] ); // internal detail, not useful to callers
		}
		unset( $row );

		return [ 'entries' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
	}

	/** Redact secrets and truncate long values for listing display. */
	private static function preview_state( array $state ): array {
		$state = Cowboy_MCP_Audit_Log::redact_sensitive( $state );
		array_walk_recursive( $state, function ( &$v, $k ) {
			if ( $k === 'content_b64' ) {
				$v = '(file contents, ' . size_format( (int) ( strlen( (string) $v ) * 0.75 ) ) . ')';
			} elseif ( is_string( $v ) && strlen( $v ) > 500 ) {
				$v = substr( $v, 0, 500 ) . '… (truncated)';
			}
		} );
		return $state;
	}

	/* ── Pruning ───────────────────────────────────────────── */

	public static function prune( int $days ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM %i WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)',
			self::table(),
			$days
		) );
		return (int) $wpdb->rows_affected;
	}
}
