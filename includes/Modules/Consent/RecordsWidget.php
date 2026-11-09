<?php
/**
 * WordPress dashboard widget: how visitors answer the cookie banner.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Same shape as BotThrottle\DashboardWidget (PLAN.md section 20.10): a static class, server-rendered, with a link into
 * the module page. Shows the share of accept all, reject all, custom choices and withdrawals in the last 30 days.
 */
final class RecordsWidget {

	/** Register the widget on the dashboard. */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ) );
	}

	/** Add the widget (administrators only, and only while records are being kept). */
	public static function add(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Records::enabled() ) {
			return;
		}
		wp_add_dashboard_widget(
			'uxstudio_consent_widget',
			__( 'Cookie banner - answers', 'ux-studio' ),
			array( self::class, 'render' )
		);
	}

	/** Render the widget body. */
	public static function render(): void {
		$s      = Records::summary( 30 );
		$total  = (int) $s['total'];
		$labels = array(
			'accept_all' => __( 'Accepted all', 'ux-studio' ),
			'reject_all' => __( 'Rejected all', 'ux-studio' ),
			'custom'     => __( 'Own choice', 'ux-studio' ),
			'withdraw'   => __( 'Withdrawn', 'ux-studio' ),
		);
		?>
		<div style="font-size:13px;">
			<?php if ( 0 === $total ) : ?>
				<p style="color:#646970;font-style:italic;"><?php esc_html_e( 'No answers recorded in the last 30 days.', 'ux-studio' ); ?></p>
			<?php else : ?>
				<p style="margin-top:0;">
					<?php
					printf(
						/* translators: %s: number of recorded answers */
						esc_html__( '%s answers in the last 30 days.', 'ux-studio' ),
						'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
					);
					?>
				</p>
				<table style="width:100%;border-collapse:collapse;">
					<?php foreach ( $labels as $key => $label ) : ?>
						<tr>
							<td style="padding:3px 0;"><?php echo esc_html( $label ); ?></td>
							<td style="text-align:right;color:#646970;"><?php echo esc_html( number_format_i18n( (int) $s['actions'][ $key ] ) ); ?></td>
							<td style="text-align:right;width:56px;"><strong><?php echo esc_html( number_format_i18n( 100 * (int) $s['actions'][ $key ] / $total ) ); ?> %</strong></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
			<p style="margin-bottom:0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ux-studio#/module?id=consent' ) ); ?>"><?php esc_html_e( 'Open consent records', 'ux-studio' ); ?></a>
			</p>
		</div>
		<?php
	}
}
