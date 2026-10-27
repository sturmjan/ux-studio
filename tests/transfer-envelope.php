<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the settings export file format (PLAN.md 29): the envelope, checksum, version handling,
 * size and depth limits, the whitelist by schema and the registry of what never travels.
 *
 *   php tests/transfer-envelope.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

function __( $text, $domain = '' ) { return $text; }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Transfer\Envelope;
use UxStudio\Core\Transfer\NonPortable;
use UxStudio\Core\Transfer\Planner;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$modules = array(
	'redirects'   => array(
		'enabled'      => true,
		'settings'     => array( 'monitor_slugs' => true, 'permanent_cache_ttl' => 3600, 'ratio' => 1.5 ),
		'needs_manual' => array(),
	),
	'smtp-email'  => array(
		'enabled'      => false,
		'settings'     => array(),
		'needs_manual' => array( 'password' ),
	),
);
$env = Envelope::build(
	array(
		'plugin_version'  => '0.8.0',
		'exported_at'     => '2026-10-26T10:00:00+00:00',
		'site_name'       => 'Příklad',
		'site_host'       => 'example.test',
		'include_enabled' => true,
		'include_data'    => false,
		'modules'         => $modules,
		'secrets'         => null,
	)
);

/* ── Build, serialise, parse ── */

$assert( 'format and version are set', Envelope::FORMAT === $env['format'] && 1 === $env['format_version'] );
$assert( 'checksum has the sha256: prefix and 64 hex digits', 1 === preg_match( '/^sha256:[0-9a-f]{64}$/', $env['checksum'] ) );
$text = Envelope::encode( $env );
$assert( 'the file is pretty printed and ends with a newline', false !== strpos( $text, "\n    \"format\"" ) || false !== strpos( $text, "\n\t\"format\"" ) || false !== strpos( $text, "\n  \"format\"" ) );
$assert( 'unicode is written as is (not \\u escapes)', false !== strpos( $text, 'Příklad' ) );
$assert( 'an empty settings object is an object in the file', false !== strpos( $text, '"settings": {}' ) );
$assert( 'exact types are kept in the text (true, 3600, 1.5)', false !== strpos( $text, '"monitor_slugs": true' ) && false !== strpos( $text, '"permanent_cache_ttl": 3600' ) && false !== strpos( $text, '1.5' ) );

$parsed = Envelope::parse( $text, '0.8.0' );
$assert( 'the file we wrote parses', $parsed['ok'], $parsed['message'] );
$assert( 'a clean round trip has no warnings', array() === $parsed['warnings'], json_encode( $parsed['warnings'] ) );
$assert( 'the round trip keeps the types', true === $parsed['envelope']['modules']['redirects']['settings']['monitor_slugs'] && 3600 === $parsed['envelope']['modules']['redirects']['settings']['permanent_cache_ttl'] && 1.5 === $parsed['envelope']['modules']['redirects']['settings']['ratio'] );
$assert( 'the round trip keeps the enabled flags', true === $parsed['envelope']['modules']['redirects']['enabled'] && false === $parsed['envelope']['modules']['smtp-email']['enabled'] );
$assert( 'needs_manual comes back', array( 'password' ) === $parsed['envelope']['modules']['smtp-email']['needs_manual'] );
$assert( 'site info comes back', 'example.test' === $parsed['envelope']['site']['host'] );

/* ── Checksum ── */

$assert( 'the checksum does not depend on the order of keys', Envelope::checksum( array( 'b' => array( 'x' => 1, 'a' => 2 ), 'a' => 1 ), null ) === Envelope::checksum( array( 'a' => 1, 'b' => array( 'a' => 2, 'x' => 1 ) ), null ) );
$assert( 'the checksum depends on the values', Envelope::checksum( array( 'a' => 1 ), null ) !== Envelope::checksum( array( 'a' => 2 ), null ) );
$assert( 'the checksum depends on the secrets block', Envelope::checksum( array(), null ) !== Envelope::checksum( array(), array( 'kdf' => 'argon2id' ) ) );
$tampered = str_replace( '"permanent_cache_ttl": 3600', '"permanent_cache_ttl": 7200', $text );
$p2       = Envelope::parse( $tampered, '0.8.0' );
$assert( 'a changed file still parses, with a checksum warning (not a block)', $p2['ok'] && 'checksum' === ( $p2['warnings'][0]['code'] ?? '' ) );
$no_sum = json_decode( $text, true );
unset( $no_sum['checksum'] );
$p3 = Envelope::parse( json_encode( $no_sum ), '0.8.0' );
$assert( 'a file without checksum parses with a warning', $p3['ok'] && 'checksum' === ( $p3['warnings'][0]['code'] ?? '' ) );
$assert( 'an empty array in modules does not change the checksum between export and import', Envelope::parse( Envelope::encode( Envelope::build( array( 'modules' => array( 'a' => array( 'settings' => array(), 'needs_manual' => array() ) ) ) ) ) )['warnings'] === array() );

/* ── Versions ── */

$newer = json_decode( $text, true );
$newer['format_version'] = 2;
$p = Envelope::parse( json_encode( $newer ), '0.8.0' );
$assert( 'a newer format version is refused with a clear message', ! $p['ok'] && 'too_new' === $p['error'] && false !== strpos( $p['message'], '2' ), $p['message'] );
$older = json_decode( $text, true );
$older['format_version'] = 0;
$assert( 'version 0 is not valid', ! Envelope::parse( json_encode( $older ), '0.8.0' )['ok'] );
$str_ver = json_decode( $text, true );
$str_ver['format_version'] = '1';
$assert( 'the version must be an integer', ! Envelope::parse( json_encode( $str_ver ), '0.8.0' )['ok'] );
$newplugin = json_decode( $text, true );
$newplugin['plugin_version'] = '0.9.0';
$p = Envelope::parse( json_encode( $newplugin ), '0.8.0' );
$assert( 'a file from a newer plugin is accepted with a note', $p['ok'] && 'newer_plugin' === $p['warnings'][0]['code'] );
$oldplugin = json_decode( $text, true );
$oldplugin['plugin_version'] = '0.5.0';
$p = Envelope::parse( json_encode( $oldplugin ), '0.8.0' );
$assert( 'a file from an older plugin is accepted (only the checksum warns because we edited it)', $p['ok'] && ! in_array( 'newer_plugin', array_column( $p['warnings'], 'code' ), true ) );

/* ── Limits and shapes ── */

$assert( 'not JSON is refused', 'json' === Envelope::parse( 'hello', '' )['error'] );
$assert( 'empty text is refused', 'empty' === Envelope::parse( "  \n", '' )['error'] );
$assert( 'invalid UTF-8 is refused', 'encoding' === Envelope::parse( "{\"a\":\"\xB1\"}", '' )['error'] );
$assert( 'a UTF-8 byte order mark is tolerated', Envelope::parse( "\xEF\xBB\xBF" . $text, '0.8.0' )['ok'] );
$assert( 'over 2 MB is refused', 'too_large' === Envelope::parse( str_repeat( ' ', Envelope::MAX_BYTES + 1 ), '' )['error'] );
$deep = str_repeat( '[', 40 ) . str_repeat( ']', 40 );
$assert( 'nesting deeper than 32 is refused', 'depth' === Envelope::parse( $deep, '' )['error'] );
$ok_depth = json_decode( $text, true );
$assert( 'a normal export is far below the depth limit', Envelope::parse( json_encode( $ok_depth ), '' )['ok'] );
$assert( 'a JSON list is not an export', 'shape' === Envelope::parse( '[1,2]', '' )['error'] );
$assert( 'a JSON scalar is not an export', in_array( Envelope::parse( '42', '' )['error'], array( 'shape' ), true ) );
$assert( 'another JSON document is refused by its format field', 'format' === Envelope::parse( '{"format":"other"}', '' )['error'] );
$bad_id = json_decode( $text, true );
$bad_id['modules']['../evil'] = array();
$assert( 'a module id that is not a plain id is refused', 'module' === Envelope::parse( json_encode( $bad_id ), '' )['error'] );
$bad_enabled = json_decode( $text, true );
$bad_enabled['modules']['redirects']['enabled'] = 'yes';
$assert( 'enabled must be a boolean', 'module' === Envelope::parse( json_encode( $bad_enabled ), '' )['error'] );
$bad_settings = json_decode( $text, true );
$bad_settings['modules']['redirects']['settings'] = array( 1, 2 );
$assert( 'settings must be an object, not a list', 'module' === Envelope::parse( json_encode( $bad_settings ), '' )['error'] );
$bad_secrets = json_decode( $text, true );
$bad_secrets['secrets'] = array( 'kdf' => 'argon2id' );
$assert( 'a damaged secrets block is refused', 'secrets' === Envelope::parse( json_encode( $bad_secrets ), '' )['error'] );
$assert( 'serialized PHP is just not JSON (never unserialized)', 'json' === Envelope::parse( 'O:8:"stdClass":0:{}', '' )['error'] );
$assert( 'unknown top level fields are ignored, not trusted', ! array_key_exists( 'evil', Envelope::parse( json_encode( $ok_depth + array( 'evil' => 1 ) ), '' )['envelope'] ?? array() ) );

/* ── Whitelist by schema ── */

$schema = array(
	array( 'key' => 'monitor_slugs', 'type' => 'toggle', 'label' => 'Monitor', 'default' => true ),
	array( 'key' => 'ttl', 'type' => 'number', 'label' => 'TTL', 'default' => 60 ),
	array( 'key' => 'style', 'type' => 'select', 'label' => 'Style', 'options' => array( 'a' => 'A', 'b' => 'B' ), 'default' => 'a' ),
	array( 'key' => 'node_api_key', 'type' => 'text', 'label' => 'Key' ),
	array( 'key' => 'central_app_url', 'type' => 'text', 'label' => 'URL' ),
);
$in = Planner::incoming(
	'content-sync',
	$schema,
	array(
		'monitor_slugs'   => true,
		'ttl'             => 30,
		'style'           => 'b',
		'node_api_key'    => 'SECRET-KEY-VALUE',
		'central_app_url' => 'https://hub.example',
		'rogue_option'    => 'x',
		'uxstudio_wizard_state' => 'done',
	)
);
$assert( 'schema keys that may travel are accepted', array( 'monitor_slugs' => true, 'ttl' => 30, 'style' => 'b' ) === $in['accepted'], json_encode( $in['accepted'] ) );
$assert( 'keys that are not in the schema are dropped as unknown', array( 'rogue_option', 'uxstudio_wizard_state' ) === $in['unknown'] );
$assert( 'non-portable keys of the schema are refused even when the file has them', array( 'node_api_key', 'central_app_url' ) === $in['non_portable'] );
$assert( 'no non-portable value reaches the accepted set', ! in_array( 'SECRET-KEY-VALUE', $in['accepted'], true ) );


$assert( 'export types: a toggle stored as 1 is exported as true', true === Planner::typed( 1, array( 'type' => 'toggle' ) ) && false === Planner::typed( '', array( 'type' => 'toggle' ) ) );
$assert( 'export types: a list stored as one text becomes a list', array( 'a' ) === Planner::typed( 'a', array( 'type' => 'multiselect' ) ) && array() === Planner::typed( '', array( 'type' => 'multiselect' ) ) && array( '1', '2' ) === Planner::typed( array( 1, 2 ), array( 'type' => 'multiselect' ) ) );
$assert( 'export types: a number stored as text is a number', 5 === Planner::typed( '5', array( 'type' => 'number' ) ) && 1.5 === Planner::typed( '1.5', array( 'type' => 'number' ) ) && null === Planner::typed( 'abc', array( 'type' => 'number' ) ) );
$assert( 'export types: a choice the site does not offer is not exported, one it offers is', null === Planner::typed( 'old-model', array( 'type' => 'select', 'options' => array( 'a' => 'A' ) ) ) && 'a' === Planner::typed( 'a', array( 'type' => 'select', 'options' => array( 'a' => 'A' ) ) ) );
$assert( 'export types: unknown items of a list are left out', array( 'a' ) === Planner::typed( array( 'a', 'zzz' ), array( 'type' => 'multiselect', 'options' => array( 'a' => 'A', 'b' => 'B' ) ) ) );
$assert( 'export types: text and choices are strings', '3' === Planner::typed( 3, array( 'type' => 'select' ) ) && null === Planner::typed( array( 'x' ), array( 'type' => 'text' ) ) );

/* ── Registry of what never travels ── */

$content_sync = NonPortable::classify( 'content-sync', $schema );
$assert( 'Central App key, URL and HMAC are never portable', in_array( 'node_api_key', array_keys( $content_sync['manual'] ), true ) && in_array( 'central_app_url', array_keys( $content_sync['manual'] ), true ) );
foreach ( array( 'node_api_key', 'central_app_url', 'hmac_secret', 'node_allowed_ips', 'mode', 'node_default_author' ) as $key ) {
	$assert( "content-sync.$key is on the never list", ! NonPortable::is_portable( 'content-sync', array( 'key' => $key, 'type' => 'text' ) ) );
}
$assert( 'a credential-looking key that is not registered is not portable', ! NonPortable::is_portable( 'some-module', array( 'key' => 'my_api_key', 'type' => 'text' ) ) );
$assert( 'a registered portable secret is not exported as a clear setting', ! NonPortable::is_portable( 'smtp-email', array( 'key' => 'password', 'type' => 'text' ) ) && NonPortable::is_secret( 'smtp-email', 'password' ) );
$assert( 'a harmless key that only looks like a credential travels', NonPortable::is_portable( 'ai-assistant', array( 'key' => 'monthly_token_limit', 'type' => 'number' ) ) && NonPortable::is_portable( 'maintenance-mode', array( 'key' => 'bypass_ips', 'type' => 'textarea' ) ) );
$assert( 'page and user ids are never portable', ! NonPortable::is_portable( 'security-optimization', array( 'key' => 'existing_page', 'type' => 'select' ) ) && ! NonPortable::is_portable( 'dns-manager', array( 'key' => 'manage_user_ids', 'type' => 'multiselect' ) ) );
$assert( 'CAPTCHA keys of a host name are never portable', ! NonPortable::is_portable( 'security-optimization', array( 'key' => 'captcha_site_key', 'type' => 'text' ) ) && ! NonPortable::is_portable( 'security-optimization', array( 'key' => 'captcha_secret_key', 'type' => 'text' ) ) );
$assert( 'secret options of a module are all in the uxstudio_secret_ namespace', (function () {
	foreach ( NonPortable::SECRET_OPTIONS as $fields ) {
		foreach ( $fields as $option ) {
			if ( 0 !== strpos( $option, 'uxstudio_secret_' ) ) {
				return false;
			}
		}
	}
	return true;
})() );
foreach ( array( 'uxstudio_wizard_done', 'uxstudio_login_url_pending', 'uxstudio_import_snapshot', 'uxstudio_starter_snapshot', 'uxstudio_migration_snapshot_r1', 'uxstudio_module_db_versions', 'uxstudio_modules_meta_cache', 'uxstudio_secret_smtp_password', 'uxstudio_csp_state', 'uxstudio_security_findings_cache', 'uxstudio_rl_5', 'uxstudio_mcp_tokens', 'uxstudio_content_sync_state' ) as $option ) {
	$assert( "$option is a state / secret option", NonPortable::is_state_option( $option ) );
}
foreach ( array( 'uxstudio_redirects', 'uxstudio_security_optimization' ) as $option ) {
	$assert( "$option (module settings) is not a state option", ! NonPortable::is_state_option( $option ) );
}
$assert( 'a sealed value is recognised in nested data', NonPortable::is_sealed( array( 'a' => array( 'uxs1:abcd' ) ) ) && ! NonPortable::is_sealed( 'plain' ) );

/* ── Media ── */

$field = array( 'key' => 'login_logo', 'type' => 'media' );
$exp   = Planner::export_value( 42, $field, static fn( int $id ): string => 'https://a.test/wp-content/uploads/logo.png' );
$assert( 'an attachment id is exported with its URL', $exp['include'] && 42 === $exp['value']['__media']['id'] && 'https://a.test/wp-content/uploads/logo.png' === $exp['value']['__media']['url'] );
$assert( 'an attachment that no longer exists is left out', ! Planner::export_value( 42, $field, static fn( int $id ): string => '' )['include'] );
$assert( 'no image is exported as 0', 0 === Planner::export_value( 0, $field, null )['value'] );
$found = Planner::resolve_media( array( '__media' => array( 'id' => 42, 'url' => 'https://b.test/uploads/logo.png' ) ), static fn( string $u ): int => 'https://b.test/uploads/logo.png' === $u ? 7 : 0 );
$assert( 'the id of the file is ignored: the destination id comes from the URL', 7 === $found['value'] && ! $found['missing'] );
$gone = Planner::resolve_media( array( '__media' => array( 'id' => 42, 'url' => 'https://b.test/other.png' ) ), static fn( string $u ): int => 0 );
$assert( 'a URL that is not in the media library is reported missing, never an id', $gone['missing'] && 0 === $gone['value'] );
$in = Planner::incoming( 'x', array( $field ), array( 'login_logo' => array( '__media' => array( 'id' => 5, 'url' => 'https://nope.test/a.png' ) ) ), static fn( string $u ): int => 0 );
$assert( 'a missing image is not accepted and is listed', array() === $in['accepted'] && array( 'login_logo' ) === $in['media_missing'] );
$in = Planner::incoming( 'x', array( $field ), array( 'login_logo' => 5 ), static fn( string $u ): int => 0 );
$assert( 'a bare attachment id from a file is rejected', array() === $in['accepted'] && isset( $in['rejected']['login_logo'] ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
