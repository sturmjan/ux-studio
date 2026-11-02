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
	 * Whether a from/to window (times of the site, empty = open) contains now.
	 *
	 * @param string $from Start, YYYY-MM-DDTHH:MM or empty.
	 * @param string $to   End, YYYY-MM-DDTHH:MM or empty.
	 */
	private function in_window( string $from, string $to ): bool {
		$now = current_time( 'timestamp' );

		if ( '' !== $from ) {
			$from_ts = strtotime( $from, $now );
			if ( $from_ts && $now < $from_ts ) {
				return false;
			}
		}

		if ( '' !== $to ) {
			$to_ts = strtotime( $to, $now );
			if ( $to_ts && $now >= $to_ts ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Messages to show right now: the simple bar text first, then the published items of the list whose own
	 * schedule contains now. Only when the whole bar is inside its own schedule.
	 *
	 * @return array<int, array{title: string, text: string}>
	 */
	public function active_slides(): array {
		if ( ! $this->in_window( (string) $this->settings->get( 'schedule_publish', '' ), (string) $this->settings->get( 'schedule_deactivate', '' ) ) ) {
			return array();
		}

		$slides  = array();
		$content = (string) $this->settings->get( 'content', '' );
		if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
			$slides[] = array(
				'title' => '',
				'text'  => $content,
			);
		}

		foreach ( (array) $this->settings->get( 'slides', array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['published'] ) ) {
				continue;
			}
			$text = (string) ( $item['text'] ?? '' );
			if ( '' === trim( wp_strip_all_tags( $text ) ) || ! $this->in_window( (string) ( $item['from'] ?? '' ), (string) ( $item['to'] ?? '' ) ) ) {
				continue;
			}
			$slides[] = array(
				'title' => 'title_text' === ( $item['mode'] ?? '' ) ? trim( (string) ( $item['title'] ?? '' ) ) : '',
				'text'  => $text,
			);
		}

		return $slides;
	}

	/**
	 * Whether the bar should currently be visible.
	 */
	public function is_bar_active(): bool {
		return array() !== $this->active_slides();
	}

	/**
	 * Output the bar markup and dynamic colour styles in wp_head.
	 */
	public function render_bar(): void {
		$slides = is_admin() ? array() : $this->active_slides();
		if ( array() === $slides ) {
			return;
		}

		$interval   = max( 2, min( 60, (int) $this->settings->get( 'interval', 6 ) ) );
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
		<div id="uxstudio-top-bar" role="banner" data-interval="<?php echo esc_attr( (string) $interval ); ?>"<?php echo count( $slides ) > 1 ? ' data-slides="' . count( $slides ) . '"' : ''; ?>>
			<div class="uxstudio-top-bar__track">
				<?php foreach ( $slides as $i => $slide ) : ?>
					<div class="uxstudio-top-bar__content<?php echo 0 === $i ? ' is-active' : ''; ?>">
						<?php if ( '' !== $slide['title'] ) : ?>
							<strong class="uxstudio-top-bar__title"><?php echo esc_html( $slide['title'] ); ?></strong>
						<?php endif; ?>
						<?php echo wp_kses_post( $slide['text'] ); ?>
					</div>
				<?php endforeach; ?>
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
				'key'         => 'slides',
				'type'        => 'repeater',
				'label'       => __( 'More messages', 'ux-studio' ),
				'help'        => __( 'Add more messages. With two or more active messages (the bar text above counts as the first one) the bar switches between them like a slideshow.', 'ux-studio' ),
				'default'     => array(),
				'wide'        => true,
				'item_label'  => __( 'Message', 'ux-studio' ),
				'item_title'  => 'title',
				'max_items'   => 20,
				'item_fields' => array(
					array(
						'key'     => 'published',
						'type'    => 'toggle',
						'label'   => __( 'Published', 'ux-studio' ),
						'default' => true,
					),
					array(
						'key'     => 'mode',
						'type'    => 'select',
						'label'   => __( 'Layout', 'ux-studio' ),
						'options' => array(
							'text'       => __( 'Text only', 'ux-studio' ),
							'title_text' => __( 'Heading and text', 'ux-studio' ),
						),
						'default' => 'text',
					),
					array(
						'key'     => 'title',
						'type'    => 'text',
						'label'   => __( 'Heading', 'ux-studio' ),
						'default' => '',
						'show_if' => array(
							'key'    => 'mode',
							'equals' => 'title_text',
						),
					),
					array(
						'key'     => 'text',
						'type'    => 'richtext',
						'label'   => __( 'Text', 'ux-studio' ),
						'default' => '',
					),
					array(
						'key'     => 'from',
						'type'    => 'datetime',
						'label'   => __( 'Show from', 'ux-studio' ),
						'help'    => __( 'Time of the site. Leave empty to show it immediately.', 'ux-studio' ),
						'default' => '',
					),
					array(
						'key'     => 'to',
						'type'    => 'datetime',
						'label'   => __( 'Show until', 'ux-studio' ),
						'help'    => __( 'Time of the site. Leave empty to show it until you unpublish or remove it.', 'ux-studio' ),
						'default' => '',
					),
				),
			),
			array(
				'key'     => 'interval',
				'type'    => 'number',
				'label'   => __( 'Seconds per message', 'ux-studio' ),
				'help'    => __( 'How long each message stays before the slideshow moves on (2 to 60).', 'ux-studio' ),
				'default' => 6,
			),
			array(
				'key'     => 'bg_color',
				'type'    => 'color',
				'label'   => __( 'Background color', 'ux-studio' ),
				'help'    => __( 'Background color of the bar.', 'ux-studio' ),
				'default' => '#1d3998',
			),
			array(
				'key'     => 'text_color',
				'type'    => 'color',
				'label'   => __( 'Text color', 'ux-studio' ),
				'help'    => __( 'Color of the text and links in the bar.', 'ux-studio' ),
				'default' => '#ffffff',
			),
			array(
				'key'     => 'schedule_publish',
				'type'    => 'datetime',
				'label'   => __( 'Auto publish', 'ux-studio' ),
				'help'    => __( 'Show the bar from this date and time (time of the site). Leave empty to show it immediately.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'schedule_deactivate',
				'type'    => 'datetime',
				'label'   => __( 'Auto deactivate', 'ux-studio' ),
				'help'    => __( 'Hide the bar after this date and time (time of the site). Leave empty to show it until you remove it.', 'ux-studio' ),
				'default' => '',
			),
		);
	}
}
