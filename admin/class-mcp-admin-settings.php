<?php
/**
 * Cowboy MCP – Admin: Settings page.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Admin_Settings {

    public static function render_tab( array $s ): void {
        $on   = static fn( string $k, bool $default ) => array_key_exists( $k, $s ) ? (bool) $s[ $k ] : $default;
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $port = wp_parse_url( home_url(), PHP_URL_PORT );
        if ( $port ) {
            $host .= ':' . $port;
        }
        ?>
        <p class="cmcp-lede"><?php esc_html_e( 'How the server behaves, how much it remembers, and how far an agent may reach.', 'cowboy-mcp' ); ?></p>
        <form method="post" class="cmcp-settings cmcp-settings-form">
            <?php wp_nonce_field( 'cowboy_mcp_save_settings' ); ?>

            <section class="cmcp-sect">
                <h2><?php esc_html_e( 'General', 'cowboy-mcp' ); ?></h2>
                <p><?php esc_html_e( 'Server switch, safety gate and request limits.', 'cowboy-mcp' ); ?></p>
                <div class="cmcp-card">
                    <?php
                    self::switch_row( 'cmcp-enabled', 'cowboy_mcp_enabled', $on( 'enabled', true ), __( 'MCP Server', 'cowboy-mcp' ), esc_html__( 'When off, every MCP request is rejected.', 'cowboy-mcp' ) );
                    self::switch_row( 'cmcp-safe-mode', 'cowboy_mcp_safe_mode', $on( 'safe_mode', true ), __( 'Safe Mode', 'cowboy-mcp' ), __( 'Tools marked as destructive (delete, drop, WP-CLI write commands, etc.) require <code>confirm: true</code> in the request.', 'cowboy-mcp' ), __( 'Recommended', 'cowboy-mcp' ) );
                    self::number_row( 'cmcp-rate-limit', 'cowboy_mcp_rate_limit', (int) ( $s['rate_limit'] ?? 120 ), 10, 1000, __( 'Rate Limit', 'cowboy-mcp' ), __( 'requests per minute, per key', 'cowboy-mcp' ) );
                    self::switch_row( 'cmcp-log-requests', 'cowboy_mcp_log_requests', $on( 'log_requests', false ), __( 'Request Logging', 'cowboy-mcp' ), __( 'Log all tool calls to <code>debug.log</code>', 'cowboy-mcp' ) . ' ' . esc_html__( 'The audit log in Logs is always on.', 'cowboy-mcp' ) );
                    ?>
                </div>
            </section>

            <section class="cmcp-sect">
                <h2><?php esc_html_e( 'Desktop Connector', 'cowboy-mcp' ); ?></h2>
                <div class="cmcp-card">
                    <?php
                    self::switch_row( 'cmcp-oauth', 'cowboy_mcp_oauth_enabled', $on( 'oauth_enabled', false ), __( 'Allow connecting via Claude Desktop / web (OAuth)', 'cowboy-mcp' ), __( 'Turns on the one-click browser sign-in used by the &#8220;Claude Desktop&#8221; connection path. This exposes public OAuth discovery, registration, and token endpoints — no tokens are issued until an administrator approves in the browser. Leave off if you only connect via the terminal.', 'cowboy-mcp' ) );
                    $hosts_desc = sprintf(
                        /* translators: %s: comma-separated list of host names */
                        esc_html__( 'Anyone can start a connection request, so the site only sends approval back to %s, and to localhost for command-line tools. Turning this off accepts any https address.', 'cowboy-mcp' ),
                        esc_html( implode( ', ', Cowboy_MCP_OAuth::DEFAULT_REDIRECT_HOSTS ) )
                    );
                    self::switch_row( 'cmcp-allowlist', 'cowboy_mcp_oauth_redirect_allowlist', $on( 'oauth_redirect_allowlist', true ), __( 'Only allow connections from known AI apps (recommended)', 'cowboy-mcp' ), $hosts_desc, '', static function () {
                        ?>
                        <div class="cmcp-nested">
                            <label for="cowboy_mcp_oauth_extra_redirect_hosts"><?php esc_html_e( 'Additional allowed hosts', 'cowboy-mcp' ); ?></label>
                            <textarea id="cowboy_mcp_oauth_extra_redirect_hosts" name="cowboy_mcp_oauth_extra_redirect_hosts" rows="2" class="cmcp-input" placeholder="n8n.example.com"><?php echo esc_textarea( implode( "\n", Cowboy_MCP_OAuth::extra_redirect_hosts() ) ); ?></textarea>
                            <p class="cmcp-desc"><?php esc_html_e( 'One host name per line, for tools such as n8n or a self-hosted client. Subdomains are included.', 'cowboy-mcp' ); ?></p>
                        </div>
                        <?php
                    } );
                    ?>
                </div>
            </section>

            <section class="cmcp-sect">
                <h2><?php esc_html_e( 'Undo & Checkpoints', 'cowboy-mcp' ); ?></h2>
                <p><?php esc_html_e( 'What gets remembered so any change can be rolled back.', 'cowboy-mcp' ); ?></p>
                <div class="cmcp-card">
                    <?php
                    self::switch_row( 'cmcp-undo', 'cowboy_mcp_undo_enabled', $on( 'undo_enabled', true ), __( 'Undo Journal', 'cowboy-mcp' ), esc_html__( 'Lets changes made through MCP be undone from the Activity tab. Disabling stops new entries from being captured; existing history is kept until it expires.', 'cowboy-mcp' ) );
                    self::number_row( 'cmcp-retention', 'cowboy_mcp_undo_retention_days', (int) ( $s['undo_retention_days'] ?? 7 ), 1, 0, __( 'Undo Retention', 'cowboy-mcp' ), __( 'days to keep undo history', 'cowboy-mcp' ) );
                    self::number_row( 'cmcp-cp-max', 'cowboy_mcp_checkpoint_max', (int) ( $s['checkpoint_max'] ?? 5 ), 1, 0, __( 'Checkpoint Limit', 'cowboy-mcp' ), __( 'checkpoints to keep', 'cowboy-mcp' ) );
                    self::switch_row( 'cmcp-auto-cli', 'cowboy_mcp_auto_checkpoint_wp_cli', $on( 'auto_checkpoint_wp_cli', true ), __( 'Auto-checkpoint before mutating WP-CLI commands', 'cowboy-mcp' ), esc_html__( 'Takes a full-database checkpoint before running a WP-CLI command that is not read-only, so it can be rolled back even if the command itself cannot be undone.', 'cowboy-mcp' ) );
                    self::switch_row( 'cmcp-auto-upd', 'cowboy_mcp_auto_checkpoint_updates', $on( 'auto_checkpoint_updates', true ), __( 'Auto-checkpoint before plugin & theme updates', 'cowboy-mcp' ), esc_html__( 'Takes a full-database checkpoint before plugin or theme updates, so database migrations run by an update can be rolled back. The old files are separately backed up per update for undo.', 'cowboy-mcp' ) );
                    ?>
                </div>
            </section>

            <section class="cmcp-sect">
                <h2><?php esc_html_e( 'WordPress Abilities API', 'cowboy-mcp' ); ?></h2>
                <?php if ( function_exists( 'wp_register_ability' ) ) : ?>
                    <p><?php esc_html_e( "Share tools with WordPress' own Abilities API, in both directions.", 'cowboy-mcp' ); ?></p>
                    <div class="cmcp-card">
                        <?php
                        self::switch_row( 'cmcp-ab-expose', 'cowboy_mcp_abilities_expose', $on( 'abilities_expose', true ), __( 'Expose tools as WordPress Abilities', 'cowboy-mcp' ), esc_html__( "Registers every allowed tool as a cowboy-mcp/* ability so WP-CLI, the REST API, MCP adapters and AI agents can call it — with Cowboy's safe mode, audit log and undo.", 'cowboy-mcp' ) );
                        self::switch_row( 'cmcp-ab-consume', 'cowboy_mcp_abilities_consume', $on( 'abilities_consume', true ), __( 'Use abilities from other plugins', 'cowboy-mcp' ), esc_html__( 'Shows abilities registered by other plugins as tools in the abilities category. They run their own permission checks and are not undoable.', 'cowboy-mcp' ) );
                        ?>
                    </div>
                <?php else : ?>
                    <p><?php esc_html_e( 'WordPress Abilities API requires WordPress 6.9 or newer.', 'cowboy-mcp' ); ?></p>
                <?php endif; ?>
            </section>

            <section class="cmcp-danger-zone" id="cmcp-power" aria-labelledby="cmcp-power-h">
                <div class="cmcp-dz-h">
                    <?php echo Cowboy_MCP_Admin::icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal. ?>
                    <h2 id="cmcp-power-h"><?php esc_html_e( 'Power Mode', 'cowboy-mcp' ); ?></h2>
                    <span class="cmcp-sub"><?php esc_html_e( 'advanced · off by default', 'cowboy-mcp' ); ?></span>
                </div>
                <div class="cmcp-srow">
                    <div>
                        <h3><label for="cmcp-power-mode"><?php esc_html_e( 'Lift safety restrictions for advanced operations', 'cowboy-mcp' ); ?></label></h3>
                        <p class="cmcp-desc cmcp-desc--danger"><?php
                            echo wp_kses(
                                __( '<strong>Danger:</strong> allows <code>eval</code>/<code>shell</code>, dangerous SQL, writing files anywhere, and requests to internal addresses. This grants effective <strong>remote code execution</strong> to anyone holding an API key. Only enable on a trusted, locked-down site you control. Your MCP API keys and the plugin&#8217;s own settings stay protected.', 'cowboy-mcp' ),
                                [ 'strong' => [], 'code' => [] ]
                            );
                        ?></p>
                        <div class="cmcp-lists">
                            <div>
                                <p class="cmcp-lh cmcp-lh--danger"><?php esc_html_e( 'Turning it on allows', 'cowboy-mcp' ); ?></p>
                                <ul class="cmcp-lifts">
                                    <li><?php echo wp_kses( __( '<code>eval</code> / <code>shell</code> via WP-CLI', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></li>
                                    <li><?php esc_html_e( 'Dangerous SQL', 'cowboy-mcp' ); ?></li>
                                    <li><?php esc_html_e( 'Writing files anywhere', 'cowboy-mcp' ); ?></li>
                                    <li><?php esc_html_e( 'Requests to internal addresses', 'cowboy-mcp' ); ?></li>
                                </ul>
                            </div>
                            <div>
                                <p class="cmcp-lh cmcp-lh--keep"><?php esc_html_e( 'Always stays protected', 'cowboy-mcp' ); ?></p>
                                <ul class="cmcp-lifts cmcp-lifts--keep">
                                    <li><?php esc_html_e( 'Your MCP API keys', 'cowboy-mcp' ); ?></li>
                                    <li><?php esc_html_e( "The plugin's own settings", 'cowboy-mcp' ); ?></li>
                                    <li><?php esc_html_e( 'Self-delete and last-admin guards', 'cowboy-mcp' ); ?></li>
                                    <li><?php esc_html_e( 'Secret redaction in results', 'cowboy-mcp' ); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="cmcp-ctl">
                        <input type="checkbox" role="switch" class="cmcp-switch" id="cmcp-power-mode" name="cowboy_mcp_power_mode" value="1" <?php checked( $on( 'power_mode', false ) ); ?>>
                        <span class="cmcp-state" data-on="<?php esc_attr_e( 'On', 'cowboy-mcp' ); ?>" data-off="<?php esc_attr_e( 'Off', 'cowboy-mcp' ); ?>" aria-hidden="true"></span>
                    </div>
                    <div class="cmcp-nested" data-cmcp-power-confirm data-host="<?php echo esc_attr( $host ); ?>" hidden>
                        <label for="cmcp-power-host"><?php
                            /* translators: %s: this site's host name, e.g. example.com */
                            echo wp_kses( sprintf( __( 'Type %s to turn on Power mode.', 'cowboy-mcp' ), '<code>' . esc_html( $host ) . '</code>' ), [ 'code' => [] ] );
                        ?></label>
                        <div class="cmcp-formrow">
                            <input type="text" id="cmcp-power-host" class="cmcp-input" autocomplete="off" spellcheck="false">
                            <button type="button" class="cmcp-btn cmcp-btn--danger-solid" data-cmcp-power-go disabled><?php esc_html_e( 'Turn on', 'cowboy-mcp' ); ?></button>
                            <button type="button" class="cmcp-btn" data-cmcp-power-cancel><?php esc_html_e( 'Cancel', 'cowboy-mcp' ); ?></button>
                        </div>
                        <p class="cmcp-desc"><?php esc_html_e( 'It takes effect when you save.', 'cowboy-mcp' ); ?></p>
                    </div>
                </div>
            </section>

            <div class="cmcp-savebar" data-clean="<?php esc_attr_e( 'No unsaved changes', 'cowboy-mcp' ); ?>" data-dirty="<?php esc_attr_e( 'You have unsaved changes', 'cowboy-mcp' ); ?>">
                <span class="cmcp-savebar-msg" aria-live="polite"><?php esc_html_e( 'No unsaved changes', 'cowboy-mcp' ); ?></span>
                <button type="button" class="cmcp-btn cmcp-btn--ghost" data-cmcp-discard hidden><?php esc_html_e( 'Discard', 'cowboy-mcp' ); ?></button>
                <button type="submit" name="cowboy_mcp_save_settings" value="1" class="cmcp-btn cmcp-btn--primary"><?php esc_html_e( 'Save Settings', 'cowboy-mcp' ); ?></button>
            </div>
        </form>
        <?php
    }

    /**
     * One labelled switch row. $desc_html may contain <code>/<strong> (wp_kses'd here);
     * pass already-escaped text when it has neither.
     */
    private static function switch_row( string $id, string $name, bool $checked, string $title, string $desc_html, string $badge = '', ?callable $extra = null ): void {
        ?>
        <div class="cmcp-srow">
            <div>
                <h3><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></label><?php if ( '' !== $badge ) : ?> <span class="cmcp-bdg cmcp-bdg--ok"><?php echo esc_html( $badge ); ?></span><?php endif; ?></h3>
                <p class="cmcp-desc"><?php echo wp_kses( $desc_html, [ 'code' => [], 'strong' => [] ] ); ?></p>
            </div>
            <div class="cmcp-ctl">
                <input type="checkbox" role="switch" class="cmcp-switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
                <span class="cmcp-state" data-on="<?php esc_attr_e( 'On', 'cowboy-mcp' ); ?>" data-off="<?php esc_attr_e( 'Off', 'cowboy-mcp' ); ?>" aria-hidden="true"></span>
            </div>
            <?php
            if ( $extra ) {
                $extra();
            }
            ?>
        </div>
        <?php
    }

    /** One labelled number row ($max 0 = no maximum). */
    private static function number_row( string $id, string $name, int $value, int $min, int $max, string $title, string $unit ): void {
        ?>
        <div class="cmcp-srow cmcp-srow--num">
            <div><h3><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></label></h3></div>
            <div class="cmcp-ctl">
                <input type="number" class="cmcp-input cmcp-num-in" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" min="<?php echo esc_attr( (string) $min ); ?>"<?php echo $max ? ' max="' . esc_attr( (string) $max ) . '"' : ''; ?>>
                <span class="cmcp-unit"><?php echo esc_html( $unit ); ?></span>
            </div>
        </div>
        <?php
    }
}
