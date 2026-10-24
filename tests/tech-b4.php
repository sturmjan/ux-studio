<?php
/**
 * Standalone test of PLAN.md 27.10 (B4, "Technika"): the API mailers (SendGrid, Mailgun, Postmark, Amazon SES with SigV4 in PHP),
 * the fallback connection and its log, the update notices / checks / security exception of Disable Auto Updates, the URL
 * events of Cron Control with their SSRF guard, and the options of Auto Image Upload. Needs no WordPress and no database:
 *
 *   php tests/tech-b4.php
 *
 * WordPress is a small double (hooks with priorities, options, an HTTP double that records every request); the real requests and
 * screens are covered by the live checks on the site.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PHP_INT_MAX_MINUS', PHP_INT_MAX - 10 );

$root = dirname( __DIR__ );

$GLOBALS['uxs_options']  = array();
$GLOBALS['uxs_hooks']    = array();
$GLOBALS['uxs_removed']  = array();
$GLOBALS['uxs_http']     = array(); // every request the HTTP double saw
$GLOBALS['uxs_http_map'] = array(); // host => response (array code, body) or WP_Error
$GLOBALS['uxs_cron']     = array();
$GLOBALS['uxs_cron_now'] = false;
$GLOBALS['uxs_user']     = null;
$GLOBALS['uxs_mail']     = array(); // wp_mail() double: calls
$GLOBALS['uxs_mail_ok']  = true;
$GLOBALS['uxs_posts']    = array();

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class FakePhpMailer {
	public $Host; public $Port; public $Username; public $Password; public $SMTPAuth; public $SMTPSecure; public $SMTPAutoTLS;
	public function isSMTP() {}
}
class FakeUser {
	public $ID = 0; public $user_login = '';
	public function __construct( int $id = 0, string $login = '' ) { $this->ID = $id; $this->user_login = $login; }
	public function exists(): bool { return $this->ID > 0; }
}
class FakeWpdb {
	public $prefix = 'wp_'; public $rows = array();
	public function insert( $table, $data, $f = null ) { $this->rows[] = $data; return 1; }
	public function prepare( $sql, ...$args ) { return $sql; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

/* ── hooks ── */
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_hooks'][ $hook ][ $prio ][] = $cb; return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return add_filter( $hook, $cb, $prio, $args ); }
function remove_action( $hook, $cb, $prio = 10 ) {
	$GLOBALS['uxs_removed'][] = $hook . '|' . ( is_string( $cb ) ? $cb : 'callable' );
	foreach ( $GLOBALS['uxs_hooks'][ $hook ][ $prio ] ?? array() as $i => $have ) {
		if ( $have === $cb ) { unset( $GLOBALS['uxs_hooks'][ $hook ][ $prio ][ $i ] ); }
	}
	return true;
}
function remove_filter( $hook, $cb, $prio = 10 ) { return remove_action( $hook, $cb, $prio ); }
function has_filter( $hook ) { foreach ( $GLOBALS['uxs_hooks'][ $hook ] ?? array() as $cbs ) { if ( $cbs ) { return true; } } return false; }
function apply_filters( $hook, $value, ...$args ) {
	$by = $GLOBALS['uxs_hooks'][ $hook ] ?? array();
	ksort( $by );
	foreach ( $by as $cbs ) {
		foreach ( $cbs as $cb ) { $value = call_user_func_array( $cb, array_merge( array( $value ), $args ) ); }
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	$by = $GLOBALS['uxs_hooks'][ $hook ] ?? array();
	ksort( $by );
	foreach ( $by as $cbs ) {
		foreach ( $cbs as $cb ) { call_user_func_array( $cb, $args ); }
	}
}
function __return_true() { return true; }
function __return_false() { return false; }

/* ── options, escaping, sanitizing ── */
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function maybe_serialize( $v ) { return is_scalar( $v ) ? (string) $v : serialize( $v ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_salt( $scheme = '' ) { return 'test-salt-' . $scheme; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/%[a-f0-9]{2}/i', '', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( (string) $s, FILTER_VALIDATE_EMAIL ) ? (string) $s : ''; }
function sanitize_file_name( $s ) { return trim( preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $s ), '-' ); }
function is_email( $s ) { return false !== filter_var( (string) $s, FILTER_VALIDATE_EMAIL ) ? $s : false; }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $url, $protocols = null ) { $url = trim( (string) $url ); return preg_match( '#^https?://#i', $url ) ? $url : ''; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://www.example.test/site' . $path; }
function network_home_url( $path = '' ) { return home_url( $path ); }
function get_bloginfo( $what = '' ) { return 'Test site'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_unslash( $v ) { return $v; }
function wp_slash( $v ) { return $v; }
function wpautop( $s ) { return '<p>' . $s . '</p>'; }
function current_time( $t ) { return '2026-10-23 12:00:00'; }
function wp_nonce_url( $u, $a = '' ) { return $u . '&_wpnonce=x'; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $p = '' ) { return 'https://www.example.test/site/wp-admin/' . $p; }
function get_plugins() { return array( 'akismet/akismet.php' => array( 'Name' => 'Akismet' ) ); }
function wp_get_themes() { return array(); }
function wp_doing_cron() { return $GLOBALS['uxs_cron_now']; }
function wp_get_current_user() { return $GLOBALS['uxs_user'] ?? new FakeUser(); }
function get_post( $id ) { return $GLOBALS['uxs_posts'][ (int) $id ] ?? null; }
function wp_get_schedules() { return array( 'hourly' => array( 'interval' => 3600, 'display' => 'Hourly' ), 'daily' => array( 'interval' => 86400, 'display' => 'Daily' ) ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_post( $url, $args = array() ) { return wp_remote_request( $url, $args + array( 'method' => 'POST' ) ); }

/* ── HTTP double ── */
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['uxs_http'][] = array( 'fn' => 'wp_remote_request', 'url' => $url, 'args' => $args );
	$host = (string) parse_url( $url, PHP_URL_HOST );
	$res  = $GLOBALS['uxs_http_map'][ $host ] ?? array( 202, '' );
	return $res instanceof WP_Error ? $res : array( 'response' => array( 'code' => $res[0] ), 'body' => $res[1] );
}
function wp_safe_remote_request( $url, $args = array() ) {
	$GLOBALS['uxs_http'][] = array( 'fn' => 'wp_safe_remote_request', 'url' => $url, 'args' => $args );
	$host = (string) parse_url( $url, PHP_URL_HOST );
	$res  = $GLOBALS['uxs_http_map'][ $host ] ?? array( 200, '' );
	return $res instanceof WP_Error ? $res : array( 'response' => array( 'code' => $res[0] ), 'body' => $res[1] );
}

/* ── wp_mail double (the SMTP attempt runs it with the SMTP transport forced) ── */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	global $wp_filter;
	$GLOBALS['uxs_mail'][] = array( 'to' => $to, 'subject' => $subject, 'wp_mail_filter_present' => isset( $wp_filter['wp_mail'] ) );
	$mailer = new FakePhpMailer();
	do_action( 'phpmailer_init', $mailer );
	$GLOBALS['uxs_mail'][ count( $GLOBALS['uxs_mail'] ) - 1 ]['smtp_host'] = (string) $mailer->Host;
	if ( $GLOBALS['uxs_mail_ok'] ) {
		do_action( 'wp_mail_succeeded', array( 'to' => $to, 'subject' => $subject ) );
		return true;
	}
	do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed', array( 'to' => $to, 'subject' => $subject ) ) );
	return false;
}

/* ── cron double ── */
function _get_cron_array() { return $GLOBALS['uxs_cron']; }
function wp_next_scheduled( $hook, $args = array() ) { foreach ( $GLOBALS['uxs_cron'] as $ts => $events ) { if ( isset( $events[ $hook ][ md5( serialize( $args ) ) ] ) ) { return $ts; } } return false; }
function wp_get_scheduled_event( $hook, $args = array() ) { foreach ( $GLOBALS['uxs_cron'] as $ts => $events ) { if ( isset( $events[ $hook ][ md5( serialize( $args ) ) ] ) ) { $e = $events[ $hook ][ md5( serialize( $args ) ) ]; return (object) array( 'hook' => $hook, 'timestamp' => $ts, 'schedule' => $e['schedule'] ?? false, 'args' => $args ); } } return false; }
function wp_schedule_event( $ts, $recurrence, $hook, $args = array() ) { if ( ! isset( wp_get_schedules()[ $recurrence ] ) ) { return false; } $GLOBALS['uxs_cron'][ $ts ][ $hook ][ md5( serialize( $args ) ) ] = array( 'args' => $args, 'schedule' => $recurrence ); return true; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['uxs_cron'][ $ts ][ $hook ][ md5( serialize( $args ) ) ] = array( 'args' => $args, 'schedule' => false ); return true; }
function wp_unschedule_event( $ts, $hook, $args = array() ) { unset( $GLOBALS['uxs_cron'][ $ts ][ $hook ][ md5( serialize( $args ) ) ] ); return true; }
function wp_clear_scheduled_hook( $hook ) { foreach ( $GLOBALS['uxs_cron'] as $ts => $events ) { unset( $GLOBALS['uxs_cron'][ $ts ][ $hook ] ); } return 1; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Security;
use UxStudio\Modules\AutoImageUpload\Processor;
use UxStudio\Modules\CronControl\UrlEvents;
use UxStudio\Modules\DisableAutoUpdates\Module as UpdatesModule;
use UxStudio\Modules\SmtpEmail\MailMessage;
use UxStudio\Modules\SmtpEmail\Module as SmtpModule;
use UxStudio\Modules\SmtpEmail\Providers;
use UxStudio\Modules\SmtpEmail\SigV4;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$reset = static function (): void {
	$GLOBALS['uxs_options']  = array();
	$GLOBALS['uxs_hooks']    = array();
	$GLOBALS['uxs_removed']  = array();
	$GLOBALS['uxs_http']     = array();
	$GLOBALS['uxs_http_map'] = array();
	$GLOBALS['uxs_cron']     = array();
	$GLOBALS['uxs_cron_now'] = false;
	$GLOBALS['uxs_user']     = null;
	$GLOBALS['uxs_mail']     = array();
	$GLOBALS['uxs_mail_ok']  = true;
	$GLOBALS['wpdb']->rows   = array();
	unset( $GLOBALS['wp_filter'] );
};

/* ═══ 1 AWS Signature Version 4 ═══ */

// Vectors of the AWS documentation and of its signature test suite (checked against an independent implementation too).
$sk  = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
$get = SigV4::sign( 'GET', '/', '', array( 'Host' => 'example.amazonaws.com', 'X-Amz-Date' => '20150830T123600Z' ), '', 'AKIDEXAMPLE', $sk, 'us-east-1', 'service', '20150830T123600Z' );
$assert( 'sigv4: get-vanilla (AWS test suite)', '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31' === $get['signature'], $get['signature'] );
$assert( 'sigv4: the Authorization header has the documented shape', 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31' === $get['authorization'] );
$post = SigV4::sign( 'POST', '/', '', array( 'Host' => 'example.amazonaws.com', 'X-Amz-Date' => '20150830T123600Z' ), '', 'AKIDEXAMPLE', $sk, 'us-east-1', 'service', '20150830T123600Z' );
$assert( 'sigv4: post-vanilla (AWS test suite)', '5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b' === $post['signature'], $post['signature'] );
$iam = SigV4::sign( 'GET', '/', 'Action=ListUsers&Version=2010-05-08', array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8', 'Host' => 'iam.amazonaws.com', 'X-Amz-Date' => '20150830T123600Z' ), '', 'AKIDEXAMPLE', $sk, 'us-east-1', 'iam', '20150830T123600Z' );
$assert( 'sigv4: the IAM ListUsers example of the documentation', '5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7' === $iam['signature'], $iam['signature'] );
$assert( 'sigv4: the derived signing key of the documentation', 'c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9' === bin2hex( SigV4::signing_key( $sk, '20150830', 'us-east-1', 'iam' ) ) );
$assert( 'sigv4: header names are lower-cased and sorted, values folded', 'content-type;host;x-amz-date' === $iam['signed_headers'] && false !== strpos( $iam['canonical'], "content-type:application/x-www-form-urlencoded; charset=utf-8\nhost:iam.amazonaws.com\n" ) );

/* ═══ 2 The message ═══ */

$tmp1 = tempnam( sys_get_temp_dir(), 'uxs' );
file_put_contents( $tmp1, "col1,col2\n1,2\n" );
rename( $tmp1, $tmp1 . '.csv' );
$tmp1 .= '.csv';
$m = MailMessage::from_atts( array(
	'to'          => array( 'Jan Novak <jan@example.cz>', 'bad address', 'eva@example.cz' ),
	'subject'     => 'Předmět',
	'message'     => '<p>Ahoj</p>',
	'headers'     => array( 'Content-Type: text/html; charset=UTF-8', 'From: "Firma, s.r.o." <info@firma.cz>', 'Cc: a@x.cz, "B, Bé" <b@x.cz>', 'Bcc: c@x.cz', 'Reply-To: reply@x.cz' ),
	'attachments' => array( $tmp1, '/no/such/file.pdf' ),
) );
$assert( 'message: recipients with names, invalid ones dropped', null !== $m && 2 === count( $m->to ) && 'Jan Novak' === $m->to[0]['name'] && 'jan@example.cz' === $m->to[0]['email'] );
$assert( 'message: From with a comma in the name, Cc with a quoted comma, Bcc, Reply-To', 'info@firma.cz' === $m->from_email && 'Firma, s.r.o.' === $m->from_name && 2 === count( $m->cc ) && 'B, Bé' === $m->cc[1]['name'] && 'c@x.cz' === $m->bcc[0]['email'] && 'reply@x.cz' === $m->reply_to[0]['email'] );
$assert( 'message: HTML from the header, the readable attachment kept and the missing one dropped', $m->is_html() && 1 === count( $m->attachments ) && 'text/csv' === $m->attachments[0]['type'] && "col1,col2\n1,2\n" === $m->attachments[0]['content'] && basename( $tmp1 ) === $m->attachments[0]['name'] );
$p = MailMessage::from_atts( array( 'to' => 'x@y.cz', 'subject' => 's', 'message' => 'text', 'headers' => '' ) );
$assert( 'message: defaults of wp_mail (plain text, wordpress@host without www, name WordPress)', ! $p->is_html() && 'wordpress@example.test' === $p->from_email && 'WordPress' === $p->from_name );
add_filter( 'wp_mail_from', static fn( $v ) => 'forced@firma.cz' );
add_filter( 'wp_mail_from_name', static fn( $v ) => 'Forced' );
add_filter( 'wp_mail_content_type', static fn( $v ) => 'text/html' );
$p = MailMessage::from_atts( array( 'to' => 'x@y.cz', 'subject' => 's', 'message' => 'text', 'headers' => "From: a@b.cz\r\nContent-Type: text/plain" ) );
$assert( 'message: the From and content-type filters run after the headers, as in wp_mail', 'forced@firma.cz' === $p->from_email && 'Forced' === $p->from_name && $p->is_html() );
$GLOBALS['uxs_hooks'] = array();
$assert( 'message: no valid recipient = no message', null === MailMessage::from_atts( array( 'to' => 'nope', 'subject' => 's', 'message' => 'm' ) ) );
$raw = $m->raw_mime();
$assert( 'message: raw MIME has the headers, no Bcc header, a multipart body with the attachment and an encoded subject', false !== strpos( $raw, "\r\nCc: " ) && false === stripos( $raw, "\r\nBcc:" ) && false !== strpos( $raw, 'multipart/mixed' ) && false !== strpos( $raw, 'Content-Disposition: attachment' ) && false !== strpos( $raw, '=?UTF-8?B?' . base64_encode( 'Předmět' ) . '?=' ) && false !== strpos( $raw, 'Reply-To: reply@x.cz' ), substr( $raw, 0, 400 ) );

/* ═══ 3 Provider requests ═══ */

$att = static function () use ( $m ): MailMessage { return $m; };
$sg  = Providers::request( 'sendgrid', $m, array( 'api_key' => 'SG.key', 'region' => 'global' ) );
$sgb = json_decode( $sg['args']['body'], true );
$assert( 'sendgrid: endpoint, bearer key, JSON content type', 'https://api.sendgrid.com/v3/mail/send' === $sg['url'] && 'Bearer SG.key' === $sg['args']['headers']['Authorization'] && 'application/json' === $sg['args']['headers']['Content-Type'] && 'POST' === $sg['args']['method'] );
$assert( 'sendgrid: to / cc / bcc, from with name, reply-to, subject, HTML content', 2 === count( $sgb['personalizations'][0]['to'] ) && 2 === count( $sgb['personalizations'][0]['cc'] ) && 'c@x.cz' === $sgb['personalizations'][0]['bcc'][0]['email'] && 'info@firma.cz' === $sgb['from']['email'] && 'Firma, s.r.o.' === $sgb['from']['name'] && 'reply@x.cz' === $sgb['reply_to']['email'] && 'Předmět' === $sgb['subject'] && 'text/html' === $sgb['content'][0]['type'], $sg['args']['body'] );
$assert( 'sendgrid: the attachment is base64 with name, type and disposition', 1 === count( $sgb['attachments'] ) && base64_encode( "col1,col2\n1,2\n" ) === $sgb['attachments'][0]['content'] && 'text/csv' === $sgb['attachments'][0]['type'] && 'attachment' === $sgb['attachments'][0]['disposition'] );
$dup = clone $m; $dup->cc = array( array( 'email' => 'jan@example.cz', 'name' => '' ), array( 'email' => 'new@x.cz', 'name' => '' ) );
$assert( 'sendgrid: an address already in "to" is not repeated in cc (SendGrid refuses duplicates)', 1 === count( json_decode( Providers::request( 'sendgrid', $dup, array( 'api_key' => 'k' ) )['args']['body'], true )['personalizations'][0]['cc'] ) );
$assert( 'sendgrid: the EU region has its own host', 'https://api.eu.sendgrid.com/v3/mail/send' === Providers::request( 'sendgrid', $m, array( 'api_key' => 'k', 'region' => 'eu' ) )['url'] );
$plain = MailMessage::from_atts( array( 'to' => 'x@y.cz', 'subject' => 's', 'message' => 'only text' ) );
$assert( 'sendgrid: plain text goes as text/plain, and it has no attachments key', 'text/plain' === json_decode( Providers::request( 'sendgrid', $plain, array( 'api_key' => 'k' ) )['args']['body'], true )['content'][0]['type'] && ! isset( json_decode( Providers::request( 'sendgrid', $plain, array( 'api_key' => 'k' ) )['args']['body'], true )['attachments'] ) );

$mg   = Providers::request( 'mailgun', $m, array( 'api_key' => 'key-mg', 'domain' => 'MG.Firma.cz', 'region' => 'us' ) );
$boundary = preg_match( '/boundary=(\S+)/', $mg['args']['headers']['Content-Type'], $bm ) ? $bm[1] : '';
$assert( 'mailgun: US endpoint with the (lower-cased) domain, basic auth api:key, multipart', 'https://api.mailgun.net/v3/mg.firma.cz/messages' === $mg['url'] && 'Basic ' . base64_encode( 'api:key-mg' ) === $mg['args']['headers']['Authorization'] && '' !== $boundary );
$assert( 'mailgun: the fields (from with a quoted name, to, cc, bcc, Reply-To, subject, html) and the attachment part', false !== strpos( $mg['args']['body'], 'name="from"' . "\r\n\r\n" . '"Firma, s.r.o." <info@firma.cz>' ) && false !== strpos( $mg['args']['body'], 'name="to"' . "\r\n\r\n" . '"Jan Novak" <jan@example.cz>, eva@example.cz' ) && false !== strpos( $mg['args']['body'], 'name="bcc"' ) && false !== strpos( $mg['args']['body'], 'name="h:Reply-To"' ) && false !== strpos( $mg['args']['body'], 'name="html"' . "\r\n\r\n" . '<p>Ahoj</p>' ) && false !== strpos( $mg['args']['body'], 'name="attachment"; filename="' . basename( $tmp1 ) . '"' ) && '--' . $boundary . '--' . "\r\n" === substr( $mg['args']['body'], -strlen( $boundary ) - 6 ), substr( $mg['args']['body'], 0, 300 ) );
$assert( 'mailgun: the EU region has its own host; plain text uses the "text" field', 0 === strpos( Providers::request( 'mailgun', $m, array( 'api_key' => 'k', 'domain' => 'mg.x.cz', 'region' => 'eu' ) )['url'], 'https://api.eu.mailgun.net/v3/mg.x.cz/' ) && false !== strpos( Providers::request( 'mailgun', $plain, array( 'api_key' => 'k', 'domain' => 'mg.x.cz' ) )['args']['body'], 'name="text"' ) );
$bad_domains = array( '', '../evil', 'a b.cz', 'x.cz/../../y', 'x.cz?y=1', "x.cz\r\nHost: evil", '-bad.cz', 'bad-.cz', 'user@x.cz' );
$bad_ok = true;
foreach ( $bad_domains as $d ) { $bad_ok = $bad_ok && null === Providers::request( 'mailgun', $m, array( 'api_key' => 'k', 'domain' => $d ) ); }
$assert( 'mailgun: a malformed domain never reaches the address (no request is built)', $bad_ok );

$pm  = Providers::request( 'postmark', $m, array( 'token' => 'pm-token', 'stream' => 'broadcast' ) );
$pmb = json_decode( $pm['args']['body'], true );
$assert( 'postmark: endpoint, server token header, JSON', 'https://api.postmarkapp.com/email' === $pm['url'] && 'pm-token' === $pm['args']['headers']['X-Postmark-Server-Token'] && 'application/json' === $pm['args']['headers']['Accept'] );
$assert( 'postmark: From / To / Cc / Bcc / ReplyTo, HtmlBody, stream, attachment', '"Firma, s.r.o." <info@firma.cz>' === $pmb['From'] && '"Jan Novak" <jan@example.cz>, eva@example.cz' === $pmb['To'] && 'c@x.cz' === $pmb['Bcc'] && 'reply@x.cz' === $pmb['ReplyTo'] && '<p>Ahoj</p>' === $pmb['HtmlBody'] && ! isset( $pmb['TextBody'] ) && 'broadcast' === $pmb['MessageStream'] && 'text/csv' === $pmb['Attachments'][0]['ContentType'] && base64_encode( "col1,col2\n1,2\n" ) === $pmb['Attachments'][0]['Content'], $pm['args']['body'] );
$pmp = json_decode( Providers::request( 'postmark', $plain, array( 'token' => 't' ) )['args']['body'], true );
$assert( 'postmark: plain text = TextBody, no stream when blank', 'only text' === $pmp['TextBody'] && ! isset( $pmp['HtmlBody'] ) && ! isset( $pmp['MessageStream'] ) );

$when = gmmktime( 12, 36, 0, 8, 30, 2015 );
$ses  = Providers::request( 'ses', $plain, array( 'access_key' => 'AKIDEXAMPLE', 'secret_key' => $sk, 'region' => 'eu-central-1', 'time' => $when ) );
$sesb = json_decode( $ses['args']['body'], true );
$assert( 'ses: v2 endpoint of the region, Simple content for a message without attachments', 'https://email.eu-central-1.amazonaws.com/v2/email/outbound-emails' === $ses['url'] && 'only text' === $sesb['Content']['Simple']['Body']['Text']['Data'] && 's' === $sesb['Content']['Simple']['Subject']['Data'] && array( 'x@y.cz' ) === $sesb['Destination']['ToAddresses'] && ! isset( $sesb['Content']['Raw'] ), $ses['args']['body'] );
$check = SigV4::sign( 'POST', '/v2/email/outbound-emails', '', array( 'content-type' => 'application/json', 'host' => 'email.eu-central-1.amazonaws.com', 'x-amz-date' => '20150830T123600Z' ), $ses['args']['body'], 'AKIDEXAMPLE', $sk, 'eu-central-1', 'ses', '20150830T123600Z' );
$assert( 'ses: the Authorization header is the SigV4 signature of exactly the request that is sent (key id, scope, signed headers, signature)', $check['authorization'] === $ses['args']['headers']['Authorization'] && '20150830T123600Z' === $ses['args']['headers']['X-Amz-Date'] && 'application/json' === $ses['args']['headers']['Content-Type'] && 0 === strpos( $ses['args']['headers']['Authorization'], 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/eu-central-1/ses/aws4_request, SignedHeaders=content-type;host;x-amz-date, Signature=' ) );
$assert( 'ses: the secret key is never in the request', false === strpos( json_encode( $ses ), $sk ) );
$ses_raw = Providers::request( 'ses', $m, array( 'access_key' => 'a', 'secret_key' => 'b', 'region' => 'us-east-1' ) );
$sesr    = json_decode( $ses_raw['args']['body'], true );
$mime    = base64_decode( $sesr['Content']['Raw']['Data'] );
$assert( 'ses: with an attachment the message goes as raw MIME; to / cc / bcc are separate destination fields, Bcc is not in the MIME', false !== strpos( $mime, 'multipart/mixed' ) && false !== strpos( $mime, 'Content-Disposition: attachment' ) && array( 'c@x.cz' ) === $sesr['Destination']['BccAddresses'] && 2 === count( $sesr['Destination']['CcAddresses'] ) && false === stripos( $mime, "\r\nBcc:" ) && array( 'reply@x.cz' ) === $sesr['ReplyToAddresses'] );
$assert( 'ses: an unknown region builds no request (no host from user input)', null === Providers::request( 'ses', $plain, array( 'access_key' => 'a', 'secret_key' => 'b', 'region' => 'evil.example.com/x' ) ) && null === Providers::request( 'nope', $plain, array() ) );

$assert( 'verdict: SendGrid 202 ok, 401 gives the message of the API', Providers::verdict( 'sendgrid', 202, '' )['ok'] && 'HTTP 401: The provided authorization grant is invalid' === Providers::verdict( 'sendgrid', 401, '{"errors":[{"message":"The provided authorization grant is invalid"}]}' )['error'] );
$assert( 'verdict: Mailgun 200 ok, 401 and a body without JSON', Providers::verdict( 'mailgun', 200, '{"id":"x"}' )['ok'] && 'HTTP 401: Forbidden' === Providers::verdict( 'mailgun', 401, '{"message":"Forbidden"}' )['error'] && 'HTTP 502' === Providers::verdict( 'mailgun', 502, '<html>' )['error'] );
$assert( 'verdict: Postmark needs HTTP 200 AND ErrorCode 0 (422 / ErrorCode 406 are rejections)', Providers::verdict( 'postmark', 200, '{"ErrorCode":0}' )['ok'] && ! Providers::verdict( 'postmark', 200, '{"ErrorCode":406,"Message":"inactive recipient"}' )['ok'] && 'HTTP 422: Invalid email request' === Providers::verdict( 'postmark', 422, '{"ErrorCode":300,"Message":"Invalid email request"}' )['error'] );
$assert( 'verdict: SES 200 ok, 403 gives the message', Providers::verdict( 'ses', 200, '{"MessageId":"x"}' )['ok'] && 'HTTP 403: The security token included in the request is invalid.' === Providers::verdict( 'ses', 403, '{"message":"The security token included in the request is invalid."}' )['error'] );

$GLOBALS['uxs_http_map'] = array( 'api.sendgrid.com' => array( 202, '' ) );
$r = Providers::send( 'sendgrid', $plain, array( 'api_key' => 'k' ) );
$assert( 'send: success goes through the WordPress HTTP API', $r['ok'] && 202 === $r['http'] && 'https://api.sendgrid.com/v3/mail/send' === $GLOBALS['uxs_http'][0]['url'] );
$GLOBALS['uxs_http_map'] = array( 'api.sendgrid.com' => new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
$r = Providers::send( 'sendgrid', $plain, array( 'api_key' => 'k' ) );
$assert( 'send: a transport error is the error text', ! $r['ok'] && 'cURL error 28: timeout' === $r['error'] && 0 === $r['http'] );
$assert( 'send: incomplete settings (bad domain) fail without a request', ! Providers::send( 'mailgun', $plain, array( 'api_key' => 'k', 'domain' => '' ) )['ok'] );

/* ═══ 4 Module: transports, fallback, log ═══ */

$mod = static function ( array $settings = array(), array $secrets = array() ) use ( $reset ): SmtpModule {
	$reset();
	update_option( 'uxstudio_smtp_email', $settings );
	foreach ( $secrets as $option => $value ) {
		Security::store_secret( 'uxstudio_secret_smtp_' . $option, $value );
	}
	$module = new SmtpModule( 'smtp-email', array() );
	add_action( 'wp_mail_succeeded', array( $module, 'log_success' ) );
	add_action( 'wp_mail_failed', array( $module, 'log_failure' ) );
	return $module;
};
$atts  = array( 'to' => 'rcpt@example.cz', 'subject' => 'Test', 'message' => 'Body', 'headers' => '', 'attachments' => array() );
$rows  = static fn(): array => $GLOBALS['wpdb']->rows;
$calls = static fn(): array => array_map( static fn( $c ) => (string) parse_url( $c['url'], PHP_URL_HOST ), $GLOBALS['uxs_http'] );
$both  = array( 'mailer' => 'sendgrid', 'fallback_mailer' => 'mailgun', 'mailgun_domain' => 'mg.x.cz', 'from_email' => 'info@firma.cz' );
$keys  = array( 'sendgrid_api_key' => 'SG.1', 'mailgun_api_key' => 'key-1', 'postmark_server_token' => 'pm-1', 'ses_access_key' => 'AKIA1', 'ses_secret_key' => 'sec1' );

$module = $mod( $both, $keys );
$GLOBALS['uxs_http_map'] = array( 'api.sendgrid.com' => array( 500, '{"errors":[{"message":"boom"}]}' ), 'api.mailgun.net' => array( 200, '{"id":"1"}' ) );
$res = $module->handle_pre_wp_mail( null, $atts );
$assert( 'fallback: the primary fails, the fallback sends, wp_mail() reports success', true === $res && array( 'api.sendgrid.com', 'api.mailgun.net' ) === $calls() );
$assert( 'fallback: BOTH attempts are in the log - the failed primary with its error, then the fallback', 2 === count( $rows() ) && 'sendgrid' === $rows()[0]['mailer'] && 'primary' === $rows()[0]['attempt'] && 'error' === $rows()[0]['status'] && 'HTTP 500: boom' === $rows()[0]['error'] && 'mailgun' === $rows()[1]['mailer'] && 'fallback' === $rows()[1]['attempt'] && 'success' === $rows()[1]['status'], json_encode( $rows() ) );
$module = $mod( $both, $keys );
$GLOBALS['uxs_http_map'] = array( 'api.sendgrid.com' => array( 202, '' ) );
$res = $module->handle_pre_wp_mail( null, $atts );
$assert( 'fallback: a primary that works leaves the fallback alone (one request, one row)', true === $res && array( 'api.sendgrid.com' ) === $calls() && 1 === count( $rows() ) && 'success' === $rows()[0]['status'] );
$module = $mod( $both, $keys );
$GLOBALS['uxs_http_map'] = array( 'api.sendgrid.com' => array( 500, '' ), 'api.mailgun.net' => new WP_Error( 'http_request_failed', 'timeout' ) );
$res = $module->handle_pre_wp_mail( null, $atts );
$assert( 'fallback: both fail = false, both logged as errors', false === $res && 2 === count( $rows() ) && 'error' === $rows()[0]['status'] && 'error' === $rows()[1]['status'] && 'timeout' === $rows()[1]['error'] );
$module = $mod( array( 'mailer' => 'sendgrid', 'fallback_mailer' => 'mailgun', 'mailgun_domain' => 'mg.x.cz' ), array( 'mailgun_api_key' => 'key-1' ) );
$GLOBALS['uxs_http_map'] = array( 'api.mailgun.net' => array( 200, '' ) );
$res = $module->handle_pre_wp_mail( null, $atts );
$assert( 'fallback: a primary that is not set up is skipped (logged as such) and the fallback sends', true === $res && array( 'api.mailgun.net' ) === $calls() && 'This connection is not set up.' === $rows()[0]['error'] && 'primary' === $rows()[0]['attempt'] && 'fallback' === $rows()[1]['attempt'] );
$module = $mod( array( 'mailer' => 'postmark' ), array() );
$assert( 'not set up and no fallback: the WordPress default transport (null), as Brevo always behaved; nothing is logged here', null === $module->handle_pre_wp_mail( null, $atts ) && array() === $rows() && array() === $GLOBALS['uxs_http'] );
$module = $mod( array( 'mailer' => 'gmail' ), array() );
$assert( 'Gmail without a connected account stays a failure (false), now logged with the transport', false === $module->handle_pre_wp_mail( null, $atts ) && 'gmail' === $rows()[0]['mailer'] );
$module = $mod( array( 'mailer' => 'sendgrid', 'fallback_mailer' => 'sendgrid' ), array( 'sendgrid_api_key' => 'SG.1' ) );
$assert( 'a fallback that is the same service as the primary is ignored', '' === $module->fallback_mailer() && 'sendgrid' === $module->primary_mailer() );
$module = $mod( array( 'mailer' => 'bogus', 'fallback_mailer' => 'also-bogus' ) );
$assert( 'unknown values fall back to SMTP / no fallback', 'smtp' === $module->primary_mailer() && '' === $module->fallback_mailer() );
$module = $mod( $both, $keys );
$assert( 'a message somebody else already took (non-null) and a non-array are passed through untouched', true === $module->handle_pre_wp_mail( true, $atts ) && false === $module->handle_pre_wp_mail( false, $atts ) && null === $module->handle_pre_wp_mail( null, 'not an array' ) && array() === $GLOBALS['uxs_http'] );

// SMTP as the primary: wp_mail() runs again with the SMTP transport forced; the fallback is an API.
$smtp = array( 'mailer' => 'smtp', 'fallback_mailer' => 'ses', 'host' => 'smtp.firma.cz', 'ses_region' => 'eu-west-1', 'from_email' => 'info@firma.cz' );
$module = $mod( $smtp, $keys );
$GLOBALS['wp_filter'] = array( 'wp_mail' => 'SENTINEL' );
$GLOBALS['uxs_mail_ok'] = false;
$GLOBALS['uxs_http_map'] = array( 'email.eu-west-1.amazonaws.com' => array( 200, '{"MessageId":"m"}' ) );
add_action( 'phpmailer_init', array( $module, 'configure_phpmailer' ) );
$res = $module->handle_pre_wp_mail( null, $atts );
$assert( 'smtp primary + SES fallback: the SMTP attempt fails (error text from wp_mail_failed), SES sends, the result is true', true === $res && 1 === count( $GLOBALS['uxs_mail'] ) && array( 'email.eu-west-1.amazonaws.com' ) === $calls() );
$assert( 'smtp primary: configured with the module SMTP host while it runs', 'smtp.firma.cz' === $GLOBALS['uxs_mail'][0]['smtp_host'] );
$assert( 'smtp primary: the "wp_mail" filter is taken off for the inner call and restored afterwards (no double footers)', false === $GLOBALS['uxs_mail'][0]['wp_mail_filter_present'] && 'SENTINEL' === ( $GLOBALS['wp_filter']['wp_mail'] ?? null ) );
$assert( 'smtp primary: both attempts logged, the SMTP one with the real error and the transport', 2 === count( $rows() ) && 'smtp' === $rows()[0]['mailer'] && 'primary' === $rows()[0]['attempt'] && 'error' === $rows()[0]['status'] && 'SMTP connect() failed' === $rows()[0]['error'] && 'ses' === $rows()[1]['mailer'] && 'fallback' === $rows()[1]['attempt'] && 'success' === $rows()[1]['status'], json_encode( $rows() ) );
$assert( 'the forced inner call is not taken over again (no recursion)', 1 === count( $GLOBALS['uxs_mail'] ) );

// API primary + SMTP fallback: SMTP is configured only while the fallback runs.
$module = $mod( array( 'mailer' => 'sendgrid', 'fallback_mailer' => 'smtp', 'host' => 'fb.smtp.cz' ), array( 'sendgrid_api_key' => 'SG.1' ) );
add_action( 'phpmailer_init', array( $module, 'configure_phpmailer' ) );
$GLOBALS['uxs_http_map'] = array( 'api.sendgrid.com' => array( 400, '{"errors":[{"message":"no"}]}' ) );
$res = $module->handle_pre_wp_mail( null, $atts );
$assert( 'sendgrid primary + SMTP fallback: SendGrid refuses, SMTP sends through wp_mail() with the SMTP host', true === $res && 'fb.smtp.cz' === $GLOBALS['uxs_mail'][0]['smtp_host'] && 'smtp' === $rows()[1]['mailer'] && 'fallback' === $rows()[1]['attempt'] && 'success' === $rows()[1]['status'], json_encode( $rows() ) );
$mailer_obj = new FakePhpMailer();
$module->configure_phpmailer( $mailer_obj );
$assert( 'outside a fallback attempt phpmailer_init does NOT touch the mailer when SMTP is not the primary', null === $mailer_obj->Host );

// Per-mailer test send and the secrets.
$module = $mod( $both, $keys );
$GLOBALS['uxs_http_map'] = array( 'api.mailgun.net' => array( 200, '' ), 'api.sendgrid.com' => array( 500, '' ) );
$t = $module->send_test( 'me@example.cz', 'mailgun' );
$assert( 'per-mailer test: goes through exactly that transport, no fallback, logged as a test', $t['success'] && 'mailgun' === $t['mailer'] && array( 'api.mailgun.net' ) === $calls() && 'test' === $rows()[0]['attempt'] && 'mailgun' === $rows()[0]['mailer'] );
$module = $mod( $both, $keys );
$GLOBALS['uxs_http_map'] = array( 'api.sendgrid.com' => array( 500, '' ) );
$t = $module->send_test( 'me@example.cz', 'sendgrid' );
$assert( 'per-mailer test: a refusing primary is reported as it is, the fallback is NOT tried', ! $t['success'] && 'HTTP 500' === $t['error'] && array( 'api.sendgrid.com' ) === $calls() );
$assert( 'per-mailer test: an unknown mailer and one that is not set up are refused without a request', ! $module->send_test( 'me@example.cz', 'nope' )['success'] && ! $mod( array(), array() )->send_test( 'me@example.cz', 'postmark' )['success'] );
$module = $mod( array_merge( $both, array( 'sendgrid_api_key' => 'LEAK' ) ), $keys );
$vals = $module->settings_values();
$assert( 'settings_values: no secret ever comes back, only has_* flags for each', '' === $vals['sendgrid_api_key'] && '' === $vals['mailgun_api_key'] && '' === $vals['postmark_server_token'] && '' === $vals['ses_access_key'] && '' === $vals['ses_secret_key'] && true === $vals['has_sendgrid_api_key'] && true === $vals['has_ses_secret_key'] && false === strpos( json_encode( $vals ), 'SG.1' ) && false === strpos( json_encode( $vals ), 'sec1' ) );
$assert( 'settings_values: which transports are set up', true === $vals['mailers_ready']['sendgrid'] && true === $vals['mailers_ready']['mailgun'] && true === $vals['mailers_ready']['postmark'] && true === $vals['mailers_ready']['ses'] && false === $vals['mailers_ready']['smtp'] && false === $vals['mailers_ready']['gmail'] );
$module->save_settings( array( 'mailer' => 'postmark', 'fallback_mailer' => 'postmark', 'postmark_server_token' => '  new-token  ', 'ses_secret_key' => '', 'mailgun_domain' => 'mg.new.cz', 'sendgrid_api_key' => '' ) );
$stored = get_option( 'uxstudio_smtp_email' );
$assert( 'save: secrets go to Security (trimmed), blank keeps the old one, none lands in the settings option, a fallback equal to the primary is cleared', 'new-token' === Security::get_secret( 'uxstudio_secret_smtp_postmark_server_token' ) && 'sec1' === Security::get_secret( 'uxstudio_secret_smtp_ses_secret_key' ) && 'SG.1' === Security::get_secret( 'uxstudio_secret_smtp_sendgrid_api_key' ) && ! isset( $stored['postmark_server_token'] ) && ! isset( $stored['ses_secret_key'] ) && 'none' === $stored['fallback_mailer'] && 'postmark' === $stored['mailer'] && false === strpos( serialize( $stored ), 'new-token' ), json_encode( $stored ) );
$assert( 'secrets are encrypted at rest', 0 === strpos( (string) get_option( 'uxstudio_secret_smtp_postmark_server_token' ), 'uxs1:' ) && false === strpos( (string) get_option( 'uxstudio_secret_smtp_ses_secret_key' ), 'sec1' ) );
$schema = $module->settings_schema();
$by     = array_column( $schema, null, 'key' );
$assert( 'schema: the new fields exist, mailers listed, fallback offers "none" + every mailer, sections for the tabs, log settings carry none', isset( $by['sendgrid_api_key'], $by['mailgun_domain'], $by['postmark_server_token'], $by['ses_region'], $by['fallback_mailer'] ) && array( 'smtp', 'brevo', 'gmail', 'sendgrid', 'mailgun', 'postmark', 'ses' ) === array_keys( $by['mailer']['options'] ) && 'none' === array_key_first( $by['fallback_mailer']['options'] ) && 'SendGrid' === $by['sendgrid_api_key']['section'] && 'Amazon SES' === $by['ses_region']['section'] && ! isset( $by['enable_email_logs']['section'] ) && ! isset( $by['mailer']['section'] ) && 23 === count( $by['ses_region']['options'] ) );

/* ═══ 5 Disable Auto Updates: notices, checks, security exception ═══ */

$upd = static function ( array $settings ) use ( $reset ): UpdatesModule {
	$reset();
	update_option( 'uxstudio_disable_auto_updates', $settings );
	$module = new UpdatesModule( 'disable-auto-updates', array() );
	$module->boot();
	return $module;
};
$offers = (object) array( 'last_checked' => 1, 'response' => array( 'a/a.php' => (object) array( 'new_version' => '2' ) ), 'translations' => array( 1 ), 'no_update' => array( 'b/b.php' => 1 ) );
$core_offers = (object) array( 'updates' => array( (object) array( 'response' => 'upgrade' ) ), 'version_checked' => '6.8' );
$off = array( 'disable_core_updates' => false, 'disable_plugin_updates' => false, 'disable_theme_updates' => false, 'disable_translation_updates' => false );

$u = $upd( $off );
$assert( 'updates: with the new settings off nothing is hooked (the module behaves as before)', ! has_filter( 'site_transient_update_plugins' ) && ! has_filter( 'pre_site_transient_update_plugins' ) && ! has_filter( 'pre_http_request' ) && ! has_filter( 'automatic_updater_disabled' ) );
$u = $upd( $off + array( 'hide_notices_plugins' => true ) );
$assert( 'updates: hiding plugin notices hooks only the plugins transient (late)', has_filter( 'site_transient_update_plugins' ) && ! has_filter( 'site_transient_update_themes' ) && ! has_filter( 'site_transient_update_core' ) && isset( $GLOBALS['uxs_hooks']['site_transient_update_plugins'][ PHP_INT_MAX - 10 ] ) );
$GLOBALS['uxs_user'] = new FakeUser( 3, 'klient' );
$seen = apply_filters( 'site_transient_update_plugins', $offers );
$assert( 'updates: an ordinary request sees no offers (response and translations emptied, last_checked and no_update kept), the stored value is untouched', array() === $seen->response && array() === $seen->translations && 1 === $seen->last_checked && array( 'b/b.php' => 1 ) === $seen->no_update && 1 === count( $offers->response ) );
$assert( 'updates: a non-object (nothing stored yet) passes through', false === apply_filters( 'site_transient_update_plugins', false ) );
$GLOBALS['uxs_cron_now'] = true;
$assert( 'updates: cron (the automatic updater) always sees the real offers', 1 === count( apply_filters( 'site_transient_update_plugins', $offers )->response ) );
$GLOBALS['uxs_cron_now'] = false;
$u = $upd( $off + array( 'hide_notices_plugins' => true, 'updates_visible_to' => 'Admin, Jan' ) );
$GLOBALS['uxs_user'] = new FakeUser( 1, 'jan' );
$assert( 'updates: a user on the allow list (case-insensitive login) sees the offers', 1 === count( apply_filters( 'site_transient_update_plugins', $offers )->response ) );
$GLOBALS['uxs_user'] = new FakeUser( 2, 'klient' );
$assert( 'updates: anybody else - an administrator included - does not', array() === apply_filters( 'site_transient_update_plugins', $offers )->response );
$u = $upd( $off + array( 'hide_notices_core' => true ) );
$assert( 'updates: hiding WordPress notices empties the core "updates" list', array() === apply_filters( 'site_transient_update_core', $core_offers )->updates && '6.8' === apply_filters( 'site_transient_update_core', $core_offers )->version_checked );

$u = $upd( $off + array( 'disable_checks_themes' => true ) );
$q = apply_filters( 'pre_site_transient_update_themes', false );
$assert( 'updates: stopping the theme check answers "checked just now, nothing new" from pre_site_transient (core and plugins untouched)', is_object( $q ) && array() === $q->response && abs( time() - $q->last_checked ) < 5 && ! has_filter( 'pre_site_transient_update_plugins' ) && ! has_filter( 'pre_site_transient_update_core' ) );
$assert( 'updates: the load-time checks and cron actions of themes (only) are removed, the cron events cleared on init', in_array( 'wp_update_themes|wp_update_themes', $GLOBALS['uxs_removed'], true ) && in_array( 'admin_init|_maybe_update_themes', $GLOBALS['uxs_removed'], true ) && ! in_array( 'wp_update_plugins|wp_update_plugins', $GLOBALS['uxs_removed'], true ) && ! in_array( 'wp_version_check|wp_version_check', $GLOBALS['uxs_removed'], true ) );
$GLOBALS['uxs_cron'] = array( 100 => array( 'wp_update_themes' => array( md5( serialize( array() ) ) => array() ), 'wp_update_plugins' => array( md5( serialize( array() ) ) => array() ) ) );
do_action( 'init' );
$assert( 'updates: only the cron event of the stopped kind is cleared', ! isset( $GLOBALS['uxs_cron'][100]['wp_update_themes'] ) && isset( $GLOBALS['uxs_cron'][100]['wp_update_plugins'] ) );
$matrix = array(
	array( array( 'themes' ), 'https://api.wordpress.org/themes/update-check/1.1/', true ),
	array( array( 'themes' ), 'https://api.wordpress.org/plugins/update-check/1.1/', false ),
	array( array( 'plugins' ), 'https://api.wordpress.org/plugins/update-check/1.1/', true ),
	array( array( 'core' ), 'http://api.wordpress.org/core/version-check/1.7/?x=1', true ),
	array( array( 'core' ), 'https://api.wordpress.org/translations/core/1.0/', false ),
	array( array( 'core', 'plugins', 'themes' ), 'https://evil.example/plugins/update-check/', false ),
	array( array( 'core', 'plugins', 'themes' ), 'https://api.wordpress.org/secret-key/1.1/salt/', false ),
);
$ok = true;
foreach ( $matrix as $case ) { $ok = $ok && $case[2] === $u->blocks_request( $case[1], $case[0] ); }
$assert( 'updates: only the update-check requests of the stopped kinds to api.wordpress.org are blocked', $ok );
$blocked = apply_filters( 'pre_http_request', false, array(), 'https://api.wordpress.org/themes/update-check/1.1/' );
$assert( 'updates: a blocked request gets a WP_Error, others are left alone', $blocked instanceof WP_Error && false === apply_filters( 'pre_http_request', false, array(), 'https://api.wordpress.org/plugins/update-check/1.1/' ) );

// The security exception.
$all_off = array( 'disable_core_updates' => true, 'core_update_type' => 'all', 'disable_plugin_updates' => true, 'disable_theme_updates' => true, 'disable_translation_updates' => true );
$u = $upd( $all_off );
$assert( 'security exception OFF: everything disabled kills the automatic updater (as before)', true === apply_filters( 'automatic_updater_disabled', false ) && false === apply_filters( 'auto_update_core', true ) );
$u = $upd( $all_off + array( 'allow_security_core' => true ) );
$assert( 'security exception ON: the updater stays alive, minor core releases are allowed, major and development ones are not', false === apply_filters( 'automatic_updater_disabled', false ) && true === apply_filters( 'allow_minor_auto_core_updates', false ) && false === apply_filters( 'allow_major_auto_core_updates', true ) && false === apply_filters( 'allow_dev_auto_core_updates', true ) && null !== ( $GLOBALS['uxs_hooks']['allow_minor_auto_core_updates'][20] ?? null ) );
$assert( 'security exception ON: plugin, theme and translation auto-updates stay off', false === apply_filters( 'auto_update_plugin', true, (object) array() ) && false === apply_filters( 'auto_update_theme', true, (object) array() ) && false === apply_filters( 'auto_update_translation', true ) );
$u = $upd( $all_off + array( 'core_update_type' => 'minor', 'allow_security_core' => true ) );
$assert( 'security exception ON beats "disable minor only": minor stays allowed', true === apply_filters( 'allow_minor_auto_core_updates', false ) );
$u = $upd( $all_off + array( 'allow_security_core' => true, 'disable_checks_core' => true, 'disable_checks_plugins' => true ) );
$assert( 'security exception ON: the WordPress check is NOT stopped (the offers are where security releases come from), the plugin check is', ! has_filter( 'pre_site_transient_update_core' ) && has_filter( 'pre_site_transient_update_plugins' ) && ! in_array( 'wp_version_check|wp_version_check', $GLOBALS['uxs_removed'], true ) );
$u = $upd( $all_off + array( 'disable_checks_core' => true ) );
$assert( 'security exception OFF: the WordPress check stops (core cron + load-time check removed)', has_filter( 'pre_site_transient_update_core' ) && in_array( 'wp_version_check|wp_version_check', $GLOBALS['uxs_removed'], true ) && in_array( 'admin_init|_maybe_update_core', $GLOBALS['uxs_removed'], true ) );

$ush = array_column( ( new UpdatesModule( 'disable-auto-updates', array() ) )->settings_schema(), null, 'key' );
$assert( 'schema: the new keys, off by default, notices / checks in their own tab with the security warning', array() === array_diff( array( 'allow_security_core', 'hide_notices_core', 'hide_notices_plugins', 'hide_notices_themes', 'updates_visible_to', 'disable_checks_core', 'disable_checks_plugins', 'disable_checks_themes' ), array_keys( $ush ) ) && false === $ush['hide_notices_plugins']['default'] && false === $ush['disable_checks_core']['default'] && false === $ush['allow_security_core']['default'] && 'Notices and update checks' === $ush['hide_notices_core']['section'] && ! isset( $ush['allow_security_core']['section'] ) && false !== strpos( $ush['hide_notices_core']['help'], 'security fixes' ) && false !== strpos( $ush['disable_checks_core']['help'], 'security fixes' ) );

/* ═══ 6 URL events: guard, storage, run, WP-Cron ═══ */

$public  = array( '93.184.216.34', '8.8.8.8', '2606:2800:220:1:248:1893:25c8:1946', '1.1.1.1' );
$private = array( '127.0.0.1', '127.1.2.3', '10.0.0.1', '172.16.5.5', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '192.0.0.8', '198.18.0.1', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '2002:7f00:0001::', '2002:0a00:0001::', 'not an ip' );
$ok_pub = true; $bad_priv = array();
foreach ( $public as $ip ) { $ok_pub = $ok_pub && UrlEvents::is_public_ip( $ip ); }
foreach ( $private as $ip ) { if ( UrlEvents::is_public_ip( $ip ) ) { $bad_priv[] = $ip; } }
$assert( 'url guard: public addresses pass, private / loopback / link-local (cloud metadata) / CGNAT / multicast / reserved / mapped and 6to4 forms do not', $ok_pub && array() === $bad_priv, implode( ',', $bad_priv ) );
$reset();
$cases = array(
	'https://93.184.216.34/ping'         => true,
	'http://93.184.216.34:8080/x?a=b'    => true,
	'http://127.0.0.1/admin'             => false,
	'http://localhost/wp-admin'          => false,
	'http://app.localhost/'              => false,
	'http://[::1]/'                      => false,
	'http://169.254.169.254/latest/meta-data/' => false,
	'http://192.168.0.10/'               => false,
	'http://user:pw@93.184.216.34/'      => false,
	'ftp://93.184.216.34/'               => false,
	'file:///etc/passwd'                 => false,
	'//93.184.216.34/'                   => false,
	'javascript:alert(1)'                => false,
);
$bad = array();
foreach ( $cases as $url => $expected ) { if ( ( true === UrlEvents::check_url( $url ) ) !== $expected ) { $bad[] = $url; } }
$assert( 'url guard: check_url() matrix (scheme, credentials, localhost, literal private targets)', array() === $bad, implode( ' | ', $bad ) );
add_filter( 'uxstudio_cron_url_allow_private', static fn( $allow, $url, $host ) => '127.0.0.1' === $host, 10, 3 );
$assert( 'url guard: ONLY the host opened by the filter is allowed, others stay blocked', true === UrlEvents::check_url( 'http://127.0.0.1/test' ) && true !== UrlEvents::check_url( 'http://10.0.0.1/test' ) );
$GLOBALS['uxs_hooks'] = array();

$e = UrlEvents::save( array( 'name' => 'Ping', 'url' => 'https://93.184.216.34/ping', 'method' => 'get', 'schedule' => 'hourly', 'timeout' => 500, 'enabled' => true ) );
$assert( 'save: a valid event is stored with a clamped timeout, upper-case method, last result empty', is_array( $e ) && 'GET' === $e['method'] && 60 === $e['timeout'] && 'hourly' === $e['schedule'] && true === $e['enabled'] && '' === $e['last_status'] && 8 === strlen( $e['id'] ) && isset( get_option( UrlEvents::OPTION )[ $e['id'] ] ) );
$assert( 'save: it is scheduled in WP-Cron with its interval and the event id as the only argument', (bool) ( $s = wp_get_scheduled_event( UrlEvents::HOOK, array( $e['id'] ) ) ) && 'hourly' === $s->schedule );
foreach ( array( 'http://127.0.0.1/x' => 'uxstudio_blocked_url', 'ftp://x.cz/' => 'uxstudio_bad_url', '' => 'uxstudio_bad_url' ) as $url => $code ) {
	$r = UrlEvents::save( array( 'url' => $url ) );
	$assert( 'save: "' . $url . '" is refused (' . $code . ') and nothing is stored', $r instanceof WP_Error && $code === $r->get_error_code() && 1 === count( UrlEvents::all() ) );
}
$r = UrlEvents::save( array( 'url' => 'https://93.184.216.34/x', 'schedule' => 'every_weird' ) );
$assert( 'save: an interval that does not exist is refused', $r instanceof WP_Error && 'uxstudio_bad_schedule' === $r->get_error_code() );
$r = UrlEvents::save( array( 'url' => 'https://93.184.216.34/y' ), 'deadbeef' );
$assert( 'save: changing an event that does not exist is a 404', $r instanceof WP_Error && 404 === $r->get_error_data()['status'] );
$post = UrlEvents::save( array( 'url' => 'https://93.184.216.34/hook', 'method' => 'POST', 'body' => '{"a":1}', 'schedule' => '', 'first_run' => time() + 3600, 'enabled' => true ) );
$assert( 'save: a one-off event is a single WP-Cron entry at the chosen time; a body is kept only for POST', is_array( $post ) && '' === $post['schedule'] && false === wp_get_scheduled_event( UrlEvents::HOOK, array( $post['id'] ) )->schedule && $post['first_run'] === wp_next_scheduled( UrlEvents::HOOK, array( $post['id'] ) ) && '{"a":1}' === $post['body'] && '' === UrlEvents::save( array( 'url' => 'https://93.184.216.34/g', 'method' => 'GET', 'body' => 'x', 'enabled' => false ) )['body'] );
$upd2 = UrlEvents::save( array( 'url' => 'https://93.184.216.34/ping', 'name' => 'Ping', 'schedule' => 'daily', 'enabled' => true ), $e['id'] );
$assert( 'save: changing the interval reschedules the entry', 'daily' === wp_get_scheduled_event( UrlEvents::HOOK, array( $e['id'] ) )->schedule );
UrlEvents::save( array( 'url' => 'https://93.184.216.34/ping', 'schedule' => 'daily', 'enabled' => false ), $e['id'] );
$assert( 'save: switching an event off removes its WP-Cron entry, the definition stays', false === wp_get_scheduled_event( UrlEvents::HOOK, array( $e['id'] ) ) && null !== UrlEvents::get( $e['id'] ) );
UrlEvents::set_enabled( array( $e['id'] ), true );
$assert( 'set_enabled: back on = scheduled again', false !== wp_get_scheduled_event( UrlEvents::HOOK, array( $e['id'] ) ) );

// Running.
$GLOBALS['uxs_http'] = array();
$GLOBALS['uxs_http_map'] = array( '93.184.216.34' => array( 204, '' ) );
$r = UrlEvents::execute( $e['id'] );
$ev = UrlEvents::get( $e['id'] );
$assert( 'run: GET through wp_safe_remote_request with the timeout, the result (status, code, time) is stored, no body stored', 'ok' === $r['status'] && 204 === $r['code'] && 'wp_safe_remote_request' === $GLOBALS['uxs_http'][0]['fn'] && 'GET' === $GLOBALS['uxs_http'][0]['args']['method'] && 15 === $GLOBALS['uxs_http'][0]['args']['timeout'] && 'ok' === $ev['last_status'] && 204 === $ev['last_code'] && abs( time() - $ev['last_run'] ) < 5 && '' === $ev['last_error'] && ! isset( $GLOBALS['uxs_http'][0]['args']['reject_unsafe_urls'] ) );
$GLOBALS['uxs_http_map'] = array( '93.184.216.34' => array( 503, 'down' ) );
$r = UrlEvents::execute( $e['id'] );
$assert( 'run: a 5xx is an error with its code', 'error' === $r['status'] && 503 === $r['code'] && 'HTTP 503' === UrlEvents::get( $e['id'] )['last_error'] && 'error' === UrlEvents::get( $e['id'] )['last_status'] );
$GLOBALS['uxs_http_map'] = array( '93.184.216.34' => new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );
$r = UrlEvents::execute( $e['id'] );
$assert( 'run: a transport error is stored as the error, code 0', 'error' === $r['status'] && 0 === $r['code'] && 'cURL error 6: Could not resolve host' === UrlEvents::get( $e['id'] )['last_error'] );
$GLOBALS['uxs_http_map'] = array( '93.184.216.34' => array( 200, '' ) );
$GLOBALS['uxs_http'] = array();
UrlEvents::execute( $post['id'] );
$pa = $GLOBALS['uxs_http'][0]['args'];
$assert( 'run: POST sends the body as JSON when it is JSON', 'POST' === $pa['method'] && '{"a":1}' === $pa['body'] && 'application/json' === $pa['headers']['Content-Type'] );
$assert( 'run: a one-off event switches itself off after it ran (and is not rescheduled by sync)', false === UrlEvents::get( $post['id'] )['enabled'] && ( UrlEvents::sync() || false === wp_get_scheduled_event( UrlEvents::HOOK, array( $post['id'] ) ) ) );

// A stored event whose target has become private (DNS changed, option edited by hand): blocked at run time, no request.
$all = get_option( UrlEvents::OPTION );
$all[ $e['id'] ]['url'] = 'http://169.254.169.254/latest/meta-data/';
update_option( UrlEvents::OPTION, $all );
$GLOBALS['uxs_http'] = array();
$r = UrlEvents::execute( $e['id'] );
$assert( 'run: the guard runs again at run time - a target that turned private is "blocked" and no request leaves', 'blocked' === $r['status'] && array() === $GLOBALS['uxs_http'] && 'blocked' === UrlEvents::get( $e['id'] )['last_status'] && false !== strpos( UrlEvents::get( $e['id'] )['last_error'], 'private network' ) );
// Opened by code: wp_remote_request without redirects.
add_filter( 'uxstudio_cron_url_allow_private', static fn( $allow, $url, $host ) => '127.0.0.1' === $host, 10, 3 );
$all[ $e['id'] ]['url'] = 'http://127.0.0.1/local-cron';
update_option( UrlEvents::OPTION, $all );
$GLOBALS['uxs_http_map'] = array( '127.0.0.1' => array( 200, '' ) );
$r = UrlEvents::execute( $e['id'] );
$assert( 'run: an address opened by the filter goes out WITHOUT the safe-URL check and WITHOUT following redirects', 'ok' === $r['status'] && 'wp_remote_request' === $GLOBALS['uxs_http'][0]['fn'] && 0 === $GLOBALS['uxs_http'][0]['args']['redirection'] && false === $GLOBALS['uxs_http'][0]['args']['reject_unsafe_urls'] );
$GLOBALS['uxs_hooks'] = array();
$assert( 'run: an event that does not exist gives null', null === UrlEvents::execute( 'ffffffff' ) );

// Sync, delete, switching the module off, limits.
$GLOBALS['uxs_cron'] = array();
UrlEvents::sync();
$assert( 'sync: re-creates the entry of an enabled event whose WP-Cron entry was lost (and none for a disabled one)', false !== wp_get_scheduled_event( UrlEvents::HOOK, array( $e['id'] ) ) && false === wp_get_scheduled_event( UrlEvents::HOOK, array( $post['id'] ) ) );
UrlEvents::unschedule_all();
$assert( 'unschedule_all: module off - the entries go, the definitions stay', false === wp_get_scheduled_event( UrlEvents::HOOK, array( $e['id'] ) ) && count( UrlEvents::all() ) >= 2 );
UrlEvents::sync();
$assert( 'delete: removes the definition and the entry', UrlEvents::delete( $e['id'] ) && null === UrlEvents::get( $e['id'] ) && false === wp_get_scheduled_event( UrlEvents::HOOK, array( $e['id'] ) ) && ! UrlEvents::delete( $e['id'] ) );
$GLOBALS['uxs_options'][ UrlEvents::OPTION ] = array();
for ( $i = 0; $i < UrlEvents::MAX; $i++ ) { $x = UrlEvents::save( array( 'url' => 'https://93.184.216.34/e' . $i, 'enabled' => false ) ); }
$over = UrlEvents::save( array( 'url' => 'https://93.184.216.34/over' ) );
$assert( 'limit: at most ' . UrlEvents::MAX . ' events', $over instanceof WP_Error && 'uxstudio_too_many' === $over->get_error_code() && UrlEvents::MAX === count( UrlEvents::all() ) );
$GLOBALS['uxs_options'][ UrlEvents::OPTION ] = array();
$ad = UrlEvents::add_disabled( 'abcdef01', array( 'url' => 'https://93.184.216.34/a', 'schedule' => 'custom_5' ), array( 'custom_5' ) );
$assert( 'add_disabled: stored OFF, not scheduled, a vouched-for interval is accepted; an unvouched one and a private target are refused', is_array( $ad ) && false === $ad['enabled'] && false === wp_next_scheduled( UrlEvents::HOOK, array( 'abcdef01' ) ) && UrlEvents::add_disabled( 'abcdef02', array( 'url' => 'https://93.184.216.34/a', 'schedule' => 'custom_5' ) ) instanceof WP_Error && UrlEvents::add_disabled( 'abcdef03', array( 'url' => 'http://10.1.1.1/a' ) ) instanceof WP_Error );

/* ═══ 7 Auto Image Upload ═══ */

$ctx = array( 'filename' => 'foto', 'alt' => 'Popis', 'host' => 'example.test', 'post_id' => 42, 'post_name' => 'muj-clanek', 'post_date' => gmmktime( 10, 0, 0, 3, 7, 2025 ), 'now' => gmmktime( 8, 0, 0, 10, 23, 2026 ) );
$assert( 'pattern: every placeholder of Auto Upload Images', '2026-10-23|2026|10|23|2025-03-7|2025|03|7|example.test|42|muj-clanek|foto|Popis|1792742400' === Processor::resolve_pattern( '%today_date%|%year%|%month%|%today_day%|%post_date%|%post_year%|%post_month%|%post_day%|%url%|%post_id%|%postname%|%filename%|%image_alt%|%timestamp%', $ctx ) );
$assert( 'pattern: the deprecated %date% / %day% still work, an unknown %word% stays, %random% is unique', '2026-10-23 23 %nope%' === Processor::resolve_pattern( '%date% %day% %nope%', $ctx ) && Processor::resolve_pattern( '%random%', $ctx ) !== Processor::resolve_pattern( '%random%', $ctx ) );
$assert( 'pattern: a post date of 0 means "now"', '2026' === Processor::resolve_pattern( '%post_year%', array( 'now' => $ctx['now'], 'post_date' => 0 ) ) );
$assert( 'file name: the default pattern keeps the downloaded name, a pattern replaces the base name and keeps the extension', 'photo.jpg' === Processor::file_name( '%filename%', 'photo.jpg', $ctx ) && 'muj-clanek-foto-2026.png' === Processor::file_name( '%postname%-%filename%-%year%', 'x.png', $ctx ) && 'a-b.webp' === Processor::file_name( 'a/b', 'x.webp', $ctx ) );
$gen = Processor::file_name( '%nothing%', 'x.png', array( 'filename' => 'foto' ) );
$assert( 'file name: unknown placeholder only = a safe name, never empty', 'nothing.png' === $gen );
$noname = Processor::file_name( 'img-%filename%', 'pasted-image-20261023.png', array( 'filename' => '' ) + $ctx );
$assert( 'file name: no name in the address = the generated name is used as %filename%', 'img-pasted-image-20261023.png' === $noname, $noname );
$sizes = array(
	array( array( 3000, 2000, 1200, 0 ), array( 1200, 800 ) ),
	array( array( 3000, 2000, 0, 500 ), array( 750, 500 ) ),
	array( array( 3000, 2000, 1200, 500 ), array( 750, 500 ) ),
	array( array( 800, 600, 1200, 900 ), null ),
	array( array( 800, 600, 0, 0 ), null ),
	array( array( 0, 0, 100, 100 ), null ),
	array( array( 5000, 10, 100, 0 ), array( 100, 1 ) ),
);
$bad = array();
foreach ( $sizes as $case ) { if ( Processor::fit_size( ...$case[0] ) !== $case[1] ) { $bad[] = json_encode( $case[0] ); } }
$assert( 'resize: scaled down inside the box keeping proportions, never up, never below 1 px', array() === $bad, implode( ' ', $bad ) );
$assert( 'host: www and a port are handled, bare domains and URLs both work', 'irani.im' === Processor::bare_host( 'https://www.irani.im/x' ) && 'irani.im' === Processor::bare_host( 'irani.im' ) && 'cdn.x.cz:8080' === Processor::bare_host( 'http://cdn.x.cz:8080/a' ) && 'x.cz' === Processor::bare_host( '//www2.x.cz/a' ) && '' === Processor::bare_host( '' ) );

$proc = new Processor( 1048576, array( 'base_url' => 'https://cdn.example.test/', 'alt_pattern' => 'Obrázek: %image_alt%', 'excluded_domains' => array( 'excluded.cz', 'https://www.other.cz', '*.cdn.bad.cz' ) ) );
$assert( 'excluded domains: exact host (www ignored, URL or bare), *. covers subdomains only', $proc->is_excluded( 'https://excluded.cz/a.jpg' ) && $proc->is_excluded( 'http://www.excluded.cz/a.jpg' ) && $proc->is_excluded( 'https://other.cz/a.jpg' ) && $proc->is_excluded( 'https://img.cdn.bad.cz/a.jpg' ) && ! $proc->is_excluded( 'https://cdn.bad.cz/a.jpg' ) && ! $proc->is_excluded( 'https://notexcluded.cz/a.jpg' ) && ! $proc->is_excluded( 'data:image/png;base64,AAAA' ) );
$assert( 'base address: replaces scheme and host of the attachment URL, keeps path and query; "/" leaves the path; blank / malformed change nothing', 'https://cdn.example.test/wp-content/uploads/2026/10/a.jpg?v=1' === $proc->public_url( 'https://www.example.test/wp-content/uploads/2026/10/a.jpg?v=1' ) && '/wp-content/uploads/a.jpg' === ( new Processor( 1024, array( 'base_url' => '/' ) ) )->public_url( 'https://www.example.test/wp-content/uploads/a.jpg' ) && 'https://www.example.test/a.jpg' === ( new Processor( 1024, array() ) )->public_url( 'https://www.example.test/a.jpg' ) && 'https://www.example.test/a.jpg' === ( new Processor( 1024, array( 'base_url' => 'cdn.no-scheme.cz' ) ) )->public_url( 'https://www.example.test/a.jpg' ) );

// process_content with every download already known (the URL map), so no download or media library is needed.
$mapped = 'https://www.example.test/site/wp-content/uploads/2026/10/mapped.jpg';
$GLOBALS['uxs_options']['uxstudio_auto_image_upload_url_map'] = array( md5( 'https://img.other-site.cz/p/a.jpg' ) => $mapped, md5( 'https://excluded.cz/b.jpg' ) => $mapped );
$html = '<p><img class="x" src="https://img.other-site.cz/p/a.jpg" alt="Kočka" srcset="https://img.other-site.cz/p/a-2x.jpg 2x" width="10"> <img src="https://excluded.cz/b.jpg" alt="Nech"> <img src="https://www.example.test/site/own.jpg" alt="Vlastní"> <img src="https://img.other-site.cz/p/a.jpg"></p>';
$out  = $proc->process_content( $html, 0, 10 );
$assert( 'process_content: the external image gets the base address and the alt pattern, srcset goes, the excluded and the own image stay', false !== strpos( $out, 'src="https://cdn.example.test/site/wp-content/uploads/2026/10/mapped.jpg"' ) && false !== strpos( $out, 'alt="Obrázek: Kočka"' ) && false === strpos( $out, 'srcset' ) && false !== strpos( $out, 'src="https://excluded.cz/b.jpg" alt="Nech"' ) && false !== strpos( $out, 'src="https://www.example.test/site/own.jpg"' ), $out );
$assert( 'process_content: an image with no alt gets the pattern result only when it is not empty (here "Obrázek: " from an empty %image_alt%)', 2 === substr_count( $out, 'cdn.example.test' ) && 1 === preg_match( '#<img alt="Obrázek:" src="https://cdn\.example\.test[^"]+">#', $out ), $out );
$default = new Processor( 1048576, array() );
$out2    = $default->process_content( '<img src="https://img.other-site.cz/p/a.jpg" alt="Kočka">', 0, 10 );
$assert( 'process_content: with the defaults the alt text and the address are exactly what WordPress gave (no change of behaviour)', false !== strpos( $out2, 'alt="Kočka"' ) && false !== strpos( $out2, 'src="' . $mapped . '"' ), $out2 );

@unlink( $tmp1 );
echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
