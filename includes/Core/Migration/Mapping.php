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

	/** @var array<int, array{module:string,key:string,label:string,value:string,secret:bool,origin:string}> */
	public array $items = array();

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
		return array() === $this->settings && array() === $this->secrets;
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
			return implode( ', ', array_map( static fn( $v ): string => self::display( $v ), $value ) );
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
}
