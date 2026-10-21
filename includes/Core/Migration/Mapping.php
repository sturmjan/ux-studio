<?php
/**
 * What a source wants to write into UX Studio, plus its honest commentary.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Collector filled by a source's `map()`: settings per module, secrets,
 * and the lists the plan shows (`lossy`, `warnings`, `notes`).
 */
final class Mapping {

	/** @var array<string, array<string, mixed>> Module id => key => raw value. */
	public array $settings = array();

	/** @var array<string, array{value:string,label:string,module:string}> Secret option => value (`module` = the module that reads it, decides direct write vs staging). */
	public array $secrets = array();

	/**
	 * Whole-option values of modules that keep their configuration outside the settings schema
	 * (Menu Organizer config, Admin Columns). Option name => module and top-level keys to merge into it.
	 *
	 * @var array<string, array{module:string,values:array<string,mixed>}>
	 */
	public array $options = array();

	/** @var array<int, array{module:string,key:string,label:string,value:string,secret:bool,origin:string,option?:string}> */
	public array $items = array();

	/**
	 * Whether values for an already-enabled module wait in the staging area (default). A source
	 * whose settings cannot change how the site behaves (log retention ...) sets this to false and
	 * its values are written directly.
	 */
	public bool $stage = true;

	/** @var string[] What does not move over. */
	public array $lossy = array();

	/** @var string[] Things the admin must know before running. */
	public array $warnings = array();

	/** @var string[] Neutral remarks ("nothing to transfer, the module takes over"). */
	public array $notes = array();

	/**
	 * Queue one module setting.
	 *
	 * @param string $module Module id.
	 * @param string $key    Setting key of the module schema.
	 * @param mixed  $value  Raw value (sanitized against the schema on write).
	 * @param string $label  Translated label shown in the plan.
	 * @param string $origin Where it comes from (plugin option / key), for the mapping table.
	 */
	public function set( string $module, string $key, $value, string $label, string $origin = '' ): self {
		$this->settings[ $module ][ $key ] = $value;
		$this->items[]                     = array(
			'module' => $module,
			'key'    => $key,
			'label'  => $label,
			'value'  => self::display( $value ),
			'secret' => false,
			'origin' => '' !== $origin ? $origin : $key,
		);
		return $this;
	}

	/**
	 * Queue top-level keys of an option that is not described by the module settings schema. The values
	 * must already be clean (the source runs the module's own pure sanitizer); they are merged key by key
	 * into the stored option, so keys the source does not mention stay as they are.
	 *
	 * @param string               $module Module id that reads the option (decides direct write vs staging).
	 * @param string               $option Option name.
	 * @param array<string, mixed> $values Top-level key => clean value.
	 * @param array<string, string> $labels Key => translated label for the plan (falls back to the key).
	 * @param string               $origin Where it comes from, for the mapping table.
	 */
	public function option( string $module, string $option, array $values, array $labels = array(), string $origin = '' ): self {
		$this->options[ $option ] = array(
			'module' => $module,
			'values' => array_merge( (array) ( $this->options[ $option ]['values'] ?? array() ), $values ),
		);
		foreach ( $values as $key => $value ) {
			$this->items[] = array(
				'module' => $module,
				'key'    => (string) $key,
				'label'  => (string) ( $labels[ $key ] ?? $key ),
				'value'  => self::display( $value ),
				'secret' => false,
				'origin' => '' !== $origin ? $origin : $option,
				'option' => $option,
			);
		}
		return $this;
	}

	/**
	 * Queue one secret (stored through Security::store_secret, never shown).
	 *
	 * @param string $option Secret option name.
	 * @param string $value  Plain value.
	 * @param string $label  Translated label.
	 * @param string $origin Origin of the value.
	 * @param string $module Module that reads the secret; empty = the source's target module.
	 */
	public function secret( string $option, string $value, string $label, string $origin = '', string $module = '' ): self {
		$this->secrets[ $option ] = array(
			'value'  => $value,
			'label'  => $label,
			'module' => $module,
		);
		$this->items[]            = array(
			'module' => '',
			'key'    => $option,
			'label'  => $label,
			'value'  => '' === $value ? '' : '********',
			'secret' => true,
			'origin' => '' !== $origin ? $origin : $option,
		);
		return $this;
	}

	/**
	 * Something that will NOT be transferred.
	 *
	 * @param string $text Translated sentence.
	 */
	public function lossy( string $text ): self {
		$this->lossy[] = $text;
		return $this;
	}

	/**
	 * A warning shown before the run.
	 *
	 * @param string $text Translated sentence.
	 */
	public function warn( string $text ): self {
		$this->warnings[] = $text;
		return $this;
	}

	/**
	 * A neutral note.
	 *
	 * @param string $text Translated sentence.
	 */
	public function note( string $text ): self {
		$this->notes[] = $text;
		return $this;
	}

	/** Whether there is nothing to write. */
	public function is_empty(): bool {
		return array() === $this->settings && array() === $this->secrets && array() === $this->options;
	}

	/**
	 * Human readable form of a value for the plan and the dry run.
	 *
	 * @param mixed $value Any value.
	 */
	public static function display( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '✓' : '✗';
		}
		if ( is_array( $value ) ) {
			$list = array_is_list( $value );
			$out  = array();
			foreach ( $value as $k => $v ) {
				$out[] = ( $list ? '' : $k . ': ' ) . self::display( $v );
			}
			$text = implode( ', ', $out );
			return strlen( $text ) > 240 ? substr( $text, 0, 237 ) . '...' : $text;
		}
		if ( null === $value ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * Loose equality that survives sanitizer type changes (`"5"` vs `5`,
	 * `1` vs `true`, list order).
	 *
	 * @param mixed $a Value A.
	 * @param mixed $b Value B.
	 */
	public static function same( $a, $b ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			if ( ! is_array( $a ) || ! is_array( $b ) ) {
				return false;
			}
			$na = array_map( 'strval', array_values( $a ) );
			$nb = array_map( 'strval', array_values( $b ) );
			sort( $na );
			sort( $nb );
			return $na === $nb;
		}
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a === (bool) $b;
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a === (float) $b;
		}
		return (string) $a === (string) $b;
	}

	/**
	 * Deep comparison for whole-option values: lists keep their order (an order IS the value), maps
	 * ignore key order, scalars compare as text (`1` equals `"1"`).
	 *
	 * @param mixed $a Value A.
	 * @param mixed $b Value B.
	 */
	public static function same_deep( $a, $b ): bool {
		return json_encode( self::canon( $a ) ) === json_encode( self::canon( $b ) );
	}

	/**
	 * Canonical form used by same_deep().
	 *
	 * @param mixed $v Any value.
	 * @return mixed
	 */
	private static function canon( $v ) {
		if ( is_array( $v ) ) {
			if ( array_is_list( $v ) ) {
				return array_map( array( self::class, 'canon' ), $v );
			}
			ksort( $v );
			return array_map( array( self::class, 'canon' ), $v );
		}
		if ( is_bool( $v ) ) {
			return $v ? '1' : '';
		}
		return null === $v ? '' : (string) $v;
	}
}
