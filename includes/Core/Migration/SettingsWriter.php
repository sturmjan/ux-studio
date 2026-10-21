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
 *
 * A DISABLED target module cannot act on its option, so the values are written there. An
 * ENABLED one would act on them at once (custom login URL next to the old login plugin =
 * lockout), so they go to the Staging area and `Staging::apply()` writes them at the switch.
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
			$live             = (array) get_option( self::option_name( $module ), array() );
			$clean[ $module ] = array(
				'values'   => Settings::clean( $input, $schema ),
				'live'     => $live,
				// What the site will have: the live option with the values already prepared by earlier runs on top.
				'stored'   => array_merge( $live, Staging::pending_settings( $module ) ),
				'defaults' => self::defaults( $schema ),
				'enabled'  => $mapping->stage && Staging::module_enabled( $module ),
			);
		}
		foreach ( $mapping->items as $item ) {
			if ( isset( $item['option'] ) ) {
				$items[] = self::option_row( $mapping, $item );
				continue;
			}
			if ( $item['secret'] ) {
				$live    = Security::get_secret( (string) $item['key'] );
				$current = Staging::pending_secret( (string) $item['key'] ) ?? $live;
				$new     = $mapping->secrets[ $item['key'] ]['value'] ?? '';
				$smodule = (string) ( $mapping->secrets[ $item['key'] ]['module'] ?? '' );
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
					'staged'  => '' !== $new && $mapping->stage && Staging::module_enabled( $smodule ) && $live !== $new,
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
			$live    = $clean[ $module ]['live'];
			$live_v  = array_key_exists( $key, $live ) ? $live[ $key ] : ( $clean[ $module ]['defaults'][ $key ] ?? null );
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
				// The module is on and the live value differs: the new value waits in the staging area.
				'staged'  => $exists && $clean[ $module ]['enabled'] && ! Mapping::same( $live_v, $new ),
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
	 * Preview row of one top-level key of a whole-option value (Menu Organizer config, Admin Columns).
	 *
	 * @param Mapping              $mapping Mapping.
	 * @param array<string, mixed> $item    Item with `option`.
	 * @return array<string, mixed>
	 */
	private static function option_row( Mapping $mapping, array $item ): array {
		$option = (string) $item['option'];
		$key    = (string) $item['key'];
		$module = (string) $mapping->options[ $option ]['module'];
		$new    = $mapping->options[ $option ]['values'][ $key ] ?? null;
		$live   = get_option( $option, array() );
		$live   = is_array( $live ) ? $live : array();
		$stored = array_merge( $live, Staging::pending_options( $option ) );
		$has    = array_key_exists( $key, $stored );
		$cur    = $has ? $stored[ $key ] : null;
		$live_v = array_key_exists( $key, $live ) ? $live[ $key ] : null;
		return array(
			'module'  => $module,
			'key'     => $key,
			'label'   => $item['label'],
			'secret'  => false,
			'from'    => Mapping::display( $cur ),
			'to'      => Mapping::display( $new ),
			'changed' => ! Mapping::same_deep( $cur, $new ),
			'write'   => ! $has || ! Mapping::same_deep( $stored[ $key ], $new ),
			'dropped' => false,
			'staged'  => $mapping->stage && Staging::module_enabled( $module ) && ! Mapping::same_deep( $live_v, $new ),
			'origin'  => $item['origin'],
			'target'  => $option . ':' . $key,
			'option'  => $option,
		);
	}

	/**
	 * Write a mapping. Secrets go through Security::store_secret, and only when the
	 * value differs (a fresh IV on every call would make a re-run look like a change).
	 * Values for a module that is switched ON go to the staging area instead of the live option.
	 *
	 * @param Mapping $mapping Collected mapping.
	 * @param string  $run_id  Run that writes (the staging entry is keyed by it).
	 * @return array{items:array<int,array<string,mixed>>,errors:string[],written:int,unchanged:int,staged:int}
	 */
	public static function apply( Mapping $mapping, string $run_id = '' ): array {
		$preview = self::preview( $mapping );
		$written = 0;
		$staged  = 0;
		$run_id  = '' !== Snapshot::clean_id( $run_id ) ? $run_id : 'rnorun';

		foreach ( $mapping->settings as $module => $input ) {
			$schema = self::schema( $module );
			if ( null === $schema ) {
				continue;
			}
			$keys = array();
			foreach ( $preview['items'] as $row ) {
				if ( $row['module'] === $module && $row['write'] && empty( $row['option'] ) ) {
					$keys[] = (string) $row['key'];
					++$written;
				}
			}
			if ( ! $keys ) {
				continue;
			}
			if ( $mapping->stage && Staging::module_enabled( (string) $module ) ) {
				Staging::put_settings( $run_id, (string) $module, array_intersect_key( Settings::clean( $input, $schema ), array_flip( $keys ) ) );
				$staged += count( $keys );
			} else {
				( new Settings( self::option_name( $module ) ) )->save( $input, $schema );
			}
		}
		foreach ( $mapping->options as $option => $spec ) {
			$keys = array();
			foreach ( $preview['items'] as $row ) {
				if ( ( $row['option'] ?? '' ) === $option && $row['write'] ) {
					$keys[] = (string) $row['key'];
				}
			}
			if ( ! $keys ) {
				continue;
			}
			$values = array_intersect_key( $spec['values'], array_flip( $keys ) );
			$written += count( $keys );
			if ( $mapping->stage && Staging::module_enabled( (string) $spec['module'] ) ) {
				Staging::put_options( $run_id, (string) $spec['module'], (string) $option, $values );
				$staged += count( $keys );
			} else {
				$live = get_option( (string) $option, array() );
				update_option( (string) $option, array_merge( is_array( $live ) ? $live : array(), $values ) );
			}
		}
		foreach ( $mapping->secrets as $option => $secret ) {
			if ( '' === $secret['value'] ) {
				continue;
			}
			$current = Staging::pending_secret( $option ) ?? Security::get_secret( $option );
			if ( $current === $secret['value'] ) {
				continue;
			}
			if ( $mapping->stage && Staging::module_enabled( (string) ( $secret['module'] ?? '' ) ) && Security::get_secret( $option ) !== $secret['value'] ) {
				Staging::put_secret( $run_id, (string) $secret['module'], $option, $secret['value'] );
				++$staged;
			} else {
				Security::store_secret( $option, $secret['value'] );
			}
			++$written;
		}

		return array(
			'items'     => $preview['items'],
			'errors'    => $preview['errors'],
			'written'   => $written,
			'unchanged' => max( 0, count( $preview['items'] ) - $written ),
			'staged'    => $staged,
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
			if ( ! empty( $row['option'] ) ) {
				$checks[] = array(
					'id'     => $id,
					'label'  => $row['label'],
					'ok'     => ! $row['write'],
					'staged' => ! $row['write'] && $row['staged'],
					'detail' => ! $row['write']
						? $row['to']
						: sprintf(
							/* translators: 1: expected value, 2: value found in UX Studio. */
							__( 'Expected "%1$s", UX Studio has "%2$s".', 'ux-studio' ),
							$row['to'],
							$row['from']
						),
				);
				continue;
			}
			if ( $row['secret'] ) {
				$stored   = Staging::pending_secret( (string) $row['key'] ) ?? Security::get_secret( (string) $row['key'] );
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
					'staged' => $ok && $row['staged'],
					'detail' => $ok
						? ( $row['staged'] ? __( 'The key is prepared encrypted, it takes effect after the switch.', 'ux-studio' ) : __( 'The key is stored encrypted.', 'ux-studio' ) )
						: __( 'The key is not stored in UX Studio.', 'ux-studio' ),
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
			$stored = array_merge( (array) get_option( self::option_name( (string) $row['module'] ), array() ), Staging::pending_settings( (string) $row['module'] ) );
			$ok     = array_key_exists( $row['key'], $stored ) && ! $row['write'];
			$checks[] = array(
				'id'     => $id,
				'label'  => $row['label'],
				'ok'     => $ok,
				'staged' => $ok && $row['staged'],
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
