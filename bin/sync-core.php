<?php
/**
 * Vendors the shared AI core (sturmjan/ux1-ai-core) into vendor/ux1-ai-core, or checks that the vendored copy was
 * not edited by hand. Never edit vendor/ux1-ai-core directly: change the core repo, release it, sync here.
 *
 *   php bin/sync-core.php [path-to-ux1-ai-core]     copy autoload.php, src/, README.md; write VERSION + checksums.json
 *   php bin/sync-core.php --check                   exit 1 when a vendored file differs from checksums.json
 *
 * Source path: the argument, else env UX1_AI_CORE_SRC, else ../.komponenty/ux1-ai-core next to the plugin folder.
 * The sync refuses a core checkout with uncommitted changes (pass --allow-dirty for local experiments only).
 *
 * @package UxStudio
 */

// phpcs:disable

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$plugin = dirname( __DIR__ );
$target = $plugin . '/vendor/ux1-ai-core';
$args   = array_slice( $argv, 1 );

/** @return array<string, string> relative path => sha256 */
function ux1_hashes( string $root ): array {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		if ( 'checksums.json' === $rel ) {
			continue;
		}
		// Hash with normalised line endings: a Windows checkout (core.autocrlf) has CRLF, git and CI have LF.
		$out[ $rel ] = hash( 'sha256', str_replace( "\r\n", "\n", (string) file_get_contents( $file->getPathname() ) ) );
	}
	ksort( $out );
	return $out;
}

if ( in_array( '--check', $args, true ) ) {
	$manifest = $target . '/checksums.json';
	if ( ! is_file( $manifest ) ) {
		fwrite( STDERR, "vendor/ux1-ai-core/checksums.json missing - run bin/sync-core.php\n" );
		exit( 1 );
	}
	$expected = json_decode( (string) file_get_contents( $manifest ), true )['files'] ?? array();
	$actual   = ux1_hashes( $target );
	$problems = array();
	foreach ( $expected as $rel => $hash ) {
		if ( ! isset( $actual[ $rel ] ) ) {
			$problems[] = "missing: {$rel}";
		} elseif ( $actual[ $rel ] !== $hash ) {
			$problems[] = "edited: {$rel}";
		}
	}
	foreach ( array_diff_key( $actual, $expected ) as $rel => $hash ) {
		$problems[] = "unexpected: {$rel}";
	}
	if ( $problems ) {
		fwrite( STDERR, "vendor/ux1-ai-core differs from the synced core (edit the core repo, not the copy):\n  " . implode( "\n  ", $problems ) . "\n" );
		exit( 1 );
	}
	echo 'vendor/ux1-ai-core matches checksums.json (' . count( $expected ) . " files)\n";
	exit( 0 );
}

$positional = array_values( array_filter( $args, static fn( $a ) => ! str_starts_with( $a, '--' ) ) );
$source     = $positional[0] ?? ( getenv( 'UX1_AI_CORE_SRC' ) ?: dirname( $plugin ) . '/.komponenty/ux1-ai-core' );
$source     = rtrim( str_replace( '\\', '/', $source ), '/' );
if ( ! is_file( $source . '/autoload.php' ) || ! is_dir( $source . '/src' ) ) {
	fwrite( STDERR, "Not a ux1-ai-core checkout: {$source}\n" );
	exit( 1 );
}

$git    = static fn( string $cmd ) => trim( (string) shell_exec( 'git -C ' . escapeshellarg( $source ) . ' ' . $cmd . ' 2>' . ( PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null' ) ) );
$commit = $git( 'rev-parse HEAD' );
$dirty  = '' !== $git( 'status --porcelain -- src autoload.php README.md' );
if ( $dirty && ! in_array( '--allow-dirty', $args, true ) ) {
	fwrite( STDERR, "The core checkout has uncommitted changes in src/ - commit them first (or --allow-dirty for a local experiment).\n" );
	exit( 1 );
}

// Fresh copy: remove the old vendored files first so deleted core files do not linger.
if ( is_dir( $target ) ) {
	$old = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $old as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
}
@mkdir( $target, 0755, true );

$copy = static function ( string $from, string $to ) use ( &$copy ): void {
	if ( is_dir( $from ) ) {
		@mkdir( $to, 0755, true );
		foreach ( scandir( $from ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$copy( $from . '/' . $entry, $to . '/' . $entry );
			}
		}
		return;
	}
	copy( $from, $to );
};
foreach ( array( 'autoload.php', 'src', 'README.md' ) as $part ) {
	$copy( $source . '/' . $part, $target . '/' . $part );
}

require_once $source . '/src/Version.php';
$version = \Ux1\AiCore\Version::VERSION;
file_put_contents( $target . '/VERSION', $version . ( $commit ? ' ' . $commit : '' ) . ( $dirty ? ' (dirty)' : '' ) . "\n" );
file_put_contents(
	$target . '/checksums.json',
	json_encode( array( 'version' => $version, 'commit' => $commit, 'files' => ux1_hashes( $target ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
);

echo "Synced ux1-ai-core {$version}" . ( $commit ? ' (' . substr( $commit, 0, 7 ) . ')' : '' ) . " into vendor/ux1-ai-core\n";
