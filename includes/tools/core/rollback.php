<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_list_changes', '[Rollback] Query the undo journal: every mutating MCP tool call with its captured before-state (secrets redacted). Filter by object, tool, batch, session, status, or date. Use the returned change_id/batch_id with wp_undo_change.', [
            'object_type' => [ 'type' => 'string', 'description' => 'Filter by object type (post, option, file, term, comment, user, plugin, theme, media, wc_object, wf_config, db_rows)' ],
            'object_id'   => [ 'type' => [ 'string', 'integer' ], 'description' => 'Filter by object id (e.g. post ID, option name, relative file path)' ],
            'tool'        => [ 'type' => 'string', 'description' => 'Filter by tool name' ],
            'batch_id'    => [ 'type' => 'string', 'description' => 'Filter by batch UUID' ],
            'session_id'  => [ 'type' => 'string', 'description' => 'Filter by MCP session id' ],
            'status'      => [ 'type' => 'string', 'description' => 'Filter by status', 'enum' => [ 'active', 'undone', 'not_undoable' ] ],
            'date_from'   => [ 'type' => 'string', 'description' => 'Start date (YYYY-MM-DD)' ],
            'date_to'     => [ 'type' => 'string', 'description' => 'End date (YYYY-MM-DD)' ],
            'per_page'    => [ 'type' => 'integer', 'description' => 'Results per page, max 200 (default 50)', 'default' => 50 ],
            'page'        => [ 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ],
        ], [ 'title' => 'List Changes', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ]),

        Cowboy_MCP_Tools::tool( 'wp_undo_change', '[Rollback] Undo journaled changes by restoring their captured before-state. Provide exactly ONE of: change_id, change_ids, or batch_id. Multiple entries are undone newest-first, stopping at the first failure. If a later edit touched the same object you get a conflict error — resend with force: true to override. Undoing is itself journaled, so an undo can be undone (redo).', [
            'change_id'  => [ 'type' => 'integer', 'description' => 'Single journal entry id to undo' ],
            'change_ids' => [ 'type' => 'array',   'description' => 'Several entry ids to undo (applied newest-first)', 'items' => [ 'type' => 'integer' ] ],
            'batch_id'   => [ 'type' => 'string',  'description' => 'Undo every active entry of this batch' ],
            'force'      => [ 'type' => 'boolean', 'description' => 'Override the changed-since conflict check', 'default' => false ],
        ], [ 'title' => 'Undo Change', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ]),

        Cowboy_MCP_Tools::tool( 'wp_create_checkpoint', '[Rollback] Snapshot the entire WordPress database (prefix-scoped SQL dump) so it can be restored later with wp_restore_checkpoint. Use before risky multi-step work. Auto-checkpoints are also taken before mutating wp_cli commands when enabled.', [
            'label' => [ 'type' => 'string', 'description' => 'Human-readable label (e.g. "Before menu restructure")' ],
        ], [ 'title' => 'Create Checkpoint', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ]),

        Cowboy_MCP_Tools::tool( 'wp_list_checkpoints', '[Rollback] List database checkpoints (id, label, trigger, size, table row-counts).', [], [ 'title' => 'List Checkpoints', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ]),

        Cowboy_MCP_Tools::tool( 'wp_restore_checkpoint', '[Rollback] Restore the database to a checkpoint. WARNING: every change made after the checkpoint — INCLUDING changes made outside MCP (new orders, comments, user signups) — is lost. A pre-restore safety checkpoint is taken automatically first. API keys and plugin settings keep their live values.', [
            'checkpoint_id' => [ 'type' => 'integer', 'description' => 'Checkpoint id from wp_list_checkpoints', 'required' => true ],
        ], [ 'title' => 'Restore Checkpoint', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ]),

        Cowboy_MCP_Tools::tool( 'wp_delete_checkpoint', '[Rollback] Delete a checkpoint and its dump file.', [
            'checkpoint_id' => [ 'type' => 'integer', 'description' => 'Checkpoint id', 'required' => true ],
        ], [ 'title' => 'Delete Checkpoint', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ]),
    ],
    'handlers' => [
        'wp_list_changes' => function ( array $a ) {
            return Cowboy_MCP_Rollback::query( $a );
        },

        'wp_undo_change' => function ( array $a ) {
            $provided = array_filter( [ isset( $a['change_id'] ), ! empty( $a['change_ids'] ), ! empty( $a['batch_id'] ) ] );
            if ( count( $provided ) !== 1 ) {
                return new WP_Error( 'invalid_params', 'Provide exactly one of: change_id, change_ids, batch_id.' );
            }
            $force = ! empty( $a['force'] );
            $actor = Cowboy_MCP_Auth::$current_key_context['key_id'] ?? 'mcp';
            if ( isset( $a['change_id'] ) ) {
                return Cowboy_MCP_Rollback::undo( (int) $a['change_id'], $force, $actor );
            }
            if ( ! empty( $a['change_ids'] ) ) {
                return Cowboy_MCP_Rollback::undo_many( (array) $a['change_ids'], $force, $actor );
            }
            return Cowboy_MCP_Rollback::undo_batch( (string) $a['batch_id'], $force, $actor );
        },

        'wp_create_checkpoint' => function ( array $a ) {
            return Cowboy_MCP_Checkpoint::create( (string) ( $a['label'] ?? '' ), 'manual' );
        },

        'wp_list_checkpoints' => function ( array $a ) {
            return [ 'checkpoints' => Cowboy_MCP_Checkpoint::list_all() ];
        },

        'wp_restore_checkpoint' => function ( array $a ) {
            $result = Cowboy_MCP_Checkpoint::restore( (int) $a['checkpoint_id'], Cowboy_MCP_Auth::$current_key_context['key_id'] ?? 'mcp' );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return $result;
        },

        'wp_delete_checkpoint' => function ( array $a ) {
            $r = Cowboy_MCP_Checkpoint::delete( (int) $a['checkpoint_id'] );
            return is_wp_error( $r ) ? $r : [ 'deleted' => true, 'checkpoint_id' => (int) $a['checkpoint_id'] ];
        },
    ],
];
