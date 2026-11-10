<?php
/**
 * Live preview of one dashboard widget, shown in a small frame when the owner points at a row of the table.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * An admin-ajax endpoint (`action=uxstudio_dw_preview&widget=<id>&_wpnonce=...`) that sets the dashboard up the way
 * wp-admin/index.php does, then prints ONLY the chosen widget in the frame of a postbox.
 *
 * Safety:
 *  - only for a logged-in administrator (`manage_options`) with a valid nonce; there is no `nopriv` hook;
 *  - the page may be framed by the same site only (X-Frame-Options and frame-ancestors), is `noindex` and is cached
 *    privately for 30 seconds;
 *  - the rules and the snapshot are switched off for the request, and the switch of the previewed widget is forced
 *    on, so a widget that is hidden or switched off is still drawn; nothing is stored;
 *  - the widget runs inside an output buffer with try / catch; a fatal error is caught by a shutdown function that
 *    replaces the page with "preview not available". A widget with no output is "not available" too.
 */
final class Preview {

	public const ACTION = 'uxstudio_dw_preview';
	public const NONCE  = 'uxstudio_dw_preview';

	/** Largest widget output that is shown (bytes). */
	private const MAX_OUTPUT = 400000;

	/** @var string Id of the previewed widget while a preview request runs, '' otherwise. */
	private static string $widget = '';

	private Module $module;

	/** @var bool The page was printed (the shutdown function then has nothing to repair). */
	private bool $done = false;

	/**
	 * @param Module $module The dashboard-widgets module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Whether a preview request is running (the rules and the snapshot must then leave the dashboard alone).
	 */
	public static function active(): bool {
		return '' !== self::$widget;
	}

	/**
	 * Hook the endpoint.
	 */
	public function boot(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The address the SPA appends `&widget=<id>` to.
	 */
	public static function url_base(): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'_wpnonce' => wp_create_nonce( self::NONCE ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * The ajax handler.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified right below.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		$id    = isset( $_GET['widget'] ) ? WidgetRules::clean_id( sanitize_text_field( wp_unslash( (string) $_GET['widget'] ) ) ) : '';
		// phpcs:enable
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'You are not allowed to see this preview.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		if ( '' === $id ) {
			status_header( 400 );
			wp_die( esc_html__( 'Unknown widget.', 'ux-studio' ), '', array( 'response' => 400 ) );
		}

		self::$widget = $id;
		send_frame_options_header();
		header( "Content-Security-Policy: frame-ancestors 'self'" );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: private, max-age=30' );
		// Needs no cookie-bound state; keep a slow third-party widget from hanging the request for long.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$level = ob_get_level();
		ob_start();
		register_shutdown_function(
			function () use ( $level ): void {
				if ( $this->done ) {
					return;
				}
				// A fatal error inside the widget: drop whatever was half printed and say so.
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
				if ( ! headers_sent() ) {
					http_response_code( 200 );
				}
				echo self::unavailable_page( __( 'This widget could not be drawn here.', 'ux-studio' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
			}
		);

		$page = '';
		try {
			$page = $this->page( $id );
		} catch ( \Throwable $e ) {
			$page = self::unavailable_page( __( 'This widget could not be drawn here.', 'ux-studio' ) );
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$this->done = true;
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts / the widget's own output.
		exit;
	}

	/**
	 * The whole page for one widget.
	 *
	 * @param string $id Widget id.
	 */
	private function page( string $id ): string {
		global $wp_meta_boxes;

		// The switch of the previewed widget is forced on (and "hide all" off) for this request only: the filter shows the
		// option with those values, nothing is saved.
		$key = self::switch_key( $id );
		add_filter(
			'option_uxstudio_dashboard_widgets',
			static function ( $value ) use ( $key ) {
				$value                          = is_array( $value ) ? $value : array();
				$value['disable_all_widgets']   = false;
				if ( '' !== $key ) {
					$value[ $key ] = true;
				}
				return $value;
			}
		);
		$this->module->flush_settings_cache();

		if ( ! function_exists( 'wp_dashboard_setup' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		$GLOBALS['hook_suffix'] = 'index.php';
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean registry for this request.
		wp_dashboard_setup();

		$title  = '';
		$html   = '';
		$found  = false;
		$box    = null;
		$welcome = 'dashboard_welcome' === $id;
		foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $priorities ) {
			foreach ( (array) $priorities as $boxes ) {
				if ( is_array( $boxes ) && isset( $boxes[ $id ] ) && is_array( $boxes[ $id ] ) ) {
					$box = $boxes[ $id ];
				}
			}
		}

		do_action( 'admin_enqueue_scripts', 'index.php' );
		wp_enqueue_style( 'dashboard' );
		wp_enqueue_style( 'buttons' );
		wp_enqueue_style( 'forms' );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'colors' );

		$reason = '';
		if ( is_array( $box ) && is_callable( $box['callback'] ?? null ) ) {
			$title = WidgetRules::clean_title( $box['title'] ?? '', $id );
			ob_start();
			try {
				call_user_func( $box['callback'], '', $box );
				$html = (string) ob_get_clean();
			} catch ( \Throwable $e ) {
				ob_end_clean();
				$reason = __( 'This widget could not be drawn here.', 'ux-studio' );
			}
			$found = true;
		} elseif ( $welcome && has_action( 'welcome_panel', 'wp_welcome_panel' ) ) {
			$title = __( 'Welcome', 'ux-studio' );
			ob_start();
			try {
				do_action( 'welcome_panel' );
				$html = (string) ob_get_clean();
			} catch ( \Throwable $e ) {
				ob_end_clean();
				$reason = __( 'This widget could not be drawn here.', 'ux-studio' );
			}
			$found = true;
		}

		if ( '' === $reason ) {
			if ( ! $found ) {
				$reason = __( 'This widget is not registered right now, for example because its module or plugin is off or something it needs is missing.', 'ux-studio' );
			} elseif ( '' === trim( wp_strip_all_tags( $html, true ) ) && ! preg_match( '/<(img|svg|canvas|input|iframe|textarea)\b/i', $html ) ) {
				$reason = __( 'This widget draws nothing without its own scripts, so there is nothing to preview.', 'ux-studio' );
			} elseif ( strlen( $html ) > self::MAX_OUTPUT ) {
				$reason = __( 'This widget is too large to preview.', 'ux-studio' );
			}
		}
		if ( '' !== $reason ) {
			return self::unavailable_page( $reason );
		}

		return $this->frame( $id, $title, $html, $welcome );
	}

	/**
	 * Setting key that switches a widget of this plugin on ('' for any other).
	 *
	 * @param string $id Widget id.
	 */
	private static function switch_key( string $id ): string {
		$entry = WidgetCatalog::entries()[ $id ] ?? null;
		return is_array( $entry ) ? (string) $entry['key'] : '';
	}

	/**
	 * The widget in the frame of a postbox, with the styles and scripts the dashboard would load for it.
	 *
	 * @param string $id      Widget id.
	 * @param string $title   Title.
	 * @param string $html    Output of the widget.
	 * @param bool   $welcome Whether it is the welcome panel (no postbox around it).
	 */
	private function frame( string $id, string $title, string $html, bool $welcome ): string {
		$classes = 'wp-admin wp-core-ui index-php dashboard-php uxs-dw-preview admin-color-' . sanitize_html_class( (string) get_user_option( 'admin_color' ) ?: 'fresh' );
		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> class="wp-toolbar">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<base target="_blank">
	<title><?php echo esc_html( $title ); ?></title>
		<?php
		wp_print_styles();
		wp_print_head_scripts();
		?>
	<style>
		html.wp-toolbar { padding-top: 0 !important; }
		body.uxs-dw-preview { margin: 0; padding: 12px; background: #f0f0f1; overflow: hidden; pointer-events: none; user-select: none; }
		body.uxs-dw-preview #dashboard-widgets { display: block; min-width: 0; }
		body.uxs-dw-preview .postbox-container { float: none; width: 100%; min-width: 0; }
		body.uxs-dw-preview .postbox { margin: 0; min-width: 0; }
		body.uxs-dw-preview .welcome-panel { margin: 0; }
		body.uxs-dw-preview .welcome-panel-close { display: none; }
	</style>
</head>
<body class="<?php echo esc_attr( $classes ); ?>">
		<?php if ( $welcome ) : ?>
	<div id="welcome-panel" class="welcome-panel"><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- the output of the widget. ?></div>
		<?php else : ?>
	<div id="dashboard-widgets-wrap">
		<div id="dashboard-widgets" class="metabox-holder">
			<div class="postbox-container">
				<div class="meta-box-sortables">
					<div id="<?php echo esc_attr( $id ); ?>" class="postbox">
						<div class="postbox-header"><h2 class="hndle"><span><?php echo esc_html( $title ); ?></span></h2></div>
						<div class="inside"><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- the output of the widget. ?></div>
					</div>
				</div>
			</div>
		</div>
	</div>
		<?php endif; ?>
		<?php do_action( 'admin_print_footer_scripts' ); ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A small page that says the preview is not available (shown in the frame).
	 *
	 * @param string $reason Why.
	 */
	public static function unavailable_page( string $reason ): string {
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html__( 'Preview not available', 'ux-studio' ) . '</title>'
			. '<style>body{margin:0;display:flex;align-items:center;justify-content:center;height:100vh;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#50575e;background:#f0f0f1;text-align:center;padding:0 24px;box-sizing:border-box}</style>'
			. '</head><body><div data-uxs-preview-unavailable="1"><strong>' . esc_html__( 'Preview not available', 'ux-studio' ) . '</strong><br>' . esc_html( $reason ) . '</div></body></html>';
	}
}
