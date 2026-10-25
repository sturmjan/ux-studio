<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the plugin-overlap catalog, detection and activation guard
 * (PLAN.md 26.5). Needs no WordPress and no database:
 *
 *   php tests/overlap-catalog.php
 *
 * WordPress functions are stubbed below. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_filters'] = array();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}

function get_option( $name, $default = false ) { return $GLOBALS['uxs_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['uxs_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function __( $text, $domain = '' ) { return $text; }

$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register(); // The refusal asks SourceRegistry whether a plugin can be migrated.
require_once $root . '/includes/Core/Migration/Catalog.php';
require_once $root . '/includes/Core/Migration/Overlaps.php';
require_once $root . '/includes/Core/Migration/ActivationGuard.php';

use UxStudio\Core\Migration\ActivationGuard;
use UxStudio\Core\Migration\Catalog;
use UxStudio\Core\Migration\Overlaps;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/** Reset the fake site: active plugins, enabled modules, module settings. */
$site = static function ( array $plugins, array $modules, array $settings = array() ): void {
	$GLOBALS['uxs_options']                = array(
		'active_plugins'         => $plugins,
		'uxstudio_active_modules' => $modules,
	);
	$GLOBALS['uxs_filters']                = array();
	Overlaps::set_foreign_reader( null );
	Overlaps::set_settings_resolver( static fn( string $id ): array => $settings[ $id ] ?? array() );
};

/* ── Catalog integrity ── */

$ids  = array();
$dirs = array();
foreach ( glob( $root . '/includes/Modules/*/meta.json' ) as $file ) {
	$meta = json_decode( (string) file_get_contents( $file ), true );
	if ( is_array( $meta ) && ! empty( $meta['id'] ) ) {
		$ids[ $meta['id'] ]  = true;
		$dirs[ $meta['id'] ] = dirname( $file );
	}
}
$entries = Catalog::entries();
$assert( 'catalog is not empty', count( $entries ) > 40, (string) count( $entries ) );

$missing = array();
$badtype = array();
$badslug = array();
$seen    = array();
$dupes   = array();
$nokey   = array();
foreach ( $entries as $e ) {
	if ( Catalog::ANY_MODULE !== $e['module'] && ! isset( $ids[ $e['module'] ] ) ) {
		$missing[] = $e['module'];
	}
	if ( ! in_array( $e['type'], array( Catalog::TYPE_FULL, Catalog::TYPE_PARTIAL ), true ) ) {
		$badtype[] = $e['module'];
	}
	foreach ( $e['plugins'] as $slug => $label ) {
		$slug = (string) $slug;
		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-_]*$/', $slug ) || '' === trim( (string) $label ) ) {
			$badslug[] = $slug;
		}
		// The same plugin may legitimately appear under different conditions of one
		// module only if the conditions differ - none of the current entries do.
		$k = $e['module'] . '|' . $slug;
		if ( isset( $seen[ $k ] ) ) {
			$dupes[] = $k;
		}
		$seen[ $k ] = true;
	}
	// Every setting key in `when` must exist in that module's code.
	foreach ( $e['when'] ?? array() as $key ) {
		$dir  = $dirs[ $e['module'] ] ?? '';
		$code = $dir && is_readable( $dir . '/Module.php' ) ? (string) file_get_contents( $dir . '/Module.php' ) : '';
		if ( ! preg_match( "/'key'\s*=>\s*'" . preg_quote( $key, '/' ) . "'/", $code ) ) {
			$nokey[] = $e['module'] . '.' . $key;
		}
	}
}
$assert( 'all target modules exist as folders with meta.json', array() === $missing, implode( ',', $missing ) );
$assert( 'entry types are full|partial', array() === $badtype, implode( ',', $badtype ) );
$assert( 'plugin slugs are well formed and labelled', array() === $badslug, implode( ',', $badslug ) );
$assert( 'no duplicate plugin slug per module', array() === $dupes, implode( ',', $dupes ) );
$assert( 'every `when` key exists in the module code', array() === $nokey, implode( ',', $nokey ) );

$slug_module = array();
foreach ( $entries as $e ) {
	foreach ( array_keys( $e['plugins'] ) as $slug ) {
		$slug_module[ (string) $slug ][] = $e['module'];
	}
}
$assert( 'ux1-wordpress-customizer is not in the catalog (Ux1Lock owns it)', ! isset( $slug_module['ux1-wordpress-customizer'] ) );
$assert( 'WP Extended is a full wildcard entry', array( '*' ) === ( $slug_module['wpextended'] ?? array() ) );
$assert( 'admin-customiser is partial with confirmation', (bool) array_filter( $entries, static fn( $e ) => 'admin-customiser' === $e['module'] && 'partial' === $e['type'] && ! empty( $e['confirm'] ) ) );
$assert( 'slug_of handles folders and single files', 'wpforms-lite' === Catalog::slug_of( 'wpforms-lite/wpforms.php' ) && 'hello' === Catalog::slug_of( 'hello.php' ) );

/* ── Detection ── */

$site( array( 'classic-editor/classic-editor.php', 'wp-crontrol/wp-crontrol.php' ), array( 'activity-log' ) );
$assert( 'no overlap when the modules are off', array() === Overlaps::current() );
$p = Overlaps::for_module( 'classic-editor' );
$assert( 'potential overlap found for a disabled module', 1 === count( $p ) && 'classic-editor/classic-editor.php' === $p[0]['basename'] && 'full' === $p[0]['group'], json_encode( $p ) );

$site( array( 'classic-editor/classic-editor.php' ), array( 'classic-editor' ) );
$c = Overlaps::current();
$assert( 'enabled module + active plugin = current overlap', 1 === count( $c ) && 'classic-editor/classic-editor.php>classic-editor' === $c[0]['key'], json_encode( $c ) );

$site( array( 'wpforms-lite/wpforms.php' ), array( 'forms' ) );
$c = Overlaps::current();
$assert( 'folder match: wpforms-lite/wpforms.php => forms (partial)', 1 === count( $c ) && 'partial' === $c[0]['group'], json_encode( $c ) );

$site( array( 'wpforms/wpforms.php' ), array( 'forms' ) );
$assert( 'folder match is exact (wpforms != wpforms-lite)', array() === Overlaps::current() );

$site( array( 'wp-crontrol/wp-crontrol.php' ), array( 'cron-control', 'activity-log' ) );
$assert( 'second plugin of another module', 1 === count( Overlaps::current() ) );

// Condition on module settings.
$site( array( 'disable-comments/disable-comments.php' ), array( 'security-optimization' ), array( 'security-optimization' => array( 'disable_comments' => false ) ) );
$assert( 'security-optimization without disable_comments => no overlap', array() === Overlaps::current() );
$site( array( 'disable-comments/disable-comments.php' ), array( 'security-optimization' ), array( 'security-optimization' => array( 'disable_comments' => true ) ) );
$c = Overlaps::current();
$assert( 'security-optimization with disable_comments => full overlap', 1 === count( $c ) && 'full' === $c[0]['group'] && 'Disable comments' === $c[0]['feature'], json_encode( $c ) );
$site( array( 'wps-hide-login/wps-hide-login.php' ), array( 'security-optimization' ), array( 'security-optimization' => array( 'login_url_enabled' => true ) ) );
$assert( 'any of the `when` keys is enough (login_url_enabled)', 1 === count( Overlaps::current() ) );
$site( array( 'wordfence/wordfence.php' ), array( 'security-optimization' ), array() );
$c = Overlaps::current();
$assert( 'Wordfence overlaps security-optimization as partial (no condition)', 1 === count( $c ) && 'partial' === $c[0]['group'], json_encode( $c ) );
$site( array( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ), array( 'security-optimization' ), array( 'security-optimization' => array( 'force_https' => false ) ) );
$assert( 'Really Simple SSL needs an HTTPS setting on', array() === Overlaps::current() );

// Wildcard.
$site( array( 'wpextended/wpextended.php' ), array(), array() );
$assert( 'WP Extended: nothing while no module is enabled', array() === Overlaps::current() );
$site( array( 'wpextended/wpextended.php' ), array( 'activity-log' ), array() );
$c = Overlaps::current();
$assert( 'WP Extended: overlap once any module is enabled', 1 === count( $c ) && '*' === $c[0]['module'], json_encode( $c ) );
$assert( 'WP Extended blocks enabling any module', 'blocked' === ActivationGuard::decide( array( 'cron-control' ) )['result'] );

// Admin Customiser.
$site( array( 'admin-menu-editor/menu-editor.php', 'adminimize/adminimize.php' ), array( 'admin-customiser' ) );
$g = Overlaps::grouped();
$assert( 'admin-customiser has its own group', 2 === count( $g['customiser'] ) && array() === $g['full'] && array() === $g['partial'], json_encode( $g ) );

// Ignore list.
Overlaps::ignore( array( 'admin-menu-editor/menu-editor.php>admin-customiser', 'not a key!' ) );
Overlaps::flush();
$g = Overlaps::grouped();
$assert( 'ignored pair moves to `ignored`, junk key is rejected', 1 === count( $g['customiser'] ) && 1 === count( $g['ignored'] ) && 1 === count( Overlaps::ignored_keys() ), json_encode( $g ) );
Overlaps::unignore( array( 'admin-menu-editor/menu-editor.php>admin-customiser' ) );
Overlaps::flush();
$assert( 'unignore restores the pair', 2 === count( Overlaps::grouped()['customiser'] ) && array() === Overlaps::ignored_keys() );

// Network-active (multisite) plugins are only seen when is_multisite() exists.
$assert( 'valid key shapes', Overlaps::is_valid_key( 'a/b.php>x-y' ) && Overlaps::is_valid_key( 'wpextended/wpextended.php>*' ) && ! Overlaps::is_valid_key( 'a b>x' ) );

/* ── Guard decision matrix ── */

$site( array( 'wp-crontrol/wp-crontrol.php' ), array( 'activity-log' ) );
$d = ActivationGuard::decide( array( 'cron-control' ) );
$assert( 'full overlap blocks', 'blocked' === $d['result'] && 1 === count( $d['blocking'] ), json_encode( $d ) );
$e = ActivationGuard::check( array( 'cron-control' ) );
$data = $e instanceof WP_Error ? $e->get_error_data() : array();
$assert( 'refusal is WP_Error uxstudio_plugin_overlap, HTTP 409', $e instanceof WP_Error && 'uxstudio_plugin_overlap' === $e->get_error_code() && 409 === ( $data['status'] ?? 0 ), json_encode( $e ) );
$assert( 'refusal tells the UI the slug and whether the wizard can migrate it (WP Crontrol has a source since F3)', 'wp-crontrol' === ( $data['plugins'][0]['slug'] ?? '' ) && true === ( $data['plugins'][0]['migratable'] ?? null ), json_encode( $data ) );
$assert( 'refusal lists name, basename, type', 'wp-crontrol/wp-crontrol.php' === ( $data['plugins'][0]['basename'] ?? '' ) && 'WP Crontrol' === ( $data['plugins'][0]['name'] ?? '' ) && 'full' === ( $data['plugins'][0]['type'] ?? '' ) && false === ( $data['requires_confirmation'] ?? null ), json_encode( $data ) );
$assert( 'confirm_overlap does NOT override a full overlap', 'blocked' === ActivationGuard::decide( array( 'cron-control' ), true )['result'] );
$assert( 'unrelated module is allowed', 'allow' === ActivationGuard::decide( array( 'activity-log' ) )['result'] );

$site( array( 'admin-menu-editor/menu-editor.php' ), array() );
$d = ActivationGuard::decide( array( 'admin-customiser' ) );
$assert( 'admin-customiser asks for confirmation', 'confirm' === $d['result'], json_encode( $d ) );
$e    = ActivationGuard::check( array( 'admin-customiser' ) );
$data = $e instanceof WP_Error ? $e->get_error_data() : array();
$assert( 'confirmation error is 409 with requires_confirmation', $e instanceof WP_Error && 409 === ( $data['status'] ?? 0 ) && true === ( $data['requires_confirmation'] ?? null ), json_encode( $data ) );
$assert( 'admin-customiser passes with confirm_overlap', 'allow_override' === ActivationGuard::decide( array( 'admin-customiser' ), true )['result'] && null === ActivationGuard::check( array( 'admin-customiser' ), true ) );

$site( array( 'wpforms-lite/wpforms.php', 'wordfence/wordfence.php' ), array() );
$assert( 'partial (group B) never blocks', 'allow' === ActivationGuard::decide( array( 'forms', 'security-optimization' ) )['result'] );

$site( array( 'wp-crontrol/wp-crontrol.php' ), array() );
$GLOBALS['uxs_filters']['uxstudio_overlap_guard_enabled'][] = static fn( $on ) => false;
$assert( 'filter uxstudio_overlap_guard_enabled=false disables the guard', 'allow' === ActivationGuard::decide( array( 'cron-control' ) )['result'] && null === ActivationGuard::check( array( 'cron-control' ) ) );
$GLOBALS['uxs_filters']['uxstudio_overlap_guard_enabled'] = array( static fn( $on, $ids ) => ! in_array( 'cron-control', $ids, true ) );
$assert( 'filter can exempt a single module', 'allow' === ActivationGuard::decide( array( 'cron-control' ) )['result'] );

$site( array( 'disable-comments/disable-comments.php' ), array(), array( 'security-optimization' => array( 'disable_comments' => true ) ) );
$assert( 'conditional entry blocks when the stored setting is on', 'blocked' === ActivationGuard::decide( array( 'security-optimization' ) )['result'] );
$site( array( 'disable-comments/disable-comments.php' ), array(), array( 'security-optimization' => array( 'disable_comments' => false ) ) );
$assert( 'conditional entry allows when the setting is off', 'allow' === ActivationGuard::decide( array( 'security-optimization' ) )['result'] );

$site( array( 'wp-crontrol/wp-crontrol.php', 'admin-menu-editor/menu-editor.php' ), array() );
$assert( 'group of modules: one blocked child blocks the group', 'blocked' === ActivationGuard::decide( array( 'cron-control', 'admin-customiser' ), true )['result'] );

/* ── Image optimizer: other optimisers fully overlap (onboarding starter pack) ── */

foreach ( array(
	'ewww-image-optimizer/ewww-image-optimizer.php',
	'wp-smushit/wp-smush.php',
	'shortpixel-image-optimiser/wp-shortpixel.php',
	'imagify/imagify.php',
	'webp-converter-for-media/webp-converter-for-media.php',
	'optimole-wp/optimole-wp.php',
) as $basename ) {
	$site( array( $basename ), array() );
	$d = ActivationGuard::decide( array( 'image-optimizer' ) );
	$assert( "image-optimizer is blocked by $basename", 'blocked' === $d['result'] && 1 === count( $d['blocking'] ), json_encode( $d ) );
}
$site( array( 'webp-express/webp-express.php' ), array() );
$assert( 'WebP Express stays a note (partial), it does not block', 'allow' === ActivationGuard::decide( array( 'image-optimizer' ) )['result'] && 'partial' === Overlaps::for_module( 'image-optimizer' )[0]['group'] );

// LiteSpeed Cache: only while its image optimisation / WebP replacement is on.
$litespeed = static function ( array $on ): callable {
	return static fn( array $cond ): bool => isset( $cond['option'] ) && in_array( $cond['option'], $on, true );
};
$site( array( 'litespeed-cache/litespeed-cache.php' ), array() );
Overlaps::set_foreign_reader( $litespeed( array() ) );
$assert( 'LiteSpeed Cache with image optimisation off: no overlap at all', array() === Overlaps::for_module( 'image-optimizer' ) && 'allow' === ActivationGuard::decide( array( 'image-optimizer' ) )['result'] );
Overlaps::set_foreign_reader( $litespeed( array( 'litespeed.conf.img_optm-webp' ) ) );
$assert( 'LiteSpeed Cache with WebP replacement on: full overlap', 'blocked' === ActivationGuard::decide( array( 'image-optimizer' ) )['result'] );
Overlaps::set_foreign_reader( $litespeed( array( 'litespeed.conf.img_optm-auto' ) ) );
$assert( 'LiteSpeed Cache with automatic image optimisation on: full overlap', 'blocked' === ActivationGuard::decide( array( 'image-optimizer' ) )['result'] );

// Jetpack: only with the image CDN (Photon) module active.
$site( array( 'jetpack/jetpack.php' ), array() );
Overlaps::set_foreign_reader( static fn( array $c ): bool => false );
$assert( 'Jetpack without the image CDN: no overlap', array() === Overlaps::for_module( 'image-optimizer' ) );
Overlaps::set_foreign_reader( static fn( array $c ): bool => 'jetpack_active_modules' === ( $c['option'] ?? '' ) && in_array( 'photon', $c['contains'] ?? array(), true ) );
$assert( 'Jetpack with the image CDN (photon): full overlap', 'blocked' === ActivationGuard::decide( array( 'image-optimizer' ) )['result'] );

/* ── ai-markdown: llms.txt of an SEO plugin blocks only while that feature is ON ── */

$site( array( 'wordpress-seo/wp-seo.php' ), array( 'ai-markdown' ) );
Overlaps::set_foreign_reader( static fn( array $c ): bool => false );
$c = Overlaps::current();
$assert( 'Yoast SEO with llms.txt off: only a note (partial), it does not block', 1 === count( $c ) && 'partial' === $c[0]['group'] && 'allow' === ActivationGuard::decide( array( 'ai-markdown' ) )['result'], json_encode( $c ) );
Overlaps::set_foreign_reader( static fn( array $c ): bool => 'wpseo' === ( $c['option'] ?? '' ) && 'enable_llms_txt' === ( $c['key'] ?? '' ) );
$c = Overlaps::current();
$assert( 'Yoast SEO with llms.txt on: full overlap, enabling is refused', 1 === count( $c ) && 'full' === $c[0]['group'] && 'blocked' === ActivationGuard::decide( array( 'ai-markdown' ) )['result'], json_encode( $c ) );
Overlaps::set_foreign_reader( static fn( array $c ): bool => isset( $c['file'] ) && 'llms.txt' === $c['file'] );
$assert( 'Yoast SEO: a physical llms.txt in the site root counts as the feature being on', 'blocked' === ActivationGuard::decide( array( 'ai-markdown' ) )['result'] );

$site( array( 'seo-by-rank-math/rank-math.php' ), array() );
Overlaps::set_foreign_reader( static fn( array $c ): bool => false );
$assert( 'Rank Math with llms.txt off: note only', 'allow' === ActivationGuard::decide( array( 'ai-markdown' ) )['result'] && 1 === count( Overlaps::for_module( 'ai-markdown' ) ) );
Overlaps::set_foreign_reader( static fn( array $c ): bool => 'rank_math_modules' === ( $c['option'] ?? '' ) && in_array( 'llms-txt', $c['contains'] ?? array(), true ) );
$assert( 'Rank Math with its llms.txt module on: full overlap', 'blocked' === ActivationGuard::decide( array( 'ai-markdown' ) )['result'] );

$site( array( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ), array() );
Overlaps::set_foreign_reader( static fn( array $c ): bool => false );
$assert( 'All in One SEO without an llms.txt file: note only', 'allow' === ActivationGuard::decide( array( 'ai-markdown' ) )['result'] );
Overlaps::set_foreign_reader( static fn( array $c ): bool => isset( $c['file'] ) );
$assert( 'All in One SEO with an llms.txt file: full overlap', 'blocked' === ActivationGuard::decide( array( 'ai-markdown' ) )['result'] );

$site( array( 'website-llms-txt/website-llms-txt.php' ), array() );
Overlaps::set_foreign_reader( static fn( array $c ): bool => false );
$assert( 'dedicated llms.txt plugins still block without any condition', 'blocked' === ActivationGuard::decide( array( 'ai-markdown' ) )['result'] );

// The real reader: options (plain, array key, JSON, list) and files.
$site( array(), array() );
$GLOBALS['uxs_options']['wpseo']                        = array( 'enable_llms_txt' => true );
$GLOBALS['uxs_options']['litespeed.conf.img_optm-webp'] = '1';
$GLOBALS['uxs_options']['litespeed.conf.img_optm-auto'] = '0';
$GLOBALS['uxs_options']['rank_math_modules']            = array( 'sitemap', 'llms-txt' );
$GLOBALS['uxs_options']['aioseo_options']               = json_encode( array( 'llms' => array( 'enabled' => true ) ) );
$assert( 'foreign option with a key', Overlaps::foreign_condition_met( array( 'option' => 'wpseo', 'key' => 'enable_llms_txt' ) ) && ! Overlaps::foreign_condition_met( array( 'option' => 'wpseo', 'key' => 'other' ) ) );
$GLOBALS['uxs_options']['wpseo'] = array( 'enable_llms_txt' => false );
$assert( 'foreign option key off', ! Overlaps::foreign_condition_met( array( 'option' => 'wpseo', 'key' => 'enable_llms_txt' ) ) );
$assert( 'foreign plain option: "1" is on, "0" is off, missing is off', Overlaps::foreign_condition_met( array( 'option' => 'litespeed.conf.img_optm-webp' ) ) && ! Overlaps::foreign_condition_met( array( 'option' => 'litespeed.conf.img_optm-auto' ) ) && ! Overlaps::foreign_condition_met( array( 'option' => 'nope' ) ) );
$assert( 'foreign list contains', Overlaps::foreign_condition_met( array( 'option' => 'rank_math_modules', 'contains' => array( 'llms-txt' ) ) ) && ! Overlaps::foreign_condition_met( array( 'option' => 'rank_math_modules', 'contains' => array( 'photon' ) ) ) );
$assert( 'foreign JSON option is decoded before the key is looked up', Overlaps::foreign_condition_met( array( 'option' => 'aioseo_options', 'key' => 'llms' ) ) );
$assert( 'a condition without option or file is false', ! Overlaps::foreign_condition_met( array() ) );
$probe = ABSPATH . 'llms.txt';
$assert( 'foreign file missing', ! Overlaps::foreign_condition_met( array( 'file' => 'llms.txt' ) ) );
file_put_contents( $probe, "# test\n" );
$assert( 'foreign file in the site root', Overlaps::foreign_condition_met( array( 'file' => 'llms.txt' ) ) );
unlink( $probe );

$bad = array();
foreach ( Catalog::entries() as $e ) {
	foreach ( $e['foreign_when'] ?? array() as $cond ) {
		if ( ! is_array( $cond ) || ( ! isset( $cond['option'] ) && ! isset( $cond['file'] ) ) ) {
			$bad[] = $e['module'];
		}
	}
	if ( isset( $e['foreign_else'] ) && ! in_array( $e['foreign_else'], array( 'partial', 'none' ), true ) ) {
		$bad[] = $e['module'];
	}
}
$assert( 'every foreign_when condition has an option or a file, foreign_else is partial|none', array() === $bad, implode( ',', $bad ) );

/* ── blocked_settings: what the starter pack must leave out ── */

$site( array(), array() );
$assert( 'blocked_settings: nothing without foreign plugins', array() === Overlaps::blocked_settings( 'security-optimization' ) );
$site( array( 'disable-comments/disable-comments.php', 'wordfence/wordfence.php' ), array() );
$b = Overlaps::blocked_settings( 'security-optimization' );
$assert( 'blocked_settings: Disable Comments blocks disable_comments, Wordfence (partial) blocks nothing', array( 'disable_comments' ) === array_keys( $b ) && array( 'Disable Comments' ) === $b['disable_comments'], json_encode( $b ) );
$site( array( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' ), array() );
$b = Overlaps::blocked_settings( 'security-optimization' );
$assert( 'blocked_settings: a login protection plugin blocks both login limiting and the custom login address', isset( $b['limit_login_attempts'] ) && isset( $b['login_url_enabled'] ), json_encode( $b ) );
$site( array( 'disable-auto-update-email-notifications/x.php' ), array() );
$assert( 'update e-mail plugins are only a note: they never block', array() === Overlaps::blocked_settings( 'security-optimization' ) );


echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
