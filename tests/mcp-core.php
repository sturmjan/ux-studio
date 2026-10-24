<?php
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
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
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
Internal::set_transport(
	static function ( string $method, string $route, array $params ) use ( &$calls ): array {
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
	}
);

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
$r     = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-update', array( 'module' => 'smtp-email', 'values' => array( 'host' => 'mail.example.test' ), 'dry_run' => true ) );
$assert( 'dry_run writes nothing and shows the change', ! array_filter( $calls, static fn( array $c ): bool => 'POST' === $c[0] ) && false !== strpos( $text( $r ), 'mail.example.test' ), $text( $r ) );
$r = $call( $proto( array( 'write' ) ), 'uxstudio-module-settings-get', array( 'module' => 'smtp-email' ) );
$assert( 'write token includes read abilities', ! isset( $r['response']['error'] ) );

/* ── Guard refusal surfaced ── */

$r    = $call( $proto( array( 'write' ) ), 'uxstudio-module-enable', array( 'module' => 'cron-control' ) );
$body = json_decode( $text( $r ), true );
$assert( 'module-enable refused by the overlap guard: structured error', true === ( $r['response']['result']['isError'] ?? false ) && 'uxstudio_plugin_overlap' === ( $body['error']['code'] ?? '' ) && 'wp-crontrol' === ( $body['error']['data']['plugins'][0]['slug'] ?? '' ), $text( $r ) );
$r = $call( $proto( array( 'write' ) ), 'uxstudio-module-enable', array( 'module' => 'Bad Id!' ) );
$assert( 'module id is validated', 'invalid_input' === ( json_decode( $text( $r ), true )['error']['code'] ?? '' ) );
$calls = array();
$r     = $call( $proto( array( 'write' ) ), 'uxstudio-module-enable', array( 'module' => 'activity-log' ) );
$assert( 'module-enable posts to the same route as the UI', array_filter( $calls, static fn( array $c ): bool => '/modules/activity-log' === $c[1] && true === $c[2]['enabled'] ) && false === ( $r['response']['result']['isError'] ?? false ) );

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
$assert( 'revoke works and is immediate', Tokens::revoke( $id ) && 'unknown' === Tokens::verify( $t['token'], '203.0.113.9', $now )['code'] && ! Tokens::revoke( $id ) );

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
$assert( 'they are listed in the token list as admin', array( 'admin' ) === \UxStudio\Core\Mcp\LegacyJwt::all()[0]['scopes'] && $jti === \UxStudio\Core\Mcp\LegacyJwt::all()[0]['id'] );
$assert( 'legacy ids are recognised', \UxStudio\Core\Mcp\LegacyJwt::is_legacy_id( $jti ) && ! \UxStudio\Core\Mcp\LegacyJwt::is_legacy_id( 'abcdef123456' ) );
\UxStudio\Core\Mcp\LegacyJwt::revoke( $jti );
$assert( 'a revoked old token stops working', 'denied' === Auth::evaluate( $jwt, Auth::KIND_ENDPOINT, '203.0.113.5' )['state'] );
$assert( 'a tampered JWT is refused', 'denied' === Auth::evaluate( $jwt . 'x', Auth::KIND_ENDPOINT, '203.0.113.5' )['state'] );

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
