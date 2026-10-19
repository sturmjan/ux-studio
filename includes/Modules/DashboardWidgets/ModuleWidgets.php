<?php
/**
 * Dashboard Widgets - one place to switch every UX Studio dashboard widget,
 * including the ones other modules add.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Core\Settings;
use UxStudio\Modules\WooCommerce\DashboardWidget as WooWidget;

defined( 'ABSPATH' ) || exit;

/**
 * Other modules keep registering their own widget (PLAN.md 20.10 pattern);
 * this module only removes the ones switched off here, after they were added.
 * WooCommerce widgets are registered here directly - their toggles used to
 * live in the WooCommerce module, which existed only for these two widgets.
 *
 * Toggle keys match the legacy UX1 dashboard-widgets keys where one existed
 * (widget_recent_orders, widget_top_products, widget_service_requests,
 * widget_blocked_users), so migrated values apply as they are.
 */
final class ModuleWidgets {

	private Settings $settings;

	/**
	 * @param Settings $settings The dashboard-widgets module settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Widgets added by other modules: widget id => [settings key, default].
	 * Default true = shown as before this switch existed.
	 *
	 * @return array<string, array{0:string, 1:bool}>
	 */
	private static function foreign(): array {
		return array(
			'uxstudio_service_requests_widget'      => array( 'widget_service_requests', true ),
			'uxstudio_security_optimization_widget' => array( 'widget_blocked_users', true ),
			'uxstudio_forms_widget'                 => array( 'widget_forms', true ),
			'uxstudio_bot_throttle_widget'          => array( 'widget_bot_throttle', true ),
			'uxstudio_dns_manager_widget'           => array( 'widget_dns_manager', true ),
			'claude_panel_dashboard_widget'         => array( 'widget_ai_panel', true ),
		);
	}

	/**
	 * Settings schema rows (appended to the module's schema).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function schema(): array {
		$section = __( 'Widgets from other modules', 'ux-studio' );
		$rows    = array(
			array( 'widget_recent_orders', __( 'Dashboard widget: recent orders', 'ux-studio' ), __( 'The 10 most recent WooCommerce orders. Needs WooCommerce.', 'ux-studio' ), false ),
			array( 'widget_top_products', __( 'Dashboard widget: top products', 'ux-studio' ), __( 'Best-selling WooCommerce products by quantity sold. Needs WooCommerce.', 'ux-studio' ), false ),
			array( 'widget_service_requests', __( 'Dashboard widget: service requests', 'ux-studio' ), __( 'Latest service requests. Shown only while the Service requests module is on.', 'ux-studio' ), true ),
			array( 'widget_blocked_users', __( 'Dashboard widget: blocked IP addresses', 'ux-studio' ), __( 'Active IP bans with their expiry. Shown only while the Security module is on.', 'ux-studio' ), true ),
			array( 'widget_forms', __( 'Dashboard widget: form submissions', 'ux-studio' ), __( 'Unread submissions of the Form Builder. Shown only while the Form Builder module is on.', 'ux-studio' ), true ),
			array( 'widget_bot_throttle', __( 'Dashboard widget: bot throttle', 'ux-studio' ), __( 'Bot traffic and bans. Shown only while the Bot throttle module is on.', 'ux-studio' ), true ),
			array( 'widget_dns_manager', __( 'Dashboard widget: DNS changes', 'ux-studio' ), __( 'Recent DNS changes. Shown only while the DNS manager module is on.', 'ux-studio' ), true ),
			array( 'widget_ai_panel', __( 'Dashboard widget: AI Panel', 'ux-studio' ), __( 'Temporary remote access for an AI agent. Shown only while the AI Panel module is on.', 'ux-studio' ), true ),
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'key'     => $row[0],
				'section' => $section,
				'type'    => 'toggle',
				'label'   => $row[1],
				'help'    => $row[2],
				'default' => $row[3],
			);
		}
		return $out;
	}

	/**
	 * Widget ids controlled by this class (kept out of the "hide widgets" list).
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array_merge( array_keys( self::foreign() ), array( 'uxstudio_woocommerce_recent_orders', 'uxstudio_woocommerce_top_products' ) );
	}

	/**
	 * Hook the widgets.
	 */
	public function boot(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_woocommerce' ), 1000 );
		// After every module added its widget (default priority 10).
		add_action( 'wp_dashboard_setup', array( $this, 'remove_disabled' ), 1001 );
	}

	/**
	 * WooCommerce widgets (rendered by the WooCommerce module's widget class).
	 */
	public function add_woocommerce(): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'WooCommerce' ) || ! class_exists( WooWidget::class ) ) {
			return;
		}
		if ( (bool) $this->settings->get( 'widget_recent_orders', false ) ) {
			wp_add_dashboard_widget( 'uxstudio_woocommerce_recent_orders', __( 'WooCommerce - recent orders', 'ux-studio' ), array( WooWidget::class, 'render_recent_orders' ) );
		}
		if ( (bool) $this->settings->get( 'widget_top_products', false ) ) {
			wp_add_dashboard_widget( 'uxstudio_woocommerce_top_products', __( 'WooCommerce - top products', 'ux-studio' ), array( WooWidget::class, 'render_top_products' ) );
		}
	}

	/**
	 * Drop other modules' widgets that are switched off here.
	 */
	public function remove_disabled(): void {
		foreach ( self::foreign() as $id => $config ) {
			if ( ! (bool) $this->settings->get( $config[0], $config[1] ) ) {
				remove_meta_box( $id, 'dashboard', 'normal' );
				remove_meta_box( $id, 'dashboard', 'side' );
				remove_meta_box( $id, 'dashboard', 'column3' );
				remove_meta_box( $id, 'dashboard', 'column4' );
			}
		}
	}
}
