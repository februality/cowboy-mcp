<?php
/**
 * Cowboy MCP – Admin: Activity page (DB checkpoints + change journal / undo).
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Admin_Activity {

    const PER_PAGE = 25;

    /** Journal key ids that are not credentials: wp-admin, the MCP fallback actor, the Abilities API. */
    const ACTORS = [ 'admin', 'mcp', 'ability' ];

    /** Most removed/untraceable keys listed in the key filter (live credentials are never cut). */
    const TAIL_MAX = 50;

    public static function render_tab(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view filters.
        $filters = [
            'search' => sanitize_text_field( wp_unslash( $_GET['jsearch'] ?? '' ) ),
            'status' => sanitize_key( wp_unslash( $_GET['jstatus'] ?? '' ) ),
            'key_id' => sanitize_text_field( wp_unslash( $_GET['jkey'] ?? '' ) ),
        ];
        $page = max( 1, absint( wp_unslash( $_GET['activity_page'] ?? 1 ) ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $filters['status'], [ '', 'active', 'undone', 'not_undoable' ], true ) ) {
            $filters['status'] = '';
        }

        $key_options = self::key_options( $filters['key_id'] );
        $query       = $filters;
        if ( '' !== $filters['key_id'] ) {
            unset( $query['key_id'] );
            // A grouped option filters on all of its keys. Anything else is taken as one
            // raw key id; a stale `conn:` group then matches nothing rather than everything.
            $query['key_ids'] = $key_options[ $filters['key_id'] ]['ids'] ?? [ $filters['key_id'] ];
        }

        $result    = Cowboy_MCP_Rollback::query( array_merge( $query, [ 'per_page' => self::PER_PAGE, 'page' => $page ] ) );
        $entries   = $result['entries'];
        $on_page   = array_map( 'intval', array_column( $entries, 'id' ) );
        $undone_by = Cowboy_MCP_Rollback::undone_by_map( $on_page );
        $nonce     = wp_create_nonce( 'cowboy_mcp_activity' );

        $conflict = get_transient( 'cowboy_mcp_undo_conflict_' . get_current_user_id() );
        if ( $conflict ) {
            delete_transient( 'cowboy_mcp_undo_conflict_' . get_current_user_id() );
        }
        $conflict_inline = $conflict && in_array( (int) $conflict['change_id'], $on_page, true );
        ?>
        <p class="cmcp-lede"><?php esc_html_e( 'Every change an agent makes is journaled with its before-state. Undo a single change, or roll the whole database back to a checkpoint.', 'cowboy-mcp' ); ?></p>
        <?php
        if ( $conflict && ! $conflict_inline ) {
            ?>
            <section class="cmcp-card cmcp-conflict-card">
                <div class="cmcp-doc-body"><?php self::render_conflict( $conflict, $nonce ); ?></div>
            </section>
            <?php
        }
        self::render_checkpoints( $nonce );
        ?>
        <section class="cmcp-card cmcp-journal-card" aria-labelledby="cmcp-journal-h">
            <div class="cmcp-card-h"><h2 id="cmcp-journal-h"><?php esc_html_e( 'Change journal', 'cowboy-mcp' ); ?></h2></div>
            <?php self::render_toolbar( $filters, (int) $result['total'], $page, $key_options ); ?>
            <?php if ( empty( $entries ) ) : ?>
                <p class="cmcp-empty"><?php echo array_filter( $filters ) ? esc_html__( 'No changes match these filters.', 'cowboy-mcp' ) : esc_html__( 'No journaled changes yet.', 'cowboy-mcp' ); ?></p>
            <?php else : ?>
                <div class="cmcp-table-wrap">
                    <table class="cmcp-t cmcp-journal">
                        <thead><tr>
                            <th scope="col"><?php esc_html_e( 'ID', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Time', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Tool', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Object', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Action', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Key', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Status', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Undo', 'cowboy-mcp' ); ?></span></th>
                        </tr></thead>
                        <tbody>
                        <?php
                        foreach ( $entries as $e ) {
                            self::render_row( $e, $undone_by, $on_page, $nonce );
                            if ( $conflict_inline && (int) $conflict['change_id'] === (int) $e['id'] ) {
                                echo '<tr class="cmcp-panel-row"><td colspan="8">';
                                self::render_conflict( $conflict, $nonce );
                                echo '</td></tr>';
                            }
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function render_row( array $e, array $undone_by, array $on_page, string $nonce ): void {
        $id      = (int) $e['id'];
        $status  = (string) $e['status'];
        $undo_of = (int) ( $e['undo_of'] ?? 0 );
        $by      = 'undone' === $status ? (int) ( $undone_by[ $id ] ?? 0 ) : 0;
        $pair    = $undo_of ?: $by;
        $object  = (string) ( $e['object_label'] ?: $e['object_id'] );
        $badge   = [ 'active' => 'cmcp-bdg--ok', 'undone' => '', 'not_undoable' => 'cmcp-bdg--warn' ][ $status ] ?? '';
        ?>
        <tr id="cmcp-change-<?php echo esc_attr( (string) $id ); ?>" class="<?php echo esc_attr( 'undone' === $status ? 'is-undone' : '' ); ?>"<?php echo $pair ? ' data-cmcp-pair="' . esc_attr( (string) $pair ) . '"' : ''; ?>>
            <td class="cmcp-mono">#<?php echo esc_html( (string) $id ); ?></td>
            <td class="cmcp-mono cmcp-nowrap"><?php echo esc_html( (string) $e['timestamp'] ); ?></td>
            <td>
                <span class="cmcp-tool"><?php echo esc_html( (string) $e['tool'] ); ?></span>
                <?php if ( $undo_of ) : ?>
                    <span class="cmcp-rel"><?php
                        /* translators: %s: link or plain text "#<change id>" */
                        printf( esc_html__( 'reverts %s', 'cowboy-mcp' ), self::change_ref( $undo_of, $on_page ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- change_ref() escapes.
                    ?></span>
                <?php endif; ?>
                <?php if ( ! empty( $e['batch_id'] ) ) : ?>
                    <span class="cmcp-batchtag"><?php
                        /* translators: %s: short batch id */
                        echo esc_html( sprintf( __( 'batch %s', 'cowboy-mcp' ), substr( (string) $e['batch_id'], 0, 8 ) ) );
                    ?></span>
                <?php endif; ?>
            </td>
            <td class="cmcp-obj" title="<?php echo esc_attr( $e['object_type'] . ' ' . $e['object_id'] ); ?>"><?php echo esc_html( $object ); ?></td>
            <td><?php echo esc_html( (string) $e['action'] ); ?></td>
            <td class="cmcp-mono"><?php echo esc_html( (string) ( $e['key_label'] ?: $e['key_id'] ) ); ?></td>
            <td class="cmcp-st">
                <span class="cmcp-bdg <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( str_replace( '_', ' ', $status ) ); ?></span>
                <?php if ( $by ) : ?>
                    <span class="cmcp-rel"><?php
                        /* translators: %s: link or plain text "#<change id>" */
                        printf( esc_html__( 'by %s', 'cowboy-mcp' ), self::change_ref( $by, $on_page ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- change_ref() escapes.
                    ?></span>
                <?php endif; ?>
                <?php if ( 'not_undoable' === $status && ! empty( $e['not_undoable_reason'] ) ) : ?>
                    <span class="cmcp-reason"><?php echo esc_html( (string) $e['not_undoable_reason'] ); ?></span>
                <?php endif; ?>
            </td>
            <td class="cmcp-acts-cell">
                <?php if ( 'active' === $status ) : ?>
                    <div class="cmcp-acts">
                        <form method="post" data-cmcp-confirm="<?php
                            /* translators: 1: change ID number, 2: object name, 3: date and time of the change */
                            echo esc_attr( sprintf( __( 'Undo change #%1$s? Restores %2$s to how it was before %3$s.', 'cowboy-mcp' ), $id, $object, $e['timestamp'] ) );
                        ?>" data-cmcp-confirm-label="<?php esc_attr_e( 'Undo', 'cowboy-mcp' ); ?>">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                            <input type="hidden" name="change_id" value="<?php echo esc_attr( (string) $id ); ?>">
                            <button type="submit" name="cowboy_mcp_undo_change" value="1" class="cmcp-btn cmcp-btn--sm"><?php esc_html_e( 'Undo', 'cowboy-mcp' ); ?></button>
                        </form>
                        <?php if ( ! empty( $e['batch_id'] ) ) : ?>
                            <form method="post" data-cmcp-confirm="<?php esc_attr_e( 'Undo ALL active changes in this batch (newest first)?', 'cowboy-mcp' ); ?>" data-cmcp-confirm-label="<?php esc_attr_e( 'Undo batch', 'cowboy-mcp' ); ?>">
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                                <input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $e['batch_id'] ); ?>">
                                <button type="submit" name="cowboy_mcp_undo_batch" value="1" class="cmcp-btn cmcp-btn--sm"><?php esc_html_e( 'Undo batch', 'cowboy-mcp' ); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /** "#123" as an in-page link when that row is rendered, plain text otherwise. Escaped. */
    private static function change_ref( int $id, array $on_page ): string {
        $label = '#' . $id;
        return in_array( $id, $on_page, true )
            ? '<a href="#cmcp-change-' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</a>'
            : esc_html( $label );
    }

    private static function render_conflict( array $conflict, string $nonce ): void {
        ?>
        <div class="cmcp-inline-confirm cmcp-inline-confirm--warn" role="alert">
            <span class="cmcp-msg"><strong><?php esc_html_e( 'Undo conflict:', 'cowboy-mcp' ); ?></strong>
                #<?php echo esc_html( (string) (int) $conflict['change_id'] ); ?> — <?php echo esc_html( (string) $conflict['message'] ); ?></span>
            <form method="post">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                <input type="hidden" name="change_id" value="<?php echo esc_attr( (string) (int) $conflict['change_id'] ); ?>">
                <input type="hidden" name="force" value="1">
                <button type="submit" name="cowboy_mcp_undo_change" value="1" class="cmcp-btn cmcp-btn--sm cmcp-btn--primary"><?php esc_html_e( 'Force undo anyway', 'cowboy-mcp' ); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * Key-filter options, value => [label, ids]. The journal stores the key of the
     * credential that made each change; an OAuth connection gets a new one with every
     * hourly access token (`oauth_<token id>`), so those are grouped back into one
     * option per connection through the token records the site still holds (refresh
     * records are kept 30 days). Order: live credentials, then wp-admin / MCP actors,
     * then up to TAIL_MAX removed or untraceable keys, each newest first.
     *
     * @return array<string,array{label:string,ids:string[]}>
     */
    private static function key_options( string $selected ): array {
        $journal = Cowboy_MCP_Rollback::journal_keys();
        if ( ! $journal ) {
            return [];
        }
        $live_keys = [];
        foreach ( Cowboy_MCP_Auth::list_keys() as $k ) {
            $live_keys[ (string) $k['id'] ] = (string) $k['prefix'];
        }
        $has_oauth = class_exists( 'Cowboy_MCP_OAuth' );
        $clients   = $has_oauth ? get_option( Cowboy_MCP_OAuth::CLIENTS_OPTION, [] ) : [];
        $tokens    = $has_oauth ? Cowboy_MCP_OAuth::access_token_clients() : [];

        $groups = [];
        foreach ( $journal as $kid => $info ) { // newest first
            $kid = (string) $kid;
            $cid = str_starts_with( $kid, 'oauth_' ) ? ( $tokens[ substr( $kid, 6 ) ] ?? null ) : null;
            if ( null !== $cid ) {
                $live  = isset( $clients[ $cid ] );
                $value = 'conn:' . substr( hash( 'sha256', $cid ), 0, 12 );
                $label = $live ? (string) ( $clients[ $cid ]['client_name'] ?? $info['label'] ) : $info['label'];
                $tier  = $live ? 0 : 2;
                $gone  = ! $live;
                $hint  = ( $live && ! empty( $clients[ $cid ]['created'] ) ) ? wp_date( 'M j, Y', (int) $clients[ $cid ]['created'] ) : '';
            } else {
                $value = $kid;
                $label = $info['label'];
                $tier  = ( isset( $live_keys[ $kid ] ) ? 0 : ( in_array( $kid, self::ACTORS, true ) ? 1 : 2 ) );
                // Only an API-key id we no longer have is known to be revoked; an OAuth token
                // key older than its refresh records cannot be traced, so it is not labelled.
                $gone  = 2 === $tier && 1 === preg_match( '/^[0-9a-f]{12}$/', $kid );
                $hint  = $live_keys[ $kid ] ?? ( str_starts_with( $kid, 'oauth_' ) ? '#' . substr( $kid, 6, 6 ) : substr( $kid, 0, 8 ) );
            }
            if ( ! isset( $groups[ $value ] ) ) {
                $groups[ $value ] = [ 'label' => $label, 'ids' => [], 'last' => (int) $info['last'], 'tier' => $tier, 'gone' => $gone, 'hint' => $hint ];
            }
            $groups[ $value ]['ids'][] = $kid;
        }

        uasort( $groups, static fn( $a, $b ) => [ $a['tier'], $b['last'] ] <=> [ $b['tier'], $a['last'] ] );
        $tail = 0;
        foreach ( $groups as $value => $g ) {
            // (string): an all-digit key id became an int array key.
            if ( 2 === $g['tier'] && ++$tail > self::TAIL_MAX && (string) $value !== $selected ) {
                unset( $groups[ $value ] );
            }
        }

        // Tell same-named entries apart (a regenerated "Claude Code" key, two ChatGPT apps).
        $counts = array_count_values( array_column( $groups, 'label' ) );
        $out    = [];
        foreach ( $groups as $value => $g ) {
            $label = ( $counts[ $g['label'] ] > 1 && '' !== $g['hint'] ) ? $g['label'] . ' · ' . $g['hint'] : $g['label'];
            if ( $g['gone'] ) {
                /* translators: %s: API key or app name */
                $label = sprintf( __( '%s (revoked)', 'cowboy-mcp' ), $label );
            }
            $out[ (string) $value ] = [ 'label' => $label, 'ids' => $g['ids'] ];
        }
        return $out;
    }

    private static function render_toolbar( array $filters, int $total, int $page, array $keys ): void {
        $keep  = [ 'tab' => 'activity', 'jsearch' => $filters['search'], 'jstatus' => $filters['status'], 'jkey' => $filters['key_id'] ];
        $pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $chips = [
            ''             => __( 'All', 'cowboy-mcp' ),
            'active'       => __( 'Active', 'cowboy-mcp' ),
            'undone'       => __( 'Undone', 'cowboy-mcp' ),
            'not_undoable' => __( 'Not undoable', 'cowboy-mcp' ),
        ];
        ?>
        <form method="get" class="cmcp-toolbar" role="search">
            <input type="hidden" name="page" value="<?php echo esc_attr( Cowboy_MCP_Admin::SLUG ); ?>">
            <input type="hidden" name="tab" value="activity">
            <input type="hidden" name="jstatus" value="<?php echo esc_attr( $filters['status'] ); ?>">
            <label class="cmcp-search">
                <span class="screen-reader-text"><?php esc_html_e( 'Search tool or object', 'cowboy-mcp' ); ?></span>
                <?php echo Cowboy_MCP_Admin::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal. ?>
                <input type="search" name="jsearch" class="cmcp-input" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search tool or object', 'cowboy-mcp' ); ?>">
            </label>
            <nav class="cmcp-chips" aria-label="<?php esc_attr_e( 'Status', 'cowboy-mcp' ); ?>">
                <?php foreach ( $chips as $value => $label ) : ?>
                    <a class="cmcp-chip" href="<?php echo esc_url( Cowboy_MCP_Admin::url( array_merge( $keep, [ 'jstatus' => $value ] ) ) ); ?>"<?php echo $value === $filters['status'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ( $keys ) : ?>
                <label>
                    <span class="screen-reader-text"><?php esc_html_e( 'Key', 'cowboy-mcp' ); ?></span>
                    <select name="jkey" class="cmcp-chip" data-cmcp-autosubmit>
                        <option value=""><?php esc_html_e( 'All keys', 'cowboy-mcp' ); ?></option>
                        <?php foreach ( $keys as $kid => $opt ) : ?>
                            <option value="<?php echo esc_attr( (string) $kid ); ?>" <?php selected( $filters['key_id'], (string) $kid ); ?>><?php echo esc_html( $opt['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <button type="submit" class="cmcp-btn cmcp-btn--sm cmcp-nojs"><?php esc_html_e( 'Filter', 'cowboy-mcp' ); ?></button>
            <div class="cmcp-right">
                <span><?php
                    /* translators: %s: number of journal entries */
                    printf( esc_html( _n( '%s change', '%s changes', $total, 'cowboy-mcp' ) ), esc_html( number_format_i18n( $total ) ) );
                ?></span>
                <?php Cowboy_MCP_Admin::render_pager( $page, $pages, static fn( int $n ) => Cowboy_MCP_Admin::url( array_merge( $keep, [ 'activity_page' => $n ] ) ) ); ?>
            </div>
        </form>
        <?php
    }

    private static function render_checkpoints( string $nonce ): void {
        $cps = Cowboy_MCP_Checkpoint::list_all();
        $max = (int) ( get_option( 'cowboy_mcp_settings', [] )['checkpoint_max'] ?? 5 );
        ?>
        <section class="cmcp-card">
            <div class="cmcp-cp">
                <div>
                    <h2><?php esc_html_e( 'Database checkpoints', 'cowboy-mcp' ); ?>
                        <span class="cmcp-sub"><?php
                            /* translators: 1: number of checkpoints stored, 2: maximum kept */
                            printf( esc_html__( '%1$d of %2$d kept', 'cowboy-mcp' ), count( $cps ), (int) $max );
                        ?></span></h2>
                    <p><?php echo $cps ? esc_html__( 'Restoring a checkpoint rewrites every site table. A pre-restore safety checkpoint is taken first.', 'cowboy-mcp' ) : esc_html__( 'No checkpoints yet. One is taken automatically before mutating WP-CLI commands and plugin & theme updates.', 'cowboy-mcp' ); ?></p>
                </div>
                <form method="post" class="cmcp-formrow cmcp-cp-form">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <label class="screen-reader-text" for="cmcp-cp-label"><?php esc_html_e( 'Label (optional)', 'cowboy-mcp' ); ?></label>
                    <input type="text" id="cmcp-cp-label" name="checkpoint_label" class="cmcp-input" placeholder="<?php esc_attr_e( 'Label (optional)', 'cowboy-mcp' ); ?>">
                    <button type="submit" name="cowboy_mcp_create_checkpoint" value="1" class="cmcp-btn"><?php esc_html_e( 'Create checkpoint now', 'cowboy-mcp' ); ?></button>
                </form>
            </div>
            <?php if ( $cps ) : ?>
                <div class="cmcp-table-wrap">
                    <table class="cmcp-t">
                        <thead><tr>
                            <th scope="col"><?php esc_html_e( 'ID', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Created', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Label', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Trigger', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Size', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'cowboy-mcp' ); ?></span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $cps as $cp ) : ?>
                            <tr>
                                <td class="cmcp-mono">#<?php echo (int) $cp['id']; ?></td>
                                <td class="cmcp-mono"><?php echo esc_html( $cp['created'] ); ?></td>
                                <td><?php echo esc_html( $cp['label'] ); ?></td>
                                <td><span class="cmcp-bdg cmcp-bdg--outline"><?php echo esc_html( $cp['trigger_type'] ); ?></span></td>
                                <td><?php echo esc_html( size_format( (int) $cp['size_bytes'] ) ); ?></td>
                                <td class="cmcp-acts-cell"><div class="cmcp-acts">
                                    <form method="post" data-cmcp-confirm="<?php
                                        /* translators: 1: checkpoint ID number, 2: checkpoint creation date/time */
                                        echo esc_attr( sprintf( __( 'Restore the database to checkpoint #%1$s (%2$s)? EVERYTHING changed since then — including changes made outside MCP — will be lost. A pre-restore safety checkpoint is taken first.', 'cowboy-mcp' ), $cp['id'], $cp['created'] ) );
                                    ?>" data-cmcp-confirm-2="<?php esc_attr_e( 'Really sure? This rewrites every site table.', 'cowboy-mcp' ); ?>" data-cmcp-confirm-tone="danger" data-cmcp-confirm-label="<?php esc_attr_e( 'Restore', 'cowboy-mcp' ); ?>">
                                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                                        <input type="hidden" name="checkpoint_id" value="<?php echo (int) $cp['id']; ?>">
                                        <button type="submit" name="cowboy_mcp_restore_checkpoint" value="1" class="cmcp-btn cmcp-btn--sm"><?php esc_html_e( 'Restore', 'cowboy-mcp' ); ?></button>
                                    </form>
                                    <form method="post" data-cmcp-confirm="<?php esc_attr_e( 'Delete this checkpoint?', 'cowboy-mcp' ); ?>" data-cmcp-confirm-tone="danger" data-cmcp-confirm-label="<?php esc_attr_e( 'Delete', 'cowboy-mcp' ); ?>">
                                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                                        <input type="hidden" name="checkpoint_id" value="<?php echo (int) $cp['id']; ?>">
                                        <button type="submit" name="cowboy_mcp_delete_checkpoint" value="1" class="cmcp-btn cmcp-btn--sm cmcp-btn--danger"><?php esc_html_e( 'Delete', 'cowboy-mcp' ); ?></button>
                                    </form>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }
}
