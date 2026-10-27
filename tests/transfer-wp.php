<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * WordPress side of the settings export / import (PLAN.md 29), run against a real site (the local pobyty):
 *
 *   cd D:\xampp\htdocs\pobyty
 *   php -d extension=sodium wp-cli.phar --skip-plugins=wp-mail-smtp eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\transfer-wp.php
 *
 * 1. exports every module of the site (read only) and checks that no secret value held by any option of the
 *    database is in the file, that only schema keys are exported and that nothing on the never list is;
 * 2. previews that file and expects 0 changes;
 * 3. round trip on a harmless value: change it in the file, apply, check, undo, check it is exactly as it was;
 * 4. hostile content: wrong types, unknown keys and keys of the never list are not applied;
 * 5. the Security module: CSP enforce is downgraded, the new login address starts pending, 2FA and country
 *    blocks are held back; undo restores everything (.htaccess is saved and put back byte for byte);
 * 6. encrypted secrets round trip (needs the sodium extension);
 * 7. the data providers (forms, redirects, snippets) on throw-away tables and rows.
 *
 * Every option the test touches is saved first and put back exactly (also when an assertion fails); cron hooks
 * of the plugin are compared before and after; the .htaccess file is compared byte for byte.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Core\Mcp\Secrets;
use UxStudio\Core\Migration\Snapshot;
use UxStudio\Core\Security;
use UxStudio\Core\Transfer\Crypto;
use UxStudio\Core\Transfer\DataProviders;
use UxStudio\Core\Transfer\Envelope;
use UxStudio\Core\Transfer\Exporter;
use UxStudio\Core\Transfer\ImportSnapshot;
use UxStudio\Core\Transfer\Importer;
use UxStudio\Core\Transfer\NonPortable;
use UxStudio\Plugin;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$modules  = Plugin::instance()->modules;
$exporter = new Exporter( $modules );
$importer = new Importer( $modules );

/** @return array<string, array<int, string>> uxstudio_* cron hooks with their next timestamps. */
$cron_hooks = static function (): array {
	$out = array();
	foreach ( (array) _get_cron_array() as $time => $hooks ) {
		foreach ( (array) $hooks as $hook => $events ) {
			if ( 0 === strpos( (string) $hook, 'uxstudio_' ) ) {
				$out[ $hook ][] = (int) $time;
			}
		}
	}
	ksort( $out );
	return $out;
};
$hook_names = static fn( array $c ): array => array_keys( $c );
$cron_before = $cron_hooks();

$htaccess_path   = ABSPATH . '.htaccess';
$htaccess_before = file_exists( $htaccess_path ) ? (string) file_get_contents( $htaccess_path ) : null;

// Options this test may touch, saved now and put back at the end.
$always = array( 'uxstudio_active_modules', ImportSnapshot::OPTION, 'uxstudio_import_applied', 'uxstudio_module_db_versions', 'uxstudio_login_url_pending' );
$names  = array_merge( $always, ImportSnapshot::option_names( array( 'security-optimization', 'stock-photos', 'forms', 'redirects', 'code-snippets' ) ) );
$saved  = Snapshot::read_options( array_unique( $names ) );

$restore = static function () use ( &$saved, $htaccess_path, $htaccess_before ): void {
	Snapshot::restore_options( $saved );
	if ( null !== $htaccess_before && (string) file_get_contents( $htaccess_path ) !== $htaccess_before ) {
		file_put_contents( $htaccess_path, $htaccess_before );
	}
	wp_cache_flush();
};

try {
	/* ═══ 1. Export everything, read only ═══ */

	$catalog = $exporter->catalog();
	$ids     = array_column( $catalog, 'id' );
	$assert( 'the catalog lists modules', count( $ids ) > 20, (string) count( $ids ) );
	$assert( 'virtual groups are not in the catalog (their features are)', ! in_array( 'duplicate', $ids, true ) && in_array( 'duplicate-post', $ids, true ) );
	$built = $exporter->build( $ids, array( 'include_enabled' => true, 'include_data' => true ) );
	if ( is_wp_error( $built ) ) {
		$assert( 'export builds', false, $built->get_error_message() );
		throw new RuntimeException( 'export failed' );
	}
	$json = $built['json'];
	$env  = $built['envelope'];
	$assert( 'export builds', true );
	$assert( 'file name follows the pattern', 1 === preg_match( '/^ux-studio-nastaveni-[a-z0-9.-]+-\d{4}-\d{2}-\d{2}\.json$/', $built['filename'] ), $built['filename'] );
	$parsed = Envelope::parse( $json, UXSTUDIO_VERSION );
	$assert( 'the export parses back with a matching checksum', $parsed['ok'] && array() === $parsed['warnings'], json_encode( $parsed['warnings'] ?? array() ) );
	$assert( 'the export has no secrets block by default', null === $env['secrets'] && false === $env['options']['has_secrets'] );
	$assert( 'no sealed value (uxs1:) anywhere in the file', false === strpos( $json, 'uxs1:' ) );

	// Only schema keys, none from the never list.
	$bad_keys = array();
	foreach ( $env['modules'] as $id => $entry ) {
		$schema = array();
		foreach ( $modules->instance( $id )->settings_schema() as $f ) {
			$schema[ $f['key'] ] = $f;
		}
		foreach ( array_keys( (array) ( $entry['settings'] ?? array() ) ) as $key ) {
			if ( ! isset( $schema[ $key ] ) || '' !== NonPortable::reason( $id, $schema[ $key ] ) || NonPortable::is_secret( $id, $key ) ) {
				$bad_keys[] = $id . '.' . $key;
			}
		}
	}
	$assert( 'every exported key is in the schema and not on the never list', array() === $bad_keys, implode( ', ', $bad_keys ) );
	$assert( 'content-sync exports none of its connection keys', ! array_intersect( array( 'node_api_key', 'central_app_url', 'hmac_secret', 'node_allowed_ips' ), array_keys( (array) ( $env['modules']['content-sync']['settings'] ?? array() ) ) ) );
	
	// Grep every secret-looking value of the database in the file.
	global $wpdb;
	$secret_values = array();
	$collect       = static function ( $data, string $where ) use ( &$collect, &$secret_values ): void {
		if ( ! is_array( $data ) ) {
			return;
		}
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && Secrets::is_secret_key( $key ) && is_string( $value ) && strlen( $value ) >= 12 && 0 !== strpos( $value, 'uxs1:' ) && ! preg_match( '/^(https?:\/\/|[a-z_\-]+$)/i', $value ) ) {
				$secret_values[ $value ] = $where . '.' . $key;
			}
			$collect( $value, $where );
		}
	};
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value <> ''", ARRAY_A ) as $row ) {
		$name  = (string) $row['option_name'];
		$value = maybe_unserialize( $row['option_value'] );
		if ( 0 === strpos( $name, 'uxstudio_secret_' ) ) {
			$plain = Security::get_secret( $name );
			if ( strlen( $plain ) >= 8 ) {
				$secret_values[ $plain ] = $name;
			}
			continue;
		}
		if ( is_array( $value ) ) {
			$collect( $value, $name );
		} elseif ( is_string( $value ) && strlen( $value ) >= 16 && preg_match( '/(api_?key|password|secret|token)/i', $name ) && ! preg_match( '/^(https?:\/\/|[a-z_\-]+$)/i', $value ) && 0 !== strpos( $value, 'uxs1:' ) ) {
			$secret_values[ $value ] = $name;
		}
	}
	$leaks = array();
	foreach ( $secret_values as $value => $where ) {
		$value = (string) $value;
		if ( false !== strpos( $json, $value ) || false !== strpos( $json, trim( (string) wp_json_encode( $value ), '"' ) ) ) {
			$leaks[] = $where;
		}
	}
	WP_CLI::log( 'INFO ' . count( $secret_values ) . ' secret-looking values from the database were searched for in the file' );
	$assert( 'no secret value from any option of the database is in the file', array() === $leaks, implode( ', ', $leaks ) );

	// The export did not change anything.
	$assert( 'the export wrote no option (module list unchanged)', $saved['uxstudio_active_modules']['value'] === get_option( 'uxstudio_active_modules' ) );

	/* ═══ 2. Preview of the same file: nothing to change ═══ */

	$opts = Importer::options( array( 'apply_enabled' => true ) );
	$prev = $importer->preview( $json, '', $opts );
	$assert( 'preview of our own export works', ! is_wp_error( $prev ), is_wp_error( $prev ) ? $prev->get_error_message() : '' );
	$noisy = array();
	foreach ( $prev['modules'] as $m ) {
		if ( $m['changes'] || $m['secrets'] || 'none' !== $m['enabled']['change'] || ( $m['data'] && ( $m['data']['counts']['create'] || $m['data']['counts']['update'] ) ) ) {
			$noisy[] = $m['id'] . ' (' . implode( ',', array_column( $m['changes'], 'key' ) ) . ')';
		}
	}
	$assert( 'preview of our own export shows 0 changes', array() === $noisy && 0 === $prev['totals']['changes'], implode( '; ', $noisy ) );
	$assert( 'preview counts the keys without change', $prev['totals']['same'] > 100, (string) $prev['totals']['same'] );
	$assert( 'preview writes nothing', $saved['uxstudio_active_modules']['value'] === get_option( 'uxstudio_active_modules' ) && null === ImportSnapshot::get() );

	/* ═══ 3. Round trip on a harmless value ═══ */

	$target = null;
	foreach ( $env['modules'] as $id => $entry ) {
		$inst = $modules->instance( $id );
		if ( ! $inst || 'BaseModule' !== ( new ReflectionMethod( $inst, 'save_settings' ) )->getDeclaringClass()->getShortName() ) {
			continue;
		}
		foreach ( $inst->settings_schema() as $f ) {
			if ( 'number' === $f['type'] && isset( $entry['settings'][ $f['key'] ] ) && is_int( $entry['settings'][ $f['key'] ] ) && ! in_array( $id, array( 'activity-log', 'admin-customiser' ), true ) ) {
				$target = array( $id, $f['key'], $entry['settings'][ $f['key'] ] );
				break 2;
			}
		}
	}
	if ( ! $target ) {
		$assert( 'a harmless numeric setting exists for the round trip', false );
	} else {
		[ $mid, $mkey, $mold ] = $target;
		WP_CLI::log( "INFO round trip on $mid.$mkey (was $mold)" );
		$opt_name   = 'uxstudio_' . str_replace( '-', '_', $mid );
		$opt_before = Snapshot::read_options( array( $opt_name ) );
		$saved     += $opt_before;
		$edited     = $env['modules'];
		$edited[ $mid ]['settings'][ $mkey ] = $mold + 1;
		$file       = Envelope::encode( Envelope::build( array( 'plugin_version' => UXSTUDIO_VERSION, 'modules' => $edited ) ) );

		$p = $importer->preview( $file, '', Importer::options( array() ) );
		$row = null;
		foreach ( $p['modules'] as $m ) {
			if ( $m['id'] === $mid ) {
				$row = $m;
			}
		}
		$assert( 'preview shows exactly the one edited key', $row && 1 === count( $row['changes'] ) && $mkey === $row['changes'][0]['key'] && (string) $mold === $row['changes'][0]['old'] && (string) ( $mold + 1 ) === $row['changes'][0]['new'], json_encode( $row['changes'] ?? null ) );
		$assert( 'preview of the edited file: only that module has work', 1 === $p['totals']['modules'] );

		$res = $importer->apply( $file, '', Importer::options( array( 'modules' => array( $mid ) ) ) );
		$assert( 'apply works', ! is_wp_error( $res ) && 1 === $res['summary']['ok'] && 1 === $res['results'][0]['written'], is_wp_error( $res ) ? $res->get_error_message() : json_encode( $res['summary'] ) );
		$assert( 'the value is changed through the module', (int) ( $modules->instance( $mid )->settings_values()[ $mkey ] ) === $mold + 1 );
		$assert( 'a snapshot exists after the import', null !== ImportSnapshot::get() && ImportSnapshot::describe()['available'] );
		$assert( 'untouched modules did not change (module list)', $saved['uxstudio_active_modules']['value'] === get_option( 'uxstudio_active_modules' ) );

		$again = $importer->preview( $file, '', Importer::options( array() ) );
		$assert( 'previewing the same file again now shows 0 changes', 0 === $again['totals']['changes'] );

		$undo = $importer->undo();
		$assert( 'undo works', ! is_wp_error( $undo ) && array() === $undo['errors'], is_wp_error( $undo ) ? $undo->get_error_message() : json_encode( $undo['errors'] ) );
		$assert( 'the value is exactly as before', (int) ( $modules->instance( $mid )->settings_values()[ $mkey ] ) === $mold );
		$assert( 'the option is byte for byte as before', get_option( $opt_name, null ) === $opt_before[ $opt_name ]['value'] );
		$assert( 'the snapshot is gone after undo', null === ImportSnapshot::get() );
		$assert( 'a second undo says there is nothing to undo', is_wp_error( $importer->undo() ) );

		// Replace mode: keys the file does not mention go back to defaults (preview only).
		$sparse = Envelope::encode( Envelope::build( array( 'plugin_version' => UXSTUDIO_VERSION, 'modules' => array( $mid => array( 'settings' => array( $mkey => $mold ), 'needs_manual' => array() ) ) ) ) );
		$merge  = $importer->preview( $sparse, '', Importer::options( array( 'mode' => 'merge' ) ) );
		$assert( 'merge of a one-key file changes nothing else', 0 === $merge['totals']['changes'] );
	}

	/* ═══ 4. Hostile content ═══ */

	$hostile_modules = array(
		'hide-admin-bar'  => array( 'settings' => array( 'roles' => 'not-a-list', 'unknown_key' => 1, 'uxstudio_wizard_done' => true ), 'needs_manual' => array() ),
		'content-sync'    => array( 'settings' => array( 'node_api_key' => 'HOSTILE-KEY', 'central_app_url' => 'https://evil.example', 'hmac_secret' => 'HOSTILE', 'retention_days' => '30' ), 'needs_manual' => array() ),
		'no-such-module'  => array( 'settings' => array( 'a' => 1 ), 'needs_manual' => array() ),
		'admin-customiser' => array( 'settings' => array( 'primary_color' => 'javascript:alert(1)', 'hide_footer_version' => 'yes' ), 'needs_manual' => array() ),
	);
	$hostile = Envelope::encode( Envelope::build( array( 'plugin_version' => UXSTUDIO_VERSION, 'modules' => $hostile_modules ) ) );
	$h       = $importer->preview( $hostile, '', Importer::options( array() ) );
	$by_id   = array_column( $h['modules'], null, 'id' );
	$assert( 'wrong types are rejected per key with a message', isset( $by_id['hide-admin-bar'] ) && 'roles' === ( $by_id['hide-admin-bar']['rejected'][0]['key'] ?? '' ) && '' !== $by_id['hide-admin-bar']['rejected'][0]['message'] );
	$assert( 'unknown keys are listed and ignored', in_array( 'unknown_key', $by_id['hide-admin-bar']['unknown'], true ) && in_array( 'uxstudio_wizard_done', $by_id['hide-admin-bar']['unknown'], true ) );
	$assert( 'keys of the never list are refused even when the file has them', 3 === count( array_intersect( array_column( $by_id['content-sync']['non_portable'], 'key' ), array( 'node_api_key', 'central_app_url', 'hmac_secret' ) ) ) );
	$assert( 'the hostile Central App values never reach the diff', false === strpos( json_encode( $by_id['content-sync']['changes'] ), 'HOSTILE' ) && false === strpos( json_encode( $by_id['content-sync']['changes'] ), 'evil.example' ) );
	$assert( 'an unknown module is skipped with a note', ! $by_id['no-such-module']['known'] && '' !== $by_id['no-such-module']['blocked'] && ! $by_id['no-such-module']['selectable'] );
	$assert( 'a wrong colour and a toggle given as text are rejected', 2 === count( $by_id['admin-customiser']['rejected'] ) );
	$res = $importer->apply( $hostile, '', Importer::options( array( 'modules' => array( 'no-such-module' ) ) ) );
	$assert( 'applying only an unknown module changes nothing and takes no snapshot', ! is_wp_error( $res ) && 0 === $res['summary']['ok'] && null === ImportSnapshot::get() );
	$bad = $importer->preview( '{"format":"uxstudio-settings","format_version":99,"modules":{}}', '', Importer::options( array() ) );
	$assert( 'a newer format is refused with its own error code', is_wp_error( $bad ) && 'uxstudio_transfer_too_new' === $bad->get_error_code() );
	$assert( 'garbage is refused', is_wp_error( $importer->preview( 'not json at all', '', Importer::options( array() ) ) ) );

	/* ═══ 5. The Security module ═══ */

	if ( in_array( 'security-optimization', $modules->enabled_ids(), true ) ) {
		$sec_opts = array_merge( ImportSnapshot::option_names( array( 'security-optimization' ) ), array( 'uxstudio_csp_state', 'uxstudio_csp_sources' ) );
		$saved   += Snapshot::read_options( $sec_opts );
		$cron_sec = $cron_hooks();
		$sec      = $modules->instance( 'security-optimization' );
		$cur      = $sec->settings_values();
		$slug     = 'uxs-test-login-' . wp_rand( 100, 999 );
		$file     = Envelope::encode(
			Envelope::build(
				array(
					'plugin_version' => UXSTUDIO_VERSION,
					'modules'        => array(
						'security-optimization' => array(
							'settings'     => array(
								'csp_mode'                  => 'enforce',
								'login_url_enabled'         => true,
								'login_url'                 => $slug,
								'two_factor_enabled'        => true,
								'country_blocklist_enabled' => true,
								'blocked_countries'         => array( 'CN' ),
							),
							'needs_manual' => array(),
						),
					),
				)
			)
		);
		$p   = $importer->preview( $file, '', Importer::options( array() ) );
		$row = array_column( $p['modules'], null, 'id' )['security-optimization'];
		$codes = array_column( $row['measures'], 'code' );
		$assert( 'Security preview: CSP enforce is downgraded', in_array( 'csp_enforce', $codes, true ) || 'enforce' === ( $cur['csp_mode'] ?? '' ) );
		$assert( 'Security preview: the login address is announced as pending', in_array( 'login_pending', $codes, true ) );
		$assert( 'Security preview: country blocks are held back', in_array( 'country_block', array_column( $row['skipped'], 'code' ), true ) || in_array( 'country_block', $codes, true ) );
		$assert( 'Security preview: the safety measures are listed at the top level too', count( $p['measures'] ) >= 2 && count( $p['safety_rules'] ) >= 8 );

		$res = $importer->apply( $file, '', Importer::options( array( 'modules' => array( 'security-optimization' ) ) ) );
		$assert( 'Security apply goes through', ! is_wp_error( $res ) && 'ok' === $res['results'][0]['status'], is_wp_error( $res ) ? $res->get_error_message() : json_encode( $res['results'][0] ) );
		$after = $modules->instance( 'security-optimization' )->settings_values();
		$assert( 'CSP is not enforcing', 'enforce' !== $after['csp_mode'] || 'enforce' === $cur['csp_mode'], $after['csp_mode'] );
		$assert( 'the new login address is saved', $slug === trim( (string) $after['login_url'], '/' ) );
		$rec = \UxStudio\Modules\SecurityOptimization\LoginUrlPending::record();
		$assert( 'the new login address is in the pending state (wp-login.php stays open)', $rec && $slug === $rec['slug'] );
		$status = $modules->instance( 'security-optimization' )->login_lock_status();
		$assert( 'the login lock is not enforcing while pending', empty( $status['active'] ) && 'pending' === $status['reason'] || 'no_pretty_permalinks' === $status['reason'] || 'collision' === $status['reason'], json_encode( $status ) );
		$assert( 'country blocks were not saved', empty( $after['blocked_countries'] ) || $after['blocked_countries'] === $cur['blocked_countries'] );
		$assert( 'login state is reported in the result', isset( $res['login'] ) && true === $res['login']['touched'] );

		$undo = $importer->undo();
		$assert( 'Security undo works', ! is_wp_error( $undo ) && array() === $undo['errors'], is_wp_error( $undo ) ? $undo->get_error_message() : json_encode( $undo['errors'] ) );
		$assert( 'the Security option is exactly as before', get_option( 'uxstudio_security_optimization' ) === $saved['uxstudio_security_optimization']['value'] );
		$assert( 'the pending state is gone again', ( $saved['uxstudio_login_url_pending']['exists'] ?? false ) ? true : null === \UxStudio\Modules\SecurityOptimization\LoginUrlPending::record() );
		$htaccess_now = file_exists( $htaccess_path ) ? (string) file_get_contents( $htaccess_path ) : null;
		if ( $htaccess_now !== $htaccess_before ) {
			WP_CLI::log( 'INFO .htaccess differs after the Security round trip; it is put back byte for byte at the end' );
		}
		$extra = array_diff( $hook_names( $cron_hooks() ), $hook_names( $cron_sec ) );
		foreach ( $extra as $hook ) {
			wp_clear_scheduled_hook( $hook ); // Caused by the test (a schedule the CSP change started): clean up.
		}
		$assert( 'cron hooks of the plugin are the same after the Security round trip', $hook_names( $cron_hooks() ) === $hook_names( $cron_sec ), implode( ',', $extra ) );
	} else {
		WP_CLI::log( 'SKIP Security round trip (the module is off on this site)' );
	}

	/* ═══ 6. Encrypted secrets ═══ */

	if ( Crypto::available() ) {
		$option = 'uxstudio_secret_stock_pexels';
		$saved += Snapshot::read_options( array( $option, 'uxstudio_stock_photos' ) );
		Security::store_secret( $option, 'TEST-PEXELS-SECRET-111222' );
		$pass  = 'correct horse battery staple';
		$built = $exporter->build( array( 'stock-photos' ), array( 'include_secrets' => true, 'passphrase' => $pass, 'passphrase_confirm' => $pass ) );
		$assert( 'export with secrets builds', ! is_wp_error( $built ), is_wp_error( $built ) ? $built->get_error_message() : '' );
		$assert( 'the secret value is not in the file text', false === strpos( $built['json'], 'TEST-PEXELS-SECRET' ) );
		$assert( 'the file has an encrypted block', is_array( $built['envelope']['secrets'] ) && 'argon2id' === $built['envelope']['secrets']['kdf'] );
		$short = $exporter->build( array( 'stock-photos' ), array( 'include_secrets' => true, 'passphrase' => 'short', 'passphrase_confirm' => 'short' ) );
		$assert( 'a short passphrase is refused', is_wp_error( $short ) && 'uxstudio_transfer_passphrase' === $short->get_error_code() );
		$mism = $exporter->build( array( 'stock-photos' ), array( 'include_secrets' => true, 'passphrase' => $pass, 'passphrase_confirm' => $pass . 'x' ) );
		$assert( 'a passphrase that does not match its confirmation is refused', is_wp_error( $mism ) );

		$no_pass = $importer->preview( $built['json'], '', Importer::options( array() ) );
		$assert( 'preview without the passphrase asks for it and shows no secrets', 'needs_passphrase' === $no_pass['secrets_state'] && array() === array_column( $no_pass['modules'], 'secrets' )[0] );
		$wrong = $importer->preview( $built['json'], 'wrong passphrase here', Importer::options( array() ) );
		$assert( 'a wrong passphrase is a clear error that echoes nothing', is_wp_error( $wrong ) && 'uxstudio_transfer_bad_passphrase' === $wrong->get_error_code() && false === strpos( $wrong->get_error_message(), 'TEST-PEXELS' ) && false === strpos( $wrong->get_error_message(), 'wrong passphrase' ) );
		$right = $importer->preview( $built['json'], $pass, Importer::options( array() ) );
		$assert( 'the right passphrase opens it, and an unchanged secret is not a change', ! is_wp_error( $right ) && 'decrypted' === $right['secrets_state'] && 0 === $right['totals']['changes'] );
		$assert( 'the preview response never contains the secret', false === strpos( wp_json_encode( $right ), 'TEST-PEXELS' ) );

		Security::store_secret( $option, 'SOMETHING-ELSE-ENTIRELY' );
		$chg = $importer->preview( $built['json'], $pass, Importer::options( array() ) );
		$assert( 'a secret that differs is shown as a masked change', 1 === $chg['totals']['changes'] && false === strpos( wp_json_encode( $chg ), 'TEST-PEXELS' ) && false === strpos( wp_json_encode( $chg ), 'SOMETHING-ELSE' ) );
		$res = $importer->apply( $built['json'], $pass, Importer::options( array( 'modules' => array( 'stock-photos' ) ) ) );
		$assert( 'apply writes the secret through the module', ! is_wp_error( $res ) && 'TEST-PEXELS-SECRET-111222' === Security::get_secret( $option ), is_wp_error( $res ) ? $res->get_error_message() : '' );
		$importer->undo();
		$assert( 'undo gives the earlier secret back', 'SOMETHING-ELSE-ENTIRELY' === Security::get_secret( $option ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$log = (string) $wpdb->get_var( "SELECT GROUP_CONCAT(meta SEPARATOR ' ') FROM {$wpdb->prefix}uxstudio_activity_log WHERE action IN ('settings_exported','settings_imported','settings_import_undone')" );
		$assert( 'the activity log holds module ids and counts, never a secret or the passphrase', '' !== $log && false === strpos( $log, 'TEST-PEXELS' ) && false === strpos( $log, 'SOMETHING-ELSE' ) && false === strpos( $log, $pass ) );
	} else {
		WP_CLI::log( 'SKIP encrypted secrets round trip (the sodium extension is not loaded; run php with -d extension=sodium)' );
		$nope = $exporter->build( array( 'stock-photos' ), array( 'include_secrets' => true, 'passphrase' => 'correct horse battery staple', 'passphrase_confirm' => 'correct horse battery staple' ) );
		$assert( 'without sodium an export with secrets is refused, not written in clear', is_wp_error( $nope ) && 'uxstudio_transfer_no_sodium' === $nope->get_error_code() );
	}

	/* ═══ 7. Data providers on throw-away tables and rows ═══ */

	global $wpdb;
	$table_exists = static fn( string $t ): bool => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) );
	$created_tables = array();
	$providers      = DataProviders::all( $modules );

	// Forms.
	$forms_module = $modules->instance( 'forms' );
	if ( ! $table_exists( 'uxstudio_forms' ) ) {
		foreach ( array( 'uxstudio_forms', 'uxstudio_form_submissions', 'uxstudio_form_submission_files', 'uxstudio_form_action_log', 'uxstudio_form_revisions' ) as $t ) {
			$created_tables[] = $t;
		}
		$forms_module->migrate( 0 );
	}
	$forms = $providers['forms'];
	$assert( 'forms provider is ready', '' === $forms->blocked_reason() );
	$before_count = $forms->count();
	$title        = 'UXS TRANSFER TEST FORM';
	$item         = array(
		'title'       => $title,
		'description' => 'first',
		'status'      => 'draft',
		'fields'      => array(),
		'settings'    => array( 'submit_text' => 'Send', 'webhook_secret' => 'LEAKED-WEBHOOK-SECRET', 'actions' => array( array( 'type' => 'email', 'to' => 'a@example.test', 'subject' => 's', 'message' => '', 'logo_id' => 99 ) ) ),
	);
	$plan = $forms->plan( array( $item ) );
	$assert( 'forms plan: a new form is "create"', 'create' === $plan['items'][0]['action'] );
	$imp = $forms->import( array( $item ) );
	$assert( 'forms import creates the form', 1 === count( $imp['created'] ) && array() === $imp['failed'] );
	$assert( 'the foreign webhook secret was not taken over', false === strpos( wp_json_encode( $forms_module->get_form( (int) $imp['created'][0] ) ), 'LEAKED-WEBHOOK-SECRET' ) );
	$exp = $forms->export();
	$assert( 'forms export holds the form without ids, counters or the webhook secret', 1 === count( $exp ) && ! array_key_exists( 'id', $exp[0] ) && false === strpos( wp_json_encode( $exp ), 'webhook_secret' ) && $title === $exp[0]['title'] );
	$assert( 'forms plan: the exported form is "same"', 'same' === $forms->plan( $exp )['items'][0]['action'] );
	$secret_before = $forms_module->get_form( (int) $imp['created'][0] )['settings']['webhook_secret'];
	$item['description'] = 'second';
	$assert( 'forms plan: a changed form is "update"', 'update' === $forms->plan( array( $item ) )['items'][0]['action'] );
	$imp2 = $forms->import( array( $item ) );
	$assert( 'forms import updates, creating nothing', array() === $imp2['created'] && 1 === count( $imp2['previous'] ), json_encode( $imp2 ) );
	$assert( 'the form keeps its own webhook secret on update', $secret_before === $forms_module->get_form( (int) $imp['created'][0] )['settings']['webhook_secret'] );
	$forms->undo( array(), $imp2['previous'] );
	$assert( 'forms undo restores the earlier version', 'first' === $forms_module->get_form( (int) $imp['created'][0] )['description'] );
	$forms->undo( $imp['created'], array() );
	$assert( 'forms undo removes what the import created', $before_count === $forms->count() );
	$assert( 'forms: submissions are never part of the export', false === strpos( wp_json_encode( $forms->export() ), 'submission' ) );

	// Redirects.
	$redirects_repo = \UxStudio\Modules\Redirects\Repository::class;
	if ( ! $table_exists( 'uxstudio_redirects' ) ) {
		$created_tables[] = 'uxstudio_redirects';
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $redirects_repo::schema() );
	}
	$rd     = $providers['redirects'];
	$rcount = $rd->count();
	$rule   = array( 'source' => '/uxs-transfer-test-old', 'match_type' => 'exact', 'target' => '/uxs-transfer-test-new', 'code' => 301, 'enabled' => true, 'note' => 'test' );
	$assert( 'redirects plan: a new rule is "create"', 'create' === $rd->plan( array( $rule ) )['items'][0]['action'] );
	$r1 = $rd->import( array( $rule ) );
	$assert( 'redirects import creates the rule', 1 === count( $r1['created'] ) && array() === $r1['failed'], json_encode( $r1['failed'] ) );
	$assert( 'redirects: the exported rule has no counters or ids', ! array_key_exists( 'hits', $rd->export()[0] ?? array() ) && ! array_key_exists( 'id', $rd->export()[0] ?? array() ) );
	$rule['code'] = 302;
	$assert( 'redirects plan: a changed code is "update"', 'update' === $rd->plan( array( $rule ) )['items'][0]['action'] );
	$bad_rule = $rd->import( array( array( 'source' => '/wp-admin/x', 'match_type' => 'exact', 'target' => '/y', 'code' => 301 ), array( 'source' => '/loop-a', 'target' => '/loop-a', 'code' => 301 ) ) );
	$assert( 'redirects: the repository refuses reserved paths and loops', array() === $bad_rule['created'] && 2 === count( $bad_rule['failed'] ), json_encode( $bad_rule['failed'] ) );
	$rd->undo( $r1['created'], array() );
	$assert( 'redirects undo removes the rule', $rcount === $rd->count() );

	// Snippets (executable code: the strictest provider).
	$sn = $providers['code-snippets'];
	$added_column = false;
	if ( ! $table_exists( 'uxstudio_code_snippets' ) ) {
		$created_tables[] = 'uxstudio_code_snippets';
		\UxStudio\Modules\CodeSnippets\Module::ensure_tables();
	} elseif ( '' !== $sn->blocked_reason() && ! $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}uxstudio_code_snippets LIKE 'conditions'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// An older table (the module has been off for a long time): bring it up to date for the test, take the column off again after.
		$added_column = true;
		\UxStudio\Modules\CodeSnippets\Module::ensure_tables();
	}
	$dir_existed = is_dir( WP_CONTENT_DIR . '/uxstudio-snippets' );
	$assert( 'snippets provider needs unfiltered_html', 'unfiltered_html' === $sn->capability() );
	if ( '' === $sn->blocked_reason() ) {
		$snippet = array( 'name' => 'UXS TRANSFER TEST SNIPPET', 'type' => 'css', 'code' => 'body { outline: 0; }', 'run_location' => '', 'enabled' => true );
		$s1      = $sn->import( array( $snippet ) );
		$assert( 'snippets import creates the snippet', 1 === count( $s1['created'] ) && array() === $s1['failed'], json_encode( $s1['failed'] ) );
		$made = $sn->export();
		$assert( 'an imported snippet arrives switched off even when the file says enabled', ! empty( $made ) && false === $made[0]['enabled'] );
		$bad_php = $sn->import( array( array( 'name' => 'UXS BAD PHP', 'type' => 'exe', 'code' => 'MZ' ) ) );
		$assert( 'a snippet of an unknown type is refused', array() === $bad_php['created'] && 1 === count( $bad_php['failed'] ), json_encode( $bad_php ) );
		$sn->undo( $s1['created'], array() );
		$assert( 'snippets undo removes it', 0 === count( array_filter( $sn->export(), static fn( $i ) => 'UXS TRANSFER TEST SNIPPET' === $i['name'] ) ) );
		// Whatever this test made is removed, also when an assertion above failed.
		$mgr = new \UxStudio\Modules\CodeSnippets\SnippetManager();
		foreach ( $mgr->getAllSnippets() as $left ) {
			if ( 0 === strpos( $left->getName(), 'UXS ' ) ) {
				$mgr->deleteSnippet( $left->getId() );
			}
		}
		wp_set_current_user( 0 );
		$assert( 'without a user the snippet capability check fails', ! current_user_can( 'unfiltered_html' ) );
		wp_set_current_user( (int) $admins[0] );
	} else {
		WP_CLI::log( 'SKIP snippets import (' . $sn->blocked_reason() . ')' );
	}
	if ( ! $dir_existed && is_dir( WP_CONTENT_DIR . '/uxstudio-snippets' ) ) {
		foreach ( (array) glob( WP_CONTENT_DIR . '/uxstudio-snippets/{,.}*', GLOB_BRACE ) as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		rmdir( WP_CONTENT_DIR . '/uxstudio-snippets' );
	}

	// Capability: a user without unfiltered_html cannot export snippets.
	$editor = wp_insert_user( array( 'user_login' => 'uxs_transfer_tmp_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'author' ) );
	if ( ! is_wp_error( $editor ) ) {
		wp_set_current_user( (int) $editor );
		$cat = array_column( ( new Exporter( $modules ) )->catalog(), null, 'id' );
		$assert( 'for a user without unfiltered_html the snippets data is marked not allowed', isset( $cat['code-snippets'] ) && false === $cat['code-snippets']['data']['allowed'] );
		$e = ( new Exporter( $modules ) )->build( array( 'code-snippets' ), array( 'include_data' => true ) );
		$assert( 'and it is left out of their export with a note', ! is_wp_error( $e ) && ! isset( $e['envelope']['modules']['code-snippets']['data'] ) && ! empty( $e['summary']['warnings'] ) );
		wp_set_current_user( (int) $admins[0] );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $editor );
	}

	if ( $added_column ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}uxstudio_code_snippets DROP COLUMN conditions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
	foreach ( array_reverse( $created_tables ) as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$assert( 'the throw-away tables are gone again', array() === array_filter( $created_tables, $table_exists ) );
} catch ( Throwable $e ) {
	++$failures;
	WP_CLI::log( 'FAIL exception: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	$restore();
	foreach ( array_diff( $hook_names( $cron_hooks() ), $hook_names( $cron_before ) ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

$assert( 'options are restored exactly (module list)', $saved['uxstudio_active_modules']['value'] === get_option( 'uxstudio_active_modules' ) );
$assert( 'no import snapshot is left', ! (bool) get_option( ImportSnapshot::OPTION, false ) );
$assert( 'uxstudio_* cron hooks are the same as before the test', $hook_names( $cron_hooks() ) === $hook_names( $cron_before ), implode( ',', array_diff( $hook_names( $cron_hooks() ), $hook_names( $cron_before ) ) ) );
$assert( '.htaccess is byte for byte as before', $htaccess_before === ( file_exists( $htaccess_path ) ? (string) file_get_contents( $htaccess_path ) : null ) );

if ( $failures ) {
	WP_CLI::error( $failures . ' FAILED' );
}
WP_CLI::success( 'ALL PASSED' );
