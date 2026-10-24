<?php
/**
 * Standalone test of PLAN.md 27.7 (B1): sub-items of the Menu Organizer (rename, hide per role, move, order), per-user rules
 * and required capabilities, the access lock and the lockout rule with them, custom CSS sanitizer and output, meta boxes per
 * role, dashboard widgets per role and the welcome panel, sorting and filtering of Admin Columns.
 *
 *   php tests/admin-b1.php
 *
 * Needs no WordPress and no database (small doubles of the few WordPress functions used). Exits non-zero when a case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );
define( 'KB_IN_BYTES', 1024 );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_hooks']   = array();
$GLOBALS['uxs_user']    = array( 'roles' => array( 'editor' ), 'login' => 'alice', 'can' => array() );

function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return preg_match( '#^https?://#i', (string) $s ) ? (string) $s : ''; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_user( $s, $strict = false ) { return trim( preg_replace( '/[^a-zA-Z0-9 _.\-@]/', '', strip_tags( (string) $s ) ) ); }
function sanitize_hex_color( $s ) { return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $s ) ? (string) $s : ''; }
function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $s ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_kses_post( $s ) { return preg_replace( '#<script\b.*?</script>#is', '', (string) $s ); }
function wpautop( $s ) { return '<p>' . $s . '</p>'; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function get_post_types( $a = array(), $o = 'names' ) { return array(); }
function translate_user_role( $n ) { return $n; }
function wp_get_attachment_image_url( $id, $size = 'full' ) { return 900 === (int) $id ? 'https://example.test/uploads/bg.jpg?x=1&y=2' : ( 901 === (int) $id ? 'https://example.test/uploads/ev"il<.jpg' : '' ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_hooks'][] = $hook; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_hooks'][] = $hook; }
function wp_get_current_user() { return (object) array( 'ID' => 7, 'roles' => $GLOBALS['uxs_user']['roles'], 'user_login' => $GLOBALS['uxs_user']['login'] ); }
function current_user_can( $cap ) { return 'manage_options' === $cap ? in_array( 'administrator', $GLOBALS['uxs_user']['roles'], true ) : in_array( $cap, $GLOBALS['uxs_user']['can'], true ); }
function wp_roles() {
	$caps = static fn( array $c = array() ) => array( 'capabilities' => $c );
	return (object) array(
		'roles' => array(
			'administrator' => array( 'name' => 'Administrator' ) + $caps( array( 'manage_options' => true, 'manage_woocommerce' => true ) ),
			'editor'        => array( 'name' => 'Editor' ) + $caps( array( 'edit_others_posts' => true ) ),
			'author'        => array( 'name' => 'Author' ) + $caps(),
			'subscriber'    => array( 'name' => 'Subscriber' ) + $caps(),
			'shop_manager'  => array( 'name' => 'Shop manager' ) + $caps( array( 'manage_woocommerce' => true ) ),
		),
	);
}

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\CssSanitizer;
use UxStudio\Core\Migration\MenuSimulator as S;
use UxStudio\Core\RoleRules;
use UxStudio\Core\Settings;
use UxStudio\Modules\AdminColumns\ColumnQuery as Q;
use UxStudio\Modules\AdminCustomiser\CustomCss;
use UxStudio\Modules\AdminCustomiser\Login;
use UxStudio\Modules\AdminCustomiser\MenuOrganizer;
use UxStudio\Modules\AdminCustomiser\MetaBoxRules;
use UxStudio\Modules\DashboardWidgets\RoleWidgets;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$j = static fn( $v ): string => (string) json_encode( $v, JSON_UNESCAPED_UNICODE );

/* ══ 1. Sub-item rules in MenuSimulator, and the access lock that follows them ══ */

$tops = array( 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'woocommerce', 'plugins.php', 'users.php', 'options-general.php', 'ux-studio', 'tools.php' );
$subs = array(
	'edit.php'                => array( 'edit.php', 'post-new.php', 'edit-tags.php?taxonomy=category' ),
	'upload.php'              => array( 'upload.php', 'media-new.php' ),
	'edit.php?post_type=page' => array( 'edit.php?post_type=page', 'post-new.php?post_type=page' ),
	'woocommerce'             => array( 'wc-orders', 'wc-settings', 'wc-reports' ),
	'tools.php'               => array( 'tools.php', 'import.php', 'export.php', 'my-tool' ),
	'plugins.php'             => array( 'plugins.php', 'plugin-install.php' ),
	'users.php'               => array( 'users.php', 'user-new.php', 'profile.php' ),
	'options-general.php'     => array( 'options-general.php', 'options-writing.php' ),
	'ux-studio'               => array( 'ux-studio' ),
);
$cfg = array_merge(
	MenuOrganizer::default_config(),
	array(
		'sub_hidden' => array(
			'woocommerce>wc-reports'                 => array( 'shop_manager' ),
			'woocommerce>wc-settings'                => array( 'editor', 'author' ),
			'edit.php>edit-tags.php?taxonomy=category' => array( '*' ),
			'tools.php>export.php'                   => array( 'editor', 'subscriber' ),
			'edit.php>edit.php'                      => array( 'subscriber' ),
			'options-general.php>options-writing.php' => array( 'editor' ),
			'plugins.php>plugins.php'                => array( '*' ),
		),
		'item_users' => array(
			'upload.php>media-new.php'  => array( 'hide' => array( 'Bob' ) ),
			'woocommerce>wc-settings'   => array( 'show' => array( 'carol' ) ),
			'woocommerce>wc-orders'     => array( 'show' => array( 'dave' ), 'hide' => array( 'dave' ) ),
			'edit.php?post_type=page'   => array( 'hide' => array( 'erin' ) ),
		),
		'item_caps'  => array(
			'woocommerce>wc-settings' => 'manage_woocommerce',
			'tools.php>import.php'    => 'edit_others_posts',
		),
		'sub_moves'  => array( 'tools.php>my-tool' => 'edit.php' ),
		'item_roles' => array( 'upload.php' => array( 'editor', 'author' ) ),
	)
);
$can = static fn( array $caps ): callable => static fn( string $cap ): bool => in_array( $cap, $caps, true );
$ctx = static fn( string $login, array $caps = array() ): array => array( 'login' => $login, 'can' => $can( $caps ) );

$sv = static fn( string $parent, string $sub, array $roles, bool $bypass = false, array $c = array() ): bool => S::sub_visible( $cfg, $parent, $sub, $roles, $bypass, $c );
$assert( 'sub hide per role: wc-reports hidden for shop_manager, shown to editor', ! $sv( 'woocommerce', 'wc-reports', array( 'shop_manager' ) ) && $sv( 'woocommerce', 'wc-reports', array( 'editor' ) ) );
$assert( 'sub hide for everybody ("*"): even an administrator does not see it in the menu (like hidden_items)', ! $sv( 'edit.php', 'edit-tags.php?taxonomy=category', array( 'editor' ) ) && ! $sv( 'edit.php', 'edit-tags.php?taxonomy=category', array( 'administrator' ), true ) );
$assert( 'sub hide: a user with several roles keeps the item when one of the roles may see it', $sv( 'tools.php', 'export.php', array( 'editor', 'author' ) ) && ! $sv( 'tools.php', 'export.php', array( 'editor', 'subscriber' ) ) );
$assert( 'sub hide: a user without roles is hidden only by "*"', $sv( 'tools.php', 'export.php', array() ) && ! $sv( 'edit.php', 'edit-tags.php?taxonomy=category', array() ) );
$assert( 'per-user hide (login compared case-insensitively)', ! $sv( 'upload.php', 'media-new.php', array( 'editor' ), false, $ctx( 'bob' ) ) && $sv( 'upload.php', 'media-new.php', array( 'editor' ), false, $ctx( 'alice' ) ) );
$assert( 'per-user show beats the role list of the sub-item (editor is hidden, carol is not)', $sv( 'woocommerce', 'wc-settings', array( 'editor' ), false, $ctx( 'carol', array( 'manage_woocommerce' ) ) ) && ! $sv( 'woocommerce', 'wc-settings', array( 'editor' ), false, $ctx( 'alice', array( 'manage_woocommerce' ) ) ) );
$assert( 'required capability is still required for a user who is shown (a show rule never gives access)', ! $sv( 'woocommerce', 'wc-settings', array( 'editor' ), false, $ctx( 'carol', array() ) ) );
$assert( 'required capability: import.php needs edit_others_posts', $sv( 'tools.php', 'import.php', array( 'editor' ), false, $ctx( 'alice', array( 'edit_others_posts' ) ) ) && ! $sv( 'tools.php', 'import.php', array( 'author' ), false, $ctx( 'alice', array() ) ) );
$assert( 'required capability is skipped when capabilities are unknown (simulation without data)', $sv( 'tools.php', 'import.php', array( 'author' ) ) );
$assert( 'hide beats show for the same user', ! $sv( 'woocommerce', 'wc-orders', array( 'editor' ), false, $ctx( 'dave' ) ) );
$assert( 'effective parent: moved sub-item', 'edit.php' === S::effective_parent( $cfg, 'tools.php', 'my-tool' ) && 'tools.php' === S::effective_parent( $cfg, 'tools.php', 'import.php' ) );
$assert( 'a moved sub-item follows the visibility of its NEW parent (Posts open, Tools hidden)', S::sub_visible( array_merge( $cfg, array( 'hidden_items' => array( 'tools.php' ) ) ), 'tools.php', 'my-tool', array( 'editor' ), false ) && ! S::sub_visible( array_merge( $cfg, array( 'hidden_items' => array( 'edit.php' ) ) ), 'tools.php', 'my-tool', array( 'editor' ), false ) );
$assert( 'top-level per-user hide (erin) and the keys of rules: Pages hidden for erin only', ! S::visible( $cfg, 'edit.php?post_type=page', array( 'editor' ), false, $ctx( 'erin' ) ) && S::visible( $cfg, 'edit.php?post_type=page', array( 'editor' ), false, $ctx( 'alice' ) ) );
$assert( 'top-level item_roles still work next to the new rules; per-user show lifts the role whitelist', ! S::visible( $cfg, 'upload.php', array( 'subscriber' ), false ) && S::visible( array_merge( $cfg, array( 'item_users' => array( 'upload.php' => array( 'show' => array( 'zoe' ) ) ) ) ), 'upload.php', array( 'subscriber' ), false, $ctx( 'zoe' ) ) );

/* Lockout safety inside the same functions. */
$assert( 'protected: Installed Plugins stays visible for an administrator although the rule hides it for "*"', S::sub_visible( $cfg, 'plugins.php', 'plugins.php', array( 'administrator' ), true ) );
$assert( 'protected: ... but a user who cannot manage options is hidden by it (display), the lock still never blocks the screen', ! S::sub_visible( $cfg, 'plugins.php', 'plugins.php', array( 'editor' ), false ) && ! S::request_blocked( $cfg, 'plugins.php', array(), $tops, $subs, array( 'editor' ), false )['blocked'] );
$assert( 'protected: per-user hide and a capability on a protected top-level item do not reach a user who can manage options', S::visible( array_merge( $cfg, array( 'item_users' => array( 'users.php' => array( 'hide' => array( 'root' ) ) ), 'item_caps' => array( 'ux-studio' => 'nobody_has_this' ) ) ), 'users.php', array( 'administrator' ), true, $ctx( 'root' ) ) && S::visible( array_merge( $cfg, array( 'item_caps' => array( 'ux-studio' => 'nobody_has_this' ) ) ), 'ux-studio', array( 'administrator' ), true, $ctx( 'root' ) ) );

/* The lock: the same decision, per request. */
$blocked = static fn( string $file, array $args, array $roles, bool $bypass = false, array $c = array(), ?array $config = null ): array => S::request_blocked( $config ?? $cfg, $file, $args, $tops, $subs, $roles, $bypass, $c );
$assert( 'lock: a hidden sub-item is refused by address (wc-reports for shop_manager), reason says sub-item', $blocked( 'admin.php', array( 'page' => 'wc-reports' ), array( 'shop_manager' ) )['blocked'] && 'hidden-sub' === $blocked( 'admin.php', array( 'page' => 'wc-reports' ), array( 'shop_manager' ) )['reason'] );
$assert( 'lock: ... and open for a role the rule does not name; its siblings stay open', ! $blocked( 'admin.php', array( 'page' => 'wc-reports' ), array( 'editor' ) )['blocked'] && ! $blocked( 'admin.php', array( 'page' => 'wc-orders' ), array( 'shop_manager' ) )['blocked'] );
$assert( 'lock: the category screen hidden for "*" is refused for an editor (typed address), never for manage_options', $blocked( 'edit-tags.php', array( 'taxonomy' => 'category' ), array( 'editor' ) )['blocked'] && ! $blocked( 'edit-tags.php', array( 'taxonomy' => 'category' ), array( 'administrator' ), true )['blocked'] );
$assert( 'lock: per-user hide refuses media-new.php for bob only', $blocked( 'media-new.php', array(), array( 'editor' ), false, $ctx( 'bob' ) )['blocked'] && ! $blocked( 'media-new.php', array(), array( 'editor' ), false, $ctx( 'alice' ) )['blocked'] );
$assert( 'lock: required capability refuses the page to a user without it', $blocked( 'admin.php', array( 'page' => 'wc-settings' ), array( 'editor' ), false, $ctx( 'carol', array() ) )['blocked'] && ! $blocked( 'admin.php', array( 'page' => 'wc-settings' ), array( 'editor' ), false, $ctx( 'carol', array( 'manage_woocommerce' ) ) )['blocked'] );
$assert( 'lock: the first sub-item of a menu is the menu page itself: Posts > All posts hidden for subscribers refuses edit.php', $blocked( 'edit.php', array(), array( 'subscriber' ) )['blocked'] && ! $blocked( 'edit.php', array(), array( 'editor' ) )['blocked'] && ! $blocked( 'post-new.php', array(), array( 'subscriber' ) )['blocked'] );
$assert( 'lock: a moved sub-item is judged under its new parent', ! $blocked( 'tools.php', array( 'page' => 'my-tool' ), array( 'editor' ), false, array(), array_merge( $cfg, array( 'hidden_items' => array( 'tools.php' ) ) ) )['blocked'] && $blocked( 'tools.php', array( 'page' => 'my-tool' ), array( 'editor' ), false, array(), array_merge( $cfg, array( 'hidden_items' => array( 'edit.php' ) ) ) )['blocked'] );
$assert( 'lock: under a protected parent nothing is ever refused (Settings > Writing is hidden for editors but opens)', ! $blocked( 'options-writing.php', array(), array( 'editor' ) )['blocked'] && ! $sv( 'options-general.php', 'options-writing.php', array( 'editor' ) ) );
$assert( 'lock: exempt files and manage_options are untouched by the new rules', ! $blocked( 'profile.php', array(), array( 'subscriber' ) )['blocked'] && ! $blocked( 'admin.php', array( 'page' => 'wc-reports' ), array( 'shop_manager' ), true )['blocked'] );
$m = S::blocked_matrix( $cfg, $tops, array( 'shop_manager', 'editor', 'administrator' ), array( 'administrator' ), $subs );
$assert( 'blocked_matrix with sub-items lists parent>sub keys and agrees with the per-request decision', in_array( 'woocommerce>wc-reports', $m['shop_manager'], true ) && ! in_array( 'woocommerce>wc-reports', $m['editor'], true ) && in_array( 'edit.php>edit-tags.php?taxonomy=category', $m['editor'], true ) && array() === $m['administrator'] && ! in_array( 'plugins.php>plugins.php', $m['editor'], true ) && ! in_array( 'options-general.php>options-writing.php', $m['editor'], true ), $j( $m ) );
$assert( 'blocked_matrix with capabilities: wc-settings is refused to editor (no manage_woocommerce)', in_array( 'woocommerce>wc-settings', S::blocked_matrix( $cfg, $tops, array( 'shop_manager', 'editor' ), array( 'administrator' ), $subs, S::role_caps( array( 'shop_manager', 'editor' ) ) )['shop_manager'] ?? array(), true ) === false && in_array( 'woocommerce>wc-settings', S::blocked_matrix( $cfg, $tops, array( 'editor' ), array( 'administrator' ), $subs, S::role_caps( array( 'editor' ) ) )['editor'], true ) );
$tm = S::target_matrix( $cfg, $tops, $subs, array( 'editor', 'administrator' ), array( 'administrator' ) );
$assert( 'target_matrix: a moved sub-item is listed under its new parent, hidden ones are gone', in_array( 'edit.php>my-tool', $tm['editor'], true ) && ! in_array( 'tools.php>my-tool', $tm['editor'], true ) && ! in_array( 'woocommerce>wc-settings', $tm['editor'], true ) && in_array( 'woocommerce>wc-reports', $tm['editor'], true ), $j( $tm['editor'] ) );

/* enforce_access (migration lockout rule) with the new kinds of rules. */
$GLOBALS['uxs_user'] = array( 'roles' => array( 'administrator' ), 'login' => 'root', 'can' => array() );
function current_user_can_stub() {}
$bad = array(
	'sub_hidden' => array( 'plugins.php>plugins.php' => array( 'editor' ), 'users.php>users.php' => array( '*' ), 'options-general.php>options-writing.php' => array( '*' ), 'woocommerce>wc-reports' => array( 'editor' ) ),
	'item_users' => array( 'users.php' => array( 'hide' => array( 'root' ), 'show' => array( 'x' ) ), 'ux-studio' => array( 'hide' => array( 'root' ) ), 'edit.php' => array( 'hide' => array( 'root' ) ) ),
	'item_caps'  => array( 'ux-studio' => 'unknown_cap', 'plugins.php>plugin-install.php' => 'x', 'edit.php' => 'edit_posts' ),
);
$out = S::enforce_access( $bad );
$assert( 'enforce_access: the protected screens lose user-hide rules and required capabilities, other items keep theirs', ! isset( $out['config']['item_users']['ux-studio'] ) && array( 'show' => array( 'x' ) ) === $out['config']['item_users']['users.php'] && isset( $out['config']['item_users']['edit.php'] ) && ! isset( $out['config']['item_caps']['ux-studio'] ) && isset( $out['config']['item_caps']['edit.php'] ) && 'user' === $out['dropped']['users.php'] && 'user' === $out['dropped']['ux-studio'], $j( $out ) );
$assert( 'enforce_access: hiding a protected sub-item from administrators (or everybody) is dropped; a limit that does not reach them stays', ! isset( $out['config']['sub_hidden']['users.php>users.php'] ) && isset( $out['config']['sub_hidden']['woocommerce>wc-reports'] ) && isset( $out['config']['sub_hidden']['options-general.php>options-writing.php'] ), $j( $out['config']['sub_hidden'] ) );
$GLOBALS['uxs_user'] = array( 'roles' => array( 'editor' ), 'login' => 'alice', 'can' => array() );

/* ══ 2. MenuOrganizer: config cleaning and the pure $submenu transformation ══ */

$clean = MenuOrganizer::clean_config(
	array(
		'item_caps'  => array( 'edit.php' => 'edit_others_posts; DROP', 'x' => '', 'woocommerce>wc-settings' => 'manage_woocommerce' ),
		'item_users' => array( 'edit.php' => array( 'hide' => array( 'Bob', 'bob', ' al<b>ice ' ), 'show' => 'carol, dave  erin;bob' ), 'bad' => 'x', 'empty' => array( 'show' => array() ) ),
		'sub_titles' => array( 'tools.php>export.php' => ' <b>Vývoz</b> ', 'tools.php>import.php' => '' ),
		'sub_hidden' => array( 'tools.php>export.php' => array( 'editor', 'ghost', 'author' ), 'tools.php>import.php' => array( 'editor', '*' ), 'x>y' => array( 'ghost' ) ),
		'sub_moves'  => array( 'tools.php>my-tool' => 'edit.php', 'tools.php>export.php' => 'tools.php', 'broken' => 'edit.php', 'a>b' => '' ),
		'sub_order'  => array( 'tools.php' => array( 'export.php', 'tools.php', 'export.php', '' ), 'x' => 'nope' ),
	),
	MenuOrganizer::default_config()
);
$assert( 'clean_config: capability keeps only capability characters, empty ones are dropped', array( 'edit.php' => 'edit_others_postsDROP', 'woocommerce>wc-settings' => 'manage_woocommerce' ) === $clean['item_caps'], $j( $clean['item_caps'] ) );
$assert( 'clean_config: user logins are sanitized, de-duplicated without regard to case, strings are split', array( 'edit.php' => array( 'show' => array( 'carol', 'dave', 'erin', 'bob' ), 'hide' => array( 'Bob', 'alice' ) ) ) === $clean['item_users'], $j( $clean['item_users'] ) );
$assert( 'clean_config: sub titles are plain text, empty ones dropped', array( 'tools.php>export.php' => 'Vývoz' ) === $clean['sub_titles'], $j( $clean['sub_titles'] ) );
$assert( 'clean_config: sub_hidden keeps known roles, "*" wins, unknown roles are dropped', array( 'tools.php>export.php' => array( 'editor', 'author' ), 'tools.php>import.php' => array( '*' ) ) === $clean['sub_hidden'], $j( $clean['sub_hidden'] ) );
$assert( 'clean_config: sub_moves drops a move to the own parent, a key without a parent and an empty target', array( 'tools.php>my-tool' => 'edit.php' ) === $clean['sub_moves'], $j( $clean['sub_moves'] ) );
$assert( 'clean_config: sub_order is de-duplicated', array( 'tools.php' => array( 'export.php', 'tools.php' ) ) === $clean['sub_order'], $j( $clean['sub_order'] ) );
$assert( 'clean_config: the new keys have defaults, an old config without them still loads', array() === MenuOrganizer::default_config()['sub_hidden'] && array() === MenuOrganizer::default_config()['item_users'] && ! MenuOrganizer::has_sub_rules( MenuOrganizer::default_config() ) && ! MenuOrganizer::has_sub_rules( array( 'hidden_items' => array( 'a' ) ) ) );

$wp_submenu = array(
	'tools.php'   => array( array( 'Available Tools', 'edit_posts', 'tools.php' ), array( 'Import', 'import', 'import.php' ), array( 'Export', 'export', 'export.php' ), array( 'My tool', 'read', 'my-tool' ) ),
	'edit.php'    => array( array( 'All Posts', 'edit_posts', 'edit.php' ), array( 'Add New', 'edit_posts', 'post-new.php' ) ),
	'woocommerce' => array( array( 'Orders', 'read', 'wc-orders' ), array( 'Settings', 'read', 'wc-settings' ) ),
);
$tcfg = array_merge(
	MenuOrganizer::default_config(),
	array(
		'sub_titles' => array( 'tools.php>export.php' => 'Vývoz', 'woocommerce>wc-orders' => 'Objednávky' ),
		'sub_hidden' => array( 'tools.php>import.php' => array( 'editor' ), 'woocommerce>wc-settings' => array( '*' ) ),
		'sub_moves'  => array( 'tools.php>my-tool' => 'edit.php' ),
		'sub_order'  => array( 'edit.php' => array( 'post-new.php', 'edit.php' ) ),
	)
);
$res = MenuOrganizer::transform_submenu( $wp_submenu, $tcfg, array( 'tools.php', 'edit.php', 'woocommerce' ), array( 'editor' ), false, $ctx( 'alice' ) );
$sm  = $res['submenu'];
$assert( 'transform: rename, hide for the role and move out of the old parent', array( 'tools.php', 'export.php' ) === array_column( $sm['tools.php'], 2 ) && 'Vývoz' === $sm['tools.php'][1][0], $j( $sm['tools.php'] ) );
$assert( 'transform: moved sub-item gets the address of the page it opens (plugin page of a core parent), appended to the new parent', 'tools.php?page=my-tool' === end( $sm['edit.php'] )[2] && 3 === count( $sm['edit.php'] ), $j( $sm['edit.php'] ) );
$assert( 'transform: order of the sub-items of a parent; unlisted ones follow in their own order', array( 'post-new.php', 'edit.php', 'tools.php?page=my-tool' ) === array_column( $sm['edit.php'], 2 ), $j( array_column( $sm['edit.php'], 2 ) ) );
$assert( 'transform: sub-item hidden for "*" disappears; a parent left without sub-items is removed from the array; the rename of Orders stays', array( 'wc-orders' ) === array_column( $sm['woocommerce'], 2 ) && 'Objednávky' === $sm['woocommerce'][0][0] && isset( MenuOrganizer::transform_submenu( array( 'a' => array( array( 'X', 'read', 'x' ) ) ), array_merge( MenuOrganizer::default_config(), array( 'sub_hidden' => array( 'a>x' => array( '*' ) ) ) ), array( 'a' ), array( 'editor' ), false )['submenu']['a'] ) === false );
$assert( 'transform: the moved map tells where a sub-item went (for the menu highlight)', array( 'tools.php>my-tool' => array( 'parent' => 'edit.php', 'slug' => 'tools.php?page=my-tool', 'orig_parent' => 'tools.php', 'orig_slug' => 'my-tool' ) ) === $res['moved'], $j( $res['moved'] ) );
$res2 = MenuOrganizer::transform_submenu( $wp_submenu, $tcfg, array( 'tools.php', 'edit.php', 'woocommerce' ), array( 'author' ), false, $ctx( 'alice' ) );
$assert( 'transform: the same config for another role keeps Import (the rule names editors only)', in_array( 'import.php', array_column( $res2['submenu']['tools.php'], 2 ), true ) );
$assert( 'transform: nothing to do without rules returns the menu unchanged', $wp_submenu === MenuOrganizer::transform_submenu( $wp_submenu, MenuOrganizer::default_config(), array(), array( 'editor' ), false )['submenu'] );
$assert( 'transform: a move to an unknown parent is ignored (the item stays)', in_array( 'my-tool', array_column( MenuOrganizer::transform_submenu( $wp_submenu, $tcfg, array( 'tools.php' ), array( 'editor' ), false )['submenu']['tools.php'], 2 ), true ) );
$assert( 'sub_url: plugin page under a core file parent, under a plugin parent, a file based slug', 'tools.php?page=a%26b' === MenuOrganizer::sub_url( 'tools.php', 'a&b' ) && 'edit.php?post_type=product&page=attrs' === MenuOrganizer::sub_url( 'edit.php?post_type=product', 'attrs' ) && 'admin.php?page=wc-settings' === MenuOrganizer::sub_url( 'woocommerce', 'wc-settings' ) && 'edit-tags.php?taxonomy=category' === MenuOrganizer::sub_url( 'edit.php', 'edit-tags.php?taxonomy=category' ) );

/* ══ 3. Custom CSS: sanitizer, settings field, output ══ */

$evil = array(
	'</style><script>alert(1)</script>',
	'a{color:red}</STYLE ><img src=x onerror=alert(1)>',
	'< / style><b>x</b>',
	'<</style>/style>',
	'<!-- x --><style>b{}</style>',
	"a{background:url(javascript:alert(1))} b{width:expression(alert(1))} c{behavior:url(x.htc)} d{-moz-binding:url(x)}",
	"a{}\x00</st\x00yle>",
);
$all_safe = true;
foreach ( $evil as $css ) {
	$out = CssSanitizer::clean( $css );
	if ( preg_match( '#</?\s*(style|script)#i', $out ) || false !== stripos( $out, 'javascript:' ) || false !== stripos( $out, 'expression(' ) || false !== stripos( $out, '-moz-binding' ) || false !== strpos( $out, "\0" ) || false !== strpos( $out, '<!--' ) ) {
		$all_safe = false;
		echo 'unsafe: ' . $j( $out ) . PHP_EOL;
	}
}
$assert( 'CssSanitizer: no way out of the style element (closing tags, split tags, comments, null bytes) and no script-in-CSS vectors', $all_safe );
$assert( 'CssSanitizer: a tag-like "<" becomes a CSS escape, text around it stays', '.a::after{content:"\3C b>"}' === CssSanitizer::clean( '.a::after{content:"<b>"}' ), CssSanitizer::clean( '.a::after{content:"<b>"}' ) );
$ok_css = ".a > .b + .c ~ .d { color: red; content: \"x\"; }\n@media (width < 600px) { .e { margin: 0 } }\n@media (min-width: 600px) and (max-width: 900px) { a:hover { color: #fff !important } }";
$assert( 'CssSanitizer: ordinary CSS (child combinators, range media queries) is left alone', $ok_css === CssSanitizer::clean( $ok_css ), CssSanitizer::clean( $ok_css ) );
$big = str_repeat( ".rule-x { color: red; }\n", 5000 );
$cut = CssSanitizer::clean( $big );
$assert( 'CssSanitizer: size limit (50 KB), cut after a whole rule', strlen( $cut ) <= CssSanitizer::MAX_BYTES && '}' === substr( $cut, -1 ) && strlen( $cut ) > 50000, (string) strlen( $cut ) );
$assert( 'CssSanitizer: non-strings and empty input give an empty string; surrounding whitespace is trimmed', '' === CssSanitizer::clean( null ) && '' === CssSanitizer::clean( array( 'x' ) ) && '' === CssSanitizer::clean( "  \n " ) && 'a{}' === CssSanitizer::clean( "\n a{} \n" ) );

$ac_module = ( new ReflectionClass( \UxStudio\Modules\AdminCustomiser\Module::class ) )->newInstanceWithoutConstructor();
$schema    = $ac_module->settings_schema();
$by_key    = array_column( $schema, null, 'key' );
$assert( 'schema: two CSS fields of type css (login page, admin area), in their own section', 'css' === ( $by_key['admin_custom_css']['type'] ?? '' ) && 'css' === ( $by_key['login_custom_css']['type'] ?? '' ) && $by_key['admin_custom_css']['section'] === $by_key['login_custom_css']['section'] );
$cleaned = Settings::clean( array( 'admin_custom_css' => "a{}</style><script>x</script>", 'login_custom_css' => 'body.login{background:#000}', 'login_background_style' => 'zoom', 'login_background_image' => '900', 'login_link_hover_color' => '#abc', 'login_button_hover_bg_color' => 'red' ), $schema );
$assert( 'Settings::clean: css type goes through the sanitizer, select falls back to its default, media is an id, color must be hex', false === strpos( $cleaned['admin_custom_css'], '</style' ) && 'body.login{background:#000}' === $cleaned['login_custom_css'] && 'cover' === $cleaned['login_background_style'] && 900 === $cleaned['login_background_image'] && '#abc' === $cleaned['login_link_hover_color'] && '' === $cleaned['login_button_hover_bg_color'], $j( $cleaned ) );

$GLOBALS['uxs_options']['uxstudio_admin_customiser'] = array( 'admin_custom_css' => "#wpadminbar{display:none}</style><script>alert(1)</script>", 'login_custom_css' => 'body.login{background:#123}' );
ob_start();
CustomCss::print_admin();
$admin_out = ob_get_clean();
ob_start();
CustomCss::print_login();
$login_out = ob_get_clean();
$assert( 'output: the stored value is sanitized once more on the way out (a tampered option cannot break out)', 1 === substr_count( strtolower( $admin_out ), '</style' ) && false === stripos( $admin_out, '<script' ) && false !== strpos( $admin_out, '#wpadminbar{display:none}' ) && 0 === strpos( $admin_out, '<style id="uxstudio-admin-custom-css">' ), $admin_out );
$assert( 'output: login CSS has its own element', 0 === strpos( $login_out, '<style id="uxstudio-login-custom-css">' ) && false !== strpos( $login_out, 'body.login{background:#123}' ) );
$GLOBALS['uxs_options']['uxstudio_admin_customiser'] = array();
ob_start();
CustomCss::print_admin();
CustomCss::print_login();
$assert( 'output: nothing is printed when there is no CSS', '' === ob_get_clean() );
$GLOBALS['uxs_hooks'] = array();
CustomCss::register();
$assert( 'never on the front end: only admin_head and login_head are hooked (no wp_head, no wp_enqueue_scripts)', array( 'admin_head', 'login_head' ) === $GLOBALS['uxs_hooks'] && false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/AdminCustomiser/CustomCss.php' ), "'wp_head'" ) );

/* Login page: background image and hover colours. */
$login_css = static function ( array $values ): string {
	$GLOBALS['uxs_options']['uxstudio_admin_customiser'] = $values;
	ob_start();
	( new Login( new Settings( 'uxstudio_admin_customiser' ) ) )->print_styles();
	return ob_get_clean();
};
$out = $login_css( array( 'login_background_image' => 900, 'login_background_style' => 'cover', 'login_background_color' => '#111111', 'login_button_hover_bg_color' => '#cc0000', 'login_button_hover_text_color' => '#ffffff', 'login_link_hover_color' => '#00cc00' ) );
$assert( 'login CSS: background image with the chosen fit, hover colours for the button and the links', false !== strpos( $out, 'body.login { background-image: url("https://example.test/uploads/bg.jpg?x=1&y=2"); background-size: cover;' ) && false !== strpos( $out, '.wp-core-ui .button-primary:hover, body.login .wp-core-ui .button-primary:focus { background-color: #cc0000 !important; }' ) && false !== strpos( $out, 'color: #ffffff !important;' ) && false !== strpos( $out, 'body.login #nav a:hover' ) && false !== strpos( $out, '{ color: #00cc00 !important; }' ), $out );
foreach ( array( 'contain' => 'background-size: contain', 'center' => 'background-size: auto; background-repeat: no-repeat', 'repeat' => 'background-repeat: repeat' ) as $style => $needle ) {
	$assert( "login CSS: background fit \"$style\"", false !== strpos( $login_css( array( 'login_background_image' => 900, 'login_background_style' => $style ) ), $needle ) );
}
$assert( 'login CSS: the background image URL cannot end the url("...") string or the style element', false === strpos( $login_css( array( 'login_background_image' => 901 ) ), '"<' ) && 1 === preg_match( '#url\("https://example.test/uploads/evil\.jpg"\)#', $login_css( array( 'login_background_image' => 901 ) ) ) );
$assert( 'login CSS: the split layout paints its own panel, so the background image is left out; no image = no rule', false === strpos( $login_css( array( 'login_background_image' => 900, 'login_style' => 'split' ) ), 'bg.jpg' ) && false === strpos( $login_css( array( 'login_background_color' => '#111111' ) ), 'background-image' ) );

/* ══ 4. Per-role rules: meta boxes, dashboard widgets, welcome panel ══ */

$assert( 'RoleRules: a user is hidden an id only when every role hides it', array( 'a' ) === RoleRules::hidden_for( array( 'editor' => array( 'a', 'b' ), 'author' => array( 'a' ) ), array( 'editor', 'author' ) ) && array( 'a', 'b' ) === RoleRules::hidden_for( array( 'editor' => array( 'a', 'b' ) ), array( 'editor' ) ) && array() === RoleRules::hidden_for( array( 'editor' => array( 'a' ) ), array( 'editor', 'subscriber' ) ) && array() === RoleRules::hidden_for( array( 'editor' => array( 'a' ) ), array() ) );
$assert( 'RoleRules::applies_to: empty list = everybody', RoleRules::applies_to( array(), array( 'editor' ) ) && RoleRules::applies_to( array( 'editor' ), array( 'author', 'editor' ) ) && ! RoleRules::applies_to( array( 'editor' ), array( 'author' ) ) && ! RoleRules::applies_to( array( 'editor' ), array() ) );

$by_role = array( 'editor' => array( 'post|authordiv', 'post|postcustom', 'page|authordiv', 'post|submitdiv' ), 'author' => array( 'post|authordiv' ) );
$assert( 'MetaBoxRules: boxes of one post type hidden for a role; the Publish box is never hidden', array( 'authordiv', 'postcustom' ) === MetaBoxRules::boxes_to_hide( $by_role, array( 'editor' ), 'post' ) && array( 'authordiv' ) === MetaBoxRules::boxes_to_hide( $by_role, array( 'editor' ), 'page' ) && ! in_array( 'submitdiv', MetaBoxRules::boxes_to_hide( $by_role, array( 'editor' ), 'post' ), true ) );
$assert( 'MetaBoxRules: several roles hide only what all hide; unknown role hides nothing', array( 'authordiv' ) === MetaBoxRules::boxes_to_hide( $by_role, array( 'editor', 'author' ), 'post' ) && array() === MetaBoxRules::boxes_to_hide( $by_role, array( 'subscriber' ), 'post' ) );
$cat = MetaBoxRules::merge_catalog( array( 'post' => array( 'a' => 'A' ) ), 'post', array( 'b' => 'B', 'submitdiv' => 'Publish', '' => 'x' ) );
$assert( 'MetaBoxRules::merge_catalog: union of what was seen, without the Publish box', array( 'post' => array( 'a' => 'A', 'b' => 'B' ) ) === $cat, $j( $cat ) );
$opts = MetaBoxRules::options();
$assert( 'MetaBoxRules::options: core boxes are known before any edit screen was opened, the Publish box is not offered', isset( $opts['post|authordiv'] ) && isset( $opts['page|pageparentdiv'] ) && ! isset( $opts['post|submitdiv'] ), $j( array_keys( $opts ) ) );
$GLOBALS['uxs_options'][ MetaBoxRules::CATALOG_OPTION ] = array( 'product' => array( 'woocommerce-product-data' => 'Product data' ) );
$assert( 'MetaBoxRules::options: captured boxes of plugins are added (and the stored catalog survives)', isset( MetaBoxRules::options()['product|woocommerce-product-data'] ) && isset( MetaBoxRules::options()['post|authordiv'] ) );
$GLOBALS['uxs_options'] = array();
$schema2 = $ac_module->settings_schema();
$mb_keys = array_values( array_filter( array_column( $schema2, 'key' ), static fn( $k ) => 0 === strpos( $k, MetaBoxRules::KEY_PREFIX ) ) );
$assert( 'schema: one meta box field per role of the site (5 roles), multi-select, in section "Meta boxes"', 5 === count( $mb_keys ) && 'hidden_metaboxes_editor' === $mb_keys[1] && 'multiselect' === array_column( $schema2, null, 'key' )['hidden_metaboxes_editor']['type'], $j( $mb_keys ) );

$dw_schema = RoleWidgets::schema( array( 'dashboard_primary' => 'News', 'dashboard_activity' => 'Activity' ), MenuOrganizer::role_list() );
$dw_keys   = array_column( $dw_schema, 'key' );
$assert( 'RoleWidgets: one multi-select per role plus the welcome panel fields', in_array( 'hidden_widgets_editor', $dw_keys, true ) && 5 === count( array_filter( $dw_keys, static fn( $k ) => 0 === strpos( $k, 'hidden_widgets_' ) ) ) && in_array( 'welcome_panel_content', $dw_keys, true ) && 'richtext' === array_column( $dw_schema, null, 'key' )['welcome_panel_content']['type'], $j( $dw_keys ) );
$GLOBALS['uxs_options']['uxstudio_dashboard_widgets'] = array( 'hidden_widgets_editor' => array( 'dashboard_primary', 'dashboard_activity' ), 'hidden_widgets_author' => array( 'dashboard_primary' ) );
$rules = RoleWidgets::rules( new Settings( 'uxstudio_dashboard_widgets' ), MenuOrganizer::role_list() );
$assert( 'RoleWidgets: hidden per role from the settings; several roles = hidden by all of them', array( 'editor' => array( 'dashboard_primary', 'dashboard_activity' ), 'author' => array( 'dashboard_primary' ) ) === $rules && array( 'dashboard_primary' ) === RoleWidgets::hidden_for( $rules, array( 'editor', 'author' ) ) && array() === RoleWidgets::hidden_for( $rules, array( 'editor', 'subscriber' ) ), $j( $rules ) );
$wp = array( 'welcome_panel_enabled' => true, 'welcome_panel_content' => '<p>Hello</p>', 'welcome_panel_roles' => array( 'editor' ) );
$assert( 'welcome panel: shown to the listed roles only, to everybody when no role is listed, never when off or empty', RoleWidgets::welcome_visible( $wp, array( 'editor' ) ) && ! RoleWidgets::welcome_visible( $wp, array( 'author' ) ) && RoleWidgets::welcome_visible( array_merge( $wp, array( 'welcome_panel_roles' => array() ) ), array( 'author' ) ) && ! RoleWidgets::welcome_visible( array_merge( $wp, array( 'welcome_panel_enabled' => false ) ), array( 'editor' ) ) && ! RoleWidgets::welcome_visible( array_merge( $wp, array( 'welcome_panel_content' => '<p> </p>' ) ), array( 'editor' ) ) && RoleWidgets::welcome_visible( array_merge( $wp, array( 'welcome_panel_content' => '<img src="https://x.test/a.png">' ) ), array( 'editor' ) ) );
$GLOBALS['uxs_options']['uxstudio_dashboard_widgets'] = array( 'welcome_panel_content' => '<p>Hi</p><script>bad()</script>' );
ob_start();
RoleWidgets::render_welcome();
$out = ob_get_clean();
$assert( 'welcome panel output goes through wp_kses_post again', false !== strpos( $out, 'Hi' ) && false === strpos( $out, '<script' ), $out );
$cleaned = Settings::clean( array( 'welcome_panel_content' => '<p>x</p><script>bad()</script>' ), array( array( 'key' => 'welcome_panel_content', 'type' => 'richtext' ) ) );
$assert( 'welcome panel content is sanitized on save too (richtext field)', false === strpos( $cleaned['welcome_panel_content'], '<script' ) );
$GLOBALS['uxs_options'] = array();

/* ══ 5. Admin Columns: sorting and filtering ══ */

$cols = array(
	array( 'key' => 'price', 'label' => 'Cena', 'type' => 'meta', 'field_type' => 'number', 'enabled' => true, 'sortable' => true, 'filterable' => true ),
	array( 'key' => 'color', 'label' => 'Barva', 'type' => 'meta', 'field_type' => 'text', 'enabled' => true, 'sortable' => true, 'filterable' => true ),
	array( 'key' => 'topic', 'label' => 'Téma', 'type' => 'taxonomy', 'field_type' => 'text', 'enabled' => true, 'sortable' => true, 'filterable' => true ),
	array( 'key' => 'item_id', 'label' => 'ID', 'type' => 'post_id', 'field_type' => 'text', 'enabled' => true, 'sortable' => true, 'filterable' => false ),
	array( 'key' => 'author', 'label' => 'Autor', 'type' => 'default', 'field_type' => 'text', 'enabled' => true, 'sortable' => true, 'filterable' => true ),
	array( 'key' => 'categories', 'label' => 'Rubriky', 'type' => 'default', 'field_type' => 'text', 'enabled' => true, 'sortable' => true, 'filterable' => true ),
	array( 'key' => 'date', 'label' => 'Datum', 'type' => 'default', 'field_type' => 'text', 'enabled' => true, 'sortable' => true, 'filterable' => true ),
	array( 'key' => 'off', 'label' => 'Off', 'type' => 'meta', 'field_type' => 'text', 'enabled' => false, 'sortable' => true, 'filterable' => true ),
	array( 'key' => 'plain', 'label' => 'Plain', 'type' => 'meta', 'field_type' => 'text', 'enabled' => true, 'sortable' => false, 'filterable' => false ),
	array( 'key' => 'thumb', 'label' => 'Thumb', 'type' => 'thumbnail', 'field_type' => 'text', 'enabled' => true, 'sortable' => true, 'filterable' => true ),
);
$map = Q::sortable_map( $cols );
$assert( 'sortable_map: meta, taxonomy, ID, author and category columns get a clickable header; native date, disabled, switched-off and thumbnail columns do not', array( 'price', 'color', 'topic', 'item_id', 'author', 'categories' ) === array_keys( $map ) && array( 'uxs_price', false ) === $map['price'], $j( $map ) );
$assert( 'sort_spec: only an orderby with our prefix that belongs to a sortable column', null === Q::sort_spec( $cols, 'title' ) && null === Q::sort_spec( $cols, 'uxs_plain' ) && null === Q::sort_spec( $cols, 'uxs_off' ) && 'meta' === Q::sort_spec( $cols, 'uxs_price' )['kind'] && 'NUMERIC' === Q::sort_spec( $cols, 'uxs_price' )['meta_type'] && 'CHAR' === Q::sort_spec( $cols, 'uxs_color' )['meta_type'] && 'category' === Q::sort_spec( $cols, 'uxs_categories' )['key'] && 'taxonomy' === Q::sort_spec( $cols, 'uxs_categories' )['kind'] );
$v = Q::sort_vars( array( 'meta_query' => array( 'relation' => 'AND', array( 'key' => 'z' ) ) ), Q::sort_spec( $cols, 'uxs_price' ), 'desc' );
$assert( 'sort_vars (meta): leaves the query alone (no meta_key / meta_query trick, WordPress sorts such posts by another meta row), only a harmless orderby and the direction', 'date' === $v['orderby'] && 'DESC' === $v['order'] && array( 'relation' => 'AND', array( 'key' => 'z' ) ) === $v['meta_query'], $j( $v ) );
$tables0 = array( 'posts' => 'wp_posts', 'postmeta' => 'wp_postmeta', 'users' => 'wp_users', 'term_relationships' => 'wp_term_relationships', 'term_taxonomy' => 'wp_term_taxonomy', 'terms' => 'wp_terms' );
$sql0    = Q::order_sql( Q::sort_spec( $cols, 'uxs_price' ), 'desc', $tables0, static fn( string $x ): string => "'" . addslashes( $x ) . "'" );
$assert( 'order_sql (meta, numeric): subquery on the post own field row, cast to a number, posts without the field last in both directions, tie-break by ID', false !== strpos( $sql0, "uxs_pm.meta_key = 'price'" ) && false !== strpos( $sql0, 'uxs_pm.post_id = wp_posts.ID' ) && false !== strpos( $sql0, 'CAST(uxs_pm.meta_value AS DECIMAL(30,10))' ) && false !== strpos( $sql0, ' IS NULL ASC, ' ) && ' DESC, wp_posts.ID DESC' === substr( $sql0, -23 ) , $sql0 );
$sql0 = Q::order_sql( Q::sort_spec( $cols, 'uxs_color' ), 'asc', $tables0, static fn( string $x ): string => "'" . addslashes( $x ) . "'" );
$assert( 'order_sql (meta, text): sorted as text, no cast', false === strpos( $sql0, 'CAST' ) && false !== strpos( $sql0, 'uxs_pm.meta_value FROM' ), $sql0 );
$assert( 'sort_vars (ID): order by the post ID; taxonomy and author are done in SQL', array( 'ID' => 'ASC' ) === Q::sort_vars( array(), Q::sort_spec( $cols, 'uxs_item_id' ), 'asc' )['orderby'] && 'date' === Q::sort_vars( array(), Q::sort_spec( $cols, 'uxs_topic' ), 'asc' )['orderby'] && 'ASC' === Q::direction( 'weird' ) && 'DESC' === Q::direction( 'DESC' ) );
$tables = array( 'posts' => 'wp_posts', 'postmeta' => 'wp_postmeta', 'users' => 'wp_users', 'term_relationships' => 'wp_term_relationships', 'term_taxonomy' => 'wp_term_taxonomy', 'terms' => 'wp_terms' );
$quote  = static fn( string $s ): string => "'" . addslashes( $s ) . "'";
$sql    = Q::order_sql( Q::sort_spec( $cols, 'uxs_topic' ), 'desc', $tables, $quote );
$assert( 'order_sql (taxonomy): first term name via a subquery on the post ID, the taxonomy quoted, tie-break by ID', false !== strpos( $sql, 'SELECT MIN(uxs_t.name)' ) && false !== strpos( $sql, "uxs_tt.taxonomy = 'topic'" ) && false !== strpos( $sql, 'uxs_tr.object_id = wp_posts.ID' ) && ' DESC, wp_posts.ID DESC' === substr( $sql, -23 ) , $sql );
$sql = Q::order_sql( array( 'kind' => 'taxonomy', 'key' => "x' OR 1=1 --" ), 'asc', $tables, $quote );
$assert( 'order_sql: a taxonomy name cannot break out of its quotes', false !== strpos( $sql, "'x\\' OR 1=1 --'" ), $sql );
$assert( 'order_sql (author): by display name; the ID kind gives nothing (plain WP_Query order)', false !== strpos( Q::order_sql( Q::sort_spec( $cols, 'uxs_author' ), 'asc', $tables, $quote ), 'uxs_u.display_name' ) && '' === Q::order_sql( Q::sort_spec( $cols, 'uxs_item_id' ), 'asc', $tables, $quote ) );
$fv = Q::filter_vars( $cols, array( 'uxs_f_color' => 'red', 'uxs_f_topic' => '12', 'uxs_f_author' => '5', 'uxs_f_categories' => '3', 'uxs_f_off' => 'x', 'uxs_f_item_id' => '9', 'uxs_f_date' => '2026-01' ), array() );
$assert( 'filter_vars: custom field equals, taxonomy by term id, author by id, categories by term id; switched-off, non-filterable and date columns are ignored', 'color' === $fv['meta_query'][0]['key'] && 'red' === $fv['meta_query'][0]['value'] && '=' === $fv['meta_query'][0]['compare'] && 1 === count( array_filter( $fv['meta_query'], 'is_array' ) ) && 5 === $fv['author'] && 'topic' === $fv['tax_query'][0]['taxonomy'] && array( 12 ) === $fv['tax_query'][0]['terms'] && 'category' === $fv['tax_query'][1]['taxonomy'] && array( 3 ) === $fv['tax_query'][1]['terms'], $j( $fv ) );
$assert( 'filter_vars: empty and "0" ("all") values add nothing; "0" is a real value for a custom field; arrays are ignored; existing queries are extended', array() === Q::filter_vars( $cols, array( 'uxs_f_topic' => '0', 'uxs_f_color' => '', 'uxs_f_author' => array( '1' ) ), array() ) && '0' === Q::filter_vars( $cols, array( 'uxs_f_color' => '0' ), array() )['meta_query'][0]['value'] && 'z' === Q::filter_vars( $cols, array( 'uxs_f_color' => 'red' ), array( 'meta_query' => array( 'relation' => 'AND', array( 'key' => 'z' ) ) ) )['meta_query'][0]['key'] );
$assert( 'can_sort / can_filter (used by the migration): meta, taxonomy, author, ID, native title; not thumbnail, not date filtering', Q::can_sort( array( 'key' => 'k', 'type' => 'meta' ) ) && Q::can_sort( array( 'key' => 'title', 'type' => 'default' ) ) && ! Q::can_sort( array( 'key' => 'f', 'type' => 'thumbnail' ) ) && Q::can_filter( array( 'key' => 'k', 'type' => 'meta' ) ) && Q::can_filter( array( 'key' => 'author', 'type' => 'default' ) ) && ! Q::can_filter( array( 'key' => 'date', 'type' => 'default' ) ) && ! Q::can_filter( array( 'key' => 'item_id', 'type' => 'post_id' ) ) );
$assert( 'wanted(): nothing is hooked for a list without sort / filter flags (or only on disabled columns)', ! Q::wanted( array( array( 'key' => 'a', 'enabled' => true ), array( 'key' => 'b', 'enabled' => false, 'sortable' => true ) ) ) && Q::wanted( $cols ) );
$norm = \UxStudio\Modules\AdminColumns\Module::clean_column( array( 'key' => 'Price', 'sortable' => '1', 'filterable' => 0, 'type' => 'meta' ) );
$assert( 'clean_column: sortable / filterable are booleans that round-trip, default false', true === $norm['sortable'] && false === $norm['filterable'] && false === \UxStudio\Modules\AdminColumns\Module::clean_column( array( 'key' => 'k' ) )['sortable'] && $norm === \UxStudio\Modules\AdminColumns\Module::clean_column( $norm ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
