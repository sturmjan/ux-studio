<?php
/**
 * Applies, reverts and describes the onboarding starter pack on the live site.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Onboarding;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Migration\ActivationGuard;
use UxStudio\Core\Migration\AutoStart;
use UxStudio\Core\Migration\Overlaps;
use UxStudio\Core\Migration\Snapshot;
use UxStudio\Core\Modules;
use UxStudio\Modules\BaseModule;
use UxStudio\Modules\SecurityOptimization\HtaccessWriter;
use UxStudio\Modules\SecurityOptimization\LoginUrlNotice;
use UxStudio\Modules\SecurityOptimization\LoginUrlPending;
use UxStudio\Modules\SecurityOptimization\Module as Security;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The same paths as the Modules screen uses, so nothing is bypassed:
 *
 *  - modules go on through ActivationGuard (a foreign plugin that does the same job refuses the module, a partial
 *    overlap needs the administrator's confirmation) and Modules::set_enabled(),
 *  - settings go through BaseModule::save_settings() (the method behind POST settings/{id}), so side effects such
 *    as the Security module's .htaccess block run.
 *
 * They are called as functions and not through rest_do_request(): the per-user write budget (60 a minute) of the
 * REST routes would run out in the middle of a pack of 20 modules.
 *
 * Before anything changes, one snapshot is stored (option uxstudio_starter_snapshot): the module list and the
 * options of every module the pack may touch. revert() puts exactly that back.
 */
final class Applier {

	private Modules $modules;

	/**
	 * @param Modules $modules Module registry.
	 */
	public function __construct( Modules $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Everything the wizard shows: detections and items with their current state.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		$env     = Environment::detect( $this->modules );
		$enabled = $this->modules->enabled_ids();
		$items   = array();
		foreach ( StarterPack::items( $env ) as $item ) {
			$mods = array();
			foreach ( $item['modules'] as $module ) {
				$mods[] = array(
					'id'      => $module,
					'name'    => ActivationGuard::module_name( $module ),
					'enabled' => in_array( $module, $enabled, true ),
					'overlap' => $this->overlap_of( $module ),
				);
			}
			$item['modules'] = $mods;
			$item['preset']  = $this->describe_preset( (array) $item['settings'] );
			unset( $item['settings'] );
			$items[] = $item;
		}
		$snapshot = get_option( StarterPack::SNAPSHOT_OPTION, null );
		$slug     = $this->login_slug();
		$record   = LoginUrlPending::record();
		return array(
			'detections' => array(
				'woocommerce'         => $env['woocommerce'],
				'cloudflare'          => $env['cloudflare'],
				'xff'                 => $env['xff'],
				'server'              => $env['server'],
				'server_software'     => $env['server_software'],
				'htaccess_writable'   => $env['htaccess_writable'],
				'pretty_permalinks'   => $env['pretty_permalinks'],
				'login_slug_collides' => $env['login_slug_collides'],
				'login_logo'          => (int) $env['login_logo_id'] > 0,
			),
			'items'      => $items,
			'applied'    => (int) get_option( StarterPack::APPLIED_OPTION, 0 ),
			'can_revert' => is_array( $snapshot ),
			'login_url'  => array(
				'state' => null === $record ? 'none' : LoginUrlPending::state( $record['slug'] ),
				'since' => null === $record ? 0 : $record['since'],
				'slug'  => null === $record ? $slug : $record['slug'],
				'url'   => '' === (string) ( $record['slug'] ?? $slug ) ? '' : home_url( '/' . ( $record['slug'] ?? $slug ) . '/' ),
			),
		);
	}

	/**
	 * Overlap status of enabling one module, from the activation guard.
	 *
	 * @param string $module Module id.
	 * @return array{status:string,plugins:string[]}
	 */
	private function overlap_of( string $module ): array {
		$decision = ActivationGuard::decide( array( $module ) );
		$rows     = array_merge( $decision['blocking'], $decision['confirm'] );
		$status   = 'ok';
		if ( 'blocked' === $decision['result'] ) {
			$status = 'blocked';
		} elseif ( 'confirm' === $decision['result'] ) {
			$status = 'confirm';
		}
		return array(
			'status'  => $status,
			'plugins' => array_values( array_unique( array_map( 'strval', array_column( $rows, 'name' ) ) ) ),
		);
	}

	/**
	 * The preset in words: schema label and human value of every setting.
	 *
	 * @param array<string, array<string, mixed>> $settings Module id => key => value.
	 * @return array<int, array{module:string,module_name:string,key:string,label:string,value:mixed,value_label:string}>
	 */
	public function describe_preset( array $settings ): array {
		$out = array();
		foreach ( $settings as $module => $values ) {
			$instance = $this->modules->instance( (string) $module );
			$schema   = array();
			if ( $instance ) {
				foreach ( $instance->settings_schema() as $field ) {
					$schema[ $field['key'] ] = $field;
				}
			}
			foreach ( $values as $key => $value ) {
				$field = $schema[ $key ] ?? array();
				$out[] = array(
					'module'      => (string) $module,
					'module_name' => ActivationGuard::module_name( (string) $module ),
					'key'         => (string) $key,
					'label'       => (string) ( $field['label'] ?? $key ),
					'value'       => $value,
					'value_label' => self::value_label( $value, $field ),
				);
			}
		}
		return $out;
	}

	/**
	 * A value as the administrator reads it.
	 *
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $field Schema field (may be empty).
	 */
	public static function value_label( $value, array $field ): string {
		$options = (array) ( $field['options'] ?? array() );
		if ( is_bool( $value ) || 'toggle' === ( $field['type'] ?? '' ) ) {
			return $value ? __( 'On', 'ux-studio' ) : __( 'Off', 'ux-studio' );
		}
		if ( 'media' === ( $field['type'] ?? '' ) && (int) $value > 0 && function_exists( 'wp_get_attachment_url' ) ) {
			$url = (string) wp_get_attachment_url( (int) $value );
			return '' !== $url ? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) : '#' . (int) $value;
		}
		if ( is_array( $value ) ) {
			$labels = array();
			foreach ( $value as $one ) {
				$labels[] = isset( $options[ $one ] ) ? (string) $options[ $one ] : (string) $one;
			}
			return implode( ', ', $labels );
		}
		if ( isset( $options[ $value ] ) ) {
			return (string) $options[ $value ];
		}
		$text = trim( (string) $value );
		return strlen( $text ) > 120 ? substr( $text, 0, 117 ) . '...' : str_replace( "\n", ', ', $text );
	}

	/**
	 * Apply the selected items.
	 *
	 * @param string[]                           $ids       Item ids (whitelisted here again).
	 * @param array<string, mixed>               $overrides Item id => module id => key => value.
	 * @param string[]                           $confirmed Item ids for which the administrator confirmed a partial overlap.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply( array $ids, array $overrides = array(), array $confirmed = array() ) {
		$ids = StarterPack::sanitize_ids( $ids );
		if ( ! $ids ) {
			return new WP_Error( 'uxstudio_starter_empty', __( 'Choose at least one item.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$confirmed = StarterPack::sanitize_ids( $confirmed );
		$env       = Environment::detect( $this->modules );

		// 1) Remember how everything is now, BEFORE anything changes.
		$touched = array();
		foreach ( $ids as $id ) {
			$item = StarterPack::item( $id, $env );
			if ( $item && $item['available'] ) {
				$touched = array_merge( $touched, $item['modules'], array_keys( (array) $item['settings'] ) );
			}
		}
		$touched = array_values( array_unique( $touched ) );
		$this->remember( $touched );

		// 2) Item by item, the login address last (ids() keeps that order).
		$results = array();
		foreach ( $ids as $id ) {
			$item = StarterPack::item( $id, $env );
			if ( ! $item ) {
				continue;
			}
			$item      = StarterPack::apply_overrides( $item, $overrides[ $id ] ?? array() );
			$results[] = $this->apply_item( $item, in_array( $id, $confirmed, true ) );
		}

		update_option( StarterPack::APPLIED_OPTION, time(), false );
		AutoStart::flush_hint_cache();
		Overlaps::flush();
		ActivityLog::log(
			'core',
			'starter_applied',
			'',
			0,
			array(
				'items'   => array_column( $results, 'status', 'id' ),
				'bots_removed' => StarterPack::bots_removed( (string) $env['bot_blacklist'] ),
			)
		);

		return array(
			'results'   => $results,
			'applied'   => (int) get_option( StarterPack::APPLIED_OPTION, 0 ),
			'login_url' => $this->describe()['login_url'],
		);
	}

	/**
	 * Apply one item.
	 *
	 * @param array<string, mixed> $item      Item (overrides already merged).
	 * @param bool                 $confirmed A partial overlap was confirmed.
	 * @return array<string, mixed>
	 */
	private function apply_item( array $item, bool $confirmed ): array {
		$result = array(
			'id'      => $item['id'],
			'status'  => 'applied',
			'reason'  => '',
			'modules' => array(),
			'plugins' => array(),
			'errors'  => array(),
		);
		if ( empty( $item['available'] ) ) {
			$result['status'] = 'skipped';
			$result['reason'] = (string) $item['unavailable'];
			return $result;
		}

		// Modules on, through the activation guard.
		$on = array();
		foreach ( $item['modules'] as $module ) {
			$state = $this->enable( (string) $module, $confirmed );
			$result['modules'][ $module ] = $state['status'];
			if ( 'enabled' === $state['status'] || 'already' === $state['status'] ) {
				$on[] = $module;
			} else {
				$result['plugins'] = array_merge( $result['plugins'], $state['plugins'] );
				$result['reason']  = $state['status'];
			}
		}
		$result['plugins'] = array_values( array_unique( $result['plugins'] ) );

		// The custom login address starts in the pending state, BEFORE the setting exists.
		if ( StarterPack::LOGIN_ITEM === $item['id'] && in_array( 'security-optimization', $on, true ) ) {
			LoginUrlPending::start( (string) $item['settings']['security-optimization']['login_url'] );
		}

		// Settings through the save path of the module.
		foreach ( (array) $item['settings'] as $module => $values ) {
			if ( ! in_array( $module, $on, true ) || ! $values ) {
				continue;
			}
			$instance = $this->modules->instance( (string) $module );
			if ( ! $instance instanceof BaseModule ) {
				continue;
			}
			try {
				$instance->save_settings( $values );
			} catch ( \Throwable $e ) {
				$result['errors'][] = $module . ': ' . $e->getMessage();
			}
		}
		if ( StarterPack::LOGIN_ITEM === $item['id'] && ! in_array( 'security-optimization', $on, true ) ) {
			LoginUrlPending::clear();
		}

		if ( $result['errors'] ) {
			$result['status'] = 'error';
		} elseif ( ! $on ) {
			$result['status'] = 'skipped';
		} elseif ( count( $on ) < count( $item['modules'] ) ) {
			$result['status'] = 'partial';
		}
		return $result;
	}

	/**
	 * Switch one module on the way POST modules/{id} does.
	 *
	 * @param string $module    Module id.
	 * @param bool   $confirmed Partial overlap confirmed.
	 * @return array{status:string,plugins:string[]} status: enabled | already | blocked | confirm | unknown.
	 */
	private function enable( string $module, bool $confirmed ): array {
		if ( ! isset( $this->modules->all()[ $module ] ) ) {
			return array(
				'status'  => 'unknown',
				'plugins' => array(),
			);
		}
		if ( in_array( $module, $this->modules->enabled_ids(), true ) ) {
			return array(
				'status'  => 'already',
				'plugins' => array(),
			);
		}
		Overlaps::flush();
		$decision = ActivationGuard::decide( array( $module ), $confirmed );
		ActivationGuard::log_decision( array( $module ), $decision );
		if ( null !== ActivationGuard::error_for( $decision ) ) {
			$rows = array_merge( $decision['blocking'], $decision['confirm'] );
			return array(
				'status'  => 'blocked' === $decision['result'] ? 'blocked' : 'confirm',
				'plugins' => array_values( array_unique( array_map( 'strval', array_column( $rows, 'name' ) ) ) ),
			);
		}
		if ( ! $this->modules->set_enabled( $module, true ) ) {
			return array(
				'status'  => 'unknown',
				'plugins' => array(),
			);
		}
		return array(
			'status'  => 'enabled',
			'plugins' => array(),
		);
	}

	/**
	 * Store the snapshot (merged with an earlier one, so the original state is never overwritten).
	 *
	 * @param string[] $touched Module ids the pack may touch.
	 */
	private function remember( array $touched ): void {
		$names = array( 'uxstudio_active_modules', LoginUrlPending::OPTION, StarterPack::APPLIED_OPTION, HtaccessWriter::HASH_OPTION );
		foreach ( $touched as $module ) {
			$names[] = 'uxstudio_' . str_replace( '-', '_', (string) $module );
		}
		$old = get_option( StarterPack::SNAPSHOT_OPTION, null );
		if ( is_array( $old ) ) {
			$fresh    = Snapshot::read_options( array_diff( $names, array_keys( (array) $old['options'] ) ) );
			$snapshot = array_merge(
				$old,
				array(
					'options' => (array) $old['options'] + $fresh,
					'touched' => array_values( array_unique( array_merge( (array) $old['touched'], $touched ) ) ),
				)
			);
		} else {
			$snapshot = array(
				'time'    => time(),
				'user_id' => get_current_user_id(),
				'modules' => array_values( array_map( 'strval', (array) get_option( 'uxstudio_active_modules', array() ) ) ),
				'options' => Snapshot::read_options( $names ),
				'touched' => $touched,
			);
		}
		update_option( StarterPack::SNAPSHOT_OPTION, $snapshot, false );
	}

	/**
	 * Put everything back the way the snapshot says.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function revert() {
		$snapshot = get_option( StarterPack::SNAPSHOT_OPTION, null );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['options'], $snapshot['modules'] ) ) {
			return new WP_Error( 'uxstudio_starter_no_snapshot', __( 'There is nothing to undo.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$before = array_map( 'strval', (array) $snapshot['modules'] );
		$errors = array();

		// 1) Modules the pack switched on go off again (their own clean-up runs, e.g. the .htaccess block).
		$off = array();
		foreach ( array_reverse( $this->modules->enabled_ids() ) as $module ) {
			if ( ! in_array( $module, $before, true ) ) {
				$this->modules->set_enabled( $module, false );
				$off[] = $module;
			}
		}

		// 2) Options exactly as before (settings, module list, pending login address, applied marker).
		$restored = Snapshot::restore_options( (array) $snapshot['options'] );

		// 3) Modules that stay on run their side effects again with the old settings.
		foreach ( (array) ( $snapshot['touched'] ?? array() ) as $module ) {
			if ( ! in_array( $module, $before, true ) || in_array( $module, $off, true ) ) {
				continue;
			}
			$instance = $this->modules->instance( (string) $module );
			if ( $instance ) {
				try {
					$instance->after_migration_switch();
				} catch ( \Throwable $e ) {
					$errors[] = $module . ': ' . $e->getMessage();
				}
			}
		}

		delete_option( StarterPack::SNAPSHOT_OPTION );
		delete_option( StarterPack::APPLIED_OPTION );
		AutoStart::flush_hint_cache();
		Overlaps::flush();
		ActivityLog::log( 'core', 'starter_reverted', '', 0, array( 'modules_off' => $off, 'errors' => count( $errors ) ) );

		return array(
			'modules_off' => $off,
			'restored'    => $restored,
			'errors'      => $errors,
		);
	}

	/**
	 * Step B of the custom login address: the administrator has seen it work, close wp-login.php.
	 *
	 * @param bool $force Skip the server's own check (the administrator opened the address in a browser).
	 * @return array<string, mixed>|WP_Error
	 */
	public function confirm_login_url( bool $force = false ) {
		$record = LoginUrlPending::record();
		if ( null === $record ) {
			return new WP_Error( 'uxstudio_login_not_pending', __( 'No new login address is waiting for confirmation.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$security = $this->modules->instance( 'security-optimization' );
		if ( ! $security instanceof Security || ! in_array( 'security-optimization', $this->modules->enabled_ids(), true ) ) {
			return new WP_Error( 'uxstudio_login_not_pending', __( 'The Security module is off, so there is no login address to confirm.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		$values = $security->settings_values();
		if ( empty( $values['login_url_enabled'] ) || trim( (string) $values['login_url'], '/' ) !== $record['slug'] ) {
			return new WP_Error( 'uxstudio_login_changed', __( 'The login address was changed in the meantime. Nothing was closed.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		$url = home_url( '/' . $record['slug'] . '/' );

		if ( ! $force ) {
			$probe = self::probe( $url );
			if ( ! $probe['ok'] ) {
				return new WP_Error(
					'uxstudio_login_probe_failed',
					__( 'The server could not open the new login address and find the login form on it. Open it in a private window yourself; if you see the login form, confirm again with "I saw the login form".', 'ux-studio' ),
					array(
						'status'    => 409,
						'can_force' => true,
						'http_code' => $probe['code'],
						'url'       => $url,
					)
				);
			}
		}

		// Step B: leave the pending state, then check that the lock really is in force (a collision or a missing
		// pretty permalink structure keeps wp-login.php open by itself; then the pending state is put back).
		LoginUrlPending::clear();
		$fresh  = $this->modules->instance( 'security-optimization' );
		$status = $fresh instanceof Security ? $fresh->login_lock_status() : array( 'active' => false, 'reason' => 'unknown' );
		if ( empty( $status['active'] ) ) {
			LoginUrlPending::start( $record['slug'] );
			return new WP_Error(
				'uxstudio_login_not_enforceable',
				__( 'The login address cannot be enforced on this site right now, so wp-login.php stays open.', 'ux-studio' ),
				array(
					'status' => 409,
					'reason' => (string) ( $status['reason'] ?? '' ),
				)
			);
		}
		$sent = LoginUrlNotice::send_confirmed_mail( $url );
		ActivityLog::log( 'security-optimization', 'login_url_confirmed', 'setting', 0, array( 'url' => $url, 'mail' => $sent ? 'sent' : 'failed', 'forced' => $force ) );
		return array(
			'confirmed' => true,
			'url'       => $url,
			'mail'      => $sent ? 'sent' : 'failed',
		);
	}

	/**
	 * Does the server itself get the login form from the address (a request without cookies)?
	 *
	 * @param string $url Address.
	 * @return array{ok:bool,code:int}
	 */
	public static function probe( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'cookies'     => array(),
				// Same rule WordPress applies to its own loopback requests (a local test site has a self-signed certificate).
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'   => false,
				'code' => 0,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'   => self::probe_verdict( $code, (string) wp_remote_retrieve_body( $response ) ),
			'code' => $code,
		);
	}

	/**
	 * Pure: is this the login form?
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 */
	public static function probe_verdict( int $code, string $body ): bool {
		return 200 === $code && false !== strpos( $body, 'id="loginform"' );
	}

	/**
	 * Current login slug of the Security module.
	 */
	private function login_slug(): string {
		$security = $this->modules->instance( 'security-optimization' );
		return $security ? trim( (string) ( $security->settings_values()['login_url'] ?? '' ), '/' ) : '';
	}
}
