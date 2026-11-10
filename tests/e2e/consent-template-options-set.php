<?php
// Test only (WP-CLI eval-file, local test site): replaces the saved settings of the consent module by the JSON in the
// UXC_OPTS environment variable ("DELETE" removes them) and purges the page cache. Used by consent-template-options.mjs.
if ( ! defined( 'WP_CLI' ) ) { exit; }
$raw = getenv( 'UXC_OPTS' ) ?: '{}';
if ( 'DELETE' === $raw ) {
	delete_option( 'uxstudio_consent' );
} else {
	update_option( 'uxstudio_consent', (array) json_decode( $raw, true ) );
}
if ( function_exists( 'rocket_clean_domain' ) ) {
	rocket_clean_domain();
}
echo "ok\n";
