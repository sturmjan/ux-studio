<?php
/**
 * Manual test: Redirects targets on custom post types, anchors, slug changes.
 *
 * Run inside a WordPress install that has UX Studio active (so Settings, DB,
 * ... autoload), loading THIS checkout's Redirects classes:
 *
 *   wp eval-file tests/redirects-cpt-target.php --skip-plugins=wp-mail-smtp
 *
 * Exits non-zero when any case fails. It creates a throw-away custom post
 * type post plus a few rules and removes them again (also on failure).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

$dir = dirname( __DIR__ ) . '/includes/Modules/Redirects/';
require_once $dir . 'Url.php';
require_once $dir . 'Repository.php';

use UxStudio\Modules\Redirects\Repository;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

$register = static function (): void {
	register_post_type(
		'uxs_sluzba',
		array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'uxs-sluzby' ),
		)
	);
};

$register();
flush_rewrite_rules( false );

$post_id = wp_insert_post(
	array(
		'post_type'   => 'uxs_sluzba',
		'post_status' => 'publish',
		'post_title'  => 'UXS test sluzba',
		'post_name'   => 'neco',
	)
);
$rule_ids = array();

try {
	$pretty = home_url( '/uxs-sluzby/neco/' );
	$assert( 'fixture permalink is pretty', get_permalink( $post_id ) === $pretty, (string) get_permalink( $post_id ) );

	// 1. Text target on a CPT, no post id: used as entered.
	$text_rule = array( 'code' => 301, 'target_post_id' => 0, 'target' => '/uxs-sluzby/neco/' );
	$assert( 'text target without id resolves to the stored path', Repository::resolve_target( $text_rule ) === $pretty, Repository::resolve_target( $text_rule ) );

	// 2. Rule bound to the id, CPT not registered yet (Engine ran early):
	// must NOT leak "?p=ID", and must give the same URL as once registered.
	$saved = Repository::save( array( 'source' => '/uxs-stary-slug', 'target' => '/uxs-sluzby/neco/' ) );
	$rule_ids[] = (int) $saved['id'];
	$rule       = Repository::get( (int) $saved['id'] );
	$assert( 'internal target is bound to the post id', (int) $rule['target_post_id'] === (int) $post_id, (string) $rule['target_post_id'] );

	unregister_post_type( 'uxs_sluzba' );
	$early = Repository::resolve_target( $rule );
	$assert( 'unregistered CPT: no "?p=" in target', ! str_contains( $early, '?p=' ) && ! str_contains( $early, '&p=' ), $early );
	$assert( 'unregistered CPT: pretty stored target', $early === $pretty, $early );
	$register();
	$late = Repository::resolve_target( $rule );
	$assert( 'registered CPT: same pretty URL (single hop)', $late === $pretty && $late === $early, $late );

	// 3. Anchor / query stay plain text targets.
	$saved = Repository::save( array( 'source' => '/uxs-kotva', 'target' => '/uxs-sluzby/neco/#kontakt' ) );
	$rule_ids[] = (int) $saved['id'];
	$rule       = Repository::get( (int) $saved['id'] );
	$assert( 'anchor target is not bound to a post', 0 === (int) $rule['target_post_id'], (string) $rule['target_post_id'] );
	$assert( 'anchor target keeps "#kontakt"', '/uxs-sluzby/neco/#kontakt' === $rule['target'] && home_url( '/uxs-sluzby/neco/#kontakt' ) === Repository::resolve_target( $rule ), Repository::resolve_target( $rule ) );

	$saved = Repository::save( array( 'source' => '/uxs-dotaz', 'target' => '/uxs-sluzby/neco/?a=1' ) );
	$rule_ids[] = (int) $saved['id'];
	$rule       = Repository::get( (int) $saved['id'] );
	$assert( 'query target is not bound to a post', 0 === (int) $rule['target_post_id'] && home_url( '/uxs-sluzby/neco/?a=1' ) === Repository::resolve_target( $rule ), Repository::resolve_target( $rule ) );

	// Legacy rule: bound to the post AND carrying an anchor in the target.
	$legacy = array( 'code' => 301, 'target_post_id' => $post_id, 'target' => '/uxs-sluzby/neco/#kontakt' );
	$assert( 'legacy bound rule re-appends the anchor', home_url( '/uxs-sluzby/neco/#kontakt' ) === Repository::resolve_target( $legacy ), Repository::resolve_target( $legacy ) );

	// 4. Target slug change: the bound rule follows and is re-saved with the
	// new path, so even before the CPT exists the visitor gets one hop.
	$rule = Repository::get( $rule_ids[0] );
	wp_update_post( array( 'ID' => $post_id, 'post_name' => 'jine' ) );
	$new = home_url( '/uxs-sluzby/jine/' );
	$assert( 'slug change: bound rule follows the new permalink', Repository::resolve_target( $rule ) === $new, Repository::resolve_target( $rule ) );
	Repository::save( array( 'id' => $rule_ids[0] ) );
	$rule = Repository::get( $rule_ids[0] );
	$assert( 'slug change: re-saved rule stores the new path', '/uxs-sluzby/jine/' === $rule['target'], (string) $rule['target'] );
	unregister_post_type( 'uxs_sluzba' );
	$assert( 'slug change: unregistered CPT still gives one pretty hop', Repository::resolve_target( $rule ) === $new, Repository::resolve_target( $rule ) );
	$register();
} finally {
	foreach ( $rule_ids as $rid ) {
		Repository::delete( array( $rid ) );
	}
	// Rule auto-created by the slug monitor for the old URL, if it ran.
	Repository::delete_by_source_path( '/uxs-sluzby/neco' );
	wp_delete_post( (int) $post_id, true );
	unregister_post_type( 'uxs_sluzba' );
	flush_rewrite_rules( false );
}

if ( $failures ) {
	WP_CLI::error( $failures . ' case(s) failed.' );
}
WP_CLI::success( 'All cases passed.' );
