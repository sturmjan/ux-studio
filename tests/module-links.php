<?php
/**
 * Standalone test of the module links and the Content hub in the WordPress menu (Admin Customiser).
 *
 *   php tests/module-links.php
 *
 * Needs no WordPress and no database (WordPress functions are stubbed). Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

namespace UxStudio\Core {
	class Settings {
		public array $v = array();
		public function get( string $k, $d = null ) { return $this->v[ $k ] ?? $d; }
	}
}

namespace UxStudio {
	class Plugin {
		public $modules;
		public static function instance(): self { static $i; return $i ??= new self(); }
	}
}

namespace {
	// Test only: never runs over HTTP (the release zip also excludes tests/).
	if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WEEK_IN_SECONDS', 604800 );

	$GLOBALS['added_pages'] = array();
	$GLOBALS['added_subs']  = array();
	$GLOBALS['caps']        = array( 'edit_posts' => true, 'manage_options' => true, 'edit_pages' => true );

	function __( $s, $d = '' ) { return $s; }
	function apply_filters( $tag, $v ) { return $v; }
	function add_action() {}
	function add_filter() {}
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
	function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
	function number_format_i18n( $n ) { return (string) $n; }
	function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
	function set_transient( $k, $v ) { $GLOBALS['transients'][ $k ] = $v; return true; }
	function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
	function get_post_types() {
		return array(
			(object) array( 'name' => 'reference', 'show_in_menu' => true ),
			(object) array( 'name' => 'product', 'show_in_menu' => true ),
			(object) array( 'name' => 'hidden_type', 'show_in_menu' => false ),
		);
	}
	function add_menu_page( $t, $label, $cap, $slug, $cb, $icon, $pos ) { $GLOBALS['added_pages'][ $slug ] = array( $label, $cap, $pos ); }
	function add_submenu_page( $parent, $t, $label, $cap, $slug ) {
		// Like WordPress: the first sub-item of a new top-level item repeats the item itself.
		if ( ! isset( $GLOBALS['submenu'][ $parent ] ) && $slug !== $parent && isset( $GLOBALS['added_pages'][ $parent ] ) ) {
			$GLOBALS['submenu'][ $parent ][] = array( 'Content', 'edit_posts', $parent );
		}
		$GLOBALS['submenu'][ $parent ][] = array( $label, $cap, $slug );
		$GLOBALS['added_subs'][ $parent ][] = array( $label, $cap, $slug );
	}

	require_once dirname( __DIR__ ) . '/includes/Modules/AdminCustomiser/ModuleLinks.php';

	use UxStudio\Core\Settings;
	use UxStudio\Modules\AdminCustomiser\ModuleLinks;

	$failures = 0;
	$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
		if ( ! $ok ) { ++$failures; }
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
	};

	$fresh_menu = static function (): void {
		$GLOBALS['menu']    = array(
			2  => array( 'Dashboard', 'read', 'index.php', '', 'menu-top' ),
			5  => array( 'Posts', 'edit_posts', 'edit.php', '', 'menu-top' ),
			10 => array( 'Media', 'upload_files', 'upload.php', '', 'menu-top' ),
			20 => array( 'Pages', 'edit_pages', 'edit.php?post_type=page', '', 'menu-top' ),
			21 => array( 'References', 'edit_posts', 'edit.php?post_type=reference', '', 'menu-top' ),
			22 => array( 'Products', 'edit_posts', 'edit.php?post_type=product', '', 'menu-top' ),
		);
		$GLOBALS['submenu'] = array(
			'edit.php' => array(
				array( 'Posts', 'edit_posts', 'edit.php' ),
				array( 'Add New', 'edit_posts', 'post-new.php' ),
				array( 'Categories', 'manage_categories', 'edit-tags.php?taxonomy=category' ),
				array( 'Tags', 'manage_categories', 'edit-tags.php?taxonomy=post_tag' ),
			),
		);
		$GLOBALS['added_pages'] = array();
		$GLOBALS['added_subs']  = array();
		$GLOBALS['transients']  = array();
	};
	$run = static function ( array $settings, array $enabled ) use ( $fresh_menu ): ModuleLinks {
		$fresh_menu();
		$s    = new Settings();
		$s->v = $settings;
		\UxStudio\Plugin::instance()->modules = new class( $enabled ) {
			public function __construct( private array $on ) {}
			public function enabled_ids(): array { return $this->on; }
		};
		$m = new ModuleLinks( $s );
		$m->build();
		return $m;
	};

	/* Both switches off: nothing changes. */
	$run( array(), array( 'top-bar', 'forms' ) );
	$assert( 'off: menu untouched', 5 === count( array_intersect_key( $GLOBALS['menu'], array_flip( array( 5, 10, 20, 21, 22 ) ) ) ) && array() === $GLOBALS['added_pages'] && array() === $GLOBALS['added_subs'] );

	/* Hub only. */
	$m = $run( array( 'content_hub_enabled' => true ), array( 'top-bar' ) );
	$assert( 'hub: Posts, Pages and the own type left the top level', ! isset( $GLOBALS['menu'][5], $GLOBALS['menu'][20], $GLOBALS['menu'][21] ) );
	$assert( 'hub: products and media stay', isset( $GLOBALS['menu'][22], $GLOBALS['menu'][10] ) );
	$assert( 'hub: one top-level item at the place of Posts', isset( $GLOBALS['added_pages']['uxs-content'] ) && 5 === $GLOBALS['added_pages']['uxs-content'][2] );
	$slugs = array_column( $GLOBALS['added_subs']['uxs-content'] ?? array(), 2 );
	$assert( 'hub: children are the lists and the post taxonomies, not "Add new"', array( 'edit.php', 'edit-tags.php?taxonomy=category', 'edit-tags.php?taxonomy=post_tag', 'edit.php?post_type=page', 'edit.php?post_type=reference' ) === $slugs, json_encode( $slugs ) );
	$assert( 'hub: no module links while that switch is off', ! in_array( 'admin.php?page=ux-studio#/module?id=top-bar', $slugs, true ) );
	$assert( 'hub: a Posts screen highlights the hub, "Add new" its list', 'uxs-content' === $m->highlight_parent( 'edit.php' ) && 'edit.php' === $m->highlight_sub( 'post-new.php' ) );
	$assert( 'hub: the empty duplicate of the hub is gone, the top link opens Posts', 'edit.php' === ( array_values( $GLOBALS['submenu']['uxs-content'] )[0][2] ?? '' ) && ! in_array( 'uxs-content', array_column( $GLOBALS['submenu']['uxs-content'], 2 ), true ) );
	$assert( 'hub: foreign screens are left alone', 'upload.php' === $m->highlight_parent( 'upload.php' ) );

	/* Links only (hub off): content links go under Posts, only modules that are on. */
	$run( array( 'module_links_enabled' => true ), array( 'top-bar', 'smtp-email', 'activity-log' ) );
	$assert( 'links: posts stay at the top level', isset( $GLOBALS['menu'][5] ) && ! isset( $GLOBALS['added_pages']['uxs-content'] ) );
	$assert( 'links: content link goes under Posts when there is no hub', 'admin.php?page=ux-studio#/module?id=top-bar' === ( $GLOBALS['added_subs']['edit.php'][0][2] ?? '' ) );
	$assert( 'links: Settings and Tools links of the modules that are on', 'admin.php?page=ux-studio#/module?id=smtp-email' === ( $GLOBALS['added_subs']['options-general.php'][0][2] ?? '' ) && 'admin.php?page=ux-studio#/module?id=activity-log' === ( $GLOBALS['added_subs']['tools.php'][0][2] ?? '' ) );
	$assert( 'links: a module that is off has no link', 1 === count( $GLOBALS['added_subs']['options-general.php'] ) );
	$assert( 'links: UX Studio pages need manage_options', 'manage_options' === $GLOBALS['added_subs']['tools.php'][0][1] );

	/* Both. */
	$GLOBALS['options'] = array();
	$run( array( 'content_hub_enabled' => true, 'module_links_enabled' => true ), array( 'top-bar', 'vulnerability-scanner' ) );
	$subs = array_column( $GLOBALS['added_subs']['uxs-content'], 2 );
	$assert( 'both: module links follow the content types in the hub', 'admin.php?page=ux-studio#/module?id=top-bar' === end( $subs ) );

	/* Badges. */
	$GLOBALS['options']    = array(
		'uxstudio_vulnerability_scan_result' => array(
			'findings' => array(
				array( 'key' => 'a', 'severity' => 'high' ),
				array( 'key' => 'b', 'severity' => 'low' ),
				array( 'key' => 'c', 'severity' => 'medium' ),
				array( 'key' => 'd', 'severity' => 'high' ),
			),
		),
		'uxstudio_vulnerability_dismissed'   => array( 'd' ),
	);
	$GLOBALS['transients'] = array();
	$assert( 'badge: open findings without low and dismissed ones', 2 === ModuleLinks::count( 'vulnerabilities' ) );
	$run( array( 'module_links_enabled' => true ), array( 'vulnerability-scanner' ) );
	$assert( 'badge: bubble in the link title', str_contains( $GLOBALS['added_subs']['tools.php'][0][0], 'plugin-count">2<' ) );
	$run( array( 'module_links_enabled' => true, 'module_links_badges' => false ), array( 'vulnerability-scanner' ) );
	$assert( 'badge: off means no bubble', ! str_contains( $GLOBALS['added_subs']['tools.php'][0][0], 'plugin-count' ) );

	/* An editor still gets the hub. */
	$GLOBALS['caps']['manage_options'] = false;
	$run( array( 'content_hub_enabled' => true, 'module_links_enabled' => true ), array( 'top-bar' ) );
	$assert( 'caps: hub is built for editors', isset( $GLOBALS['added_pages']['uxs-content'] ) );

	echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
	exit( 0 === $failures ? 0 : 1 );
}
