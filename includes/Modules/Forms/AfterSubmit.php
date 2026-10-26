<?php
/**
 * What happens on the page after a successful submit: the thank-you screen (or a message above the form, or a
 * redirect) and the conversion tracking. Settings live in the form's `after_submit` and `tracking` keys
 * (Module::sanitize_settings()); this class sanitizes them, builds the server side of the response and the markup
 * and CSS of the thank-you box (shared by the public form and the live preview in the builder).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

final class AfterSubmit {

	/** replace = hide the form and show the thank-you box, message = box above the form (form stays), redirect = go to a page. */
	public const MODES = array( 'replace', 'message', 'redirect' );

	public const ICONS = array( 'check', 'none' );

	public const REDIRECT_TYPES = array( 'page', 'url' );

	/** Meta Pixel standard events offered in the builder (anything else must be a custom event). */
	public const META_STANDARD_EVENTS = array( 'Lead', 'CompleteRegistration', 'Contact', 'Subscribe', 'SubmitApplication', 'Schedule', 'StartTrial', 'Donate', 'FindLocation' );

	public const CUSTOM_JS_MAX = 20000;

	/** Default green of the success icon (the form itself has no accent colour setting). */
	public const DEFAULT_ACCENT = '#1a7f37';

	/** Animated check mark: the ring draws in first, then the tick (see done_css()). */
	public const ICON_SVG = '<svg viewBox="0 0 52 52" width="72" height="72" focusable="false"><circle class="uxs-fp-done__circle" cx="26" cy="26" r="24" fill="none" stroke-width="3"/><path class="uxs-fp-done__check" fill="none" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" d="M15 27l8 8 14-16"/></svg>';

	// =====================================================================
	// Settings: after_submit
	// =====================================================================

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'mode'             => 'replace',
			'title'            => '',
			'message'          => '',
			'icon'             => 'check',
			'accent'           => '',
			'again'            => false,
			'scroll'           => true,
			'redirect_type'    => 'page',
			'redirect_page_id' => 0,
			'redirect_url'     => '',
			'redirect_external' => false,
		);
	}

	/**
	 * Sanitizes the `after_submit` settings. Forms saved before 0.7.3 have none: their `success_text` becomes the
	 * message and their redirect action (if any) becomes the redirect mode, so nothing changes for the visitor.
	 *
	 * @param mixed  $raw             Raw `after_submit` value (null/absent = a legacy form).
	 * @param string $legacy_text     The old `success_text`.
	 * @param string $legacy_redirect URL of the old `redirect` action, '' = none.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $raw, string $legacy_text = '', string $legacy_redirect = '' ): array {
		$out = self::defaults();

		if ( ! is_array( $raw ) ) {
			$legacy_text = trim( $legacy_text );
			if ( '' !== $legacy_text ) {
				$out['message'] = self::text_to_html( $legacy_text );
			}
			if ( '' !== $legacy_redirect ) {
				$url = esc_url_raw( $legacy_redirect, array( 'http', 'https' ) );
				if ( '' !== $url ) {
					$out['mode']              = 'redirect';
					$out['redirect_type']     = 'url';
					$out['redirect_url']      = $url;
					$out['redirect_external'] = ! self::is_same_host( $url );
				}
			}
			return $out;
		}

		$mode = (string) ( $raw['mode'] ?? 'replace' );
		$icon = (string) ( $raw['icon'] ?? 'check' );
		$type = (string) ( $raw['redirect_type'] ?? 'page' );

		$out['mode']              = in_array( $mode, self::MODES, true ) ? $mode : 'replace';
		$out['title']             = mb_substr( sanitize_text_field( (string) ( $raw['title'] ?? '' ) ), 0, 120 );
		$out['message']           = self::sanitize_message( (string) ( $raw['message'] ?? '' ) );
		$out['icon']              = in_array( $icon, self::ICONS, true ) ? $icon : 'check';
		$out['accent']            = EmailTemplateRenderer::sanitize_hex( $raw['accent'] ?? '' );
		$out['again']             = ! empty( $raw['again'] );
		$out['scroll']            = ! array_key_exists( 'scroll', $raw ) || ! empty( $raw['scroll'] );
		$out['redirect_type']     = in_array( $type, self::REDIRECT_TYPES, true ) ? $type : 'page';
		$out['redirect_page_id']  = max( 0, (int) ( $raw['redirect_page_id'] ?? 0 ) );
		$out['redirect_external'] = ! empty( $raw['redirect_external'] );

		$url = esc_url_raw( trim( (string) ( $raw['redirect_url'] ?? '' ) ), array( 'http', 'https' ) );
		// Same site unless the editor explicitly allowed another one (an open redirect to a stranger is never a default).
		if ( '' !== $url && ! $out['redirect_external'] && ! self::is_same_host( $url ) ) {
			$url = '';
		}
		$out['redirect_url'] = $url;

		return $out;
	}

	/** Plain text (legacy success message) as HTML paragraphs. */
	public static function text_to_html( string $text ): string {
		$text = trim( str_replace( "\r", '', $text ) );
		if ( '' === $text ) {
			return '';
		}
		$paragraphs = preg_split( "/\n{2,}/", $text ) ?: array( $text );
		$html       = '';
		foreach ( $paragraphs as $p ) {
			$html .= '<p>' . nl2br( esc_html( trim( $p ) ) ) . '</p>';
		}
		return $html;
	}

	/** Plain text of a message, for the legacy `success_text` key. */
	public static function message_to_text( string $html ): string {
		$text = wp_strip_all_tags( preg_replace( '#</(p|div|h[1-6]|li)>|<br\s*/?>#i', "\n", $html ) ?? $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return sanitize_textarea_field( trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) ) );
	}

	/**
	 * The thank-you message is HTML typed in the WordPress editor. Explicit allowlist: text formatting, headings,
	 * lists, links and images. No script, iframe, form, object or event-handler attribute; `javascript:` and `data:`
	 * addresses are dropped (only http, https, mailto and tel survive in links, http and https in images).
	 */
	public static function sanitize_message( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$block   = array( 'style' => true, 'align' => true, 'dir' => true, 'class' => true );
		$allowed = array(
			'p'          => $block,
			'div'        => $block,
			'span'       => array( 'style' => true ),
			'br'         => array(),
			'hr'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			's'          => array(),
			'del'        => array(),
			'sub'        => array(),
			'sup'        => array(),
			'code'       => array(),
			'pre'        => array(),
			'blockquote' => array(),
			'h2'         => $block,
			'h3'         => $block,
			'h4'         => $block,
			'ul'         => array(),
			'ol'         => array( 'start' => true ),
			'li'         => array(),
			'a'          => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			),
			'img'        => array(
				'src'    => true,
				'alt'    => true,
				'title'  => true,
				'width'  => true,
				'height' => true,
				'class'  => true,
				'style'  => true,
				'loading' => true,
			),
		);

		// wp_kses() drops a forbidden tag but keeps its text, so whole blocks of active content go first.
		$html = preg_replace( '#<(script|style|iframe|object|embed|form|noscript|template|svg|math|textarea|select|button)\b[^>]*>.*?</\1\s*>#is', '', $html ) ?? '';

		add_filter( 'safe_style_css', array( EmailTemplateRenderer::class, 'email_safe_css' ), 99 );
		try {
			$clean = (string) wp_kses( $html, $allowed, array( 'http', 'https', 'mailto', 'tel' ) );
		} finally {
			remove_filter( 'safe_style_css', array( EmailTemplateRenderer::class, 'email_safe_css' ), 99 );
		}

		// Images over http(s) only (wp_kses lets mailto: / tel: through for every URL attribute).
		$clean = preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $m ): string {
				return 1 === preg_match( '#\bsrc\s*=\s*(["\'])(?:https?:)?//#i', $m[0] ) || 1 === preg_match( '#\bsrc\s*=\s*(["\'])/(?!/)#i', $m[0] ) ? $m[0] : '';
			},
			$clean
		) ?? '';

		if ( function_exists( 'wp_targeted_link_rel' ) ) {
			$clean = wp_targeted_link_rel( $clean );
		}
		return $clean;
	}

	/**
	 * Replaces `{field_key}` / `{form_title}` in an already sanitized message. The values are escaped here, on the
	 * server, and tags that match nothing disappear - the browser never builds HTML from a submitted value.
	 *
	 * @param array<string, mixed> $values Submitted values by field key.
	 */
	public static function merge( string $text, array $values, string $form_title, bool $html = true ): string {
		$flat = array( 'form_title' => $form_title );
		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$flat[ (string) $key ] = (string) $value;
			} elseif ( is_array( $value ) && array() !== $value && array_is_list( $value ) && array() === array_filter( $value, static fn( $v ) => ! is_scalar( $v ) ) ) {
				$flat[ (string) $key ] = implode( ', ', array_map( 'strval', $value ) );
			}
		}

		return (string) preg_replace_callback(
			'/\{([A-Za-z0-9_]+)\}/',
			static function ( array $m ) use ( $flat, $html ): string {
				$value = trim( str_replace( array( "\r", "\n" ), ' ', (string) ( $flat[ $m[1] ] ?? '' ) ) );
				return $html ? esc_html( $value ) : $value;
			},
			$text
		);
	}

	// =====================================================================
	// Redirect
	// =====================================================================

	private static function is_same_host( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return '' === $host || $host === $home;
	}

	/** Target of the redirect mode, '' when the mode is not redirect or the target is gone (a trashed page, an empty address). */
	public static function redirect_url( array $form ): string {
		$config = (array) ( $form['settings']['after_submit'] ?? array() );
		if ( 'redirect' !== ( $config['mode'] ?? '' ) ) {
			return '';
		}
		if ( 'page' === ( $config['redirect_type'] ?? 'page' ) ) {
			$id = (int) ( $config['redirect_page_id'] ?? 0 );
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				return (string) get_permalink( $id );
			}
			return '';
		}
		$url = (string) ( $config['redirect_url'] ?? '' );
		if ( '' === $url ) {
			return '';
		}
		if ( empty( $config['redirect_external'] ) ) {
			$url = wp_validate_redirect( $url, '' );
		}
		return $url;
	}

	// =====================================================================
	// Response and markup
	// =====================================================================

	/**
	 * The server half of a successful submit: everything the browser needs to show the thank-you, already merged
	 * and escaped.
	 *
	 * @param array $form       Form (title, settings).
	 * @param array $submission Result of Submissions::submit() ('values').
	 * @return array<string, mixed>
	 */
	public static function response( array $form, array $submission ): array {
		$config = array_merge( self::defaults(), (array) ( $form['settings']['after_submit'] ?? array() ) );
		$values = (array) ( $submission['values'] ?? array() );
		$title  = (string) ( $form['title'] ?? '' );

		$message = '' !== trim( (string) $config['message'] ) ? (string) $config['message'] : '<p>' . esc_html( self::default_message() ) . '</p>';
		$heading = '' !== trim( (string) $config['title'] ) ? (string) $config['title'] : self::default_title();

		return array(
			'mode'          => (string) $config['mode'],
			'title'         => self::merge( $heading, $values, $title, false ),
			'html'          => self::merge( $message, $values, $title, true ),
			'icon'          => (string) $config['icon'],
			'accent'        => (string) $config['accent'],
			'again'         => ! empty( $config['again'] ),
			'scroll'        => ! empty( $config['scroll'] ),
			'submission_id' => (int) ( $submission['id'] ?? 0 ),
			'form_title'    => $title,
		);
	}

	public static function default_title(): string {
		return __( 'Thank you', 'ux-studio' );
	}

	public static function default_message(): string {
		return __( 'Thank you, your submission has been received.', 'ux-studio' );
	}

	/**
	 * Markup of the thank-you box. The public form prints it empty and hidden and fills it after the submit; the
	 * builder preview prints it filled and animating.
	 *
	 * @param array<string, mixed>|null $data Result of response(), null = the empty hidden skeleton.
	 */
	public static function box_html( ?array $data, bool $again_label = true ): string {
		$visible = null !== $data;
		$data    = $data ?? array(
			'mode'   => 'replace',
			'title'  => '',
			'html'   => '',
			'icon'   => 'check',
			'accent' => '',
			'again'  => false,
		);

		$classes = 'uxs-fp-done' . ( 'message' === $data['mode'] ? ' uxs-fp-done--inline' : '' ) . ( 'none' === $data['icon'] ? ' uxs-fp-done--no-icon' : '' ) . ( $visible ? ' is-playing' : '' );
		$style   = '' !== (string) $data['accent'] ? ' style="--uxs-done:' . esc_attr( (string) $data['accent'] ) . '"' : '';

		$html  = '<div class="' . esc_attr( $classes ) . '" data-uxs-done tabindex="-1" role="status" aria-live="polite"' . $style . ( $visible ? '' : ' hidden' ) . '>';
		$html .= '<div class="uxs-fp-done__icon" data-uxs-done-icon aria-hidden="true">' . ( $visible && 'none' !== $data['icon'] ? self::ICON_SVG : '' ) . '</div>';
		$html .= '<div class="uxs-fp-done__body">';
		$html .= '<h3 class="uxs-fp-done__title" data-uxs-done-title tabindex="-1"' . ( $visible && '' !== (string) $data['title'] ? '' : ' hidden' ) . '>' . esc_html( (string) $data['title'] ) . '</h3>';
		$html .= '<div class="uxs-fp-done__text" data-uxs-done-text>' . ( $visible ? (string) $data['html'] : '' ) . '</div>';
		if ( $again_label ) {
			$html .= '<button type="button" class="uxs-fp-btn uxs-fp-btn--ghost uxs-fp-done__again" data-uxs-again' . ( $visible && ! empty( $data['again'] ) ? '' : ' hidden' ) . '>' . esc_html__( 'Send another response', 'ux-studio' ) . '</button>';
		}
		$html .= '</div></div>';
		return $html;
	}

	/** CSS of the thank-you box - printed with the form styles and inside the builder preview. */
	public static function done_css(): string {
		return <<<'CSS'
.uxs-fp-done{--uxs-done:#1a7f37;text-align:center;padding:48px 20px;outline:none;}
.uxs-fp-done[hidden],.uxs-fp-done [hidden]{display:none;}
.uxs-fp-done.is-playing{animation:uxs-fp-done-in .4s ease both;}
.uxs-fp-done__icon{width:88px;height:88px;margin:0 auto 20px;border-radius:50%;background:rgba(26,127,55,.12);background:color-mix(in srgb,var(--uxs-done) 14%,transparent);display:flex;align-items:center;justify-content:center;}
.uxs-fp-done__icon svg{width:72px;height:72px;display:block;}
.uxs-fp-done__circle{stroke:var(--uxs-done);stroke-dasharray:151;stroke-dashoffset:151;animation:uxs-fp-done-draw .6s ease .1s forwards;}
.uxs-fp-done__check{stroke:var(--uxs-done);stroke-dasharray:40;stroke-dashoffset:40;animation:uxs-fp-done-draw .4s ease .6s forwards;}
.uxs-fp-done__title{margin:0 0 10px;padding:0;font-size:26px;line-height:1.25;font-weight:700;color:inherit;outline:none;}
.uxs-fp-done__text{font-size:16px;line-height:1.55;color:inherit;}
.uxs-fp-done__text>:first-child{margin-top:0;}
.uxs-fp-done__text>:last-child{margin-bottom:0;}
.uxs-fp-done__text p{margin:0 0 .8em;}
.uxs-fp-done__text a{color:inherit;text-decoration:underline;}
.uxs-fp-done__text img{max-width:100%;height:auto;border-radius:6px;}
.uxs-fp-done__again{margin-top:20px;}
.uxs-fp-done--no-icon .uxs-fp-done__icon{display:none;}
.uxs-fp-done--no-icon{padding-top:32px;padding-bottom:32px;}
.uxs-fp-done--inline{display:flex;align-items:flex-start;gap:14px;text-align:left;padding:14px 16px;margin:0 0 18px;border-radius:8px;border:1px solid rgba(26,127,55,.35);border:1px solid color-mix(in srgb,var(--uxs-done) 35%,transparent);background:rgba(26,127,55,.08);background:color-mix(in srgb,var(--uxs-done) 8%,transparent);}
.uxs-fp-done--inline .uxs-fp-done__icon{flex:0 0 44px;width:44px;height:44px;margin:0;}
.uxs-fp-done--inline .uxs-fp-done__icon svg{width:36px;height:36px;}
.uxs-fp-done--inline .uxs-fp-done__title{font-size:17px;margin-bottom:4px;}
.uxs-fp-done--inline .uxs-fp-done__text{font-size:15px;}
.uxs-fp-done--inline.uxs-fp-done--no-icon{padding:14px 16px;}
@keyframes uxs-fp-done-draw{to{stroke-dashoffset:0;}}
@keyframes uxs-fp-done-in{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
@media (prefers-reduced-motion:reduce){.uxs-fp-done.is-playing{animation:none;}.uxs-fp-done__circle,.uxs-fp-done__check{animation:none;stroke-dashoffset:0;}}
CSS;
	}

	/**
	 * A full document for the builder's sandboxed preview iframe (no scripts: replaying the animation reloads it).
	 *
	 * @param string $scheme light|dark - the colour of the page the box would sit on.
	 */
	public static function preview_document( array $data, string $scheme ): string {
		$dark = 'dark' === $scheme;
		$css  = 'html,body{margin:0;}body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px;'
			. ( $dark ? 'background:#111827;color:#f3f4f6;' : 'background:#fff;color:#111827;' ) . '}'
			. '.uxs-fp-btn{border:0;border-radius:6px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;min-height:42px;background:#e5e7eb;color:#1f2937;}'
			. self::done_css();
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style></head><body>'
			. self::box_html( $data )
			. '</body></html>';
	}

	// =====================================================================
	// Settings: tracking
	// =====================================================================

	/** @return array<string, mixed> */
	public static function tracking_defaults(): array {
		return array(
			'datalayer' => true,
			'ga4'       => array( 'enabled' => false, 'event' => 'generate_lead' ),
			'ads'       => array( 'enabled' => false, 'send_to' => '', 'value' => null, 'currency' => 'CZK' ),
			'meta'      => array( 'enabled' => false, 'custom' => false, 'event' => 'Lead' ),
			'sklik'     => array( 'enabled' => false, 'id' => '', 'value' => null, 'consent' => 'auto', 'consent_fallback' => 0 ),
			'custom_js' => '',
		);
	}

	/**
	 * Sanitizes the `tracking` settings. `custom_js` is only length-limited here: whether the current user may
	 * change it is decided on the write path (resolve_custom_js()), not when stored settings are read back.
	 *
	 * @param mixed $raw Raw `tracking` value.
	 * @return array<string, mixed>
	 */
	public static function sanitize_tracking( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::tracking_defaults();

		$out['datalayer'] = ! array_key_exists( 'datalayer', $raw ) || ! empty( $raw['datalayer'] );

		$ga4                = (array) ( $raw['ga4'] ?? array() );
		$out['ga4']['enabled'] = ! empty( $ga4['enabled'] );
		$out['ga4']['event']   = self::event_name( $ga4['event'] ?? '', 40, 'generate_lead' );

		$ads                  = (array) ( $raw['ads'] ?? array() );
		$out['ads']['enabled']  = ! empty( $ads['enabled'] );
		$out['ads']['send_to']  = self::ads_send_to( $ads['send_to'] ?? '' );
		$out['ads']['value']    = self::amount( $ads['value'] ?? null );
		$out['ads']['currency'] = self::currency( $ads['currency'] ?? '' );

		$meta                  = (array) ( $raw['meta'] ?? array() );
		$custom                = ! empty( $meta['custom'] );
		$name                  = trim( (string) ( $meta['event'] ?? '' ) );
		$out['meta']['enabled'] = ! empty( $meta['enabled'] );
		if ( $custom && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $name ) ) {
			$out['meta']['custom'] = true;
			$out['meta']['event']  = $name;
		} elseif ( ! $custom && in_array( $name, self::META_STANDARD_EVENTS, true ) ) {
			$out['meta']['event'] = $name;
		}

		$sklik                  = (array) ( $raw['sklik'] ?? array() );
		$out['sklik']['enabled'] = ! empty( $sklik['enabled'] );
		$id                     = trim( (string) ( $sklik['id'] ?? '' ) );
		$out['sklik']['id']      = 1 === preg_match( '/^\d{1,12}$/', $id ) ? $id : '';
		$out['sklik']['value']   = self::amount( $sklik['value'] ?? null );
		$out['sklik']['consent']          = self::sklik_consent_mode( $sklik );
		$out['sklik']['consent_fallback'] = isset( $sklik['consent_fallback'] ) && 1 === (int) $sklik['consent_fallback'] ? 1 : 0;

		$js = str_replace( "\0", '', (string) ( $raw['custom_js'] ?? '' ) );
		$out['custom_js'] = mb_substr( $js, 0, self::CUSTOM_JS_MAX );

		return $out;
	}

	/**
	 * Where the Sklik consent flag comes from: 'auto' (the visitor's cookie banner, read in the browser when the
	 * conversion fires) or a fixed 1 / 0.
	 *
	 * Migration (0.7.4): before, the value was always stored as 1 or 0. A form that has Sklik switched on keeps its
	 * fixed value, so nothing changes silently. For a form with Sklik off the stored 1 was only the old default, not a
	 * decision, so it becomes 'auto'; a stored 0 and a missing key (new form) are handled the same way: 0 stays 0,
	 * missing is 'auto'.
	 *
	 * @param array<string, mixed> $sklik Raw sklik settings.
	 * @return string|int 'auto' | 1 | 0
	 */
	private static function sklik_consent_mode( array $sklik ) {
		if ( ! array_key_exists( 'consent', $sklik ) || 'auto' === $sklik['consent'] ) {
			return 'auto';
		}
		if ( 0 === (int) $sklik['consent'] ) {
			return 0;
		}
		return empty( $sklik['enabled'] ) ? 'auto' : 1;
	}

	/** An event name: a letter, then letters, digits and underscores. Invalid input falls back to the default. */
	public static function event_name( $value, int $max, string $fallback ): string {
		$value = trim( (string) $value );
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,' . ( $max - 1 ) . '}$/', $value ) ? $value : $fallback;
	}

	/** Google Ads conversion target `AW-123456789/AbC-dEfGhIj`, '' when the format is wrong. */
	public static function ads_send_to( $value ): string {
		$value = trim( (string) $value );
		return 1 === preg_match( '/^AW-\d+\/[\w-]+$/', $value ) ? $value : '';
	}

	/** @return float|null */
	private static function amount( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$n = (float) $value;
		return $n >= 0 && $n < 1e9 ? round( $n, 2 ) : null;
	}

	private static function currency( $value ): string {
		$value = strtoupper( trim( (string) $value ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $value ) ? $value : 'CZK';
	}

	/**
	 * Who may write the custom script: it runs on public pages, so it needs the same trust as pasting a script
	 * into a post - the `unfiltered_html` capability, and on a multisite network only a super admin.
	 */
	public static function user_can_edit_script(): bool {
		if ( is_multisite() && ! is_super_admin() ) {
			return false;
		}
		return current_user_can( 'unfiltered_html' );
	}

	/**
	 * Write-path guard: a user without the right keeps the stored script untouched, whatever they sent.
	 */
	public static function resolve_custom_js( string $incoming, string $existing, bool $can_edit ): string {
		return $can_edit ? $incoming : $existing;
	}

	/**
	 * The `tracking` settings with the script protected (see resolve_custom_js()).
	 *
	 * @param mixed $incoming Raw tracking settings from the client.
	 * @param mixed $existing Stored tracking settings ('' script for a new form).
	 * @return array<string, mixed>
	 */
	public static function guard_tracking( $incoming, $existing, bool $can_edit ): array {
		$incoming = is_array( $incoming ) ? $incoming : array();
		$existing = is_array( $existing ) ? $existing : array();
		$incoming['custom_js'] = self::resolve_custom_js( (string) ( $incoming['custom_js'] ?? '' ), (string) ( $existing['custom_js'] ?? '' ), $can_edit );
		return $incoming;
	}

	// =====================================================================
	// Front end
	// =====================================================================

	/**
	 * What the browser may know about the tracking setup (the custom script is never part of it).
	 *
	 * @return array<string, mixed>
	 */
	public static function client_tracking( array $tracking ): array {
		$t = array_replace_recursive( self::tracking_defaults(), $tracking );
		return array(
			'datalayer' => ! empty( $t['datalayer'] ),
			'ga4'       => ! empty( $t['ga4']['enabled'] ) ? array( 'event' => (string) $t['ga4']['event'] ) : null,
			'ads'       => ! empty( $t['ads']['enabled'] ) && '' !== $t['ads']['send_to'] ? array(
				'sendTo'   => (string) $t['ads']['send_to'],
				'value'    => $t['ads']['value'],
				'currency' => (string) $t['ads']['currency'],
			) : null,
			'meta'      => ! empty( $t['meta']['enabled'] ) ? array(
				'method' => ! empty( $t['meta']['custom'] ) ? 'trackCustom' : 'track',
				'event'  => (string) $t['meta']['event'],
			) : null,
			'sklik'     => ! empty( $t['sklik']['enabled'] ) && '' !== $t['sklik']['id'] ? array(
				'id'      => (int) $t['sklik']['id'],
				'value'   => $t['sklik']['value'],
				'consent'  => 'auto' === $t['sklik']['consent'] ? 'auto' : (int) $t['sklik']['consent'],
				'fallback' => (int) $t['sklik']['consent_fallback'],
			) : null,
		);
	}

	/**
	 * The custom script as an inline script that only REGISTERS a function (window.uxstudioFormHooks[formId]); the
	 * runtime calls it after a successful submit. No eval / new Function, so a CSP without 'unsafe-eval' is fine.
	 * A strict CSP allows it through a hash (hook_script_body() + CspInlineScripts, PLAN.md 20.14).
	 */
	public static function hook_script( int $form_id, string $code ): string {
		$body = self::hook_script_body( $form_id, $code );
		return '' === $body ? '' : '<script>' . $body . "</script>\n";
	}

	/**
	 * The exact text between <script> and </script> of the hook script. The printed element and the CSP hash are both
	 * made from this one function, so they cannot drift apart.
	 */
	public static function hook_script_body( int $form_id, string $code ): string {
		if ( '' === trim( $code ) ) {
			return '';
		}
		return "\n(window.uxstudioFormHooks=window.uxstudioFormHooks||{})[" . (int) $form_id . "]=function(detail){\n" . self::escape_script( $code ) . "\n};\n";
	}

	/** Keeps script text from closing its own element or opening an HTML comment. */
	public static function escape_script( string $code ): string {
		$code = (string) preg_replace( '#</(script)#i', '<\\/$1', $code );
		return (string) preg_replace( '/<!--/', '<\\!--', $code );
	}
}
