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

		'wp_write_file'  => [ 'type' => 'file', 'action' => 'update', 'id_arg' => 'path' ],
		'wp_delete_file' => [ 'type' => 'file', 'action' => 'delete', 'id_arg' => 'path' ],

		'wp_create_term'      => [ 'type' => 'term', 'action' => 'create', 'result_id' => 'term_id' ],
		'wp_update_term'      => [ 'type' => 'term', 'action' => 'update' ],
		'wp_delete_term'      => [ 'type' => 'term', 'action' => 'delete' ],
		'wp_create_comment'   => [ 'type' => 'comment', 'action' => 'create', 'result_id' => 'comment_id' ],
		'wp_update_comment'   => [ 'type' => 'comment', 'action' => 'update', 'id_arg' => 'comment_id' ],
		'wp_delete_comment'   => [ 'type' => 'comment', 'action' => 'delete', 'id_arg' => 'comment_id' ],
		'wp_woo_add_order_note' => [ 'type' => 'comment', 'action' => 'create', 'result_id' => 'note_id' ],

		'wp_create_menu'      => [ 'type' => 'menu', 'action' => 'create', 'result_id' => 'menu_id' ],
		'wp_update_menu'      => [ 'type' => 'menu', 'action' => 'update', 'id_arg' => 'menu_id' ],
		'wp_delete_menu'      => [ 'type' => 'menu', 'action' => 'delete', 'id_arg' => 'menu_id' ],
		'wp_set_menu_items'   => [ 'type' => 'menu', 'action' => 'update', 'id_arg' => 'menu_id' ],

		'wp_activate_plugin'   => [ 'type' => 'plugin', 'action' => 'toggle', 'id_arg' => 'plugin_file' ],
		'wp_deactivate_plugin' => [ 'type' => 'plugin', 'action' => 'toggle', 'id_arg' => 'plugin_file' ],
		'wp_switch_theme'      => [ 'type' => 'theme', 'action' => 'toggle', 'static_id' => 'active' ],
		'wp_upload_media'      => [ 'type' => 'media', 'action' => 'create', 'result_id' => 'attachment_id' ],
		'wp_update_media'      => [ 'type' => 'media', 'action' => 'update', 'id_arg' => 'attachment_id' ],
		'wp_create_user'       => [ 'type' => 'user', 'action' => 'create', 'result_id' => 'user_id' ],
		'wp_update_user'       => [ 'type' => 'user', 'action' => 'update', 'id_arg' => 'user_id' ],
		'wp_delete_user'       => [ 'type' => 'user', 'action' => 'delete', 'id_arg' => 'user_id' ],

		'wp_woo_create_product'   => [ 'type' => 'wc_object', 'action' => 'create', 'kind' => 'product', 'result_id' => 'product_id' ],
		'wp_woo_update_product'   => [ 'type' => 'wc_object', 'action' => 'update', 'kind' => 'product', 'id_arg' => 'product_id' ],
		'wp_woo_delete_product'   => [ 'type' => 'wc_object', 'action' => 'delete', 'kind' => 'product', 'id_arg' => 'product_id' ],
		'wp_woo_create_variation' => [ 'type' => 'wc_object', 'action' => 'create', 'kind' => 'product', 'result_id' => 'variation_id' ],
		'wp_woo_update_variation' => [ 'type' => 'wc_object', 'action' => 'update', 'kind' => 'product', 'id_arg' => 'variation_id' ],
		'wp_woo_delete_variation' => [ 'type' => 'wc_object', 'action' => 'delete', 'kind' => 'product', 'id_arg' => 'variation_id' ],
		'wp_woo_create_order'     => [ 'type' => 'wc_object', 'action' => 'create', 'kind' => 'order', 'result_id' => 'order_id' ],
		'wp_woo_update_order'     => [ 'type' => 'wc_object', 'action' => 'update', 'kind' => 'order', 'id_arg' => 'order_id' ],
		'wp_woo_delete_order'     => [ 'type' => 'wc_object', 'action' => 'delete', 'kind' => 'order', 'id_arg' => 'order_id' ],
		'wp_woo_create_refund'    => [ 'type' => 'wc_object', 'action' => 'create', 'kind' => 'order', 'result_id' => 'refund_id' ],
		'wp_woo_create_customer'  => [ 'type' => 'wc_object', 'action' => 'create', 'kind' => 'customer', 'result_id' => 'customer_id' ],
		'wp_woo_update_customer'  => [ 'type' => 'wc_object', 'action' => 'update', 'kind' => 'customer', 'id_arg' => 'customer_id' ],
		'wp_woo_create_coupon'    => [ 'type' => 'wc_object', 'action' => 'create', 'kind' => 'coupon', 'result_id' => 'coupon_id' ],
		'wp_woo_update_coupon'    => [ 'type' => 'wc_object', 'action' => 'update', 'kind' => 'coupon', 'id_arg' => 'coupon_id' ],
		'wp_woo_delete_coupon'    => [ 'type' => 'wc_object', 'action' => 'delete', 'kind' => 'coupon', 'id_arg' => 'coupon_id' ],
		// wp_woo_manage_stock is NOT registered here: its args are `{ updates: [{product_id,...}] }`
		// with no top-level product_id, so it cannot be captured as a single wc_object (see NOT_UNDOABLE).
		// wp_wordfence_block_ip/block_country/block_pattern are NOT registered here: verified none of
		// their handlers return a created block id (see NOT_UNDOABLE).
		'wp_wordfence_update_settings'   => [ 'type' => 'wf_config', 'action' => 'update' ],
		'wp_wordfence_set_firewall_mode' => [ 'type' => 'wf_config', 'action' => 'update' ],

		'wp_search_replace' => [ 'type' => 'db_rows', 'action' => 'update', 'static_id' => 'posts:post_content' ],
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
		'wp_woo_manage_stock'      => 'Bulk stock updates touch multiple products via a single updates[] array with no per-call product_id; not journaled as a single object in v1.',
		'wp_wordfence_block_ip'           => 'Wordfence does not return the created block id; remove blocks via wp_wordfence_unblock_ip.',
		'wp_wordfence_block_country'      => 'Wordfence does not return the created block id; remove blocks via wp_wordfence_unblock_ip.',
		'wp_wordfence_block_pattern'      => 'Wordfence does not return the created block id; remove blocks via wp_wordfence_unblock_ip.',
		'wp_wordfence_unblock_ip'         => 'Re-creating a removed block is not journaled in v1; re-block explicitly if needed.',
		'wp_wordfence_resolve_scan_issue' => 'Scan issue state is managed by Wordfence and not journaled.',
		'wp_wordfence_delete_scan_issues' => 'Scan issue state is managed by Wordfence and not journaled.',
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
			// Installer tools journal per-item from inside the handler (all: true
			// updates produce several rows per call — the one-row dispatch capture
			// cannot represent that). See Cowboy_MCP_Installer.
			if ( class_exists( 'Cowboy_MCP_Installer' ) && in_array( $tool, Cowboy_MCP_Installer::TOOLS, true ) ) {
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

			if ( $strategy['type'] === 'db_rows' ) {
				$handle = [
					'tool'         => $tool,
					'type'         => 'db_rows',
					'action'       => 'update',
					'object_id'    => $strategy['static_id'] ?? '',
					'object_label' => sprintf( 'Replace "%s" → "%s"', (string) ( $args['search'] ?? '' ), (string) ( $args['replace'] ?? '' ) ),
					'before'       => null,
					'rows'         => [],
					'reason'       => null,
					'ctx'          => [],
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
				if ( $strategy['type'] === 'user' && $before !== null && ! empty( $args['reassign_to'] ) ) {
					$before['reassigned_to'] = (int) $args['reassign_to'];
				}
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
				'ctx'          => [
					'taxonomy' => (string) ( $args['taxonomy'] ?? '' ),
					'kind'     => (string) ( $strategy['kind'] ?? '' ),
				],
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
				$reason = $capture['reason'] ?? 'No capture strategy.';
				// Consumes the id set by wp_cli's auto-checkpoint earlier in THIS call;
				// relies on the system.php invariant that commit() always runs for wp_cli.
				if ( $capture['tool'] === 'wp_cli' && self::$last_checkpoint_id !== null ) {
					$reason .= ' Checkpoint #' . self::$last_checkpoint_id . ' was taken before this command.';
					self::$last_checkpoint_id = null;
				}
				return self::insert_row( [
					'tool'                => $capture['tool'],
					'action'              => $capture['action'],
					'object_type'         => $capture['type'],
					'object_id'           => $capture['object_id'],
					'object_label'        => $capture['object_label'],
					'before_state'        => null,
					'after_hash'          => null,
					'status'              => self::STATUS_NOT_UNDOABLE,
					'not_undoable_reason' => $reason,
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
				$capture['object_id'] = match ( $capture['type'] ) {
					'term'      => ( $capture['ctx']['taxonomy'] ?? '' ) . ':' . $rid,
					'wc_object' => ( $capture['ctx']['kind'] ?? 'product' ) . ':' . $rid,
					default     => (string) $rid,
				};
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
		if ( $strategy['type'] === 'term' && isset( $args['taxonomy'], $args['term_id'] ) ) {
			return $args['taxonomy'] . ':' . $args['term_id'];
		}
		if ( $strategy['type'] === 'wc_object' ) {
			$arg = $strategy['id_arg'] ?? null;
			$oid = $arg !== null ? ( $args[ $arg ] ?? null ) : null;
			return $oid !== null ? $strategy['kind'] . ':' . $oid : null;
		}
		if ( $strategy['type'] === 'wf_config' ) {
			$keys = array_keys( (array) ( $args['settings'] ?? [] ) );
			if ( $keys === [] && isset( $args['mode'] ) ) {
				// set_firewall_mode writes wafStatus always, firewallEnabled for enabled/disabled.
				$keys = [ 'wafStatus' ];
				if ( in_array( $args['mode'], [ 'enabled', 'disabled' ], true ) ) {
					$keys[] = 'firewallEnabled';
				}
			}
			sort( $keys );
			return 'wfconfig:' . implode( ',', $keys );
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
			'term'    => $state['term']['name'] ?? $id,
			'comment' => $id !== null ? "comment #{$id}" : null,
			'plugin' => $id,
			'theme'  => 'active theme',
			'user'   => $state['user']['user_login'] ?? ( $id !== null ? "user #{$id}" : null ),
			'menu'    => $state['menu']['name'] ?? ( $id !== null ? "menu #{$id}" : null ),
			'wc_object' => ucfirst( str_replace( ':', ' #', (string) $id ) ),
			'wf_config' => 'Wordfence settings (' . substr( (string) $id, strlen( 'wfconfig:' ) ) . ')',
			'wf_block'  => $id !== null ? "Wordfence block #{$id}" : null,
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

			case 'file':
				if ( class_exists( 'Cowboy_MCP_Tools' ) ) {
					Cowboy_MCP_Tools::boot_domains();
				}
				if ( ! function_exists( 'cowboy_mcp_resolve_wp_content_path' ) ) {
					return null;
				}
				$full = cowboy_mcp_resolve_wp_content_path( $id );
				if ( is_wp_error( $full ) || ! is_file( $full ) ) {
					return null;
				}
				$content = file_get_contents( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( $content === false ) {
					return null;
				}
				return [ 'content_b64' => base64_encode( $content ), 'size' => strlen( $content ) ];

			case 'term': {
				global $wpdb;
				[ $tax, $tid ] = array_pad( explode( ':', $id, 2 ), 2, '' );
				$tid = (int) $tid;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$term = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE term_id = %d', $wpdb->terms, $tid ), ARRAY_A );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$tt = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE term_id = %d AND taxonomy = %s', $wpdb->term_taxonomy, $tid, $tax ), ARRAY_A );
				if ( ! $term || ! $tt ) {
					return null;
				}
				$objects = get_objects_in_term( $tid, $tax );
				$objects = is_wp_error( $objects ) ? [] : array_map( 'intval', $objects );
				sort( $objects );
				return [ 'term' => $term, 'tt' => $tt, 'meta' => get_term_meta( $tid ), 'objects' => $objects ];
			}

			case 'comment': {
				$c = get_comment( (int) $id, ARRAY_A );
				if ( ! $c ) {
					return null;
				}
				return [ 'comment' => $c, 'meta' => get_comment_meta( (int) $id ) ];
			}

			case 'menu': {
				$menu = wp_get_nav_menu_object( (int) $id );
				if ( ! $menu ) {
					return null;
				}
				$items = [];
				foreach ( wp_get_nav_menu_items( $menu->term_id ) ?: [] as $item ) {
					$items[] = [
						'db_id'       => (int) $item->db_id,
						'parent'      => (int) $item->menu_item_parent,
						'order'       => (int) $item->menu_order,
						'title'       => $item->title,
						'url'         => $item->url,
						'type'        => $item->type,
						'object'      => $item->object,
						'object_id'   => (int) $item->object_id,
						'target'      => $item->target,
						'classes'     => array_values( array_filter( (array) $item->classes ) ),
						'description' => $item->description,
						'attr_title'  => $item->attr_title,
						'xfn'         => $item->xfn,
					];
				}
				$locations = [];
				foreach ( get_nav_menu_locations() as $location => $held_by ) {
					if ( (int) $held_by === (int) $menu->term_id ) {
						$locations[] = $location;
					}
				}
				sort( $locations );
				return [
					'menu'      => [ 'name' => $menu->name, 'slug' => $menu->slug, 'description' => $menu->description ],
					'locations' => $locations,
					'items'     => $items,
				];
			}

			case 'plugin':
				return [ 'active' => Cowboy_MCP_Compat::is_plugin_active( $id ) ];

			case 'theme':
				return [ 'stylesheet' => get_stylesheet() ];

			case 'user': {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$user = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->users, (int) $id ), ARRAY_A );
				if ( ! $user ) {
					return null;
				}
				$meta = get_user_meta( (int) $id );
				unset( $meta['session_tokens'] ); // stale sessions must not be resurrected
				return [ 'user' => $user, 'meta' => $meta, 'reassigned_to' => null ];
			}

			case 'wc_object': {
				if ( ! class_exists( 'WooCommerce' ) ) {
					return null;
				}
				[ $kind, $oid ] = array_pad( explode( ':', $id, 2 ), 2, '' );
				$obj = self::wc_get_object( $kind, (int) $oid );
				if ( ! $obj || ! $obj->get_id() ) {
					return null;
				}
				return [ 'class' => get_class( $obj ), 'data' => self::wc_clean_data( $obj->get_data() ) ];
			}

			case 'wf_config': {
				if ( ! class_exists( 'wfConfig' ) ) {
					return null;
				}
				$keys = explode( ',', substr( $id, strlen( 'wfconfig:' ) ) );
				$vals = [];
				foreach ( array_filter( $keys ) as $k ) {
					$vals[ $k ] = wfConfig::get( $k );
				}
				return [ 'keys' => $vals ];
			}

			// Currently unreachable: no strategy maps to wf_block (handlers return no block id); kept as forward-compat scaffolding.
			case 'wf_block':
				return null; // creates only; nothing to snapshot before

			case 'plugin_files': {
				$file = Cowboy_MCP_Installer::find_plugin_file( $id );
				if ( $file === null ) {
					return null;
				}
				$v = Cowboy_MCP_Compat::get_plugins()[ $file ]['Version'] ?? '';
				return [ 'version' => (string) $v ];
			}

			case 'theme_files': {
				$theme = wp_get_theme( $id );
				return $theme->exists() ? [ 'version' => (string) $theme->get( 'Version' ) ] : null;
			}
		}
		return null;
	}

	private static function wc_get_object( string $kind, int $id ): ?object {
		try {
			return match ( $kind ) {
				'product'  => wc_get_product( $id ) ?: null,
				'order'    => wc_get_order( $id ) ?: null,
				'customer' => new WC_Customer( $id ),
				'coupon'   => new WC_Coupon( $id ),
				default    => null,
			};
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Recursively convert WC_DateTime/DateTime values to ISO strings for JSON round-trips. */
	private static function wc_clean_data( mixed $data ): mixed {
		if ( $data instanceof \DateTimeInterface ) {
			return $data->format( DATE_ATOM );
		}
		if ( is_object( $data ) && method_exists( $data, 'get_data' ) ) {
			return self::wc_clean_data( $data->get_data() ); // WC_Meta_Data and friends
		}
		if ( is_array( $data ) ) {
			return array_map( [ __CLASS__, 'wc_clean_data' ], $data );
		}
		return $data;
	}

	/* ── Restore (inverse application; per-type cases extended in Tasks 4-9) ── */

	/**
	 * Write a captured state back wholesale. $state null = delete the object.
	 */
	public static function restore( string $type, string $id, ?array $state ): bool|WP_Error {
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

			case 'file':
				return self::restore_file( $id, $state );

			case 'term':
				return self::restore_term( $id, $state );

			case 'comment':
				return self::restore_comment( (int) $id, $state );

			case 'menu':
				return self::restore_menu( (int) $id, $state );

			case 'plugin':
				if ( $state === null ) {
					return new WP_Error( 'undo_unsupported', 'No captured plugin state.' );
				}
				if ( ! empty( $state['active'] ) ) {
					$r = Cowboy_MCP_Compat::activate_plugin( $id );
					return is_wp_error( $r ) ? $r : true;
				}
				Cowboy_MCP_Compat::deactivate_plugin( $id );
				return true;

			case 'theme':
				if ( $state === null || empty( $state['stylesheet'] ) ) {
					return new WP_Error( 'undo_unsupported', 'No captured theme state.' );
				}
				switch_theme( $state['stylesheet'] );
				return true;

			case 'media':
				// Null state = undo of an upload → remove the attachment. Non-null =
				// undo of a metadata edit; the attachment post row and meta (including
				// _wp_attachment_image_alt) round-trip through restore_post().
				if ( $state === null ) {
					return wp_delete_attachment( (int) $id, true )
						? true
						: new WP_Error( 'undo_failed', "Could not delete attachment #{$id}." );
				}
				return self::restore_post( (int) $id, $state );

			case 'user':
				return self::restore_user( (int) $id, $state );

			case 'wc_object':
				return self::restore_wc_object( $id, $state );

			case 'wf_config':
				if ( ! class_exists( 'wfConfig' ) ) {
					return new WP_Error( 'undo_unsupported', 'Wordfence is not active.' );
				}
				foreach ( (array) ( $state['keys'] ?? [] ) as $k => $v ) {
					wfConfig::set( $k, $v );
				}
				return true;

			// Currently unreachable: no strategy maps to wf_block (handlers return no block id); kept as forward-compat scaffolding.
			case 'wf_block':
				if ( $state === null ) {
					if ( class_exists( 'wfBlock' ) && method_exists( 'wfBlock', 'removeBlockIDs' ) ) {
						wfBlock::removeBlockIDs( [ (int) $id ] );
						return true;
					}
					return new WP_Error( 'undo_unsupported', 'Wordfence block removal API unavailable.' );
				}
				return new WP_Error( 'undo_unsupported', 'Re-creating a removed Wordfence block is not supported.' );

			case 'plugin_files':
			case 'theme_files': {
				$root    = $type === 'plugin_files' ? Cowboy_MCP_Compat::plugins_dir() : get_theme_root();
				$current = $root . '/' . $id;
				$flush   = static function () use ( $type ) {
					if ( $type === 'plugin_files' ) {
						Cowboy_MCP_Compat::flush_plugins_cache();
					} else {
						wp_clean_themes_cache();
					}
				};
				if ( $state === null ) { // undo-install / redo-delete → remove the package
					// Never remove an ACTIVE package: undoing an install that was later
					// activated must not break the live site (mirrors the delete tools'
					// active_delete guard). Also covers redo-of-delete.
					if ( $type === 'plugin_files' ) {
						$pf = Cowboy_MCP_Installer::find_plugin_file( $id );
						if ( $pf !== null && ( Cowboy_MCP_Compat::is_plugin_active( $pf ) || Cowboy_MCP_Compat::is_plugin_active_for_network( $pf ) ) ) {
							return new WP_Error( 'active_undo_target', "Plugin '{$pf}' is active. Deactivate it first with wp_deactivate_plugin, then retry the undo." );
						}
					} elseif ( $id === get_stylesheet() || $id === get_template() ) {
						return new WP_Error( 'active_undo_target', "Theme '{$id}' is the active theme (or its parent). Switch themes first with wp_switch_theme, then retry the undo." );
					}
					if ( is_dir( $current ) ) {
						Cowboy_MCP_Installer::delete_dir( $current );
					} elseif ( is_file( $current ) ) {
						wp_delete_file( $current );
					}
					$flush();
					return true;
				}
				if ( empty( $state['backup_zip'] ) ) {
					return new WP_Error( 'undo_unsupported', 'No file backup in this journal entry; re-run the install/update tool to re-apply it.' );
				}
				$backups = Cowboy_MCP_Installer::backups_dir();
				$breal   = is_string( $backups ) ? realpath( $backups ) : false;
				$zreal   = realpath( (string) $state['backup_zip'] );
				if ( is_wp_error( $backups ) || $breal === false || $zreal === false || ! str_starts_with( $zreal, $breal . '/' ) ) {
					return new WP_Error( 'backup_missing', 'The backup archive for this change no longer exists (pruned or removed).' );
				}

				// Stage-then-swap: extract the backup into a temp dir first and verify
				// it, so a mid-extraction failure never leaves the live folder half-restored.
				$staging = $backups . '/tmp-restore-' . wp_generate_password( 8, false );
				wp_mkdir_p( $staging );
				$ex = Cowboy_MCP_Installer::extract_backup( $zreal, $staging );
				if ( is_wp_error( $ex ) ) {
					Cowboy_MCP_Installer::delete_dir( $staging );
					return $ex;
				}
				$staged = $staging . '/' . $id;
				if ( ! file_exists( $staged ) ) {
					Cowboy_MCP_Installer::delete_dir( $staging );
					return new WP_Error( 'backup_missing', 'The backup archive does not contain the expected package.' );
				}
				// Aside-rename swap (same shape as Cowboy_MCP_Installer::update_single()):
				// the live package is only ever discarded AFTER the verified staged
				// copy is confirmed in place, so a failed swap always leaves a working
				// package behind — never a half-deleted live folder.
				$aside       = $root . '/' . $id . '-cowboy-aside-' . time();
				$had_current = is_dir( $current ) || is_file( $current );
				if ( $had_current && ! @rename( $current, $aside ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap; WP_Filesystem requires wp-admin includes (hard invariant)
					Cowboy_MCP_Installer::delete_dir( $staging );
					return new WP_Error( 'fs_not_writable', 'Could not move the current package aside for restore.' );
				}
				if ( ! @rename( $staged, $current ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap; WP_Filesystem requires wp-admin includes (hard invariant)
					if ( $had_current ) {
						@rename( $aside, $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- rollback of failed swap
					}
					Cowboy_MCP_Installer::delete_dir( $staging );
					$flush();
					return new WP_Error( 'fs_not_writable', 'Could not move the restored package into place; the previous state was preserved.' );
				}
				if ( $had_current ) {
					if ( is_dir( $aside ) ) {
						Cowboy_MCP_Installer::delete_dir( $aside );
					} elseif ( is_file( $aside ) ) {
						wp_delete_file( $aside );
					}
				}
				Cowboy_MCP_Installer::delete_dir( $staging );
				$flush();
				return true;
			}
		}
		return new WP_Error( 'undo_unsupported', "No restore handler for object type '{$type}'." );
	}

	/** Restore (or recreate with original ID) a post + meta + terms. Null state = delete. */
	private static function restore_post( int $post_id, ?array $state ): bool|WP_Error {
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
	private static function restore_db_rows( ?array $state ): bool|WP_Error {
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

	/** Rewrite a file's captured bytes atomically, or delete it (null state). */
	private static function restore_file( string $relpath, ?array $state ): bool|WP_Error {
		if ( ! function_exists( 'cowboy_mcp_resolve_wp_content_path' ) ) {
			return new WP_Error( 'undo_failed', 'File helpers unavailable.' );
		}
		$full = cowboy_mcp_resolve_wp_content_path( $relpath );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		if ( $state === null ) {
			if ( file_exists( $full ) ) {
				wp_delete_file( $full );
			}
			return true;
		}
		if ( function_exists( 'cowboy_mcp_is_blocked_upload_write' ) && cowboy_mcp_is_blocked_upload_write( $full ) ) {
			return new WP_Error( 'blocked_extension', 'Refusing to restore an executable file into the uploads directory.' );
		}
		$content = base64_decode( (string) ( $state['content_b64'] ?? '' ), true );
		if ( $content === false ) {
			return new WP_Error( 'undo_failed', 'Captured file content is corrupted.' );
		}
		$dir = dirname( $full );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$tmp = $full . '.mcp_tmp_' . uniqid( '', true );
		if ( file_put_contents( $tmp, $content ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp );
			return new WP_Error( 'undo_failed', "Could not write {$relpath}." );
		}
		// Atomic same-directory rename; same justification as wp_write_file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $tmp, $full ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'undo_failed', "Atomic rename failed for {$relpath}." );
		}
		return true;
	}

	/** Restore (or recreate with the original ID) a term + term_taxonomy row + meta + object relationships. */
	private static function restore_term( string $composite, ?array $state ): bool|WP_Error {
		global $wpdb;
		[ $tax, $tid ] = array_pad( explode( ':', $composite, 2 ), 2, '' );
		$tid = (int) $tid;
		if ( $state === null ) {
			$r = wp_delete_term( $tid, $tax );
			return is_wp_error( $r ) ? $r : true;
		}
		$exists = term_exists( $tid, $tax );
		if ( ! $exists ) {
			// Recreate rows with the original IDs (wp_insert_term cannot force an id).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $wpdb->terms, $state['term'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $wpdb->term_taxonomy, $state['tt'] );
		} else {
			$r = wp_update_term( $tid, $tax, wp_slash( [
				'name'        => $state['term']['name'],
				'slug'        => $state['term']['slug'],
				'description' => $state['tt']['description'],
				'parent'      => (int) $state['tt']['parent'],
			] ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		foreach ( array_keys( get_term_meta( $tid ) ) as $k ) {
			delete_term_meta( $tid, $k );
		}
		foreach ( $state['meta'] as $k => $values ) {
			foreach ( (array) $values as $v ) {
				add_term_meta( $tid, $k, wp_slash( maybe_unserialize( $v ) ) );
			}
		}
		foreach ( $state['objects'] as $object_id ) {
			wp_set_object_terms( (int) $object_id, [ $tid ], $tax, true );
		}
		clean_term_cache( $tid, $tax );
		return true;
	}

	/** Restore (or recreate with the original ID) a comment row + meta. Null state = delete. */
	private static function restore_comment( int $cid, ?array $state ): bool|WP_Error {
		global $wpdb;
		if ( $state === null ) {
			return wp_delete_comment( $cid, true ) ? true : new WP_Error( 'undo_failed', "Could not delete comment #{$cid}." );
		}
		if ( get_comment( $cid ) ) {
			$row = $state['comment'];
			$row['comment_ID'] = $cid;
			wp_update_comment( wp_slash( $row ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $wpdb->comments, $state['comment'] );
			wp_update_comment_count( (int) $state['comment']['comment_post_ID'] );
		}
		foreach ( array_keys( get_comment_meta( $cid ) ) as $k ) {
			delete_comment_meta( $cid, $k );
		}
		foreach ( $state['meta'] as $k => $values ) {
			foreach ( (array) $values as $v ) {
				add_comment_meta( $cid, $k, wp_slash( maybe_unserialize( $v ) ) );
			}
		}
		clean_comment_cache( $cid );
		return true;
	}

	/** Recreate a deleted user with the original ID + all usermeta. */
	/**
	 * Restore a classic nav menu. Null state deletes it (undo of a create).
	 * A missing menu is re-created, which yields a NEW term_id — the same
	 * constraint restore_wc_object() carries, surfaced in undo()'s note.
	 */
	private static function restore_menu( int $menu_id, ?array $state ): bool|WP_Error {
		if ( $state === null ) {
			if ( ! wp_get_nav_menu_object( $menu_id ) ) {
				return true; // already gone; undo is idempotent
			}
			$deleted = wp_delete_nav_menu( $menu_id );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			return $deleted !== false ? true : new WP_Error( 'undo_failed', "Could not delete menu #{$menu_id}." );
		}

		if ( ! function_exists( 'cowboy_mcp_menu_item_args' ) ) {
			return new WP_Error( 'undo_unsupported', 'Menu tools are unavailable; cannot restore menu items.' );
		}

		$target_id = $menu_id;
		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			$created = wp_create_nav_menu( $state['menu']['name'] );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$target_id = (int) $created;
		} else {
			$updated = wp_update_nav_menu_object( $menu_id, [ 'menu-name' => $state['menu']['name'] ] );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		foreach ( wp_get_nav_menu_items( $target_id ) ?: [] as $existing ) {
			wp_delete_post( (int) $existing->db_id, true );
		}

		// Parents must exist before their children, and menu_order does not
		// guarantee that ordering. Create what we can each pass until no further
		// progress is possible; anything left over was orphaned.
		$pending = $state['items'];
		usort( $pending, static fn( $x, $y ) => $x['order'] <=> $y['order'] );
		$id_map   = [];
		$position = 0;
		$guard    = 0;
		while ( $pending && $guard++ < 50 ) {
			$deferred = [];
			foreach ( $pending as $row ) {
				$old_parent = (int) $row['parent'];
				if ( $old_parent !== 0 && ! isset( $id_map[ $old_parent ] ) ) {
					$deferred[] = $row;
					continue;
				}
				$new_id = wp_update_nav_menu_item(
					$target_id,
					0,
					cowboy_mcp_menu_item_args( $row, $old_parent === 0 ? 0 : (int) $id_map[ $old_parent ], ++$position )
				);
				if ( ! is_wp_error( $new_id ) ) {
					$id_map[ (int) $row['db_id'] ] = (int) $new_id;
				}
			}
			if ( count( $deferred ) === count( $pending ) ) {
				break; // orphaned items reference a parent that never existed
			}
			$pending = $deferred;
		}

		$locations = get_nav_menu_locations();
		foreach ( $locations as $location => $held_by ) {
			if ( (int) $held_by === $target_id ) {
				$locations[ $location ] = 0;
			}
		}
		foreach ( (array) $state['locations'] as $location ) {
			$locations[ $location ] = $target_id;
		}
		set_theme_mod( 'nav_menu_locations', $locations );

		return true;
	}

	private static function restore_user( int $uid, ?array $state ): bool|WP_Error {
		global $wpdb;

		// ── Undo of a creation → remove the user again. ──
		if ( $state === null ) {
			if ( ! get_user_by( 'id', $uid ) ) {
				return true; // already gone; undo is idempotent
			}
			if ( $uid === get_current_user_id() ) {
				return new WP_Error( 'undo_conflict', "User #{$uid} is the currently authenticated user and cannot be removed." );
			}
			$user = get_userdata( $uid );
			if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
				$admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ] );
				if ( count( $admins ) <= 1 ) {
					return new WP_Error( 'undo_conflict', 'Undoing this creation would delete the last administrator.' );
				}
			}
			return Cowboy_MCP_Compat::delete_user( $uid, null )
				? true
				: new WP_Error( 'undo_failed', "Could not remove user #{$uid}." );
		}

		// ── Undo of a deletion → reinsert the row verbatim. ──
		if ( ! get_user_by( 'id', $uid ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( ! $wpdb->insert( $wpdb->users, $state['user'] ) ) {
				return new WP_Error( 'undo_failed', "Could not reinsert user #{$uid}." );
			}
			foreach ( $state['meta'] as $k => $values ) {
				foreach ( (array) $values as $v ) {
					add_user_meta( $uid, $k, wp_slash( maybe_unserialize( $v ) ) );
				}
			}
			clean_user_cache( $uid );
			return true;
		}

		// ── Undo of an update → restore columns, then reconcile captured meta. ──
		$columns = $state['user'];
		unset( $columns['ID'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( $wpdb->users, $columns, [ 'ID' => $uid ] );

		// session_tokens is deliberately excluded from snapshot(): restoring stale
		// sessions is wrong, and DELETING the live blob would log every user on the
		// site out the moment someone undoes a profile edit. Everything else is
		// reconciled — captured keys are rewritten, and keys that appeared after the
		// capture are removed so the undo is a true inverse.
		$skip     = [ 'session_tokens' ];
		$captured = array_keys( (array) $state['meta'] );
		$current  = array_keys( (array) get_user_meta( $uid ) );
		foreach ( array_diff( $current, $captured, $skip ) as $stale_key ) {
			delete_user_meta( $uid, $stale_key );
		}
		foreach ( $state['meta'] as $k => $values ) {
			if ( in_array( $k, $skip, true ) ) {
				continue;
			}
			delete_user_meta( $uid, $k );
			foreach ( (array) $values as $v ) {
				add_user_meta( $uid, $k, wp_slash( maybe_unserialize( $v ) ) );
			}
		}
		clean_user_cache( $uid );
		return true;
	}

	/** Restore (or recreate with a NEW id) a WooCommerce object. Null state = delete. */
	private static function restore_wc_object( string $composite, ?array $state ): bool|WP_Error {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'undo_unsupported', 'WooCommerce is not active.' );
		}
		[ $kind, $oid ] = array_pad( explode( ':', $composite, 2 ), 2, '' );
		$oid = (int) $oid;
		if ( $state === null ) {
			$obj = self::wc_get_object( $kind, $oid );
			if ( $obj && $obj->get_id() ) {
				$obj->delete( true );
			}
			return true;
		}
		$obj = self::wc_get_object( $kind, $oid );
		if ( ! $obj || ! $obj->get_id() ) {
			// Recreate: WC data stores cannot force an id → NEW id, flagged in undo() note.
			$class = $state['class'];
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'undo_failed', "Class {$class} unavailable." );
			}
			$obj = new $class();
		}
		$data = $state['data'];
		$meta = $data['meta_data'] ?? [];
		unset( $data['id'], $data['meta_data'] );
		$obj->set_props( $data );
		foreach ( $meta as $m ) {
			if ( isset( $m['key'] ) ) {
				$obj->update_meta_data( $m['key'], $m['value'] ?? '' );
			}
		}
		$obj->save();
		return true;
	}

	/** Current values of captured db_rows, for conflict hashing. */
	private static function db_rows_current_values( array $rows ): array {
		global $wpdb;
		$values = [];
		foreach ( $rows as $r ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$values[] = $wpdb->get_var( $wpdb->prepare( "SELECT {$r['col']} FROM %i WHERE {$r['pk_col']} = %s", $r['table'], $r['pk_val'] ) );
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
		$redo_supported = ! ( $type === 'media' && $redo_action === 'delete' );
		$redo_reason    = 'Re-creating a deleted attachment is not supported.';
		if ( in_array( $type, [ 'plugin_files', 'theme_files' ], true ) && $pre_undo !== null ) {
			// Redo would need the removed version's bytes, which are not re-zipped
			// on undo; only "remove the package again" redos are journaled.
			$redo_supported = false;
			$redo_reason    = 'Package redo is not journaled; re-run the install/update tool to re-apply it.';
		}
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
			'not_undoable_reason' => $redo_supported ? null : $redo_reason,
		] );

		// ── Mark the original row undone ──
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
		if ( $type === 'menu' && $row['action'] === 'delete' ) {
			$note = 'Menu re-created with a NEW id (the original term_id could not be preserved). Items and theme location assignments were restored; check any hard-coded menu ids.';
		}
		if ( in_array( $type, [ 'plugin_files', 'theme_files' ], true ) && $row['action'] === 'update' ) {
			$note = 'Files restored to the previous version. Database changes made by the update (migrations) are NOT reverted — restore the pre-update checkpoint if the update ran migrations.';
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
				'batch_id'            => self::$batch_id !== null ? substr( self::$batch_id, 0, 36 ) : null,
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
			$formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ];
			// Explicit formats: wpdb's global field_types maps the column NAME object_id
			// to %d (core tables use it for ints); ours is a string — without this, option
			// names and file paths get silently zeroed. Live-verified failure mode.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert( self::table(), $row, $formats );
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
			$row['before_state'] = $state === null ? null : self::preview_state( $state, $row['object_type'], $row['object_id'] );
			unset( $row['after_hash'] ); // internal detail, not useful to callers
		}
		unset( $row );

		return [ 'entries' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
	}

	/** Redact secrets and truncate long values for listing display. */
	private static function preview_state( array $state, string $object_type, string $object_id ): array {
		if ( $object_type === 'option' && class_exists( 'Cowboy_MCP_Security' )
			&& Cowboy_MCP_Security::is_sensitive_option( $object_id ) ) {
			$state['value'] = '[REDACTED]';
		}
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
		// Package rows own a backup zip on disk; delete it with the row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$blobs = $wpdb->get_col( $wpdb->prepare( "SELECT before_state FROM %i WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY) AND object_type IN ('plugin_files','theme_files') AND before_state IS NOT NULL", self::table(), $days ) );
		$backups = class_exists( 'Cowboy_MCP_Installer' ) ? Cowboy_MCP_Installer::backups_dir() : null;
		$breal   = is_string( $backups ) ? realpath( $backups ) : false;
		foreach ( $blobs as $blob ) {
			$state = json_decode( (string) @gzuncompress( (string) $blob ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$zip   = is_array( $state ) ? (string) ( $state['backup_zip'] ?? '' ) : '';
			// Containment check: only ever delete inside our own backups dir.
			$zreal = $zip !== '' ? realpath( $zip ) : false;
			if ( $zreal !== false && $breal !== false && str_starts_with( $zreal, $breal . '/' ) && is_file( $zreal ) ) {
				wp_delete_file( $zreal );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM %i WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)',
			self::table(),
			$days
		) );
		return (int) $wpdb->rows_affected;
	}
}
