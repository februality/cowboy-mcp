<?php
/**
 * Cowboy MCP – Admin: Logs page (audit log).
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Admin_Logs {

    const EVENTS = [ 'tool_call', 'tool_error', 'tool_exception', 'auth_missing_header', 'auth_invalid_key', 'rate_limit_exceeded' ];

    public static function render_tab(): void {
        if ( ! class_exists( 'Cowboy_MCP_Audit_Log' ) ) {
            echo '<div class="cmcp-callout"><p>' . esc_html__( 'Audit log is not available. Please deactivate and reactivate the plugin.', 'cowboy-mcp' ) . '</p></div>';
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin filters, fully sanitized.
        $f = [
            'event'     => sanitize_text_field( wp_unslash( $_GET['log_event'] ?? '' ) ),
            'tool'      => sanitize_text_field( wp_unslash( $_GET['log_tool'] ?? '' ) ),
            'date_from' => sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ),
            'date_to'   => sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ),
            'per_page'  => max( 10, min( 200, (int) sanitize_text_field( wp_unslash( $_GET['per_page'] ?? '25' ) ) ) ),
            'page'      => max( 1, (int) sanitize_text_field( wp_unslash( $_GET['paged'] ?? '' ) ) ),
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $result   = Cowboy_MCP_Audit_Log::query( $f );
        $entries  = $result['entries'];
        $total    = (int) $result['total'];
        $pages    = (int) ceil( $total / $f['per_page'] );
        $filtered = '' !== $f['event'] || '' !== $f['tool'] || '' !== $f['date_from'] || '' !== $f['date_to'];
        $keep     = [ 'tab' => 'logs', 'log_event' => $f['event'], 'log_tool' => $f['tool'], 'date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'per_page' => $f['per_page'] ];
        ?>
        <div class="cmcp-lede-row">
            <p class="cmcp-lede"><?php esc_html_e( 'Structured log of all MCP tool calls, errors, and auth events. Auto-pruned after 30 days.', 'cowboy-mcp' ); ?></p>
            <form method="post" class="cmcp-right" data-cmcp-confirm="<?php esc_attr_e( 'Clear all audit log entries? This cannot be undone.', 'cowboy-mcp' ); ?>" data-cmcp-confirm-2="<?php esc_attr_e( 'Really sure? Every audit log entry will be deleted.', 'cowboy-mcp' ); ?>" data-cmcp-confirm-tone="danger">
                <span class="cmcp-sub"><?php
                    /* translators: %s: total number of log entries */
                    printf( esc_html__( '%s entries total', 'cowboy-mcp' ), esc_html( number_format_i18n( $total ) ) );
                ?></span>
                <?php wp_nonce_field( 'cowboy_mcp_clear_audit_log' ); ?>
                <button type="submit" name="cowboy_mcp_clear_audit_log" value="1" class="cmcp-btn cmcp-btn--sm cmcp-btn--danger"><?php esc_html_e( 'Clear All Logs', 'cowboy-mcp' ); ?></button>
            </form>
        </div>
        <section class="cmcp-card cmcp-logs">
            <form method="get" class="cmcp-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr( Cowboy_MCP_Admin::SLUG ); ?>">
                <input type="hidden" name="tab" value="logs">
                <label><?php esc_html_e( 'Event', 'cowboy-mcp' ); ?>
                    <select name="log_event" class="cmcp-input">
                        <option value=""><?php esc_html_e( 'All events', 'cowboy-mcp' ); ?></option>
                        <?php foreach ( self::EVENTS as $ev ) : ?>
                            <option value="<?php echo esc_attr( $ev ); ?>" <?php selected( $f['event'], $ev ); ?>><?php echo esc_html( $ev ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?php esc_html_e( 'Tool', 'cowboy-mcp' ); ?>
                    <input type="text" name="log_tool" class="cmcp-input" value="<?php echo esc_attr( $f['tool'] ); ?>" placeholder="<?php echo esc_attr__( 'e.g. wp_list_posts', 'cowboy-mcp' ); ?>">
                </label>
                <label><?php esc_html_e( 'From', 'cowboy-mcp' ); ?>
                    <input type="date" name="date_from" class="cmcp-input" value="<?php echo esc_attr( $f['date_from'] ); ?>">
                </label>
                <label><?php esc_html_e( 'To', 'cowboy-mcp' ); ?>
                    <input type="date" name="date_to" class="cmcp-input" value="<?php echo esc_attr( $f['date_to'] ); ?>">
                </label>
                <label><?php esc_html_e( 'Per page', 'cowboy-mcp' ); ?>
                    <select name="per_page" class="cmcp-input">
                        <?php foreach ( [ 25, 50, 100 ] as $pp ) : ?>
                            <option value="<?php echo esc_attr( (string) $pp ); ?>" <?php selected( $f['per_page'], $pp ); ?>><?php echo esc_html( (string) $pp ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="cmcp-btn"><?php esc_html_e( 'Filter', 'cowboy-mcp' ); ?></button>
                <?php if ( $filtered ) : ?>
                    <a class="cmcp-linkbtn cmcp-reset" href="<?php echo esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'logs' ] ) ); ?>"><?php esc_html_e( 'Reset', 'cowboy-mcp' ); ?></a>
                <?php endif; ?>
                <div class="cmcp-right"><?php Cowboy_MCP_Admin::render_pager( $f['page'], $pages, static fn( int $n ) => Cowboy_MCP_Admin::url( array_merge( $keep, [ 'paged' => $n ] ) ) ); ?></div>
            </form>
            <?php if ( empty( $entries ) ) : ?>
                <p class="cmcp-empty"><?php esc_html_e( 'No log entries found.', 'cowboy-mcp' ); ?></p>
            <?php else : ?>
                <div class="cmcp-table-wrap">
                    <table class="cmcp-t cmcp-log-table">
                        <thead><tr>
                            <th scope="col"><?php esc_html_e( 'Timestamp', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Key', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Event', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Tool', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Status', 'cowboy-mcp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'IP', 'cowboy-mcp' ); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $entries as $i => $row ) :
                            $has_args = ! empty( $row['args'] );
                            $detail   = 'cmcp-log-' . (int) $i;
                            $ev_class = match ( true ) {
                                'tool_exception' === $row['event']                                     => 'cmcp-ev--exc',
                                'tool_error' === $row['event']                                         => 'cmcp-ev--err',
                                str_starts_with( (string) $row['event'], 'auth_' ), 'rate_limit_exceeded' === $row['event'] => 'cmcp-ev--auth',
                                default                                                                => '',
                            };
                            $dot      = [ 'success' => '', 'error' => ' cmcp-dot--warn', 'exception' => ' cmcp-dot--bad' ][ (string) $row['result_status'] ] ?? null;
                            ?>
                            <tr>
                                <td class="cmcp-mono cmcp-nowrap"><?php if ( $has_args ) : ?><button type="button" class="cmcp-caret" aria-expanded="false" aria-controls="<?php echo esc_attr( $detail ); ?>" aria-label="<?php esc_attr_e( 'Show details', 'cowboy-mcp' ); ?>"><?php echo Cowboy_MCP_Admin::icon( 'caret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal. ?></button><?php else : ?><span class="cmcp-cspace"></span><?php endif; ?><?php echo esc_html( (string) $row['timestamp'] ); ?></td>
                                <td class="cmcp-mono"><?php echo esc_html( (string) ( $row['key_label'] ?: $row['key_id'] ?: '—' ) ); ?></td>
                                <td><span class="cmcp-ev <?php echo esc_attr( $ev_class ); ?>"><?php echo esc_html( (string) $row['event'] ); ?></span></td>
                                <td class="cmcp-mono"><?php echo esc_html( (string) ( $row['tool'] ?: '—' ) ); ?></td>
                                <td><?php if ( null === $dot ) : ?>—<?php else : ?><span class="cmcp-seen"><span class="cmcp-dot<?php echo esc_attr( $dot ); ?>" aria-hidden="true"></span><?php echo esc_html( (string) $row['result_status'] ); ?></span><?php endif; ?></td>
                                <td class="cmcp-mono"><?php echo esc_html( (string) ( $row['ip'] ?: '—' ) ); ?></td>
                            </tr>
                            <?php if ( $has_args ) : ?>
                                <tr class="cmcp-detail" id="<?php echo esc_attr( $detail ); ?>" hidden>
                                    <td colspan="6"><pre class="cmcp-term"><?php echo esc_html( is_array( $row['args'] ) ? (string) wp_json_encode( $row['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : (string) $row['args'] ); ?></pre></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }
}
