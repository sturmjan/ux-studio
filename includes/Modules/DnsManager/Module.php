<?php
/**
 * DNS Management module - clients edit their DNS records in wp-admin without
 * ever getting Cloudflare access. All work happens in the central app (PLAN 24).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DnsManager;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * This class only wires hooks, capabilities and settings. Validation, locks,
 * rate limit, the authoritative log and e-mails live in the central app; the
 * Cloudflare token never reaches this site.
 */
final class Module extends BaseModule {

	public const CAP_VIEW   = 'uxstudio_view_dns';
	public const CAP_MANAGE = 'uxstudio_manage_dns';

	/** user_meta: time the user acknowledged the risks (first entry modal). */
	public const META_ACK = 'uxstudio_dns_ack';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		LocalLog::install();
		add_filter( 'user_has_cap', array( $this, 'grant_caps' ), 10, 4 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		DashboardWidget::register();
	}

	/**
	 * Capabilities: viewing = any administrator; editing = administrators the
	 * site admin explicitly picked in the module settings (default: nobody).
	 *
	 * @param array    $allcaps All caps of the user.
	 * @param string[] $caps    Required primitive caps.
	 * @param array    $args    Requested cap args.
	 * @param \WP_User $user    The user.
	 * @return array
	 */
	public function grant_caps( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );
		$admin = ! empty( $allcaps['manage_options'] );

		$allcaps[ self::CAP_VIEW ] = $admin;
		$allowed                   = array_map( 'intval', (array) $this->settings->get( 'manage_user_ids', array() ) );
		$allcaps[ self::CAP_MANAGE ] = $admin && in_array( (int) $user->ID, $allowed, true );

		return $allcaps;
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings: which administrators may edit DNS.
	 */
	public function settings_schema(): array {
		$options = array();
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login', 'display_name' ) ) ) as $u ) {
			$options[ (string) $u->ID ] = $u->display_name . ' (' . $u->user_login . ')';
		}
		return array(
			array(
				'key'     => 'manage_user_ids',
				'type'    => 'multiselect',
				'label'   => __( 'Administrators who may edit DNS', 'ux-studio' ),
				'help'    => __( 'Only the selected administrators can add, change, delete and revert DNS records. Other administrators can only view. Nobody is selected by default.', 'ux-studio' ),
				'options' => $options,
				'default' => array(),
			),
		);
	}

}
