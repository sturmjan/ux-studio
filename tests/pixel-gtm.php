<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
/**
 * Standalone test (no WordPress) of the Google Tag Manager container in Pixel & Tag Manager: ID validation, the head
 * snippet, the noscript iframe and that a bad ID prints nothing.
 *
 *   php tests/pixel-gtm.php
 *
 * @package UxStudio
 */

// phpcs:disable

namespace UxStudio\Modules {
	abstract class BaseModule {
		public $settings;
	}
}
namespace UxStudio\Modules\Consent {
	final class Gate {
		public static $active = false;
		public static function attrs( string $c, bool $g = false ): string { return self::$active ? ' type="text/plain" data-uxc="' . $c . '"' : ''; }
		public static function active(): bool { return self::$active; }
	}
}
namespace {
	if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $t, $d = '' ) { return $t; }
	function add_action() {}
	function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }

	require_once dirname( __DIR__ ) . '/includes/Modules/PixelTagManager/Module.php';

	$fail = 0;
	function check( string $name, bool $ok ): void {
		global $fail;
		echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
		if ( ! $ok ) { ++$fail; }
	}
	function module( string $gtm ): \UxStudio\Modules\PixelTagManager\Module {
		$ref = new \ReflectionClass( \UxStudio\Modules\PixelTagManager\Module::class );
		$m   = $ref->newInstanceWithoutConstructor();
		$m->settings = new class( $gtm ) {
			public function __construct( private string $gtm ) {}
			public function get( string $k, $d = '' ) { return 'google_tag_manager' === $k ? $this->gtm : $d; }
		};
		return $m;
	}
	function out( callable $f ): string { ob_start(); $f(); return (string) ob_get_clean(); }

	$m = module( ' gtm-ab12cd ' );
	$head = out( fn() => $m->render_tracking_scripts() );
	check( 'head has gtm.js loader', str_contains( $head, 'googletagmanager.com/gtm.js' ) );
	check( 'head has the normalised ID', str_contains( $head, '"GTM-AB12CD"' ) );
	check( 'head is not held back without consent module', ! str_contains( $head, 'text/plain' ) );
	$ns = out( fn() => $m->render_gtm_noscript() );
	check( 'noscript iframe printed', str_contains( $ns, 'ns.html?id=GTM-AB12CD' ) );

	\UxStudio\Modules\Consent\Gate::$active = true;
	check( 'held back when consent banner runs', str_contains( out( fn() => $m->render_tracking_scripts() ), 'data-uxc="statistics marketing"' ) );
	check( 'noscript left out when consent banner runs', '' === out( fn() => $m->render_gtm_noscript() ) );
	\UxStudio\Modules\Consent\Gate::$active = false;

	foreach ( array( 'GTM-', 'GTM-<script>', 'G-ABCDEF', "GTM-AB12\"><x", 'GTM-ab' ) as $bad ) {
		$b = module( $bad );
		check( 'rejects ' . $bad, '' === out( fn() => $b->render_tracking_scripts() ) && '' === out( fn() => $b->render_gtm_noscript() ) );
	}
	check( 'empty prints nothing', '' === out( fn() => module( '' )->render_tracking_scripts() ) );

	exit( $fail ? 1 : 0 );
}
