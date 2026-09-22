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
 */
final class PublicRenderer {

	/**
	 * @param array $form Full form array (id, title, fields, settings).
	 */
	public function render( array $form ): string {
		static $styles_printed = false;

		$fields   = (array) $form['fields'];
		$settings = (array) $form['settings'];
		$uid      = 'uxsf-' . (int) $form['id'] . '-' . wp_generate_password( 6, false, false );

		ob_start();

		if ( ! $styles_printed ) {
			$this->print_styles();
			$styles_printed = true;
		}
		?>
		<form
			class="uxs-form-public"
			id="<?php echo esc_attr( $uid ); ?>"
			data-uxs-form
			data-form-id="<?php echo (int) $form['id']; ?>"
			data-progress="<?php echo esc_attr( (string) ( $settings['progress_style'] ?? 'steps' ) ); ?>"
			novalidate
		>
			<div class="uxs-fp-progress" data-uxs-progress hidden></div>
			<div class="uxs-fp-alert" data-uxs-alert role="alert" hidden></div>

			<?php $this->render_honeypot(); ?>

			<?php echo $this->render_steps( $fields, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="uxs-fp-nav">
				<button type="button" class="uxs-fp-btn uxs-fp-btn--ghost" data-uxs-prev hidden><?php esc_html_e( 'Back', 'ux-studio' ); ?></button>
				<button type="button" class="uxs-fp-btn uxs-fp-btn--primary" data-uxs-next hidden><?php esc_html_e( 'Next', 'ux-studio' ); ?></button>
				<button type="submit" class="uxs-fp-btn uxs-fp-btn--primary" data-uxs-submit><?php esc_html_e( 'Submit', 'ux-studio' ); ?></button>
			</div>
		</form>
		<script>
		( function () {
			var cfg = <?php echo wp_json_encode( $this->client_config( $form ) ); ?>;
			if ( window.UxStudioForms && typeof window.UxStudioForms.init === 'function' ) {
				window.UxStudioForms.init( document.getElementById( <?php echo wp_json_encode( $uid ); ?> ), cfg );
			} else {
				( window.UxStudioFormsQueue = window.UxStudioFormsQueue || [] ).push( [ <?php echo wp_json_encode( $uid ); ?>, cfg ] );
			}
		} )();
		</script>
		<?php
		if ( ! wp_script_is( 'uxstudio-forms-public', 'done' ) ) {
			$this->print_runtime();
		}

		return (string) ob_get_clean();
	}

	/**
	 * @return array{restUrl:string,fields:array,labelDisplay:string,progressStyle:string}
	 */
	private function client_config( array $form ): array {
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
			'labelDisplay'  => (string) ( $settings['label_display'] ?? 'visible' ),
			'progressStyle' => (string) ( $settings['progress_style'] ?? 'steps' ),
			'successText'   => (string) ( $settings['success_text'] ?? __( 'Thank you, your submission has been received.', 'ux-studio' ) ),
			'i18n'          => array(
				'required'      => __( 'This field is required.', 'ux-studio' ),
				'genericError'  => __( 'Something went wrong, please try again.', 'ux-studio' ),
				'removeChip'    => __( 'Remove', 'ux-studio' ),
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
	 * Splits fields[] into steps at every `step` field and renders each as a
	 * <fieldset data-uxs-step>. A form with no `step` fields is one implicit step.
	 */
	private function render_steps( array $fields, array $settings ): string {
		$steps = array( array( 'title' => '', 'fields' => array() ) );
		foreach ( $fields as $field ) {
			if ( 'step' === ( $field['type'] ?? '' ) ) {
				$steps[] = array(
					'title'  => (string) ( $field['step_title'] ?? '' ),
					'fields' => array(),
				);
				continue;
			}
			$steps[ count( $steps ) - 1 ]['fields'][] = $field;
		}
		// Drop a leading empty step when the form starts with a `step` field.
		if ( count( $steps ) > 1 && empty( $steps[0]['fields'] ) ) {
			array_shift( $steps );
		}

		$html = '';
		foreach ( $steps as $i => $step ) {
			$html .= '<fieldset class="uxs-fp-step" data-uxs-step="' . (int) $i . '"' . ( $i > 0 ? ' hidden' : '' ) . '>';
			if ( '' !== $step['title'] && count( $steps ) > 1 ) {
				$html .= '<legend class="uxs-fp-step__title">' . esc_html( $step['title'] ) . '</legend>';
			}
			$html .= '<div class="uxs-fp-row">';
			foreach ( $step['fields'] as $field ) {
				$html .= $this->render_field( $field, $settings );
			}
			$html .= '</div></fieldset>';
		}
		return $html;
	}

	private function render_field( array $field, array $settings ): string {
		$type = (string) $field['type'];
		if ( 'html' === $type ) {
			return sprintf( '<div class="uxs-fp-html" style="%s">%s</div>', esc_attr( $this->width_style( $field ) ), wp_kses_post( (string) ( $field['html'] ?? '' ) ) );
		}
		if ( 'hidden' === $type ) {
			return $this->render_control( $field, '', '' );
		}

		$key         = (string) $field['key'];
		$label       = (string) $field['label'];
		$required    = ! empty( $field['required'] );
		$label_mode  = 'inherit' === ( $field['label_display'] ?? 'inherit' ) ? (string) ( $settings['label_display'] ?? 'visible' ) : (string) $field['label_display'];
		$placeholder = (string) ( $field['placeholder'] ?? '' );
		if ( 'placeholder_only' === $label_mode && '' === $placeholder ) {
			$placeholder = $label;
		}

		$wrap_attrs = sprintf(
			'class="uxs-fp-field uxs-fp-field--%1$s%2$s" style="%3$s" data-uxs-field="%4$s" data-conditions=\'%5$s\' data-logic="%6$s"',
			esc_attr( $type ),
			$required ? ' is-required' : '',
			esc_attr( $this->width_style( $field ) ),
			esc_attr( $key ),
			esc_attr( wp_json_encode( $field['conditions'] ?? array() ) ?: '[]' ),
			esc_attr( (string) ( $field['logic'] ?? 'all' ) )
		);

		ob_start();
		echo '<div ' . $wrap_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$show_label = 'placeholder_only' !== $label_mode && 'html' !== $type && 'captcha' !== $type;
		$label_class = 'placeholder_only' === $label_mode ? 'uxs-sr-only' : 'uxs-fp-label';

		if ( $show_label || 'placeholder_only' === $label_mode ) {
			printf(
				'<label class="%1$s" for="%2$s">%3$s%4$s</label>',
				esc_attr( $label_class ),
				esc_attr( $uid = 'uxsf-' . $key . '-' . wp_generate_password( 4, false, false ) ),
				esc_html( $label ),
				$required ? '<span class="uxs-fp-req" aria-hidden="true">*</span>' : ''
			);
		} else {
			$uid = 'uxsf-' . $key . '-' . wp_generate_password( 4, false, false );
		}

		echo $this->render_control( $field, $uid, $placeholder ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="uxs-fp-error" data-uxs-field-error></span>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	private function render_control( array $field, string $uid, string $placeholder ): string {
		$type     = (string) $field['type'];
		$key      = (string) $field['key'];
		$required = ! empty( $field['required'] ) ? ' required' : '';
		$name     = esc_attr( $key );

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
				$html = '<div class="uxs-fp-options" role="radiogroup">';
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
				$html = '<div class="uxs-fp-options">';
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
					'<label class="uxs-fp-option uxs-fp-checkbox"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s><span class="uxs-fp-check" aria-hidden="true"></span><span class="uxs-fp-checkbox-text">%4$s</span></label>',
					esc_attr( $uid ),
					$name,
					$required,
					esc_html( $placeholder )
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
					'<label class="uxs-fp-option uxs-fp-checkbox"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s><span class="uxs-fp-check" aria-hidden="true"></span><span>%4$s%5$s</span></label>',
					esc_attr( $uid ),
					$name,
					$required,
					$text,
					'' !== $terms ? ' <a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms', 'ux-studio' ) . '</a>' : ''
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
	 * widget; otherwise a neutral placeholder (full verification wiring is
	 * PLAN.md 20.11 phase F2 - the field type/slot exists from F1).
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
	 * @param array $field Field with width/width_tablet/width_mobile (0-100, layout types always 100).
	 */
	private function width_style( array $field ): string {
		$w = max( 1, min( 100, (int) ( $field['width'] ?? 100 ) ) );
		return 'flex-basis:' . $w . '%;max-width:' . $w . '%;';
	}

	/**
	 * Scoped, self-contained CSS - printed once per page regardless of how
	 * many form shortcodes render (same guard pattern as PopupManager).
	 */
	private function print_styles(): void {
		?>
		<style>
		.uxs-form-public{display:block;max-width:100%;}
		.uxs-fp-row{display:flex;flex-wrap:wrap;gap:16px;}
		.uxs-fp-field{display:flex;flex-direction:column;gap:6px;box-sizing:border-box;padding-right:0;}
		@media (max-width:782px){.uxs-fp-field{flex-basis:100% !important;max-width:100% !important;}}
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
		.uxs-fp-error{color:#dc2626;font-size:12px;min-height:14px;}
		.uxs-fp-options{display:flex;flex-direction:column;gap:8px;}
		.uxs-fp-option{display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;}
		.uxs-fp-checkbox input{position:absolute;opacity:0;width:1px;height:1px;}
		.uxs-fp-check{width:18px;height:18px;border:2px solid #9ca3af;border-radius:4px;flex:0 0 18px;position:relative;transition:background .15s ease,border-color .15s ease;}
		.uxs-fp-check::after{content:"";position:absolute;left:4px;top:0px;width:5px;height:10px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg) scale(0);transition:transform .12s ease;}
		.uxs-fp-checkbox input:checked + .uxs-fp-check{background:#2563eb;border-color:#2563eb;}
		.uxs-fp-checkbox input:checked + .uxs-fp-check::after{transform:rotate(45deg) scale(1);}
		.uxs-fp-checkbox input:focus-visible + .uxs-fp-check{box-shadow:0 0 0 3px rgba(37,99,235,.25);}
		.uxs-fp-dropzone{border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;position:relative;}
		.uxs-fp-dropzone.is-dragover{border-color:#2563eb;background:rgba(37,99,235,.05);}
		.uxs-fp-dropzone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
		.uxs-fp-dropzone__hint{margin:0;font-size:13px;color:#6b7280;}
		.uxs-fp-files{list-style:none;margin:10px 0 0;padding:0;font-size:13px;text-align:left;}
		.uxs-fp-files li{padding:2px 0;color:#1f2937;}
		.uxs-fp-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;}
		.uxs-fp-chip{background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 10px;font-size:12px;display:inline-flex;align-items:center;gap:6px;}
		.uxs-fp-chip button{border:0;background:none;color:inherit;cursor:pointer;font-size:12px;line-height:1;padding:0;}
		.uxs-fp-hp{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;}
		.uxs-fp-html{font-size:14px;line-height:1.5;}
		.uxs-fp-nav{display:flex;gap:10px;margin-top:20px;}
		.uxs-fp-btn{border:0;border-radius:6px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;transition:opacity .15s ease;}
		.uxs-fp-btn--primary{background:#2563eb;color:#fff;}
		.uxs-fp-btn--ghost{background:#e5e7eb;color:#1f2937;}
		.uxs-fp-btn:disabled{opacity:.6;cursor:not-allowed;}
		.uxs-fp-alert{padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:14px;}
		.uxs-fp-alert.is-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
		.uxs-fp-alert.is-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
		.uxs-fp-progress{display:flex;gap:8px;margin-bottom:18px;}
		.uxs-fp-progress__step{flex:1;height:4px;border-radius:2px;background:#e5e7eb;}
		.uxs-fp-progress__step.is-done,.uxs-fp-progress__step.is-active{background:#2563eb;}
		.uxs-fp-step__title{font-size:16px;font-weight:700;margin:0 0 12px;padding:0;}
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
		?>
		<script>
		window.UxStudioForms = ( function () {
			function evalRule( rule, values ) {
				var v = values[ rule.field ];
				v = Array.isArray( v ) ? v.join( ', ' ) : ( v || '' );
				var want = rule.value || '';
				switch ( rule.operator ) {
					case 'not_equals': return v !== want;
					case 'contains': return want !== '' && v.toLowerCase().indexOf( want.toLowerCase() ) !== -1;
					case 'empty': return v.trim() === '';
					case 'not_empty': return v.trim() !== '';
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

			function stepFields( form, index ) {
				var step = form.querySelector( '[data-uxs-step="' + index + '"]' );
				return step ? step.querySelectorAll( '[data-uxs-field]:not([hidden])' ) : [];
			}

			function validateStep( form, index, cfg ) {
				var ok = true;
				stepFields( form, index ).forEach( function ( wrap ) {
					var key = wrap.getAttribute( 'data-uxs-field' );
					var def = cfg.fields.filter( function ( f ) { return f.key === key; } )[ 0 ];
					var errorEl = wrap.querySelector( '[data-uxs-field-error]' );
					var inputs = wrap.querySelectorAll( 'input, select, textarea' );
					var valid = true;
					inputs.forEach( function ( input ) { if ( ! input.checkValidity() ) { valid = false; } } );
					wrap.classList.toggle( 'has-error', ! valid );
					if ( errorEl ) { errorEl.textContent = valid ? '' : ( def && def.required ? cfg.i18n.required : '' ); }
					if ( ! valid ) { ok = false; }
				} );
				return ok;
			}

			function updateProgress( form, index, total ) {
				var bar = form.querySelector( '[data-uxs-progress]' );
				if ( ! bar || total <= 1 ) { if ( bar ) { bar.hidden = true; } return; }
				bar.hidden = false;
				bar.innerHTML = '';
				for ( var i = 0; i < total; i++ ) {
					var el = document.createElement( 'span' );
					el.className = 'uxs-fp-progress__step' + ( i < index ? ' is-done' : ( i === index ? ' is-active' : '' ) );
					bar.appendChild( el );
				}
			}

			function goToStep( form, index, cfg ) {
				var steps = form.querySelectorAll( '[data-uxs-step]' );
				steps.forEach( function ( s, i ) { s.hidden = i !== index; } );
				form.setAttribute( 'data-uxs-current-step', String( index ) );
				updateProgress( form, index, steps.length );
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
			}

			function init( form, cfg ) {
				if ( ! form || form.getAttribute( 'data-uxs-inited' ) ) { return; }
				form.setAttribute( 'data-uxs-inited', '1' );

				form.querySelectorAll( '.uxs-fp-field' ).forEach( function ( wrap ) {
					setupMultiselect( wrap, cfg );
					setupDropzone( wrap );
				} );

				form.addEventListener( 'input', function () { applyConditions( form ); } );
				form.addEventListener( 'change', function () { applyConditions( form ); } );
				applyConditions( form );

				var steps = form.querySelectorAll( '[data-uxs-step]' );
				goToStep( form, 0, cfg );

				var next = form.querySelector( '[data-uxs-next]' );
				var prev = form.querySelector( '[data-uxs-prev]' );
				if ( next ) {
					next.addEventListener( 'click', function () {
						var current = parseInt( form.getAttribute( 'data-uxs-current-step' ) || '0', 10 );
						if ( validateStep( form, current, cfg ) ) {
							goToStep( form, Math.min( steps.length - 1, current + 1 ), cfg );
						}
					} );
				}
				if ( prev ) {
					prev.addEventListener( 'click', function () {
						var current = parseInt( form.getAttribute( 'data-uxs-current-step' ) || '0', 10 );
						goToStep( form, Math.max( 0, current - 1 ), cfg );
					} );
				}

				form.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					var current = parseInt( form.getAttribute( 'data-uxs-current-step' ) || '0', 10 );
					var allValid = true;
					for ( var i = 0; i <= current; i++ ) { if ( ! validateStep( form, i, cfg ) ) { allValid = false; } }
					if ( ! allValid ) { return; }

					var alertEl = form.querySelector( '[data-uxs-alert]' );
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
					fetch( cfg.restUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( res ) { return res.json().then( function ( json ) { return { ok: res.ok, json: json }; } ); } )
						.then( function ( result ) {
							if ( result.ok ) {
								if ( alertEl ) {
									alertEl.hidden = false;
									alertEl.className = 'uxs-fp-alert is-success';
									alertEl.textContent = ( result.json.data && result.json.data.message ) || cfg.successText;
								}
								form.reset();
								form.querySelectorAll( '.uxs-fp-step' ).forEach( function ( s, i ) { s.hidden = i !== 0; } );
								goToStep( form, 0, cfg );
							} else {
								var fieldErrors = result.json && result.json.data && result.json.data.fields;
								if ( fieldErrors ) {
									Object.keys( fieldErrors ).forEach( function ( key ) {
										var wrap = form.querySelector( '[data-uxs-field="' + key + '"]' );
										if ( wrap ) {
											wrap.classList.add( 'has-error' );
											var err = wrap.querySelector( '[data-uxs-field-error]' );
											if ( err ) { err.textContent = fieldErrors[ key ]; }
										}
									} );
								}
								if ( alertEl ) {
									alertEl.hidden = false;
									alertEl.className = 'uxs-fp-alert is-error';
									alertEl.textContent = ( result.json && result.json.message ) || cfg.i18n.genericError;
								}
							}
						} )
						.catch( function () {
							if ( alertEl ) {
								alertEl.hidden = false;
								alertEl.className = 'uxs-fp-alert is-error';
								alertEl.textContent = cfg.i18n.genericError;
							}
						} )
						.finally( function () {
							if ( submitBtn ) { submitBtn.disabled = false; }
						} );
				} );
			}

			var api = { init: init };
			if ( window.UxStudioFormsQueue ) {
				window.UxStudioFormsQueue.forEach( function ( pair ) {
					var el = document.getElementById( pair[ 0 ] );
					if ( el ) { init( el, pair[ 1 ] ); }
				} );
				window.UxStudioFormsQueue = [];
			}
			return api;
		} )();
		</script>
		<?php
	}
}
