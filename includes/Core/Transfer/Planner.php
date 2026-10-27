<?php
/**
 * Pure planning of an import: which keys of the file may be used, what changes, how it is shown.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

use UxStudio\Core\Mcp\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * No WordPress calls (the functions that need the database are passed in as callables), so
 * tests/transfer-envelope.php and tests/transfer-import-rules.php cover it without a site.
 *
 * Whitelist by schema: a key of the file that is not in the module's settings schema is dropped and
 * reported as unknown; a key that is in the schema but never travels (NonPortable) is dropped and reported
 * as such, even when somebody wrote it into the file by hand.
 */
final class Planner {

	/** Longest value text shown in a diff. */
	public const LABEL_MAX = 120;

	/** Shown instead of a secret. */
	public const MASK = '••••••';

	/**
	 * A setting value as written into the file.
	 *
	 * @param mixed                $value     Value from `settings_values()`.
	 * @param array<string, mixed> $field     Schema field.
	 * @param callable|null        $media_url callable(int $id): string - URL of an attachment ('' when it is gone).
	 * @return array{include:bool,value:mixed} `include` false when the value cannot travel (a media id with no file).
	 */
	public static function export_value( $value, array $field, ?callable $media_url = null ): array {
		if ( 'media' === ( $field['type'] ?? '' ) ) {
			$id = is_numeric( $value ) ? (int) $value : 0;
			if ( $id <= 0 ) {
				return array(
					'include' => true,
					'value'   => 0,
				);
			}
			$url = null === $media_url ? '' : (string) $media_url( $id );
			if ( '' === $url ) {
				return array(
					'include' => false,
					'value'   => null,
				);
			}
			return array(
				'include' => true,
				'value'   => array(
					'__media' => array(
						'id'  => $id,
						'url' => $url,
					),
				),
			);
		}
		return array(
			'include' => true,
			'value'   => $value,
		);
	}

	/**
	 * Resolve `{"__media":{"id","url"}}` to an attachment id of THIS site. The id of the file is never trusted;
	 * only an attachment whose URL is in the media library here counts.
	 *
	 * @param mixed    $value Value from the file.
	 * @param callable $find  callable(string $url): int - attachment id for a URL, 0 when there is none.
	 * @return array{ok:bool,value:int,missing:bool,error:string}
	 */
	public static function resolve_media( $value, callable $find ): array {
		if ( 0 === $value || null === $value || '' === $value ) {
			return array(
				'ok'      => true,
				'value'   => 0,
				'missing' => false,
				'error'   => '',
			);
		}
		if ( is_array( $value ) && isset( $value['__media'] ) && is_array( $value['__media'] ) && is_string( $value['__media']['url'] ?? null ) ) {
			$id = (int) $find( $value['__media']['url'] );
			return array(
				'ok'      => true,
				'value'   => max( 0, $id ),
				'missing' => $id <= 0,
				'error'   => '',
			);
		}
		return array(
			'ok'      => false,
			'value'   => 0,
			'missing' => false,
			'error'   => __( 'Expected an image.', 'ux-studio' ),
		);
	}

	/**
	 * Sort the keys of one module's `settings` from a file into usable ones and the rest.
	 *
	 * @param string                           $module   Module id.
	 * @param array<int, array<string, mixed>> $schema   Settings schema of this site.
	 * @param array<string, mixed>             $incoming `settings` of the module from the file.
	 * @param callable|null                    $find_media callable(string $url): int.
	 * @return array{accepted:array<string,mixed>,unknown:string[],non_portable:string[],rejected:array<string,string>,dropped:array<string,int>,media_missing:string[]}
	 */
	public static function incoming( string $module, array $schema, array $incoming, ?callable $find_media = null ): array {
		$by_key = array();
		foreach ( $schema as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$by_key[ (string) $field['key'] ] = $field;
			}
		}
		$out = array(
			'accepted'      => array(),
			'unknown'       => array(),
			'non_portable'  => array(),
			'rejected'      => array(),
			'dropped'       => array(),
			'media_missing' => array(),
		);
		foreach ( $incoming as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $by_key[ $key ] ) ) {
				$out['unknown'][] = $key;
				continue;
			}
			$field = $by_key[ $key ];
			if ( '' !== NonPortable::reason( $module, $field ) || NonPortable::is_secret( $module, $key ) ) {
				// Secrets never come from the clear part of a file; they have their own encrypted block.
				$out['non_portable'][] = $key;
				continue;
			}
			if ( 'media' === ( $field['type'] ?? '' ) ) {
				$media = self::resolve_media( $value, $find_media ?? static fn( string $url ): int => 0 );
				if ( ! $media['ok'] ) {
					$out['rejected'][ $key ] = $media['error'];
					continue;
				}
				if ( $media['missing'] ) {
					$out['media_missing'][] = $key;
					continue;
				}
				$value = $media['value'];
			}
			$checked = TypeCheck::check( $value, $field );
			if ( ! $checked['ok'] ) {
				$out['rejected'][ $key ] = $checked['error'];
				continue;
			}
			if ( $checked['dropped'] > 0 ) {
				$out['dropped'][ $key ] = $checked['dropped'];
			}
			$out['accepted'][ $key ] = $checked['value'];
		}
		return $out;
	}

	/**
	 * Keys of the secrets block that this site accepts for the module.
	 *
	 * @param string                       $module  Module id.
	 * @param array<string, string>|null   $block   Decrypted secrets of this module (key => value).
	 * @return array{accepted:array<string,string>,rejected:string[]}
	 */
	public static function incoming_secrets( string $module, ?array $block ): array {
		$out = array(
			'accepted' => array(),
			'rejected' => array(),
		);
		foreach ( (array) $block as $key => $value ) {
			$key = (string) $key;
			if ( NonPortable::is_secret( $module, $key ) && is_string( $value ) && '' !== $value && strlen( $value ) <= 65536 ) {
				$out['accepted'][ $key ] = $value;
			} else {
				$out['rejected'][] = $key;
			}
		}
		return $out;
	}

	/**
	 * The values an import would set: merge (only the keys of the file) or replace (every portable key of the
	 * module; the ones the file does not mention go back to the schema default).
	 *
	 * @param array<int, array<string, mixed>> $schema   Schema.
	 * @param string[]                         $portable Keys that travel as ordinary settings.
	 * @param array<string, mixed>             $accepted Keys accepted from the file.
	 * @param bool                             $replace  Replace the whole module setting.
	 * @param string[]                         $rejected Keys the file had but that failed the checks (kept as they are).
	 * @return array<string, mixed>
	 */
	public static function candidate( array $schema, array $portable, array $accepted, bool $replace, array $rejected = array() ): array {
		if ( ! $replace ) {
			return $accepted;
		}
		$out = $accepted;
		foreach ( $schema as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key || ! in_array( $key, $portable, true ) || array_key_exists( $key, $out ) || in_array( $key, $rejected, true ) ) {
				continue;
			}
			$out[ $key ] = $field['default'] ?? self::empty_for( (string) ( $field['type'] ?? 'text' ) );
		}
		return $out;
	}

	/**
	 * The "empty" value of a field type.
	 *
	 * @param string $type Field type.
	 * @return mixed
	 */
	private static function empty_for( string $type ) {
		switch ( $type ) {
			case 'toggle':
				return false;
			case 'number':
			case 'media':
				return 0;
			case 'multiselect':
				return array();
			default:
				return '';
		}
	}

	/**
	 * Are two setting values the same (numbers compare by value, so `5` equals `5.0`; lists compare in order)?
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 */
	public static function same( $a, $b ): bool {
		if ( is_numeric( $a ) && is_numeric( $b ) && ! is_string( $a ) && ! is_string( $b ) ) {
			return (float) $a === (float) $b;
		}
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return $a === $b;
		}
		if ( ( null === $a || is_scalar( $a ) ) && ( null === $b || is_scalar( $b ) ) ) {
			// Choices may be stored as numbers and arrive as text; an unset value equals an empty one.
			return (string) $a === (string) $b;
		}
		if ( ( null === $a && array() === $b ) || ( array() === $a && null === $b ) ) {
			return true;
		}
		if ( is_array( $a ) && is_array( $b ) && array_is_list( $a ) && array_is_list( $b ) && count( $a ) === count( $b ) && ! array_filter( array_merge( $a, $b ), 'is_array' ) ) {
			// A list of choices is a set: the order of the items does not change anything.
			$sa = array_map( 'strval', $a );
			$sb = array_map( 'strval', $b );
			sort( $sa );
			sort( $sb );
			return $sa === $sb;
		}
		return Envelope::canonical( $a ) === Envelope::canonical( $b );
	}

	/**
	 * Keys whose value differs from the current one.
	 *
	 * @param array<string, mixed> $current Current values.
	 * @param array<string, mixed> $new     Values the import would set.
	 * @return array{changed:array<string,array{old:mixed,new:mixed}>,same:string[]}
	 */
	public static function changes( array $current, array $new ): array {
		$changed = array();
		$same    = array();
		foreach ( $new as $key => $value ) {
			$old = $current[ $key ] ?? null;
			if ( self::same( $old, $value ) ) {
				$same[] = (string) $key;
				continue;
			}
			$changed[ (string) $key ] = array(
				'old' => $old,
				'new' => $value,
			);
		}
		return array(
			'changed' => $changed,
			'same'    => $same,
		);
	}

	/**
	 * A value as a person reads it (labels of choices, On / Off, shortened long text). Secrets are masked.
	 *
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $field  Schema field (may be empty).
	 * @param bool                 $secret Mask the value.
	 */
	public static function value_label( $value, array $field, bool $secret = false ): string {
		if ( $secret || NonPortable::is_sealed( $value ) ) {
			return NonPortable::is_blank( $value ) ? __( '(empty)', 'ux-studio' ) : self::MASK;
		}
		$type    = (string) ( $field['type'] ?? '' );
		$options = (array) ( $field['options'] ?? array() );
		if ( is_bool( $value ) || 'toggle' === $type ) {
			return $value ? __( 'On', 'ux-studio' ) : __( 'Off', 'ux-studio' );
		}
		if ( null === $value || '' === $value || array() === $value ) {
			return __( '(empty)', 'ux-studio' );
		}
		if ( 'media' === $type ) {
			return is_int( $value ) && $value > 0 ? '#' . $value : __( '(empty)', 'ux-studio' );
		}
		if ( is_array( $value ) ) {
			$labels = array();
			foreach ( $value as $one ) {
				$labels[] = is_scalar( $one ) ? ( isset( $options[ $one ] ) ? (string) $options[ $one ] : (string) $one ) : '…';
			}
			return self::shorten( implode( ', ', $labels ) );
		}
		if ( is_scalar( $value ) && isset( $options[ $value ] ) ) {
			return self::shorten( (string) $options[ $value ] );
		}
		return self::shorten( str_replace( array( "\r\n", "\n" ), ' ⏎ ', trim( (string) $value ) ) );
	}

	/**
	 * Cut a long text.
	 *
	 * @param string $text Text.
	 */
	public static function shorten( string $text ): string {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $length <= self::LABEL_MAX ) {
			return $text;
		}
		return ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::LABEL_MAX - 1, 'UTF-8' ) : substr( $text, 0, self::LABEL_MAX - 1 ) ) . '…';
	}

	/**
	 * Diff rows for the preview.
	 *
	 * @param array<int, array<string, mixed>> $schema  Schema.
	 * @param array<string, array{old:mixed,new:mixed}> $changed Changed keys.
	 * @param string                           $module  Module id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( string $module, array $schema, array $changed ): array {
		$by_key = array();
		foreach ( $schema as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$by_key[ (string) $field['key'] ] = $field;
			}
		}
		$rows = array();
		foreach ( $changed as $key => $pair ) {
			$field  = $by_key[ $key ] ?? array();
			$secret = Secrets::is_secret_key( (string) $key ) || NonPortable::is_secret( $module, (string) $key );
			$rows[] = array(
				'key'       => (string) $key,
				'label'     => (string) ( $field['label'] ?? $key ),
				'section'   => (string) ( $field['section'] ?? '' ),
				'old'       => self::value_label( $pair['old'], $field, $secret ),
				'new'       => self::value_label( $pair['new'], $field, $secret ),
				'secret'    => $secret,
				'sensitive' => ! empty( $field['sensitive'] ),
			);
		}
		return $rows;
	}
}
