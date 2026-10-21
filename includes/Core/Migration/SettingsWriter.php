<?php
/**
 * Writes migrated values into module settings - always through the module's
 * settings schema (never a raw update_option of foreign data).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\Security;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately NOT BaseModule::save_settings(): modules override that method with
 * side effects (Security Optimization rewrites the .htaccess block, syncs country
 * bans, provisions CAPTCHA keys) and the migration must stay a pure settings copy
 * until the module is switched on in a later, confirmed step.
 */
final class SettingsWriter {

	/** @var callable|null Test seam: module id => schema. */
	private static $schema_resolver = null;

	/**
	 * Replace how schemas are resolved (tests only).
	 *
	 * @param callable|null $resolver Receives a module id, returns the schema or null when unknown.
	 */
	public static function set_schema_resolver( ?callable $resolver ): void {
		self::$schema_resolver = $resolver;
	}

	/**
	 * Option name of a module's settings.
	 *
	 * @param string $module Module id.
	 */
	public static function option_name( string $module ): string {
		return 'uxstudio_' . str_replace( '-', '_', $module );
	}

	/**
	 * Settings schema of a module, or null when the module is unknown.
	 *
	 * @param string $module Module id.
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function schema( string $module ): ?array {
		if ( null !== self::$schema_resolver ) {
			$schema = call_user_func( self::$schema_resolver, $module );
			return is_array( $schema ) ? $schema : null;
		}
		if ( ! class_exists( '\UxStudio\Plugin' ) || ! isset( \UxStudio\Plugin::instance()->modules ) ) {
			return null;
		}
		$instance = \UxStudio\Plugin::instance()->modules->instance( $module );
		return $instance ? $instance->settings_schema() : null;
	}

	/**
	 * Default values from the schema.
	 *
	 * @param array<int, array<string, mixed>> $schema Module schema.
	 * @return array<string, mixed>
	 */
	private static function defaults( array $schema ): array {
		$out = array();
		foreach ( $schema as $field ) {
			$out[ $field['key'] ] = $field['default'] ?? null;
		}
		return $out;
	}

	/**
	 * Compare what a mapping would write with what is stored now. Nothing is written.
	 *
	 * @param Mapping $mapping Collected mapping.
	 * @return array{items:array<int,array<string,mixed>>,errors:string[]}
	 *         items: one row per queued value - `from`/`to` (display), `changed` (effective value
	 *         differs), `write` (key is missing or differs, so the write is needed), `dropped`
	 *         (the schema sanitizer changed the value, e.g. an unknown post type).
	 */
	public static function preview( Mapping $mapping ): array {
		$items  = array();
		$errors = array();
		$clean  = array();
		foreach ( $mapping->settings as $module => $input ) {
			$schema = self::schema( $module );
			if ( null === $schema ) {
				$errors[] = sprintf(
					/* translators: %s: module id. */
					__( 'Module "%s" is not available, its settings were not transferred.', 'ux-studio' ),
					$module
				);
				continue;
			}
			$clean[ $module ] = array(
				'values'   => Settings::clean( $input, $schema ),
				'stored'   => (array) get_option( self::option_name( $module ), array() ),
				'defaults' => self::defaults( $schema ),
			);
		}
		foreach ( $mapping->items as $item ) {
			if ( $item['secret'] ) {
				$current = Security::get_secret( (string) $item['key'] );
				$new     = $mapping->secrets[ $item['key'] ]['value'] ?? '';
				$items[] = array(
					'module'  => '',
					'key'     => $item['key'],
					'label'   => $item['label'],
					'secret'  => true,
					'from'    => '' === $current ? '' : '********',
					'to'      => '' === $new ? '' : '********',
					'changed' => '' !== $new && $current !== $new,
					'write'   => '' !== $new && $current !== $new,
					'dropped' => false,
					'origin'  => $item['origin'],
					'target'  => $item['key'],
				);
				continue;
			}
			$module = $item['module'];
			$key    = $item['key'];
			if ( ! isset( $clean[ $module ] ) ) {
				continue;
			}
			$stored  = $clean[ $module ]['stored'];
			$has     = array_key_exists( $key, $stored );
			$current = $has ? $stored[ $key ] : ( $clean[ $module ]['defaults'][ $key ] ?? null );
			$exists  = array_key_exists( $key, $clean[ $module ]['values'] );
			$new     = $exists ? $clean[ $module ]['values'][ $key ] : null;
			$items[] = array(
				'module'  => $module,
				'key'     => $key,
				'label'   => $item['label'],
				'secret'  => false,
				'from'    => Mapping::display( $current ),
				'to'      => Mapping::display( $new ),
				'changed' => $exists && ! Mapping::same( $current, $new ),
				'write'   => $exists && ( ! $has || ! Mapping::same( $stored[ $key ], $new ) ),
				'dropped' => ! $exists || ! Mapping::same( $mapping->settings[ $module ][ $key ], $new ),
				'origin'  => $item['origin'],
				'target'  => self::option_name( $module ) . ':' . $key,
			);
		}
		return array(
			'items'  => $items,
			'errors' => $errors,
		);
	}

	/**
	 * Write a mapping. Secrets go through Security::store_secret, and only when the
	 * value differs (a fresh IV on every call would make a re-run look like a change).
	 *
	 * @param Mapping $mapping Collected mapping.
	 * @return array{items:array<int,array<string,mixed>>,errors:string[],written:int,unchanged:int}
	 */
	public static function apply( Mapping $mapping ): array {
		$preview = self::preview( $mapping );
		$written = 0;

		foreach ( $mapping->settings as $module => $input ) {
			$schema = self::schema( $module );
			if ( null === $schema ) {
				continue;
			}
			$needs = false;
			foreach ( $preview['items'] as $row ) {
				if ( $row['module'] === $module && $row['write'] ) {
					$needs = true;
					++$written;
				}
			}
			if ( $needs ) {
				( new Settings( self::option_name( $module ) ) )->save( $input, $schema );
			}
		}
		foreach ( $mapping->secrets as $option => $secret ) {
			if ( '' === $secret['value'] ) {
				continue;
			}
			if ( Security::get_secret( $option ) !== $secret['value'] ) {
				Security::store_secret( $option, $secret['value'] );
				++$written;
			}
		}

		return array(
			'items'     => $preview['items'],
			'errors'    => $preview['errors'],
			'written'   => $written,
			'unchanged' => max( 0, count( $preview['items'] ) - $written ),
		);
	}

	/**
	 * Verify a mapping against the stored values.
	 *
	 * @param Mapping $mapping Collected mapping (recomputed from the source).
	 * @return array<int, array{id:string,label:string,ok:bool,detail:string}>
	 */
	public static function verify( Mapping $mapping ): array {
		$checks = array();
		foreach ( self::preview( $mapping )['items'] as $row ) {
			$id = $row['target'];
			if ( $row['secret'] ) {
				$stored   = Security::get_secret( (string) $row['key'] );
				$expected = $mapping->secrets[ $row['key'] ]['value'] ?? '';
				if ( '' === $expected ) {
					$checks[] = array(
						'id'     => $id,
						'label'  => $row['label'],
						'ok'     => true,
						'detail' => __( 'The plugin has no key stored, nothing to compare.', 'ux-studio' ),
					);
					continue;
				}
				$ok       = $stored === $expected;
				$checks[] = array(
					'id'     => $id,
					'label'  => $row['label'],
					'ok'     => $ok,
					'detail' => $ok ? __( 'The key is stored encrypted.', 'ux-studio' ) : __( 'The key is not stored in UX Studio.', 'ux-studio' ),
				);
				continue;
			}
			if ( $row['dropped'] ) {
				$checks[] = array(
					'id'     => $id,
					'label'  => $row['label'],
					'ok'     => false,
					'detail' => sprintf(
						/* translators: %s: value that the module did not accept. */
						__( 'The module does not accept the value "%s" (for example a post type or role that does not exist), it was not transferred.', 'ux-studio' ),
						Mapping::display( $mapping->settings[ $row['module'] ][ $row['key'] ] ?? '' )
					),
				);
				continue;
			}
			$stored = (array) get_option( self::option_name( (string) $row['module'] ), array() );
			$ok     = array_key_exists( $row['key'], $stored ) && ! $row['write'];
			$checks[] = array(
				'id'     => $id,
				'label'  => $row['label'],
				'ok'     => $ok,
				'detail' => $ok
					? $row['to']
					: sprintf(
						/* translators: 1: expected value, 2: value found in UX Studio. */
						__( 'Expected "%1$s", UX Studio has "%2$s".', 'ux-studio' ),
						$row['to'],
						$row['from']
					),
			);
		}
		return $checks;
	}
}
