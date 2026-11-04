<?php
/**
 * Links to UX Studio modules inside the WordPress admin menu, and the "Content" hub.
 *
 * Two independent switches of the Admin Customiser (both off until the setup wizard or the user turns them on):
 *
 *  - `module_links_enabled`: for every module that is switched on, one sub-item in the native menu that opens the
 *    module page of UX Studio (Tools, Settings, Media, Users, Appearance and the Content hub). The main "UX Studio"
 *    menu item is not touched.
 *  - `content_hub_enabled`: Posts, Pages and the site's own content types move out of the top level into ONE
 *    top-level "Content" item; the content modules (Top Bar, Popups, Forms...) join them there. Without the hub
 *    the content links go under Posts.
 *
 * A link can carry a small count bubble (unread form entries, open vulnerabilities, failed e-mails of the last
 * week). The counts are cached for a few minutes, so the menu never costs a query on every admin screen.
 *
 * Runs before the Menu Organizer (priority 99999), which then sees the hub as one more top-level item and files it
 * under its Content category (see MenuOrganizer::auto_categorize()).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ModuleLinks {

	/** Slug of the top-level hub. */
	public const HUB = 'uxs-content';

	/** Placement used for the content links while the hub is off. */
	private const HUB_FALLBACK = 'edit.php';

	/** How long a count is reused (seconds). */
	private const BADGE_TTL = 300;

	private Settings $settings;

	/** @var array<string, string> Slugs the hub took over (slug => top-level slug it belongs to). */
	private array $moved = array();

	/** Original parent file of the screen being drawn, while the hub is active. */
	private string $origin = '';

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook everything. Both switches are read when the menu is built, so turning them on needs no reload of hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'build' ), 9999 );
		add_filter( 'parent_file', array( $this, 'highlight_parent' ), 9999 );
		add_filter( 'submenu_file', array( $this, 'highlight_sub' ), 9999 );
		add_action( 'admin_head', array( $this, 'badge_styles' ) );
	}

	/**
	 * The catalog, filterable so a site or an extension can add or drop a link.
	 *
	 * @return array<int, array<int, string>> Rows of [ module id, label, parent menu slug (or HUB), badge key ].
	 */
	public static function catalog(): array {
		$links = array(
			array( 'top-bar', __( 'Top Bar', 'ux-studio' ), self::HUB, '' ),
			array( 'popup-manager', __( 'Popups', 'ux-studio' ), self::HUB, '' ),
			array( 'exit-popup', __( 'Exit Popup', 'ux-studio' ), self::HUB, '' ),
			array( 'forms', __( 'Forms', 'ux-studio' ), self::HUB, 'forms' ),
			array( 'opening-hours', __( 'Opening Hours', 'ux-studio' ), self::HUB, '' ),
			array( 'maps', __( 'Maps', 'ux-studio' ), self::HUB, '' ),
			array( 'notice-board', __( 'Notice Board', 'ux-studio' ), self::HUB, '' ),
			array( 'review-aggregator', __( 'Reviews', 'ux-studio' ), self::HUB, '' ),
			array( 'redirects', __( 'Redirects and 404', 'ux-studio' ), self::HUB, '' ),
			array( 'folder-manager', __( 'Folders', 'ux-studio' ), self::HUB, '' ),

			array( 'image-optimizer', __( 'Image Optimizer', 'ux-studio' ), 'upload.php', '' ),
			array( 'media-trash', __( 'Media Trash', 'ux-studio' ), 'upload.php', '' ),
			array( 'stock-photos', __( 'Stock Photos', 'ux-studio' ), 'upload.php', '' ),

			array( 'export-posts', __( 'Export Posts', 'ux-studio' ), 'tools.php', '' ),
			array( 'export-users', __( 'Export Users', 'ux-studio' ), 'tools.php', '' ),
			array( 'content-sync', __( 'Content Sync', 'ux-studio' ), 'tools.php', '' ),
			array( 'code-snippets', __( 'Code Snippets', 'ux-studio' ), 'tools.php', '' ),
			array( 'cron-control', __( 'Cron Control', 'ux-studio' ), 'tools.php', '' ),
			array( 'rollback-manager', __( 'Rollback Manager', 'ux-studio' ), 'tools.php', '' ),
			array( 'elementor-import', __( 'Elementor Import', 'ux-studio' ), 'tools.php', '' ),
			array( 'activity-log', __( 'Activity Log', 'ux-studio' ), 'tools.php', '' ),
			array( 'email-log', __( 'E-mail Log', 'ux-studio' ), 'tools.php', 'email-log' ),
			array( 'dns-manager', __( 'DNS Management', 'ux-studio' ), 'tools.php', '' ),
			array( 'vulnerability-scanner', __( 'Vulnerability Scanner', 'ux-studio' ), 'tools.php', 'vulnerabilities' ),
			array( 'page-load', __( 'Page Load', 'ux-studio' ), 'tools.php', '' ),

			array( 'smtp-email', __( 'SMTP E-mail', 'ux-studio' ), 'options-general.php', '' ),
			array( 'email-health', __( 'E-mail Health', 'ux-studio' ), 'options-general.php', '' ),
			array( 'maintenance-mode', __( 'Maintenance Mode', 'ux-studio' ), 'options-general.php', '' ),
			array( 'security-optimization', __( 'Security', 'ux-studio' ), 'options-general.php', '' ),
			array( 'third-party-login', __( 'Third-Party Login', 'ux-studio' ), 'options-general.php', '' ),
			array( 'bot-throttle', __( 'Bot Throttle', 'ux-studio' ), 'options-general.php', '' ),
			array( 'disable-auto-updates', __( 'Auto Updates', 'ux-studio' ), 'options-general.php', '' ),
			array( 'pixel-tag-manager', __( 'Pixel and Tag Manager', 'ux-studio' ), 'options-general.php', '' ),
			array( 'performance-optimization', __( 'Performance', 'ux-studio' ), 'options-general.php', '' ),
			array( 'link-manager', __( 'Link Manager', 'ux-studio' ), 'options-general.php', '' ),
			array( 'analytics', __( 'Visitor Statistics', 'ux-studio' ), 'options-general.php', '' ),

			array( 'user-last-login', __( 'Last Login', 'ux-studio' ), 'users.php', '' ),
			array( 'user-switching', __( 'User Switching', 'ux-studio' ), 'users.php', '' ),
			array( 'clean-profiles', __( 'Clean Profiles', 'ux-studio' ), 'users.php', '' ),

			array( 'admin-customiser', __( 'Admin Customiser', 'ux-studio' ), 'themes.php', '' ),
			array( 'menu-icons', __( 'Menu Icons', 'ux-studio' ), 'themes.php', '' ),
			array( 'menu-visibility', __( 'Menu Visibility', 'ux-studio' ), 'themes.php', '' ),
		);

		/**
		 * Filter the module links of the WordPress menu.
		 *
		 * @param array $links Rows of [ module id, label, parent slug (or ModuleLinks::HUB), badge key ].
		 */
		$filtered = apply_filters( 'uxstudio_module_menu_links', $links );
		return is_array( $filtered ) ? $filtered : $links;
	}

	/**
	 * Top-level slugs the hub takes over: Posts, Pages and the site's own public types, except the ones that have
	 * a home of their own (WooCommerce, Elementor templates...).
	 *
	 * @return string[]
	 */
	private function hub_slugs(): array {
		$skip  = (array) apply_filters(
			'uxstudio_content_hub_exclude',
			array( 'elementor_library', 'e-floating-buttons', 'product', 'shop_order', 'shop_coupon', 'shop_subscription', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'wpcode', 'cookielawinfo' )
		);
		$slugs = array( 'edit.php', 'edit.php?post_type=page' );
		foreach ( get_post_types( array( 'show_ui' => true, '_builtin' => false ), 'objects' ) as $type ) {
			if ( in_array( $type->name, $skip, true ) || true !== $type->show_in_menu ) {
				continue;
			}
			$slugs[] = 'edit.php?post_type=' . $type->name;
		}
		return $slugs;
	}

	/**
	 * Build the hub and the module links.
	 */
	public function build(): void {
		global $menu, $submenu;

		$links_on = (bool) $this->settings->get( 'module_links_enabled', false );
		$hub_on   = (bool) $this->settings->get( 'content_hub_enabled', false );
		if ( ( ! $links_on && ! $hub_on ) || ! is_array( $menu ) ) {
			return;
		}

		$badges       = $links_on && (bool) $this->settings->get( 'module_links_badges', true );
		$hub_children = array();
		$position     = null;

		if ( $hub_on ) {
			$wanted = $this->hub_slugs();
			foreach ( $menu as $key => $item ) {
				$slug = is_array( $item ) ? (string) ( $item[2] ?? '' ) : '';
				// A type the user may not even list is not offered.
				if ( ! in_array( $slug, $wanted, true ) || ! current_user_can( (string) ( $item[1] ?? 'edit_posts' ) ) ) {
					continue;
				}
				$position = null === $position ? (int) $key : min( $position, (int) $key );
				unset( $menu[ $key ] );
				$hub_children[]       = array(
					'title' => wp_strip_all_tags( (string) $item[0] ),
					'cap'   => (string) $item[1],
					'slug'  => $slug,
				);
				$this->moved[ $slug ] = $slug;
				// Posts keep their taxonomies (categories, tags); the "Add" screens are one click from the list.
				if ( 'edit.php' === $slug && ! empty( $submenu['edit.php'] ) ) {
					foreach ( array_slice( (array) $submenu['edit.php'], 1 ) as $sub ) {
						$sub_slug = (string) ( $sub[2] ?? '' );
						if ( '' === $sub_slug || str_starts_with( $sub_slug, 'post-new.php' ) ) {
							continue;
						}
						$hub_children[]           = array(
							'title' => wp_strip_all_tags( (string) $sub[0] ),
							'cap'   => (string) $sub[1],
							'slug'  => $sub_slug,
						);
						$this->moved[ $sub_slug ] = 'edit.php';
					}
				}
			}

			if ( array() === $hub_children ) {
				$hub_on = false;
			} else {
				$rows  = $links_on ? $this->links_for( self::HUB, $badges ) : array();
				$count = array_sum( array_column( $rows, 'count' ) );
				$label = __( 'Content', 'ux-studio' ) . ( $count > 0 ? $this->bubble( (int) $count ) : '' );
				add_menu_page( __( 'Content', 'ux-studio' ), $label, 'edit_posts', self::HUB, '__return_null', 'dashicons-admin-page', $position ?? 5 );
				foreach ( $hub_children as $child ) {
					add_submenu_page( self::HUB, $child['title'], $child['title'], $child['cap'], $child['slug'] );
				}
				$this->add_links( self::HUB, $rows );
				// WordPress adds a first sub-item that repeats the hub itself (a page with no content); the top-level
				// link should open Posts instead, so that duplicate goes.
				$submenu[ self::HUB ] = array_values(
					array_filter( (array) ( $submenu[ self::HUB ] ?? array() ), static fn( $sub ): bool => self::HUB !== ( $sub[2] ?? '' ) )
				);
			}
		}

		if ( ! $links_on ) {
			return;
		}

		$parents = array( 'upload.php', 'tools.php', 'options-general.php', 'users.php', 'themes.php' );
		if ( ! $hub_on ) {
			$parents[] = self::HUB;
		}
		foreach ( $parents as $parent ) {
			$this->add_links( self::HUB === $parent ? self::HUB_FALLBACK : $parent, $this->links_for( $parent, $badges ) );
		}
	}

	/**
	 * Links of one parent: only modules that are on, in the catalog order.
	 *
	 * @param string $parent Parent slug of the catalog (or HUB).
	 * @param bool   $badges Compute the counts.
	 * @return array<int, array{id:string,label:string,count:int}>
	 */
	private function links_for( string $parent, bool $badges ): array {
		$enabled = \UxStudio\Plugin::instance()->modules->enabled_ids();
		$rows    = array();
		foreach ( self::catalog() as $link ) {
			if ( ! is_array( $link ) || count( $link ) < 3 || $link[2] !== $parent || ! in_array( $link[0], $enabled, true ) ) {
				continue;
			}
			$rows[] = array(
				'id'    => (string) $link[0],
				'label' => (string) $link[1],
				'count' => $badges && ! empty( $link[3] ) ? self::count( (string) $link[3] ) : 0,
			);
		}
		return $rows;
	}

	/**
	 * Add rows as sub-items. UX Studio pages need the same capability as UX Studio itself.
	 *
	 * @param string                                              $parent Parent menu slug.
	 * @param array<int, array{id:string,label:string,count:int}> $rows   Rows.
	 */
	private function add_links( string $parent, array $rows ): void {
		foreach ( $rows as $row ) {
			$url   = 'admin.php?page=ux-studio#/module?id=' . rawurlencode( $row['id'] );
			$title = $row['label'] . ( $row['count'] > 0 ? $this->bubble( $row['count'] ) : '' );
			add_submenu_page( $parent, $row['label'], $title, 'manage_options', $url );
		}
	}

	/**
	 * Count bubble markup (the WordPress one, so it takes the colors of the admin theme).
	 *
	 * @param int $count Number.
	 */
	private function bubble( int $count ): string {
		return ' <span class="update-plugins count-' . $count . ' uxs-ml-badge"><span class="plugin-count">' . number_format_i18n( $count ) . '</span></span>';
	}

	/**
	 * Number behind a badge key, cached for a few minutes.
	 *
	 * @param string $key Badge key.
	 */
	public static function count( string $key ): int {
		$cache = 'uxs_ml_badge_' . $key;
		$known = get_transient( $cache );
		if ( false !== $known ) {
			return (int) $known;
		}

		global $wpdb;
		$count = 0;
		switch ( $key ) {
			case 'forms':
				if ( class_exists( '\UxStudio\Modules\Forms\Submissions' ) ) {
					$count = \UxStudio\Modules\Forms\Submissions::count_unread();
				}
				break;
			case 'email-log':
				$since = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_email_log WHERE status = 'error' AND created_at >= %s", $since ) );
				break;
			case 'vulnerabilities':
				$result    = get_option( 'uxstudio_vulnerability_scan_result', array() );
				$dismissed = (array) get_option( 'uxstudio_vulnerability_dismissed', array() );
				foreach ( (array) ( is_array( $result ) ? ( $result['findings'] ?? array() ) : array() ) as $finding ) {
					if ( is_array( $finding ) && ! in_array( $finding['key'] ?? '', $dismissed, true ) && 'low' !== ( $finding['severity'] ?? '' ) ) {
						++$count;
					}
				}
				break;
		}

		set_transient( $cache, $count, self::BADGE_TTL );
		return $count;
	}

	/**
	 * While a Posts, Pages or custom type screen is open, the hub is the highlighted top-level item.
	 *
	 * @param mixed $parent_file Parent file of the screen.
	 * @return mixed
	 */
	public function highlight_parent( $parent_file ) {
		if ( is_string( $parent_file ) && isset( $this->moved[ $parent_file ] ) ) {
			$this->origin = $parent_file;
			return self::HUB;
		}
		return $parent_file;
	}

	/**
	 * Highlight the matching hub sub-item (the "Add new" screens belong to their list).
	 *
	 * @param mixed $submenu_file Current sub-item.
	 * @return mixed
	 */
	public function highlight_sub( $submenu_file ) {
		if ( '' === $this->origin ) {
			return $submenu_file;
		}
		if ( null === $submenu_file || ( is_string( $submenu_file ) && str_starts_with( $submenu_file, 'post-new.php' ) ) ) {
			return $this->origin;
		}
		return $submenu_file;
	}

	/**
	 * Keep the bubble compact inside sub-menus.
	 */
	public function badge_styles(): void {
		echo '<style id="uxs-ml-badge">#adminmenu .uxs-ml-badge{margin-left:4px}</style>';
	}
}
