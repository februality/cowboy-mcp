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

	/**
	 * Capture registry: tool name → strategy config.
	 * type: snapshot/restore family. action: create|update|delete|toggle.
	 * id_arg: argument holding the object id. result_id: result key holding a
	 * created object's id (create actions). Extended by Tasks 4-9.
	 */
	private const STRATEGIES = [
		'wp_update_option'      => [ 'type' => 'option', 'action' => 'update', 'id_arg' => 'option_name' ],
		'wp_woo_update_setting' => [ 'type' => 'option', 'action' => 'update', 'id_arg' => 'key' ],

		'wp_create_post'        => [ 'type' => 'post', 'action' => 'create', 'result_id' => 'ID' ],
		'wp_update_post'        => [ 'type' => 'post', 'action' => 'update', 'id_arg' => 'post_id' ],
		'wp_delete_post'        => [ 'type' => 'post', 'action' => 'delete', 'id_arg' => 'post_id' ],
		'wp_elementor_update_template'      => [ 'type' => 'post', 'action' => 'update', 'id_arg' => 'template_id' ],
		'wp_elementor_update_global_styles' => [ 'type' => 'post', 'action' => 'update' ], // kit id resolved below
		'wp_acf_update_field'   => [ 'type' => 'acf_value', 'action' => 'update' ],
		'wp_acf_delete_field'   => [ 'type' => 'acf_value', 'action' => 'delete' ],
		'wp_acf_add_row'        => [ 'type' => 'acf_value', 'action' => 'update' ],
		'wp_acf_update_row'     => [ 'type' => 'acf_value', 'action' => 'update' ],
		'wp_acf_delete_row'     => [ 'type' => 'acf_value', 'action' => 'update' ],
	];

	/**
	 * Mutating tools with no strategy — journaled as not_undoable so the ledger
	 * stays complete. Extended by Task 13 (wp_cli reason references checkpoints).
	 */
	private const NOT_UNDOABLE = [
		'wp_cli'                   => 'Arbitrary WP-CLI commands cannot be inverted. Covered by DB checkpoints (see checkpoint_id in the tool response when auto-checkpointing is on).',
		'wp_batch_execute'         => 'Batch wrapper — the individual calls are journaled with a shared batch_id.',
		'wp_cache_flush'           => 'Cache contents are transient; nothing to restore.',
		'wp_cache_preload'         => 'Cache warming has no persistent before-state.',
		'wp_send_test_email'       => 'A sent email cannot be recalled.',
		'wp_test_email'            => 'A sent email cannot be recalled.',
		'wp_regenerate_thumbnails' => 'Thumbnail files are derived data; regenerate again to change them.',
		'wp_db_repair_table'       => 'REPAIR TABLE is a storage-level operation with no logical inverse.',
		'wp_get_transients'        => 'Transient deletion/cleanup removes ephemeral cache data only.',
		'wp_flush_rewrite_rules'   => 'Rewrite rules are derived data; flushing regenerates them.',
		'wp_wordfence_start_scan'  => 'A started scan cannot be un-started.',
		'wp_woo_create_tax_rate'   => 'Tax-rate rows are not journaled in v1.',
	];

	/* ── Capture plumbing (called from Cowboy_MCP_Tools::call_tool) ── */

	public static function begin( string $tool, array $args, array $annotations ): ?array {
		try {
			if ( ! empty( $annotations['readOnlyHint'] ) ) {
				return null;
			}
			if ( empty( Cowboy_MCP_Tools::get_settings()['undo_enabled'] ?? true ) ) {
				return null;
			}
			// Undo/checkpoint tools manage their own journaling (Task 3 / Task 14).
			if ( in_array( $tool, [ 'wp_undo_change', 'wp_create_checkpoint', 'wp_list_checkpoints', 'wp_restore_checkpoint', 'wp_delete_checkpoint', 'wp_list_changes', 'cowboy_mcp_get_audit_log' ], true ) ) {
				return null;
			}

			if ( isset( self::NOT_UNDOABLE[ $tool ] ) ) {
				$handle = [
					'tool'   => $tool,
					'type'   => 'none',
					'action' => 'update',
					'object_id' => '',
					'object_label' => null,
					'before' => null,
					'rows'   => [],
					'reason' => self::NOT_UNDOABLE[ $tool ],
				];
				self::$pending = $handle;
				return $handle;
			}

			$strategy = self::STRATEGIES[ $tool ] ?? null;
			if ( $strategy === null ) {
				// Unknown mutating tool (filter-added / future): visible gap, not silent.
				$handle = [
					'tool' => $tool, 'type' => 'none', 'action' => 'update',
					'object_id' => '', 'object_label' => null, 'before' => null, 'rows' => [],
					'reason' => 'No capture strategy registered for this tool.',
				];
				self::$pending = $handle;
				return $handle;
			}

			$object_id = self::extract_id( $tool, $strategy, $args );
			$before    = null;
			if ( $strategy['action'] !== 'create' ) {
				if ( $object_id === null ) {
					$handle = [
						'tool' => $tool, 'type' => $strategy['type'], 'action' => $strategy['action'],
						'object_id' => '', 'object_label' => null, 'before' => null, 'rows' => [],
						'reason' => 'Could not identify the target object from the arguments.',
					];
					self::$pending = $handle;
					return $handle;
				}
				$before = self::snapshot( $strategy['type'], $object_id );
			}

			$handle = [
				'tool'         => $tool,
				'type'         => $strategy['type'],
				'action'       => $strategy['action'],
				'object_id'    => $object_id ?? '',
				'object_label' => self::object_label( $strategy['type'], $object_id, $before, $args ),
				'before'       => $before,
				'rows'         => [],
				'reason'       => null,
				'result_id'    => $strategy['result_id'] ?? null,
			];
			self::$pending = $handle;
			return $handle;
		} catch ( \Throwable $e ) {
			$handle = [
				'tool' => $tool, 'type' => 'none', 'action' => 'update',
				'object_id' => '', 'object_label' => null, 'before' => null, 'rows' => [],
				'reason' => 'Capture failed: ' . substr( $e->getMessage(), 0, 150 ),
			];
			self::$pending = $handle;
			return $handle;
		}
	}

	/** Handler-assisted capture (wp_search_replace): push row-level old/new values. */
	public static function add_rows( array $rows ): void {
		if ( self::$pending !== null ) {
			self::$pending['rows'] = array_merge( self::$pending['rows'], $rows );
		}
	}

	public static function discard( ?array $capture ): void {
		self::$pending = null;
	}

	public static function commit( ?array $capture, mixed $result ): ?int {
		if ( $capture === null ) {
			return null;
		}
		// add_rows() mutates the stored copy; prefer it over the caller's stale handle.
		$capture       = self::$pending ?? $capture;
		self::$pending = null;
		try {
			if ( $capture['reason'] !== null || $capture['type'] === 'none' ) {
				return self::insert_row( [
					'tool'                => $capture['tool'],
					'action'              => $capture['action'],
					'object_type'         => $capture['type'],
					'object_id'           => $capture['object_id'],
					'object_label'        => $capture['object_label'],
					'before_state'        => null,
					'after_hash'          => null,
					'status'              => self::STATUS_NOT_UNDOABLE,
					'not_undoable_reason' => $capture['reason'] ?? 'No capture strategy.',
				] );
			}

			// db_rows: rows pushed by the handler ARE the state (Task 9).
			if ( $capture['type'] === 'db_rows' ) {
				if ( empty( $capture['rows'] ) ) {
					return null; // nothing changed → nothing to journal
				}
				$news = array_column( $capture['rows'], 'new' );
				return self::insert_row( [
					'tool'         => $capture['tool'],
					'action'       => 'update',
					'object_type'  => 'db_rows',
					'object_id'    => $capture['object_id'],
					'object_label' => $capture['object_label'],
					'before_state' => [ 'rows' => $capture['rows'] ],
					'after_hash'   => self::state_hash( [ 'values' => $news ] ),
				] );
			}

			// Resolve created object's id from the result.
			if ( $capture['action'] === 'create' ) {
				$rid = null;
				if ( is_array( $result ) && ! empty( $capture['result_id'] ) ) {
					$rid = $result[ $capture['result_id'] ] ?? null;
				}
				if ( $rid === null ) {
					return self::insert_row( [
						'tool' => $capture['tool'], 'action' => 'create',
						'object_type' => $capture['type'], 'object_id' => '',
						'object_label' => $capture['object_label'],
						'status' => self::STATUS_NOT_UNDOABLE,
						'not_undoable_reason' => 'Could not identify the created object from the tool result.',
					] );
				}
				$capture['object_id'] = (string) $rid;
			}

			$after = self::snapshot( $capture['type'], $capture['object_id'] );
			if ( $capture['object_label'] === null ) {
				$capture['object_label'] = self::object_label( $capture['type'], $capture['object_id'], $after, [] );
			}
			return self::insert_row( [
				'tool'         => $capture['tool'],
				'action'       => $capture['action'],
				'object_type'  => $capture['type'],
				'object_id'    => $capture['object_id'],
				'object_label' => $capture['object_label'],
				'before_state' => $capture['before'],
				'after_hash'   => self::state_hash( $after ),
			] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/* ── Object id/label extraction ────────────────────────── */

	private static function extract_id( string $tool, array $strategy, array $args ): ?string {
		// wp_woo_update_setting normalizes its key (prepends woocommerce_ when
		// missing) before writing; capture must target the same option.
		if ( $tool === 'wp_woo_update_setting' ) {
			$key = (string) ( $args['key'] ?? '' );
			if ( $key === '' ) {
				return null;
			}
			return strpos( $key, 'woocommerce_' ) === 0 ? $key : 'woocommerce_' . $key;
		}

		// Tools whose target is not in the args.
		if ( $tool === 'wp_elementor_update_global_styles' ) {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_id();
				return $kit ? (string) $kit : null;
			}
			return null;
		}
		if ( $strategy['type'] === 'acf_value' ) {
			$f = $args['field_name'] ?? '';
			$o = $args['object_id'] ?? '';
			return ( $f !== '' && $o !== '' ) ? "{$f}@{$o}" : null;
		}
		if ( isset( $strategy['static_id'] ) ) {
			return $strategy['static_id'];
		}
		$arg = $strategy['id_arg'] ?? null;
		if ( $arg !== null && isset( $args[ $arg ] ) && $args[ $arg ] !== '' ) {
			return (string) $args[ $arg ];
		}
		return null;
	}

	/** Short human label for the Activity list. Extended per-type in later tasks. */
	private static function object_label( string $type, ?string $id, ?array $state, array $args ): ?string {
		return match ( $type ) {
			'option'    => $id,
			'post', 'media' => $state['post']['post_title'] ?? ( $id !== null ? "post #{$id}" : null ),
			'acf_value' => 'ACF ' . str_replace( '@', ' on ', (string) $id ),
			default     => $id,
		};
	}

	/* ── Snapshots (extended per-type in Tasks 4-9) ────────── */

	/** Sentinel distinguishing "option absent" from a stored false. */
	private const OPTION_MISSING = '__cowboy_mcp_option_missing__';

	/**
	 * Current full state of an object, or null when it does not exist.
	 * Public: the undo engine (Task 3) and conflict checks reuse it.
	 */
	public static function snapshot( string $type, string $id ): ?array {
		switch ( $type ) {
			case 'option':
				$v = get_option( $id, self::OPTION_MISSING );
				if ( self::OPTION_MISSING === $v ) {
					return null;
				}
				return [ 'value' => $v ];

			case 'post':
			case 'media':
				$post = get_post( (int) $id, ARRAY_A );
				if ( ! $post ) {
					return null;
				}
				$meta = get_post_meta( (int) $id );
				unset( $meta['_edit_lock'], $meta['_edit_last'] ); // volatile; false conflicts
				$terms = [];
				foreach ( get_object_taxonomies( $post['post_type'] ) as $tax ) {
					$t = wp_get_object_terms( (int) $id, $tax, [ 'fields' => 'ids' ] );
					if ( ! is_wp_error( $t ) && $t ) {
						sort( $t );
						$terms[ $tax ] = array_map( 'intval', $t );
					}
				}
				return [ 'post' => $post, 'meta' => $meta, 'terms' => $terms ];

			case 'acf_value':
				if ( ! function_exists( 'get_field' ) ) {
					return null;
				}
				[ $field, $object ] = array_pad( explode( '@', $id, 2 ), 2, '' );
				$object = is_numeric( $object ) ? (int) $object : $object;
				$value  = get_field( $field, $object, false ); // raw/unformatted round-trips with update_field
				return [ 'exists' => $value !== null && $value !== false, 'value' => $value ];
		}
		return null;
	}

	/* ── Restore (inverse application; per-type cases extended in Tasks 4-9) ── */

	/**
	 * Write a captured state back wholesale. $state null = delete the object.
	 */
	public static function restore( string $type, string $id, ?array $state ): true|WP_Error {
		if ( class_exists( 'Cowboy_MCP_Tools' ) ) {
			Cowboy_MCP_Tools::boot_domains(); // domain helper functions (file paths etc.)
		}
		switch ( $type ) {
			case 'option':
				if ( $state === null ) {
					delete_option( $id );
					return true;
				}
				update_option( $id, $state['value'] ); // false = value unchanged; still success
				return true;

			case 'db_rows':
				return self::restore_db_rows( $state );

			case 'post':
				return self::restore_post( (int) $id, $state );

			case 'acf_value':
				if ( ! function_exists( 'update_field' ) ) {
					return new WP_Error( 'undo_unsupported', 'ACF is not active; cannot restore field value.' );
				}
				[ $field, $object ] = array_pad( explode( '@', $id, 2 ), 2, '' );
				$object = is_numeric( $object ) ? (int) $object : $object;
				if ( $state === null || empty( $state['exists'] ) ) {
					delete_field( $field, $object );
				} else {
					update_field( $field, $state['value'], $object );
				}
				return true;
		}
		return new WP_Error( 'undo_unsupported', "No restore handler for object type '{$type}'." );
	}

	/** Restore (or recreate with original ID) a post + meta + terms. Null state = delete. */
	private static function restore_post( int $post_id, ?array $state ): true|WP_Error {
		if ( $state === null ) {
			$deleted = wp_delete_post( $post_id, true );
			return $deleted ? true : new WP_Error( 'undo_failed', "Could not delete post #{$post_id}." );
		}
		$postarr = $state['post'];
		if ( get_post( $post_id ) ) {
			$postarr['ID'] = $post_id;
			$result = wp_update_post( wp_slash( $postarr ), true );
		} else {
			unset( $postarr['ID'] );
			$postarr['import_id'] = $post_id; // preserve the original ID on reinsert
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$live_id = (int) $result;

		// Meta: clear current, re-add captured (values are stored serialized-raw arrays).
		foreach ( array_keys( get_post_meta( $live_id ) ) as $k ) {
			delete_post_meta( $live_id, $k );
		}
		foreach ( $state['meta'] as $k => $values ) {
			foreach ( (array) $values as $v ) {
				add_post_meta( $live_id, $k, wp_slash( maybe_unserialize( $v ) ) );
			}
		}

		// Terms: restore per-taxonomy assignments exactly.
		foreach ( get_object_taxonomies( $postarr['post_type'] ?? 'post' ) as $tax ) {
			wp_set_object_terms( $live_id, $state['terms'][ $tax ] ?? [], $tax );
		}
		clean_post_cache( $live_id );
		return true;
	}

	/** Re-apply captured old values row by row (wp_search_replace inverse). */
	private static function restore_db_rows( ?array $state ): true|WP_Error {
		global $wpdb;
		$rows = $state['rows'] ?? [];
		if ( empty( $rows ) ) {
			return new WP_Error( 'undo_failed', 'No captured rows to restore.' );
		}
		foreach ( $rows as $r ) {
			// Table/column names come from our own capture code (trusted), values are data.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update( $r['table'], [ $r['col'] => $r['old'] ], [ $r['pk_col'] => $r['pk_val'] ] );
			if ( $updated === false ) {
				return new WP_Error( 'undo_failed', "Row restore failed for {$r['table']} {$r['pk_col']}={$r['pk_val']}: " . $wpdb->last_error );
			}
			if ( $r['table'] === $wpdb->posts ) {
				clean_post_cache( (int) $r['pk_val'] );
			}
		}
		return true;
	}

	/** Current values of captured db_rows, for conflict hashing. */
	private static function db_rows_current_values( array $rows ): array {
		global $wpdb;
		$values = [];
		foreach ( $rows as $r ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$values[] = $wpdb->get_var( $wpdb->prepare(
				"SELECT {$r['col']} FROM %i WHERE {$r['pk_col']} = %s", $r['table'], $r['pk_val']
			) );
		}
		return $values;
	}

	/* ── Undo engine ───────────────────────────────────────── */

	public static function undo( int $change_id, bool $force = false, string $actor = 'mcp' ): array|WP_Error {
		global $wpdb;
		$row = self::get_row( $change_id );
		if ( $row === null ) {
			return new WP_Error( 'not_found', "Change #{$change_id} not found in the undo journal." );
		}
		if ( $row['status'] === self::STATUS_UNDONE ) {
			return new WP_Error( 'already_undone', "Change #{$change_id} was already undone (by {$row['undone_by']} at {$row['undone_at']})." );
		}
		if ( $row['status'] === self::STATUS_NOT_UNDOABLE ) {
			return new WP_Error( 'not_undoable', "Change #{$change_id} is not undoable: {$row['not_undoable_reason']}" );
		}

		$type = $row['object_type'];
		$id   = $row['object_id'];

		// ── Conflict check: has the object changed since this edit? ──
		if ( $type === 'db_rows' ) {
			$current      = [ 'values' => self::db_rows_current_values( $row['before_state']['rows'] ?? [] ) ];
			$current_hash = self::state_hash( $current );
			$pre_undo     = null; // redo state built by swapping old/new below
		} else {
			$pre_undo     = self::snapshot( $type, $id );
			$current_hash = self::state_hash( $pre_undo );
		}
		if ( ! $force && $row['after_hash'] !== null && $row['after_hash'] !== '' && $current_hash !== $row['after_hash'] ) {
			return new WP_Error(
				'undo_conflict',
				"Conflict: '{$row['object_label']}' ({$type} {$id}) has been modified since change #{$change_id} was made. "
					. 'Undoing now would clobber the later change.',
				[ 'suggestion' => 'Inspect the object, then resend with force: true to undo anyway.' ]
			);
		}

		// ── Apply the inverse ──
		$restored = self::restore( $type, $id, $row['before_state'] );
		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		// ── Journal the undo itself (redo capability) ──
		$redo_action = match ( $row['action'] ) {
			'create' => 'delete',
			'delete' => 'create',
			default  => $row['action'],
		};
		if ( $type === 'db_rows' ) {
			$swapped = array_map(
				fn( $r ) => array_merge( $r, [ 'old' => $r['new'], 'new' => $r['old'] ] ),
				$row['before_state']['rows'] ?? []
			);
			$pre_undo = [ 'rows' => $swapped ];
		}
		$after_undo      = $type === 'db_rows'
			? [ 'values' => self::db_rows_current_values( $row['before_state']['rows'] ?? [] ) ]
			: self::snapshot( $type, $id );
		$redo_supported  = ! ( $type === 'media' && $redo_action === 'delete' );
		$redo_change_id  = self::insert_row( [
			'tool'                => 'wp_undo_change',
			'action'              => $redo_action,
			'object_type'         => $type,
			'object_id'           => $id,
			'object_label'        => $row['object_label'],
			'before_state'        => $pre_undo,
			'after_hash'          => self::state_hash( $after_undo ),
			'undo_of'             => $change_id,
			'key_id'              => $actor,
			'status'              => $redo_supported ? self::STATUS_ACTIVE : self::STATUS_NOT_UNDOABLE,
			'not_undoable_reason' => $redo_supported ? null : 'Re-creating a deleted attachment is not supported.',
		] );

		// ── Mark the original row undone ──
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$marked = $wpdb->update( self::table(), [
			'status'    => self::STATUS_UNDONE,
			'undone_at' => gmdate( 'Y-m-d H:i:s' ),
			'undone_by' => substr( $actor, 0, 64 ),
		], [ 'id' => $change_id, 'status' => self::STATUS_ACTIVE ] );

		$note = null;
		if ( $type === 'user' && $row['action'] === 'delete' && ! empty( $row['before_state']['reassigned_to'] ) ) {
			$note = 'User restored. Content reassigned to user #' . $row['before_state']['reassigned_to']
				. ' during deletion was NOT re-reassigned; move it back explicitly if needed.';
		}
		if ( $type === 'wc_object' && $row['action'] === 'delete' ) {
			$note = 'WooCommerce object recreated with a NEW id (original id could not be preserved). Check references.';
		}

		$response = [ 'undone' => true, 'change_id' => $change_id, 'redo_change_id' => $redo_change_id, 'note' => $note ];
		if ( $marked === false || $marked === 0 ) {
			$response['warning'] = 'Object restored, but the journal row could not be marked undone (possible concurrent undo).';
		}
		return $response;
	}

	/** Undo several entries newest-first; stop at the first failure/conflict. */
	public static function undo_many( array $ids, bool $force = false, string $actor = 'mcp' ): array {
		$ids = array_unique( array_map( 'intval', $ids ) );
		rsort( $ids );
		$results = [];
		$stopped = false;
		foreach ( $ids as $id ) {
			$r = self::undo( $id, $force, $actor );
			if ( is_wp_error( $r ) ) {
				$results[] = [ 'change_id' => $id, 'undone' => false, 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ];
				$stopped   = true;
				break;
			}
			$results[] = $r;
		}
		return [
			'results'       => $results,
			'undone_count'  => count( array_filter( $results, fn( $r ) => ! empty( $r['undone'] ) ) ),
			'stopped_early' => $stopped,
		];
	}

	public static function undo_batch( string $batch_id, bool $force = false, string $actor = 'mcp' ): array|WP_Error {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE batch_id = %s AND status = %s ORDER BY id DESC',
			self::table(), $batch_id, self::STATUS_ACTIVE
		) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'not_found', "No active journal entries for batch '{$batch_id}'." );
		}
		return self::undo_many( array_map( 'intval', $ids ), $force, $actor );
	}

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
				'tool'                => substr( (string) ( $data['tool'] ?? '' ), 0, 100 ),
				'action'              => substr( (string) ( $data['action'] ?? '' ), 0, 20 ),
				'object_type'         => substr( (string) ( $data['object_type'] ?? '' ), 0, 32 ),
				'object_id'           => substr( (string) ( $data['object_id'] ?? '' ), 0, 191 ),
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
