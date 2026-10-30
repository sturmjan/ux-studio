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

// Base = the handle-named file merged by the last LOCAL run (versioned in the
// repo), then JSONs that belong to a CURRENT build/*.js file override it.
// - Locally (after make-json) the fresh per-chunk files win over the base, and
//   stale per-chunk files left by `make-json --no-purge` (old content hashes)
//   are ignored, so outdated strings can't override the .po.
// - In CI the content hashes may differ from the local build, so no per-chunk
//   file matches - the complete base still lands in the file WP loads.
$current_hashes = array();
foreach ( glob( __DIR__ . '/../build/*.js' ) ?: array() as $f ) {
	$current_hashes[ md5( 'build/' . basename( $f ) ) ] = true;
}

$by_locale = array();
foreach ( glob( $languages . '/ux-studio-*-ux-studio-app.json' ) ?: array() as $file ) {
	if ( preg_match( '/ux-studio-([a-zA-Z_]+)-ux-studio-app\.json$/', basename( $file ), $m ) ) {
		$by_locale[ $m[1] ][] = $file;
	}
}
foreach ( glob( $languages . '/ux-studio-*.json' ) ?: array() as $file ) {
	if ( ! preg_match( '/ux-studio-([a-zA-Z_]+)-([0-9a-f]{32})\.json$/', basename( $file ), $m ) ) {
		continue;
	}
	if ( ! isset( $current_hashes[ $m[2] ] ) ) {
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

	// WordPress checks the handle-named file first (Core\Admin passes the languages path to
	// wp_set_script_translations), so this single file is all the SPA ever loads. The md5-named files of the
	// chunks (make-json output) are only inputs here and are not committed or shipped.
	$target = $languages . "/ux-studio-{$locale}-ux-studio-app.json";
	file_put_contents( $target, wp_json_encode_fallback( $merged ) );
	fwrite( STDOUT, "merged {$locale}: " . count( $merged['locale_data']['messages'] ) . " messages -> ux-studio-{$locale}-ux-studio-app.json\n" );
}

/**
 * json_encode without WP, keeping unicode readable.
 *
 * @param mixed $data Data.
 */
function wp_json_encode_fallback( $data ): string {
	return (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
