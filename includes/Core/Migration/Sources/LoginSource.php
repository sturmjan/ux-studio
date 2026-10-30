<?php
/**
 * Source: Limit Login Attempts Reloaded, WPS Hide Login, Loginizer.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\DB;
use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Activatable;
use UxStudio\Core\Migration\IpEntries;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\SettingsWriter;
use UxStudio\Core\Migration\Staging;
use UxStudio\Modules\SecurityOptimization\IpBanStore;
use UxStudio\Modules\SecurityOptimization\Module as SecurityModule;

defined( 'ABSPATH' ) || exit;

/**
 * Target: Security Optimization login keys (`limit_login_attempts`, `max_attempts`,
 * `lockout_time` in minutes, `notify_admin`, `lockout_notify_email`, `lockout_progressive`,
 * `lockout_multiplier`, `lockout_max_hours`, `login_url_enabled`, `login_url`, `disabled_behavior`,
 * `url_redirect`) and the Firewall (PLAN.md 27, A1):
 *
 *  - the ALLOW list goes into the setting `ip_firewall_allowlist` (merged with what is there, no duplicates);
 *  - the BLOCK list becomes IP bans with origin `migration` (data phase). With the module on they are created
 *    INACTIVE and switched on at the switch (Activatable), like other behaviour-changing data (PLAN 26.8). The
 *    Firewall setting `ip_firewall_enabled` is turned on with them, otherwise the bans would do nothing.
 *
 * Entries are `1.2.3.4`, CIDR, `start-end` ranges (cut into CIDR blocks) and wildcards; see IpEntries.
 */
final class LoginSource extends AbstractSource implements Activatable {

	private const MODULE = 'security-optimization';

	/** @var array{allow:string[],block:string[],skipped:string[]}|null */
	private ?array $lists = null;

	protected function option_names(): array {
		switch ( $this->slug ) {
			case 'wps-hide-login':
				return array( 'whl_page', 'whl_redirect_admin' );
			case 'loginizer':
				return array( 'loginizer_options', 'loginizer_blacklist', 'loginizer_whitelist' );
			default:
				return array( 'limit_login_allowed_retries', 'limit_login_lockout_duration', 'limit_login_allowed_lockouts', 'limit_login_lockout_notify', 'limit_login_whitelist', 'limit_login_blacklist' );
		}
	}

	public function forget(): void {
		$this->lists = null;
		parent::forget();
	}

	public function smoke_checks(): array {
		if ( 'wps-hide-login' !== $this->slug ) {
			return array();
		}
		$settings = (array) ( $this->mapping()->settings[ self::MODULE ] ?? array() );
		$slug     = trim( (string) ( $settings['login_url'] ?? '' ), '/' );
		if ( empty( $settings['login_url_enabled'] ) || '' === $slug ) {
			return array();
		}
		return array(
			array(
				'id'       => 'login',
				'label'    => __( 'New login address works, wp-login.php is closed', 'ux-studio' ),
				'type'     => 'login_slug',
				'slug'     => $slug,
				'behavior' => (string) ( $settings['disabled_behavior'] ?? '404' ),
				'redirect' => (string) ( $settings['url_redirect'] ?? '' ),
				'replaces' => array( 'wp-login' ),
			),
		);
	}

	protected function map( bool $active ): Mapping {
		switch ( $this->slug ) {
			case 'wps-hide-login':
				return $this->map_wps( $active );
			case 'loginizer':
				return $this->map_loginizer( $active );
			default:
				return $this->map_llar( $active );
		}
	}

	/* ═══════════════════════════════════════════════════
	   Settings
	   ═══════════════════════════════════════════════════ */

	/**
	 * Limit Login Attempts Reloaded: `limit_login_*` options (durations in seconds).
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_llar( bool $active ): Mapping {
		$m = new Mapping();
		if ( ! $active && ! $this->has_data() ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$retries  = $this->opt( 'limit_login_allowed_retries' );
		$duration = $this->opt( 'limit_login_lockout_duration' );
		$notify   = $this->opt( 'limit_login_lockout_notify' );

		$m->set( self::MODULE, 'limit_login_attempts', true, __( 'Limit login attempts', 'ux-studio' ), 'limit_login_allowed_retries' );
		$m->set( self::MODULE, 'max_attempts', (int) ( $retries ?? 4 ), __( 'Max attempts', 'ux-studio' ), 'limit_login_allowed_retries' );
		$m->set( self::MODULE, 'lockout_time', max( 1, (int) round( (int) ( $duration ?? 1200 ) / 60 ) ), __( 'Lockout time (minutes)', 'ux-studio' ), 'limit_login_lockout_duration' );
		$m->set( self::MODULE, 'notify_admin', in_array( 'email', explode( ',', (string) ( $notify ?? 'email' ) ), true ), __( 'E-mail admin on lockout', 'ux-studio' ), 'limit_login_lockout_notify' );

		$this->map_notify_email( $m, (string) $this->opt( 'admin_notify_email' ), 'admin_notify_email' );
		$this->map_progressive(
			$m,
			(int) ( $this->opt( 'limit_login_allowed_lockouts' ) ?? 4 ),
			(int) ( $duration ?? 1200 ),
			(int) ( $this->opt( 'limit_login_long_duration' ) ?? 86400 ),
			'limit_login_allowed_lockouts'
		);
		$this->map_lists( $m, 'limit_login_whitelist', 'limit_login_blacklist' );

		if ( ! empty( $this->opt( 'limit_login_whitelist_usernames' ) ) || ! empty( $this->opt( 'limit_login_blacklist_usernames' ) ) ) {
			$m->lossy( __( 'The lists of allowed and blocked user names are not transferred.', 'ux-studio' ) );
		}
		if ( 'local' !== (string) ( $this->opt( 'limit_login_active_app' ) ?? 'local' ) ) {
			$m->lossy( __( 'The cloud protection of the plugin has no equivalent in the module.', 'ux-studio' ) );
		}
		return $m;
	}

	/**
	 * Loginizer: `loginizer_options` { max_retries, lockout_time (s), max_lockouts, lockouts_extend (s),
	 * notify_email (lockouts before a mail, 0 = never), notify_email_address }; `loginizer_blacklist` /
	 * `loginizer_whitelist` { id => { start, end, time } }.
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_loginizer( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'loginizer_options' );
		if ( ! is_array( $o ) && ! $active && ! $this->has_data() ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$o = is_array( $o ) ? $o : array();

		$lockout_s = (int) ( empty( $o['lockout_time'] ) ? 900 : $o['lockout_time'] );
		$m->set( self::MODULE, 'limit_login_attempts', empty( $this->opt( 'loginizer_disable_brute' ) ), __( 'Limit login attempts', 'ux-studio' ), 'loginizer_disable_brute' );
		$m->set( self::MODULE, 'max_attempts', (int) ( empty( $o['max_retries'] ) ? 3 : $o['max_retries'] ), __( 'Max attempts', 'ux-studio' ), 'loginizer_options:max_retries' );
		$m->set( self::MODULE, 'lockout_time', max( 1, (int) round( $lockout_s / 60 ) ), __( 'Lockout time (minutes)', 'ux-studio' ), 'loginizer_options:lockout_time' );
		$m->set( self::MODULE, 'notify_admin', ! empty( $o['notify_email'] ), __( 'E-mail admin on lockout', 'ux-studio' ), 'loginizer_options:notify_email' );

		$this->map_notify_email( $m, (string) ( $o['notify_email_address'] ?? '' ), 'loginizer_options:notify_email_address' );
		$this->map_progressive(
			$m,
			(int) ( empty( $o['max_lockouts'] ) ? 5 : $o['max_lockouts'] ),
			$lockout_s,
			(int) ( empty( $o['lockouts_extend'] ) ? 86400 : $o['lockouts_extend'] ),
			'loginizer_options:max_lockouts'
		);
		$this->map_lists( $m, 'loginizer_whitelist', 'loginizer_blacklist' );

		$m->lossy( __( 'Loginizer CAPTCHA, passwordless login and social login are not transferred. Two-step verification cannot be moved (the secrets stay with the plugin): users set it up again in their profile once it is turned on in the module.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * The e-mail address for lockout notices, when the plugin has its own.
	 *
	 * @param Mapping $m      Mapping.
	 * @param string  $email  Address from the plugin.
	 * @param string  $origin Origin label.
	 */
	private function map_notify_email( Mapping $m, string $email, string $origin ): void {
		$email = trim( $email );
		if ( '' === $email ) {
			return;
		}
		if ( function_exists( 'is_email' ) ? ! is_email( $email ) : false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$m->lossy( __( 'The e-mail address for lockout notices in the plugin is not valid, so the module e-mails the site administrator.', 'ux-studio' ) );
			return;
		}
		$m->set( self::MODULE, 'lockout_notify_email', $email, __( 'Lockout e-mail address', 'ux-studio' ), $origin );
	}

	/**
	 * The plugin lengthens the lockout to a long one after N lockouts; the module multiplies every repeated lockout of an IP
	 * within 24 hours. The factor is chosen so that N lockouts reach about the long time, and the long time is the maximum.
	 *
	 * @param Mapping $m             Mapping.
	 * @param int     $lockouts      Lockouts after which the long lockout starts.
	 * @param int     $base_seconds  Normal lockout.
	 * @param int     $long_seconds  Long lockout.
	 * @param string  $origin        Origin label.
	 */
	private function map_progressive( Mapping $m, int $lockouts, int $base_seconds, int $long_seconds, string $origin ): void {
		if ( $lockouts < 1 || $base_seconds < 1 || $long_seconds <= $base_seconds ) {
			return;
		}
		$factor = round( min( 10.0, max( 1.0, ( $long_seconds / $base_seconds ) ** ( 1 / $lockouts ) ) ), 1 );
		$hours  = max( 1, min( 168, (int) ceil( $long_seconds / 3600 ) ) );
		$m->set( self::MODULE, 'lockout_progressive', true, __( 'Lengthen repeated lockouts', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'lockout_multiplier', $factor, __( 'Lockout multiplier', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'lockout_max_hours', $hours, __( 'Longest lockout (hours)', 'ux-studio' ), $origin );
		$m->note(
			sprintf(
				/* translators: 1: number of lockouts, 2: hours, 3: factor. */
				__( 'Repeated lockouts: the plugin switches to a long lockout of %2$d h after %1$d lockouts. The module multiplies the lockout with every repeated lockout of the same IP within 24 hours (factor %3$s, longest %2$d h), so the steps differ a little.', 'ux-studio' ),
				$lockouts,
				$hours,
				(string) $factor
			)
		);
	}

	/**
	 * Allow list -> setting, block list -> data (bans), unusable entries -> a lossy line.
	 *
	 * @param Mapping $m            Mapping.
	 * @param string  $allow_option Option with the allow list.
	 * @param string  $block_option Option with the block list.
	 */
	private function map_lists( Mapping $m, string $allow_option, string $block_option ): void {
		$lists = $this->lists();
		if ( $lists['allow'] ) {
			$m->set(
				self::MODULE,
				'ip_firewall_allowlist',
				IpEntries::merge_lines( $this->current_allowlist(), $lists['allow'] ),
				__( 'Allowlist (never block)', 'ux-studio' ),
				$allow_option
			);
		}
		if ( $lists['block'] ) {
			$m->set( self::MODULE, 'ip_firewall_enabled', true, __( 'Enable IP firewall', 'ux-studio' ), $block_option );
			$m->warn(
				sprintf(
					/* translators: %d: number of addresses. */
					_n( '%d blocked IP address or range goes to the Firewall of Security Optimization, and the Firewall is turned on so it takes effect. It starts to block once the module is turned on.', '%d blocked IP addresses or ranges go to the Firewall of Security Optimization, and the Firewall is turned on so they take effect. They start to block once the module is turned on.', count( $lists['block'] ), 'ux-studio' ),
					count( $lists['block'] )
				)
			);
		}
		if ( $lists['skipped'] ) {
			$shown = array_slice( $lists['skipped'], 0, 5 );
			$m->lossy(
				sprintf(
					/* translators: 1: number of entries, 2: first entries. */
					__( '%1$d IP list entries could not be transferred (not a valid address or range, or too wide): %2$s.', 'ux-studio' ),
					count( $lists['skipped'] ),
					implode( ', ', $shown ) . ( count( $lists['skipped'] ) > count( $shown ) ? ', ...' : '' )
				)
			);
		}
	}

	/**
	 * Allowlist as it will be: the stored one with what earlier runs prepared on top.
	 */
	private function current_allowlist(): string {
		$live   = (array) get_option( SettingsWriter::option_name( self::MODULE ), array() );
		$staged = Staging::pending_settings( self::MODULE );
		return (string) ( $staged['ip_firewall_allowlist'] ?? $live['ip_firewall_allowlist'] ?? '' );
	}

	/**
	 * The plugin's IP lists in the form the Firewall takes.
	 *
	 * @return array{allow:string[],block:string[],skipped:string[]}
	 */
	private function lists(): array {
		if ( null !== $this->lists ) {
			return $this->lists;
		}
		$out = array(
			'allow'   => array(),
			'block'   => array(),
			'skipped' => array(),
		);
		if ( 'wps-hide-login' !== $this->slug ) {
			$names = 'loginizer' === $this->slug ? array( 'allow' => 'loginizer_whitelist', 'block' => 'loginizer_blacklist' ) : array( 'allow' => 'limit_login_whitelist', 'block' => 'limit_login_blacklist' );
			foreach ( $names as $kind => $option ) {
				$raw = $this->opt( $option );
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$res            = IpEntries::convert_all( array_values( $raw ) );
				$out[ $kind ]   = $res['entries'];
				$out['skipped'] = array_merge( $out['skipped'], $res['skipped'] );
			}
		}
		$this->lists = $out;
		return $out;
	}

	/**
	 * WPS Hide Login: `whl_page` (slug, default `login`) and `whl_redirect_admin`
	 * (where a visitor of the old URL goes: `404` or a slug).
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_wps( bool $active ): Mapping {
		$m    = new Mapping();
		$slug = trim( (string) ( $this->opt( 'whl_page' ) ?? '' ), '/' );
		if ( '' === $slug ) {
			if ( ! $active ) {
				$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
				return $m;
			}
			$slug = 'login'; // The plugin's default.
		}
		$redirect = trim( (string) ( $this->opt( 'whl_redirect_admin' ) ?? '' ), '/' );
		if ( '' === $redirect ) {
			$redirect = '404';
		}

		$m->set( self::MODULE, 'login_url_enabled', true, __( 'Enable custom login URL', 'ux-studio' ), 'whl_page' );
		$m->set( self::MODULE, 'login_url', $slug, __( 'Custom login slug', 'ux-studio' ), 'whl_page' );
		if ( false !== strpos( $redirect, '404' ) ) {
			$m->set( self::MODULE, 'disabled_behavior', '404', __( 'Direct wp-login.php behavior', 'ux-studio' ), 'whl_redirect_admin' );
		} else {
			$m->set( self::MODULE, 'disabled_behavior', 'url_redirect', __( 'Direct wp-login.php behavior', 'ux-studio' ), 'whl_redirect_admin' );
			$m->set( self::MODULE, 'url_redirect', $redirect, __( 'Redirect path', 'ux-studio' ), 'whl_redirect_admin' );
		}

		$base = function_exists( 'home_url' ) ? trailingslashit( home_url() ) : '/';
		$m->warn(
			sprintf(
				/* translators: %s: new login address. */
				__( 'Once the module is turned on, the login address changes for everyone to %s. Save the new address before you switch over, the old one stops working.', 'ux-studio' ),
				$base . $slug . '/'
			)
		);
		return $m;
	}

	/* ═══════════════════════════════════════════════════
	   Data: blocked IP addresses -> Firewall bans
	   ═══════════════════════════════════════════════════ */

	protected function data_kind(): string {
		return 'ip_ban';
	}

	protected function data_label(): string {
		return __( 'Blocked IP addresses', 'ux-studio' );
	}

	protected function data_total( array $options ): int {
		return count( $this->lists()['block'] );
	}

	protected function data_plan( array $options ): array {
		$count = count( $this->lists()['block'] );
		return $count > 0 ? array(
			array(
				'key'   => 'ip_ban',
				'label' => $this->data_label(),
				'count' => $count,
			),
		) : array();
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		$all    = $this->lists()['block'];
		$offset = max( 0, (int) $position );
		$slice  = array_slice( $all, $offset, $limit );
		$items  = array();
		foreach ( $slice as $entry ) {
			$items[] = array(
				'key'     => 'ip:' . $entry,
				'label'   => $entry,
				'value'   => $entry,
				'type'    => IpEntries::type_of( $entry ),
				'enabled' => true,
			);
		}
		$next = $offset + count( $slice );
		return array(
			'items' => $items,
			'next'  => $next,
			'done'  => $next >= count( $all ),
		);
	}

	public function target_tables(): array {
		return 'wps-hide-login' === $this->slug ? array() : array( 'uxstudio_ip_bans', 'uxstudio_ip_ban_ranges' );
	}

	protected function extra_target_options(): array {
		return 'wps-hide-login' === $this->slug ? array() : array( IpBanStore::ACTIVE_OPTION );
	}

	protected function prepare_data(): void {
		DB::ensure_module_tables( self::MODULE, 1, array( SecurityModule::class, 'create_tables' ) );
	}

	/**
	 * Create a ban (origin `migration`); with the module on it waits, switched off, for the switch.
	 *
	 * @param array<string, mixed> $item Normalized entry.
	 * @param array<string, mixed> $ctx  Run context.
	 */
	protected function apply_item( array $item, array $ctx ): array {
		$store = new IpBanStore();
		if ( null !== $store->find_by_value( (string) $item['value'] ) ) {
			return array( 'status' => 'unchanged' );
		}
		$staged = Staging::module_enabled( self::MODULE );
		$id     = $store->save_ban(
			array(
				'type'       => $item['type'],
				'value'      => $item['value'],
				'label'      => sprintf(
					/* translators: %s: plugin name. */
					__( 'Migrated from %s', 'ux-studio' ),
					$this->label()
				),
				'origin'     => 'migration',
				'is_active'  => ! $staged,
				'created_by' => get_current_user_id(),
			)
		);
		if ( is_wp_error( $id ) ) {
			return array(
				'status'  => 'skipped',
				'message' => $id->get_error_message(),
			);
		}
		$out = array(
			'status'   => 'created',
			'map_type' => 'ip_ban',
			'map_id'   => (string) $id,
		);
		if ( $staged ) {
			$out['activate'] = (string) $id;
		}
		return $out;
	}

	protected function preview_item( array $item, array $ctx ): array {
		return array( 'status' => null !== ( new IpBanStore() )->find_by_value( (string) $item['value'] ) ? 'unchanged' : 'created' );
	}

	protected function verify_item( array $item ): array {
		$found = ( new IpBanStore() )->find_by_value( (string) $item['value'] );
		return array(
			'ok'     => null !== $found,
			'detail' => null !== $found ? '' : __( 'The address is not in the Firewall.', 'ux-studio' ),
		);
	}

	public function activate( string $kind, array $ids, array $data = array() ): int {
		return $this->switch_bans( $kind, $ids, true );
	}

	public function deactivate( string $kind, array $ids, array $data = array() ): int {
		return $this->switch_bans( $kind, $ids, false );
	}

	/**
	 * @param string                 $kind Kind registered by this source.
	 * @param array<int, int|string> $ids  Ban ids.
	 * @param bool                   $on   Switch on or off.
	 */
	private function switch_bans( string $kind, array $ids, bool $on ): int {
		if ( 'ip_ban' !== $kind ) {
			return 0;
		}
		$store = new IpBanStore();
		$count = 0;
		foreach ( array_values( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			if ( null === $store->get_ban( $id ) ) {
				continue;
			}
			$store->set_active( $id, $on );
			++$count;
		}
		return $count;
	}
}
