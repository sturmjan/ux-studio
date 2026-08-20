<?php
/**
 * Top Bar module - scheduled announcement bar in wp_head.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\TopBar;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an announcement bar at the very top of the frontend, with optional
 * publish/deactivate scheduling and a marquee for long content. Ported from
 * the legacy module.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_head', array( $this, 'render_bar' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Whether the bar should currently be visible.
	 */
	public function is_bar_active(): bool {
		$content = (string) $this->settings->get( 'content', '' );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return false;
		}

		$now = current_time( 'timestamp' );

		$publish = (string) $this->settings->get( 'schedule_publish', '' );
		if ( '' !== $publish ) {
			$publish_ts = strtotime( $publish, $now );
			if ( $publish_ts && $now < $publish_ts ) {
				return false;
			}
		}

		$deactivate = (string) $this->settings->get( 'schedule_deactivate', '' );
		if ( '' !== $deactivate ) {
			$deactivate_ts = strtotime( $deactivate, $now );
			if ( $deactivate_ts && $now >= $deactivate_ts ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Output the bar markup and dynamic colour styles in wp_head.
	 */
	public function render_bar(): void {
		if ( is_admin() || ! $this->is_bar_active() ) {
			return;
		}

		$content    = wp_kses_post( (string) $this->settings->get( 'content', '' ) );
		$bg_color   = sanitize_hex_color( (string) $this->settings->get( 'bg_color', '#1d3998' ) ) ?: '#1d3998';
		$text_color = sanitize_hex_color( (string) $this->settings->get( 'text_color', '#ffffff' ) ) ?: '#ffffff';
		?>
		<style id="uxstudio-top-bar-inline">
			#uxstudio-top-bar {
				background: <?php echo esc_html( $bg_color ); ?>;
				color: <?php echo esc_html( $text_color ); ?>;
			}
			#uxstudio-top-bar a {
				color: <?php echo esc_html( $text_color ); ?>;
				text-decoration: underline;
			}
			#uxstudio-top-bar a:hover {
				opacity: 0.8;
			}
		</style>
		<div id="uxstudio-top-bar" role="banner">
			<div class="uxstudio-top-bar__track">
				<div class="uxstudio-top-bar__content"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue the bar stylesheet and marquee script.
	 */
	public function enqueue_assets(): void {
		if ( is_admin() || ! $this->is_bar_active() ) {
			return;
		}

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_style(
			'uxstudio-top-bar',
			plugins_url( 'assets/css/top-bar.css', __FILE__ ),
			array(),
			$version
		);

		wp_enqueue_script(
			'uxstudio-top-bar',
			plugins_url( 'assets/js/top-bar.js', __FILE__ ),
			array(),
			$version,
			true
		);
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'content',
				'type'    => 'richtext',
				'label'   => __( 'Bar text', 'ux-studio' ),
				'help'    => __( 'Text shown in the top bar. If it is wider than the screen it scrolls automatically.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'bg_color',
				'type'    => 'color',
				'label'   => __( 'Background colour', 'ux-studio' ),
				'help'    => __( 'Background colour of the bar.', 'ux-studio' ),
				'default' => '#1d3998',
			),
			array(
				'key'     => 'text_color',
				'type'    => 'color',
				'label'   => __( 'Text colour', 'ux-studio' ),
				'help'    => __( 'Colour of the text and links in the bar.', 'ux-studio' ),
				'default' => '#ffffff',
			),
			array(
				'key'     => 'schedule_publish',
				'type'    => 'text',
				'label'   => __( 'Auto publish', 'ux-studio' ),
				'help'    => __( 'Show the bar from this date/time. Leave empty to show immediately. Format: YYYY-MM-DDTHH:MM.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'schedule_deactivate',
				'type'    => 'text',
				'label'   => __( 'Auto deactivate', 'ux-studio' ),
				'help'    => __( 'Hide the bar after this date/time. Leave empty to show permanently. Format: YYYY-MM-DDTHH:MM.', 'ux-studio' ),
				'default' => '',
			),
		);
	}
}
