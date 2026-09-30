<?php
/**
 * WordPress dashboard widget summarising Form Builder submissions.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered, exactly the BotThrottle\DashboardWidget pattern
 * (PLAN.md 20.10): static register()/add()/render(), no React - this
 * widget lives on the native wp-admin dashboard, not inside the SPA.
 */
final class DashboardWidget {

	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ) );
	}

	public static function add(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'uxstudio_forms_widget',
			__( 'Form Builder - submissions', 'ux-studio' ),
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		$unread = Submissions::count_unread();
		$recent = Submissions::recent( 5 );
		$screen_unread = admin_url( 'admin.php?page=ux-studio#/module?id=forms&tab=archive&status=unread' );
		$screen_all    = admin_url( 'admin.php?page=ux-studio#/module?id=forms&tab=archive' );
		?>
		<div class="uxs-forms-widget" style="font-size:13px;">
			<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid <?php echo $unread > 0 ? '#d63638' : '#16a34a'; ?>;margin-bottom:12px;border-radius:0 4px 4px 0;">
				<span><?php esc_html_e( 'Unread submissions:', 'ux-studio' ); ?></span>
				<span style="margin-left:auto;font-size:18px;font-weight:700;"><?php echo (int) $unread; ?></span>
			</div>

			<?php if ( empty( $recent ) ) : ?>
				<p style="color:#646970;font-style:italic;"><?php esc_html_e( 'No submissions recorded yet.', 'ux-studio' ); ?></p>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;font-size:12px;">
					<thead>
						<tr>
							<th style="text-align:left;color:#646970;"><?php esc_html_e( 'Form', 'ux-studio' ); ?></th>
							<th style="text-align:left;color:#646970;"><?php esc_html_e( 'Summary', 'ux-studio' ); ?></th>
							<th style="text-align:right;color:#646970;"><?php esc_html_e( 'When', 'ux-studio' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<?php
							$values  = (array) ( json_decode( (string) $row['values_json'], true ) ?: array() );
							$summary = self::summarize( $values );
							?>
							<tr>
								<td style="padding:4px 0;"><strong><?php echo esc_html( (string) $row['form_title'] ); ?></strong></td>
								<td style="color:#646970;padding-right:8px;"><?php echo esc_html( $summary ); ?></td>
								<td style="text-align:right;color:#646970;white-space:nowrap;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference, e.g. "2 hours". */
											__( '%s ago', 'ux-studio' ),
											human_time_diff( strtotime( (string) $row['created_at'] ), current_time( 'timestamp' ) )
										)
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:10px;">
				<a href="<?php echo esc_url( $unread > 0 ? $screen_unread : $screen_all ); ?>" class="button button-small">
					<?php esc_html_e( 'Open Archive', 'ux-studio' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * First non-empty scalar value (typically name/email) as a short summary line.
	 *
	 * @param array $values Submission values.
	 */
	private static function summarize( array $values ): string {
		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return mb_strimwidth( $value, 0, 60, '…' );
			}
		}
		return __( '(no text fields)', 'ux-studio' );
	}
}
