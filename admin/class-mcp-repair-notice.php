<?php
/**
 * Admin notice for values damaged by pre-1.7.1 checkpoint restores: found → Repair
 * now (admin click only) → repairing → done. Dashboard, Plugins and Cowboy MCP screens.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Repair_Notice {

	public static function init(): void {
		add_action( 'admin_init', [ 'Cowboy_MCP_Placeholder_Repair', 'ensure_scheduled' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render' ] );
		add_action( 'admin_post_cowboy_mcp_placeholder_repair', [ __CLASS__, 'handle_repair' ] );
		add_action( 'wp_ajax_cowboy_mcp_dismiss_repair', [ __CLASS__, 'ajax_dismiss' ] );
	}

	public static function is_due(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins', 'settings_page_cowboy-mcp' ], true ) ) {
			return false;
		}
		$s = Cowboy_MCP_Placeholder_Repair::state();
		return match ( $s['status'] ?? '' ) {
			'found'     => empty( $s['dismissed'] ),
			'repairing' => true,
			'done'      => ! empty( $s['repaired'] ) && empty( $s['done_dismissed'] ),
			default     => false,
		};
	}

	public static function render(): void {
		if ( ! self::is_due() ) {
			return;
		}
		$err_key = 'cowboy_mcp_repair_error_' . get_current_user_id();
		$err     = get_transient( $err_key );
		if ( is_string( $err ) && '' !== $err ) {
			delete_transient( $err_key );
			echo '<div class="notice notice-error mcp-repair-notice"><p>' . esc_html( $err ) . '</p></div>';
		}
		$s     = Cowboy_MCP_Placeholder_Repair::state();
		$count = (int) ( $s['count'] ?? 0 );
		if ( 'found' === $s['status'] ) :
			?>
			<div class="notice notice-warning is-dismissible mcp-repair-notice" data-cmcp-dismiss="found">
				<p><strong><?php
					/* translators: %d: number of damaged values */
					echo esc_html( sprintf( _n( 'Cowboy MCP found %d value damaged by an earlier checkpoint restore.', 'Cowboy MCP found %d values damaged by an earlier checkpoint restore.', $count, 'cowboy-mcp' ), $count ) );
				?></strong></p>
				<p><?php echo wp_kses( __( 'An older version\'s restore saved <code>%</code> signs incorrectly, which can break permalinks and page layouts.', 'cowboy-mcp' ), [ 'code' => [] ] ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcp-repair-actions">
					<input type="hidden" name="action" value="cowboy_mcp_placeholder_repair">
					<?php wp_nonce_field( 'cowboy_mcp_placeholder_repair' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Repair now', 'cowboy-mcp' ); ?></button>
					<details class="mcp-repair-details"><summary><?php esc_html_e( 'See what will change', 'cowboy-mcp' ); ?></summary>
						<ul><?php foreach ( (array) ( $s['sample'] ?? [] ) as $row ) : ?><li><?php echo esc_html( $row['label'] ); ?></li><?php endforeach; ?>
						<?php if ( $count > count( (array) ( $s['sample'] ?? [] ) ) ) : ?><li><?php
							/* translators: %d: number of further damaged values not listed */
							echo esc_html( sprintf( __( 'and %d more', 'cowboy-mcp' ), $count - count( (array) $s['sample'] ) ) );
						?></li><?php endif; ?></ul>
					</details>
				</form>
				<p class="description"><?php esc_html_e( 'Cowboy MCP takes a checkpoint first, and you can undo the repair from the Activity tab.', 'cowboy-mcp' ); ?></p>
			</div>
			<?php
		elseif ( 'repairing' === $s['status'] ) :
			?>
			<div class="notice notice-info mcp-repair-notice"><p><strong><?php
				/* translators: %d: number of damaged values */
				echo esc_html( sprintf( _n( 'Cowboy MCP is repairing %d damaged value…', 'Cowboy MCP is repairing %d damaged values…', $count, 'cowboy-mcp' ), $count ) );
			?></strong> <?php esc_html_e( 'You can leave this page; it finishes in the background.', 'cowboy-mcp' ); ?></p></div>
			<?php
		else :
			$n = (int) $s['repaired'];
			?>
			<div class="notice notice-success is-dismissible mcp-repair-notice" data-cmcp-dismiss="done"><p><strong><?php
				/* translators: %d: number of repaired values */
				echo esc_html( sprintf( _n( 'Cowboy MCP repaired %d value.', 'Cowboy MCP repaired %d values.', $n, 'cowboy-mcp' ), $n ) );
			?></strong> <?php
				echo wp_kses(
					/* translators: %s: URL of the Activity tab */
					sprintf( __( 'Permalinks and layouts should look right again. Something off? <a href="%s">Undo the repair</a> on the Activity tab.', 'cowboy-mcp' ), esc_url( Cowboy_MCP_Admin::url( [ 'tab' => 'activity' ] ) ) ),
					[ 'a' => [ 'href' => [] ] ]
				);
			?></p></div>
			<?php
		endif;
	}

	public static function handle_repair(): void {
		check_admin_referer( 'cowboy_mcp_placeholder_repair' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'cowboy-mcp' ) );
		}
		$r = Cowboy_MCP_Placeholder_Repair::start(); // state drives the notice shown after the redirect
		if ( is_wp_error( $r ) && 'repair_failed' === $r->get_error_code() ) {
			set_transient( 'cowboy_mcp_repair_error_' . get_current_user_id(), $r->get_error_message(), 60 );
		}
		wp_safe_redirect( wp_get_referer() ?: Cowboy_MCP_Admin::url() );
		exit;
	}

	public static function ajax_dismiss(): void {
		check_ajax_referer( 'cowboy_mcp_dismiss_repair' );
		if ( current_user_can( 'manage_options' ) ) {
			$s = Cowboy_MCP_Placeholder_Repair::state();
			$which = sanitize_key( wp_unslash( $_POST['which'] ?? '' ) );
			if ( 'found' === $which ) {
				$s['dismissed'] = true;
			} elseif ( 'done' === $which ) {
				$s['done_dismissed'] = true;
			}
			update_option( Cowboy_MCP_Placeholder_Repair::OPTION, $s, false );
		}
		wp_die();
	}
}
