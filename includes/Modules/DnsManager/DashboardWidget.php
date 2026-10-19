<?php
/**
 * Dashboard widget: last DNS changes (PLAN 20.10 pattern).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DnsManager;

defined( 'ABSPATH' ) || exit;

final class DashboardWidget {

	/**
	 * Register the widget on the dashboard.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ) );
	}

	/**
	 * Add the widget (users who may view DNS only).
	 */
	public static function add(): void {
		if ( ! current_user_can( 'uxstudio_view_dns' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'uxstudio_dns_manager_widget',
			__( 'DNS - recent changes', 'ux-studio' ),
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the widget body.
	 */
	public static function render(): void {
		$rows   = LocalLog::recent( 3 );
		$screen = admin_url( 'admin.php?page=ux-studio#/module?id=dns-manager' );
		$labels = array(
			'create'       => __( 'Added', 'ux-studio' ),
			'update'       => __( 'Changed', 'ux-studio' ),
			'delete'       => __( 'Deleted', 'ux-studio' ),
			'revert'       => __( 'Reverted', 'ux-studio' ),
			'acknowledged' => __( 'Risks acknowledged', 'ux-studio' ),
		);
		?>
		<div class="uxs-dns-widget" style="font-size:13px;">
			<?php if ( empty( $rows ) ) : ?>
				<p style="color:#646970;font-style:italic;"><?php esc_html_e( 'No DNS changes recorded yet.', 'ux-studio' ); ?></p>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;font-size:12px;">
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td style="padding:4px 0;"><strong><?php echo esc_html( $labels[ $row['action'] ] ?? (string) $row['action'] ); ?></strong>
									<?php echo esc_html( trim( (string) $row['record_type'] . ' ' . (string) $row['record_name'] ) ); ?></td>
								<td style="color:#646970;"><?php echo esc_html( (string) $row['wp_user_login'] ); ?></td>
								<td style="text-align:right;color:#646970;white-space:nowrap;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference, e.g. "2 hours". */
											__( '%s ago', 'ux-studio' ),
											human_time_diff( (int) strtotime( (string) $row['created_at'] . ' UTC' ), time() )
										)
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p style="margin-top:10px;"><a href="<?php echo esc_url( $screen ); ?>" class="button button-small"><?php esc_html_e( 'Open DNS management', 'ux-studio' ); ?></a></p>
		</div>
		<?php
	}
}
