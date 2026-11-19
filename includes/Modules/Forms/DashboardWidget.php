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
		$recent = Submissions::recent( 15 );
		$screen_unread = admin_url( 'admin.php?page=ux-studio#/module?id=forms&status=unread' );
		$screen_all    = admin_url( 'admin.php?page=ux-studio#/module?id=forms' );
		$uid           = 'uxs-forms-widget-' . wp_unique_id();

		// Forms that appear in the shown rows, for the filter.
		$forms = array();
		foreach ( $recent as $row ) {
			$forms[ (int) $row['form_id'] ] = (string) $row['form_title'];
		}
		?>
		<div class="uxs-forms-widget" id="<?php echo esc_attr( $uid ); ?>" style="font-size:13px;">
			<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f6f7f7;border-left:4px solid <?php echo $unread > 0 ? '#d63638' : '#16a34a'; ?>;margin-bottom:12px;border-radius:0 4px 4px 0;">
				<span><?php esc_html_e( 'Unread submissions:', 'ux-studio' ); ?></span>
				<span style="margin-left:auto;font-size:18px;font-weight:700;"><?php echo (int) $unread; ?></span>
			</div>

			<?php if ( empty( $recent ) ) : ?>
				<p style="color:#646970;font-style:italic;"><?php esc_html_e( 'No submissions recorded yet.', 'ux-studio' ); ?></p>
			<?php else : ?>
				<?php if ( count( $forms ) > 1 ) : ?>
					<p style="margin:0 0 8px;">
						<select class="uxs-forms-widget__filter" aria-label="<?php esc_attr_e( 'Form', 'ux-studio' ); ?>" style="max-width:100%;">
							<option value=""><?php esc_html_e( 'All forms', 'ux-studio' ); ?></option>
							<?php foreach ( $forms as $form_id => $form_title ) : ?>
								<option value="<?php echo (int) $form_id; ?>"><?php echo esc_html( $form_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>
				<ul style="margin:0;padding:0;list-style:none;">
					<?php foreach ( $recent as $row ) : ?>
						<?php
						$values = (array) ( json_decode( (string) $row['values_json'], true ) ?: array() );
						$labels = (array) ( json_decode( (string) ( $row['fields_snapshot_json'] ?? '' ), true ) ?: array() );
						$pairs  = self::pairs( $values, $labels );
						?>
						<li class="uxs-forms-widget__row" data-form="<?php echo (int) $row['form_id']; ?>" style="padding:8px 0;border-top:1px solid #f0f0f1;">
							<div style="display:flex;gap:8px;align-items:baseline;">
								<?php if ( 'unread' === $row['status'] ) : ?>
									<span title="<?php esc_attr_e( 'Unread', 'ux-studio' ); ?>" style="width:8px;height:8px;border-radius:50%;background:#d63638;flex:none;align-self:center;"></span>
								<?php endif; ?>
								<strong><?php echo esc_html( (string) $row['form_title'] ); ?></strong>
								<span style="margin-left:auto;color:#646970;white-space:nowrap;font-size:12px;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference, e.g. "2 hours". */
											__( '%s ago', 'ux-studio' ),
											human_time_diff( strtotime( (string) $row['created_at'] ), current_time( 'timestamp' ) )
										)
									);
									?>
								</span>
							</div>
							<?php if ( empty( $pairs ) ) : ?>
								<div style="color:#646970;font-size:12px;"><?php esc_html_e( '(no text fields)', 'ux-studio' ); ?></div>
							<?php else : ?>
								<?php foreach ( $pairs as $pair ) : ?>
									<div style="font-size:12px;"><span style="color:#646970;"><?php echo esc_html( $pair[0] ); ?>:</span> <?php echo esc_html( $pair[1] ); ?></div>
								<?php endforeach; ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<script>
				( function () {
					var root = document.getElementById( <?php echo wp_json_encode( $uid ); ?> );
					var select = root && root.querySelector( '.uxs-forms-widget__filter' );
					if ( ! select ) { return; }
					select.addEventListener( 'change', function () {
						Array.prototype.forEach.call( root.querySelectorAll( '.uxs-forms-widget__row' ), function ( row ) {
							row.style.display = ( '' === select.value || row.getAttribute( 'data-form' ) === select.value ) ? '' : 'none';
						} );
					} );
				}() );
				</script>
			<?php endif; ?>

			<p style="margin-top:10px;">
				<a href="<?php echo esc_url( $unread > 0 ? $screen_unread : $screen_all ); ?>" class="button button-small">
					<?php esc_html_e( 'Open submissions', 'ux-studio' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Up to three "label => value" pairs of what the visitor filled in, in form order.
	 *
	 * @param array $values Submission values.
	 * @param array $labels Field snapshot: key => { label, type }.
	 * @return array<int, array{0:string,1:string}>
	 */
	private static function pairs( array $values, array $labels ): array {
		$out = array();
		foreach ( $labels as $key => $meta ) {
			$type = is_array( $meta ) ? (string) ( $meta['type'] ?? '' ) : '';
			if ( in_array( $type, array( 'file', 'files', 'upload', 'signature', 'captcha', 'hidden' ), true ) ) {
				continue;
			}
			$value = $values[ $key ] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			} elseif ( true === $value ) {
				$value = __( 'Yes', 'ux-studio' );
			} elseif ( ! is_scalar( $value ) ) {
				$value = '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$out[] = array( is_array( $meta ) ? (string) ( $meta['label'] ?? $key ) : (string) $key, mb_strimwidth( $value, 0, 70, '…' ) );
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return $out;
	}
}
