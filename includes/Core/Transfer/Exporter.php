<?php
/**
 * Builds the settings export: a whitelist by the schema of each module, never a dump of an option.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Modules;
use UxStudio\Core\Security;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * What goes in (per module): the portable settings declared by the settings schema, optionally the on / off
 * state, optionally the content of modules that have a data provider, optionally the secrets (encrypted with
 * a passphrase). What never goes in is decided in NonPortable.
 */
final class Exporter {

	private Modules $modules;

	/**
	 * @param Modules $modules Module registry.
	 */
	public function __construct( Modules $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Translate a string of a meta.json (data, not code - see Rest::translate_meta).
	 *
	 * @param string $text English source.
	 */
	private function t( string $text ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgids are registered in languages/ux-studio-meta.php.
		return '' === $text ? '' : translate( $text, 'ux-studio' );
	}

	/**
	 * Modules that can be exported, with what they offer. Virtual groups are not listed (their features are).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function catalog(): array {
		$enabled   = $this->modules->enabled_ids();
		$providers = DataProviders::all( $this->modules );
		$out       = array();
		foreach ( $this->modules->all() as $id => $meta ) {
			if ( ! empty( $meta['virtual'] ) ) {
				continue;
			}
			$instance = $this->modules->instance( $id );
			if ( ! $instance ) {
				continue;
			}
			$class    = NonPortable::classify( $id, $instance->settings_schema() );
			$provider = $providers[ $id ] ?? null;
			if ( ! $class['portable'] && ! $class['secret'] && ! $provider ) {
				continue;
			}
			$allowed = true;
			if ( $provider && '' !== $provider->capability() && ! current_user_can( $provider->capability() ) ) {
				$allowed = false;
			}
			$parent = isset( $meta['parent'] ) ? (string) $meta['parent'] : '';
			$out[]  = array(
				'id'            => $id,
				'name'          => $this->t( (string) ( $meta['name'] ?? $id ) ),
				'description'   => $this->t( (string) ( $meta['description'] ?? '' ) ),
				'group'         => (string) ( $meta['group'] ?? 'general' ),
				'icon'          => (string) ( $meta['icon'] ?? 'puzzle' ),
				'enabled'       => in_array( $id, $enabled, true ),
				'parent'        => '' === $parent ? null : $parent,
				'parent_name'   => '' === $parent ? '' : $this->t( (string) ( $this->modules->all()[ $parent ]['name'] ?? $parent ) ),
				'settings'      => count( $class['portable'] ),
				'secrets'       => count( $this->secrets_set( $id, $class['secret'] ) ),
				'data'          => $provider ? array(
					'kind'    => $provider->kind(),
					'label'   => $provider->label(),
					'count'   => $provider->count(),
					'allowed' => $allowed,
					'needs'   => $allowed ? '' : $provider->capability(),
				) : null,
			);
		}
		return $out;
	}

	/**
	 * Which of the portable secrets of a module have a value on this site?
	 *
	 * @param string   $module Module id.
	 * @param string[] $keys   Secret keys of the module.
	 * @return string[]
	 */
	private function secrets_set( string $module, array $keys ): array {
		$set = array();
		foreach ( $keys as $key ) {
			$option = NonPortable::secret_fields( $module )[ $key ] ?? '';
			if ( '' !== $option && '' !== Security::get_secret( $option ) ) {
				$set[] = $key;
			}
		}
		return $set;
	}

	/**
	 * Resolve the requested ids: groups become their features, unknown ids are dropped.
	 *
	 * @param string[] $ids Requested module ids.
	 * @return string[]
	 */
	public function resolve_ids( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$id   = (string) $id;
			$meta = $this->modules->all()[ $id ] ?? null;
			if ( ! $meta ) {
				continue;
			}
			if ( Modules::is_group( $meta ) ) {
				foreach ( (array) $meta['children'] as $child ) {
					$out[] = (string) $child;
				}
			} elseif ( empty( $meta['virtual'] ) ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Build the export.
	 *
	 * @param string[]             $ids  Module ids.
	 * @param array<string, mixed> $opts include_enabled, include_data, include_secrets, passphrase, passphrase_confirm.
	 * @return array{envelope:array<string,mixed>,json:string,filename:string,summary:array<string,mixed>}|WP_Error
	 */
	public function build( array $ids, array $opts ) {
		$ids = $this->resolve_ids( $ids );
		if ( ! $ids ) {
			return new WP_Error( 'uxstudio_transfer_empty', __( 'Choose at least one module.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$include_enabled = ! empty( $opts['include_enabled'] );
		$include_data    = ! empty( $opts['include_data'] );
		$dry             = ! empty( $opts['dry'] );
		$include_secrets = ! empty( $opts['include_secrets'] );
		$passphrase      = (string) ( $opts['passphrase'] ?? '' );

		if ( $include_secrets && ! $dry ) {
			if ( ! Crypto::available() ) {
				return new WP_Error( 'uxstudio_transfer_no_sodium', __( 'This server has no libsodium (the PHP sodium extension), so secrets cannot be encrypted. Export without secrets, or ask your host to enable the extension.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$problem = Crypto::check_new_passphrase( $passphrase, (string) ( $opts['passphrase_confirm'] ?? '' ) );
			if ( '' !== $problem ) {
				$messages = array(
					'short'    => sprintf(
						/* translators: %d: minimum length. */
						__( 'The passphrase must have at least %d characters.', 'ux-studio' ),
						Crypto::MIN_PASS
					),
					'long'     => __( 'The passphrase is too long.', 'ux-studio' ),
					'mismatch' => __( 'The two passphrases are not the same.', 'ux-studio' ),
				);
				return new WP_Error( 'uxstudio_transfer_passphrase', $messages[ $problem ] ?? $messages['mismatch'], array( 'status' => 400 ) );
			}
		}

		$enabled   = $this->modules->enabled_ids();
		$providers = DataProviders::all( $this->modules );
		$modules   = array();
		$secrets   = array();
		$warnings  = array();
		$stats     = array(
			'modules'  => 0,
			'settings' => 0,
			'items'    => 0,
			'secrets'  => 0,
			'manual'   => 0,
		);

		foreach ( $ids as $id ) {
			$instance = $this->modules->instance( $id );
			if ( ! $instance ) {
				continue;
			}
			$schema = $instance->settings_schema();
			$class  = NonPortable::classify( $id, $schema );
			$fields = array();
			foreach ( $schema as $field ) {
				if ( is_array( $field ) && isset( $field['key'] ) ) {
					$fields[ (string) $field['key'] ] = $field;
				}
			}
			$entry = array();
			if ( $include_enabled ) {
				$entry['enabled'] = in_array( $id, $enabled, true );
			}

			// Settings.
			$settings = array();
			$manual   = array();
			$values   = $class['portable'] || $class['manual'] || $class['secret'] ? $instance->settings_values() : array();
			foreach ( $class['portable'] as $key ) {
				$value = $values[ $key ] ?? null;
				if ( null === $value || NonPortable::is_sealed( $value ) ) {
					continue;
				}
				$exported = Planner::export_value( $value, $fields[ $key ], static fn( int $att ): string => (string) wp_get_attachment_url( $att ) );
				if ( ! $exported['include'] ) {
					$manual[] = $key; // An image that no longer exists.
					continue;
				}
				$settings[ $key ] = $exported['value'];
			}
			foreach ( array_keys( $class['manual'] ) as $key ) {
				if ( ! NonPortable::is_blank( $values[ $key ] ?? null ) ) {
					$manual[] = $key;
				}
			}

			// Secrets.
			$set = $this->secrets_set( $id, $class['secret'] );
			foreach ( $set as $key ) {
				if ( $include_secrets ) {
					$secrets[ $id ][ $key ] = Security::get_secret( NonPortable::secret_fields( $id )[ $key ] );
					++$stats['secrets'];
				} else {
					$manual[] = $key;
				}
			}
			foreach ( NonPortable::MANUAL_CONNECTIONS[ $id ] ?? array() as $connection ) {
				if ( '' !== Security::get_secret( $connection['option'] ) ) {
					$manual[] = NonPortable::manual_label( $connection['label'] );
				}
			}

			$entry['settings'] = $settings;
			$stats['settings'] += count( $settings );

			// Content.
			$provider = $providers[ $id ] ?? null;
			if ( $include_data && $provider ) {
				if ( '' !== $provider->capability() && ! current_user_can( $provider->capability() ) ) {
					$warnings[] = sprintf(
						/* translators: %s: name of the content, such as "Code snippets". */
						__( '%s were left out: exporting them needs a user who may add scripts to the site (unfiltered_html).', 'ux-studio' ),
						$provider->label()
					);
				} else {
					$items = $provider->export();
					if ( $items ) {
						$entry['data']   = array( $provider->kind() => $items );
						$stats['items'] += count( $items );
					}
				}
			}

			$entry['needs_manual'] = array_values( array_unique( $manual ) );
			$stats['manual']      += count( $entry['needs_manual'] );
			$modules[ $id ]        = $entry;
			++$stats['modules'];
		}

		$block = null;
		if ( $include_secrets && $secrets && ! $dry ) {
			$block = Crypto::encrypt( $secrets, $passphrase );
			if ( null === $block ) {
				return new WP_Error( 'uxstudio_transfer_encrypt', __( 'The secrets could not be encrypted on this server.', 'ux-studio' ), array( 'status' => 500 ) );
			}
		} elseif ( $include_secrets && ! $dry ) {
			$warnings[] = __( 'None of the chosen modules has a secret value set, so the file has no encrypted block.', 'ux-studio' );
		}
		unset( $secrets, $passphrase );

		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$envelope = Envelope::build(
			array(
				'plugin_version'  => defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '',
				'exported_at'     => gmdate( 'c' ),
				'site_name'       => (string) get_bloginfo( 'name' ),
				'site_host'       => $host,
				'include_enabled' => $include_enabled,
				'include_data'    => $include_data,
				'modules'         => $modules,
				'secrets'         => $block,
			)
		);

		if ( ! $dry ) {
			ActivityLog::log(
				'core',
				'settings_exported',
				'',
				0,
				array(
					'modules' => array_keys( $modules ),
					'data'    => $include_data,
					'secrets' => null !== $block,
				)
			);
		}

		$slug = trim( (string) preg_replace( '/[^a-z0-9.-]+/', '-', strtolower( $host ) ), '-' );
		return array(
			'envelope' => $envelope,
			'json'     => Envelope::encode( $envelope ),
			'filename' => 'ux-studio-nastaveni-' . ( '' === $slug ? 'web' : $slug ) . '-' . gmdate( 'Y-m-d' ) . '.json',
			'summary'  => $stats + array(
				'secrets_encrypted' => null !== $block,
				'warnings'          => $warnings,
			),
		);
	}
}
