<?php
/**
 * Import of a settings export: preview (writes nothing), apply (snapshot first, module save paths only), undo.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

use ReflectionMethod;
use UxStudio\Core\ActivityLog;
use UxStudio\Core\ClientIp;
use UxStudio\Core\Migration\ActivationGuard;
use UxStudio\Core\Migration\AutoStart;
use UxStudio\Core\Migration\MenuSimulator;
use UxStudio\Core\Migration\Overlaps;
use UxStudio\Core\Migration\Snapshot;
use UxStudio\Core\Modules;
use UxStudio\Core\Onboarding\Applier;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\AdminCustomiser\MenuOrganizer;
use UxStudio\Modules\BaseModule;
use UxStudio\Modules\SecurityOptimization\LoginUrlPending;
use UxStudio\Modules\SecurityOptimization\Module as SecurityModule;
use UxStudio\Modules\SecurityOptimization\TwoFactorStore;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The preview and the apply run the same planning (`plan_module()`), and apply plans AGAIN from the file
 * it is handed: nothing the preview showed is trusted. Every value goes through
 *
 *   whitelist by schema -> NonPortable -> TypeCheck (strict JSON types) -> merge / replace -> ImportRules
 *   (safety) -> the module's own `save_settings()` (schema sanitising, side effects such as .htaccess)
 *
 * and never into an option directly. One module failing does not stop the others.
 */
final class Importer {

	/** Failed passphrase attempts per user before the import asks to wait. */
	private const MAX_PASSPHRASE_FAILS = 8;

	/**
	 * Modules whose own `save_settings()` only stores secrets and values: safe to run while the module is off.
	 * Others with an override (.htaccess, cron events, tables) are only written while the module is on.
	 *
	 * @var string[]
	 */
	private const SAFE_WHEN_OFF = array( 'smtp-email', 'stock-photos', 'ai-assistant', 'opening-hours' );

	/**
	 * Modules that need a plugin to do anything: module id => plugin file, name and the settings that depend on it.
	 *
	 * @var array<string, array{plugin:string,name:string,keys:string[]}>
	 */
	private const REQUIRES = array(
		'google-review-request' => array(
			'plugin' => 'woocommerce/woocommerce.php',
			'name'   => 'WooCommerce',
			'keys'   => array( 'trigger_on_order_complete' ),
		),
		'ai-markdown'           => array(
			'plugin' => 'woocommerce/woocommerce.php',
			'name'   => 'WooCommerce',
			'keys'   => array( 'enable_woocommerce', 'show_price', 'show_currency', 'show_stock_status', 'show_product_categories', 'show_product_attributes' ),
		),
	);

	private Modules $modules;

	/**
	 * @param Modules $modules Module registry.
	 */
	public function __construct( Modules $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Explanation of the safety rules, shown above every preview.
	 *
	 * @return string[]
	 */
	public static function safety_rules(): array {
		return array(
			__( 'Content-Security-Policy is never switched to Enforce by an import; at most to the policy test (report only).', 'ux-studio' ),
			__( 'A custom login address is saved in two steps: wp-login.php stays open until you open the new address and confirm it.', 'ux-studio' ),
			__( 'Two-factor login is switched on only if you have already set it up for your own account.', 'ux-studio' ),
			__( 'Country blocks are imported only if your IP address is on the firewall allowlist.', 'ux-studio' ),
			__( 'The menu lock is switched on only if it keeps UX Studio, Plugins, Users and Settings open for you.', 'ux-studio' ),
			__( 'Maintenance mode is never switched on by an import unless you tick the extra box.', 'ux-studio' ),
			__( 'Code (custom CSS, scripts, snippets) is imported only if you may add scripts to the site (unfiltered_html). Snippets arrive switched off.', 'ux-studio' ),
			__( 'Redirecting to HTTPS is switched on only when you are on HTTPS now; CAPTCHA only when this site has its own CAPTCHA keys.', 'ux-studio' ),
			__( 'Everything is saved through the module itself, after a snapshot you can undo for 30 days.', 'ux-studio' ),
		);
	}

	/**
	 * Normalised options from a request.
	 *
	 * @param mixed $raw Options payload.
	 * @return array{modules:string[]|null,mode:string,apply_enabled:bool,disable_with_data:bool,allow_maintenance:bool,confirmed:string[]}
	 */
	public static function options( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$ids = null;
		if ( isset( $raw['modules'] ) && is_array( $raw['modules'] ) ) {
			$ids = array();
			foreach ( $raw['modules'] as $id ) {
				if ( is_string( $id ) && 1 === preg_match( Envelope::MODULE_ID, $id ) ) {
					$ids[] = $id;
				}
			}
		}
		$confirmed = array();
		foreach ( (array) ( $raw['confirmed'] ?? array() ) as $id ) {
			if ( is_string( $id ) && 1 === preg_match( Envelope::MODULE_ID, $id ) ) {
				$confirmed[] = $id;
			}
		}
		return array(
			'modules'           => $ids,
			'mode'              => 'replace' === ( $raw['mode'] ?? '' ) ? 'replace' : 'merge',
			'apply_enabled'     => ! empty( $raw['apply_enabled'] ),
			'disable_with_data' => ! empty( $raw['disable_with_data'] ),
			'allow_maintenance' => ! empty( $raw['allow_maintenance'] ),
			'confirmed'         => $confirmed,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Reading the file
	 * ------------------------------------------------------------------ */

	/**
	 * Parse the text and open the secrets block when there is a passphrase.
	 *
	 * @param string $content    File text.
	 * @param string $passphrase Passphrase ('' when none was typed).
	 * @return array{envelope:array<string,mixed>,warnings:array<int,array{code:string,message:string}>,secrets:array<string,array<string,string>>|null,secrets_state:string}|WP_Error
	 */
	public function read( string $content, string $passphrase ) {
		$parsed = Envelope::parse( $content, defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '' );
		if ( ! $parsed['ok'] ) {
			return new WP_Error( 'uxstudio_transfer_' . $parsed['error'], $parsed['message'], array( 'status' => 400 ) );
		}
		$envelope = $parsed['envelope'];
		$secrets  = null;
		$state    = 'none';
		if ( null !== $envelope['secrets'] ) {
			if ( '' === $passphrase ) {
				$state = 'needs_passphrase';
			} else {
				if ( ! Crypto::available() ) {
					return new WP_Error( 'uxstudio_transfer_no_sodium', __( 'The file has encrypted secrets, but this server has no libsodium (the PHP sodium extension), so they cannot be opened. You can import without them.', 'ux-studio' ), array( 'status' => 400 ) );
				}
				$key   = 'uxstudio_tr_fail_' . get_current_user_id();
				$fails = (int) get_transient( $key );
				if ( $fails >= self::MAX_PASSPHRASE_FAILS ) {
					return new WP_Error( 'uxstudio_transfer_throttled', __( 'Too many wrong passphrases. Wait a few minutes and try again.', 'ux-studio' ), array( 'status' => 429 ) );
				}
				$secrets = Crypto::decrypt( $envelope['secrets'], $passphrase );
				if ( null === $secrets ) {
					set_transient( $key, $fails + 1, 15 * MINUTE_IN_SECONDS );
					return new WP_Error( 'uxstudio_transfer_bad_passphrase', __( 'The passphrase is wrong, or the encrypted block of the file is damaged.', 'ux-studio' ), array( 'status' => 403 ) );
				}
				delete_transient( $key );
				$state = 'decrypted';
			}
		}
		return array(
			'envelope'      => $envelope,
			'warnings'      => $parsed['warnings'],
			'secrets'       => $secrets,
			'secrets_state' => $state,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Context
	 * ------------------------------------------------------------------ */

	/**
	 * Facts about the importing user and the site that the safety rules need.
	 *
	 * @param array<string, mixed> $options Normalised options.
	 * @return array<string, mixed>
	 */
	private function context( array $options ): array {
		$user      = wp_get_current_user();
		$security  = $this->modules->instance( 'security-optimization' );
		$sec_vals  = $security ? $security->settings_values() : array();
		$proxy     = (string) ( $sec_vals['ip_firewall_proxy'] ?? 'none' );
		$menu_conf = get_option( MenuOrganizer::CONFIG_OPTION, array() );
		$menu_safe = true;
		if ( is_array( $menu_conf ) && $menu_conf ) {
			$menu_safe = array() === MenuSimulator::enforce_access( $menu_conf )['dropped'];
		}
		return array(
			'can_unfiltered_html' => current_user_can( 'unfiltered_html' ),
			'admin_has_2fa'       => (int) $user->ID > 0 && TwoFactorStore::is_enabled( (int) $user->ID ),
			'admin_roles'         => array_map( 'strval', (array) $user->roles ),
			'admin_login'         => (string) $user->user_login,
			'admin_ip'            => ClientIp::get( in_array( $proxy, array( 'none', 'cloudflare', 'xff' ), true ) ? $proxy : 'none' ),
			'allow_maintenance'   => ! empty( $options['allow_maintenance'] ),
			'menu_lock_safe'      => $menu_safe,
			'is_ssl'              => is_ssl(),
			'captcha_ready'       => '' !== Security::get_secret( 'uxstudio_secret_security_optimization_captcha_secret_key' ) && '' !== trim( (string) ( $sec_vals['captcha_site_key'] ?? '' ) ),
		);
	}

	/**
	 * Attachment id of this site for a URL from a file, or 0. Only an image that already exists in this media
	 * library counts: the URL itself, or the same file path under this site's uploads folder.
	 *
	 * @param string $url URL from the file.
	 */
	private function find_media( string $url ): int {
		if ( strlen( $url ) > 2000 || ! preg_match( '#^https?://#i', $url ) ) {
			return 0;
		}
		$id = (int) attachment_url_to_postid( $url );
		if ( $id > 0 ) {
			return $id;
		}
		$pos = strpos( $url, '/uploads/' );
		if ( false !== $pos ) {
			$uploads = wp_get_upload_dir();
			$local   = trailingslashit( (string) $uploads['baseurl'] ) . substr( strtok( $url, '?#' ) ?: $url, $pos + 9 );
			return (int) attachment_url_to_postid( $local );
		}
		return 0;
	}

	/* ------------------------------------------------------------------ *
	 * Planning
	 * ------------------------------------------------------------------ */

	/**
	 * Does the module's `save_settings()` do more than store values (it overrides the base class)?
	 *
	 * @param BaseModule $instance Module.
	 */
	private function has_side_effects( BaseModule $instance ): bool {
		try {
			return BaseModule::class !== ( new ReflectionMethod( $instance, 'save_settings' ) )->getDeclaringClass()->getName();
		} catch ( \ReflectionException $e ) {
			return true;
		}
	}

	/**
	 * Plan one module: what would change, what is held back and why. Writes nothing.
	 *
	 * @param string               $id      Module id.
	 * @param array<string, mixed> $file    The module's entry from the file.
	 * @param array<string, array<string,string>>|null $secrets Decrypted secrets (all modules) or null.
	 * @param array<string, mixed> $options Normalised options.
	 * @param array<string, mixed> $ctx     Context.
	 * @param string[]             $enabled Enabled module ids at the start of the request.
	 * @return array{view:array<string,mixed>,write:array<string,mixed>}
	 */
	private function plan_module( string $id, array $file, ?array $secrets, array $options, array $ctx, array $enabled ): array {
		$meta     = $this->modules->all()[ $id ] ?? null;
		$instance = $meta && empty( $meta['virtual'] ) ? $this->modules->instance( $id ) : null;
		$name     = $meta ? (string) translate( (string) ( $meta['name'] ?? $id ), 'ux-studio' ) : $id; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		$view     = array(
			'id'          => $id,
			'name'        => $name,
			'icon'        => (string) ( $meta['icon'] ?? 'puzzle' ),
			'group'       => (string) ( $meta['group'] ?? 'general' ),
			'known'       => null !== $instance,
			'blocked'     => '',
			'enabled'     => array(
				'file'      => array_key_exists( 'enabled', $file ) ? (bool) $file['enabled'] : null,
				'current'   => in_array( $id, $enabled, true ),
				'change'    => 'none',
				'overlap'   => array(
					'status'  => 'ok',
					'plugins' => array(),
				),
				'holds_data' => false,
			),
			'changes'     => array(),
			'same'        => 0,
			'unknown'     => array(),
			'non_portable' => array(),
			'rejected'    => array(),
			'skipped'     => array(),
			'dropped'     => array(),
			'media_missing' => array(),
			'secrets'     => array(),
			'data'        => null,
			'needs_manual' => array(),
			'warnings'    => array(),
			'measures'    => array(),
			'writable'    => true,
		);
		$write = array(
			'values'        => array(),
			'secrets'       => array(),
			'login_pending' => null,
			'enable'        => 'none',
			'data'          => null,
		);

		if ( ! $instance ) {
			$view['blocked']  = __( 'This module does not exist on this site, so it is skipped.', 'ux-studio' );
			$view['writable'] = false;
			return array(
				'view'  => $view,
				'write' => $write,
			);
		}
		if ( ! current_user_can( $instance->capability() ) ) {
			$view['blocked']  = __( 'You are not allowed to manage this module.', 'ux-studio' );
			$view['writable'] = false;
			return array(
				'view'  => $view,
				'write' => $write,
			);
		}

		$schema = $instance->settings_schema();
		$class  = NonPortable::classify( $id, $schema );
		$labels = array();
		foreach ( $schema as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$labels[ (string) $field['key'] ] = (string) ( $field['label'] ?? $field['key'] );
			}
		}
		$label = static fn( string $key ): string => $labels[ $key ] ?? $key;

		// On / off.
		$on = $view['enabled']['current'];
		if ( $options['apply_enabled'] && null !== $view['enabled']['file'] ) {
			if ( $view['enabled']['file'] && ! $on ) {
				$view['enabled']['change'] = 'enable';
				$decision                  = ActivationGuard::decide( array( $id ), in_array( $id, $options['confirmed'], true ) );
				$rows                      = array_merge( $decision['blocking'], $decision['confirm'] );
				$view['enabled']['overlap'] = array(
					'status'  => 'blocked' === $decision['result'] ? 'blocked' : ( 'confirm' === $decision['result'] ? 'confirm' : 'ok' ),
					'plugins' => array_values( array_unique( array_map( 'strval', array_column( $rows, 'name' ) ) ) ),
				);
			} elseif ( ! $view['enabled']['file'] && $on ) {
				$view['enabled']['change'] = 'disable';
			}
		}
		$provider = DataProviders::all( $this->modules )[ $id ] ?? null;
		if ( $provider && 'disable' === $view['enabled']['change'] ) {
			$view['enabled']['holds_data'] = $provider->count() > 0;
			if ( $view['enabled']['holds_data'] && ! $options['disable_with_data'] ) {
				$view['enabled']['change'] = 'keep';
			}
		}
		$write['enable'] = $view['enabled']['change'];

		// Settings.
		$current = array();
		$values  = $instance->settings_values();
		foreach ( $schema as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' !== $key ) {
				$stored          = $values[ $key ] ?? ( $field['default'] ?? null );
				$typed           = Planner::typed( $stored, $field );
				$current[ $key ] = null === $typed ? $stored : $typed; // Compared in the exact type the file carries.
			}
		}
		$in = Planner::incoming( $id, $schema, (array) ( $file['settings'] ?? array() ), fn( string $url ): int => $this->find_media( $url ) );
		foreach ( $in['unknown'] as $key ) {
			$view['unknown'][] = $key;
		}
		foreach ( $in['non_portable'] as $key ) {
			$view['non_portable'][] = array(
				'key'   => $key,
				'label' => $label( $key ),
			);
		}
		foreach ( $in['rejected'] as $key => $message ) {
			$view['rejected'][] = array(
				'key'     => $key,
				'label'   => $label( $key ),
				'message' => $message,
			);
		}
		foreach ( $in['dropped'] as $key => $count ) {
			$view['dropped'][] = array(
				'key'   => $key,
				'label' => $label( $key ),
				'count' => $count,
			);
		}
		foreach ( $in['media_missing'] as $key ) {
			$view['media_missing'][] = array(
				'key'   => $key,
				'label' => $label( $key ),
			);
		}

		$candidate = Planner::candidate( $schema, $class['portable'], $in['accepted'], 'replace' === $options['mode'], array_keys( $in['rejected'] ) );
		$ruled     = ImportRules::apply( $id, $schema, $candidate, $current, $ctx );
		foreach ( $ruled['skipped'] as $key => $info ) {
			$view['skipped'][] = array(
				'key'     => $key,
				'label'   => $label( $key ),
				'code'    => $info['code'],
				'message' => $info['message'],
			);
		}
		foreach ( $ruled['measures'] as $measure ) {
			$view['measures'][] = array(
				'key'     => $measure['key'],
				'label'   => $label( $measure['key'] ),
				'code'    => $measure['code'],
				'message' => $measure['message'],
			);
		}

		// Compare with what the module would store after its own sanitising.
		$diff    = Planner::changes( $current, $ruled['values'] );
		$cleaned = $diff['changed'] ? Settings::clean( array_map( static fn( array $pair ) => $pair['new'], $diff['changed'] ), $schema ) : array();
		$cleaned_values = array();
		foreach ( $diff['changed'] as $key => $pair ) {
			$cleaned_values[ $key ] = array_key_exists( $key, $cleaned ) ? $cleaned[ $key ] : $pair['new'];
		}
		$final = Planner::changes( $current, $cleaned_values );
		$view['changes'] = Planner::rows( $id, $schema, $final['changed'] );
		$view['same']    = count( $diff['same'] ) + count( $final['same'] );
		$write['values'] = array_intersect_key( $ruled['values'], $final['changed'] );
		$write['login_pending'] = $ruled['login_pending'];

		// Secrets (only from the decrypted block).
		$inc_secrets = Planner::incoming_secrets( $id, $secrets[ $id ] ?? null );
		foreach ( $inc_secrets['accepted'] as $key => $value ) {
			$option = NonPortable::secret_fields( $id )[ $key ];
			$has    = '' !== Security::get_secret( $option );
			if ( $has && hash_equals( Security::get_secret( $option ), $value ) ) {
				++$view['same'];
				continue;
			}
			$view['secrets'][]     = array(
				'key'         => $key,
				'label'       => $label( $key ),
				'has_current' => $has,
			);
			$write['secrets'][ $key ] = $value;
		}
		foreach ( $inc_secrets['rejected'] as $key ) {
			$view['unknown'][] = $key;
		}

		// What the file says needs a hand.
		foreach ( (array) ( $file['needs_manual'] ?? array() ) as $key ) {
			$view['needs_manual'][] = array(
				'key'   => $key,
				'label' => $label( $key ),
			);
		}

		// Writing to an off module: only where the save path has no side effects.
		$stays_off = ! $on && ( 'enable' !== $view['enabled']['change'] || 'ok' !== $view['enabled']['overlap']['status'] );
		if ( ( $write['values'] || $write['secrets'] ) && $stays_off && $this->has_side_effects( $instance ) && ! in_array( $id, self::SAFE_WHEN_OFF, true ) ) {
			$view['warnings'][] = __( 'The module is off, and saving its settings also changes files or schedules. Its settings are not imported; switch the module on (or tick "Switch modules on and off as in the file") and import again.', 'ux-studio' );
			$write['values']    = array();
			$write['secrets']   = array();
			$view['changes']    = array();
			$view['secrets']    = array();
			$write['login_pending'] = null;
		}

		// Plugin needed.
		if ( isset( self::REQUIRES[ $id ] ) ) {
			$req = self::REQUIRES[ $id ];
			if ( ! $this->plugin_active( $req['plugin'] ) && ( array_intersect( $req['keys'], array_keys( $in['accepted'] ) ) || true === $view['enabled']['file'] ) ) {
				$view['warnings'][] = sprintf(
					/* translators: %s: plugin name. */
					__( 'This site does not have %s, which the settings of this module work with.', 'ux-studio' ),
					$req['name']
				);
			}
		}
		if ( 'confirm' === $view['enabled']['overlap']['status'] || 'blocked' === $view['enabled']['overlap']['status'] ) {
			$view['warnings'][] = sprintf(
				/* translators: %s: plugin names. */
				'blocked' === $view['enabled']['overlap']['status']
					? __( 'It cannot be switched on: %s already does the same job on this site.', 'ux-studio' )
					: __( 'It overlaps with %s. Confirm to switch it on anyway.', 'ux-studio' ),
				implode( ', ', $view['enabled']['overlap']['plugins'] )
			);
		}
		if ( 'keep' === $view['enabled']['change'] ) {
			$view['warnings'][] = __( 'The file has this module off, but it holds data here, so it stays on. Tick "Also switch off modules that hold data" to switch it off.', 'ux-studio' );
		}

		// Content.
		$file_data = (array) ( $file['data'] ?? array() );
		if ( $provider && isset( $file_data[ $provider->kind() ] ) && is_array( $file_data[ $provider->kind() ] ) ) {
			$block = '';
			if ( '' !== $provider->capability() && ! current_user_can( $provider->capability() ) ) {
				$block = __( 'Skipped: this content can only be imported by a user who may add scripts to the site (unfiltered_html).', 'ux-studio' );
			} elseif ( ! $on ) {
				$block = __( 'Skipped: the module is off. Switch it on, then import again to bring its content.', 'ux-studio' );
			} elseif ( '' !== $provider->blocked_reason() ) {
				$block = $provider->blocked_reason();
			}
			$plan = '' === $block ? $provider->plan( array_values( $file_data[ $provider->kind() ] ) ) : array(
				'items'    => array(),
				'rejected' => array(),
				'warnings' => array(),
			);
			$counts = array(
				'create' => 0,
				'update' => 0,
				'same'   => 0,
			);
			foreach ( $plan['items'] as $item ) {
				++$counts[ $item['action'] ];
			}
			$view['data']  = array(
				'kind'     => $provider->kind(),
				'label'    => $provider->label(),
				'blocked'  => $block,
				'items'    => $plan['items'],
				'rejected' => $plan['rejected'],
				'warnings' => $plan['warnings'],
				'counts'   => $counts,
			);
			$write['data'] = '' === $block ? array_values( $file_data[ $provider->kind() ] ) : null;
		}

		return array(
			'view'  => $view,
			'write' => $write,
		);
	}

	/**
	 * Is a plugin active?
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 */
	private function plugin_active( string $file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $file );
	}

	/**
	 * Does the planned module change anything at all?
	 *
	 * @param array<string, mixed> $plan plan_module() result.
	 */
	private function has_work( array $plan ): bool {
		$v = $plan['view'];
		$w = $plan['write'];
		if ( $w['values'] || $w['secrets'] || in_array( $w['enable'], array( 'enable', 'disable' ), true ) ) {
			return true;
		}
		if ( $v['data'] && ( $v['data']['counts']['create'] || $v['data']['counts']['update'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Plan the whole file.
	 *
	 * @param array<string, mixed>                     $envelope Validated envelope.
	 * @param array<string, array<string,string>>|null $secrets  Decrypted secrets.
	 * @param array<string, mixed>                     $options  Normalised options.
	 * @return array<string, array{view:array<string,mixed>,write:array<string,mixed>}>
	 */
	private function plan_all( array $envelope, ?array $secrets, array $options ): array {
		$ctx     = $this->context( $options );
		$enabled = $this->modules->enabled_ids();
		$plans   = array();
		foreach ( (array) $envelope['modules'] as $id => $file ) {
			if ( null !== $options['modules'] && ! in_array( $id, $options['modules'], true ) ) {
				continue;
			}
			$plans[ (string) $id ] = $this->plan_module( (string) $id, (array) $file, $secrets, $options, $ctx, $enabled );
		}
		return $plans;
	}

	/* ------------------------------------------------------------------ *
	 * Preview
	 * ------------------------------------------------------------------ */

	/**
	 * Server side preview. Nothing is written.
	 *
	 * @param string               $content    File text.
	 * @param string               $passphrase Passphrase or ''.
	 * @param array<string, mixed> $options    Normalised options.
	 * @return array<string, mixed>|WP_Error
	 */
	public function preview( string $content, string $passphrase, array $options ) {
		$read = $this->read( $content, $passphrase );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$envelope = $read['envelope'];
		$plans    = $this->plan_all( $envelope, $read['secrets'], $options + array( 'modules' => null ) );
		$modules  = array();
		$totals   = array(
			'changes' => 0,
			'same'    => 0,
			'items'   => 0,
			'modules' => 0,
		);
		$measures = array();
		foreach ( $plans as $id => $plan ) {
			$view               = $plan['view'];
			$view['has_work']   = $this->has_work( $plan );
			$view['selectable'] = $view['known'] && $view['writable'] && '' === $view['blocked'];
			$modules[]          = $view;
			$totals['changes'] += count( $view['changes'] ) + count( $view['secrets'] );
			$totals['same']    += (int) $view['same'];
			if ( $view['data'] ) {
				$totals['items'] += $view['data']['counts']['create'] + $view['data']['counts']['update'];
			}
			if ( $view['has_work'] ) {
				++$totals['modules'];
			}
			foreach ( $view['measures'] as $measure ) {
				$measures[] = array( 'module' => $id, 'module_name' => $view['name'] ) + $measure;
			}
		}
		usort( $modules, static fn( array $a, array $b ): int => array( ! $a['known'], $a['name'] ) <=> array( ! $b['known'], $b['name'] ) );

		return array(
			'file'          => array(
				'site'           => $envelope['site'],
				'exported_at'    => $envelope['exported_at'],
				'plugin_version' => $envelope['plugin_version'],
				'format_version' => $envelope['format_version'],
				'options'        => $envelope['options'],
			),
			'warnings'      => $read['warnings'],
			'secrets_state' => $read['secrets_state'],
			'sodium'        => Crypto::available(),
			'measures'      => $measures,
			'safety_rules'  => self::safety_rules(),
			'modules'       => $modules,
			'totals'        => $totals,
			'snapshot'      => ImportSnapshot::describe(),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Apply
	 * ------------------------------------------------------------------ */

	/**
	 * Apply the selected modules of the file.
	 *
	 * @param string               $content    File text.
	 * @param string               $passphrase Passphrase or ''.
	 * @param array<string, mixed> $options    Normalised options (modules = the selection).
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply( string $content, string $passphrase, array $options ) {
		$read = $this->read( $content, $passphrase );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		if ( ! is_array( $options['modules'] ) || ! $options['modules'] ) {
			return new WP_Error( 'uxstudio_transfer_nothing', __( 'Choose at least one module to import.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		// Planned again here, from the file: the preview is not trusted.
		$plans = $this->plan_all( $read['envelope'], $read['secrets'], $options );
		$todo  = array();
		foreach ( $plans as $id => $plan ) {
			if ( $plan['view']['known'] && $plan['view']['writable'] && '' === $plan['view']['blocked'] && $this->has_work( $plan ) ) {
				$todo[ $id ] = $plan;
			}
		}
		if ( ! $todo ) {
			return array(
				'results'  => $this->idle_results( $plans ),
				'snapshot' => ImportSnapshot::describe(),
				'summary'  => array(
					'ok'      => 0,
					'skipped' => count( $plans ),
					'failed'  => 0,
					'changes' => 0,
				),
				'login'    => $this->login_state(),
			);
		}

		ImportSnapshot::create( array_keys( $todo ) );
		$enabled_before = $this->modules->enabled_ids();
		$applier        = new Applier( $this->modules );
		$providers      = DataProviders::all( $this->modules );
		$results        = array();
		$summary        = array(
			'ok'      => 0,
			'skipped' => 0,
			'failed'  => 0,
			'changes' => 0,
		);

		foreach ( $todo as $id => $plan ) {
			$result = array(
				'id'       => $id,
				'name'     => $plan['view']['name'],
				'status'   => 'ok',
				'reason'   => '',
				'enabled'  => 'none',
				'written'  => 0,
				'skipped'  => array_column( $plan['view']['skipped'], 'message', 'key' ),
				'data'     => null,
				'warnings' => array(),
			);
			try {
				$instance = $this->modules->instance( $id );
				if ( ! $instance ) {
					throw new \RuntimeException( __( 'The module does not exist.', 'ux-studio' ) );
				}

				// 1) On / off, the way the Modules screen does it (overlap guard included).
				$write = $plan['write'];
				if ( 'enable' === $write['enable'] ) {
					$state = $applier->enable( $id, in_array( $id, $options['confirmed'], true ) );
					$result['enabled'] = $state['status'];
					if ( in_array( $state['status'], array( 'blocked', 'confirm' ), true ) ) {
						$result['warnings'][] = sprintf(
							/* translators: %s: plugin names. */
							__( 'Not switched on: %s overlaps with this module.', 'ux-studio' ),
							implode( ', ', $state['plugins'] )
						);
					}
				} elseif ( 'disable' === $write['enable'] ) {
					$this->modules->set_enabled( $id, false );
					$result['enabled'] = 'disabled';
				}
				$now_on = in_array( $id, $this->modules->enabled_ids(), true );

				// 2) Settings through the module's own save path.
				$values = $write['values'] + $write['secrets'];
				if ( $values && ( $now_on || ! $this->has_side_effects( $instance ) || in_array( $id, self::SAFE_WHEN_OFF, true ) ) ) {
					if ( null !== $write['login_pending'] ) {
						LoginUrlPending::start( (string) $write['login_pending'] ); // Before the setting exists: never a moment of enforcement.
					}
					$instance->save_settings( $values );
					$result['written'] = count( $write['values'] ) + count( $write['secrets'] );
					$summary['changes'] += $result['written'];
				} elseif ( $values ) {
					$result['warnings'][] = __( 'The module stayed off, so its settings were not written.', 'ux-studio' );
				}

				// 3) Content (only into a module that was already on when the request started).
				if ( null !== $write['data'] && isset( $providers[ $id ] ) ) {
					if ( in_array( $id, $enabled_before, true ) ) {
						$data = $providers[ $id ]->import( $write['data'] );
						ImportSnapshot::add_data( $id, $data['created'], $data['previous'] );
						$result['data'] = array(
							'label'     => $providers[ $id ]->label(),
							'created'   => count( $data['created'] ),
							'updated'   => count( $data['previous'] ),
							'unchanged' => $data['unchanged'],
							'failed'    => $data['failed'],
						);
						$summary['changes'] += count( $data['created'] ) + count( $data['previous'] );
						if ( $data['failed'] ) {
							$result['status'] = 'partial';
						}
					} else {
						$result['warnings'][] = __( 'The module was off when the import started, so its content was not imported. Import again now that it is on.', 'ux-studio' );
					}
				}
				if ( ! $result['written'] && ! $result['data'] && 'none' === $result['enabled'] ) {
					$result['status'] = 'skipped';
				}
			} catch ( \Throwable $e ) {
				$result['status'] = 'failed';
				$result['reason'] = $e->getMessage();
			}
			++$summary['failed' === $result['status'] ? 'failed' : ( 'skipped' === $result['status'] ? 'skipped' : 'ok' )];
			$results[] = $result;
		}

		foreach ( $plans as $id => $plan ) {
			if ( ! isset( $todo[ $id ] ) ) {
				$results[] = array(
					'id'       => $id,
					'name'     => $plan['view']['name'],
					'status'   => 'skipped',
					'reason'   => '' !== $plan['view']['blocked'] ? $plan['view']['blocked'] : __( 'Nothing to change.', 'ux-studio' ),
					'enabled'  => 'none',
					'written'  => 0,
					'skipped'  => array(),
					'data'     => null,
					'warnings' => array(),
				);
				++$summary['skipped'];
			}
		}

		// 7) Security: the importing administrator must still be able to log in.
		$login = $this->guard_login( $todo, $results );

		update_option( 'uxstudio_import_applied', time(), false );
		AutoStart::flush_hint_cache();
		Overlaps::flush();
		ActivityLog::log(
			'core',
			'settings_imported',
			'',
			0,
			array(
				'modules' => array_keys( $todo ),
				'counts'  => $summary,
				'mode'    => $options['mode'],
			)
		);

		return array(
			'results'  => $results,
			'summary'  => $summary,
			'snapshot' => ImportSnapshot::describe(),
			'login'    => $login,
		);
	}

	/**
	 * Result rows for an import where nothing could be applied.
	 *
	 * @param array<string, array{view:array<string,mixed>,write:array<string,mixed>}> $plans Plans.
	 * @return array<int, array<string, mixed>>
	 */
	private function idle_results( array $plans ): array {
		$out = array();
		foreach ( $plans as $id => $plan ) {
			$out[] = array(
				'id'       => $id,
				'name'     => $plan['view']['name'],
				'status'   => 'skipped',
				'reason'   => '' !== $plan['view']['blocked'] ? $plan['view']['blocked'] : __( 'Nothing to change.', 'ux-studio' ),
				'enabled'  => 'none',
				'written'  => 0,
				'skipped'  => array(),
				'data'     => null,
				'warnings' => array(),
			);
		}
		return $out;
	}

	/**
	 * State of the login address after an import.
	 *
	 * @return array{touched:bool,lock_active:bool,pending:bool,slug:string,url:string,reachable:bool,reverted:bool}
	 */
	private function login_state(): array {
		$security = $this->modules->instance( 'security-optimization' );
		$status   = $security instanceof SecurityModule ? $security->login_lock_status() : array(
			'active' => false,
			'slug'   => '',
			'url'    => '',
			'reason' => '',
		);
		return array(
			'touched'     => false,
			'lock_active' => ! empty( $status['active'] ),
			'pending'     => 'pending' === ( $status['reason'] ?? '' ),
			'slug'        => (string) ( $status['slug'] ?? '' ),
			'url'         => (string) ( $status['url'] ?? '' ),
			'reachable'   => true,
			'reverted'    => false,
		);
	}

	/**
	 * After the Security module was imported: if wp-login.php is now closed and the new login address does not
	 * show the login form, go back to the old Security settings at once.
	 *
	 * @param array<string, array{view:array<string,mixed>,write:array<string,mixed>}> $todo    Applied plans.
	 * @param array<int, array<string, mixed>>                                         $results Results (changed in place on revert).
	 * @return array{touched:bool,lock_active:bool,pending:bool,slug:string,url:string,reachable:bool,reverted:bool}
	 */
	private function guard_login( array $todo, array &$results ): array {
		$state = $this->login_state();
		if ( ! isset( $todo['security-optimization'] ) ) {
			return $state;
		}
		$state['touched'] = true;
		if ( ! $state['lock_active'] || '' === $state['url'] ) {
			return $state; // wp-login.php is open (lock off, held in the pending state, or failing open): nobody is locked out.
		}
		$probe = Applier::probe( $state['url'] );
		if ( $probe['ok'] ) {
			return $state;
		}
		$state['reachable'] = false;
		$snap               = ImportSnapshot::get();
		if ( $snap ) {
			// Only the Security module's own options: other modules of the same import keep their result (and so does the module list).
			$names = array_diff( ImportSnapshot::option_names( array( 'security-optimization' ) ), array( 'uxstudio_active_modules' ) );
			Snapshot::restore_options( array_intersect_key( (array) $snap['options'], array_flip( $names ) ) );
			$security = $this->modules->instance( 'security-optimization' );
			if ( $security ) {
				$security->after_migration_switch();
			}
			$state['reverted'] = true;
			foreach ( $results as $i => $row ) {
				if ( 'security-optimization' === $row['id'] ) {
					$results[ $i ]['status']     = 'failed';
					$results[ $i ]['reason']     = __( 'After the import the new login address did not show the login form, so the Security settings were put back to what they were.', 'ux-studio' );
					$results[ $i ]['warnings'][] = $results[ $i ]['reason'];
				}
			}
			$state = array_merge( $state, array_diff_key( $this->login_state(), array( 'touched' => 1, 'reachable' => 1, 'reverted' => 1 ) ) );
		}
		return $state;
	}

	/* ------------------------------------------------------------------ *
	 * Undo
	 * ------------------------------------------------------------------ */

	/**
	 * Put back the state before the last import.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function undo() {
		$snap = ImportSnapshot::get();
		if ( ! $snap ) {
			return new WP_Error( 'uxstudio_transfer_no_snapshot', __( 'There is no import to undo (the snapshot is kept for 30 days, and only for the last import).', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$before    = array_map( 'strval', (array) $snap['modules'] );
		$errors    = array();
		$providers = DataProviders::all( $this->modules );

		// Content first: the modules that hold it are still on.
		$data_result = array();
		foreach ( (array) ( $snap['data'] ?? array() ) as $module => $record ) {
			if ( isset( $providers[ $module ] ) ) {
				$data_result[ $module ] = $providers[ $module ]->undo( (array) ( $record['created'] ?? array() ), (array) ( $record['previous'] ?? array() ) );
				if ( $data_result[ $module ]['failed'] ) {
					$errors[] = $module . ': ' . __( 'some content could not be put back.', 'ux-studio' );
				}
			}
		}

		// Modules the import switched on go off (their clean-up runs); modules it switched off come back with the option.
		$off = array();
		foreach ( array_reverse( $this->modules->enabled_ids() ) as $module ) {
			if ( ! in_array( $module, $before, true ) ) {
				$this->modules->set_enabled( $module, false );
				$off[] = $module;
			}
		}
		$restored = Snapshot::restore_options( (array) $snap['options'] );
		foreach ( (array) ( $snap['touched'] ?? array() ) as $module ) {
			$instance = $this->modules->instance( (string) $module );
			if ( $instance ) {
				$instance->reload_settings(); // The instances still hold what the import had written.
			}
		}

		// Modules that stay on write their side effects again with the old settings (.htaccess, bans, cron).
		foreach ( (array) ( $snap['touched'] ?? array() ) as $module ) {
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

		ImportSnapshot::delete();
		delete_option( 'uxstudio_import_applied' );
		AutoStart::flush_hint_cache();
		Overlaps::flush();
		ActivityLog::log( 'core', 'settings_import_undone', '', 0, array( 'modules' => (array) ( $snap['touched'] ?? array() ), 'errors' => count( $errors ) ) );

		return array(
			'modules_off' => $off,
			'restored'    => $restored,
			'data'        => $data_result,
			'errors'      => $errors,
			'login'       => $this->login_state(),
		);
	}
}
