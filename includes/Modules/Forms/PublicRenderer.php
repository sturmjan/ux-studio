<?php
/**
 * Public (front-end) form rendering: markup, scoped styles and the vanilla-JS
 * runtime (multi-step navigation, client-side condition preview, submit).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

use UxStudio\Modules\SecurityOptimization\CaptchaVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately NOT a React island (the public site never loads the admin
 * SPA bundle) - same pattern as NoticeBoard::render_shortcode() and
 * PopupManager's front-end delivery: server-rendered HTML/CSS + a small
 * self-contained script per shortcode instance. The server is always the
 * final authority - every condition/required check this script performs is
 * re-run in Submissions::submit() before anything is stored.
 *
 * Multi-step (PLAN.md 20.4a / 20.12): every `step` field starts a new
 * <fieldset>. The runtime shows one at a time, validates it before "Next",
 * moves focus to the step heading (a <legend> with tabindex -1) and reports
 * errors through an aria-live alert plus a per-field message linked with
 * aria-describedby. Without JavaScript the form still shows every step.
 */
final class PublicRenderer {

	/** Width in percent -> columns of the 12-column grid. */
	private const COLS = array(
		25  => 3,
		33  => 4,
		50  => 6,
		66  => 8,
		75  => 9,
		100 => 12,
	);

	/** Types that may sit side by side when the form uses the "two columns" setting. */
	private const NARROW_TYPES = array( 'text', 'email', 'tel', 'number', 'url', 'password', 'date', 'time', 'select' );

	/**
	 * @param array $form Full form array (id, title, fields, settings).
	 */
	public function render( array $form ): string {
		static $styles_printed = false;

		$fields   = (array) $form['fields'];
		$settings = (array) $form['settings'];
		$uid      = 'uxsf-' . (int) $form['id'] . '-' . wp_generate_password( 6, false, false );

		$layout = in_array( $settings['layout'] ?? 'plain', Module::LAYOUTS, true ) ? (string) $settings['layout'] : 'plain';
		$steps  = $this->split_steps( $fields );

		$classes = array( 'uxs-form-public', 'uxs-fp--' . $layout );
		if ( 2 === (int) ( $settings['columns'] ?? 1 ) ) {
			$classes[] = 'uxs-fp--cols-2';
		}
		if ( ! empty( $settings['numbered_sections'] ) ) {
			$classes[] = 'uxs-fp--numbered';
		}
		if ( count( $steps ) > 1 ) {
			$classes[] = 'uxs-fp--multistep';
		}

		$submit_text = '' !== trim( (string) ( $settings['submit_text'] ?? '' ) ) ? (string) $settings['submit_text'] : __( 'Submit', 'ux-studio' );
		$is_card     = 'card' === $layout;
		// The one-row signup puts the button in the field grid, next to the first field.
		$inline_nav  = 'inline' === $layout && 1 === count( $steps );
		$title       = trim( (string) ( $form['title'] ?? '' ) );
		$description = trim( (string) ( $form['description'] ?? '' ) );

		ob_start();

		if ( ! $styles_printed ) {
			$this->print_styles();
			$styles_printed = true;
		}
		?>
		<form
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			id="<?php echo esc_attr( $uid ); ?>"
			data-uxs-form
			data-form-id="<?php echo (int) $form['id']; ?>"
			data-progress="<?php echo esc_attr( (string) ( $settings['progress_style'] ?? 'steps' ) ); ?>"
			<?php echo $is_card && '' !== $title ? 'aria-labelledby="' . esc_attr( $uid ) . '-title"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			novalidate
		>
			<?php if ( $is_card && '' !== $title ) : ?>
				<div class="uxs-fp-card__head">
					<p class="uxs-fp-card__title" id="<?php echo esc_attr( $uid ); ?>-title"><?php echo esc_html( $title ); ?></p>
					<?php if ( '' !== $description ) : ?>
						<p class="uxs-fp-card__desc"><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="uxs-fp-body">
				<?php echo AfterSubmit::box_html( null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="uxs-fp-progress" data-uxs-progress hidden></div>
				<div class="uxs-fp-alert" data-uxs-alert role="alert" hidden></div>

				<div class="uxs-fp-steps" data-uxs-steps>
					<?php $this->render_honeypot(); ?>
					<?php echo $this->render_steps( $steps, $settings, $inline_nav ? $this->render_nav( $submit_text, true ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

			</div>

			<?php if ( ! $inline_nav ) : ?>
				<?php echo $this->render_nav( $submit_text, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</form>
		<?php
		// A JSON data block, not code: a strict CSP does not touch it, and it never has to be hashed. The runtime reads it.
		echo '<script type="application/json" data-uxs-form-config="' . esc_attr( $uid ) . '">' . self::config_json( $this->client_config( $form, $steps ) ) . '</script>' . "
"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with the HEX_TAG flags.
		?>
		<?php
		static $hooks_printed = array();
		$tracking = (array) ( $settings['tracking'] ?? array() );
		if ( empty( $hooks_printed[ (int) $form['id'] ] ) && '' !== trim( (string) ( $tracking['custom_js'] ?? '' ) ) ) {
			$hooks_printed[ (int) $form['id'] ] = true;
			echo AfterSubmit::hook_script( (int) $form['id'], (string) $tracking['custom_js'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- code of a user with unfiltered_html, script-escaped.
		}
		if ( ! wp_script_is( 'uxstudio-forms-public', 'done' ) ) {
			$this->print_runtime();
		}

		return (string) ob_get_clean();
	}

	/**
	 * Back / Next / Submit buttons (the runtime shows the right ones per step).
	 *
	 * @param bool $inline True when the buttons are a cell of the field grid (one-row signup).
	 */
	private function render_nav( string $submit_text, bool $inline ): string {
		ob_start();
		?>
		<div class="uxs-fp-nav<?php echo $inline ? ' uxs-fp-nav--inline' : ''; ?>" data-uxs-nav>
			<button type="button" class="uxs-fp-btn uxs-fp-btn--ghost" data-uxs-prev hidden><?php esc_html_e( 'Back', 'ux-studio' ); ?></button>
			<button type="button" class="uxs-fp-btn uxs-fp-btn--primary" data-uxs-next hidden><?php esc_html_e( 'Next', 'ux-studio' ); ?></button>
			<button type="submit" class="uxs-fp-btn uxs-fp-btn--primary" data-uxs-submit><?php echo esc_html( $submit_text ); ?></button>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array $steps Result of split_steps().
	 * @return array<string, mixed>
	 */
	private function client_config( array $form, array $steps ): array {
		$settings = (array) $form['settings'];
		return array(
			'restUrl'       => esc_url_raw( rest_url( 'uxstudio/v1/forms/submit' ) ),
			'fields'        => array_map(
				static function ( array $f ): array {
					return array(
						'key'        => $f['key'],
						'type'       => $f['type'],
						'required'   => ! empty( $f['required'] ),
						'conditions' => $f['conditions'] ?? array(),
						'logic'      => $f['logic'] ?? 'all',
						'step'       => null,
					);
				},
				array_values( array_filter( (array) $form['fields'], static fn( $f ) => ! in_array( $f['type'] ?? '', array( 'html', 'step' ), true ) ) )
			),
			'steps'         => array_map(
				static fn( array $s ): array => array(
					'title'       => $s['title'],
					'description' => $s['description'],
				),
				$steps
			),
			'labelDisplay'  => (string) ( $settings['label_display'] ?? 'visible' ),
			'progressStyle' => (string) ( $settings['progress_style'] ?? 'steps' ),
			'successText'   => (string) ( $settings['success_text'] ?? '' ) !== '' ? (string) $settings['success_text'] : AfterSubmit::default_message(),
			'formId'        => (int) $form['id'],
			'formTitle'     => (string) ( $form['title'] ?? '' ),
			'iconSvg'       => AfterSubmit::ICON_SVG,
			'tracking'      => AfterSubmit::client_tracking( (array) ( $settings['tracking'] ?? array() ) ),
			'i18n'          => array(
				'required'     => __( 'This field is required.', 'ux-studio' ),
				'genericError' => __( 'Something went wrong, please try again.', 'ux-studio' ),
				'removeChip'   => __( 'Remove', 'ux-studio' ),
				'email'        => __( 'Enter a valid e-mail address.', 'ux-studio' ),
				'url'          => __( 'Enter a valid web address, starting with https://.', 'ux-studio' ),
				'number'       => __( 'Enter a number.', 'ux-studio' ),
				'min'          => __( 'The value must be at least %s.', 'ux-studio' ),
				'max'          => __( 'The value must be at most %s.', 'ux-studio' ),
				'tooShort'     => __( 'Enter at least %s characters.', 'ux-studio' ),
				'tooLong'      => __( 'Enter at most %s characters.', 'ux-studio' ),
				'invalid'      => __( 'This value is not valid.', 'ux-studio' ),
				'fixErrors'    => __( 'Please check the highlighted fields (%s).', 'ux-studio' ),
				/* translators: 1: current step number, 2: number of steps. */
				'stepOf'       => __( 'Step %1$d of %2$d', 'ux-studio' ),
				'progress'     => __( 'Form progress', 'ux-studio' ),
			),
		);
	}

	private function render_honeypot(): void {
		printf(
			'<div class="uxs-fp-hp" aria-hidden="true"><label>%1$s<input type="text" name="%2$s" tabindex="-1" autocomplete="off"></label></div>',
			esc_html__( 'Leave this field empty', 'ux-studio' ),
			esc_attr( Submissions::HONEYPOT_FIELD )
		);
	}

	/**
	 * Splits fields[] into steps at every `step` field. A form with no `step`
	 * fields is one implicit step.
	 *
	 * @return array<int, array{title:string,description:string,fields:array}>
	 */
	private function split_steps( array $fields ): array {
		$steps = array( array( 'title' => '', 'description' => '', 'fields' => array() ) );
		foreach ( $fields as $field ) {
			if ( 'step' === ( $field['type'] ?? '' ) ) {
				$steps[] = array(
					'title'       => (string) ( $field['step_title'] ?? '' ),
					'description' => (string) ( $field['step_description'] ?? '' ),
					'fields'      => array(),
				);
				continue;
			}
			$steps[ count( $steps ) - 1 ]['fields'][] = $field;
		}
		// Drop a leading empty step when the form starts with a `step` field.
		if ( count( $steps ) > 1 && empty( $steps[0]['fields'] ) ) {
			array_shift( $steps );
		}
		// A step without a title still needs a name for the progress indicator and the screen reader.
		if ( count( $steps ) > 1 ) {
			foreach ( $steps as $i => $step ) {
				if ( '' === $step['title'] ) {
					/* translators: %d: step number. */
					$steps[ $i ]['title'] = sprintf( __( 'Step %d', 'ux-studio' ), $i + 1 );
				}
			}
		}
		return $steps;
	}

	/**
	 * Renders each step as a <fieldset data-uxs-step>.
	 *
	 * @param array  $steps      Result of split_steps().
	 * @param string $inline_nav Buttons to place after the first field (one-row signup), '' = none.
	 */
	private function render_steps( array $steps, array $settings, string $inline_nav = '' ): string {
		$total = count( $steps );
		$html  = '';
		foreach ( $steps as $i => $step ) {
			$html .= '<fieldset class="uxs-fp-step" data-uxs-step="' . (int) $i . '"' . ( $i > 0 ? ' hidden' : '' ) . ( '' !== $step['description'] && $total > 1 ? ' aria-describedby="uxs-fp-stepdesc-' . (int) $i . '"' : '' ) . '>';
			if ( $total > 1 ) {
				$html .= '<legend class="uxs-fp-step__title" tabindex="-1" data-uxs-step-head>'
					. '<span class="uxs-fp-step__badge" aria-hidden="true">' . (int) ( $i + 1 ) . '</span>'
					. '<span class="uxs-sr-only">' . esc_html( sprintf( __( 'Step %1$d of %2$d', 'ux-studio' ), $i + 1, $total ) ) . ': </span>'
					. '<span class="uxs-fp-step__text">' . esc_html( $step['title'] ) . '</span>'
					. '</legend>';
				if ( '' !== $step['description'] ) {
					$html .= '<p class="uxs-fp-step__desc" id="uxs-fp-stepdesc-' . (int) $i . '">' . esc_html( $step['description'] ) . '</p>';
				}
			}
			$html .= '<div class="uxs-fp-row">';
			foreach ( $step['fields'] as $n => $field ) {
				$html .= $this->render_field( $field, $settings );
				if ( 0 === $n && '' !== $inline_nav ) {
					$html .= $inline_nav;
				}
			}
			if ( empty( $step['fields'] ) && '' !== $inline_nav ) {
				$html .= $inline_nav;
			}
			$html .= '</div></fieldset>';
		}
		return $html;
	}

	private function render_field( array $field, array $settings ): string {
		$type = (string) $field['type'];
		if ( 'html' === $type ) {
			return sprintf( '<div class="uxs-fp-html" style="%s">%s</div>', esc_attr( $this->grid_style( $field, $settings ) ), wp_kses_post( (string) ( $field['html'] ?? '' ) ) );
		}
		if ( 'hidden' === $type ) {
			return $this->render_control( $field, '', '', false );
		}

		$key         = (string) $field['key'];
		$label       = (string) $field['label'];
		$required    = ! empty( $field['required'] );
		$label_mode  = 'inherit' === ( $field['label_display'] ?? 'inherit' ) ? (string) ( $settings['label_display'] ?? 'visible' ) : (string) $field['label_display'];
		$placeholder = (string) ( $field['placeholder'] ?? '' );
		if ( 'placeholder_only' === $label_mode && '' === $placeholder ) {
			$placeholder = $label;
		}

		$uid      = 'uxsf-' . $key . '-' . wp_generate_password( 4, false, false );
		$is_check = in_array( $type, array( 'checkbox', 'acceptance' ), true );
		$is_group = in_array( $type, array( 'radio', 'checkbox_group' ), true );
		$hide     = 'placeholder_only' === $label_mode;

		$wrap_attrs = sprintf(
			'class="uxs-fp-field uxs-fp-field--%1$s%2$s" style="%3$s" data-uxs-field="%4$s" data-conditions=\'%5$s\' data-logic="%6$s"',
			esc_attr( $type ),
			$required ? ' is-required' : '',
			esc_attr( $this->grid_style( $field, $settings ) ),
			esc_attr( $key ),
			esc_attr( wp_json_encode( $field['conditions'] ?? array() ) ?: '[]' ),
			esc_attr( (string) ( $field['logic'] ?? 'all' ) )
		);

		ob_start();
		echo '<div ' . $wrap_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$star = $required ? '<span class="uxs-fp-req" aria-hidden="true">*</span>' : '';
		if ( 'captcha' === $type ) {
			// No label: the provider's own widget carries its name.
			$group_label = '';
		} elseif ( $is_check && $hide ) {
			// The checkbox's own text IS its label (and its accessible name), a second hidden label would only repeat it.
			$group_label = '';
		} elseif ( $is_group ) {
			$group_label = $uid . '-label';
			printf(
				'<span class="%1$s" id="%2$s">%3$s%4$s</span>',
				$hide ? 'uxs-sr-only' : 'uxs-fp-label',
				esc_attr( $group_label ),
				esc_html( $label ),
				$star // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		} else {
			$group_label = '';
			printf(
				'<label class="%1$s" for="%2$s">%3$s%4$s</label>',
				$hide ? 'uxs-sr-only' : 'uxs-fp-label',
				esc_attr( $uid ),
				esc_html( $label ),
				$star // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		echo $this->render_control( $field, $uid, $placeholder, $is_check && $hide && $required, $group_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="uxs-fp-error" id="' . esc_attr( $uid ) . '-err" data-uxs-field-error></span>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * @param bool   $inline_star True when the required star must sit in the control's own text (its label is not drawn).
	 * @param string $group_label Id of the element that names a radio / checkbox group.
	 */
	private function render_control( array $field, string $uid, string $placeholder, bool $inline_star = false, string $group_label = '' ): string {
		$type     = (string) $field['type'];
		$key      = (string) $field['key'];
		$required = ! empty( $field['required'] ) ? ' required' : '';
		$name     = esc_attr( $key );
		$star     = $inline_star ? '<span class="uxs-fp-req" aria-hidden="true">*</span>' : '';
		$labelled = '' !== $group_label ? ' aria-labelledby="' . esc_attr( $group_label ) . '"' : '';
		$style    = in_array( $field['choice_style'] ?? 'list', Fields::CHOICE_STYLES, true ) ? (string) ( $field['choice_style'] ?? 'list' ) : 'list';

		switch ( $type ) {
			case 'textarea':
				return sprintf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" placeholder="%4$s"%5$s></textarea>',
					esc_attr( $uid ),
					$name,
					(int) ( $field['rows'] ?? 4 ),
					esc_attr( $placeholder ),
					$required
				);

			case 'select':
				$options = '<option value="">' . esc_html__( 'Choose…', 'ux-studio' ) . '</option>';
				foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
					$options .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $opt['value'] ), esc_html( $opt['label'] ) );
				}
				return sprintf( '<select id="%1$s" name="%2$s"%3$s>%4$s</select>', esc_attr( $uid ), $name, $required, $options );

			case 'multiselect':
				$options = '';
				foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
					$options .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $opt['value'] ), esc_html( $opt['label'] ) );
				}
				return sprintf(
					'<select id="%1$s" name="%2$s[]" multiple data-uxs-multiselect%3$s>%4$s</select><div class="uxs-fp-chips" data-uxs-chips></div>',
					esc_attr( $uid ),
					$name,
					$required,
					$options
				);

			case 'radio':
				$html = '<div class="uxs-fp-options uxs-fp-options--' . esc_attr( $style ) . '" role="radiogroup"' . $labelled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( (array) ( $field['options'] ?? array() ) as $i => $opt ) {
					$id    = $uid . '-' . $i;
					$html .= sprintf(
						'<label class="uxs-fp-option"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s> <span>%5$s</span></label>',
						esc_attr( $id ),
						$name,
						esc_attr( $opt['value'] ),
						0 === $i ? $required : '',
						esc_html( $opt['label'] )
					);
				}
				return $html . '</div>';

			case 'checkbox_group':
				$html = '<div class="uxs-fp-options uxs-fp-options--' . esc_attr( 'inline' === $style ? 'inline' : $style ) . '" role="group"' . $labelled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( (array) ( $field['options'] ?? array() ) as $i => $opt ) {
					$id    = $uid . '-' . $i;
					$html .= sprintf(
						'<label class="uxs-fp-option uxs-fp-checkbox"><input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"><span class="uxs-fp-check" aria-hidden="true"></span><span>%4$s</span></label>',
						esc_attr( $id ),
						$name,
						esc_attr( $opt['value'] ),
						esc_html( $opt['label'] )
					);
				}
				return $html . '</div>';

			case 'checkbox':
				return sprintf(
					'<label class="uxs-fp-option uxs-fp-checkbox"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s><span class="uxs-fp-check" aria-hidden="true"></span><span class="uxs-fp-checkbox-text">%4$s%5$s</span></label>',
					esc_attr( $uid ),
					$name,
					$required,
					esc_html( $placeholder ),
					$star // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);

			case 'acceptance':
				$terms = (string) ( $field['terms_url'] ?? '' );
				$text  = esc_html( $placeholder ?: $field['label'] );
				if ( '' !== $terms ) {
					$text = sprintf(
						/* translators: %s: link to terms/policy, already HTML. */
						esc_html__( '%s (see terms)', 'ux-studio' ),
						$text
					);
				}
				return sprintf(
					'<label class="uxs-fp-option uxs-fp-checkbox"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s><span class="uxs-fp-check" aria-hidden="true"></span><span>%4$s%5$s%6$s</span></label>',
					esc_attr( $uid ),
					$name,
					$required,
					$text,
					'' !== $terms ? ' <a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms', 'ux-studio' ) . '</a>' : '',
					$star // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);

			case 'file':
				$accept   = implode( ',', array_map( static fn( $e ) => '.' . $e, (array) ( $field['accept'] ?? array() ) ) );
				$multiple = ! empty( $field['multiple'] ) ? ' multiple' : '';
				return sprintf(
					'<div class="uxs-fp-dropzone" data-uxs-dropzone><input type="file" id="%1$s" name="file_%2$s%3$s" accept="%4$s"%5$s%6$s><p class="uxs-fp-dropzone__hint">%7$s</p><ul class="uxs-fp-files" data-uxs-file-list></ul></div>',
					esc_attr( $uid ),
					$name,
					$multiple ? '[]' : '',
					esc_attr( $accept ),
					$multiple,
					$required,
					esc_html__( 'Drag & drop a file here, or click to choose.', 'ux-studio' )
				);

			case 'signature':
				return sprintf(
					'<div class="uxs-fp-signature" data-uxs-signature>' .
						'<canvas class="uxs-fp-signature__pad" height="160"></canvas>' .
						'<input type="hidden" id="%1$s" name="%2$s">' .
						'<div class="uxs-fp-signature__actions">' .
							'<button type="button" class="uxs-fp-btn uxs-fp-btn--ghost" data-uxs-signature-clear>%3$s</button>' .
						'</div>' .
						'<p class="uxs-fp-dropzone__hint">%4$s</p>' .
					'</div>',
					esc_attr( $uid ),
					$name,
					esc_html__( 'Clear', 'ux-studio' ),
					esc_html__( 'Sign using your mouse or finger.', 'ux-studio' )
				);

			case 'hidden':
				return sprintf(
					'<input type="hidden" name="%1$s" value="%2$s">',
					$name,
					esc_attr( Fields::resolve_default_token( (string) ( $field['default_value'] ?? '' ) ) )
				);

			case 'captcha':
				return $this->render_captcha();

			case 'date':
			case 'time':
			case 'email':
			case 'url':
			case 'tel':
			case 'number':
			case 'password':
			case 'text':
			default:
				$type_attr = in_array( $type, array( 'date', 'time', 'email', 'url', 'tel', 'number', 'password' ), true ) ? $type : 'text';
				$extra     = '';
				if ( 'number' === $type ) {
					if ( null !== ( $field['min'] ?? null ) ) {
						$extra .= ' min="' . esc_attr( (string) $field['min'] ) . '"';
					}
					if ( null !== ( $field['max'] ?? null ) ) {
						$extra .= ' max="' . esc_attr( (string) $field['max'] ) . '"';
					}
				}
				if ( ! empty( $field['minlength'] ) ) {
					$extra .= ' minlength="' . (int) $field['minlength'] . '"';
				}
				if ( ! empty( $field['maxlength'] ) ) {
					$extra .= ' maxlength="' . (int) $field['maxlength'] . '"';
				}
				if ( 'email' === $type ) {
					$extra .= ' autocomplete="email"';
				}
				$default = 'hidden' !== $type ? esc_attr( Fields::resolve_default_token( (string) ( $field['default_value'] ?? '' ) ) ) : '';
				return sprintf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" placeholder="%5$s"%6$s%7$s>',
					esc_attr( $type_attr ),
					esc_attr( $uid ),
					$name,
					$default,
					esc_attr( $placeholder ),
					$required,
					$extra
				);
		}
	}

	/**
	 * If Security Optimization has a configured provider, embed the real
	 * widget; otherwise a neutral placeholder. Server-side verification of
	 * whatever token this widget produces happens in
	 * RestController::verify_captcha() (PLAN.md 20.11/F2).
	 */
	private function render_captcha(): string {
		$module = class_exists( \UxStudio\Modules\SecurityOptimization\Module::class )
			? \UxStudio\Plugin::instance()->modules->instance( 'security-optimization' )
			: null;

		if ( $module instanceof \UxStudio\Modules\SecurityOptimization\Module && CaptchaVerifier::is_configured( $module ) ) {
			CaptchaVerifier::enqueue_provider_script( $module );
			ob_start();
			CaptchaVerifier::render_widget( $module );
			return (string) ob_get_clean();
		}

		return '<p class="uxs-fp-captcha-placeholder">' . esc_html__( 'Spam protection is not configured for this site yet.', 'ux-studio' ) . '</p>';
	}

	/**
	 * Grid placement of a field: columns of a 12-column grid for desktop,
	 * tablet and mobile (custom properties read by the media queries in
	 * print_styles()). With the "two columns" setting a field left at full
	 * width on desktop and tablet becomes half width, if its type is narrow.
	 *
	 * @param array $field    Field with width / width_tablet / width_mobile (layout types always 100).
	 * @param array $settings Form settings.
	 */
	private function grid_style( array $field, array $settings ): string {
		$desktop = self::cols( (int) ( $field['width'] ?? 100 ) );
		$tablet  = self::cols( (int) ( $field['width_tablet'] ?? ( $field['width'] ?? 100 ) ) );
		$mobile  = self::cols( (int) ( $field['width_mobile'] ?? ( $field['width_tablet'] ?? ( $field['width'] ?? 100 ) ) ) );

		if ( 2 === (int) ( $settings['columns'] ?? 1 ) && in_array( $field['type'] ?? '', self::NARROW_TYPES, true ) ) {
			$desktop = 12 === $desktop ? 6 : $desktop;
			$tablet  = 12 === $tablet ? 6 : $tablet;
		}

		return '--uxs-c:' . $desktop . ';--uxs-ct:' . $tablet . ';--uxs-cm:' . $mobile . ';';
	}

	private static function cols( int $width ): int {
		if ( isset( self::COLS[ $width ] ) ) {
			return self::COLS[ $width ];
		}
		return max( 1, min( 12, (int) round( $width / 100 * 12 ) ) );
	}

	/**
	 * Scoped, self-contained CSS - printed once per page regardless of how
	 * many form shortcodes render (same guard pattern as PopupManager).
	 */
	private function print_styles(): void {
		?>
		<style>
		.uxs-form-public{display:block;max-width:100%;box-sizing:border-box;font-size:14px;}
		.uxs-form-public *{box-sizing:border-box;}
		.uxs-fp-steps{margin:0;padding:0;}
		.uxs-fp-step{border:0;margin:0;padding:0;min-width:0;}
		.uxs-fp-step[hidden]{display:none;}
		.uxs-fp-row{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:18px 16px;align-items:start;}
		.uxs-fp-field{display:flex;flex-direction:column;gap:6px;grid-column:span var(--uxs-c,12);min-width:0;}
		.uxs-fp-html{grid-column:span var(--uxs-c,12);}
		@media (max-width:1024px){.uxs-fp-field,.uxs-fp-html{grid-column:span var(--uxs-ct,var(--uxs-c,12));}}
		@media (max-width:782px){.uxs-fp-field,.uxs-fp-html{grid-column:span var(--uxs-cm,var(--uxs-ct,var(--uxs-c,12)));}}
		.uxs-fp-field.uxs-fp-field--hidden{display:none;}
		.uxs-fp-field[hidden]{display:none;}
		.uxs-fp-label{font-weight:600;font-size:14px;color:#1f2937;}
		.uxs-fp-req{color:#dc2626;margin-left:2px;}
		.uxs-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
		.uxs-form-public input[type=text],.uxs-form-public input[type=email],.uxs-form-public input[type=url],
		.uxs-form-public input[type=tel],.uxs-form-public input[type=number],.uxs-form-public input[type=password],
		.uxs-form-public input[type=date],.uxs-form-public input[type=time],
		.uxs-form-public select,.uxs-form-public textarea{
			width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;
			transition:border-color .15s ease,box-shadow .15s ease;background:#fff;color:#111827;
		}
		.uxs-form-public input:focus,.uxs-form-public select:focus,.uxs-form-public textarea:focus{
			outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);
		}
		.uxs-fp-field.has-error input,.uxs-fp-field.has-error select,.uxs-fp-field.has-error textarea{border-color:#dc2626;}
		.uxs-fp-field.has-error .uxs-fp-dropzone,.uxs-fp-field.has-error .uxs-fp-options--cards .uxs-fp-option{border-color:#dc2626;}
		.uxs-fp-error{color:#b91c1c;font-size:12px;min-height:14px;}
		.uxs-fp-error:empty{min-height:0;}
		.uxs-fp-options{display:flex;flex-direction:column;gap:8px;}
		.uxs-fp-option{display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;}
		.uxs-fp-options--cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}
		.uxs-fp-options--cards .uxs-fp-option{padding:12px 14px;border:1px solid #d1d5db;border-radius:8px;background:#fff;transition:border-color .15s ease,box-shadow .15s ease,background .15s ease;}
		.uxs-fp-options--cards .uxs-fp-option:hover{border-color:#9ca3af;}
		.uxs-fp-options--cards .uxs-fp-option:has(input:checked){border-color:#2563eb;background:#eff6ff;box-shadow:0 0 0 1px #2563eb;}
		.uxs-fp-options--cards .uxs-fp-option:has(input:focus-visible){box-shadow:0 0 0 3px rgba(37,99,235,.3);}
		.uxs-fp-options--inline{flex-direction:row;flex-wrap:wrap;gap:8px;}
		.uxs-fp-options--inline .uxs-fp-option{position:relative;min-width:46px;min-height:44px;justify-content:center;padding:8px 14px;border:1px solid #d1d5db;border-radius:8px;background:#fff;font-weight:600;transition:border-color .15s ease,background .15s ease,color .15s ease;}
		.uxs-fp-options--inline .uxs-fp-option input{position:absolute;opacity:0;width:100%;height:100%;inset:0;margin:0;cursor:pointer;}
		.uxs-fp-options--inline .uxs-fp-option:hover{border-color:#9ca3af;}
		.uxs-fp-options--inline .uxs-fp-option:has(input:checked){background:#2563eb;border-color:#2563eb;color:#fff;}
		.uxs-fp-options--inline .uxs-fp-option:has(input:focus-visible){box-shadow:0 0 0 3px rgba(37,99,235,.3);}
		.uxs-fp-checkbox input{position:absolute;opacity:0;width:1px;height:1px;}
		.uxs-fp-check{width:18px;height:18px;border:2px solid #9ca3af;border-radius:4px;flex:0 0 18px;position:relative;transition:background .15s ease,border-color .15s ease;}
		.uxs-fp-check::after{content:"";position:absolute;left:4px;top:0px;width:5px;height:10px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg) scale(0);transition:transform .12s ease;}
		.uxs-fp-checkbox input:checked + .uxs-fp-check{background:#2563eb;border-color:#2563eb;}
		.uxs-fp-checkbox input:checked + .uxs-fp-check::after{transform:rotate(45deg) scale(1);}
		.uxs-fp-checkbox input:focus-visible + .uxs-fp-check{box-shadow:0 0 0 3px rgba(37,99,235,.25);}
		.uxs-fp-field.has-error .uxs-fp-check{border-color:#dc2626;}
		.uxs-fp-field--acceptance .uxs-fp-option,.uxs-fp-field--checkbox .uxs-fp-option{align-items:flex-start;}
		.uxs-fp-field--acceptance .uxs-fp-check,.uxs-fp-field--checkbox .uxs-fp-check{margin-top:1px;}
		.uxs-fp-dropzone{border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;position:relative;}
		.uxs-fp-dropzone.is-dragover{border-color:#2563eb;background:rgba(37,99,235,.05);}
		.uxs-fp-dropzone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
		.uxs-fp-dropzone__hint{margin:0;font-size:13px;color:#6b7280;}
		.uxs-fp-files{list-style:none;margin:10px 0 0;padding:0;font-size:13px;text-align:left;}
		.uxs-fp-files li{padding:2px 0;color:#1f2937;}
		.uxs-fp-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;}
		.uxs-fp-chip{background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 10px;font-size:12px;display:inline-flex;align-items:center;gap:6px;}
		.uxs-fp-chip button{border:0;background:none;color:inherit;cursor:pointer;font-size:12px;line-height:1;padding:0;}
		.uxs-fp-signature{display:flex;flex-direction:column;gap:8px;}
		.uxs-fp-signature__pad{width:100%;height:160px;border:1px solid #d1d5db;border-radius:6px;background:#fff;touch-action:none;cursor:crosshair;}
		.uxs-fp-signature__actions{display:flex;justify-content:flex-end;}
		.uxs-fp-hp{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;}
		.uxs-fp-html{font-size:14px;line-height:1.5;}
		.uxs-fp-nav{display:flex;align-items:center;gap:10px;margin-top:22px;}
		.uxs-fp-nav [data-uxs-next],.uxs-fp-nav [data-uxs-submit]{margin-left:auto;}
		.uxs-fp-nav [data-uxs-prev]:not([hidden]) ~ [data-uxs-next],.uxs-fp-nav [data-uxs-prev]:not([hidden]) ~ [data-uxs-submit]{margin-left:0;}
		.uxs-fp-nav [data-uxs-prev]:not([hidden]){margin-right:auto;}
		.uxs-fp-btn{border:0;border-radius:6px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;transition:opacity .15s ease,background .15s ease;min-height:42px;}
		.uxs-fp-btn[hidden]{display:none;}
		.uxs-fp-btn--primary{background:#2563eb;color:#fff;}
		.uxs-fp-btn--primary:hover{background:#1d4ed8;}
		.uxs-fp-btn--ghost{background:#e5e7eb;color:#1f2937;}
		.uxs-fp-btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.35);}
		.uxs-fp-btn:disabled{opacity:.6;cursor:not-allowed;}
		.uxs-fp-alert{padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:14px;}
		.uxs-fp-alert[hidden],.uxs-fp-progress[hidden]{display:none;}
		.uxs-fp-alert.is-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
		.uxs-fp-alert.is-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
		.uxs-form-public.is-done .uxs-fp-steps,.uxs-form-public.is-done .uxs-fp-nav,.uxs-form-public.is-done .uxs-fp-progress,.uxs-form-public.is-done .uxs-fp-alert,.uxs-form-public.is-done .uxs-fp-card__head{display:none;}
		<?php echo AfterSubmit::done_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		.uxs-fp-progress{margin-bottom:22px;}
		.uxs-fp-progress__list{display:flex;gap:8px;list-style:none;margin:0;padding:0;}
		.uxs-fp-progress__step{flex:1;min-width:0;}
		.uxs-fp-progress--steps .uxs-fp-progress__step{height:4px;border-radius:2px;background:#e5e7eb;}
		.uxs-fp-progress--steps .uxs-fp-progress__step.is-done,.uxs-fp-progress--steps .uxs-fp-progress__step.is-active{background:#2563eb;}
		.uxs-fp-progress--steps .uxs-fp-progress__num,.uxs-fp-progress--steps .uxs-fp-progress__label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);}
		.uxs-fp-progress--numbers .uxs-fp-progress__list{gap:0;}
		.uxs-fp-progress--numbers .uxs-fp-progress__step{display:flex;flex-direction:column;align-items:center;gap:6px;position:relative;text-align:center;}
		.uxs-fp-progress--numbers .uxs-fp-progress__step::before{content:"";position:absolute;top:15px;right:50%;width:100%;height:2px;background:#e5e7eb;z-index:0;}
		.uxs-fp-progress--numbers .uxs-fp-progress__step:first-child::before{display:none;}
		.uxs-fp-progress--numbers .uxs-fp-progress__step.is-done::before,.uxs-fp-progress--numbers .uxs-fp-progress__step.is-active::before{background:#2563eb;}
		.uxs-fp-progress--numbers .uxs-fp-progress__num{position:relative;z-index:1;display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;border:2px solid #d1d5db;background:#fff;color:#6b7280;font-weight:700;font-size:14px;transition:background .2s ease,border-color .2s ease,color .2s ease;}
		.uxs-fp-progress--numbers .is-active .uxs-fp-progress__num{border-color:#2563eb;color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.15);}
		.uxs-fp-progress--numbers .is-done .uxs-fp-progress__num{background:#2563eb;border-color:#2563eb;color:#fff;}
		.uxs-fp-progress--numbers .uxs-fp-progress__label{font-size:12px;font-weight:600;color:#6b7280;line-height:1.3;padding:0 4px;}
		.uxs-fp-progress--numbers .is-active .uxs-fp-progress__label{color:#111827;}
		.uxs-fp-progress--bar .uxs-fp-progress__meta{display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;font-size:13px;font-weight:600;color:#374151;}
		.uxs-fp-progress--bar .uxs-fp-progress__track{height:6px;border-radius:3px;background:#e5e7eb;overflow:hidden;}
		.uxs-fp-progress--bar .uxs-fp-progress__fill{display:block;height:100%;border-radius:3px;background:#2563eb;transition:width .25s ease;}
		@media (max-width:560px){
			.uxs-fp-progress--numbers .uxs-fp-progress__label{display:none;}
			.uxs-fp-progress--numbers .is-active .uxs-fp-progress__label{display:block;position:absolute;top:38px;left:50%;transform:translateX(-50%);white-space:nowrap;}
			.uxs-fp-progress--numbers{padding-bottom:22px;}
		}

		.uxs-fp-step__title{display:flex;align-items:center;gap:10px;font-size:17px;font-weight:700;margin:0 0 4px;padding:0;color:#111827;outline:none;}
		.uxs-fp-step__title:focus-visible{box-shadow:0 0 0 3px rgba(37,99,235,.3);border-radius:6px;}
		.uxs-fp-step__badge{display:none;align-items:center;justify-content:center;flex:0 0 28px;width:28px;height:28px;border-radius:50%;background:#2563eb;color:#fff;font-size:14px;font-weight:700;}
		.uxs-fp--numbered .uxs-fp-step__badge{display:inline-flex;}
		.uxs-fp-step__desc{margin:0 0 16px;color:#6b7280;font-size:14px;}
		.uxs-fp--numbered .uxs-fp-step__desc{margin-left:38px;}
		.uxs-fp-step__title + .uxs-fp-row{margin-top:16px;}

		.uxs-fp--card{border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.06);}
		.uxs-fp-card__head{padding:22px 24px 0;}
		.uxs-fp-card__title{margin:0;font-size:20px;font-weight:700;line-height:1.3;color:#111827;}
		.uxs-fp-card__desc{margin:4px 0 0;font-size:14px;color:#6b7280;}
		.uxs-fp--card .uxs-fp-body{padding:20px 24px;}
		.uxs-fp--card .uxs-fp-nav{margin:0;padding:14px 24px;border-top:1px solid #e5e7eb;background:#f9fafb;}

		.uxs-fp--inline .uxs-fp-nav--inline{grid-column:span 3;margin:0;align-self:start;}
		.uxs-fp--inline .uxs-fp-nav--inline .uxs-fp-btn{width:100%;white-space:nowrap;min-height:42px;}
		@media (max-width:1024px){.uxs-fp--inline .uxs-fp-nav--inline{grid-column:span 12;}}

		@media (max-width:560px){
			.uxs-fp--card .uxs-fp-body{padding:16px;}
			.uxs-fp-card__head{padding:18px 16px 0;}
			.uxs-fp--card .uxs-fp-nav{padding:12px 16px;}
			.uxs-fp-nav:not(.uxs-fp-nav--inline){flex-wrap:wrap;}
			.uxs-fp-nav:not(.uxs-fp-nav--inline) .uxs-fp-btn{flex:1 1 140px;}
			.uxs-fp-nav [data-uxs-prev]:not([hidden]){order:2;margin-right:0;}
			.uxs-fp-nav [data-uxs-next],.uxs-fp-nav [data-uxs-submit]{order:1;margin-left:0;}
		}
		@media (prefers-reduced-motion:reduce){.uxs-form-public *{transition:none !important;}}
		</style>
		<?php
	}

	/**
	 * One shared runtime script for every form on the page (registered
	 * lazily on first use, not unconditionally enqueued site-wide).
	 */
	private function print_runtime(): void {
		wp_register_script( 'uxstudio-forms-public', false, array(), UXSTUDIO_VERSION, true );
		wp_enqueue_script( 'uxstudio-forms-public' );
		echo '<script>' . self::runtime_body() . '</script>' . "
"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static plugin code.
	}

	/**
	 * JSON for a data block inside a script element: < > & are escaped so the text can never close the element
	 * or open an HTML comment.
	 *
	 * @param array<string, mixed> $config Client config.
	 */
	public static function config_json( array $config ): string {
		return (string) wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	}

	/**
	 * The text between <script> and </script> of the public runtime. Static (no per-request values), so the CSP
	 * module hashes exactly this string (CspInlineScripts, PLAN.md 20.14); the printed script uses the same function.
	 */
	public static function runtime_body(): string {
		static $body = null;
		if ( null === $body ) {
			$detect = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/forms-consent-detect.js' );
			ob_start();
			self::runtime_template( $detect );
			$body = (string) ob_get_clean();
		}
		return $body;
	}

	/**
	 * @param string $detect Source of assets/js/forms-consent-detect.js, inlined into the runtime.
	 */
	private static function runtime_template( string $detect ): void {
		?>
		<?php echo $detect; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin file. ?>

		window.UxStudioForms = ( function () {
			function fmt( text, a, b ) {
				return String( text ).replace( '%1$d', a ).replace( '%2$d', b ).replace( '%s', a );
			}

			function evalRule( rule, values ) {
				var v = values[ rule.field ];
				v = Array.isArray( v ) ? v.join( ', ' ) : ( v || '' );
				var want = rule.value || '';
				switch ( rule.operator ) {
					case 'not_equals': return v !== want;
					case 'contains': return want !== '' && v.toLowerCase().indexOf( want.toLowerCase() ) !== -1;
					case 'empty': return v.trim() === '';
					case 'not_empty': return v.trim() !== '';
					case 'greater': return v !== '' && want !== '' && ! isNaN( parseFloat( v ) ) && ! isNaN( parseFloat( want ) ) && parseFloat( v ) > parseFloat( want );
					case 'less': return v !== '' && want !== '' && ! isNaN( parseFloat( v ) ) && ! isNaN( parseFloat( want ) ) && parseFloat( v ) < parseFloat( want );
					default: return v === want;
				}
			}

			function collectValues( form ) {
				var values = {};
				form.querySelectorAll( '[data-uxs-field]' ).forEach( function ( wrap ) {
					var key = wrap.getAttribute( 'data-uxs-field' );
					var inputs = wrap.querySelectorAll( 'input, select, textarea' );
					if ( ! inputs.length ) { return; }
					if ( inputs.length > 1 && inputs[ 0 ].type === 'radio' ) {
						var checked = wrap.querySelector( 'input[type=radio]:checked' );
						values[ key ] = checked ? checked.value : '';
					} else if ( inputs[ 0 ].type === 'checkbox' && inputs.length > 1 ) {
						values[ key ] = Array.prototype.filter.call( inputs, function ( i ) { return i.checked; } ).map( function ( i ) { return i.value; } );
					} else if ( inputs[ 0 ].type === 'checkbox' ) {
						values[ key ] = inputs[ 0 ].checked ? '1' : '';
					} else if ( inputs[ 0 ].multiple ) {
						values[ key ] = Array.prototype.filter.call( inputs[ 0 ].options, function ( o ) { return o.selected; } ).map( function ( o ) { return o.value; } );
					} else {
						values[ key ] = inputs[ 0 ].value;
					}
				} );
				return values;
			}

			function applyConditions( form ) {
				var values = collectValues( form );
				form.querySelectorAll( '[data-uxs-field]' ).forEach( function ( wrap ) {
					var raw = wrap.getAttribute( 'data-conditions' );
					var conditions = [];
					try { conditions = JSON.parse( raw || '[]' ); } catch ( e ) {}
					if ( ! conditions.length ) { wrap.hidden = false; return; }
					var logic = wrap.getAttribute( 'data-logic' ) || 'all';
					var results = conditions.map( function ( r ) { return evalRule( r, values ); } );
					var active = logic === 'any' ? results.indexOf( true ) !== -1 : results.indexOf( false ) === -1;
					wrap.hidden = ! active;
				} );
			}

			function setupMultiselect( wrap, cfg ) {
				var select = wrap.querySelector( '[data-uxs-multiselect]' );
				var chips  = wrap.querySelector( '[data-uxs-chips]' );
				if ( ! select || ! chips ) { return; }
				function render() {
					chips.innerHTML = '';
					Array.prototype.filter.call( select.options, function ( o ) { return o.selected; } ).forEach( function ( o ) {
						var chip = document.createElement( 'span' );
						chip.className = 'uxs-fp-chip';
						var label = document.createElement( 'span' );
						label.textContent = o.textContent;
						var btn = document.createElement( 'button' );
						btn.type = 'button';
						btn.setAttribute( 'aria-label', cfg.i18n.removeChip );
						btn.textContent = '×';
						btn.addEventListener( 'click', function () { o.selected = false; render(); } );
						chip.appendChild( label );
						chip.appendChild( btn );
						chips.appendChild( chip );
					} );
				}
				select.addEventListener( 'change', render );
				render();
			}

			function setupDropzone( wrap ) {
				var zone  = wrap.querySelector( '[data-uxs-dropzone]' );
				var input = zone ? zone.querySelector( 'input[type=file]' ) : null;
				var list  = zone ? zone.querySelector( '[data-uxs-file-list]' ) : null;
				if ( ! zone || ! input ) { return; }
				function renderList() {
					list.innerHTML = '';
					Array.prototype.forEach.call( input.files, function ( f ) {
						var li = document.createElement( 'li' );
						li.textContent = f.name + ' (' + Math.round( f.size / 1024 ) + ' KB)';
						list.appendChild( li );
					} );
				}
				input.addEventListener( 'change', renderList );
				[ 'dragenter', 'dragover' ].forEach( function ( evt ) {
					zone.addEventListener( evt, function ( e ) { e.preventDefault(); zone.classList.add( 'is-dragover' ); } );
				} );
				[ 'dragleave', 'drop' ].forEach( function ( evt ) {
					zone.addEventListener( evt, function ( e ) { e.preventDefault(); zone.classList.remove( 'is-dragover' ); } );
				} );
				zone.addEventListener( 'drop', function ( e ) {
					if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length ) {
						input.files = e.dataTransfer.files;
						renderList();
					}
				} );
			}

			function setupSignature( wrap ) {
				var container = wrap.querySelector( '[data-uxs-signature]' );
				if ( ! container ) { return; }
				var canvas = container.querySelector( 'canvas' );
				var input = container.querySelector( 'input[type=hidden]' );
				var clearBtn = container.querySelector( '[data-uxs-signature-clear]' );
				if ( ! canvas || ! input ) { return; }
				var ctx = canvas.getContext( '2d' );
				var drawing = false;
				var hasInk = false;

				function resize() {
					var ratio = window.devicePixelRatio || 1;
					var rect = canvas.getBoundingClientRect();
					var wasInk = hasInk;
					canvas.width = Math.max( 1, rect.width ) * ratio;
					canvas.height = 160 * ratio;
					ctx.scale( ratio, ratio );
					ctx.lineWidth = 2;
					ctx.lineCap = 'round';
					ctx.strokeStyle = '#111827';
					// A resize clears the canvas bitmap - an in-progress signature
					// can't be preserved across a breakpoint change, so start over
					// rather than silently keep a stale value in the hidden input.
					if ( wasInk ) {
						hasInk = false;
						input.value = '';
					}
				}
				resize();
				window.addEventListener( 'resize', resize );

				function pointFromEvent( e ) {
					var rect = canvas.getBoundingClientRect();
					var point = e.touches && e.touches.length ? e.touches[ 0 ] : e;
					return { x: point.clientX - rect.left, y: point.clientY - rect.top };
				}
				function start( e ) {
					e.preventDefault();
					drawing = true;
					var p = pointFromEvent( e );
					ctx.beginPath();
					ctx.moveTo( p.x, p.y );
				}
				function move( e ) {
					if ( ! drawing ) { return; }
					e.preventDefault();
					var p = pointFromEvent( e );
					ctx.lineTo( p.x, p.y );
					ctx.stroke();
					hasInk = true;
				}
				function end() {
					if ( ! drawing ) { return; }
					drawing = false;
					input.value = hasInk ? canvas.toDataURL( 'image/png' ) : '';
				}
				canvas.addEventListener( 'mousedown', start );
				canvas.addEventListener( 'mousemove', move );
				window.addEventListener( 'mouseup', end );
				canvas.addEventListener( 'touchstart', start, { passive: false } );
				canvas.addEventListener( 'touchmove', move, { passive: false } );
				canvas.addEventListener( 'touchend', end );
				if ( clearBtn ) {
					clearBtn.addEventListener( 'click', function () {
						ctx.clearRect( 0, 0, canvas.width, canvas.height );
						hasInk = false;
						input.value = '';
					} );
				}
			}

			function stepFields( form, index ) {
				var step = form.querySelector( '[data-uxs-step="' + index + '"]' );
				return step ? step.querySelectorAll( '[data-uxs-field]:not([hidden])' ) : [];
			}

			function fieldDef( cfg, key ) {
				return cfg.fields.filter( function ( f ) { return f.key === key; } )[ 0 ];
			}

			/** Message for the first problem of a field, '' when it is fine. */
			function fieldProblem( wrap, cfg ) {
				var key = wrap.getAttribute( 'data-uxs-field' );
				var def = fieldDef( cfg, key );
				var inputs = wrap.querySelectorAll( 'input, select, textarea' );
				var message = '';
				Array.prototype.some.call( inputs, function ( input ) {
					if ( input.type === 'hidden' || input.checkValidity() ) { return false; }
					var v = input.validity;
					if ( v.valueMissing ) { message = cfg.i18n.required; }
					else if ( v.typeMismatch ) { message = input.type === 'email' ? cfg.i18n.email : ( input.type === 'url' ? cfg.i18n.url : cfg.i18n.invalid ); }
					else if ( v.badInput ) { message = input.type === 'number' ? cfg.i18n.number : cfg.i18n.invalid; }
					else if ( v.rangeUnderflow ) { message = fmt( cfg.i18n.min, input.min ); }
					else if ( v.rangeOverflow ) { message = fmt( cfg.i18n.max, input.max ); }
					else if ( v.tooShort ) { message = fmt( cfg.i18n.tooShort, input.minLength ); }
					else if ( v.tooLong ) { message = fmt( cfg.i18n.tooLong, input.maxLength ); }
					else { message = input.validationMessage || cfg.i18n.invalid; }
					return true;
				} );
				// A `signature` field's actual control is a <canvas>, not a
				// native form element - its hidden mirror input is type=hidden,
				// which the constraint-validation API always treats as valid
				// regardless of `required`, so check it manually here.
				if ( ! message && def && def.required && def.type === 'signature' ) {
					var sigInput = wrap.querySelector( '[data-uxs-signature] input[type=hidden]' );
					if ( sigInput && ! sigInput.value ) { message = cfg.i18n.required; }
				}
				// A required group of checkboxes has no native `required`.
				if ( ! message && def && def.required && def.type === 'checkbox_group' && ! wrap.querySelector( 'input:checked' ) ) {
					message = cfg.i18n.required;
				}
				return message;
			}

			function showFieldState( wrap, message ) {
				var errorEl = wrap.querySelector( '[data-uxs-field-error]' );
				var controls = wrap.querySelectorAll( 'input:not([type=hidden]), select, textarea' );
				wrap.classList.toggle( 'has-error', !! message );
				if ( errorEl ) { errorEl.textContent = message; }
				controls.forEach( function ( c ) {
					if ( message ) {
						c.setAttribute( 'aria-invalid', 'true' );
						if ( errorEl && errorEl.id ) { c.setAttribute( 'aria-describedby', errorEl.id ); }
					} else {
						c.removeAttribute( 'aria-invalid' );
						c.removeAttribute( 'aria-describedby' );
					}
				} );
			}

			function focusField( wrap ) {
				var target = wrap.querySelector( 'input:not([type=hidden]), select, textarea, canvas' );
				if ( target && typeof target.focus === 'function' ) {
					if ( target.tagName === 'CANVAS' ) { target.setAttribute( 'tabindex', '-1' ); }
					target.focus();
				}
			}

			function showAlert( form, kind, text, focus ) {
				var alertEl = form.querySelector( '[data-uxs-alert]' );
				if ( ! alertEl ) { return; }
				if ( ! text ) { alertEl.hidden = true; alertEl.textContent = ''; return; }
				alertEl.hidden = false;
				alertEl.className = 'uxs-fp-alert is-' + kind;
				alertEl.textContent = text;
			}

			/** Validate the visible fields of one step. Returns the number of invalid fields. */
			function validateStep( form, index, cfg, focusFirst ) {
				var invalid = [];
				stepFields( form, index ).forEach( function ( wrap ) {
					var message = fieldProblem( wrap, cfg );
					showFieldState( wrap, message );
					if ( message ) { invalid.push( wrap ); }
				} );
				if ( invalid.length ) {
					showAlert( form, 'error', fmt( cfg.i18n.fixErrors, invalid.length ) );
					if ( focusFirst ) { focusField( invalid[ 0 ] ); }
				}
				return invalid.length;
			}

			function progressItems( cfg, total ) {
				var items = [];
				for ( var i = 0; i < total; i++ ) {
					var s = cfg.steps && cfg.steps[ i ] ? cfg.steps[ i ] : { title: '' };
					items.push( s.title || fmt( cfg.i18n.stepOf, i + 1, total ) );
				}
				return items;
			}

			function updateProgress( form, index, total, cfg ) {
				var bar = form.querySelector( '[data-uxs-progress]' );
				if ( ! bar ) { return; }
				var style = cfg.progressStyle;
				if ( 'none' === style || total <= 1 ) {
					bar.hidden = true;
					bar.innerHTML = '';
					return;
				}
				bar.hidden = false;
				bar.innerHTML = '';
				bar.setAttribute( 'role', 'group' );
				bar.setAttribute( 'aria-label', cfg.i18n.progress );
				var titles = progressItems( cfg, total );
				if ( 'bar' === style ) {
					bar.className = 'uxs-fp-progress uxs-fp-progress--bar';
					var meta = document.createElement( 'div' );
					meta.className = 'uxs-fp-progress__meta';
					var left = document.createElement( 'span' );
					left.textContent = fmt( cfg.i18n.stepOf, index + 1, total );
					var right = document.createElement( 'span' );
					right.textContent = titles[ index ];
					meta.appendChild( left );
					meta.appendChild( right );
					var track = document.createElement( 'div' );
					track.className = 'uxs-fp-progress__track';
					var fill = document.createElement( 'span' );
					fill.className = 'uxs-fp-progress__fill';
					fill.style.width = Math.round( ( ( index + 1 ) / total ) * 100 ) + '%';
					track.appendChild( fill );
					bar.appendChild( meta );
					bar.appendChild( track );
					return;
				}
				bar.className = 'uxs-fp-progress uxs-fp-progress--' + ( 'numbers' === style ? 'numbers' : 'steps' );
				var list = document.createElement( 'ol' );
				list.className = 'uxs-fp-progress__list';
				for ( var i = 0; i < total; i++ ) {
					var li = document.createElement( 'li' );
					li.className = 'uxs-fp-progress__step' + ( i < index ? ' is-done' : ( i === index ? ' is-active' : '' ) );
					if ( i === index ) { li.setAttribute( 'aria-current', 'step' ); }
					var num = document.createElement( 'span' );
					num.className = 'uxs-fp-progress__num';
					num.setAttribute( 'aria-hidden', 'true' );
					num.textContent = i < index ? '✓' : String( i + 1 );
					var label = document.createElement( 'span' );
					label.className = 'uxs-fp-progress__label';
					label.textContent = titles[ i ];
					li.appendChild( num );
					li.appendChild( label );
					list.appendChild( li );
				}
				bar.appendChild( list );
			}

			/** @param {boolean} focusHead Move focus to the new step heading (keyboard / screen reader users). */
			function goToStep( form, index, cfg, focusHead ) {
				var steps = form.querySelectorAll( '[data-uxs-step]' );
				steps.forEach( function ( s, i ) { s.hidden = i !== index; } );
				form.setAttribute( 'data-uxs-current-step', String( index ) );
				updateProgress( form, index, steps.length, cfg );
				var prev = form.querySelector( '[data-uxs-prev]' );
				var next = form.querySelector( '[data-uxs-next]' );
				var submit = form.querySelector( '[data-uxs-submit]' );
				if ( prev ) { prev.hidden = index === 0; }
				if ( steps.length > 1 && index < steps.length - 1 ) {
					if ( next ) { next.hidden = false; }
					if ( submit ) { submit.hidden = true; }
				} else {
					if ( next ) { next.hidden = true; }
					if ( submit ) { submit.hidden = false; }
				}
				if ( focusHead && steps[ index ] ) {
					var head = steps[ index ].querySelector( '[data-uxs-step-head]' );
					if ( head ) { head.focus(); }
				}
			}

			function stepOfField( form, key ) {
				var wrap = form.querySelector( '[data-uxs-field="' + key + '"]' );
				var step = wrap ? wrap.closest( '[data-uxs-step]' ) : null;
				return step ? parseInt( step.getAttribute( 'data-uxs-step' ), 10 ) : 0;
			}

			/** Server-built thank-you (title as text, message as already sanitized and merged HTML) shown instead of, or above, the form. */
			function showDone( form, cfg, d, goToStep, showFieldState, applyConditions, showAlert ) {
				var done = form.querySelector( '[data-uxs-done]' );
				if ( ! done ) { return; }
				var inline = d.mode === 'message';
				var iconEl = done.querySelector( '[data-uxs-done-icon]' );
				var titleEl = done.querySelector( '[data-uxs-done-title]' );
				var textEl = done.querySelector( '[data-uxs-done-text]' );
				var again = done.querySelector( '[data-uxs-again]' );

				done.className = 'uxs-fp-done' + ( inline ? ' uxs-fp-done--inline' : '' ) + ( d.icon === 'none' ? ' uxs-fp-done--no-icon' : '' );
				if ( d.accent ) { done.style.setProperty( '--uxs-done', d.accent ); } else { done.style.removeProperty( '--uxs-done' ); }
				if ( iconEl ) { iconEl.innerHTML = d.icon === 'none' ? '' : cfg.iconSvg; }
				if ( titleEl ) { titleEl.textContent = d.title || ''; titleEl.hidden = ! d.title; }
				if ( textEl ) {
					if ( typeof d.html === 'string' && d.html !== '' ) { textEl.innerHTML = d.html; } else { textEl.textContent = d.message || cfg.successText; }
				}
				if ( again ) { again.hidden = inline || ! d.again; }

				form.reset();
				form.querySelectorAll( '[data-uxs-field]' ).forEach( function ( w ) { showFieldState( w, '' ); } );
				applyConditions( form );
				form.querySelectorAll( '[data-uxs-multiselect]' ).forEach( function ( s ) { s.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } );
				form.querySelectorAll( '[data-uxs-file-list]' ).forEach( function ( l ) { l.innerHTML = ''; } );
				showAlert( form, 'error', '' );
				goToStep( form, 0, cfg, false );

				form.classList.toggle( 'is-done', ! inline );
				form.classList.toggle( 'has-notice', inline );
				done.hidden = false;
				// Restart the entrance and the drawing of the check mark.
				void done.offsetWidth;
				done.classList.add( 'is-playing' );

				var target = titleEl && ! titleEl.hidden ? titleEl : done;
				target.focus( { preventScroll: true } );
				if ( d.scroll !== false ) {
					var top = done.getBoundingClientRect().top;
					if ( top < 80 || top > window.innerHeight * 0.6 ) {
						var calm = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
						done.scrollIntoView( { behavior: calm ? 'auto' : 'smooth', block: 'center' } );
					}
				}
			}

			/**
			 * One successful, counted submission: a DOM event, the dataLayer, the configured trackers that are ALREADY on the
			 * page (nothing is loaded here, consent tools stay in charge) and the custom script. No field values go anywhere.
			 */
			function track( form, cfg, detail, leaving ) {
				var t = cfg.tracking || {};
				var seen = form.getAttribute( 'data-uxs-tracked' ) || '';
				if ( seen === String( detail.submissionId ) ) { return; }
				form.setAttribute( 'data-uxs-tracked', String( detail.submissionId ) );

				var frozen = Object.freeze( { formId: detail.formId, formTitle: detail.formTitle, submissionId: detail.submissionId } );
				function safely( fn ) { try { fn(); } catch ( err ) { if ( window.console && console.error ) { console.error( 'UX Studio form tracking:', err ); } } }

				safely( function () {
					form.dispatchEvent( new CustomEvent( 'uxstudio:form-submitted', { bubbles: true, detail: frozen } ) );
				} );
				if ( t.datalayer ) {
					safely( function () {
						( window.dataLayer = window.dataLayer || [] ).push( { event: 'uxstudio_form_submit', form_id: detail.formId, form_title: detail.formTitle, submission_id: detail.submissionId } );
					} );
				}
				var beacon = leaving ? { transport_type: 'beacon' } : {};
				if ( t.ga4 && typeof window.gtag === 'function' ) {
					safely( function () {
						var p = { form_id: detail.formId, form_title: detail.formTitle };
						if ( leaving ) { p.transport_type = 'beacon'; }
						window.gtag( 'event', t.ga4.event, p );
					} );
				}
				if ( t.ads && typeof window.gtag === 'function' ) {
					safely( function () {
						var p = { send_to: t.ads.sendTo };
						if ( t.ads.value !== null && t.ads.value !== undefined ) { p.value = t.ads.value; p.currency = t.ads.currency; }
						if ( beacon.transport_type ) { p.transport_type = 'beacon'; }
						window.gtag( 'event', 'conversion', p );
					} );
				}
				if ( t.meta && typeof window.fbq === 'function' ) {
					safely( function () { window.fbq( t.meta.method, t.meta.event ); } );
				}
				if ( t.sklik && window.rc && typeof window.rc.conversionHit === 'function' ) {
					safely( function () {
						// 'auto': what the visitor's cookie banner says right now; the fallback only when no known banner is on the page.
						var consent = t.sklik.consent;
						if ( consent === 'auto' ) {
							var seen = uxsDetectMarketingConsent( window );
							consent = seen === null ? t.sklik.fallback : seen;
						}
						var c = { id: t.sklik.id, consent: consent };
						if ( t.sklik.value !== null && t.sklik.value !== undefined ) { c.value = t.sklik.value; }
						window.rc.conversionHit( c );
					} );
				}
				safely( function () {
					var hook = window.uxstudioFormHooks && window.uxstudioFormHooks[ detail.formId ];
					if ( typeof hook === 'function' ) { hook( frozen ); }
				} );
			}

			function init( form, cfg ) {
				if ( ! form || form.getAttribute( 'data-uxs-inited' ) ) { return; }
				form.setAttribute( 'data-uxs-inited', '1' );

				form.querySelectorAll( '.uxs-fp-field' ).forEach( function ( wrap ) {
					setupMultiselect( wrap, cfg );
					setupDropzone( wrap );
					setupSignature( wrap );
				} );

				// Clear / refresh a field's message as soon as the visitor fixes it.
				function recheck( e ) {
					var wrap = e.target.closest ? e.target.closest( '[data-uxs-field]' ) : null;
					if ( wrap && wrap.classList.contains( 'has-error' ) ) { showFieldState( wrap, fieldProblem( wrap, cfg ) ); }
				}
				form.addEventListener( 'input', function ( e ) { applyConditions( form ); recheck( e ); } );
				form.addEventListener( 'change', function ( e ) { applyConditions( form ); recheck( e ); } );
				applyConditions( form );

				var steps = form.querySelectorAll( '[data-uxs-step]' );
				var last = steps.length - 1;
				goToStep( form, 0, cfg, false );

				var next = form.querySelector( '[data-uxs-next]' );
				var prev = form.querySelector( '[data-uxs-prev]' );
				function current() { return parseInt( form.getAttribute( 'data-uxs-current-step' ) || '0', 10 ); }
				function goNext() {
					var now = current();
					if ( validateStep( form, now, cfg, true ) === 0 ) {
						showAlert( form, 'error', '' );
						goToStep( form, Math.min( last, now + 1 ), cfg, true );
					}
				}
				if ( next ) { next.addEventListener( 'click', goNext ); }
				if ( prev ) {
					prev.addEventListener( 'click', function () {
						showAlert( form, 'error', '' );
						goToStep( form, Math.max( 0, current() - 1 ), cfg, true );
					} );
				}

				// Enter inside a text input must advance a multi-step form, never submit it half-filled.
				form.addEventListener( 'keydown', function ( e ) {
					if ( e.key !== 'Enter' || last < 1 || current() >= last ) { return; }
					var t = e.target;
					if ( t && t.tagName === 'INPUT' && [ 'text', 'email', 'tel', 'url', 'number', 'date', 'time', 'password' ].indexOf( t.type ) !== -1 ) {
						e.preventDefault();
						goNext();
					}
				} );

				var again = form.querySelector( '[data-uxs-again]' );
				if ( again ) {
					again.addEventListener( 'click', function () {
						form.classList.remove( 'is-done' );
						var done = form.querySelector( '[data-uxs-done]' );
						if ( done ) { done.hidden = true; }
						showAlert( form, 'error', '' );
						goToStep( form, 0, cfg, true );
						if ( last < 1 ) { var first = form.querySelector( '[data-uxs-field]:not([hidden])' ); if ( first ) { focusField( first ); } }
					} );
				}

				form.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					if ( form.getAttribute( 'aria-busy' ) || form.getAttribute( 'data-uxs-leaving' ) ) { return; }
					if ( current() < last ) { goNext(); return; }

					// Every step again, in order: the first one with a problem is shown.
					for ( var i = 0; i <= last; i++ ) {
						if ( validateStep( form, i, cfg, false ) > 0 ) {
							goToStep( form, i, cfg, false );
							validateStep( form, i, cfg, true );
							return;
						}
					}
					showAlert( form, 'error', '' );

					var oldNotice = form.querySelector( '[data-uxs-done]' );
					if ( oldNotice && form.classList.contains( 'has-notice' ) ) { oldNotice.hidden = true; form.classList.remove( 'has-notice' ); }
					var submitBtn = form.querySelector( '[data-uxs-submit]' );
					var values = collectValues( form );
					// Start from every native form field (this is what carries the
					// honeypot input and any CAPTCHA provider's own hidden response
					// field through to $_POST server-side, plus files) and layer the
					// structured 'id'/'data' contract the REST handler expects on top.
					var fd = new FormData( form );
					fd.set( 'id', form.getAttribute( 'data-form-id' ) );
					fd.set( 'data', JSON.stringify( values ) );

					if ( submitBtn ) { submitBtn.disabled = true; }
					form.setAttribute( 'aria-busy', 'true' );
					fetch( cfg.restUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( res ) { return res.json().then( function ( json ) { return { ok: res.ok, json: json }; } ); } )
						.then( function ( result ) {
							if ( result.ok ) {
								var d = ( result.json && result.json.data ) || {};
								var detail = { formId: cfg.formId, formTitle: d.form_title || cfg.formTitle, submissionId: parseInt( d.submission_id, 10 ) || 0 };
								if ( d.redirect && /^(https?:\/\/|\/)/i.test( d.redirect ) ) {
									form.setAttribute( 'data-uxs-leaving', '1' );
									// Count the conversion first, then leave (gtag uses a beacon, so a short pause is enough).
									if ( detail.submissionId > 0 ) { track( form, cfg, detail, true ); }
									window.setTimeout( function () { window.location.href = d.redirect; }, detail.submissionId > 0 ? 200 : 0 );
									return;
								}
								showDone( form, cfg, d, goToStep, showFieldState, applyConditions, showAlert );
								// After the thank-you is on screen: a failing tracker or custom script must never take it away.
								if ( detail.submissionId > 0 ) { track( form, cfg, detail, false ); }
							} else {
								var fieldErrors = result.json && result.json.data && result.json.data.fields;
								var firstKey = '';
								if ( fieldErrors ) {
									Object.keys( fieldErrors ).forEach( function ( key ) {
										var wrap = form.querySelector( '[data-uxs-field="' + key + '"]' );
										if ( wrap ) {
											showFieldState( wrap, fieldErrors[ key ] );
											if ( ! firstKey ) { firstKey = key; }
										}
									} );
								}
								showAlert( form, 'error', ( result.json && result.json.message ) || cfg.i18n.genericError );
								if ( firstKey ) {
									// The server found a problem on an earlier step: take the visitor there.
									goToStep( form, stepOfField( form, firstKey ), cfg, false );
									var w = form.querySelector( '[data-uxs-field="' + firstKey + '"]' );
									if ( w ) { focusField( w ); }
								}
							}
						} )
						.catch( function () {
							showAlert( form, 'error', cfg.i18n.genericError );
						} )
						.finally( function () {
							if ( submitBtn ) { submitBtn.disabled = false; }
							form.removeAttribute( 'aria-busy' );
							resetCaptcha( form );
						} );
				} );
			}

			// CAPTCHA tokens are single-use and the server has consumed this
			// one (or will reject it again) - render a fresh challenge so a
			// second submission works without reloading the page. reCAPTCHA v3
			// needs nothing: its footer script refreshes the token on submit.
			function resetCaptcha( form ) {
				try {
					if ( window.turnstile ) {
						form.querySelectorAll( '.cf-turnstile' ).forEach( function ( el ) { window.turnstile.reset( el ); } );
					}
					if ( window.grecaptcha && typeof window.grecaptcha.reset === 'function' ) {
						// Auto-rendered v2 widgets get ids in document order.
						var all = Array.prototype.slice.call( document.querySelectorAll( '.g-recaptcha' ) );
						form.querySelectorAll( '.g-recaptcha' ).forEach( function ( el ) { window.grecaptcha.reset( all.indexOf( el ) ); } );
					}
				} catch ( err ) {}
			}

			// Forms hand their config over as <script type="application/json" data-uxs-form-config="FORM-ELEMENT-ID">.
			var CONFIG_SELECTOR = 'script[type="application/json"][data-uxs-form-config]';
			function scan() {
				document.querySelectorAll( CONFIG_SELECTOR ).forEach( function ( block ) {
					if ( block.getAttribute( 'data-uxs-scanned' ) ) { return; }
					block.setAttribute( 'data-uxs-scanned', '1' );
					var el = document.getElementById( block.getAttribute( 'data-uxs-form-config' ) );
					var cfg = null;
					try { cfg = JSON.parse( block.textContent ); } catch ( err ) {}
					if ( el && cfg ) { init( el, cfg ); }
				} );
			}

			var api = { init: init, scan: scan };
			if ( window.UxStudioFormsQueue ) {
				window.UxStudioFormsQueue.forEach( function ( pair ) {
					var el = document.getElementById( pair[ 0 ] );
					if ( el ) { init( el, pair[ 1 ] ); }
				} );
				window.UxStudioFormsQueue = [];
			}
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', scan );
			} else {
				scan();
			}
			// A form added later (a popup, an AJAX-loaded block) brings its own config block.
			if ( window.MutationObserver ) {
				var queued = false;
				new MutationObserver( function ( records ) {
					if ( queued ) { return; }
					for ( var i = 0; i < records.length; i++ ) {
						for ( var j = 0; j < records[ i ].addedNodes.length; j++ ) {
							var node = records[ i ].addedNodes[ j ];
							if ( node.nodeType === 1 && ( ( node.matches && node.matches( CONFIG_SELECTOR ) ) || ( node.querySelector && node.querySelector( CONFIG_SELECTOR ) ) ) ) {
								queued = true;
								window.setTimeout( function () { queued = false; scan(); }, 0 );
								return;
							}
						}
					}
				} ).observe( document.documentElement, { childList: true, subtree: true } );
			}
			return api;
		} )();
		<?php
	}
}
