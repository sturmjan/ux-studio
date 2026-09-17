<?php
/**
 * Login screen customisation: custom logo/colors (restored from the legacy
 * admin-customiser module) plus an optional "split" layout - a full-height
 * media panel (video / image / YouTube) on one side and the form on the
 * other, matching the Centrální aplikace login screen.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Login {

	/**
	 * Split layout, "no media configured" default: the same background clip
	 * used on the Centrální aplikace login screen. Loaded from ux1.cz rather
	 * than bundled with the plugin - it's a shadcnspace demo asset, not
	 * licensed for redistribution, so it can only be linked to, not shipped.
	 */
	private const DEFAULT_VIDEO_URL = 'https://app.ux1.cz/public/tpl/images/backgrounds/login_bg.mp4';
	private const DEFAULT_POSTER_URL = 'https://app.ux1.cz/public/tpl/images/backgrounds/login_bg.jpg';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks. Only instantiated when login_customization_enabled=true.
	 */
	public function register(): void {
		add_action( 'login_enqueue_scripts', array( $this, 'print_styles' ) );
		add_filter( 'login_headerurl', array( $this, 'logo_url' ) );
		add_filter( 'login_headertext', array( $this, 'logo_title' ) );
		add_filter( 'login_message', array( $this, 'prepend_custom_message' ) );
		add_filter( 'login_body_class', array( $this, 'add_split_body_class' ) );
		add_action( 'login_header', array( $this, 'render_split_media' ) );
		add_action( 'login_footer', array( $this, 'print_footer_script' ) );
		$this->disable_competing_login_branding();
	}

	/**
	 * White Label CMS (if active) unconditionally wraps `#login` in its own
	 * `#wlcms-login-wrapper` div via jQuery and prints its own login CSS/logo
	 * on every request, with no per-feature toggle to opt out. That silently
	 * breaks this module's layout (colors, and especially the split media
	 * panel, assume `#login` sits directly under `<body>`). Once this module
	 * is switched on, it becomes the one plugin responsible for login-screen
	 * branding, so its competing hook is removed here - the rest of White
	 * Label CMS (admin menu/dashboard/footer branding) is untouched.
	 *
	 * Runs from `register()`, itself called on `plugins_loaded`: by then every
	 * plugin file (including White Label CMS's own top-level bootstrap call)
	 * has already executed, so the hook we're removing is guaranteed to exist.
	 */
	private function disable_competing_login_branding(): void {
		if ( ! class_exists( '\WLCMS_Loader' ) ) {
			return;
		}
		$loader = \WLCMS_Loader::getInstance();
		if ( ! method_exists( $loader, 'Login' ) ) {
			return;
		}
		$wlcms_login = $loader->Login();
		if ( $wlcms_login ) {
			remove_action( 'login_footer', array( $wlcms_login, 'scripts' ), 1000 );
		}
	}

	/** Point the login logo link at the site's home URL. */
	public function logo_url(): string {
		return home_url();
	}

	/** Use the site name as the logo's title/alt text. */
	public function logo_title(): string {
		return get_bloginfo( 'name' );
	}

	private function is_split(): bool {
		return 'split' === (string) $this->settings->get( 'login_style', 'classic' );
	}

	/**
	 * Prepends, in order: the split-layout brand heading (logo/"Vítejte
	 * zpět"/subtitle, auto - not configurable), then the configured custom
	 * message, then WordPress's own login message/errors.
	 */
	public function prepend_custom_message( string $message ): string {
		$prefix = $this->is_split() ? $this->render_split_brand() : '';

		$custom = (string) $this->settings->get( 'login_message', '' );
		if ( '' !== trim( $custom ) ) {
			$prefix .= '<p class="message uxstudio-login-message">' . wp_kses_post( $custom ) . '</p>';
		}

		return $prefix . $message;
	}

	/** Logo badge + "Vítejte zpět" heading + subtitle, shown above the form in split layout. */
	private function render_split_brand(): string {
		$logo_id  = (int) $this->settings->get( 'login_logo', 0 );
		$logo_url = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		$icon = $logo_url
			? '<img src="' . esc_url( $logo_url ) . '" alt="">'
			: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>';

		return '<div class="uxstudio-login-brand">'
			. '<span class="uxstudio-login-brand__icon">' . $icon . '</span>'
			. '<h1 class="uxstudio-login-brand__title">' . esc_html__( 'Vítejte zpět', 'ux-studio' ) . '</h1>'
			. '<p class="uxstudio-login-brand__subtitle">' . esc_html( get_bloginfo( 'name' ) ) . ' &mdash; ' . esc_html__( 'přihlaste se do systému', 'ux-studio' ) . '</p>'
			. '</div>';
	}

	/**
	 * Inline <style> for the login logo, colors and (when enabled) the split
	 * media-panel layout.
	 */
	public function print_styles(): void {
		$logo_id = (int) $this->settings->get( 'login_logo', 0 );
		$logo_url = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		$css = '';

		if ( $logo_url && ! $this->is_split() ) {
			$css .= '#login h1 a, .login h1 a {';
			$css .= 'background-image: url(' . esc_url( $logo_url ) . ') !important;';
			$css .= 'background-size: contain; background-position: center; background-repeat: no-repeat;';
			$css .= 'width: 100%; height: 80px;';
			$css .= '}';
		}

		$css .= $this->color_rule( 'body.login', 'background-color', 'login_background_color' );
		$css .= $this->color_rule( 'body.login, body.login #nav a, body.login #backtoblog a', 'color', 'login_text_color' );
		$css .= $this->color_rule( '#login form', 'background-color', 'login_form_bg_color' );
		$css .= $this->color_rule( '#login form, #login form label', 'color', 'login_form_text_color' );
		$css .= $this->color_rule( '#login form', 'border-color', 'login_form_border_color' );

		$radius = (int) $this->settings->get( 'login_form_border_radius', 0 );
		if ( $radius > 0 ) {
			$css .= '#login form { border-radius: ' . $radius . 'px; overflow: hidden; }';
		}

		$css .= $this->color_rule(
			'#login form input[type=text], #login form input[type=password], #login form input[type=email]',
			'background-color',
			'login_input_bg_color'
		);
		$css .= $this->color_rule(
			'#login form input[type=text], #login form input[type=password], #login form input[type=email]',
			'color',
			'login_input_text_color'
		);
		$css .= $this->color_rule(
			'#login form input[type=text], #login form input[type=password], #login form input[type=email]',
			'border-color',
			'login_input_border_color'
		);
		$css .= $this->color_rule( '.wp-core-ui .button-primary', 'background-color', 'login_button_bg_color' );
		$css .= $this->color_rule( '.wp-core-ui .button-primary', 'border-color', 'login_button_bg_color' );
		$css .= $this->color_rule( '.wp-core-ui .button-primary', 'color', 'login_button_text_color' );

		if ( $this->is_split() ) {
			$css .= $this->split_layout_css();
		}

		if ( '' === $css ) {
			return;
		}

		printf( '<style id="uxstudio-login-customiser">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Emits `$selector { $property: <hex>; }` when the given color setting is a valid hex value. */
	private function color_rule( string $selector, string $property, string $setting_key ): string {
		$value = (string) $this->settings->get( $setting_key, '' );
		if ( '' === $value || ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
			return '';
		}
		return $selector . ' { ' . $property . ': ' . esc_attr( $value ) . ' !important; }';
	}

	private function split_layout_css(): string {
		$css = '
			body.login.uxstudio-login-split {
				display: flex;
				min-height: 100vh;
				overflow-x: hidden;
				background: #fff;
			}
			body.login.uxstudio-login-split #login {
				width: 50%;
				max-width: none;
				margin: 0 0 0 50%;
				min-height: 100vh;
				box-sizing: border-box;
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				padding: 5vh 6vw;
			}
			body.login.uxstudio-login-split #login > * {
				width: 100%;
				max-width: 400px;
			}
			body.login.uxstudio-login-split #login > h1 { display: none; }
			body.login.uxstudio-login-split #login p.message,
			body.login.uxstudio-login-split #login #login_error {
				margin: 0 0 20px;
				border: none;
				padding: 0;
				background: transparent;
				text-align: left;
			}
			body.login.uxstudio-login-split .language-switcher { display: none; }
			.uxstudio-login-brand {
				display: flex;
				flex-direction: column;
				gap: 10px;
				margin-bottom: 28px;
			}
			.uxstudio-login-brand__icon {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 48px;
				height: 48px;
				border-radius: 9999px;
				background: #111827;
				color: #fff;
				overflow: hidden;
			}
			.uxstudio-login-brand__icon svg { width: 24px; height: 24px; }
			.uxstudio-login-brand__icon img { width: 100%; height: 100%; object-fit: cover; }
			.uxstudio-login-brand__title { font-size: 24px; line-height: 1.25; font-weight: 700; margin: 0; color: inherit; }
			.uxstudio-login-brand__subtitle { font-size: 14px; margin: 0; color: inherit; opacity: .65; }
			body.login.uxstudio-login-split #loginform {
				background: transparent;
				border: 0;
				box-shadow: none;
				padding: 0;
				margin-top: 0;
			}
			body.login.uxstudio-login-split #loginform p { margin-bottom: 18px; }
			body.login.uxstudio-login-split #loginform label {
				display: block;
				font-size: 13px;
				font-weight: 500;
				margin-bottom: 6px;
			}
			body.login.uxstudio-login-split #loginform input[type=text],
			body.login.uxstudio-login-split #loginform input[type=password] {
				width: 100%;
				height: 44px;
				border-radius: 10px;
				border: 1px solid rgba(0,0,0,.15);
				padding: 0 14px;
				font-size: 14px;
				box-shadow: none;
			}
			body.login.uxstudio-login-split #loginform input[type=text]:focus,
			body.login.uxstudio-login-split #loginform input[type=password]:focus {
				border-color: #6366f1;
				box-shadow: 0 0 0 3px rgba(99,102,241,.25);
			}
			body.login.uxstudio-login-split #loginform p.forgetmenot {
				display: flex;
				align-items: center;
				flex-wrap: wrap;
				gap: 8px;
				float: none;
				width: 100%;
				margin: 0 0 20px;
			}
			body.login.uxstudio-login-split #loginform p.forgetmenot label {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				margin: 0;
				font-size: 14px;
				font-weight: 400;
			}
			body.login.uxstudio-login-split #loginform p.forgetmenot input[type=checkbox] {
				width: 16px;
				height: 16px;
				margin: 0;
			}
			body.login.uxstudio-login-split #loginform p.forgetmenot button { display: none; }
			body.login.uxstudio-login-split #loginform .uxstudio-login-lostpw {
				margin-left: auto;
				font-size: 13px;
				white-space: nowrap;
			}
			body.login.uxstudio-login-split .button-primary {
				width: 100%;
				height: 44px;
				border-radius: 10px;
				font-size: 14px;
				font-weight: 600;
				text-shadow: none;
				box-shadow: none;
				border: 0;
				margin-top: 4px;
				background: #111827;
				border-color: #111827;
				color: #fff;
			}
			.uxstudio-login-footer {
				margin-top: 24px;
				font-size: 12px;
				text-align: center;
				color: rgba(0,0,0,.5);
			}
			.uxstudio-login-footer #nav,
			.uxstudio-login-footer #backtoblog,
			.uxstudio-login-footer .privacy-policy-page-link {
				display: inline;
				margin: 0;
				padding: 0;
			}
			.uxstudio-login-footer a { color: inherit; }
			.uxstudio-login-footer__sep { margin: 0 8px; opacity: .6; }
			.uxstudio-login-media {
				position: fixed;
				top: 0; left: 0;
				width: 50%;
				height: 100vh;
				overflow: hidden;
				background: #0a0a0a;
				z-index: 0;
			}
			.uxstudio-login-media img,
			.uxstudio-login-media video {
				position: absolute;
				inset: 0;
				width: 100%;
				height: 100%;
				object-fit: cover;
			}
			.uxstudio-login-media iframe {
				position: absolute;
				top: 50%; left: 50%;
				width: 50vw;
				height: 100vh;
				min-width: 177.78vh;
				min-height: 28.125vw;
				border: 0;
				pointer-events: none;
				transform: translate(-50%, -50%);
			}
			.uxstudio-login-media::after {
				content: "";
				position: absolute;
				inset: 0;
				background: linear-gradient(to top, rgba(0,0,0,.72), rgba(0,0,0,.12) 45%, rgba(0,0,0,.35));
			}
			.uxstudio-login-media__logo {
				position: absolute;
				top: 32px; left: 32px;
				z-index: 2;
				max-width: 160px;
				max-height: 64px;
				width: auto; height: auto;
				object-fit: contain;
			}
			@media (max-width: 782px) {
				body.login.uxstudio-login-split { display: block; }
				body.login.uxstudio-login-split #login { width: 100%; margin: 0; min-height: 0; padding: 24px 20px; }
				.uxstudio-login-media { display: none; }
			}
		';

		return $css;
	}

	/** Adds the split-layout marker class to <body class="login …"> (WP 5.8+). */
	public function add_split_body_class( array $classes ): array {
		if ( $this->is_split() ) {
			$classes[] = 'uxstudio-login-split';
		}
		return $classes;
	}

	/**
	 * Outputs the left/media panel just after <body> opens (split layout only).
	 * Runs before #login so it stays a sibling, positioned via CSS.
	 */
	public function render_split_media(): void {
		if ( ! $this->is_split() ) {
			return;
		}

		$type       = (string) $this->settings->get( 'login_split_media_type', 'video' );
		$image_id   = (int) $this->settings->get( 'login_split_media_image', 0 );
		$image_url  = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'full' ) : self::DEFAULT_POSTER_URL;
		$video_url  = (string) $this->settings->get( 'login_split_media_video_url', '' );
		$video_url  = '' !== $video_url ? $video_url : self::DEFAULT_VIDEO_URL;
		$youtube    = (string) $this->settings->get( 'login_split_media_youtube_url', '' );
		$show_logo  = (bool) $this->settings->get( 'login_split_show_logo', true );
		$logo_id    = (int) $this->settings->get( 'login_logo', 0 );
		$logo_url   = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		echo '<div class="uxstudio-login-media">';

		if ( 'video' === $type && '' !== $video_url ) {
			echo '<video autoplay muted loop playsinline';
			if ( $image_url ) {
				echo ' poster="' . esc_url( $image_url ) . '"';
			}
			echo '><source src="' . esc_url( $video_url ) . '" type="video/mp4"></video>';
		} elseif ( 'youtube' === $type && '' !== $youtube ) {
			$video_id = $this->extract_youtube_id( $youtube );
			if ( $video_id ) {
				$src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $video_id )
					. '?autoplay=1&mute=1&loop=1&controls=0&showinfo=0&modestbranding=1&rel=0&iv_load_policy=3&playsinline=1'
					. '&playlist=' . rawurlencode( $video_id );
				echo '<iframe src="' . esc_url( $src ) . '" allow="autoplay; encrypted-media" title="' . esc_attr__( 'Background video', 'ux-studio' ) . '"></iframe>';
			} elseif ( $image_url ) {
				echo '<img src="' . esc_url( $image_url ) . '" alt="">';
			}
		} elseif ( $image_url ) {
			echo '<img src="' . esc_url( $image_url ) . '" alt="">';
		}

		if ( $show_logo && $logo_url ) {
			echo '<img class="uxstudio-login-media__logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '">';
		}

		echo '</div>';
	}

	/**
	 * Accepts a full YouTube URL (watch/short/embed) or a bare 11-char video ID.
	 */
	private function extract_youtube_id( string $input ): string {
		$input = trim( $input );
		if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $input ) ) {
			return $input;
		}
		if ( preg_match( '#(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})#', $input, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Pre-checks "Remember me" (if configured) and, in split layout, re-homes
	 * a few WordPress core nav links that live in fixed markup positions
	 * CSS alone can't reach: the "lost password" link moves next to the
	 * remember-me checkbox, and "back to site"/"privacy policy" are merged
	 * into one small footer row below the submit button - matching the
	 * Centrální aplikace login layout.
	 */
	public function print_footer_script(): void {
		$js = '';

		if ( $this->settings->get( 'login_remember_me_default', false ) ) {
			$js .= 'var c=document.getElementById("rememberme");if(c&&!c.checked){c.checked=true;}';
		}

		if ( $this->is_split() ) {
			$js .= '
				if (document.body.classList.contains("uxstudio-login-split")) {
					var form = document.getElementById("loginform");
					var forgetmenot = document.querySelector("#loginform p.forgetmenot");
					var nav = document.getElementById("nav");
					var backtoblog = document.getElementById("backtoblog");
					var privacy = document.querySelector(".privacy-policy-page-link");

					var lost = nav ? nav.querySelector(".wp-login-lost-password") : null;
					if (lost && forgetmenot) {
						lost.classList.add("uxstudio-login-lostpw");
						forgetmenot.appendChild(lost);
					}

					var footer = document.createElement("div");
					footer.className = "uxstudio-login-footer";
					[backtoblog, privacy, (nav && nav.textContent.trim() !== "") ? nav : null].forEach(function (el) {
						if (!el || !el.parentNode) { return; }
						if (footer.children.length) {
							var sep = document.createElement("span");
							sep.className = "uxstudio-login-footer__sep";
							sep.textContent = "·";
							footer.appendChild(sep);
						}
						footer.appendChild(el);
					});
					if (footer.children.length && form) {
						form.insertAdjacentElement("afterend", footer);
					}
					if (nav && nav.parentNode && nav.textContent.trim() === "") {
						nav.remove();
					}
				}
			';
		}

		if ( '' === $js ) {
			return;
		}

		echo '<script>(function(){' . $js . '})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
