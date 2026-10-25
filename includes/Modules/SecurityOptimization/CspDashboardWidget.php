<?php
/**
 * WordPress dashboard widget: what the website loads from where (CSP inventory).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Same server-rendered pattern as DashboardWidget (PLAN.md 20.10). Shown
 * whenever CSP is not off and deliberately impossible to hide: registered
 * after the Dashboard Widgets module removes hidden widgets (priority 999),
 * and stripped from the per-user "hidden"/"collapsed" lists. A suspicious
 * script nobody looks at is the exact failure this exists to prevent.
 */
final class CspDashboardWidget {

	public const ID = 'uxstudio_csp_widget';

	private const ROWS = 12;

	/**
	 * Register the widget on the dashboard.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ), 1001 );
		add_filter( 'get_user_option_metaboxhidden_dashboard', array( self::class, 'never_hidden' ) );
		add_filter( 'get_user_option_closedpostboxes_dashboard', array( self::class, 'never_hidden' ) );
		add_action( 'admin_head-index.php', array( self::class, 'hide_screen_option' ) );
	}

	/**
	 * Add the widget (admins only), at the top of the main column.
	 */
	public static function add(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::ID,
			__( 'Security - what the website loads (CSP)', 'ux-studio' ),
			array( self::class, 'render' ),
			null,
			null,
			'normal',
			'high'
		);
	}

	/**
	 * @param mixed $value Stored list of widget ids.
	 * @return mixed
	 */
	public static function never_hidden( $value ) {
		return is_array( $value ) ? array_values( array_diff( $value, array( self::ID ) ) ) : $value;
	}

	/**
	 * No "show/hide" checkbox for this widget in Screen Options.
	 */
	public static function hide_screen_option(): void {
		echo '<style>label[for="' . esc_attr( self::ID ) . '-hide"]{display:none!important}#' . esc_attr( self::ID ) . ' .handle-actions{display:none}</style>';
	}

	/**
	 * Render the widget body.
	 */
	public static function render(): void {
		$settings = (array) get_option( 'uxstudio_security_optimization', array() );
		$mode     = (string) ( $settings['csp_mode'] ?? 'off' );
		$state    = CspPolicy::state();
		$probe    = CspHeaderProbe::last();
		$stats    = CspLogger::stats();
		$rows     = CspLogger::get_violations(
			array(
				'orderby'  => 'risk',
				'per_page' => self::ROWS,
			)
		);
		$screen   = admin_url( 'admin.php?page=ux-studio#/module?id=security-optimization&tab=csp' );

		$modes = array(
			'audit'       => __( 'Inventory (Report only)', 'ux-studio' ),
			'report-only' => __( 'Policy test (Report only)', 'ux-studio' ),
			'enforce'     => __( 'Enforce (blocking)', 'ux-studio' ),
		);
		$alert = $stats['high'] > 0 ? '#d63638' : ( $stats['medium'] > 0 ? '#dba617' : '#16a34a' );
		?>
		<div class="uxs-csp-widget" style="font-size:13px;">
			<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid <?php echo esc_attr( $alert ); ?>;margin-bottom:12px;border-radius:0 4px 4px 0;">
				<span>
					<strong><?php echo esc_html( $modes[ $mode ] ?? $mode ); ?></strong>
					<?php if ( $state['since'] > 0 ) : ?>
						<span style="color:#646970;">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human-readable time difference, e.g. "3 days". */
									__( 'for %s', 'ux-studio' ),
									human_time_diff( $state['since'], time() )
								)
							);
							?>
						</span>
					<?php endif; ?>
				</span>
				<span style="margin-left:auto;text-align:right;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of external sources, 2: number of high-risk sources. */
							__( '%1$d sources, %2$d high risk', 'ux-studio' ),
							$stats['total'],
							$stats['high']
						)
					);
					?>
				</span>
			</div>

			<?php self::render_warnings( $probe, $state ); ?>

			<?php if ( empty( $rows ) ) : ?>
				<p style="color:#646970;font-style:italic;">
					<?php esc_html_e( 'No external sources reported yet. Browsers report them as visitors open pages - give it a few hours.', 'ux-studio' ); ?>
				</p>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;font-size:12px;">
					<thead>
						<tr>
							<th style="text-align:left;color:#646970;padding-bottom:4px;"><?php esc_html_e( 'Source', 'ux-studio' ); ?></th>
							<th style="text-align:left;color:#646970;padding-bottom:4px;"><?php esc_html_e( 'Type', 'ux-studio' ); ?></th>
							<th style="text-align:right;color:#646970;padding-bottom:4px;"><?php esc_html_e( 'Rating', 'ux-studio' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php self::render_row( $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $stats['total'] > count( $rows ) ) : ?>
					<p style="color:#646970;margin:6px 0 0;">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of further sources not shown. */
								__( 'and %d more', 'ux-studio' ),
								$stats['total'] - count( $rows )
							)
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<p style="margin-top:10px;">
				<a href="<?php echo esc_url( $screen ); ?>" class="button button-small"><?php esc_html_e( 'Open CSP overview', 'ux-studio' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $probe Last self-test.
	 * @param array<string,int|string> $state CSP state.
	 */
	private static function render_warnings( array $probe, array $state ): void {
		$warnings = array();
		if ( CspPolicy::disabled_by_constant() ) {
			$warnings[] = __( 'CSP is switched off by UXSTUDIO_CSP_DISABLE in wp-config.php - no header is sent.', 'ux-studio' );
		}
		if ( ! empty( $probe ) && empty( $probe['ok'] ) ) {
			$warnings[] = 'rewritten' === ( $probe['reason'] ?? '' )
				? __( 'The header is replaced on the way to the browser (server or another plugin). Reports cannot arrive - see the CSP overview.', 'ux-studio' )
				: __( 'The self-test did not find the CSP header in the response. Reports cannot arrive - see the CSP overview.', 'ux-studio' );
		}
		if ( (int) $state['paused_until'] > time() ) {
			$warnings[] = __( 'Too many reports in the last hour - collecting is paused until the next full hour.', 'ux-studio' );
		}
		foreach ( $warnings as $warning ) {
			echo '<p style="margin:0 0 10px;padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;">' . esc_html( $warning ) . '</p>';
		}
	}

	/**
	 * @param array<string,mixed> $row Presented inventory row.
	 */
	private static function render_row( array $row ): void {
		$level  = (string) $row['risk_level'];
		$styles = array(
			'high'    => array( '#fcf0f1', '#d63638', __( 'High risk', 'ux-studio' ) ),
			'medium'  => array( '#fcf9e8', '#996800', __( 'Suspicious', 'ux-studio' ) ),
			'unknown' => array( '', '#2271b1', __( 'Unknown', 'ux-studio' ) ),
			'known'   => array( '', '#008a20', __( 'Known service', 'ux-studio' ) ),
			'self'    => array( '', '#646970', __( 'Own domain', 'ux-studio' ) ),
		);
		[ $bg, $color, $label ] = $styles[ $level ] ?? $styles['unknown'];

		$first = strtotime( (string) $row['first_seen'] . ' UTC' );
		$new   = false !== $first && time() - $first < 7 * DAY_IN_SECONDS;
		?>
		<tr style="<?php echo '' !== $bg ? 'background:' . esc_attr( $bg ) . ';' : ''; ?>border-top:1px solid #f0f0f1;">
			<td style="padding:5px 4px;">
				<strong<?php echo 'high' === $level ? ' style="color:#d63638;"' : ''; ?>><?php echo esc_html( (string) $row['blocked_host'] ); ?></strong>
				<?php if ( $new ) : ?>
					<span style="display:inline-block;margin-left:4px;padding:0 5px;border-radius:3px;background:#2271b1;color:#fff;font-size:10px;line-height:16px;"><?php esc_html_e( 'NEW', 'ux-studio' ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== (string) $row['service'] ) : ?>
					<span style="color:#646970;"> - <?php echo esc_html( (string) $row['service'] ); ?></span>
				<?php endif; ?>
				<?php if ( in_array( $level, array( 'high', 'medium' ), true ) && ! empty( $row['risk_reasons'] ) ) : ?>
					<div style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( implode( ', ', array_map( array( self::class, 'reason_label' ), (array) $row['risk_reasons'] ) ) ); ?></div>
				<?php endif; ?>
				<?php if ( 'resolved' === $row['status'] ) : ?>
					<div style="color:#646970;"><?php esc_html_e( 'approved', 'ux-studio' ); ?></div>
				<?php endif; ?>
			</td>
			<td style="padding:5px 4px;color:#646970;white-space:nowrap;"><?php echo esc_html( self::directive_label( (string) $row['directive'] ) ); ?></td>
			<td style="padding:5px 4px;text-align:right;white-space:nowrap;color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo esc_html( $label ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Human label of a CSP directive.
	 */
	public static function directive_label( string $directive ): string {
		$labels = array(
			'script-src'   => __( 'Script', 'ux-studio' ),
			'style-src'    => __( 'Stylesheet', 'ux-studio' ),
			'img-src'      => __( 'Image', 'ux-studio' ),
			'font-src'     => __( 'Font', 'ux-studio' ),
			'connect-src'  => __( 'Data connection', 'ux-studio' ),
			'frame-src'    => __( 'Embedded frame', 'ux-studio' ),
			'media-src'    => __( 'Audio/video', 'ux-studio' ),
			'worker-src'   => __( 'Worker', 'ux-studio' ),
			'manifest-src' => __( 'Manifest', 'ux-studio' ),
			'form-action'  => __( 'Form submission', 'ux-studio' ),
			'object-src'   => __( 'Plugin object', 'ux-studio' ),
		);
		return $labels[ $directive ] ?? $directive;
	}

	/**
	 * Human label of a risk reason code (codes with a domain: "typo:google.com").
	 */
	public static function reason_label( string $code ): string {
		[ $code, $detail ] = array_pad( explode( ':', $code, 2 ), 2, '' );
		switch ( $code ) {
			case 'insecure_scheme':
				return __( 'unencrypted http', 'ux-studio' );
			case 'ip_address':
				return __( 'IP address instead of a domain', 'ux-studio' );
			case 'punycode':
				return __( 'internationalised (look-alike) characters', 'ux-studio' );
			case 'risky_tld':
				return __( 'domain ending often used for abuse', 'ux-studio' );
			case 'odd_host':
				return __( 'tunnel / paste / free hosting service', 'ux-studio' );
			case 'typo':
				/* translators: %s: imitated domain, e.g. google-analytics.com */
				return sprintf( __( 'imitates %s', 'ux-studio' ), $detail );
			case 'brand':
				/* translators: %s: known domain with the same name, e.g. stripe.com */
				return sprintf( __( 'same name as %s, other ending', 'ux-studio' ), $detail );
			case 'random_name':
				return __( 'random-looking name', 'ux-studio' );
			case 'new_domain':
				return __( 'domain registered less than 30 days ago', 'ux-studio' );
			case 'young_domain':
				return __( 'domain registered less than 6 months ago', 'ux-studio' );
			case 'obfuscated_inline':
				return __( 'obfuscated inline code', 'ux-studio' );
			default:
				return $code;
		}
	}
}
