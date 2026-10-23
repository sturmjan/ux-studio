<?php
/**
 * Standalone test of the Menu Visibility rules (PLAN.md 27.6): login state and roles, several rules per item
 * (all must hold), backward compatible storage, children of hidden items.
 *
 *   php tests/menu-visibility-rules.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/Modules/MenuVisibility/Rules.php';

use UxStudio\Modules\MenuVisibility\Rules as R;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$j = static fn( $v ): string => (string) json_encode( $v );

$rule = static fn( string $t, array $r = array() ): array => array( 't' => $t, 'r' => $r );

/* ── Evaluation: visitors x rules ── */
$visitors = array(
	'guest'      => array( false, array() ),
	'subscriber' => array( true, array( 'subscriber' ) ),
	'editor'     => array( true, array( 'editor' ) ),
	'shop'       => array( true, array( 'shop_manager' ) ),
	'multi'      => array( true, array( 'editor', 'shop_manager' ) ),
);
$cases = array(
	'no rules'                           => array( array(), array( 'guest' => true, 'subscriber' => true, 'editor' => true, 'shop' => true, 'multi' => true ) ),
	'logged in'                          => array( array( $rule( 'in' ) ), array( 'guest' => false, 'subscriber' => true, 'editor' => true, 'shop' => true, 'multi' => true ) ),
	'logged out'                         => array( array( $rule( 'out' ) ), array( 'guest' => true, 'subscriber' => false, 'editor' => false, 'shop' => false, 'multi' => false ) ),
	'only roles editor'                  => array( array( $rule( 'role', array( 'editor' ) ) ), array( 'guest' => false, 'subscriber' => false, 'editor' => true, 'shop' => false, 'multi' => true ) ),
	'only roles editor or shop_manager'  => array( array( $rule( 'role', array( 'editor', 'shop_manager' ) ) ), array( 'guest' => false, 'subscriber' => false, 'editor' => true, 'shop' => true, 'multi' => true ) ),
	'hide from subscriber'               => array( array( $rule( 'not_role', array( 'subscriber' ) ) ), array( 'guest' => true, 'subscriber' => false, 'editor' => true, 'shop' => true, 'multi' => true ) ),
	'hide from editor'                   => array( array( $rule( 'not_role', array( 'editor' ) ) ), array( 'guest' => true, 'subscriber' => true, 'editor' => false, 'shop' => true, 'multi' => false ) ),
	'logged in AND hide from subscriber' => array( array( $rule( 'in' ), $rule( 'not_role', array( 'subscriber' ) ) ), array( 'guest' => false, 'subscriber' => false, 'editor' => true, 'shop' => true, 'multi' => true ) ),
	'logged in AND role editor'          => array( array( $rule( 'in' ), $rule( 'role', array( 'editor' ) ) ), array( 'guest' => false, 'subscriber' => false, 'editor' => true, 'shop' => false, 'multi' => true ) ),
	'role editor AND role shop_manager'  => array( array( $rule( 'role', array( 'editor' ) ), $rule( 'role', array( 'shop_manager' ) ) ), array( 'guest' => false, 'subscriber' => false, 'editor' => false, 'shop' => false, 'multi' => true ) ),
	'logged out AND logged in = never'   => array( array( $rule( 'in' ), $rule( 'out' ) ), array( 'guest' => false, 'subscriber' => false, 'editor' => false, 'shop' => false, 'multi' => false ) ),
	'logged out AND role = never'        => array( array( $rule( 'out' ), $rule( 'role', array( 'editor' ) ) ), array( 'guest' => false, 'subscriber' => false, 'editor' => false, 'shop' => false, 'multi' => false ) ),
	'logged out AND hide from editor'    => array( array( $rule( 'out' ), $rule( 'not_role', array( 'editor' ) ) ), array( 'guest' => true, 'subscriber' => false, 'editor' => false, 'shop' => false, 'multi' => false ) ),
);
foreach ( $cases as $name => $case ) {
	$rules = R::normalize( $case[0] );
	foreach ( $case[1] as $who => $expect ) {
		$assert( "$name: $who " . ( $expect ? 'sees' : 'does not see' ), $expect === R::passes( $rules, $visitors[ $who ][0], $visitors[ $who ][1] ) );
	}
}

/* ── Normalization ── */
$assert( 'normalize: junk is dropped', array() === R::normalize( 'x' ) && array() === R::normalize( null ) && array() === R::normalize( array( 'a', 5, array( 't' => 'nope' ) ) ) );
$assert( 'normalize: a role rule without roles says nothing', array() === R::normalize( array( $rule( 'role', array() ), $rule( 'not_role', array( '', '!!' ) ) ) ) );
$assert( 'normalize: role slugs are cleaned, de-duplicated and sorted', array( array( 'type' => 'role', 'roles' => array( 'editor', 'shop_manager' ) ) ) === R::normalize( array( array( 'type' => 'role', 'roles' => array( 'Shop_Manager', 'editor', 'editor', '!!' ) ) ) ), $j( R::normalize( array( array( 'type' => 'role', 'roles' => array( 'Shop_Manager', 'editor', 'editor', '!!' ) ) ) ) ) );
$assert( 'normalize: unknown roles are removed when the site roles are given', array( array( 'type' => 'role', 'roles' => array( 'editor' ) ) ) === R::normalize( array( $rule( 'role', array( 'editor', 'ghost' ) ) ), array( 'editor', 'author' ) ) );
$assert( 'normalize: duplicate rules collapse, order is canonical', R::normalize( array( $rule( 'not_role', array( 'a' ) ), $rule( 'in' ), $rule( 'in' ) ) ) === R::normalize( array( $rule( 'in' ), $rule( 'not_role', array( 'a' ) ) ) ) );
$assert( 'normalize: at most ' . R::MAX_RULES . ' rules', R::MAX_RULES === count( R::normalize( array_map( static fn( $i ) => $rule( 'role', array( 'r' . $i ) ), range( 1, 20 ) ) ) ) );
$assert( 'normalize: both spellings (type/roles and t/r) are read', R::normalize( array( array( 'type' => 'role', 'roles' => array( 'editor' ) ) ) ) === R::normalize( array( $rule( 'role', array( 'editor' ) ) ) ) );

/* ── Storage: backward compatible ── */
$assert( 'meta: the legacy value 1 means logged in', 'in' === R::from_meta( '1', '' )[0]['type'] );
$assert( 'meta: the legacy value 2 means logged out', 'out' === R::from_meta( '2', '' )[0]['type'] );
$assert( 'meta: nothing means everybody', array() === R::from_meta( '', '' ) && array() === R::from_meta( '9', '' ) );
$assert( 'meta: logged in alone is stored as the old value 1 (older code keeps working)', array( 'visible' => '1', 'rules' => '' ) === R::to_meta( R::normalize( array( $rule( 'in' ) ) ) ) );
$assert( 'meta: logged out alone is stored as 2', array( 'visible' => '2', 'rules' => '' ) === R::to_meta( R::normalize( array( $rule( 'out' ) ) ) ) );
$assert( 'meta: no rules stores nothing', array( 'visible' => '', 'rules' => '' ) === R::to_meta( array() ) );
$many = R::normalize( array( $rule( 'in' ), $rule( 'role', array( 'editor' ) ) ) );
$m    = R::to_meta( $many );
$assert( 'meta: several rules go to the rules JSON only', '' === $m['visible'] && '' !== $m['rules'] && $m['rules'] === '[{"t":"in"},{"t":"role","r":["editor"]}]', $j( $m ) );
$assert( 'meta: the JSON reads back to the same rules (round trip)', $many === R::from_meta( '', $m['rules'] ) );
$assert( 'meta: the rules JSON wins over the old value', 'role' === R::from_meta( '1', '[{"t":"role","r":["editor"]}]' )[0]['type'] );
$assert( 'meta: a broken JSON falls back to the old value', 'in' === R::from_meta( '1', '{not json' )[0]['type'] );
$assert( 'meta: the stored JSON has no backslash (update_post_meta unslashes)', false === strpos( $m['rules'], '\\' ) );
$assert( 'same: equal meaning, different order', R::same( array( $rule( 'in' ), $rule( 'role', array( 'b', 'a' ) ) ), array( $rule( 'role', array( 'a', 'b' ) ), $rule( 'in' ) ) ) );
$assert( 'same: different roles differ', ! R::same( array( $rule( 'role', array( 'a' ) ) ), array( $rule( 'role', array( 'b' ) ) ) ) );

/* ── Children of hidden items go with them ── */
$items = array(
	array( 'id' => 1, 'parent' => 0 ),
	array( 'id' => 2, 'parent' => 1 ),
	array( 'id' => 3, 'parent' => 2 ),
	array( 'id' => 4, 'parent' => 0 ),
	array( 'id' => 5, 'parent' => 4 ),
);
$assert( 'keep: nothing hidden keeps all', array( 1, 2, 3, 4, 5 ) === R::keep( $items, array( 1 => true, 2 => true ) ) );
$assert( 'keep: a hidden parent takes its children and grandchildren', array( 4, 5 ) === R::keep( $items, array( 1 => false ) ), $j( R::keep( $items, array( 1 => false ) ) ) );
$assert( 'keep: a hidden child hides only its own branch', array( 1, 2, 4, 5 ) === R::keep( $items, array( 3 => false ) ) );
$assert( 'keep: a visible child of a hidden item is hidden too', array( 1, 4, 5 ) === R::keep( $items, array( 2 => false, 3 => true ) ) );
$assert( 'keep: a parent cycle does not hang', is_array( R::keep( array( array( 'id' => 1, 'parent' => 2 ), array( 'id' => 2, 'parent' => 1 ) ), array() ) ) );

/* ── The module and the assets ── */
$module = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/MenuVisibility/Module.php' );
$assert( 'module: server side filter and the rules meta key are wired', false !== strpos( $module, "'wp_get_nav_menu_items'" ) && false !== strpos( $module, '_uxstudio_menu_item_rules' ) );
$assert( 'module: the new meta keys are the ones the migration writes', 1 === preg_match( "/RULES_KEY\s*=\s*'_uxstudio_menu_item_rules'/", $module ) && 1 === preg_match( "/META_KEY\s*=\s*'_uxstudio_menu_item_visible'/", $module ) );
$assert( 'assets: the script and style exist', is_file( dirname( __DIR__ ) . '/includes/Modules/MenuVisibility/assets/menu-visibility.js' ) && is_file( dirname( __DIR__ ) . '/includes/Modules/MenuVisibility/assets/menu-visibility.css' ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
