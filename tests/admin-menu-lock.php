<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the Menu Organizer access lock (PLAN.md 27.6): which admin request is refused to whom.
 * Pure: roles x hidden items x protected screens x manage_options x kill switch.
 *
 *   php tests/admin-menu-lock.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

$GLOBALS['uxs_options'] = array();

function __( $text, $domain = '' ) { return $text; }
function get_post_types( $a = array(), $o = 'names' ) { return array(); }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
$GLOBALS['uxs_posts'] = array( 5 => 'page', 6 => 'post', 7 => 'product' );
function get_post_type( $id ) { return $GLOBALS['uxs_posts'][ (int) $id ] ?? false; }
function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'product' ), true ); }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

// Settings double: reads the fake options.
if ( ! class_exists( \UxStudio\Core\Settings::class, false ) ) {
	eval( 'namespace UxStudio\Core; final class Settings { private string $o; public function __construct( string $o ) { $this->o = $o; } public function get( string $k, $d = null ) { return $GLOBALS["uxs_options"][ $this->o ][ $k ] ?? $d; } }' );
}

use UxStudio\Core\Migration\MenuSimulator as S;
use UxStudio\Modules\AdminCustomiser\MenuLock;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* The menu as WordPress builds it (before the Organizer), like pobyty with WooCommerce and a plugin. */
$tops = array(
	'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'woocommerce', 'edit.php?post_type=product',
	'themes.php', 'plugins.php', 'users.php', 'tools.php', 'options-general.php', 'ux-studio', 'my-plugin',
);
$subs = array(
	'index.php'                => array( 'index.php', 'update-core.php' ),
	'edit.php'                 => array( 'edit.php', 'post-new.php', 'edit-tags.php?taxonomy=category', 'edit-tags.php?taxonomy=post_tag' ),
	'upload.php'               => array( 'upload.php', 'media-new.php' ),
	'edit.php?post_type=page'  => array( 'edit.php?post_type=page', 'post-new.php?post_type=page' ),
	'woocommerce'              => array( 'wc-orders', 'wc-settings', 'wc-reports' ),
	'edit.php?post_type=product' => array( 'edit.php?post_type=product', 'post-new.php?post_type=product', 'edit-tags.php?taxonomy=product_cat&amp;post_type=product' ),
	'themes.php'               => array( 'themes.php', 'nav-menus.php' ),
	'users.php'                => array( 'users.php', 'user-new.php', 'profile.php' ),
	'tools.php'                => array( 'tools.php', 'import.php', 'my-tool' ),
	'options-general.php'      => array( 'options-general.php', 'options-writing.php', 'my-options' ),
	'my-plugin'                => array( 'my-plugin', 'my-plugin-sub' ),
	'ux-studio'                => array( 'ux-studio' ),
);

$config = array_merge(
	\UxStudio\Modules\AdminCustomiser\MenuOrganizer::default_config(),
	array(
		'item_roles'    => array(
			'woocommerce'             => array( 'shop_manager' ),
			'edit.php?post_type=page' => array( 'editor' ),
			'plugins.php'             => array( 'shop_manager' ), // Protected: must never be blocked.
			'options-general.php'     => array( 'editor' ),
			'ux-studio'               => array( 'editor' ),
			'users.php'               => array( 'editor' ),
		),
		'hidden_items'  => array( 'edit-comments.php', 'tools.php', 'my-plugin' ),
		'promoted_subs' => array(
			'import.php' => array( 'parent' => 'tools.php', 'title' => 'Import', 'icon' => '', 'category' => 'other' ),
			'my-tool'    => array( 'parent' => 'tools.php', 'title' => 'My tool', 'icon' => '', 'category' => 'other' ),
		),
	)
);
$config['item_roles']['import.php'] = array( 'editor' );

$blocked = static function ( string $role, string $file, array $args = array(), ?array $cfg = null ) use ( $config, $tops, $subs ): bool {
	$cfg = $cfg ?? $config;
	return S::request_blocked( $cfg, $file, $args, $tops, $subs, array( $role ), 'administrator' === $role )['blocked'];
};
$reason = static function ( string $role, string $file, array $args = array() ) use ( $config, $tops, $subs ): string {
	return S::request_blocked( $config, $file, $args, $tops, $subs, array( $role ), 'administrator' === $role )['reason'];
};

/* ── Resolver ── */
$res = static fn( string $file, array $args = array() ) => S::resolve_request( $file, $args, $tops, $subs );
$assert( 'resolve: edit.php is Posts', 'edit.php' === $res( 'edit.php' )['top'] );
$assert( 'resolve: edit.php?post_type=page is Pages (not Posts)', 'edit.php?post_type=page' === $res( 'edit.php', array( 'post_type' => 'page' ) )['top'] );
$assert( 'resolve: post-new.php?post_type=page is under Pages', 'edit.php?post_type=page' === $res( 'post-new.php', array( 'post_type' => 'page' ) )['top'] );
$assert( 'resolve: post.php of a page (post type from the post) is Pages', 'edit.php?post_type=page' === $res( 'post.php', array( 'post_type' => 'page' ) )['top'] );
$assert( 'resolve: post.php without type is Posts', 'edit.php' === $res( 'post.php' )['top'] );
$assert( 'resolve: a post type without a menu entry fits nothing', null === $res( 'edit.php', array( 'post_type' => 'wp_block' ) ) );
$assert( 'resolve: category screen is under Posts', 'edit.php' === $res( 'edit-tags.php', array( 'taxonomy' => 'category' ) )['top'] );
$assert( 'resolve: product category (taxonomy + post_type) is under Products', 'edit.php?post_type=product' === $res( 'edit-tags.php', array( 'taxonomy' => 'product_cat', 'post_type' => 'product' ) )['top'] );
$assert( 'resolve: term.php editing a category is under Posts', 'edit.php' === $res( 'term.php', array( 'taxonomy' => 'category' ) )['top'] );
$assert( 'resolve: admin.php?page=wc-settings is a sub-item of WooCommerce', array( 'top' => 'woocommerce', 'sub' => 'wc-settings' ) === $res( 'admin.php', array( 'page' => 'wc-settings' ) ) );
$assert( 'resolve: admin.php?page=my-plugin is the top-level item', array( 'top' => 'my-plugin', 'sub' => null ) === $res( 'admin.php', array( 'page' => 'my-plugin' ) ) );
$assert( 'resolve: tools.php?page=my-tool is under Tools', 'tools.php' === $res( 'tools.php', array( 'page' => 'my-tool' ) )['top'] );
$assert( 'resolve: nav-menus.php is under Appearance', 'themes.php' === $res( 'nav-menus.php' )['top'] );
$assert( 'resolve: an unknown page fits nothing', null === $res( 'admin.php', array( 'page' => 'nope' ) ) );
$assert( 'resolve: an unknown file fits nothing', null === $res( 'site-health.php' ) );

/* ── Matrix: role x request ── */
$cases = array(
	// file, args, [role => expected blocked]
	array( 'edit-comments.php', array(), array( 'editor' => true, 'shop_manager' => true, 'subscriber' => true, 'administrator' => false ), 'hidden for everybody' ),
	array( 'admin.php', array( 'page' => 'my-plugin' ), array( 'editor' => true, 'shop_manager' => true, 'subscriber' => true, 'administrator' => false ), 'hidden top-level plugin page' ),
	array( 'admin.php', array( 'page' => 'my-plugin-sub' ), array( 'editor' => true, 'shop_manager' => true, 'subscriber' => true, 'administrator' => false ), 'its sub-item follows the parent' ),
	array( 'edit.php', array( 'post_type' => 'page' ), array( 'editor' => false, 'shop_manager' => true, 'subscriber' => true, 'administrator' => false ), 'Pages limited to editor' ),
	array( 'post-new.php', array( 'post_type' => 'page' ), array( 'editor' => false, 'shop_manager' => true, 'subscriber' => true, 'administrator' => false ), 'new page limited to editor' ),
	array( 'post.php', array( 'post_type' => 'page' ), array( 'editor' => false, 'shop_manager' => true, 'subscriber' => true, 'administrator' => false ), 'editing a page limited to editor' ),
	array( 'edit.php', array(), array( 'editor' => false, 'shop_manager' => false, 'subscriber' => false, 'administrator' => false ), 'Posts: no rule, never blocked' ),
	array( 'admin.php', array( 'page' => 'wc-settings' ), array( 'editor' => true, 'shop_manager' => false, 'subscriber' => true, 'administrator' => false ), 'WooCommerce sub-item limited to shop_manager' ),
	array( 'edit.php', array( 'post_type' => 'product' ), array( 'editor' => false, 'shop_manager' => false, 'subscriber' => false, 'administrator' => false ), 'Products: no rule' ),
	array( 'tools.php', array(), array( 'editor' => true, 'shop_manager' => true, 'subscriber' => true, 'administrator' => false ), 'Tools hidden for everybody' ),
	array( 'import.php', array(), array( 'editor' => false, 'shop_manager' => true, 'subscriber' => true, 'administrator' => false ), 'Import is a promoted sub-item allowed for editor (parent Tools hidden)' ),
	// "my-tool" is promoted but has no item_roles entry: the Organizer shows it to everybody, so it stays reachable although Tools is hidden.
	array( 'tools.php', array( 'page' => 'my-tool' ), array( 'editor' => false, 'shop_manager' => false, 'subscriber' => false, 'administrator' => false ), 'a promoted sub-item shown to everybody stays reachable' ),
);
foreach ( $cases as $case ) {
	foreach ( $case[2] as $role => $expect ) {
		$assert( "{$case[3]}: $role " . ( $expect ? 'refused' : 'allowed' ) . " ({$case[0]})", $expect === $blocked( $role, $case[0], $case[1] ), $reason( $role, $case[0], $case[1] ) );
	}
}

/* ── Protected screens and exempt files: never blocked, even when a rule says so ── */
foreach ( array( 'editor', 'shop_manager', 'subscriber' ) as $role ) {
	$assert( "protected: UX Studio opens for $role (item_roles would hide it)", ! $blocked( $role, 'admin.php', array( 'page' => 'ux-studio' ) ) );
	$assert( "protected: Plugins opens for $role", ! $blocked( $role, 'plugins.php' ) );
	$assert( "protected: Users opens for $role", ! $blocked( $role, 'users.php' ) );
	$assert( "protected: Settings opens for $role", ! $blocked( $role, 'options-general.php' ) );
	$assert( "protected: a Settings sub-screen opens for $role", ! $blocked( $role, 'options-general.php', array( 'page' => 'my-options' ) ) );
	$assert( "protected: Add user opens for $role", ! $blocked( $role, 'user-new.php' ) );
	foreach ( array( 'index.php', 'profile.php', 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'options.php' ) as $file ) {
		$assert( "exempt file $file opens for $role even when everything is hidden", ! S::request_blocked( array_merge( $config, array( 'hidden_items' => $tops ) ), $file, array(), $tops, $subs, array( $role ), false )['blocked'] );
	}
}
$all_hidden = array_merge( $config, array( 'hidden_items' => $tops ) );
$assert( 'everything hidden: the four protected screens still open for a non-admin', ! S::request_blocked( $all_hidden, 'plugins.php', array(), $tops, $subs, array( 'editor' ), false )['blocked']
	&& ! S::request_blocked( $all_hidden, 'users.php', array(), $tops, $subs, array( 'editor' ), false )['blocked']
	&& ! S::request_blocked( $all_hidden, 'options-general.php', array(), $tops, $subs, array( 'editor' ), false )['blocked']
	&& ! S::request_blocked( $all_hidden, 'admin.php', array( 'page' => 'ux-studio' ), $tops, $subs, array( 'editor' ), false )['blocked'] );
$assert( 'everything hidden: an administrator (manage_options) is never blocked', ! S::request_blocked( $all_hidden, 'edit.php', array(), $tops, $subs, array( 'administrator' ), true )['blocked'] );
$assert( 'everything hidden: a non-admin is blocked from Posts', S::request_blocked( $all_hidden, 'edit.php', array(), $tops, $subs, array( 'editor' ), false )['blocked'] );

/* ── manage_options wins over the role list: a custom role with the capability is never blocked ── */
$assert( 'manage_options bypass: role "editor" with manage_options is not blocked from a hidden page', ! S::request_blocked( $config, 'edit-comments.php', array(), $tops, $subs, array( 'editor' ), true )['blocked'] );

/* ── A user with several roles: one allowed role is enough (the Organizer shows the item) ── */
$assert( 'several roles: editor + shop_manager can open WooCommerce', ! S::request_blocked( $config, 'admin.php', array( 'page' => 'wc-settings' ), $tops, $subs, array( 'editor', 'shop_manager' ), false )['blocked'] );
$assert( 'several roles: subscriber + customer cannot', S::request_blocked( $config, 'admin.php', array( 'page' => 'wc-settings' ), $tops, $subs, array( 'subscriber', 'customer' ), false )['blocked'] );
$assert( 'a user with no role is treated like a role without access', S::request_blocked( $config, 'admin.php', array( 'page' => 'wc-settings' ), $tops, $subs, array(), false )['blocked'] );

/* ── Category roles (a whole group limited to roles) ── */
$cat_config = array_merge( \UxStudio\Modules\AdminCustomiser\MenuOrganizer::default_config(), array( 'category_roles' => array( 'commerce' => array( 'shop_manager' ) ) ) );
$assert( 'category roles: WooCommerce (auto category "commerce") is refused for editor', $blocked( 'editor', 'admin.php', array( 'page' => 'wc-orders' ), $cat_config ) );
$assert( 'category roles: and open for shop_manager', ! $blocked( 'shop_manager', 'admin.php', array( 'page' => 'wc-orders' ), $cat_config ) );
$assert( 'category roles: Products (commerce) refused for editor', $blocked( 'editor', 'edit.php', array( 'post_type' => 'product' ), $cat_config ) );
$assert( 'category roles: Posts (content) unaffected', ! $blocked( 'editor', 'edit.php', array(), $cat_config ) );
$assign = array_merge( $cat_config, array( 'assignments' => array( 'woocommerce' => 'other' ) ) );
$assert( 'category roles: moving WooCommerce to another category lifts the limit', ! $blocked( 'editor', 'admin.php', array( 'page' => 'wc-orders' ), $assign ) );

/* ── Same answer as the menu simulation used by the migration ── */
$matrix = S::blocked_matrix( $config, $tops, array( 'editor', 'shop_manager', 'subscriber', 'administrator' ), array( 'administrator' ) );
$ok     = true;
foreach ( array( 'editor', 'shop_manager', 'subscriber' ) as $role ) {
	foreach ( $tops as $top ) {
		$sim = in_array( $top, $matrix[ $role ], true );
		$req = S::request_blocked( $config, 'admin.php', array( 'page' => $top ), $tops, $subs, array( $role ), false );
		// Only compare tops that are plugin pages (a slug that is a file is requested by file).
		if ( ! preg_match( '/\.php/', $top ) && $sim !== $req['blocked'] ) {
			$ok = false;
		}
	}
}
$assert( 'blocked_matrix agrees with the per-request decision', $ok );
$assert( 'blocked_matrix never lists a protected screen or an administrator', ! array_intersect( array_keys( S::protected_screens() ), $matrix['editor'] ) && array() === $matrix['administrator'] );
$assert( 'blocked_matrix: editor is refused hidden items, shop_manager the editor-only page', in_array( 'edit-comments.php', $matrix['editor'], true ) && in_array( 'edit.php?post_type=page', $matrix['shop_manager'], true ) && ! in_array( 'edit.php?post_type=page', $matrix['editor'], true ) );

/* ── post.php: the post type comes from the post, never from the request (finding 5) ── */
$assert( 'request_args: post.php?post=<page> with a forged post_type=post still resolves to page', array( 'post_type' => 'page' ) === MenuLock::request_args( 'post.php', array( 'post' => '5', 'post_type' => 'post' ) ) );
$assert( 'request_args: a save (post_ID in the body) ignores the forged post_type too', array( 'post_type' => 'page' ) === MenuLock::request_args( 'post.php', array( 'post_ID' => '5', 'post_type' => 'post', 'action' => 'editpost' ) ) );
$assert( 'request_args: post.php without a post id gets no post type from the request', array() === MenuLock::request_args( 'post.php', array( 'post_type' => 'page' ) ) );
$assert( 'request_args: a forged post_type for an unknown post id is dropped', array() === MenuLock::request_args( 'post.php', array( 'post' => '999', 'post_type' => 'page' ) ) );
$assert( 'request_args: edit.php keeps a registered post_type', array( 'post_type' => 'page' ) === MenuLock::request_args( 'edit.php', array( 'post_type' => 'page' ) ) );
$assert( 'request_args: post-new.php keeps it too, an unregistered type is dropped', array( 'post_type' => 'product' ) === MenuLock::request_args( 'post-new.php', array( 'post_type' => 'product' ) ) && array() === MenuLock::request_args( 'post-new.php', array( 'post_type' => 'bogus' ) ) );
$assert( 'request_args: page and taxonomy pass through', array( 'page' => 'wc-settings' ) === MenuLock::request_args( 'admin.php', array( 'page' => 'wc-settings' ) ) && array( 'taxonomy' => 'product_cat', 'post_type' => 'product' ) === MenuLock::request_args( 'edit-tags.php', array( 'taxonomy' => 'product_cat', 'post_type' => 'product' ) ) );
// The full bypass: Pages are limited to editors; a shop manager opens the page with a forged post_type=post.
$bypass_args = MenuLock::request_args( 'post.php', array( 'post' => '5', 'post_type' => 'post' ) );
$assert( 'bypass attempt (edit a hidden Page via post.php?post_type=post) is refused for shop_manager and allowed for editor', S::request_blocked( $config, 'post.php', $bypass_args, $tops, $subs, array( 'shop_manager' ), false )['blocked'] && ! S::request_blocked( $config, 'post.php', $bypass_args, $tops, $subs, array( 'editor' ), false )['blocked'] );
$forged = array( 'post_type' => 'post' ); // What the old code passed on: Posts, which the shop manager may open.
$assert( 'the old (request-trusting) args would have let the shop manager through', ! S::request_blocked( $config, 'post.php', $forged, $tops, $subs, array( 'shop_manager' ), false )['blocked'] );

/* ── Switches: setting off by default, kill constant ── */
$GLOBALS['uxs_options'] = array();
$assert( 'switch: off by default (existing sites keep hide only)', ! MenuLock::active() );
$GLOBALS['uxs_options']['uxstudio_admin_customiser'] = array( 'menu_lock_enabled' => true );
$assert( 'switch: the lock setting alone is not enough without the menu organizer', ! MenuLock::active() );
$GLOBALS['uxs_options']['uxstudio_admin_customiser']['menu_organizer_enabled'] = true;
$assert( 'switch: on with the setting and the organizer', MenuLock::active() );
$assert( 'switch: rescue constant not defined yet', ! MenuLock::killed() );
define( 'UXSTUDIO_DISABLE_MENU_LOCK', true );
$assert( 'kill switch: the constant is seen', MenuLock::killed() );
$assert( 'kill switch: the lock is off even with the setting on', ! MenuLock::active() );
$assert( 'kill switch constant is named in the UI help text', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/AdminCustomiser/Module.php' ), 'UXSTUDIO_DISABLE_MENU_LOCK' ) );
$assert( 'the real schema has the setting, off by default', (function () {
	$m = ( new ReflectionClass( \UxStudio\Modules\AdminCustomiser\Module::class ) )->newInstanceWithoutConstructor();
	foreach ( $m->settings_schema() as $f ) {
		if ( 'menu_lock_enabled' === $f['key'] ) {
			return 'toggle' === $f['type'] && false === $f['default'];
		}
	}
	return false;
})() );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
