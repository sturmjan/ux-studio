<?php
/**
 * Field type catalog: whitelist, definition sanitization and the
 * client-independent evaluation of per-field conditional logic.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * A form's `fields_json` is an ordered array of field definitions. This class
 * is the single place that knows which types exist and how their attributes
 * are sanitized - both the REST create/update path (definition) and the
 * public submit path (values) go through here so the two can never drift.
 */
final class Fields {

	/** Every supported field type. */
	public const TYPES = array(
		'text',
		'textarea',
		'email',
		'url',
		'tel',
		'number',
		'password',
		'hidden',
		'select',
		'radio',
		'checkbox',
		'checkbox_group',
		'multiselect',
		'acceptance',
		'date',
		'time',
		'file',
		'signature',
		'html',
		'step',
		'captcha',
	);

	/** Types whose submitted value is a stored-file entry, same shape as `file` (PLAN.md 20.11/F4). */
	public const FILE_LIKE_TYPES = array( 'file', 'signature' );

	/** Types that carry an `options[]` list of { label, value }. */
	public const CHOICE_TYPES = array( 'select', 'radio', 'checkbox_group', 'multiselect' );

	/** Layout-only types: never a value, always full width, never conditions. */
	public const LAYOUT_TYPES = array( 'html', 'step' );

	/** Types that don't produce a submitted value at all (besides layout types). */
	public const NON_INPUT_TYPES = array( 'html', 'step', 'captcha' );

	public const ALLOWED_WIDTHS = array( 25, 33, 50, 66, 75, 100 );

	public const LABEL_DISPLAY = array( 'inherit', 'visible', 'placeholder_only' );

	public const CONDITION_OPERATORS = array( 'equals', 'not_equals', 'contains', 'empty', 'not_empty', 'greater', 'less' );

	public const CONDITION_LOGIC = array( 'all', 'any' );

	public static function is_valid_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Sanitize the whole `fields[]` definition array coming from the builder.
	 * Drops invalid entries outright rather than trying to partially repair
	 * them - a malformed field must not silently corrupt the form.
	 *
	 * @param mixed             $fields     Raw input.
	 * @param array<int,string> $seed_keys  Keys already in use elsewhere (e.g. the
	 *                                      form's existing fields when sanitizing a
	 *                                      batch of AI-drafted fields to append) -
	 *                                      guarantees the result never collides with them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_fields( $fields, array $seed_keys = array() ): array {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$clean      = array();
		$used_keys  = $seed_keys;
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$sanitized = self::sanitize_field( $field, $used_keys );
			if ( null === $sanitized ) {
				continue;
			}
			$used_keys[]  = $sanitized['key'];
			$clean[]      = $sanitized;
		}

		return $clean;
	}

	/**
	 * Sanitize one field definition.
	 *
	 * @param array $field     Raw field.
	 * @param array $used_keys Keys already assigned in this form (for uniqueness).
	 * @return array<string, mixed>|null Null when the field is unusable (bad type/key).
	 */
	public static function sanitize_field( array $field, array $used_keys ): ?array {
		$type = sanitize_key( (string) ( $field['type'] ?? '' ) );
		if ( ! self::is_valid_type( $type ) ) {
			return null;
		}

		$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
		if ( '' === $key ) {
			$key = $type . '_' . substr( md5( wp_generate_password( 12, false ) ), 0, 6 );
		}
		$original_key = $key;
		$suffix       = 1;
		while ( in_array( $key, $used_keys, true ) ) {
			++$suffix;
			$key = $original_key . '_' . $suffix;
		}

		$is_layout = in_array( $type, self::LAYOUT_TYPES, true );

		$clean = array(
			'key'           => $key,
			'type'          => $type,
			'label'         => sanitize_text_field( (string) ( $field['label'] ?? '' ) ),
			'placeholder'   => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
			'required'      => ! $is_layout && ! empty( $field['required'] ),
			'css_class'     => self::sanitize_css_class( (string) ( $field['css_class'] ?? '' ) ),
			'default_value' => sanitize_text_field( (string) ( $field['default_value'] ?? '' ) ),
			'width'         => $is_layout ? 100 : self::sanitize_width( $field['width'] ?? 100 ),
			'width_tablet'  => $is_layout ? 100 : self::sanitize_width( $field['width_tablet'] ?? ( $field['width'] ?? 100 ) ),
			'width_mobile'  => $is_layout ? 100 : self::sanitize_width( $field['width_mobile'] ?? ( $field['width_tablet'] ?? ( $field['width'] ?? 100 ) ) ),
			'label_display' => in_array( $field['label_display'] ?? 'inherit', self::LABEL_DISPLAY, true ) ? $field['label_display'] : 'inherit',
			'conditions'    => $is_layout ? array() : self::sanitize_conditions( $field['conditions'] ?? array() ),
			'logic'         => in_array( $field['logic'] ?? 'all', self::CONDITION_LOGIC, true ) ? $field['logic'] : 'all',
		);

		if ( 'step' === $type ) {
			$clean['step_title'] = sanitize_text_field( (string) ( $field['step_title'] ?? $field['label'] ?? '' ) );
			$clean['width_mobile'] = 100;
		}

		if ( 'html' === $type ) {
			$clean['html'] = wp_kses_post( (string) ( $field['html'] ?? '' ) );
		}

		if ( in_array( $type, self::CHOICE_TYPES, true ) ) {
			$clean['options'] = self::sanitize_options( $field['options'] ?? array() );
		}

		if ( 'acceptance' === $type ) {
			$clean['terms_url'] = esc_url_raw( (string) ( $field['terms_url'] ?? '' ) );
		}

		if ( 'number' === $type ) {
			$clean['min'] = is_numeric( $field['min'] ?? null ) ? (float) $field['min'] : null;
			$clean['max'] = is_numeric( $field['max'] ?? null ) ? (float) $field['max'] : null;
		}

		if ( in_array( $type, array( 'text', 'textarea', 'tel' ), true ) ) {
			$clean['minlength'] = isset( $field['minlength'] ) && is_numeric( $field['minlength'] ) ? max( 0, (int) $field['minlength'] ) : null;
			$clean['maxlength'] = isset( $field['maxlength'] ) && is_numeric( $field['maxlength'] ) ? max( 0, (int) $field['maxlength'] ) : null;
		}

		if ( 'textarea' === $type ) {
			$clean['rows'] = max( 2, min( 20, (int) ( $field['rows'] ?? 4 ) ) );
		}

		if ( 'file' === $type ) {
			$clean['accept']     = self::sanitize_accept( $field['accept'] ?? array() );
			$clean['max_size_mb'] = max( 1, min( 50, (int) ( $field['max_size_mb'] ?? 10 ) ) );
			$clean['multiple']   = ! empty( $field['multiple'] );
		}

		return $clean;
	}

	/**
	 * @param mixed $width Raw width.
	 */
	private static function sanitize_width( $width ): int {
		$width = (int) $width;
		if ( in_array( $width, self::ALLOWED_WIDTHS, true ) ) {
			return $width;
		}
		// Snap to the nearest allowed step instead of silently defaulting to
		// 100 - a drag-resize can transiently pass an in-between value.
		$closest = 100;
		$delta   = PHP_INT_MAX;
		foreach ( self::ALLOWED_WIDTHS as $step ) {
			$d = abs( $step - $width );
			if ( $d < $delta ) {
				$delta   = $d;
				$closest = $step;
			}
		}
		return $closest;
	}

	private static function sanitize_css_class( string $class ): string {
		$class = preg_replace( '/[^A-Za-z0-9_\- ]/', '', $class ) ?? '';
		return trim( $class );
	}

	/**
	 * @param mixed $options Raw options.
	 * @return array<int, array{label:string,value:string}>
	 */
	private static function sanitize_options( $options ): array {
		if ( ! is_array( $options ) ) {
			return array();
		}
		$clean = array();
		foreach ( $options as $option ) {
			if ( is_string( $option ) ) {
				$label = sanitize_text_field( $option );
				$value = sanitize_text_field( $option );
			} elseif ( is_array( $option ) ) {
				$label = sanitize_text_field( (string) ( $option['label'] ?? '' ) );
				$value = sanitize_text_field( (string) ( $option['value'] ?? $option['label'] ?? '' ) );
			} else {
				continue;
			}
			if ( '' === $label && '' === $value ) {
				continue;
			}
			$clean[] = array(
				'label' => '' !== $label ? $label : $value,
				'value' => '' !== $value ? $value : $label,
			);
		}
		return array_slice( $clean, 0, 200 );
	}

	/**
	 * @param mixed $accept Raw extensions list.
	 * @return string[] Lowercase extensions without the leading dot.
	 */
	private static function sanitize_accept( $accept ): array {
		if ( is_string( $accept ) ) {
			$accept = preg_split( '/[\s,]+/', $accept ) ?: array();
		}
		if ( ! is_array( $accept ) ) {
			return array();
		}
		$clean = array();
		foreach ( $accept as $ext ) {
			$ext = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $ext ) ?? '' );
			if ( '' !== $ext && isset( FileStorage::ALLOWED_TYPES[ $ext ] ) ) {
				$clean[] = $ext;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		return empty( $clean ) ? array_keys( FileStorage::ALLOWED_TYPES ) : $clean;
	}

	/**
	 * @param mixed $conditions Raw conditions.
	 * @return array<int, array{field:string,operator:string,value:string}>
	 */
	private static function sanitize_conditions( $conditions ): array {
		if ( ! is_array( $conditions ) ) {
			return array();
		}
		$clean = array();
		foreach ( $conditions as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
				continue;
			}
			$operator = in_array( $rule['operator'] ?? 'equals', self::CONDITION_OPERATORS, true ) ? $rule['operator'] : 'equals';
			$clean[]  = array(
				'field'    => sanitize_key( (string) $rule['field'] ),
				'operator' => $operator,
				'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
			);
			if ( count( $clean ) >= 10 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Server-side re-evaluation of a field's conditional visibility, applied
	 * at submit time so a hidden/inactive field can never be forced into a
	 * submission by a hand-crafted request - this MUST mirror the client's
	 * live show/hide logic, since it is the authoritative copy of it.
	 *
	 * @param array $field Sanitized field definition.
	 * @param array $input Raw submitted values, keyed by field key.
	 */
	public static function is_active( array $field, array $input ): bool {
		$conditions = $field['conditions'] ?? array();
		if ( empty( $conditions ) ) {
			return true;
		}

		$logic   = ( 'any' === ( $field['logic'] ?? 'all' ) ) ? 'any' : 'all';
		$results = array();
		foreach ( $conditions as $rule ) {
			$dep  = (string) ( $rule['field'] ?? '' );
			$val  = isset( $input[ $dep ] ) ? $input[ $dep ] : '';
			$val  = is_array( $val ) ? implode( ', ', array_map( 'strval', $val ) ) : (string) $val;
			$want = (string) ( $rule['value'] ?? '' );

			switch ( $rule['operator'] ?? 'equals' ) {
				case 'not_equals':
					$results[] = ( $val !== $want );
					break;
				case 'contains':
					$results[] = ( '' !== $want && false !== stripos( $val, $want ) );
					break;
				case 'empty':
					$results[] = ( '' === trim( $val ) );
					break;
				case 'not_empty':
					$results[] = ( '' !== trim( $val ) );
					break;
				case 'greater':
					$results[] = ( is_numeric( $val ) && is_numeric( $want ) && (float) $val > (float) $want );
					break;
				case 'less':
					$results[] = ( is_numeric( $val ) && is_numeric( $want ) && (float) $val < (float) $want );
					break;
				default:
					$results[] = ( $val === $want );
					break;
			}
		}

		return 'any' === $logic ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * Resolve a definition's `default_value` token ({today}, {query.xxx})
	 * against the current request. Used both to pre-fill the public render
	 * and to resolve `hidden` fields (whose value is never taken from user
	 * input at submit time).
	 *
	 * @param string $token Raw default_value.
	 */
	public static function resolve_default_token( string $token ): string {
		if ( '{today}' === $token ) {
			return current_time( 'Y-m-d' );
		}
		if ( 0 === strpos( $token, '{query.' ) && '}' === substr( $token, -1 ) ) {
			$param = substr( $token, 7, -1 );
			$param = sanitize_key( $param );
			if ( '' !== $param && isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			return '';
		}
		return $token;
	}
}
