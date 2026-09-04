<?php
/**
 * Exit Popup module - shows a configurable email/CTA popup on exit-intent.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExitPopup;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Full port of the legacy exit-popup module: appearance/CTA/image config,
 * an optional autoresponder e-mail, cookie/session frequency control, five
 * exit-detection modes (mouse-leave, tab change, window blur, idle, scroll-up)
 * plus a time-on-page trigger, and URL/post-type targeting. A public REST
 * endpoint captures the e-mail (IP rate-limited, nonce guarded) and a CSV export
 * of the captured e-mails is available to admins. No raw IPs are ever stored
 * (GDPR) - only a salted hash.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables(
			'exit-popup',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_exit_popup_emails (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						email VARCHAR(255) NOT NULL DEFAULT '',
						page_url VARCHAR(500) NOT NULL DEFAULT '',
						ip_hash VARCHAR(64) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_post_uxstudio_exit_popup_export', array( $this, 'export_csv' ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab. Rendered
	 * as a flat list by <SettingsFields>; ordering below groups the fields by
	 * intent (content, appearance, behavior, detection, e-mail, targeting).
	 */
	public function settings_schema(): array {
		$post_type_options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$post_type_options[ $post_type->name ] = $post_type->label;
		}

		return array(
			// --- Content ---
			array(
				'key'     => 'headline',
				'type'    => 'text',
				'label'   => __( 'Headline', 'ux-studio' ),
				'default' => __( "Wait, don't go!", 'ux-studio' ),
			),
			array(
				'key'     => 'body',
				'type'    => 'richtext',
				'label'   => __( 'Body', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'   => 'image',
				'type'  => 'media',
				'label' => __( 'Image', 'ux-studio' ),
				'help'  => __( 'Optional image shown above the popup content.', 'ux-studio' ),
			),
			// --- Call to action ---
			array(
				'key'     => 'enable_cta',
				'type'    => 'toggle',
				'label'   => __( 'Show CTA button', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'cta_text',
				'type'    => 'text',
				'label'   => __( 'CTA button label', 'ux-studio' ),
				'default' => __( 'Stay on page', 'ux-studio' ),
			),
			array(
				'key'     => 'cta_url',
				'type'    => 'text',
				'label'   => __( 'CTA button URL', 'ux-studio' ),
				'help'    => __( 'Use # to simply close the popup.', 'ux-studio' ),
				'default' => '#',
			),
			array(
				'key'     => 'cta_new_tab',
				'type'    => 'toggle',
				'label'   => __( 'Open CTA in a new tab', 'ux-studio' ),
				'default' => false,
			),
			// --- Appearance ---
			array(
				'key'     => 'bg_color',
				'type'    => 'color',
				'label'   => __( 'Background colour', 'ux-studio' ),
				'default' => '#ffffff',
			),
			array(
				'key'     => 'title_color',
				'type'    => 'color',
				'label'   => __( 'Headline colour', 'ux-studio' ),
				'default' => '#1f2937',
			),
			array(
				'key'     => 'text_color',
				'type'    => 'color',
				'label'   => __( 'Text colour', 'ux-studio' ),
				'default' => '#4b5563',
			),
			array(
				'key'     => 'cta_bg_color',
				'type'    => 'color',
				'label'   => __( 'CTA background colour', 'ux-studio' ),
				'default' => '#2563eb',
			),
			array(
				'key'     => 'cta_text_color',
				'type'    => 'color',
				'label'   => __( 'CTA text colour', 'ux-studio' ),
				'default' => '#ffffff',
			),
			array(
				'key'     => 'overlay_opacity',
				'type'    => 'number',
				'label'   => __( 'Overlay opacity (%)', 'ux-studio' ),
				'default' => 60,
			),
			// --- Behaviour ---
			array(
				'key'     => 'delay_seconds',
				'type'    => 'number',
				'label'   => __( 'Delay before enabling detection (seconds)', 'ux-studio' ),
				'default' => 2,
			),
			array(
				'key'     => 'frequency_mode',
				'type'    => 'select',
				'label'   => __( 'Show frequency', 'ux-studio' ),
				'options' => array(
					'session' => __( 'Once per browser session', 'ux-studio' ),
					'cookie'  => __( 'Once every N days (cookie)', 'ux-studio' ),
					'always'  => __( 'Every page view', 'ux-studio' ),
				),
				'default' => 'session',
			),
			array(
				'key'     => 'cookie_days',
				'type'    => 'number',
				'label'   => __( 'Cookie lifetime (days)', 'ux-studio' ),
				'help'    => __( 'Used when frequency is "Once every N days".', 'ux-studio' ),
				'default' => 7,
			),
			// --- Detection ---
			array(
				'key'     => 'detect_mouse_leave',
				'type'    => 'toggle',
				'label'   => __( 'Detect: mouse leaves the window (exit-intent)', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'mouse_leave_delay',
				'type'    => 'number',
				'label'   => __( 'Mouse-leave delay (ms)', 'ux-studio' ),
				'default' => 500,
			),
			array(
				'key'     => 'detect_tab_change',
				'type'    => 'toggle',
				'label'   => __( 'Detect: switch to another browser tab', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'detect_window_blur',
				'type'    => 'toggle',
				'label'   => __( 'Detect: browser window loses focus', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'detect_idle',
				'type'    => 'toggle',
				'label'   => __( 'Detect: user inactivity', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'idle_timeout',
				'type'    => 'number',
				'label'   => __( 'Inactivity timeout (seconds)', 'ux-studio' ),
				'default' => 30,
			),
			array(
				'key'     => 'detect_scroll_up',
				'type'    => 'toggle',
				'label'   => __( 'Detect: fast scroll up (mobile exit signal)', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'scroll_up_desktop',
				'type'    => 'number',
				'label'   => __( 'Scroll-up threshold desktop (px)', 'ux-studio' ),
				'default' => 400,
			),
			array(
				'key'     => 'scroll_up_mobile',
				'type'    => 'number',
				'label'   => __( 'Scroll-up threshold mobile (px)', 'ux-studio' ),
				'default' => 200,
			),
			array(
				'key'     => 'time_on_page',
				'type'    => 'number',
				'label'   => __( 'Show after N seconds on page (0 = off)', 'ux-studio' ),
				'default' => 0,
			),
			// --- E-mail capture ---
			array(
				'key'     => 'enable_email',
				'type'    => 'toggle',
				'label'   => __( 'Show e-mail capture field', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'email_placeholder',
				'type'    => 'text',
				'label'   => __( 'E-mail field placeholder', 'ux-studio' ),
				'default' => __( 'Email address', 'ux-studio' ),
			),
			array(
				'key'     => 'email_button_text',
				'type'    => 'text',
				'label'   => __( 'Submit button label', 'ux-studio' ),
				'default' => __( 'Subscribe', 'ux-studio' ),
			),
			array(
				'key'     => 'email_success_message',
				'type'    => 'text',
				'label'   => __( 'Success message', 'ux-studio' ),
				'default' => __( 'Thanks! Check your inbox soon.', 'ux-studio' ),
			),
			array(
				'key'     => 'enable_autoresponder',
				'type'    => 'toggle',
				'label'   => __( 'Send autoresponder e-mail to the subscriber', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'autoresponder_subject',
				'type'    => 'text',
				'label'   => __( 'Autoresponder subject', 'ux-studio' ),
				'default' => __( 'Thanks for your interest', 'ux-studio' ),
			),
			array(
				'key'     => 'autoresponder_body',
				'type'    => 'richtext',
				'label'   => __( 'Autoresponder body', 'ux-studio' ),
				'default' => '',
			),
			// --- Targeting ---
			array(
				'key'     => 'enabled_post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Show on', 'ux-studio' ),
				'help'    => __( 'Post types on which the exit popup can appear.', 'ux-studio' ),
				'options' => $post_type_options,
				'default' => array( 'post', 'page' ),
			),
			array(
				'key'     => 'targeting_mode',
				'type'    => 'select',
				'label'   => __( 'URL targeting', 'ux-studio' ),
				'options' => array(
					'everywhere' => __( 'Everywhere allowed above', 'ux-studio' ),
					'include'    => __( 'Only on matching URLs', 'ux-studio' ),
					'exclude'    => __( 'Everywhere except matching URLs', 'ux-studio' ),
				),
				'default' => 'everywhere',
			),
			array(
				'key'     => 'include_pages',
				'type'    => 'textarea',
				'label'   => __( 'Include URLs / page IDs', 'ux-studio' ),
				'help'    => __( 'One per line: a page ID or a URL fragment (e.g. /contact).', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'exclude_pages',
				'type'    => 'textarea',
				'label'   => __( 'Exclude URLs / page IDs', 'ux-studio' ),
				'help'    => __( 'One per line: a page ID or a URL fragment (e.g. /checkout).', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hide_for_logged_in',
				'type'    => 'toggle',
				'label'   => __( 'Hide for logged-in users', 'ux-studio' ),
				'default' => false,
			),
		);
	}

	/**
	 * Whether the popup may render on the current request. Runs post-type,
	 * logged-in and URL targeting checks entirely server-side.
	 */
	private function should_show(): bool {
		$enabled_post_types = (array) $this->settings->get( 'enabled_post_types', array( 'post', 'page' ) );
		if ( empty( $enabled_post_types ) || ! is_singular( $enabled_post_types ) ) {
			return false;
		}

		if ( $this->settings->get( 'hide_for_logged_in', false ) && is_user_logged_in() ) {
			return false;
		}

		$mode = (string) $this->settings->get( 'targeting_mode', 'everywhere' );
		if ( 'include' === $mode ) {
			return $this->current_url_in_list( 'include_pages' );
		}
		if ( 'exclude' === $mode ) {
			return ! $this->current_url_in_list( 'exclude_pages' );
		}

		return true;
	}

	/**
	 * Whether the current request matches any entry (page ID or URL fragment) in
	 * a newline-separated targeting list setting.
	 *
	 * @param string $setting_key Setting key holding the newline-separated list.
	 */
	private function current_url_in_list( string $setting_key ): bool {
		$raw = (string) $this->settings->get( $setting_key, '' );
		if ( '' === trim( $raw ) ) {
			return false;
		}

		$entries     = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		$current_id  = get_queried_object_id();
		$current_url = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';

		foreach ( $entries as $entry ) {
			if ( is_numeric( $entry ) && (int) $entry === (int) $current_id ) {
				return true;
			}
			if ( ! is_numeric( $entry ) && '' !== $current_url && false !== strpos( $current_url, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Conditionally enqueue the popup script, configured entirely via a localized
	 * config object (appearance, detection modes, frequency, e-mail copy).
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! $this->should_show() ) {
			return;
		}

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_script(
			'uxstudio-exit-popup',
			plugins_url( 'assets/exit-popup.js', __FILE__ ),
			array(),
			$version,
			true
		);

		$image_id  = (int) $this->settings->get( 'image', 0 );
		$image_url = $image_id ? (string) wp_get_attachment_url( $image_id ) : '';
		$image_alt = $image_id ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';

		$overlay_opacity = (int) $this->settings->get( 'overlay_opacity', 60 );
		$overlay_opacity = max( 0, min( 100, $overlay_opacity ) );

		wp_localize_script(
			'uxstudio-exit-popup',
			'uxStudioExitPopup',
			array(
				'headline'       => (string) $this->settings->get( 'headline', __( "Wait, don't go!", 'ux-studio' ) ),
				'body'           => (string) $this->settings->get( 'body', '' ),
				'imageUrl'       => $image_url ? esc_url_raw( $image_url ) : '',
				'imageAlt'       => $image_alt,
				'cta'            => array(
					'enabled' => (bool) $this->settings->get( 'enable_cta', false ),
					'text'    => (string) $this->settings->get( 'cta_text', '' ),
					'url'     => esc_url_raw( (string) $this->settings->get( 'cta_url', '#' ) ) ?: '#',
					'newTab'  => (bool) $this->settings->get( 'cta_new_tab', false ),
				),
				'colors'         => array(
					'bg'       => $this->color( 'bg_color', '#ffffff' ),
					'title'    => $this->color( 'title_color', '#1f2937' ),
					'text'     => $this->color( 'text_color', '#4b5563' ),
					'ctaBg'    => $this->color( 'cta_bg_color', '#2563eb' ),
					'ctaText'  => $this->color( 'cta_text_color', '#ffffff' ),
					'overlay'  => $overlay_opacity / 100,
				),
				'delaySeconds'   => (int) $this->settings->get( 'delay_seconds', 2 ),
				'frequency'      => (string) $this->settings->get( 'frequency_mode', 'session' ),
				'cookieDays'     => (int) $this->settings->get( 'cookie_days', 7 ),
				'detection'      => array(
					'mouseLeave'      => (bool) $this->settings->get( 'detect_mouse_leave', true ),
					'mouseLeaveDelay' => (int) $this->settings->get( 'mouse_leave_delay', 500 ),
					'tabChange'       => (bool) $this->settings->get( 'detect_tab_change', false ),
					'windowBlur'      => (bool) $this->settings->get( 'detect_window_blur', false ),
					'idle'            => (bool) $this->settings->get( 'detect_idle', false ),
					'idleTimeout'     => (int) $this->settings->get( 'idle_timeout', 30 ),
					'scrollUp'        => (bool) $this->settings->get( 'detect_scroll_up', false ),
					'scrollUpDesktop' => (int) $this->settings->get( 'scroll_up_desktop', 400 ),
					'scrollUpMobile'  => (int) $this->settings->get( 'scroll_up_mobile', 200 ),
					'timeOnPage'      => (int) $this->settings->get( 'time_on_page', 0 ),
				),
				'emailEnabled'   => (bool) $this->settings->get( 'enable_email', true ),
				'restUrl'        => esc_url_raw( rest_url( 'uxstudio/v1/exit-popup/subscribe' ) ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'emailLabel'     => (string) $this->settings->get( 'email_placeholder', __( 'Email address', 'ux-studio' ) ),
				'submitLabel'    => (string) $this->settings->get( 'email_button_text', __( 'Subscribe', 'ux-studio' ) ),
				'closeLabel'     => __( 'Close', 'ux-studio' ),
				'successMessage' => (string) $this->settings->get( 'email_success_message', __( 'Thanks! Check your inbox soon.', 'ux-studio' ) ),
				'errorMessage'   => __( 'Something went wrong, please try again.', 'ux-studio' ),
			)
		);
	}

	/**
	 * Read a colour setting, falling back to a default when unset/invalid.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Fallback hex colour.
	 */
	private function color( string $key, string $fallback ): string {
		$value = sanitize_hex_color( (string) $this->settings->get( $key, $fallback ) );
		return $value ? $value : $fallback;
	}

	/**
	 * Whether an autoresponder should be sent for a new capture.
	 */
	public function autoresponder_enabled(): bool {
		return (bool) $this->settings->get( 'enable_email', true )
			&& (bool) $this->settings->get( 'enable_autoresponder', false );
	}

	/**
	 * Send the configured autoresponder e-mail to a captured address. No-op when
	 * subject or body are empty. Called from the public capture endpoint.
	 *
	 * @param string $email Recipient address (already validated).
	 */
	public function send_autoresponder( string $email ): void {
		if ( ! is_email( $email ) ) {
			return;
		}

		$subject = trim( (string) $this->settings->get( 'autoresponder_subject', '' ) );
		$body    = (string) $this->settings->get( 'autoresponder_body', '' );
		if ( '' === $subject || '' === trim( wp_strip_all_tags( $body ) ) ) {
			return;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $email, $subject, wpautop( wp_kses_post( $body ) ), $headers );
	}

	/**
	 * Last 200 subscriber rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_subscribers(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, email, page_url FROM {$wpdb->prefix}uxstudio_exit_popup_emails ORDER BY id DESC LIMIT 200",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Stream a CSV export of all captured subscribers (admin-post handler).
	 */
	public function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ) );
		}

		check_admin_referer( 'uxstudio_exit_popup_export' );

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT email, page_url, created_at FROM {$wpdb->prefix}uxstudio_exit_popup_emails ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		ActivityLog::log( 'exit-popup', 'export', 'exit_popup_emails', 0, array( 'rows' => count( $rows ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="exit-popup-subscribers.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'email', 'page_url', 'created_at' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					(string) $row['email'],
					(string) $row['page_url'],
					(string) $row['created_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}
}
