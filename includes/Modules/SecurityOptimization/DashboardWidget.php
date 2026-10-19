<?php
/**
 * WordPress dashboard widget summarising active IP bans.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered, exactly the BotThrottle\DashboardWidget pattern
 * (PLAN.md 20.10): static register()/add()/render(), no React - this
 * widget lives on the native wp-admin dashboard, not inside the SPA.
 */
final class DashboardWidget {

	/**
	 * Register the widget on the dashboard.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ) );
	}

	/**
	 * Add the widget (admins only).
	 */
	public static function add(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'uxstudio_security_optimization_widget',
			__( 'Security - active IP bans', 'ux-studio' ),
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the widget body.
	 */
	public static function render(): void {
		// active_only = is_active AND not yet expired (NULL expires_at = permanent).
		$result = ( new IpBanStore() )->get_bans(
			array(
				'active_only' => true,
				'per_page'    => 10,
			)
		);
		$bans   = $result['items'];
		$total  = (int) $result['total'];
		$screen = admin_url( 'admin.php?page=ux-studio#/module?id=security-optimization' );
		?>
		<div class="uxs-so-widget" style="font-size:13px;">
			<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid <?php echo $total > 0 ? '#d63638' : '#16a34a'; ?>;margin-bottom:12px;border-radius:0 4px 4px 0;">
				<span><?php esc_html_e( 'Active bans:', 'ux-studio' ); ?></span>
				<span style="margin-left:auto;font-size:18px;font-weight:700;"><?php echo (int) $total; ?></span>
			</div>

			<?php if ( empty( $bans ) ) : ?>
				<p style="color:#646970;font-style:italic;"><?php esc_html_e( 'No active bans.', 'ux-studio' ); ?></p>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;font-size:12px;">
					<thead>
						<tr>
							<th style="text-align:left;color:#646970;"><?php esc_html_e( 'Value', 'ux-studio' ); ?></th>
							<th style="text-align:left;color:#646970;"><?php esc_html_e( 'Type', 'ux-studio' ); ?></th>
							<th style="text-align:right;color:#646970;"><?php esc_html_e( 'Expires', 'ux-studio' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $bans as $ban ) : ?>
							<tr>
								<td style="padding:3px 0;">
									<strong><?php echo esc_html( (string) $ban['value'] ); ?></strong>
									<?php if ( ! empty( $ban['label'] ) ) : ?>
										<span style="color:#646970;"> - <?php echo esc_html( (string) $ban['label'] ); ?></span>
									<?php endif; ?>
								</td>
								<td style="color:#646970;"><?php echo esc_html( (string) $ban['type'] ); ?></td>
								<td style="text-align:right;color:#646970;white-space:nowrap;">
									<?php
									// expires_at is stored in UTC (IpBanStore::normalize_date),
									// so compare against time(), not the site-local clock.
									$expires = empty( $ban['expires_at'] ) ? 0 : (int) strtotime( (string) $ban['expires_at'] . ' UTC' );
									if ( ! $expires ) {
										esc_html_e( 'Never', 'ux-studio' );
									} elseif ( $expires > time() ) {
										echo esc_html(
											sprintf(
												/* translators: %s: human-readable time difference, e.g. "2 hours". */
												__( 'in %s', 'ux-studio' ),
												human_time_diff( time(), $expires )
											)
										);
									} else {
										esc_html_e( 'Expired', 'ux-studio' );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:10px;">
				<a href="<?php echo esc_url( $screen ); ?>" class="button button-small"><?php esc_html_e( 'Open Security - Access Control', 'ux-studio' ); ?></a>
			</p>
		</div>
		<?php
	}
}
