<?php
/**
 * Merge every per-chunk JS translation file that `wp i18n make-json` produces
 * into the ONE file WordPress actually loads for the SPA (the build/index.js
 * handle). Without this, strings living in lazy-loaded webpack chunks are never
 * translated in the browser, because those chunks aren't registered as WP
 * scripts and WP only auto-loads the main script's JSON.
 *
 * Run AFTER `wp i18n make-json` (locally and in CI):
 *     php bin/merge-json-translations.php
 *
 * Idempotent. Safe to re-run.
 *
 * @package UxStudio
 */

$languages = __DIR__ . '/../languages';
if ( ! is_dir( $languages ) ) {
	fwrite( STDERR, "languages/ not found\n" );
	exit( 1 );
}

// The relative source WP hashes for the enqueued 'ux-studio-app' handle is the
// actual (content-hashed) main bundle path, e.g. build/index.<hash>.js. Resolve
// it so the merged file lands where WP looks.
$index_rel = 'build/index.js';
foreach ( glob( __DIR__ . '/../build/index.*.js' ) ?: array() as $f ) {
	if ( substr( $f, -3 ) === '.js' ) {
		$index_rel = 'build/' . basename( $f );
		break;
	}
}
$main_hash = md5( $index_rel );

$by_locale = array();
foreach ( glob( $languages . '/ux-studio-*.json' ) ?: array() as $file ) {
	if ( ! preg_match( '/ux-studio-([a-zA-Z_]+)-[0-9a-f]{32}\.json$/', basename( $file ), $m )
		&& ! preg_match( '/ux-studio-([a-zA-Z_]+)-ux-studio-app\.json$/', basename( $file ), $m ) ) {
		continue;
	}
	$by_locale[ $m[1] ][] = $file;
}

foreach ( $by_locale as $locale => $files ) {
	$merged = null;
	foreach ( $files as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) || empty( $data['locale_data']['messages'] ) ) {
			continue;
		}
		if ( null === $merged ) {
			$merged = $data;
		} else {
			foreach ( $data['locale_data']['messages'] as $k => $v ) {
				if ( '' === $k ) {
					continue;
				}
				$merged['locale_data']['messages'][ $k ] = $v;
			}
		}
	}
	if ( null === $merged ) {
		continue;
	}

	$targets = array(
		$languages . "/ux-studio-{$locale}-{$main_hash}.json", // what WP loads
		$languages . "/ux-studio-{$locale}-ux-studio-app.json", // handle-named fallback
	);
	foreach ( $targets as $target ) {
		file_put_contents( $target, wp_json_encode_fallback( $merged ) );
	}
	fwrite( STDOUT, "merged {$locale}: " . count( $merged['locale_data']['messages'] ) . " messages -> {$main_hash}\n" );
}

/**
 * json_encode without WP, keeping unicode readable.
 *
 * @param mixed $data Data.
 */
function wp_json_encode_fallback( $data ): string {
	return (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
