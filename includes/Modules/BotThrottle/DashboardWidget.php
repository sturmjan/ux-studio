<?php
/**
 * WordPress dashboard widget summarising bot-throttle activity.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered dashboard widget (parity with the legacy module): current
 * load tier, 24h counters and the top bots.
 */
final class DashboardWidget {

	private const COLORS = array(
		'GREEN'  => '#16a34a',
		'YELLOW' => '#dba617',
		'ORANGE' => '#d97706',
		'RED'    => '#d63638',
	);

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
			'uxstudio_bot_throttle_widget',
			__( 'Bot Throttle - activity', 'ux-studio' ),
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the widget body.
	 */
	public static function render(): void {
		$tier   = ( new LoadSampler() )->current_tier();
		$stats  = Log::stats( 24 );
		$color  = self::COLORS[ $tier['tier'] ] ?? '#646970';
		$screen = admin_url( 'admin.php?page=ux-studio#/module?id=bot-throttle' );
		?>
		<div class="uxs-bt-widget" style="font-size:13px;">
			<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid <?php echo esc_attr( $color ); ?>;margin-bottom:12px;border-radius:0 4px 4px 0;">
				<span><?php esc_html_e( 'Current server load:', 'ux-studio' ); ?></span>
				<span style="font-weight:700;color:<?php echo esc_attr( $color ); ?>;letter-spacing:.5px;"><?php echo esc_html( $tier['tier'] ); ?></span>
				<span style="margin-left:auto;font-size:18px;font-weight:600;"><?php echo (int) $tier['score']; ?>%</span>
			</div>
			<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;">
				<?php
				self::stat_card( number_format_i18n( $stats['total'] ), __( 'Bot requests (24h)', 'ux-studio' ) );
				self::stat_card( number_format_i18n( $stats['delayed'] ), __( 'Delayed', 'ux-studio' ) );
				self::stat_card( number_format_i18n( $stats['blocked'] ), __( 'Blocked', 'ux-studio' ) );
				?>
			</div>
			<?php if ( ! empty( $stats['top_bots'] ) ) : ?>
				<table style="width:100%;border-collapse:collapse;font-size:12px;">
					<thead>
						<tr>
							<th style="text-align:left;color:#646970;"><?php esc_html_e( 'Bot', 'ux-studio' ); ?></th>
							<th style="text-align:left;color:#646970;"><?php esc_html_e( 'Category', 'ux-studio' ); ?></th>
							<th style="text-align:right;color:#646970;"><?php esc_html_e( 'Requests', 'ux-studio' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $stats['top_bots'], 0, 5 ) as $bot ) : ?>
							<tr>
								<td style="padding:3px 0;"><strong><?php echo esc_html( (string) $bot['bot_name'] ); ?></strong></td>
								<td style="color:#646970;"><?php echo esc_html( (string) $bot['bot_category'] ); ?></td>
								<td style="text-align:right;"><?php echo number_format_i18n( (int) $bot['c'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p style="color:#646970;font-style:italic;"><?php esc_html_e( 'No bot activity recorded yet.', 'ux-studio' ); ?></p>
			<?php endif; ?>
			<p style="margin-top:10px;">
				<a href="<?php echo esc_url( $screen ); ?>" class="button button-small"><?php esc_html_e( 'Open Bot Throttle', 'ux-studio' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * @param string $value Big number.
	 * @param string $label Caption.
	 */
	private static function stat_card( string $value, string $label ): void {
		?>
		<div style="background:#fff;border:1px solid #dcdcde;padding:8px;text-align:center;border-radius:3px;">
			<div style="font-size:18px;font-weight:600;"><?php echo esc_html( $value ); ?></div>
			<div style="font-size:11px;color:#646970;margin-top:2px;"><?php echo esc_html( $label ); ?></div>
		</div>
		<?php
	}
}
