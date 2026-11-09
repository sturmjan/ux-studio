<?php
/**
 * Builds includes/Modules/Consent/data/open-cookie-database.json from the Open Cookie Database.
 *
 *   php bin/import-open-cookie-database.php                 download the CSV from GitHub
 *   php bin/import-open-cookie-database.php --file=ocd.csv  use a CSV you already have
 *
 * Source: https://github.com/jkwakman/Open-Cookie-Database (Apache License 2.0, copy in
 * includes/Modules/Consent/data/OPEN-COOKIE-DATABASE-LICENSE.txt). The output keeps only what the plugin shows: cookie
 * name, wildcard flag, platform, category, retention, data controller and a description shortened to 160 characters.
 * It is a modified (reduced) copy, which the data file says in its `meta`. Run it by hand when you want newer data and
 * commit the result; the plugin never downloads anything at run time.
 *
 * Output format (one file, read lazily by Consent\OpenCookieDb):
 *   { "meta": {...}, "platforms": [name...], "controllers": [name...],
 *     "cookies": [ [ name, wildcard 0|1, platform index, category a|f|m|p|s|n, retention, controller index, description ] ... ] }
 * Categories: a analytics, f functional, m marketing, p personalization, s security, n necessary.
 * Retention is a duration code (`session`, `30d`, `13mo`, `2y`, `15min`...) when it can be read, otherwise the original text.
 *
 * @package UxStudio
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

const OCD_RAW_BASE = 'https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/';
const OCD_API_COMMITS = 'https://api.github.com/repos/jkwakman/Open-Cookie-Database/commits?path=open-cookie-database.csv&per_page=1';

$options = getopt( '', array( 'file::', 'out::' ) );
$root    = dirname( __DIR__ );
$out     = is_string( $options['out'] ?? null ) ? $options['out'] : $root . '/includes/Modules/Consent/data/open-cookie-database.json';

/**
 * @param string $url Address to download.
 */
function ocd_fetch( string $url ): string {
	$ctx  = stream_context_create( array( 'http' => array( 'timeout' => 60, 'header' => "User-Agent: ux-studio-ocd-import\r\n" ) ) );
	$data = @file_get_contents( $url, false, $ctx );
	if ( false === $data && function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'ux-studio-ocd-import' ) );
		$data = curl_exec( $ch );
		curl_close( $ch );
	}
	if ( ! is_string( $data ) || '' === $data ) {
		fwrite( STDERR, "Could not download $url\n" );
		exit( 1 );
	}
	return $data;
}

/**
 * Retention text => duration code, or the original text when it cannot be read.
 *
 * @param string $text Raw retention.
 */
function ocd_retention( string $text ): string {
	$text = trim( $text );
	$low  = strtolower( $text );
	if ( '' === $low ) {
		return '';
	}
	if ( 1 === preg_match( '/^(?:sessions?|end of (?:the )?session(?: \(browser\))?)$/', $low ) ) {
		return 'session';
	}
	if ( 1 === preg_match( '/^(\d+)\s*(second|sec|minute|min|hour|day|week|month|year)s?$/', $low, $m ) ) {
		$n    = (int) $m[1];
		$unit = $m[2];
		switch ( $unit ) {
			case 'second':
			case 'sec':
				return $n . 's';
			case 'minute':
			case 'min':
				return $n . 'min';
			case 'hour':
				return $n . 'h';
			case 'day':
				return $n . 'd';
			case 'week':
				return ( $n * 7 ) . 'd';
			case 'month':
				return $n . 'mo';
			default:
				return $n . 'y';
		}
	}
	return mb_substr( $text, 0, 60 );
}

/**
 * Description cleaned and cut to 160 characters at a word boundary.
 *
 * @param string $text Raw description.
 */
function ocd_description( string $text ): string {
	$text = (string) preg_replace( '/(?<=\p{L})\x{FFFD}(?=\p{L})/u', "'", $text );
	$text = str_replace( "\u{FFFD}", '', $text );
	$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	if ( mb_strlen( $text ) <= 160 ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, 160 );
	$space = mb_strrpos( $cut, ' ' );
	return rtrim( false !== $space && $space > 100 ? mb_substr( $cut, 0, $space ) : $cut, " ,;:-" ) . '...';
}

$csv = is_string( $options['file'] ?? null ) ? (string) file_get_contents( $options['file'] ) : ocd_fetch( OCD_RAW_BASE . 'open-cookie-database.csv' );
$csv = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
$fh  = fopen( 'php://temp', 'r+' );
fwrite( $fh, $csv );
rewind( $fh );

$header = fgetcsv( $fh, 0, ',', '"', '' );
if ( ! is_array( $header ) || ! in_array( 'Cookie / Data Key name', $header, true ) ) {
	fwrite( STDERR, "Unexpected CSV header\n" );
	exit( 1 );
}
$col = array_flip( $header );

$cat_map     = array( 'Analytics' => 'a', 'Functional' => 'f', 'Marketing' => 'm', 'Personalization' => 'p', 'Security' => 's', 'Necessary' => 'n' );
$platforms   = array();
$controllers = array();
$cookies     = array();
while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
	$name = trim( (string) ( $row[ $col['Cookie / Data Key name'] ] ?? '' ) );
	if ( '' === $name || 1 === preg_match( '/\s/', $name ) ) {
		continue;
	}
	$platform   = trim( (string) $row[ $col['Platform'] ] );
	$controller = trim( (string) $row[ $col['Data Controller'] ] );
	$p          = array_search( $platform, $platforms, true );
	if ( false === $p ) {
		$platforms[] = $platform;
		$p           = count( $platforms ) - 1;
	}
	$c = array_search( $controller, $controllers, true );
	if ( false === $c ) {
		$controllers[] = $controller;
		$c             = count( $controllers ) - 1;
	}
	$cookies[] = array(
		$name,
		'1' === trim( (string) $row[ $col['Wildcard match'] ] ) ? 1 : 0,
		$p,
		$cat_map[ trim( (string) $row[ $col['Category'] ] ) ] ?? 'f',
		ocd_retention( (string) $row[ $col['Retention period'] ] ),
		$c,
		ocd_description( (string) $row[ $col['Description'] ] ),
	);
}

$sha = '';
if ( ! isset( $options['file'] ) ) {
	$commits = json_decode( (string) @file_get_contents( OCD_API_COMMITS, false, stream_context_create( array( 'http' => array( 'timeout' => 30, 'header' => "User-Agent: ux-studio-ocd-import\r\n" ) ) ) ), true );
	$sha     = is_array( $commits ) && isset( $commits[0]['sha'] ) ? substr( (string) $commits[0]['sha'], 0, 12 ) : '';
}

$data = array(
	'meta'        => array(
		'source'   => 'https://github.com/jkwakman/Open-Cookie-Database',
		'license'  => 'Apache-2.0',
		'notice'   => 'Reduced copy: only name, wildcard flag, platform, category, retention, data controller and a description shortened to 160 characters. Wildcard names match as a prefix. Generated by bin/import-open-cookie-database.php.',
		'fetched'  => gmdate( 'Y-m-d' ),
		'commit'   => $sha,
		'cookies'  => count( $cookies ),
	),
	'platforms'   => $platforms,
	'controllers' => $controllers,
	'cookies'     => $cookies,
);

if ( ! is_dir( dirname( $out ) ) ) {
	mkdir( dirname( $out ), 0777, true );
}
$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( ! is_string( $json ) || false === file_put_contents( $out, $json ) ) {
	fwrite( STDERR, "Could not write $out\n" );
	exit( 1 );
}
printf( "%d cookies, %d platforms, %s (%.0f kB)\n", count( $cookies ), count( $platforms ), $out, strlen( $json ) / 1024 );
