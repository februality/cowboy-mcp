<?php
/**
 * Cowboy MCP – Audit Log
 *
 * Structured audit logging to a custom DB table with auto-prune via daily cron.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Audit_Log {

	/** @var string Cron hook name for daily cleanup. */
	const CRON_HOOK = 'cowboy_mcp_audit_log_cleanup';

	/** @var string[] Secret substrings; a field whose normalized key contains any is redacted. */
	private const REDACTED_KEY_PARTS = [ 'pass', 'pwd', 'secret', 'token', 'apikey', 'auth', 'bearer', 'nonce', 'salt', 'key' ];

	/**
	 * Get the full table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cowboy_mcp_audit_log';
	}

	/**
	 * Register the daily cron hook for log pruning.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'prune' ] );
	}

	/**
	 * Create the audit log table using dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			key_id VARCHAR(12) DEFAULT NULL,
			key_label VARCHAR(255) DEFAULT NULL,
			event VARCHAR(50) NOT NULL,
			tool VARCHAR(100) DEFAULT NULL,
			args TEXT DEFAULT NULL,
			result_status VARCHAR(20) DEFAULT NULL,
			ip VARCHAR(45) DEFAULT NULL,
			session_id VARCHAR(64) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_key_id (key_id),
			KEY idx_event (event),
			KEY idx_tool (tool)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log an event to the audit table.
	 *
	 * @param string $event  Event name (e.g. 'tool_call', 'auth_invalid_key').
	 * @param array  $context Contextual data — supports keys: key_id, key_label, tool, args, result_status, session_id, ip.
	 */
	public static function log( string $event, array $context = [] ): void {
		global $wpdb;

		$args = $context['args'] ?? null;
		if ( is_array( $args ) ) {
			$args = self::redact_sensitive( $args );
			$args = wp_json_encode( $args, JSON_UNESCAPED_SLASHES );
		} elseif ( $args !== null ) {
			$args = (string) $args;
		}

		$data = [
			'event'         => substr( $event, 0, 50 ),
			'key_id'        => substr( $context['key_id'] ?? '', 0, 12 ) ?: null,
			'key_label'     => isset( $context['key_label'] ) ? substr( $context['key_label'], 0, 255 ) : null,
			'tool'          => isset( $context['tool'] ) ? substr( $context['tool'], 0, 100 ) : null,
			'args'          => $args,
			'result_status' => isset( $context['result_status'] ) ? substr( $context['result_status'], 0, 20 ) : null,
			'ip'            => substr( $context['ip'] ?? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 45 ) ?: null,
			'session_id'    => isset( $context['session_id'] ) ? substr( $context['session_id'], 0, 64 ) : null,
		];

		$inserted = $wpdb->insert( self::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Fallback to error_log if DB insert fails.
		if ( false === $inserted ) {
			$entry = array_merge( [ 'event' => $event, 'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ) ], $context );
			error_log( '[COWBOY_MCP] ' . wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Query the audit log with filtering and pagination.
	 *
	 * @param array $filters Supported keys: event, tool, key_id, date_from, date_to, per_page, page.
	 * @return array{entries: array, total: int, page: int, per_page: int}
	 */
	public static function query( array $filters = [] ): array {
		global $wpdb;

		$table    = self::table();
		$conditions = [];
		$per_page   = max( 1, min( 200, (int) ( $filters['per_page'] ?? 50 ) ) );
		$page       = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$offset     = ( $page - 1 ) * $per_page;

		if ( ! empty( $filters['event'] ) ) {
			$conditions[] = $wpdb->prepare( 'event = %s', $filters['event'] );
		}
		if ( ! empty( $filters['tool'] ) ) {
			$conditions[] = $wpdb->prepare( 'tool = %s', $filters['tool'] );
		}
		if ( ! empty( $filters['key_id'] ) ) {
			$conditions[] = $wpdb->prepare( 'key_id = %s', $filters['key_id'] );
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = $wpdb->prepare( 'timestamp >= %s', $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = $wpdb->prepare( 'timestamp <= %s', $filters['date_to'] . ' 23:59:59' );
		}

		$where_sql = ! empty( $conditions ) ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

		// Count total.
		$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table ) . " $where_sql";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $count_sql );

		// Fetch rows.
		$select_sql = $wpdb->prepare( "SELECT * FROM %i", $table ) . " $where_sql " . $wpdb->prepare( "ORDER BY timestamp DESC LIMIT %d OFFSET %d", $per_page, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $select_sql, ARRAY_A );

		// Decode JSON args for each row.
		foreach ( $rows as &$row ) {
			if ( ! empty( $row['args'] ) ) {
				$decoded = json_decode( $row['args'], true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$row['args'] = $decoded;
				}
			}
		}

		return [
			'entries'  => $rows ?: [],
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Delete log entries older than a given number of days.
	 *
	 * @param int $days Number of days to retain. Default 30.
	 * @return int Number of rows deleted.
	 */
	public static function prune( int $days = 30 ): int {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"DELETE FROM %i WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$table,
			$days
		) );
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Remove all entries from the audit log.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function clear(): int {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE %i", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Strip sensitive values from args before logging.
	 */
	private static function redact_sensitive( array $args ): array {
		foreach ( $args as $key => &$value ) {
			if ( is_array( $value ) ) {
				$value = self::redact_sensitive( $value );
			} elseif ( self::key_is_sensitive( (string) $key ) ) {
				$value = '[REDACTED]';
			} elseif ( is_string( $value ) && class_exists( 'Cowboy_MCP_Security' ) ) {
				// Scrub Bearer tokens / raw MCP keys that slip through as free-text values.
				$value = Cowboy_MCP_Security::scrub_secrets( $value );
			}
		}
		return $args;
	}

	/**
	 * Whether a logged arg key looks like it carries a secret (camelCase-safe).
	 */
	private static function key_is_sensitive( string $key ): bool {
		$norm = preg_replace( '/[^a-z]/', '', strtolower( $key ) ); // apiKey -> apikey, access_token -> accesstoken
		foreach ( self::REDACTED_KEY_PARTS as $part ) {
			if ( $norm !== '' && str_contains( $norm, $part ) ) {
				return true;
			}
		}
		return false;
	}
}
