<?php
/**
 * Dashboard widgets: the list of the widgets UX Studio itself provides (its own and those of other modules), with the
 * switch that turns each one on, and the classification of every row of the unified table. Pure functions apart from
 * the translations, no WordPress needed.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * One row of the table = one dashboard widget, whoever provides it. The rows come from three places: the snapshot of
 * what the dashboard registers (WordPress, plugins, UX Studio), this catalog (UX Studio widgets, so that a widget
 * that is switched off is still listed and can be switched on) and the stored rules (a rule of an unknown widget).
 *
 * Types: wordpress | plugin (also themes and must-use code: a third party) | own (a widget of this module) |
 * module (a widget of another UX Studio module).
 */
final class WidgetCatalog {

	public const TYPE_WORDPRESS = 'wordpress';
	public const TYPE_PLUGIN    = 'plugin';
	public const TYPE_OWN       = 'own';
	public const TYPE_MODULE    = 'module';

	/** Hidden for everybody on a new installation, at once (these widgets exist on every site). */
	public const NEW_INSTALL_HIDDEN = array( 'uxstudio_notes', 'dashboard_site_health', 'dashboard_welcome', 'dashboard_primary' );

	/** Hidden on a new installation as soon as the widget first shows up (the plugin may be installed later). */
	public const NEW_INSTALL_HIDDEN_WHEN_SEEN = array( 'wpseo-wincher-dashboard-overview', 'wpseo-dashboard-overview' );

	/**
	 * Every widget of UX Studio with a switch or an owner. `key` is the setting that turns it on ('' = it follows its module),
	 * `new_install` the value a new installation starts with, `needs` a condition the site must meet (woocommerce |
	 * elementor_pro) and `config` a hint that the widget needs keys (ga | pagespeed).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function entries(): array {
		$own = static fn( string $key, string $title, string $desc, bool $new, string $needs = '', string $config = '' ): array => array(
			'kind'        => self::TYPE_OWN,
			'key'         => $key,
			'title'       => $title,
			'desc'        => $desc,
			'new_install' => $new,
			'module'      => '',
			'module_name' => '',
			'needs'       => $needs,
			'config'      => $config,
		);
		$mod = static fn( string $module, string $name, string $key, string $title, string $desc, bool $new, string $needs = '' ): array => array(
			'kind'        => self::TYPE_MODULE,
			'key'         => $key,
			'title'       => $title,
			'desc'        => $desc,
			'new_install' => $new,
			'module'      => $module,
			'module_name' => $name,
			'needs'       => $needs,
			'config'      => '',
		);
		return array(
			'uxstudio_disk_usage'                   => $own( 'widget_disk_usage', __( 'Disk usage', 'ux-studio' ), __( 'The size of the WordPress install, of the database and the free disk space.', 'ux-studio' ), false ),
			'uxstudio_recent_posts'                 => $own( 'widget_recent_posts', __( 'Recent posts', 'ux-studio' ), __( 'The 5 most recently published posts.', 'ux-studio' ), false ),
			'uxstudio_recent_media'                 => $own( 'widget_recent_media', __( 'Recent media', 'ux-studio' ), __( 'A gallery of the most recently uploaded images.', 'ux-studio' ), true ),
			'uxstudio_tasks'                        => $own( 'widget_tasks', __( 'Tasks', 'ux-studio' ), __( 'A to-do list: add, tick off and delete tasks.', 'ux-studio' ), true ),
			'uxstudio_notes'                        => $own( 'widget_notes', __( 'Notes', 'ux-studio' ), __( 'A notepad, saved while you type.', 'ux-studio' ), true ),
			'uxstudio_pagespeed'                    => $own( 'widget_pagespeed', __( 'PageSpeed Insights', 'ux-studio' ), __( 'The performance score of the home page from Google PageSpeed.', 'ux-studio' ), false, '', 'pagespeed' ),
			'uxstudio_user_activity'                => $own( 'widget_user_activity', __( 'Recent user activity', 'ux-studio' ), __( 'The last 15 actions from the activity log.', 'ux-studio' ), true ),
			'uxstudio_recent_forms'                 => $own( 'widget_recent_forms', __( 'Recent form submissions (Elementor)', 'ux-studio' ), __( 'The last 10 form submissions of Elementor Pro.', 'ux-studio' ), false, 'elementor_pro' ),
			'uxstudio_analytics'                    => $own( 'widget_analytics', __( 'Visits', 'ux-studio' ), __( 'Sessions today, this month and this year plus a 7-day chart from Google Analytics 4.', 'ux-studio' ), false, '', 'ga' ),
			'uxstudio_service_requests_widget'      => $mod( 'service-requests', __( 'Service Requests', 'ux-studio' ), 'widget_service_requests', __( 'Service requests', 'ux-studio' ), __( 'The latest service requests.', 'ux-studio' ), true ),
			'uxstudio_security_optimization_widget' => $mod( 'security-optimization', __( 'Security', 'ux-studio' ), 'widget_blocked_users', __( 'Security - active IP bans', 'ux-studio' ), __( 'Active IP bans with their expiry.', 'ux-studio' ), true ),
			'uxstudio_forms_widget'                 => $mod( 'forms', __( 'Form Builder', 'ux-studio' ), 'widget_forms', __( 'Form Builder - submissions', 'ux-studio' ), __( 'Unread submissions of the Form Builder.', 'ux-studio' ), true ),
			'uxstudio_bot_throttle_widget'          => $mod( 'bot-throttle', __( 'Bot Throttle', 'ux-studio' ), 'widget_bot_throttle', __( 'Bot Throttle - activity', 'ux-studio' ), __( 'Bot traffic and bans.', 'ux-studio' ), true ),
			'uxstudio_dns_manager_widget'           => $mod( 'dns-manager', __( 'DNS Management', 'ux-studio' ), 'widget_dns_manager', __( 'DNS - recent changes', 'ux-studio' ), __( 'Recent DNS changes.', 'ux-studio' ), true ),
			'claude_panel_dashboard_widget'         => $mod( 'ai-panel', __( 'AI Panel', 'ux-studio' ), 'widget_ai_panel', __( 'AI Panel: remote access', 'ux-studio' ), __( 'Temporary remote access for an AI agent.', 'ux-studio' ), true ),
			'uxstudio_woocommerce_recent_orders'    => $mod( '', 'WooCommerce', 'widget_recent_orders', __( 'WooCommerce - recent orders', 'ux-studio' ), __( 'The 10 most recent WooCommerce orders.', 'ux-studio' ), false, 'woocommerce' ),
			'uxstudio_woocommerce_top_products'     => $mod( '', 'WooCommerce', 'widget_top_products', __( 'WooCommerce - top products', 'ux-studio' ), __( 'Best-selling WooCommerce products by quantity sold.', 'ux-studio' ), false, 'woocommerce' ),
			// No switch in this module: the widget follows its module.
			'uxstudio_consent_widget'               => $mod( 'consent', __( 'Cookie Consent', 'ux-studio' ), '', __( 'Cookie banner - answers', 'ux-studio' ), __( 'How visitors answered the cookie banner in the last 30 days.', 'ux-studio' ), true ),
			'uxstudio_csp_widget'                   => $mod( 'security-optimization', __( 'Security', 'ux-studio' ), '', __( 'Security - what the website loads (CSP)', 'ux-studio' ), __( 'What the website loads from other servers.', 'ux-studio' ), true ),
			'uxstudio_php_version_widget'           => $mod( 'security-optimization', __( 'Security', 'ux-studio' ), '', __( 'Security - PHP version', 'ux-studio' ), __( 'Appears only when the PHP version of the site needs attention.', 'ux-studio' ), true ),
		);
	}

	/**
	 * Widgets WordPress itself always registers (listed before the first look at the dashboard, so a rule on them is not "not found").
	 *
	 * @return array<string, array{title:string,context:string}>
	 */
	public static function core_widgets(): array {
		return array(
			'dashboard_welcome'     => array(
				'title'   => __( 'Welcome', 'ux-studio' ),
				'context' => 'welcome',
			),
			'dashboard_site_health' => array(
				'title'   => __( 'Site Health Status', 'ux-studio' ),
				'context' => 'normal',
			),
			'dashboard_right_now'   => array(
				'title'   => __( 'At a Glance', 'ux-studio' ),
				'context' => 'normal',
			),
			'dashboard_activity'    => array(
				'title'   => __( 'Activity', 'ux-studio' ),
				'context' => 'normal',
			),
			'dashboard_quick_press' => array(
				'title'   => __( 'Quick Draft', 'ux-studio' ),
				'context' => 'side',
			),
			'dashboard_primary'     => array(
				'title'   => __( 'WordPress Events and News', 'ux-studio' ),
				'context' => 'side',
			),
		);
	}

	/**
	 * Setting keys of the switches that exist.
	 *
	 * @return string[]
	 */
	public static function toggle_keys(): array {
		$keys = array();
		foreach ( self::entries() as $entry ) {
			if ( '' !== $entry['key'] ) {
				$keys[] = (string) $entry['key'];
			}
		}
		return $keys;
	}

	/**
	 * Only the given toggles that exist, as booleans.
	 *
	 * @param mixed $raw Raw map key => bool.
	 * @return array<string, bool>
	 */
	public static function clean_toggles( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$valid = self::toggle_keys();
		foreach ( $raw as $key => $value ) {
			if ( in_array( (string) $key, $valid, true ) ) {
				$out[ (string) $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			}
		}
		return $out;
	}

	/**
	 * What a new installation starts with: every switch and the rules. The widgets of third parties that may come later
	 * are not in the rules but in `pending` (see pending_hits()).
	 *
	 * @return array{toggles:array<string,bool>,rules:array<string,array{hidden:bool,roles:string[]}>,pending:string[]}
	 */
	public static function new_install(): array {
		$toggles = array();
		foreach ( self::entries() as $entry ) {
			if ( '' !== $entry['key'] ) {
				$toggles[ (string) $entry['key'] ] = (bool) $entry['new_install'];
			}
		}
		$rules = array();
		foreach ( self::NEW_INSTALL_HIDDEN as $id ) {
			$rules[ $id ] = array(
				'hidden' => true,
				'roles'  => array(),
			);
		}
		return array(
			'toggles' => $toggles,
			'rules'   => $rules,
			'pending' => self::NEW_INSTALL_HIDDEN_WHEN_SEEN,
		);
	}

	/**
	 * Which of the pending default-hidden widgets are on the dashboard now, and the rules after hiding them. A widget that
	 * already has a rule (the owner decided) is only taken off the pending list.
	 *
	 * @param string[]                                         $pending Pending ids.
	 * @param string[]                                         $present Ids of the widgets on the dashboard now.
	 * @param array<string, array{hidden:bool,roles:string[]}> $rules   Current rules.
	 * @return array{rules:array<string,array{hidden:bool,roles:string[]}>,pending:string[],changed:bool}
	 */
	public static function pending_hits( array $pending, array $present, array $rules ): array {
		$hit = array_values( array_intersect( $pending, $present ) );
		foreach ( $hit as $id ) {
			if ( ! isset( $rules[ $id ] ) ) {
				$rules[ $id ] = array(
					'hidden' => true,
					'roles'  => array(),
				);
			}
		}
		return array(
			'rules'   => $rules,
			'pending' => array_values( array_diff( $pending, $hit ) ),
			'changed' => (bool) $hit,
		);
	}

	/**
	 * Split a stored source (`core`, `uxstudio`, `plugin:<folder>`, `theme:<folder>`, `mu`) into kind and folder.
	 *
	 * @param string $source Stored source.
	 * @return array{0:string,1:string}
	 */
	public static function split_source( string $source ): array {
		$parts = explode( ':', $source, 2 );
		return array( $parts[0], $parts[1] ?? '' );
	}

	/**
	 * Everything the table shows about one row besides what the snapshot knows: type, owner, switch and state.
	 *
	 * State: on | off (the switch is off) | module_off (the module that owns the widget is off) | unavailable (the site
	 * lacks WooCommerce / Elementor Pro).
	 *
	 * @param array<string, mixed>  $row          Base row (id, title, source, source_name ...).
	 * @param array<string, mixed>  $values       Stored settings of the module.
	 * @param array<string, string> $module_names Module id => name of every module that is on.
	 * @param array<string, mixed>  $env          woocommerce, elementor_pro, ga, pagespeed (bool).
	 * @return array<string, mixed> The row with type, type_name, toggle, module, state, description, note.
	 */
	public static function decorate( array $row, array $values, array $module_names, array $env ): array {
		$id      = (string) $row['id'];
		$entries = self::entries();
		$entry   = $entries[ $id ] ?? null;

		$row += array(
			'type'        => self::TYPE_PLUGIN,
			'type_name'   => '',
			'toggle'      => null,
			'module'      => null,
			'state'       => 'on',
			'description' => '',
			'note'        => '',
			'note_tab'    => '',
		);

		if ( null === $entry ) {
			list( $kind, $folder ) = self::split_source( (string) ( $row['source'] ?? 'unknown' ) );
			$name                  = '' !== (string) ( $row['source_name'] ?? '' ) ? (string) $row['source_name'] : $folder;
			switch ( $kind ) {
				case 'core':
					$row['type'] = self::TYPE_WORDPRESS;
					break;
				case 'uxstudio':
					$row['type']      = self::TYPE_MODULE;
					$row['type_name'] = 'UX Studio';
					break;
				case 'theme':
				case 'plugin':
					$row['type']      = self::TYPE_PLUGIN;
					$row['type_name'] = $name;
					break;
				default:
					$row['type']      = self::TYPE_PLUGIN;
					$row['type_name'] = '';
			}
			return $row;
		}

		$row['type']        = (string) $entry['kind'];
		$row['description'] = (string) $entry['desc'];
		$row['source']      = 'uxstudio';
		if ( '' === (string) ( $row['title'] ?? '' ) || $id === (string) $row['title'] ) {
			$row['title'] = (string) $entry['title'];
		}
		$state = 'on';
		if ( self::TYPE_MODULE === $entry['kind'] ) {
			$module_id        = (string) $entry['module'];
			$active           = '' === $module_id || isset( $module_names[ $module_id ] );
			$name             = '' !== $module_id && isset( $module_names[ $module_id ] ) ? (string) $module_names[ $module_id ] : (string) $entry['module_name'];
			$row['type_name'] = $name;
			$row['module']    = array(
				'id'     => $module_id,
				'name'   => $name,
				'active' => $active,
			);
			if ( ! $active ) {
				$state = 'module_off';
			}
		}
		if ( '' !== $entry['key'] ) {
			$enabled       = (bool) ( $values[ $entry['key'] ] ?? false );
			$row['toggle'] = array(
				'key'     => (string) $entry['key'],
				'enabled' => $enabled,
			);
			if ( 'on' === $state && ! $enabled ) {
				$state = 'off';
			}
		}
		if ( 'on' === $state && 'woocommerce' === $entry['needs'] && empty( $env['woocommerce'] ) ) {
			$state       = 'unavailable';
			$row['note'] = __( 'Needs WooCommerce, which is not active on this site.', 'ux-studio' );
		}
		if ( 'on' === $state && 'elementor_pro' === $entry['needs'] && empty( $env['elementor_pro'] ) ) {
			$state       = 'unavailable';
			$row['note'] = __( 'Needs Elementor Pro, which is not active on this site.', 'ux-studio' );
		}
		if ( 'ga' === $entry['config'] && empty( $env['ga'] ) ) {
			$row['note']     = __( 'Needs the GA4 property ID and the service-account JSON.', 'ux-studio' );
			$row['note_tab'] = 'keys';
		}
		if ( 'pagespeed' === $entry['config'] && empty( $env['pagespeed'] ) ) {
			$row['note']     = __( 'Needs the Google PageSpeed API key.', 'ux-studio' );
			$row['note_tab'] = 'keys';
		}
		$row['state'] = $state;
		return $row;
	}
}
