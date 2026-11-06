<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the MCP server core (PLAN.md 26.6): scope matrix, tokens, confirmations,
 * secret masking, guard refusals. Needs no WordPress and no database:
 *
 *   php tests/mcp-core.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'UXSTUDIO_VERSION', '9.9.9' );

$GLOBALS['uxs_options']    = array();
$GLOBALS['uxs_transients'] = array();
$GLOBALS['uxs_filters']    = array();

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
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function get_option( $name, $default = false ) { return $GLOBALS['uxs_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; $GLOBALS['uxs_option_writes'][ $name ] = ( $GLOBALS['uxs_option_writes'][ $name ] ?? 0 ) + 1; return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function get_transient( $k ) { return $GLOBALS['uxs_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['uxs_transients'][ $k ] = $v; return true; }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['uxs_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function __( $text, $domain = '' ) { return $text; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_user_by( $f, $id ) { return (object) array( 'display_name' => 'Admin ' . $id ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function rest_url( $p ) { return 'https://example.test/wp-json/' . $p; }

$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Mcp\Auth;
use UxStudio\Core\Mcp\Config;
use UxStudio\Core\Mcp\CoreAbilities;
use UxStudio\Core\Mcp\Internal;
use UxStudio\Core\Mcp\McpError;
use UxStudio\Core\Mcp\Protocol;
use UxStudio\Core\Mcp\Registry;
use UxStudio\Core\Mcp\Schema;
use UxStudio\Core\Mcp\Scopes;
use UxStudio\Core\Mcp\Secrets;
use UxStudio\Core\Mcp\Sensitive;
use UxStudio\Core\Mcp\Tokens;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* ── Fixtures ── */

$GLOBALS['uxs_user'] = array( 'pwh' => 'pw-1', 'admin' => true );
Tokens::set_user_resolver( static fn( int $id ): ?array => 7 === $id ? $GLOBALS['uxs_user'] : null );
Registry::set_module_checker( static fn( string $id ): bool => true );

$calls = array();
$transport = static function ( string $method, string $route, array $params ) use ( &$calls ): array {
		$calls[] = array( $method, $route, $params );
		if ( 'POST' === $method && '/modules/cron-control' === $route && ! empty( $params['enabled'] ) ) {
			throw new McpError( 'uxstudio_plugin_overlap', 'The module was not enabled: an active plugin already does the same job (WP Crontrol).', array( 'plugins' => array( array( 'slug' => 'wp-crontrol', 'migratable' => true ) ) ) );
		}
		if ( 'GET' === $method && '/settings/smtp-email' === $route ) {
			return array(
				'data' => array(
					'name'   => 'SMTP',
					'schema' => array(
						array( 'key' => 'host', 'type' => 'text', 'label' => 'Host' ),
						array( 'key' => 'port', 'type' => 'number', 'label' => 'Port' ),
						array( 'key' => 'password', 'type' => 'text', 'label' => 'Password' ),
						array( 'key' => 'from_name', 'type' => 'text', 'label' => 'From name' ),
						array( 'key' => 'note', 'type' => 'text', 'label' => 'Note', 'sensitive' => true ),
					),
					'values' => array( 'host' => 'smtp.example.test', 'port' => 587, 'password' => 'S3cr3t-value', 'api_key' => 'KEY-123', 'has_other' => true ),
				),
				'meta' => array(),
			);
		}
		if ( 'GET' === $method && '/migration/switch-plan' === $route ) {
			return array(
				'data' => array(
					'run_id'      => 'run-1',
					'switched'    => false,
					'errors'      => 0,
					'plugins'     => array( array( 'name' => 'Disable Comments', 'basename' => 'disable-comments/disable-comments.php', 'source' => 'disable-comments' ) ),
					'modules'     => array( array( 'id' => 'security-optimization', 'name' => 'Security & Optimization', 'enabled' => false ) ),
					'staging'     => array( 'settings' => array( 'security-optimization' ), 'activations' => array() ),
					'login'       => array( 'changes' => true, 'url' => 'https://example.test/secret-login/', 'slug' => 'secret-login', 'now_url' => 'https://example.test/wp-login.php' ),
					'preview'     => array( 'available' => array( 'ok' => true ), 'tested' => true, 'fresh' => true, 'complete' => true, 'counts' => array( 'fail' => 0 ), 'total' => 3 ),
					'revert_days' => 30,
				),
				'meta' => array(),
			);
		}
		return array( 'data' => array( 'route' => $route, 'params' => $params ), 'meta' => array() );
	};
Internal::set_transport( $transport );

$log   = array();
$proto = static function ( array $scopes ) use ( &$log ): Protocol {
	return new Protocol(
		array( 'id' => 'tok1', 'label' => 'Test', 'scopes' => $scopes, 'ip' => '203.0.113.5' ),
		static function ( string $action, array $meta ) use ( &$log ): void {
			$log[] = array( $action, $meta );
		}
	);
};
$call  = static function ( Protocol $p, string $tool, array $args = array() ): array {
	return $p->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) ) );
};
$text  = static fn( array $r ): string => (string) ( $r['response']['result']['content'][0]['text'] ?? '' );

/* ── Scopes ── */

$assert( 'admin expands to all scopes', Scopes::expand( array( 'admin' ) ) === Scopes::all() );
$assert( 'write includes read', Scopes::allows( array( 'write' ), 'read' ) && ! Scopes::allows( array( 'write' ), 'migration' ) );
$assert( 'migration includes read, not write', Scopes::allows( array( 'migration' ), 'read' ) && ! Scopes::allows( array( 'migration' ), 'write' ) );
$assert( 'read alone is read only', Scopes::allows( array( 'read' ), 'read' ) && ! Scopes::allows( array( 'read' ), 'write' ) );
$assert( 'unknown scopes are dropped', array( 'read' ) === Scopes::normalize( array( 'bogus', 'read', 'read' ) ) );
$assert( 'no scopes allow nothing', ! Scopes::allows( array(), 'read' ) );

/* ── Scope matrix per ability ── */

$expected = array(
	'read'      => array( 'uxstudio-site-overview', 'uxstudio-modules-list', 'uxstudio-module-settings-get', 'uxstudio-overlaps-list', 'uxstudio-csp-inventory', 'uxstudio-activity-log-query', 'uxstudio-email-log-search', 'uxstudio-migration-scan', 'uxstudio-migration-plan', 'uxstudio-migration-status', 'uxstudio-migration-runs', 'uxstudio-migration-switch-plan', 'uxstudio-migration-preview-status', 'redirects-list-rules', 'redirects-test-url', 'redirects-list-404', 'redirects-list-site-urls', 'redirects-find-target' ),
	'write'     => array( 'uxstudio-module-enable', 'uxstudio-module-disable', 'uxstudio-module-settings-update', 'uxstudio-overlap-ignore', 'uxstudio-overlap-unignore', 'redirects-upsert-rules', 'redirects-delete-rules', 'redirects-reset-hits', 'redirects-audit' ),
	'migration' => array( 'uxstudio-migration-run', 'uxstudio-migration-verify', 'uxstudio-migration-preview-start', 'uxstudio-migration-preview-end', 'uxstudio-migration-smoke', 'uxstudio-migration-switch', 'uxstudio-migration-revert', 'uxstudio-migration-rollback' ),
);
$all_tools = array_map( static fn( array $d ): string => $d['tool'], array_values( Registry::all() ) );
$by_scope  = array();
foreach ( Registry::all() as $def ) {
	$by_scope[ $def['scope'] ][] = $def['tool'];
}
foreach ( $expected as $scope => $tools ) {
	$have = $by_scope[ $scope ] ?? array();
	sort( $tools );
	sort( $have );
	$assert( "ability scopes: $scope", $tools === $have, json_encode( array( 'expected' => $tools, 'have' => $have ) ) );
}
$assert( 'every ability is classified (' . count( $all_tools ) . ')', count( $all_tools ) === array_sum( array_map( 'count', $expected ) ) );

$list = static function ( array $scopes ) use ( $proto ): array {
	$r = $proto( $scopes )->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) );
	return array_column( $r['response']['result']['tools'], 'name' );
};
$expect_visible = static function ( array $granted ) use ( $expected ): array {
	$out = array();
	foreach ( $expected as $scope => $tools ) {
		if ( Scopes::allows( $granted, $scope ) ) {
			$out = array_merge( $out, $tools );
		}
	}
	sort( $out );
	return $out;
};
foreach ( array( array( 'read' ), array( 'write' ), array( 'migration' ), array( 'read', 'write' ), array( 'admin' ) ) as $granted ) {
	$have = $list( $granted );
	sort( $have );
	$assert( 'tools/list for [' . implode( ',', $granted ) . ']', $have === $expect_visible( $granted ), count( $have ) . ' tools' );
}
$assert( 'read token does not see write or migration tools', ! in_array( 'uxstudio-module-enable', $list( array( 'read' ) ), true ) && ! in_array( 'uxstudio-migration-switch', $list( array( 'read' ) ), true ) );

/* ── Calls: scope enforcement, logging ── */

$r = $call( $proto( array( 'read' ) ), 'uxstudio-module-enable', array( 'module' => 'activity-log' ) );
$assert( 'read token calling a write ability -> HTTP 403 insufficient scope', 403 === $r['status'] && Protocol::ERR_SCOPE === ( $r['response']['error']['code'] ?? 0 ) && 'write' === ( $r['response']['error']['data']['required_scope'] ?? '' ), json_encode( $r ) );
$assert( 'the refused call reached no route', ! array_filter( $calls, static fn( array $c ): bool => '/modules/activity-log' === $c[1] ) );
$assert( 'refusal is logged with the token id', in_array( 'tool_denied', array_column( $log, 0 ), true ) && 'tok1' === end( $log )[1]['token_id'] );

$r = $call( $proto( array( 'write' ) ), 'uxstudio-migration-run', array( 'sources' => array( 'disable-comments' ) ) );
$assert( 'write token cannot run migrations', 403 === $r['status'] );

$r = $call( $proto( array( 'write' ) ), 'nope-nothing' );
$assert( 'unknown tool is a protocol error', isset( $r['response']['error'] ) && 403 !== $r['status'] );

$log = array();
$r   = $call( $proto( array( 'read' ) ), 'uxstudio-module-settings-get', array( 'module' => 'smtp-email' ) );
$assert( 'allowed call is logged with the token id and the tool', 'tool_call' === $log[0][0] && 'tok1' === $log[0][1]['token_id'] && 'uxstudio-module-settings-get' === $log[0][1]['tool'] && true === $log[0][1]['ok'] );

/* ── Secrets ── */

$out = $text( $r );
$assert( 'settings-get never returns a secret value', false === strpos( $out, 'S3cr3t-value' ) && false === strpos( $out, 'KEY-123' ), $out );
$assert( 'settings-get reports has_ flags instead', false !== strpos( $out, '"has_password": true' ) && false !== strpos( $out, '"has_api_key": true' ) && false !== strpos( $out, 'smtp.example.test' ), $out );
$assert( 'redacted keys are listed', false !== strpos( $out, 'redacted_keys' ) );
$assert( 'scrub() hides credential-like keys in any output', 'smtp' === Secrets::scrub( array( 'x' => array( 'name' => 'smtp' ) ) )['x']['name'] && Secrets::MARK === Secrets::scrub( array( 'deep' => array( 'api_token' => 'abc' ) ) )['deep']['api_token'] );
$assert( 'scrub() hides sealed values wherever they are', Secrets::MARK === Secrets::scrub( array( 'v' => 'uxs1:AAAA' ) )['v'] );
$assert( 'scrub() keeps booleans (has_*, flags)', true === Secrets::scrub( array( 'has_password' => true, 'password_enabled' => true ) )['password_enabled'] );
$assert( 'ids, counts and flags about credentials are kept (token_id, has_x, secrets=[])', 'abc' === Secrets::scrub( array( 'token_id' => 'abc' ) )['token_id'] && array() === Secrets::scrub( array( 'secrets' => array() ) )['secrets'] );
$assert( 'plain data survives', 'Hello' === Secrets::scrub( array( 'title' => 'Hello' ) )['title'] );

$calls = array();
$r     = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( 'password' => 'new' ) ) );
$assert( 'write token cannot set credentials', 'secret_not_allowed' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) && ! array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] ), $text( $r ) );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'write' ) ) );
$r = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( 'password' => 'new' ), 'allow_secrets' => true ) );
$assert( 'allow_secrets is ignored without the admin scope', 'secret_not_allowed' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'admin' ) ) );
$calls = array();
$r     = $call( $proto( array( 'admin' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( 'password' => 'new' ), 'allow_secrets' => true ) );
$assert( 'admin token + allow_secrets may set a credential', array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] && '/settings/smtp-email' === $c[1] ) && false === strpos( $text( $r ), 'new"' ), $text( $r ) );
$assert( 'the credential value is not logged', false === strpos( json_encode( $log ), '"new"' ) );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'write' ) ) );
$r = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( 'hostt' => 'x' ) ) );
$assert( 'unknown settings keys are refused', 'unknown_setting' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ), $text( $r ) );
$calls = array();
$r     = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( 'from_name' => 'Shop' ), 'dry_run' => true ) );
$assert( 'dry_run writes nothing and shows the change', ! array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] ) && false !== strpos( $text( $r ), 'Shop' ), $text( $r ) );

/* ── Sensitive settings: destinations of credentials need the admin scope (finding 4) ── */

$calls = array();
foreach ( array( 'host', 'port', 'note' ) as $k ) { // host/port: central list, note: flagged `sensitive` in the schema.
	$r = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( $k => 'x' ) ) );
	$assert( "write token cannot change $k (admin_scope_required)", 'admin_scope_required' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) && ! array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] ), $text( $r ) );
}
$r = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( 'host' => 'evil.example', 'from_name' => 'x' ), 'dry_run' => true ) );
$assert( 'mixing a sensitive key with a normal one is refused as a whole, also as dry run', 'admin_scope_required' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'admin' ) ) );
$r = $call( $proto( array( 'admin' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( 'host' => 'mail.example.test2' ) ) );
$assert( 'an admin token may change the host', (bool) array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] && '/settings/smtp-email' === $c[1] ), $text( $r ) );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'write' ) ) );
$sec_keys = Sensitive::admin_write_keys( 'security-optimization', array(), array( 'two_factor_enabled', 'login_url', 'ip_firewall_allowlist', 'csp_mode', 'heartbeat_control', 'hide_wp_version' ) );
$assert( 'sensitive: the whole security-optimization module is admin-write', array( 'two_factor_enabled', 'login_url', 'ip_firewall_allowlist', 'csp_mode', 'heartbeat_control', 'hide_wp_version' ) === $sec_keys, json_encode( $sec_keys ) );
$assert( 'sensitive: prefix entries (activity-log alert_*) and module-wide disable-auto-updates', array( 'alert_new_ip', 'retention_days' ) === Sensitive::admin_write_keys( 'activity-log', array(), array( 'alert_new_ip', 'retention_days', 'log_logins' ) ) && array( 'disable_core_updates' ) === Sensitive::admin_write_keys( 'disable-auto-updates', array(), array( 'disable_core_updates' ) ) );
$assert( 'sensitive: newly covered fields (pixels, system prompts, maintenance mode, bot throttle, login CSS)', array( 'google_analytics' ) === Sensitive::admin_write_keys( 'pixel-tag-manager', array(), array( 'google_analytics' ) ) && array( 'custom_system_prompt', 'chatbot_enabled' ) === Sensitive::admin_write_keys( 'ai-assistant', array(), array( 'custom_system_prompt', 'chatbot_enabled' ) ) && array( 'mode', 'custom_css' ) === Sensitive::admin_write_keys( 'maintenance-mode', array(), array( 'mode', 'custom_css' ) ) && array( 'requests_per_minute', 'blacklist_ua' ) === Sensitive::admin_write_keys( 'bot-throttle', array(), array( 'requests_per_minute', 'blacklist_ua' ) ) && array( 'login_custom_css', 'menu_lock_enabled' ) === Sensitive::admin_write_keys( 'admin-customiser', array(), array( 'login_custom_css', 'menu_lock_enabled' ) ) );
$assert( 'sensitive: SMTP username and Gmail client id are hidden from read tokens', Sensitive::is_read_hidden( 'smtp-email', array(), 'username' ) && Sensitive::is_read_hidden( 'smtp-email', array(), 'gmail_client_id' ) );
$assert( 'sensitive: ordinary keys of other modules stay open', array() === Sensitive::admin_write_keys( 'smtp-email', array(), array( 'from_name', 'retention_days' ) ) );
$assert( 'sensitive: central app and SSO keys are admin only', array( 'central_app_url', 'sso_allow_admin' ) === Sensitive::admin_write_keys( 'content-sync', array(), array( 'central_app_url', 'sso_allow_admin', 'retention_days' ) ) );

/* ── Read scope hides non-secret values that weaken protection ── */

$hid = Sensitive::hide_values( 'security-optimization', array(), array( 'login_url' => 'my-secret-door', 'ip_firewall_allowlist' => '1.2.3.4', 'hide_wp_version' => true, 'upload_guard_whitelist_paths' => '' ) );
$assert( 'login URL and allowlists are hidden from read callers, empty values are left', Sensitive::HIDDEN === $hid['values']['login_url'] && Sensitive::HIDDEN === $hid['values']['ip_firewall_allowlist'] && true === $hid['values']['hide_wp_version'] && '' === $hid['values']['upload_guard_whitelist_paths'] && array( 'login_url', 'ip_firewall_allowlist' ) === $hid['hidden'] );
$assert( 'maintenance bypass IPs hidden, schema flag mcp_hidden honoured', Sensitive::HIDDEN === Sensitive::hide_values( 'maintenance-mode', array(), array( 'bypass_ips' => '1.1.1.1' ) )['values']['bypass_ips'] && Sensitive::HIDDEN === Sensitive::hide_values( 'x', array( array( 'key' => 'foo', 'mcp_hidden' => true ) ), array( 'foo' => 'bar' ) )['values']['foo'] );
Internal::set_transport(
	static function ( string $method, string $route, array $params ): array {
		return array( 'data' => array( 'name' => 'Sec', 'schema' => array(), 'values' => array( 'login_url' => 'my-secret-door', 'heartbeat_control' => 'allow' ) ), 'meta' => array() );
	}
);
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'read' ) ) );
$out = $text( $call( $proto( array( 'read' ) ), 'uxstudio-module-settings-get', array( 'module' => 'security-optimization' ) ) );
$assert( 'settings-get with read scope does not leak the secret login URL', false === strpos( $out, 'my-secret-door' ) && false !== strpos( $out, '"heartbeat_control": "allow"' ) && false !== strpos( $out, 'hidden_keys' ), $out );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'admin' ) ) );
$out = $text( $call( $proto( array( 'admin' ) ), 'uxstudio-module-settings-get', array( 'module' => 'security-optimization' ) ) );
$assert( 'an admin token sees it', false !== strpos( $out, 'my-secret-door' ), $out );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'write' ) ) );
Internal::set_transport( $transport );

/* ── Secret key names (finding 4) ── */

foreach ( array( 'ses_access_key', 'aws_access-key', 'signing_key', 'ga_service_account_json', 'webhook_url', 'jwt_token' ) as $k ) {
	$assert( "secret key pattern matches $k", Secrets::is_secret_key( $k ) );
}
foreach ( array( 'footprint', 'access_roles', 'webhook_events', 'host', 'key_count' ) as $k ) {
	$assert( "secret key pattern leaves $k alone", ! Secrets::is_secret_key( $k ) );
}
$r = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-get', array( 'module' => 'smtp-email' ) );
$assert( 'write token includes read abilities', ! isset( $r['response']['error'] ) );

/* ── Guard refusal surfaced ── */

Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'admin' ) ) );
$r    = $call( $proto( array( 'admin' ) ), 'uxstudio-module-enable', array( 'module' => 'cron-control' ) );
$body = json_decode( $text( $r ), true );
$assert( 'module-enable refused by the overlap guard: structured error', true === ( $r['response']['result']['isError'] ?? false ) && 'uxstudio_plugin_overlap' === ( $body['error']['code'] ?? '' ) && 'wp-crontrol' === ( $body['error']['data']['plugins'][0]['slug'] ?? '' ), $text( $r ) );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'write' ) ) );
$r = $call( $proto( array( 'write' ) ), 'uxstudio-module-enable', array( 'module' => 'Bad Id!' ) );
$assert( 'module id is validated', 'invalid_input' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) );
$calls = array();
$r     = $call( $proto( array( 'write' ) ), 'uxstudio-module-enable', array( 'module' => 'duplicate-post' ) );
$assert( 'module-enable posts to the same route as the UI', array_filter( $calls, static fn( array $c ): bool => '/modules/duplicate-post' === $c[1] && true === $c[2]['enabled'] ) && false === ( $r['response']['result']['isError'] ?? false ) );
$calls = array();
foreach ( array( 'uxstudio-module-enable', 'uxstudio-module-disable' ) as $ability ) {
	$r = $call( $proto( array( 'write' ) ), $ability, array( 'module' => 'security-optimization' ) );
	$assert( "write token cannot use $ability on a security module (admin_scope_required)", 'admin_scope_required' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) && ! array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] ), $text( $r ) );
}
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'admin' ) ) );
$r = $call( $proto( array( 'admin' ) ), 'uxstudio-module-disable', array( 'module' => 'activity-log' ) );
Auth::reset( array( 'state' => 'ok', 'scopes' => array( 'write' ) ) );
$assert( 'an admin token may disable a security module', (bool) array_filter( $calls, static fn( array $c ): bool => '/modules/activity-log' === $c[1] && false === $c[2]['enabled'] ), $text( $r ) );

/* ── Confirmations ── */

$calls = array();
$r     = $call( $proto( array( 'migration' ) ), 'uxstudio-migration-switch', array( 'run_id' => 'run-1' ) );
$body  = json_decode( $text( $r ), true );
$assert( 'switch without confirm is refused', true === ( $r['response']['result']['isError'] ?? false ) && 'confirmation_required' === ( $body['error']['code'] ?? '' ), $text( $r ) );
$assert( 'refusal describes the consequences incl. the login URL change', false !== strpos( $body['error']['message'] ?? '', 'secret-login' ) && false !== strpos( $body['error']['message'] ?? '', 'Disable Comments' ) && false !== strpos( $body['error']['message'] ?? '', 'ack_login' ), $body['error']['message'] ?? '' );
$assert( 'nothing was switched', ! array_filter( $calls, static fn( array $c ): bool => '/migration/switch' === $c[1] ) );
$r = $call( $proto( array( 'migration' ) ), 'uxstudio-migration-switch', array( 'run_id' => 'run-1', 'confirm' => false ) );
$assert( 'confirm=false is refused too', 'confirmation_required' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) );
$r = $call( $proto( array( 'migration' ) ), 'uxstudio-migration-switch', array( 'run_id' => 'run-1', 'confirm' => true, 'ack_login' => true ) );
$sw = array_values( array_filter( $calls, static fn( array $c ): bool => '/migration/switch' === $c[1] ) );
$assert( 'switch with confirm goes through the wizard route with its safety flags OFF', 1 === count( $sw ) && false === $sw[0][2]['override_failures'] && false === $sw[0][2]['no_preview'] && true === $sw[0][2]['ack_login'], json_encode( $sw ) );
$calls = array();
$call( $proto( array( 'migration' ) ), 'uxstudio-migration-switch', array( 'run_id' => 'run-1', 'confirm' => true, 'override' => true ) );
$sw = array_values( array_filter( $calls, static fn( array $c ): bool => '/migration/switch' === $c[1] ) );
$assert( 'override maps to override_failures (only together with confirm)', true === ( $sw[0][2]['override_failures'] ?? null ) );
$calls = array();
$call( $proto( array( 'migration' ) ), 'uxstudio-migration-switch', array( 'run_id' => 'run-1', 'override' => true ) );
$assert( 'override without confirm does nothing', ! array_filter( $calls, static fn( array $c ): bool => '/migration/switch' === $c[1] ) );
foreach ( array( 'uxstudio-migration-revert', 'uxstudio-migration-rollback' ) as $tool ) {
	$calls = array();
	$r     = $call( $proto( array( 'migration' ) ), $tool, array( 'run_id' => 'run-1' ) );
	$assert( "$tool needs confirm", 'confirmation_required' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) && ! array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] ) );
	$call( $proto( array( 'migration' ) ), $tool, array( 'run_id' => 'run-1', 'confirm' => true ) );
	$assert( "$tool with confirm acts", (bool) array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] ) );
}
$r = $call( $proto( array( 'write' ) ), 'redirects-delete-rules', array( 'ids' => array( 1 ) ) );
$assert( 'destructive redirects ability needs confirm too', 'confirmation_required' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ), $text( $r ) );

$calls = array();
$call( $proto( array( 'migration' ) ), 'uxstudio-migration-run', array( 'sources' => array( 'disable-comments' ) ) );
$run = array_values( array_filter( $calls, static fn( array $c ): bool => '/migration/run' === $c[1] ) );
$assert( 'migration-run defaults to dry_run=true', true === ( $run[0][2]['dry_run'] ?? null ), json_encode( $run ) );
$calls = array();
$call( $proto( array( 'migration' ) ), 'uxstudio-migration-run', array( 'sources' => array( 'disable-comments' ), 'dry_run' => false ) );
$run = array_values( array_filter( $calls, static fn( array $c ): bool => '/migration/run' === $c[1] ) );
$assert( 'a real run needs dry_run=false', false === ( $run[0][2]['dry_run'] ?? null ) );
$r = $call( $proto( array( 'migration' ) ), 'uxstudio-migration-run', array( 'sources' => array( 'x' ), 'dry_run' => 'no' ) );
$assert( 'wrong types are refused (dry_run must be boolean)', 'invalid_input' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ), $text( $r ) );
$r = $call( $proto( array( 'migration' ) ), 'uxstudio-migration-run', array( 'sources' => array( 'x' ), 'bogus' => 1 ) );
$assert( 'unknown parameters are refused', 'invalid_input' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) );

/* ── Logging never stores values ── */

$d = Protocol::describe_args( array( 'values' => array( 'password' => 'hunter2' ), 'module' => 'smtp-email', 'confirm' => true, 'content' => 'private text' ) );
$assert( 'describe_args keeps names and flags only', false === strpos( json_encode( $d ), 'hunter2' ) && false === strpos( json_encode( $d ), 'private text' ) && true === $d['confirm'] && 'smtp-email' === $d['module'] );

/* ── Protocol ── */

$p = $proto( array( 'read' ) );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-03-26' ) ) );
$assert( 'initialize echoes a supported version and advertises tools/resources/prompts', '2025-03-26' === $r['response']['result']['protocolVersion'] && isset( $r['response']['result']['capabilities']['tools'], $r['response']['result']['capabilities']['resources'], $r['response']['result']['capabilities']['prompts'] ) );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize', 'params' => array( 'protocolVersion' => '1999-01-01' ) ) );
$assert( 'initialize falls back to the latest version', Protocol::VERSIONS[0] === $r['response']['result']['protocolVersion'] );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
$assert( 'notifications get 202 and no body', 202 === $r['status'] && null === $r['response'] );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping' ) );
$assert( 'ping', 200 === $r['status'] && isset( $r['response']['result'] ) );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'nope/nope' ) );
$assert( 'unknown method -32601', Protocol::ERR_METHOD === $r['response']['error']['code'] );
$r = $p->handle( array( 'foo' => 'bar' ) );
$assert( 'garbage is an invalid request', 400 === $r['status'] && Protocol::ERR_REQUEST === $r['response']['error']['code'] );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'prompts/list' ) );
$assert( 'prompt migrate-from-plugins is listed', 'migrate-from-plugins' === $r['response']['result']['prompts'][0]['name'] );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'prompts/get', 'params' => array( 'name' => 'migrate-from-plugins' ) ) );
$prompt = $r['response']['result']['messages'][0]['content']['text'] ?? '';
$assert( 'prompt walks scan -> plan -> dry run -> run -> verify -> preview -> switch with confirmation points', 1 === preg_match( '/migration-scan.*migration-plan.*dry_run=true.*dry_run=false.*migration-verify.*migration-preview-start.*migration-switch.*confirm=true/s', $prompt ) && false !== strpos( $prompt, 'STOP and ask the user explicitly' ) && false !== strpos( $prompt, 'Never use override=true unless' ) );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'resources/list' ) );
$uris = array_column( $r['response']['result']['resources'], 'uri' );
$assert( 'resources: modules and overlaps', in_array( 'uxstudio://modules', $uris, true ) && in_array( 'uxstudio://overlaps', $uris, true ) );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'resources/templates/list' ) );
$assert( 'resource template for migration runs', 'uxstudio://migration/run/{id}' === $r['response']['result']['resourceTemplates'][0]['uriTemplate'] );
$r = $p->handle( array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/read', 'params' => array( 'uri' => 'uxstudio://nothing' ) ) );
$assert( 'unknown resource is an error', isset( $r['response']['error'] ) );

/* ── Schema ── */

$schema = array( 'type' => 'object', 'properties' => array( 'a' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ), 'b' => array( 'type' => 'string', 'enum' => array( 'x', 'y' ), 'default' => 'x' ), 'c' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 2 ) ), 'required' => array( 'a' ), 'additionalProperties' => false );
$assert( 'schema accepts valid input', array() === Schema::validate( $schema, array( 'a' => 2, 'b' => 'y', 'c' => array( 'q' ) ) ) );
$assert( 'schema rejects bounds, enum, items, unknown, missing', 5 === count( array_filter( array(
	Schema::validate( $schema, array( 'a' => 9 ) ),
	Schema::validate( $schema, array( 'a' => 1, 'b' => 'z' ) ),
	Schema::validate( $schema, array( 'a' => 1, 'c' => array( 1 ) ) ),
	Schema::validate( $schema, array( 'a' => 1, 'q' => 1 ) ),
	Schema::validate( $schema, array() ),
) ) ) );
$assert( 'defaults are filled', 'x' === Schema::with_defaults( $schema, array( 'a' => 1 ) )['b'] );
$assert( 'booleans are not integers', array() !== Schema::validate( $schema, array( 'a' => true ) ) );

/* ── Tokens ── */

$GLOBALS['uxs_options'] = array();
$now = 1_800_000_000;
$t   = Tokens::create( array( 'label' => 'Claude Code', 'user_id' => 7, 'scopes' => array( 'read', 'migration' ), 'days' => 30, 'ips' => array( '203.0.113.0/24' ) ), $now );
$assert( 'token has the documented shape', 1 === preg_match( '/^uxsmcp_[a-f0-9]{12}_[a-f0-9]{48}$/', $t['token'] ) );
$secret = substr( $t['token'], -48 );
$assert( 'only the hash is stored, never the secret or the token', false === strpos( json_encode( $GLOBALS['uxs_options'] ), $secret ) && false === strpos( json_encode( $GLOBALS['uxs_options'] ), $t['token'] ) && false !== strpos( json_encode( $GLOBALS['uxs_options'] ), hash( 'sha256', $secret ) ) );
$assert( 'the public record has no hash', ! isset( $t['record']['hash'] ) && ! isset( Tokens::all( $now )[0]['hash'] ) );
$v = Tokens::verify( $t['token'], '203.0.113.9', $now + 10 );
$assert( 'valid token verifies', $v['ok'] && array( 'read', 'migration' ) === $v['token']['scopes'] );
$assert( 'expiry is exactly the requested days', $now + 30 * 86400 === (int) $v['token']['expires_at'] );
$assert( 'expired token is refused', 'expired' === Tokens::verify( $t['token'], '203.0.113.9', $now + 30 * 86400 )['code'] );
$assert( 'IP outside the allowlist is refused', 'ip_denied' === Tokens::verify( $t['token'], '198.51.100.1', $now + 10 )['code'] );
$assert( 'allowlist set + unknown IP is refused', 'ip_denied' === Tokens::verify( $t['token'], '', $now + 10 )['code'] );
$assert( 'tampered secret is refused', 'unknown' === Tokens::verify( substr( $t['token'], 0, -1 ) . ( 'a' === substr( $t['token'], -1 ) ? 'b' : 'a' ), '203.0.113.9', $now )['code'] );
$assert( 'malformed bearer is refused', 'malformed' === Tokens::verify( 'abc', '1.1.1.1' )['code'] && 'malformed' === Tokens::verify( 'Bearer x', '1.1.1.1' )['code'] );
$GLOBALS['uxs_user']['pwh'] = 'pw-2';
$assert( 'a password change kills the token', 'user_invalid' === Tokens::verify( $t['token'], '203.0.113.9', $now )['code'] );
$GLOBALS['uxs_user']['pwh'] = 'pw-1';
$GLOBALS['uxs_user']['admin'] = false;
$assert( 'a user who is no longer an administrator loses the token', 'user_invalid' === Tokens::verify( $t['token'], '203.0.113.9', $now )['code'] );
$GLOBALS['uxs_user']['admin'] = true;
$id = $t['record']['id'];
Tokens::touch( $id, '203.0.113.9', $now + 100 );
$assert( 'last used is recorded', gmdate( 'Y-m-d H:i:s', $now + 100 ) === Tokens::find( $id )['last_used'] && '203.0.113.9' === Tokens::find( $id )['last_ip'] );
$list_writes = $GLOBALS['uxs_option_writes'][ Tokens::OPTION ] ?? 0;
Tokens::touch( $id, '203.0.113.9', $now + 400 );
$assert( 'touch never rewrites the token list (no lost revoke), last use lives in its own option', ( $GLOBALS['uxs_option_writes'][ Tokens::OPTION ] ?? 0 ) === $list_writes && isset( $GLOBALS['uxs_options'][ Tokens::USED_PREFIX . $id ] ) );
$gone = Tokens::create( array( 'label' => 'race', 'user_id' => 7, 'scopes' => array( 'read' ), 'days' => 5 ), $now );
$rid  = $gone['record']['id'];
Tokens::revoke( $rid );
Tokens::touch( $rid, '203.0.113.9', $now + 10 );
$assert( 'a touch after revoke neither brings the token back nor leaves a record', ! isset( $GLOBALS['uxs_options'][ Tokens::OPTION ][ $rid ] ) && ! isset( $GLOBALS['uxs_options'][ Tokens::USED_PREFIX . $rid ] ) && 'unknown' === Tokens::verify( $gone['token'], '203.0.113.9', $now )['code'] );
$assert( 'revoke works and is immediate', Tokens::revoke( $id ) && 'unknown' === Tokens::verify( $t['token'], '203.0.113.9', $now )['code'] && ! Tokens::revoke( $id ) );
$assert( 'revoke removes the last-use option too', ! isset( $GLOBALS['uxs_options'][ Tokens::USED_PREFIX . $id ] ) );

foreach ( array(
	'no label'        => array( 'label' => '', 'scopes' => array( 'read' ), 'days' => 5 ),
	'no scopes'       => array( 'label' => 'x', 'scopes' => array( 'root' ), 'days' => 5 ),
	'no expiry'       => array( 'label' => 'x', 'scopes' => array( 'read' ), 'days' => 0 ),
	'expiry too long' => array( 'label' => 'x', 'scopes' => array( 'read' ), 'days' => 91 ),
	'bad ip'          => array( 'label' => 'x', 'scopes' => array( 'read' ), 'days' => 5, 'ips' => array( '999.1.1.1' ) ),
	'bad cidr'        => array( 'label' => 'x', 'scopes' => array( 'read' ), 'days' => 5, 'ips' => array( '10.0.0.0/40' ) ),
) as $name => $args ) {
	try {
		Tokens::create( $args + array( 'user_id' => 7 ), $now );
		$assert( "create refuses: $name", false );
	} catch ( McpError $e ) {
		$assert( "create refuses: $name", true );
	}
}
$ok90 = Tokens::create( array( 'label' => 'x', 'scopes' => array( 'admin' ), 'days' => 90, 'user_id' => 7 ), $now );
$assert( '90 days is the maximum that works', (bool) $ok90['token'] );
$ips = Tokens::clean_ips( array( '10.0.0.1', '2001:db8::/32', '10.0.0.1', 'nope', '' ) );
$assert( 'IP list: valid IPv4/IPv6/CIDR kept once, junk reported', array( '10.0.0.1', '2001:db8::/32' ) === $ips['valid'] && array( 'nope' ) === $ips['invalid'] );
$v6 = Tokens::create( array( 'label' => 'v6', 'scopes' => array( 'read' ), 'days' => 1, 'user_id' => 7, 'ips' => array( '2001:db8::/32' ) ), $now );
$assert( 'IPv6 allowlist works', Tokens::verify( $v6['token'], '2001:db8::5', $now )['ok'] && ! Tokens::verify( $v6['token'], '2001:db9::5', $now )['ok'] );

/* ── Rate limit ── */

$GLOBALS['uxs_transients'] = array();
$ok = 0;
for ( $i = 0; $i < 130; $i++ ) {
	if ( Tokens::hit( 'abc', $now )['ok'] ) {
		++$ok;
	}
}
$assert( 'rate limit: 120 calls per minute, then refused', 120 === $ok );
$hit = Tokens::hit( 'abc', $now );
$assert( 'refusal says when to retry', ! $hit['ok'] && $hit['retry_after'] >= 1 && $hit['retry_after'] <= 60 );
$assert( 'the next minute starts fresh', Tokens::hit( 'abc', $now + 60 )['ok'] );
$assert( 'other tokens are not affected', Tokens::hit( 'other', $now )['ok'] );
$GLOBALS['uxs_transients'] = array();
$assert( 'batch cost: a batch of 20 counts 20 calls against the budget', Tokens::hit( 'batch', $now, 20 )['ok'] );
for ( $i = 0; $i < 4; $i++ ) { Tokens::hit( 'batch', $now, 20 ); }
$assert( 'batch cost: with 100 of 120 used a batch of 30 is refused as a whole, one of 20 still fits', ! Tokens::hit( 'batch', $now, 30 )['ok'] && Tokens::hit( 'batch', $now, 20 )['ok'] );
$assert( 'batch cost: then the budget is used up', ! Tokens::hit( 'batch', $now )['ok'] );

/* ── Auth: routes, scopes per route ── */

$assert( 'route kind: the endpoint', Auth::KIND_ENDPOINT === Auth::route_kind( '/uxstudio/v1/mcp' ) && Auth::KIND_ENDPOINT === Auth::route_kind( '/uxstudio/v1/mcp/' ) );
$assert( 'route kind: third party MCP transports', Auth::KIND_LEGACY === Auth::route_kind( '/mcp/mcp-adapter-default-server' ) && Auth::KIND_LEGACY === Auth::route_kind( '/wp-abilities/v1/abilities' ) );
$assert( 'route kind: nothing else takes a token', '' === Auth::route_kind( '/wp/v2/users' ) && '' === Auth::route_kind( '/uxstudio/v1/mcp-admin/tokens' ) && '' === Auth::route_kind( '/uxstudio/v1/modules' ) && '' === Auth::route_kind( '/wc/v3/orders' ) );
$assert( 'route kind: no dot-segment springboard', '' === Auth::route_kind( '/mcp/../wp/v2/users' ) && '' === Auth::route_kind( '/mcp/%2e%2e/wp/v2/users' ) );

$GLOBALS['uxs_transients'] = array();
$read = Tokens::create( array( 'label' => 'r', 'scopes' => array( 'read' ), 'days' => 5, 'user_id' => 7 ) );
$adm  = Tokens::create( array( 'label' => 'a', 'scopes' => array( 'admin' ), 'days' => 5, 'user_id' => 7 ) );
$c    = Auth::evaluate( $read['token'], Auth::KIND_ENDPOINT, '203.0.113.5' );
$assert( 'read token authenticates on the UX Studio endpoint', 'ok' === $c['state'] && 7 === $c['user_id'] );
$c = Auth::evaluate( $read['token'], Auth::KIND_LEGACY, '203.0.113.5' );
$assert( 'a scoped token is refused on third party routes that know no scopes', 'denied' === $c['state'] && 'scope_route' === $c['code'] );
$c = Auth::evaluate( $adm['token'], Auth::KIND_LEGACY, '203.0.113.5' );
$assert( 'an admin token works there', 'ok' === $c['state'] );
$GLOBALS['uxs_filters']['uxstudio_mcp_rate_limit'] = array( static fn() => 2 );
$GLOBALS['uxs_transients'] = array();
$states = array();
for ( $i = 0; $i < 4; $i++ ) {
	$states[] = Auth::evaluate( $adm['token'], Auth::KIND_ENDPOINT, '203.0.113.5' )['code'];
}
$assert( 'rate limiting surfaces as rate_limited (HTTP 429)', array( 'ok', 'ok', 'rate_limited', 'rate_limited' ) === $states, json_encode( $states ) );
unset( $GLOBALS['uxs_filters']['uxstudio_mcp_rate_limit'] );

// Old AI Assistant JWT tokens keep working, with the admin scope.
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function get_userdata( $id ) { return 7 === (int) $id ? (object) array( 'ID' => 7, 'user_pass' => 'hash7' ) : false; }
$GLOBALS['uxs_jwt_admin'] = true;
function user_can( $user, $cap ) { return 'manage_options' === $cap && $GLOBALS['uxs_jwt_admin']; }
$b64   = static fn( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
$jti   = '11111111-2222-4333-8444-555555555555';
$pwh   = substr( hash_hmac( 'sha256', 'pwh|7|hash7', wp_salt( 'secure_auth' ) ), 0, 32 );
$h     = $b64( '{"typ":"JWT","alg":"HS256"}' );
$body  = $b64( json_encode( array( 'iss' => 'x', 'iat' => time(), 'exp' => time() + 3600, 'user_id' => 7, 'jti' => $jti, 'pwh' => $pwh ) ) );
$jwt   = "$h.$body." . $b64( hash_hmac( 'sha256', "$h.$body", wp_salt( 'secure_auth' ), true ) );
$GLOBALS['uxs_options']['uxstudio_ai_assistant_jwt_token_registry'] = array( $jti => array( 'user_id' => 7, 'issued_at' => time(), 'expires_at' => time() + 3600 ) );
$GLOBALS['uxs_transients'] = array();
$c = Auth::evaluate( $jwt, Auth::KIND_ENDPOINT, '203.0.113.5' );
$assert( 'old JWT tokens still authenticate, as admin', 'ok' === $c['state'] && array( 'admin' ) === $c['scopes'] && 7 === $c['user_id'], json_encode( $c ) );
$c = Auth::evaluate( $jwt, Auth::KIND_LEGACY, '203.0.113.5' );
$assert( 'and on the old adapter routes', 'ok' === $c['state'] );
$GLOBALS['uxs_jwt_admin'] = false;
$GLOBALS['uxs_transients'] = array();
$assert( 'an old JWT of a user who lost manage_options is refused', 'denied' === Auth::evaluate( $jwt, Auth::KIND_ENDPOINT, '203.0.113.5' )['state'] );
$GLOBALS['uxs_jwt_admin'] = true;
$GLOBALS['uxs_transients'] = array();
$assert( 'they are listed in the token list as admin', array( 'admin' ) === \UxStudio\Core\Mcp\LegacyJwt::all()[0]['scopes'] && $jti === \UxStudio\Core\Mcp\LegacyJwt::all()[0]['id'] );
$assert( 'legacy ids are recognised', \UxStudio\Core\Mcp\LegacyJwt::is_legacy_id( $jti ) && ! \UxStudio\Core\Mcp\LegacyJwt::is_legacy_id( 'abcdef123456' ) );
\UxStudio\Core\Mcp\LegacyJwt::revoke( $jti );
$assert( 'a revoked old token stops working', 'denied' === Auth::evaluate( $jwt, Auth::KIND_ENDPOINT, '203.0.113.5' )['state'] );
$assert( 'a tampered JWT is refused', 'denied' === Auth::evaluate( $jwt . 'x', Auth::KIND_ENDPOINT, '203.0.113.5' )['state'] );

/* ── Route the token is judged on: what WordPress dispatches, not a guess (U1, U3) ── */

function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function rest_get_url_prefix() { return 'wp-json'; }

use UxStudio\Core\RestRoute;

$srv = static fn( string $uri, string $method = 'POST', array $extra = array() ): array => array( 'REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method ) + $extra;
$assert( 'route: pretty permalink', '/uxstudio/v1/mcp' === RestRoute::requested( array(), array(), $srv( '/wp-json/uxstudio/v1/mcp' ) ) );
$assert( 'route: subdirectory install and trailing slash', '/uxstudio/v1/mcp' === RestRoute::requested( array(), array(), $srv( '/pobyty/wp-json/uxstudio/v1/mcp/' ) ) );
$assert( 'route: ?rest_route= beats the path', '/wp/v2/users' === RestRoute::requested( array( 'rest_route' => '/wp/v2/users' ), array(), $srv( '/wp-json/uxstudio/v1/mcp' ) ) );
$assert( 'route: POST rest_route beats the permalink (WP::parse_request order)', '/wp/v2/users' === RestRoute::requested( array(), array( 'rest_route' => '/wp/v2/users' ), $srv( '/wp-json/uxstudio/v1/mcp' ) ) );
$assert( 'route: POST rest_route beats an equal GET one', '/wp/v2/users' === RestRoute::requested( array( 'rest_route' => '/wp/v2/users' ), array( 'rest_route' => '/wp/v2/users' ), $srv( '/' ) ) );
$assert( 'route: GET and POST rest_route differ -> none (WordPress answers 400)', '' === RestRoute::requested( array( 'rest_route' => '/uxstudio/v1/mcp' ), array( 'rest_route' => '/wp/v2/users' ), $srv( '/' ) ) );
$assert( 'route: non-string rest_route -> none', '' === RestRoute::requested( array( 'rest_route' => array( 'x' ) ), array(), $srv( '/wp-json/uxstudio/v1/mcp' ) ) );
$assert( 'route: not a REST URL', '' === RestRoute::requested( array(), array(), $srv( '/wp-admin/admin-ajax.php' ) ) );
$assert( 'route: the U1 bypass no longer looks like the MCP route', '' === Auth::route_kind( RestRoute::requested( array(), array( 'rest_route' => '/wp/v2/users', 'roles' => 'administrator' ), $srv( '/wp-json/uxstudio/v1/mcp' ) ) ) );
$assert( 'method: plain', 'POST' === RestRoute::method( array(), $srv( '/', 'post' ) ) );
$assert( 'method: X-HTTP-Method-Override is honoured like the REST server does', 'GET' === RestRoute::method( array(), $srv( '/', 'POST', array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'get' ) ) ) );
$assert( 'method: ?_method= wins over the header', 'DELETE' === RestRoute::method( array( '_method' => 'DELETE' ), $srv( '/', 'POST', array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET' ) ) ) );
$assert( 'outside a REST request the token is not even looked at', ! RestRoute::is_rest() );
Auth::reset();
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $adm['token'];
$_SERVER['REQUEST_URI']        = '/wp-json/uxstudio/v1/mcp';
$GLOBALS['uxs_options']['uxstudio_mcp'] = array( 'enabled' => true );
$assert( 'authenticate() refuses a valid token before REST_REQUEST', false === Auth::authenticate( false ) && null === Auth::context() );
unset( $_SERVER['HTTP_AUTHORIZATION'] );

/* ── AI Assistant tools that can escalate need admin (U2) ── */

use UxStudio\Core\Mcp\Bridge;

foreach ( array( 'users-create', 'users-update', 'users-delete', 'settings-update' ) as $tool ) {
	$assert( "AI tool $tool needs the admin scope", Scopes::ADMIN === Bridge::ai_scope( 'ai-assistant/' . $tool, array( 'readonly' => false ) ) );
	$assert( "a write token cannot run $tool", ! Scopes::allows( array( 'write' ), Bridge::ai_scope( 'ai-assistant/' . $tool, array() ) ) );
}
$assert( 'AI read tools stay read', Scopes::READ === Bridge::ai_scope( 'ai-assistant/users-list', array( 'readonly' => true ) ) );
$assert( 'other AI write tools stay write', Scopes::WRITE === Bridge::ai_scope( 'ai-assistant/posts-create', array( 'readonly' => false ) ) );

/* ── R4: non-readonly AI tools need admin unless allowlisted as write ── */

$write_tools = array( 'posts-create', 'posts-update', 'posts-delete', 'pages-create', 'pages-update', 'pages-delete', 'categories-create', 'categories-update', 'categories-delete', 'tags-create', 'tags-update', 'tags-delete', 'media-delete', 'woo-products-create', 'woo-products-update', 'woo-products-delete' );
foreach ( $write_tools as $tool ) {
	$assert( "R4: $tool is write", Scopes::WRITE === Bridge::ai_scope( 'ai-assistant/' . $tool, array( 'readonly' => false ) ) );
}
$assert( 'R4: the allowlist is exactly the content tools', count( $write_tools ) === count( Bridge::AI_WRITE_TOOLS ) );
foreach ( array( 'woo-orders-create', 'elementor-update-widget', 'some-future-tool', 'plugins-install' ) as $tool ) {
	$assert( "R4: $tool defaults to admin", Scopes::ADMIN === Bridge::ai_scope( 'ai-assistant/' . $tool, array( 'readonly' => false ) ) );
}
$assert( 'R4: a new read-only tool is read', Scopes::READ === Bridge::ai_scope( 'ai-assistant/some-future-tool', array( 'readonly' => true ) ) );

/* ── R1: a token without admin never has unfiltered_html (stored XSS -> admin takeover) ── */

$GLOBALS['uxs_caps']     = array( 'unfiltered_html' => true, 'edit_posts' => true, 'manage_options' => true );
$GLOBALS['uxs_editable'] = array( 10 );
// Mimics WP_User::has_cap(): the user_has_cap filter (Auth::limit_caps) runs on every check.
function current_user_can( $cap, ...$args ) {
	$caps = Auth::limit_caps( $GLOBALS['uxs_caps'], array( $cap ), array( $cap ), null );
	if ( 'edit_post' === $cap ) {
		return ! empty( $caps['edit_posts'] ) && in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['uxs_editable'], true );
	}
	return ! empty( $caps[ $cap ] );
}
$tok = static fn( array $scopes, string $state = 'ok' ): array => array( 'state' => $state, 'code' => $state, 'id' => 't1', 'label' => 'x', 'scopes' => $scopes, 'user_id' => 7, 'ip' => '203.0.113.5' );

Auth::reset( null );
$assert( 'R1: no token (cookie admin) keeps unfiltered_html', current_user_can( 'unfiltered_html' ) && ! Auth::restricted() );
foreach ( array( array( 'read' ), array( 'write' ), array( 'migration' ), array( 'read', 'write', 'migration' ) ) as $scopes ) {
	Auth::reset( $tok( $scopes ) );
	$label = implode( '+', $scopes );
	$assert( "R1: a $label token loses unfiltered_html", Auth::restricted() && ! current_user_can( 'unfiltered_html' ) );
	$assert( "R1: a $label token keeps its other capabilities", current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ) );
}
Auth::reset( $tok( array( 'admin' ) ) );
$assert( 'R1: an admin token keeps unfiltered_html', ! Auth::restricted() && current_user_can( 'unfiltered_html' ) );
Auth::reset( $tok( array( 'write', 'admin' ) ) );
$assert( 'R1: write+admin is admin', current_user_can( 'unfiltered_html' ) );
Auth::reset( $tok( array( 'write' ), 'denied' ) );
$assert( 'R1: a refused token changes nothing (no user anyway)', ! Auth::restricted() );
Auth::reset( $tok( array( 'write' ) ) );
$assert( 'R1: limit_caps leaves a user without unfiltered_html alone', array( 'edit_posts' => true ) === Auth::limit_caps( array( 'edit_posts' => true ) ) );

/* ── R1: WooCommerce meta_data of a restricted token ── */

use UxStudio\Modules\AiAssistant\Mcp\Tools\RestEndpointTool;

function wp_kses_post( $v ) { return preg_replace( '/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', strip_tags( preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $v ), '<p><a><strong><em><img>' ) ); }
function esc_url_raw( $v ) { return preg_match( '#^(https?:)?//|^/(?!/)|^\#|^mailto:#i', (string) $v ) ? (string) $v : ''; }
function is_protected_meta( $key, $type = '' ) { return '_' === substr( (string) $key, 0, 1 ); }

Auth::reset( $tok( array( 'write' ) ) );
$g = RestEndpointTool::guard_meta( array( 'name' => 'P', 'meta_data' => array( array( 'key' => '_elementor_data', 'value' => '[]' ) ) ) );
$assert( 'R1: write token cannot set protected meta (_elementor_data) over woo meta_data', is_wp_error( $g ) );
$g = RestEndpointTool::guard_meta( array( 'meta_data' => array( array( 'key' => 'hero_text', 'value' => array( 'a' => '<script>alert(1)</script><strong>ok</strong>' ) ) ) ) );
$assert( 'R1: write token meta_data strings are kses-filtered', ! is_wp_error( $g ) && '<strong>ok</strong>' === $g['meta_data'][0]['value']['a'] );
Auth::reset( $tok( array( 'admin' ) ) );
$raw = array( 'meta_data' => array( array( 'key' => '_custom', 'value' => '<script>x</script>' ) ) );
$assert( 'R1: admin token meta_data is untouched', $raw === RestEndpointTool::guard_meta( $raw ) );

/* ── R5: wp/v2 `meta` object of a restricted token (Elementor meta -> stored XSS) ── */

$xss = '[{"id":"a1","elType":"widget","widgetType":"html","settings":{"html":"<script>alert(1)</script>"}}]';
Auth::reset( $tok( array( 'write' ) ) );
$g = RestEndpointTool::guard_meta( array( 'id' => '5', 'meta' => array( '_elementor_edit_mode' => 'builder', '_elementor_data' => $xss ) ), 'post' );
$assert( 'R5: write token cannot set _elementor_data/_elementor_edit_mode over wp/v2 meta', is_wp_error( $g ) && 403 === $g->get_error_data()['status'] );
foreach ( array( '_elementor_page_settings' => array( 'custom_css' => 'x' ), '_elementor_conditions' => array( 'include/general' ), '_elementor_template_type' => 'wp-page', '_edit_lock' => '1' ) as $k => $v ) {
	$assert( "R5: write token cannot set protected $k", is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( $k => $v ) ), 'post' ) ) );
}
$assert( 'R5: deleting protected meta (null) is refused too', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_elementor_data' => null ) ), 'post' ) ) );
$assert( 'R5: protected term meta is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_term_secret' => 'x' ) ), 'term' ) ) );
$assert( 'R5: protected user meta is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_user_x' => 'x' ) ), 'user' ) ) );
$assert( 'R5: protected comment meta is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_wp_note_status' => 'x' ) ), 'comment' ) ) );
$assert( 'R5: an empty key is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '' => 'x' ) ), 'post' ) ) );
$assert( 'R5: meta that is not an object is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => 'x' ), 'post' ) ) );
$g = RestEndpointTool::guard_meta( array( 'title' => 'T', 'meta' => array( 'hero' => '<script>alert(1)</script><strong>ok</strong>', 'opts' => array( 'a' => '<img src="x" onerror="alert(1)">' ), 'count' => 3 ) ), 'post' );
$assert( 'R5: other meta strings are kses-filtered (nested too), scalars kept', ! is_wp_error( $g ) && '<strong>ok</strong>' === $g['meta']['hero'] && '<img src="x">' === $g['meta']['opts']['a'] && 3 === $g['meta']['count'] && 'T' === $g['title'], json_encode( $g ) );
$fn = '[{"content":"<a href=\"https://x.test\">x</a>","id":"f1"}]';
$g  = RestEndpointTool::guard_meta( array( 'meta' => array( 'footnotes' => $fn ) ), 'post' );
$assert( 'R5: footnotes is left to core (its JSON is not run through kses here)', ! is_wp_error( $g ) && $fn === $g['meta']['footnotes'] );
$g = RestEndpointTool::guard_meta( array( 'meta' => array( '_yoast_wpseo_title' => '<b>SEO</b> title', '_uxstudio_ai_seo_description' => 'Popis', '_yoast_wpseo_focuskw' => null ) ), 'post' );
$assert( 'R5: allowlisted SEO text meta passes as plain text', ! is_wp_error( $g ) && 'SEO title' === $g['meta']['_yoast_wpseo_title'] && 'Popis' === $g['meta']['_uxstudio_ai_seo_description'] && null === $g['meta']['_yoast_wpseo_focuskw'] );
$assert( 'R5: allowlisted SEO meta must be a string', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_yoast_wpseo_title' => array( '<script>' ) ) ), 'post' ) ) );
$assert( 'R5: the SEO allowlist is post meta only', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_yoast_wpseo_title' => 'x' ) ), 'term' ) ) );
$g = RestEndpointTool::guard_meta( array( 'meta' => (object) array( '_elementor_data' => $xss ) ), 'post' );
$assert( 'R5: a meta object (stdClass) is checked as well', is_wp_error( $g ) );
$assert( 'R5: input without meta is untouched', array( 'title' => '<b>x</b>' ) === RestEndpointTool::guard_meta( array( 'title' => '<b>x</b>' ), 'post' ) );
Auth::reset( $tok( array( 'admin' ) ) );
$raw = array( 'meta' => array( '_elementor_edit_mode' => 'builder', '_elementor_data' => $xss ) );
$assert( 'R5: admin token may still write Elementor meta', $raw === RestEndpointTool::guard_meta( $raw, 'post' ) );
Auth::reset( null );
$assert( 'R5: no token (cookie admin with unfiltered_html) is untouched', $raw === RestEndpointTool::guard_meta( $raw, 'post' ) );

/* ── R6: protected meta sent back unchanged (GET round trip) is dropped, changed is refused ── */

$GLOBALS['uxs_stored'] = array(
	'post' => array(
		5 => array(
			'_elementor_data'      => array( $xss ),
			'_elementor_edit_mode' => array( 'builder' ),
			'_acf_changed'         => array( '' ),
			'_wc_count'            => array( '3' ),
			'_settings'            => array( array( 'b' => 2, 'a' => '1' ) ),
			'_multi'               => array( 'x', 'y' ),
		),
	),
);
$GLOBALS['uxs_mids'] = array(
	91 => (object) array( 'meta_id' => 91, 'post_id' => 5, 'meta_key' => '_price', 'meta_value' => '10' ),
	92 => (object) array( 'meta_id' => 92, 'post_id' => 5, 'meta_key' => 'colour', 'meta_value' => 'red' ),
	93 => (object) array( 'meta_id' => 93, 'post_id' => 5, 'meta_key' => '_elementor_data', 'meta_value' => $xss ),
	94 => (object) array( 'meta_id' => 94, 'post_id' => 6, 'meta_key' => '_elementor_data', 'meta_value' => $xss ),
);
function get_metadata( $type, $id, $key = '', $single = false ) { return $GLOBALS['uxs_stored'][ $type ][ (int) $id ][ $key ] ?? array(); }
function get_metadata_by_mid( $type, $mid ) { return 'post' === $type ? ( $GLOBALS['uxs_mids'][ (int) $mid ] ?? false ) : false; }

Auth::reset( $tok( array( 'write' ) ) );
$rt = array( 'hero' => 'Hi', '_elementor_data' => $xss, '_elementor_edit_mode' => 'builder', '_acf_changed' => false, '_wc_count' => 3, '_settings' => array( 'a' => 1, 'b' => '2' ), '_multi' => array( 'x', 'y' ), '_missing' => '' );
$g  = RestEndpointTool::guard_meta( array( 'meta' => $rt ), 'post', 5 );
$assert( 'R6: unchanged protected meta from GET is dropped, the rest kept', ! is_wp_error( $g ) && array( 'hero' => 'Hi' ) === $g['meta'], is_wp_error( $g ) ? $g->get_error_message() : json_encode( $g ) );
$g = RestEndpointTool::guard_meta( array( 'meta' => array( '_elementor_data' => $xss . ' ', '_acf_changed' => true, 'hero' => 'x' ) ), 'post', 5 );
$assert( 'R6: changed protected meta is refused and the error lists the keys', is_wp_error( $g ) && 403 === $g->get_error_data()['status'] && array( '_elementor_data', '_acf_changed' ) === $g->get_error_data()['keys'] && str_contains( $g->get_error_message(), '_elementor_data, _acf_changed' ) );
$assert( 'R6: same value on another object is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_elementor_data' => $xss ) ), 'post', 6 ) ) );
$assert( 'R6: without an object id (create) it is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_elementor_edit_mode' => 'builder' ) ), 'post', 0 ) ) );
$assert( 'R6: deleting stored protected meta (null) is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_elementor_edit_mode' => null ) ), 'post', 5 ) ) );
$assert( 'R6: a value missing a row of multi meta is refused', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_multi' => array( 'x' ) ) ), 'post', 5 ) ) );
$assert( 'R6: meta of another type with the same id is not compared', is_wp_error( RestEndpointTool::guard_meta( array( 'meta' => array( '_elementor_edit_mode' => 'builder' ) ), 'term', 5 ) ) );

/* ── R7: WooCommerce meta_data with the id of an existing row ── */

$md = static fn( array $items, int $id = 5 ) => RestEndpointTool::guard_meta( array( 'meta_data' => $items ), 'post', $id );
$g  = $md( array( array( 'id' => 91, 'key' => 'note', 'value' => 'x' ) ) );
$assert( 'R7: renaming a protected row (_price) by its id is refused', is_wp_error( $g ) && array( '_price' ) === $g->get_error_data()['keys'] );
$assert( 'R7: string id works the same', is_wp_error( $md( array( array( 'id' => '91', 'key' => 'note', 'value' => 'x' ) ) ) ) );
$assert( 'R7: a non numeric id is refused', is_wp_error( $md( array( array( 'id' => '91abc', 'key' => 'note', 'value' => 'x' ) ) ) ) );
$g = $md( array( array( 'id' => 92, 'key' => 'colour', 'value' => '<script>x</script>blue' ) ) );
$assert( 'R7: updating an unprotected row by id works (kses-filtered)', ! is_wp_error( $g ) && 'blue' === $g['meta_data'][0]['value'] && 92 === $g['meta_data'][0]['id'] );
$g = $md( array( array( 'id' => 93, 'key' => '_elementor_data', 'value' => $xss ), array( 'id' => 92, 'key' => 'colour', 'value' => 'red' ) ) );
$assert( 'R7: an unchanged protected row from GET is dropped', ! is_wp_error( $g ) && 1 === count( $g['meta_data'] ) && 'colour' === $g['meta_data'][0]['key'] );
$assert( 'R7: a changed protected row is refused', is_wp_error( $md( array( array( 'id' => 93, 'key' => '_elementor_data', 'value' => '[]' ) ) ) ) );
$assert( 'R7: a protected row of another product is refused', is_wp_error( $md( array( array( 'id' => 94, 'key' => '_elementor_data', 'value' => $xss ) ) ) ) );
$assert( 'R7: a protected key without id on create is refused', is_wp_error( $md( array( array( 'key' => '_elementor_data', 'value' => $xss ) ), 0 ) ) );
$g = $md( array( array( 'key' => '_elementor_data', 'value' => $xss ) ) );
$assert( 'R7: a protected key without id but unchanged is dropped', ! is_wp_error( $g ) && array() === $g['meta_data'] );
$assert( 'R7: renaming an unprotected row to a protected key is refused', is_wp_error( $md( array( array( 'id' => 92, 'key' => '_price', 'value' => '1' ) ) ) ) );
$assert( 'R7: ... also when the value equals the row value', is_wp_error( $md( array( array( 'id' => 92, 'key' => '_price', 'value' => 'red' ) ) ) ) );

/* ── R8: WooCommerce downloads[].file of a restricted token ── */

function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); }
function trailingslashit( $v ) { return untrailingslashit( $v ) . '/'; }
function wp_upload_dir( $time = null, $create = true ) { return array( 'baseurl' => 'https://shop.test/wp-content/uploads', 'basedir' => '/srv/www/wp-content/uploads' ); }
function site_url( $p = '' ) { return 'https://shop.test' . $p; }
function home_url( $p = '' ) { return 'https://shop.test' . $p; }

$approved = static fn( string $url ): bool => 0 === strpos( $url, 'https://shop.test/wp-content/uploads/woocommerce_uploads/' ) || 0 === strpos( $url, 'https://cdn.example.com/files/' ) || 0 === strpos( $url, 'https://shop.test/' );
$dl       = static fn( $file ) => RestEndpointTool::guard_downloads( array( 'name' => 'P', 'downloads' => array( array( 'name' => 'F', 'file' => $file ) ) ), $approved );

Auth::reset( $tok( array( 'write' ) ) );
foreach ( array( 'https://shop.test/wp-content/uploads/woocommerce_uploads/2026/11/e-book.pdf', 'https://cdn.example.com/files/a.zip?sig=abc%20def&x=1' ) as $ok ) {
	$assert( "R8: approved http(s) file passes: $ok", ! is_wp_error( $dl( $ok ) ) );
}
$bad_files = array(
	'/etc/passwd', 'wp-includes/sodium_compat/LICENSE', '../wp-config.php', 'wp-config.php', 'C:\\xampp\\passwords.txt', 'C:/xampp/passwords.txt',
	'file:///etc/passwd', 'FILE:///etc/passwd', '//cdn.example.com/files/a.zip', 'HTTPS://cdn.example.com/files/a.zip', 'ftp://cdn.example.com/files/a.zip',
	'[download id="1"]', '[x]https://cdn.example.com/files/a.zip', ' https://cdn.example.com/files/a.zip', "https://cdn.example.com/files/a.zip\n",
	'https://cdn.example.com/files/../../etc/passwd', 'https://cdn.example.com/files/%2e%2e/%2e%2e/etc/passwd', 'https://cdn.example.com/files/%252e%252e/x',
	'https://cdn.example.com/files/..%5c..%5cx', 'https://cdn.example.com/files/a%5cb', 'https://cdn.example.com/files/a.zip%00.pdf', 'https://cdn.example.com/files\\..\\x', 'https://user@cdn.example.com/files/a.zip',
	'https://cdn.example.com/files/a.zip?u=https://shop.test/wp-config.php', 'https://cdn.example.com/files/a.zip?u=https%3A%2F%2Fshop.test%2Fwp-config.php',
	'https://shop.test/wp-config.php', 'http://shop.test/wp-config.php', 'https://SHOP.test/wp-config.php', 'https://shop.test/wp-content/uploads-old/backup.sql',
	'https://evil.example.com/x.zip', 'https://cdn.example.com', 'https://cdn.example.com:99999x/files/a',
);
foreach ( $bad_files as $bad ) {
	$assert( 'R8: refused download file ' . json_encode( $bad ), is_wp_error( $dl( $bad ) ) );
}
$assert( 'R8: a file that is not a string is refused', is_wp_error( $dl( array( '/etc/passwd' ) ) ) );
$assert( 'R8: downloads that are not a list are refused', is_wp_error( RestEndpointTool::guard_downloads( array( 'downloads' => '/etc/passwd' ), $approved ) ) );
$assert( 'R8: an item without a file passes (WooCommerce skips it)', ! is_wp_error( RestEndpointTool::guard_downloads( array( 'downloads' => array( array( 'id' => 'x' ) ) ), $approved ) ) );
$assert( 'R8: the http variant of the uploads URL passes when approved', ! is_wp_error( RestEndpointTool::guard_downloads( array( 'downloads' => array( array( 'file' => 'http://shop.test/wp-content/uploads/woocommerce_uploads/a.pdf' ) ) ), static fn() => true ) ) );
$assert( 'R8: a well formed URL outside approved directories is refused', is_wp_error( RestEndpointTool::guard_downloads( array( 'downloads' => array( array( 'file' => 'https://other.example.com/a.zip' ) ) ), static fn() => false ) ) );
$assert( 'R8: without WooCommerce nothing is approved (fail closed)', false === RestEndpointTool::woo_approved_download( 'https://cdn.example.com/files/a.zip' ) );
Auth::reset( $tok( array( 'admin' ) ) );
$assert( 'R8: an admin token may set a local path', ! is_wp_error( $dl( 'wp-includes/sodium_compat/LICENSE' ) ) );
Auth::reset( null );
$assert( 'R8: no token (cookie admin) is untouched', ! is_wp_error( $dl( '/etc/passwd' ) ) );
$assert( 'R8: query filter blocks writes to the approved directories table', '' === RestEndpointTool::block_download_directory_writes( "INSERT INTO `wp_wc_product_download_directories` (`url`, `enabled`) VALUES ('file:///etc/', 1)" ) && '' === RestEndpointTool::block_download_directory_writes( ' update wp_wc_product_download_directories SET enabled = 1' ) );
$sel = 'SELECT 1 FROM `wp_wc_product_download_directories` WHERE enabled = 1';
$assert( 'R8: query filter leaves reads and other tables alone', $sel === RestEndpointTool::block_download_directory_writes( $sel ) && 'INSERT INTO wp_posts' === RestEndpointTool::block_download_directory_writes( 'INSERT INTO wp_posts' ) );

/* ── R9: a wrapped route that is not registered gives no tool (fail closed) ── */

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

$GLOBALS['uxs_abilities'] = array();
function wp_register_ability( $name, $args ) { $GLOBALS['uxs_abilities'][ $name ] = $args; }
function rest_get_server() {
	return new class() {
		public function get_routes( $ns ) {
			return array( '/wc/v3/products/(?P<id>[\d]+)' => array( array( 'methods' => array( 'GET' => true ), 'args' => array( 'context' => array( 'type' => 'string' ) ) ) ) );
		}
	};
}
$tool = new class() extends RestEndpointTool {
	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'p-get', 'G', 'G', 'GET', '/products/(?P<id>[\d]+)', 'wc/v3' ),
			new RestOperationConfig( 'p-put', 'U', 'U', 'PUT', '/products/(?P<id>[\d]+)', 'wc/v3' ),
			new RestOperationConfig( 'p-list', 'L', 'L', 'GET', '/products', 'wc/v3' ),
		);
	}
};
$tool->register();
$assert( 'R9: a matched route is registered with additionalProperties:false', false === ( $GLOBALS['uxs_abilities']['ai-assistant/p-get']['input_schema']['additionalProperties'] ?? null ) );
$assert( 'R9: an unknown route or method registers no tool', ! isset( $GLOBALS['uxs_abilities']['ai-assistant/p-put'] ) && ! isset( $GLOBALS['uxs_abilities']['ai-assistant/p-list'] ) );

$assert( 'R5: meta_type of posts/pages/media is post', 'post' === RestEndpointTool::meta_type( 'wp/v2', '/pages/(?P<id>[\d]+)' ) && 'post' === RestEndpointTool::meta_type( 'wp/v2', '/posts' ) && 'post' === RestEndpointTool::meta_type( 'wp/v2', '/media/(?P<id>[\d]+)' ) );
$assert( 'R5: meta_type of categories/tags is term', 'term' === RestEndpointTool::meta_type( 'wp/v2', '/categories' ) && 'term' === RestEndpointTool::meta_type( 'wp/v2', '/tags/(?P<id>[\d]+)' ) );
$assert( 'R5: meta_type of users/comments', 'user' === RestEndpointTool::meta_type( 'wp/v2', '/users/(?P<id>[\d]+)' ) && 'comment' === RestEndpointTool::meta_type( 'wp/v2', '/comments' ) );
$assert( 'R5: meta_type of WooCommerce routes is post', 'post' === RestEndpointTool::meta_type( 'wc/v3', '/products/(?P<id>[\d]+)' ) && 'post' === RestEndpointTool::meta_type( 'wc/v3', '/orders' ) );

/* ── R3: URL parameters can only fill their own route ── */

$br = static fn( string $route, array $in ) => RestEndpointTool::build_route( $route, $in );
$ok = $br( '/products/(?P<id>[\d]+)', array( 'id' => '42', 'name' => 'X' ) );
$assert( 'R3: numeric id fills the route, the rest stays body', ! is_wp_error( $ok ) && '/products/42' === $ok['route'] && array( 'name' => 'X' ) === $ok['input'] );
$ok = $br( '/products/(?P<id>[\d]+)', array( 'id' => 42 ) );
$assert( 'R3: integer id is accepted', ! is_wp_error( $ok ) && '/products/42' === $ok['route'] );
$assert( 'R3: id "batch" (PUT products/batch deletes products) is refused', is_wp_error( $br( '/products/(?P<id>[\d]+)', array( 'id' => 'batch', 'delete' => array( 1, 2 ) ) ) ) );
$assert( 'R3: id "1/application-passwords" is refused', is_wp_error( $br( '/users/(?P<id>[\d]+)', array( 'id' => '1/application-passwords' ) ) ) );
foreach ( array( '1?context=edit', '1#x', '../1', '1%2F2', "1\n", ' 1', '', true, array( 1 ), 1.5 ) as $bad ) {
	$assert( 'R3: id ' . json_encode( $bad ) . ' is refused', is_wp_error( $br( '/posts/(?P<id>[\d]+)', array( 'id' => $bad ) ) ) );
}
$assert( 'R3: a missing URL parameter is refused', is_wp_error( $br( '/posts/(?P<id>[\d]+)', array() ) ) );
$ok = $br( '/types/(?P<type>[\w-]+)', array( 'type' => 'wp_block' ) );
$assert( 'R3: slug parameter within its regex', ! is_wp_error( $ok ) && '/types/wp_block' === $ok['route'] );
$assert( 'R3: a slash is refused even where the route regex is looser', is_wp_error( $br( '/types/(?P<type>.+)', array( 'type' => 'post/x' ) ) ) );
$assert( 'R3: "$1" in a value cannot act as a backreference', is_wp_error( $br( '/posts/(?P<id>[\d]+)', array( 'id' => '$1' ) ) ) );
$ok = $br( '/categories', array( 'name' => 'a/b' ) );
$assert( 'R3: routes without parameters pass the body through', ! is_wp_error( $ok ) && '/categories' === $ok['route'] && array( 'name' => 'a/b' ) === $ok['input'] );
$assert( 'R3: url_params reads named groups', array( 'id' => '[\d]+', 'type' => '[\w-]+' ) === RestEndpointTool::url_params( '/a/(?P<id>[\d]+)/(?P<type>[\w-]+)' ) );

/* ── R2: elementor-update-widget ── */

use UxStudio\Modules\AiAssistant\Mcp\Tools\ElementorTools;

function get_post( $id ) { return in_array( (int) $id, array( 10, 11 ), true ) ? (object) array( 'ID' => (int) $id ) : null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['uxs_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['uxs_meta'][ $id ][ $key ] = stripslashes( $value ); return true; } // WordPress unslashes.
function wp_slash( $v ) { return addslashes( $v ); }

$doc = static fn(): string => json_encode( array( array( 'id' => 'sec1', 'elType' => 'section', 'elements' => array( array( 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Old' ) ) ) ) ) );
$el  = new ElementorTools();
$GLOBALS['uxs_meta'] = array( 10 => array( '_elementor_data' => $doc() ), 11 => array( '_elementor_data' => $doc() ) );
$title = static fn( int $id ) => (array) ( json_decode( (string) $GLOBALS['uxs_meta'][ $id ]['_elementor_data'], true )[0]['elements'][0]['settings'] ?? array() );

Auth::reset( $tok( array( 'admin' ) ) );
$res = $el->update_widget_content( array( 'post_id' => 10, 'widget_id' => 'w1', 'settings' => json_encode( array( 'title' => 'Nové "jméno" a/b' ) ) ) );
$assert( 'R2: update works and survives update_post_meta unslashing (wp_slash)', ! is_wp_error( $res ) && 'Nové "jméno" a/b' === ( $title( 10 )['title'] ?? null ), (string) $GLOBALS['uxs_meta'][10]['_elementor_data'] );
$res = $el->update_widget_content( array( 'post_id' => 11, 'widget_id' => 'w1', 'settings' => '{"title":"x"}' ) );
$assert( 'R2: a post the user may not edit is refused', is_wp_error( $res ) && 'Old' === $title( 11 )['title'] );
$res = $el->update_widget_content( array( 'post_id' => 999, 'widget_id' => 'w1', 'settings' => '{"title":"x"}' ) );
$assert( 'R2: a missing post is refused', is_wp_error( $res ) );
$before = $GLOBALS['uxs_meta'][10]['_elementor_data'];
$res    = $el->update_widget_content( array( 'post_id' => 10, 'widget_id' => 'nope', 'settings' => '{"title":"x"}' ) );
$assert( 'R2: an unknown widget writes nothing', $before === $GLOBALS['uxs_meta'][10]['_elementor_data'] && str_contains( (string) $res[0]['text'], 'not found' ) );
$el->update_widget_content( array( 'post_id' => 10, 'widget_id' => 'w1', 'settings' => '["a"]' ) );
$assert( 'R2: settings must be an object', $before === $GLOBALS['uxs_meta'][10]['_elementor_data'] );
Auth::reset( $tok( array( 'write' ) ) );
$el->update_widget_content( array( 'post_id' => 10, 'widget_id' => 'w1', 'settings' => json_encode( array( 'title' => '<script>alert(1)</script>Hi', 'link' => array( 'url' => 'javascript:alert(1)' ) ) ) ) );
$set = $title( 10 );
$assert( 'R2: without unfiltered_html the settings are kses-filtered', 'Hi' === $set['title'] && '' === $set['link']['url'] );
$assert( 'R2: the tool needs the admin scope over MCP', Scopes::ADMIN === Bridge::ai_scope( 'ai-assistant/elementor-update-widget', array( 'readonly' => false ) ) );
Auth::reset( null );

/* ── Config: default off, legacy value migrated once ── */

$GLOBALS['uxs_options'] = array();
$assert( 'MCP server is OFF by default', false === Config::enabled() );
$GLOBALS['uxs_options'] = array( 'uxstudio_ai_assistant' => array( 'mcp_enabled' => true ) );
$assert( 'the old AI Assistant switch is migrated', true === Config::enabled() && true === $GLOBALS['uxs_options']['uxstudio_mcp']['enabled'] );
$GLOBALS['uxs_options']['uxstudio_ai_assistant']['mcp_enabled'] = false;
$assert( 'ONCE: later changes of the old setting do not leak in', true === Config::enabled() );
Config::set_enabled( false );
$assert( 'the new switch is authoritative', false === Config::enabled() );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
