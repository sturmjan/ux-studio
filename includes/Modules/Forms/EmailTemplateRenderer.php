<?php
/**
 * Inline-styled HTML email templates for the "email" post-submit action.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's first shared HTML email layer (nothing else ships one yet -
 * see PLAN.md 20.9). Every style is inline (`style="..."`) since email
 * clients don't reliably support `<style>` blocks or external CSS, and the
 * layout is built from tables with width / bgcolor / align attributes next to
 * the CSS so that Outlook (Word rendering engine) draws it the same way as
 * Gmail or Apple Mail. Three built-in templates share one layout function and
 * only vary the default accent colour, keeping the actual markup (and its
 * escaping) in one place.
 */
final class EmailTemplateRenderer {

	public const TEMPLATES = array( 'minimal', 'card', 'branded' );

	public const LOGO_POSITIONS = array( 'left', 'center', 'right' );

	public const LOGO_WIDTH_MIN     = 40;
	public const LOGO_WIDTH_MAX     = 400;
	public const LOGO_WIDTH_DEFAULT = 160;

	/** WCAG AA contrast for normal text. */
	public const MIN_CONTRAST = 4.5;

	private const ACCENTS = array(
		'minimal' => '#1f2937',
		'card'    => '#2563eb',
		'branded' => '#7c3aed',
	);

	/** URL schemes an email body link may use. */
	private const LINK_PROTOCOLS = array( 'http', 'https', 'mailto', 'tel' );

	public static function is_valid_template( string $template ): bool {
		return in_array( $template, self::TEMPLATES, true );
	}

	/** Default header / link / button colour of a built-in template. */
	public static function default_accent( string $template ): string {
		return self::ACCENTS[ self::is_valid_template( $template ) ? $template : 'branded' ];
	}

	// =====================================================================
	// Sanitization
	// =====================================================================

	/**
	 * Sanitizes a user-authored "custom" email template (PLAN.md 20.11/F3) -
	 * a complete hand-written HTML document, not a post-content fragment, so
	 * `wp_kses_post()` alone is too strict (it drops the outer
	 * html/head/body/style shell and never allows the `style` attribute
	 * inline styling requires for HTML email clients). Still an ALLOWLIST,
	 * not a blocklist: `<script>`, `<iframe>`, `<object>`/`<embed>` and
	 * external `<link>` stylesheets stay excluded because they are never
	 * added to the base set, exactly like `wp_kses_post()` itself.
	 */
	public static function sanitize_custom_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( $allowed as $tag => $attrs ) {
			$allowed[ $tag ]['style'] = true;
		}
		$allowed['html']  = array( 'lang' => true );
		$allowed['head']  = array();
		$allowed['body']  = array( 'style' => true );
		$allowed['meta']  = array( 'charset' => true, 'name' => true, 'content' => true );
		$allowed['title'] = array();
		$allowed['style'] = array( 'type' => true );

		return (string) wp_kses( $html, $allowed );
	}

	/**
	 * Sanitizes the WYSIWYG "Message" of a notification (TinyMCE output).
	 * An explicit, small ALLOWLIST of what an email can carry: text
	 * formatting, headings, lists, links and images. No script, iframe,
	 * form, object, event-handler attribute; `style` goes through core's
	 * safecss filter (only whitelisted properties survive) and URL schemes are
	 * limited to http, https, mailto and tel (so `javascript:` / `data:` in a
	 * link or an image src is dropped).
	 */
	public static function sanitize_message( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$block  = array( 'style' => true, 'align' => true, 'dir' => true );
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
			'pre'        => array( 'style' => true ),
			'blockquote' => array( 'style' => true ),
			'h1'         => $block,
			'h2'         => $block,
			'h3'         => $block,
			'h4'         => $block,
			'ul'         => array( 'style' => true ),
			'ol'         => array( 'style' => true, 'start' => true ),
			'li'         => array( 'style' => true ),
			'a'          => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
				'style'  => true,
			),
			'img'        => array(
				'src'    => true,
				'alt'    => true,
				'title'  => true,
				'width'  => true,
				'height' => true,
				'style'  => true,
				'border' => true,
				'align'  => true,
			),
		);

		// wp_kses() drops a forbidden tag but keeps its text, so the whole blocks go first.
		$html = preg_replace( '#<(script|style|iframe|object|embed|form|noscript|template|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html ) ?? '';

		// Core's safecss filter admits far more CSS properties than a mail needs (position, ...).
		add_filter( 'safe_style_css', array( self::class, 'email_safe_css' ), 99 );
		try {
			return (string) wp_kses( $html, $allowed, self::LINK_PROTOCOLS );
		} finally {
			remove_filter( 'safe_style_css', array( self::class, 'email_safe_css' ), 99 );
		}
	}

	/**
	 * CSS properties an e-mail message may use inline.
	 *
	 * @param mixed $properties Properties core allows.
	 * @return string[]
	 */
	public static function email_safe_css( $properties ): array {
		unset( $properties );
		return array(
			'color',
			'background-color',
			'font-size',
			'font-weight',
			'font-style',
			'font-family',
			'text-align',
			'text-decoration',
			'line-height',
			'letter-spacing',
			'vertical-align',
			'width',
			'max-width',
			'height',
			'margin',
			'margin-top',
			'margin-right',
			'margin-bottom',
			'margin-left',
			'padding',
			'padding-top',
			'padding-right',
			'padding-bottom',
			'padding-left',
			'border',
			'border-top',
			'border-right',
			'border-bottom',
			'border-left',
			'border-color',
			'border-style',
			'border-width',
			'border-radius',
			'list-style-type',
			'display',
		);
	}

	/**
	 * Final pass over an HTML body AFTER merge tags are filled in: a link may
	 * only keep an http / https / mailto / tel href (a merged field value must
	 * never produce a `javascript:` link), an image must be an absolute http(s)
	 * URL (relative paths are made absolute, anything else, e.g. `data:`, is
	 * dropped) and gets the attributes email clients need. When $link_color is
	 * given, every link is coloured with it inline.
	 */
	public static function finalize_html( string $html, string $link_color = '' ): string {
		if ( '' === $html || ( false === stripos( $html, '<a' ) && false === stripos( $html, '<img' ) ) ) {
			return $html;
		}

		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$tag = $p->get_tag();
			if ( 'A' === $tag ) {
				$href = trim( (string) $p->get_attribute( 'href' ) );
				if ( '' !== $href && ( '/' === $href[0] ) ) {
					// A link to a page of this site: mails need the full address.
					$href = self::absolute_url( $href );
				}
				// Only a real scheme: a stripped `javascript:` leaves `alert(1)` behind, which esc_url() would turn into http://alert(1).
				$safe = ( '' !== $href && 1 === preg_match( '#^(https?://|mailto:|tel:)#i', $href ) ) ? esc_url( $href, self::LINK_PROTOCOLS ) : '';
				if ( '' === $safe ) {
					$p->remove_attribute( 'href' );
				} else {
					$p->set_attribute( 'href', $safe );
				}
				if ( '' !== $link_color && '' !== $safe ) {
					$p->set_attribute( 'style', self::merge_style( (string) $p->get_attribute( 'style' ), 'color:' . $link_color . ';' ) );
				}
			} elseif ( 'IMG' === $tag ) {
				$src = self::absolute_url( (string) $p->get_attribute( 'src' ) );
				if ( '' === $src ) {
					// Not a usable image: blank the src so no request is made, and say so in the alt text.
					$p->remove_attribute( 'src' );
					continue;
				}
				$p->set_attribute( 'src', $src );
				$p->set_attribute( 'border', '0' );
				if ( null === $p->get_attribute( 'alt' ) ) {
					$p->set_attribute( 'alt', '' );
				}
				$p->set_attribute(
					'style',
					self::merge_style( (string) $p->get_attribute( 'style' ), 'max-width:100%;height:auto;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;' )
				);
			}
		}
		return $p->get_updated_html();
	}

	/** Absolute http(s) URL, or '' when the value can not be one. */
	private static function absolute_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( '/' === $url[0] ) {
			$url = home_url( $url );
		}
		// Anything else without an http(s) scheme (a bare `alert(1)`, `data:`, `ftp:`) is no usable URL.
		if ( 1 !== preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/** Append CSS declarations to an existing style attribute, later ones winning. */
	private static function merge_style( string $existing, string $extra ): string {
		$existing = trim( $existing );
		if ( '' !== $existing && ';' !== substr( $existing, -1 ) ) {
			$existing .= ';';
		}
		return $existing . $extra;
	}

	// =====================================================================
	// Colour helpers
	// =====================================================================

	/**
	 * Validate a #rgb / #rrggbb colour. Returns it lower-cased in the long
	 * form, or '' when it is not a hex colour.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function sanitize_hex( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		if ( 1 === preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m ) ) {
			return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		}
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '';
	}

	/** WCAG relative luminance of a #rrggbb colour. */
	public static function luminance( string $hex ): float {
		$hex = self::sanitize_hex( $hex );
		if ( '' === $hex ) {
			return 0.0;
		}
		$channels = array();
		foreach ( array( 1, 3, 5 ) as $offset ) {
			$c          = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/** WCAG contrast ratio (1 to 21) of two #rrggbb colours. */
	public static function contrast_ratio( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/** White or near-black, whichever reads better on the given background. */
	public static function readable_text_color( string $background ): string {
		return self::contrast_ratio( '#ffffff', $background ) >= self::contrast_ratio( '#111827', $background ) ? '#ffffff' : '#111827';
	}

	/**
	 * Contrast problems of an action's colours, for the editor to show next
	 * to the preview. The header text colour is chosen automatically, so only
	 * a mid-tone header can fall short; the link colour is drawn on the white
	 * body.
	 *
	 * @param string $header_bg   Resolved header background.
	 * @param string $link_color  Resolved link colour.
	 * @return array<int, array{code:string,message:string}>
	 */
	public static function contrast_warnings( string $header_bg, string $link_color ): array {
		$warnings = array();

		$text  = self::readable_text_color( $header_bg );
		$ratio = self::contrast_ratio( $text, $header_bg );
		if ( $ratio < self::MIN_CONTRAST ) {
			$warnings[] = array(
				'code'    => 'header_contrast',
				'message' => sprintf(
					/* translators: %s: contrast ratio such as 3.4. */
					__( 'The header text has low contrast on this background (%s:1, at least 4.5:1 is recommended). Pick a lighter or darker header color.', 'ux-studio' ),
					number_format_i18n( $ratio, 1 )
				),
			);
		}

		$ratio = self::contrast_ratio( $link_color, '#ffffff' );
		if ( $ratio < self::MIN_CONTRAST ) {
			$warnings[] = array(
				'code'    => 'link_contrast',
				'message' => sprintf(
					/* translators: %s: contrast ratio such as 3.4. */
					__( 'The link color has low contrast on the white message body (%s:1, at least 4.5:1 is recommended). Pick a darker link color.', 'ux-studio' ),
					number_format_i18n( $ratio, 1 )
				),
			);
		}

		return $warnings;
	}

	// =====================================================================
	// Rendering
	// =====================================================================

	/**
	 * Per-action layout options (header, logo, colours) with defaults filled in
	 * from the chosen template. The single place both the real send and the
	 * editor preview read them from, so the two can not drift.
	 *
	 * @param array $action Sanitized (or raw, it is re-validated) email action.
	 * @return array{template:string,logo_url:string,logo_width:int,logo_height:int,logo_position:string,header_bg:string,link_color:string}
	 */
	public static function layout_options( array $action ): array {
		$template = (string) ( $action['template'] ?? '' );
		$template = self::is_valid_template( $template ) ? $template : 'branded';
		$accent   = self::default_accent( $template );

		$header_bg  = self::sanitize_hex( $action['header_bg'] ?? '' );
		$link_color = self::sanitize_hex( $action['link_color'] ?? '' );

		$position = (string) ( $action['logo_position'] ?? 'left' );
		$width    = self::clamp_logo_width( $action['logo_width'] ?? self::LOGO_WIDTH_DEFAULT );

		$logo_url    = '';
		$logo_height = 0;
		$logo_id     = (int) ( $action['logo_id'] ?? 0 );
		if ( $logo_id <= 0 ) {
			// No logo picked for this action: fall back to the site logo, as the templates always did.
			$logo_id = (int) get_theme_mod( 'custom_logo' );
		}
		if ( $logo_id > 0 ) {
			$src = wp_get_attachment_image_src( $logo_id, 'large' );
			if ( is_array( $src ) ) {
				$logo_url = self::absolute_url( (string) $src[0] );
				if ( '' !== $logo_url && (int) $src[1] > 0 && (int) $src[2] > 0 ) {
					$logo_height = (int) round( $width * (int) $src[2] / (int) $src[1] );
				}
			}
		}

		return array(
			'template'      => $template,
			'logo_url'      => $logo_url,
			'logo_width'    => $width,
			'logo_height'   => $logo_height,
			'logo_position' => in_array( $position, self::LOGO_POSITIONS, true ) ? $position : 'left',
			'header_bg'     => '' !== $header_bg ? $header_bg : $accent,
			'link_color'    => '' !== $link_color ? $link_color : $accent,
		);
	}

	/**
	 * @param mixed $width Raw logo max width.
	 */
	public static function clamp_logo_width( $width ): int {
		return max( self::LOGO_WIDTH_MIN, min( self::LOGO_WIDTH_MAX, (int) ( is_numeric( $width ) ? $width : self::LOGO_WIDTH_DEFAULT ) ) );
	}

	/**
	 * The whole HTML of one email action, for the real send AND the preview:
	 * either the admin's own full HTML document (template "custom") or the
	 * built-in layout around the sanitized, merged WYSIWYG message.
	 *
	 * @param array  $action     Email action settings.
	 * @param string $form_title Form title (header line).
	 * @param array  $context    Merge-tag context, see merge_tags().
	 */
	public static function build_html( array $action, string $form_title, array $context ): string {
		$template = (string) ( $action['template'] ?? '' );

		if ( 'custom' === $template ) {
			return self::finalize_html( self::merge_tags( self::sanitize_custom_html( (string) ( $action['custom_html'] ?? '' ) ), $context, true ) );
		}

		$layout    = self::layout_options( $action );
		$body_text = self::merge_tags( self::sanitize_message( (string) ( $action['message'] ?? '' ) ), $context, true );

		return self::render(
			$layout['template'],
			array(
				'heading'         => $form_title,
				'body_html'       => wpautop( $body_text ),
				'include_table'   => ! isset( $action['include_table'] ) || ! empty( $action['include_table'] ),
				'fields_snapshot' => (array) ( $context['fields_snapshot'] ?? array() ),
				'values'          => (array) ( $context['values'] ?? array() ),
				'cta_text'        => sanitize_text_field( (string) ( $action['cta_text'] ?? '' ) ),
				'cta_url'         => esc_url_raw( (string) ( $action['cta_url'] ?? '' ), array( 'http', 'https', 'mailto', 'tel' ) ),
				'logo_url'        => $layout['logo_url'],
				'logo_width'      => $layout['logo_width'],
				'logo_height'     => $layout['logo_height'],
				'logo_position'   => $layout['logo_position'],
				'header_bg'       => $layout['header_bg'],
				'link_color'      => $layout['link_color'],
			)
		);
	}

	/**
	 * Render the full HTML email body.
	 *
	 * @param string $template  One of self::TEMPLATES.
	 * @param array  $args {
	 *     @type string $heading          Header line.
	 *     @type string $body_html        Body text (already through merge_tags(), wpautop'd by the caller if needed).
	 *     @type bool   $include_table    Whether to append the auto-rendered submission table.
	 *     @type array  $fields_snapshot  { key => { label, type } } - for the table.
	 *     @type array  $values           { key => value } - for the table.
	 *     @type string $cta_text         Optional button text.
	 *     @type string $cta_url          Optional button URL.
	 *     @type string $site_name        Footer site name.
	 *     @type string $policy_url       Optional footer privacy-policy link.
	 *     @type string $logo_url         Absolute logo URL ('' = no logo).
	 *     @type int    $logo_width       Logo width in px.
	 *     @type int    $logo_height      Logo height in px (0 = unknown).
	 *     @type string $logo_position    left|center|right (aligns the whole header).
	 *     @type string $header_bg        Header background (#rrggbb), default = template accent.
	 *     @type string $link_color       Link and button colour (#rrggbb), default = template accent.
	 * }
	 */
	public static function render( string $template, array $args ): string {
		if ( ! self::is_valid_template( $template ) ) {
			$template = 'branded';
		}
		$accent = self::default_accent( $template );

		$header_bg     = self::sanitize_hex( $args['header_bg'] ?? '' ) ?: $accent;
		$link_color    = self::sanitize_hex( $args['link_color'] ?? '' ) ?: $accent;
		$header_text   = self::readable_text_color( $header_bg );
		$button_text   = self::readable_text_color( $link_color );
		$position      = in_array( $args['logo_position'] ?? 'left', self::LOGO_POSITIONS, true ) ? (string) $args['logo_position'] : 'left';
		$logo_width    = self::clamp_logo_width( $args['logo_width'] ?? self::LOGO_WIDTH_DEFAULT );
		$logo_height   = max( 0, (int) ( $args['logo_height'] ?? 0 ) );
		$heading       = (string) ( $args['heading'] ?? '' );
		$include_table = ! empty( $args['include_table'] );
		$cta_text      = (string) ( $args['cta_text'] ?? '' );
		$cta_url       = (string) ( $args['cta_url'] ?? '' );
		$site_name     = (string) ( $args['site_name'] ?? get_bloginfo( 'name' ) );
		$policy_url    = (string) ( $args['policy_url'] ?? '' );
		$logo          = array_key_exists( 'logo_url', $args )
			? (string) $args['logo_url']
			: ( (int) get_theme_mod( 'custom_logo' ) > 0 ? (string) wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : '' );

		// body_html is trusted here: build_html() sanitizes the message BEFORE the merge tags are filled in
		// (sanitizing afterwards would strip the {submission_table} markup) and HTML-escapes merged values.
		// What is left to do is colouring the links and fixing images / link schemes.
		$body = self::finalize_html( (string) ( $args['body_html'] ?? '' ), $link_color );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<!--[if mso]><style type="text/css">table,td{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;}</style><![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
	<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#f3f4f6" style="background-color:#f3f4f6;">
		<tr><td align="center" style="padding:24px 12px;">
			<table role="presentation" width="600" border="0" cellpadding="0" cellspacing="0" align="center" bgcolor="#ffffff" style="background-color:#ffffff;width:100%;max-width:600px;">
				<tr>
					<td align="<?php echo esc_attr( $position ); ?>" bgcolor="<?php echo esc_attr( $header_bg ); ?>" style="background-color:<?php echo esc_attr( $header_bg ); ?>;padding:20px 28px;text-align:<?php echo esc_attr( $position ); ?>;">
						<?php if ( '' !== $logo ) : ?>
							<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" width="<?php echo (int) $logo_width; ?>"<?php echo $logo_height > 0 ? ' height="' . (int) $logo_height . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> border="0" style="display:inline-block;vertical-align:bottom;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;width:<?php echo (int) $logo_width; ?>px;max-width:100%;height:auto;"><br>
							<?php if ( '' !== $heading ) : ?>
								<div style="height:10px;line-height:10px;font-size:1px;">&nbsp;</div>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( '' !== $heading ) : ?>
							<span style="color:<?php echo esc_attr( $header_text ); ?>;font-size:20px;line-height:26px;font-weight:bold;font-family:Arial,Helvetica,sans-serif;"><?php echo esc_html( $heading ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="padding:28px;color:#1f2937;font-size:14px;line-height:22px;font-family:Arial,Helvetica,sans-serif;">
						<div style="margin-bottom:20px;"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized by build_html() / finalize_html(). ?></div>
						<?php if ( $include_table ) : ?>
							<?php echo self::submission_table( (array) ( $args['fields_snapshot'] ?? array() ), (array) ( $args['values'] ?? array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<?php if ( '' !== $cta_text && '' !== $cta_url ) : ?>
							<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin-top:24px;"><tr>
								<td align="center" bgcolor="<?php echo esc_attr( $link_color ); ?>" style="background-color:<?php echo esc_attr( $link_color ); ?>;border-radius:5px;">
									<a href="<?php echo esc_url( $cta_url, self::LINK_PROTOCOLS ); ?>" style="display:inline-block;padding:11px 22px;color:<?php echo esc_attr( $button_text ); ?>;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;"><?php echo esc_html( $cta_text ); ?></a>
								</td>
							</tr></table>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td bgcolor="#f9fafb" style="padding:16px 28px;background-color:#f9fafb;color:#6b7280;font-size:12px;line-height:18px;font-family:Arial,Helvetica,sans-serif;">
						<?php echo esc_html( $site_name ); ?>
						<?php if ( '' !== $policy_url ) : ?>
							&middot; <a href="<?php echo esc_url( $policy_url ); ?>" style="color:#6b7280;"><?php esc_html_e( 'Privacy policy', 'ux-studio' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Auto-rendered label -> value table from a submission's field snapshot,
	 * NOT the current (possibly since-edited) form definition - so an email
	 * sent moments after submit always matches what the submitter actually
	 * saw and filled in.
	 *
	 * @param array $fields_snapshot { key => { label, type } }.
	 * @param array $values          { key => value }.
	 */
	public static function submission_table( array $fields_snapshot, array $values ): string {
		$rows = '';
		foreach ( $fields_snapshot as $key => $meta ) {
			$type = is_array( $meta ) ? (string) ( $meta['type'] ?? '' ) : '';
			if ( in_array( $type, array( 'html', 'step', 'captcha', 'password' ), true ) ) {
				continue;
			}
			$label = is_array( $meta ) ? (string) ( $meta['label'] ?? $key ) : (string) $key;
			$value = $values[ $key ] ?? '';
			$text  = self::format_display_value( $type, $value );
			if ( '' === $text ) {
				continue;
			}
			$rows .= '<tr>'
				. '<td valign="top" style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:bold;vertical-align:top;font-size:14px;word-break:break-word;font-family:Arial,Helvetica,sans-serif;">' . esc_html( $label ) . '</td>'
				. '<td valign="top" style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#1f2937;font-size:14px;word-break:break-word;font-family:Arial,Helvetica,sans-serif;">' . esc_html( $text ) . '</td>'
				. '</tr>';
		}
		if ( '' === $rows ) {
			return '';
		}
		return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:8px;">' . $rows . '</table>';
	}

	/**
	 * @param mixed $value Raw stored value.
	 */
	private static function format_display_value( string $type, $value ): string {
		if ( 'checkbox' === $type || 'acceptance' === $type ) {
			return $value ? __( 'Yes', 'ux-studio' ) : __( 'No', 'ux-studio' );
		}
		if ( in_array( $type, array( 'checkbox_group', 'multiselect' ), true ) ) {
			return is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		if ( 'file' === $type || 'signature' === $type ) {
			if ( is_array( $value ) ) {
				$list = array_is_list( $value ) ? $value : array( $value );
				$names = array_map( static fn( $f ) => is_array( $f ) ? (string) ( $f['original_name'] ?? '' ) : '', $list );
				return implode( ', ', array_filter( $names ) );
			}
			return '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Replace {field_key}/{form_title}/{submission_date}/{submission_table}
	 * merge tags in a subject or body template. Same brace syntax as the
	 * `{today}`/`{query.xxx}` default-value tokens elsewhere in this module,
	 * so users only learn one notation across the whole plugin.
	 *
	 * @param array $context {
	 *     @type string $form_title
	 *     @type string $submission_date
	 *     @type array  $fields_snapshot
	 *     @type array  $values
	 * }
	 * @param bool  $html    True when the text is an HTML body: values are then HTML-escaped
	 *                       (a submitted `<a href>` must not become live markup in the mail).
	 *                       Subjects, recipients and titles stay plain text.
	 */
	public static function merge_tags( string $text, array $context, bool $html = false ): string {
		$escape = static fn( string $value ): string => $html ? esc_html( $value ) : $value;

		$replacements = array(
			'{form_title}'       => $escape( self::strip_header_breaks( (string) ( $context['form_title'] ?? '' ) ) ),
			'{submission_date}'  => $escape( self::strip_header_breaks( (string) ( $context['submission_date'] ?? '' ) ) ),
			// {submission_table} is rendered HTML for the body only, never used in
			// a subject/To context, so it is intentionally exempt from the strip.
			'{submission_table}' => self::submission_table( (array) ( $context['fields_snapshot'] ?? array() ), (array) ( $context['values'] ?? array() ) ),
		);

		$values = (array) ( $context['values'] ?? array() );
		foreach ( $values as $key => $value ) {
			$flat = is_scalar( $value ) ? (string) $value : ( is_array( $value ) ? implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : '' );
			// Submitted field values feed both the subject and the To address of
			// the notification email (Actions.php) - without stripping CR/LF here
			// a crafted field value could inject extra mail headers.
			$replacements[ '{' . $key . '}' ] = $escape( self::strip_header_breaks( $flat ) );
		}

		return strtr( $text, $replacements );
	}

	/** Remove CR/LF so a merge-tag value can never inject extra mail headers. */
	private static function strip_header_breaks( string $value ): string {
		return trim( str_replace( array( "\r", "\n" ), ' ', $value ) );
	}

	/**
	 * Best-effort plain-text alternative for the multipart email - strips
	 * tags and collapses whitespace. Not meant to be pretty, just legible in
	 * text-only clients / spam filters that penalise HTML-only mail.
	 */
	public static function html_to_text( string $html ): string {
		$html = preg_replace( '#<(style|head|script)[^>]*>.*?</\1>#is', '', $html ) ?? $html;
		$text = wp_strip_all_tags( preg_replace( '/<(br|\/p|\/tr|\/h[1-6]|\/li)[^>]*>/i', "\n", $html ) ?? $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;
		return trim( $text );
	}
}
