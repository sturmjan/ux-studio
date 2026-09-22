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
 * clients don't reliably support `<style>` blocks or external CSS. Three
 * built-in templates share one layout function and only vary the accent
 * colour/header treatment, keeping the actual markup (and its escaping) in
 * one place.
 */
final class EmailTemplateRenderer {

	public const TEMPLATES = array( 'minimal', 'card', 'branded' );

	private const ACCENTS = array(
		'minimal' => '#1f2937',
		'card'    => '#2563eb',
		'branded' => '#7c3aed',
	);

	public static function is_valid_template( string $template ): bool {
		return in_array( $template, self::TEMPLATES, true );
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
	 * }
	 */
	public static function render( string $template, array $args ): string {
		if ( ! self::is_valid_template( $template ) ) {
			$template = 'branded';
		}
		$accent = self::ACCENTS[ $template ];

		$heading       = (string) ( $args['heading'] ?? '' );
		$body_html     = (string) ( $args['body_html'] ?? '' );
		$include_table = ! empty( $args['include_table'] );
		$cta_text      = (string) ( $args['cta_text'] ?? '' );
		$cta_url       = (string) ( $args['cta_url'] ?? '' );
		$site_name     = (string) ( $args['site_name'] ?? get_bloginfo( 'name' ) );
		$policy_url    = (string) ( $args['policy_url'] ?? '' );
		$logo          = (string) get_theme_mod( 'custom_logo' ) ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : '';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
		<tr><td align="center">
			<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">
				<tr>
					<td style="background:<?php echo esc_attr( $accent ); ?>;padding:20px 28px;">
						<?php if ( $logo ) : ?>
							<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" style="max-height:36px;display:block;margin-bottom:8px;">
						<?php endif; ?>
						<span style="color:#ffffff;font-size:20px;font-weight:bold;font-family:Arial,Helvetica,sans-serif;"><?php echo esc_html( $heading ); ?></span>
					</td>
				</tr>
				<tr>
					<td style="padding:28px;color:#1f2937;font-size:14px;line-height:1.6;">
						<div style="margin-bottom:20px;"><?php echo wp_kses_post( $body_html ); ?></div>
						<?php if ( $include_table ) : ?>
							<?php echo self::submission_table( (array) ( $args['fields_snapshot'] ?? array() ), (array) ( $args['values'] ?? array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<?php if ( '' !== $cta_text && '' !== $cta_url ) : ?>
							<p style="margin:24px 0 0;">
								<a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $accent ); ?>;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:5px;font-weight:bold;">
									<?php echo esc_html( $cta_text ); ?>
								</a>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="padding:16px 28px;background:#f9fafb;color:#6b7280;font-size:12px;">
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
				. '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:bold;vertical-align:top;white-space:nowrap;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#1f2937;">' . esc_html( $text ) . '</td>'
				. '</tr>';
		}
		if ( '' === $rows ) {
			return '';
		}
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:8px;">' . $rows . '</table>';
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
		if ( 'file' === $type ) {
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
	 */
	public static function merge_tags( string $text, array $context ): string {
		$replacements = array(
			'{form_title}'       => (string) ( $context['form_title'] ?? '' ),
			'{submission_date}'  => (string) ( $context['submission_date'] ?? '' ),
			'{submission_table}' => self::submission_table( (array) ( $context['fields_snapshot'] ?? array() ), (array) ( $context['values'] ?? array() ) ),
		);

		$values = (array) ( $context['values'] ?? array() );
		foreach ( $values as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = is_scalar( $value ) ? (string) $value : ( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : '' );
		}

		return strtr( $text, $replacements );
	}

	/**
	 * Best-effort plain-text alternative for the multipart email - strips
	 * tags and collapses whitespace. Not meant to be pretty, just legible in
	 * text-only clients / spam filters that penalise HTML-only mail.
	 */
	public static function html_to_text( string $html ): string {
		$text = wp_strip_all_tags( preg_replace( '/<(br|\/p|\/tr|\/h[1-6])[^>]*>/i', "\n", $html ) ?? $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;
		return trim( $text );
	}
}
