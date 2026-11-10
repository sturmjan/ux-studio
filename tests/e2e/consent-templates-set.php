<?php
// Test only (WP-CLI eval-file, local test site): sets the `template` setting of the consent module from the UXC_TPL
// environment variable ("DELETE" removes the saved settings) and purges the page cache. Used by consent-templates.mjs.
if ( ! defined( 'WP_CLI' ) ) { exit; }
$tpl = getenv( 'UXC_TPL' ) ?: 'classic';
$o   = get_option( 'uxstudio_consent', array() );
$o   = is_array( $o ) ? $o : array();
if ( 'DELETE' === $tpl ) {
	delete_option( 'uxstudio_consent' );
} else {
	$o['template'] = $tpl;
	update_option( 'uxstudio_consent', $o );
}
if ( function_exists( 'rocket_clean_domain' ) ) {
	rocket_clean_domain();
}
echo "template: $tpl\n";
