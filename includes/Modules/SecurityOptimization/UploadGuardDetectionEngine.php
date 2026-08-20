<?php
/**
 * Upload Guard detection engine: heuristic pattern scoring for uploaded/scanned files.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Ported verbatim (signatures/weights) from the legacy security-optimization
 * module's upload-guard/DetectionEngine.php. This is the core detection
 * logic - PHP/JS/SVG/HTML pattern signatures, obfuscation heuristics, and
 * ZIP archive scanning with strict per-archive safety limits.
 */
final class UploadGuardDetectionEngine {

	private const THRESHOLD_LOW      = 16;
	private const THRESHOLD_MEDIUM   = 31;
	private const THRESHOLD_HIGH     = 51;

	private const PHP_PATTERNS = array(
		array( 'name' => 'eval', 'regex' => '/\beval\s*\(/i', 'weight' => 30, 'label' => 'eval() function call' ),
		array( 'name' => 'base64_decode', 'regex' => '/\bbase64_decode\s*\(/i', 'weight' => 20, 'label' => 'base64_decode() function call' ),
		array( 'name' => 'gzinflate', 'regex' => '/\bgzinflate\s*\(/i', 'weight' => 25, 'label' => 'gzinflate() function call' ),
		array( 'name' => 'str_rot13', 'regex' => '/\bstr_rot13\s*\(/i', 'weight' => 20, 'label' => 'str_rot13() function call' ),
		array( 'name' => 'assert', 'regex' => '/\bassert\s*\(/i', 'weight' => 25, 'label' => 'assert() function call' ),
		array( 'name' => 'preg_replace_e', 'regex' => '/preg_replace\s*\(\s*[\'"][^\'"]*\/e[\'"\s,]/i', 'weight' => 40, 'label' => 'preg_replace() with /e modifier' ),
		array( 'name' => 'system', 'regex' => '/\bsystem\s*\(/i', 'weight' => 40, 'label' => 'system() function call' ),
		array( 'name' => 'shell_exec', 'regex' => '/\bshell_exec\s*\(/i', 'weight' => 40, 'label' => 'shell_exec() function call' ),
		array( 'name' => 'passthru', 'regex' => '/\bpassthru\s*\(/i', 'weight' => 40, 'label' => 'passthru() function call' ),
		array( 'name' => 'exec', 'regex' => '/\bexec\s*\(/i', 'weight' => 35, 'label' => 'exec() function call' ),
		array( 'name' => 'create_function', 'regex' => '/\bcreate_function\s*\(/i', 'weight' => 30, 'label' => 'create_function() call (deprecated)' ),
		array( 'name' => 'post_in_include', 'regex' => '/(include|require|include_once|require_once)\s*\(?\s*\$_(POST|GET|REQUEST|COOKIE)/i', 'weight' => 50, 'label' => '$_POST/$_GET/$_REQUEST in include/require' ),
	);

	private const OBFUSCATION_PATTERNS = array(
		array( 'name' => 'long_base64', 'regex' => '/[A-Za-z0-9+\/=]{500,}/', 'weight' => 30, 'label' => 'Long base64-encoded string (>500 chars)' ),
		array( 'name' => 'hex_strings', 'regex' => '/(\\\\x[0-9a-fA-F]{2}){10,}/', 'weight' => 25, 'label' => 'Hex-encoded string sequence' ),
	);

	private const SVG_PATTERNS = array(
		array( 'name' => 'svg_script', 'regex' => '/<script[^>]*>/i', 'weight' => 50, 'label' => '<script> tag in SVG' ),
		array( 'name' => 'svg_event_handler', 'regex' => '/\bon(load|error|click|mouseover|mouseout|focus|blur)\s*=/i', 'weight' => 40, 'label' => 'Event handler attribute in SVG' ),
		array( 'name' => 'svg_javascript_uri', 'regex' => '/javascript\s*:/i', 'weight' => 40, 'label' => 'javascript: URI in SVG' ),
		array( 'name' => 'svg_external_ref', 'regex' => '/xlink:href\s*=\s*["\']https?:/i', 'weight' => 15, 'label' => 'External reference in SVG (xlink:href)' ),
	);

	private const HTML_PATTERNS = array(
		array( 'name' => 'html_script', 'regex' => '/<script[^>]*>/i', 'weight' => 50, 'label' => '<script> tag in HTML' ),
		array( 'name' => 'html_event_handler', 'regex' => '/\bon(load|error|click|mouseover)\s*=/i', 'weight' => 40, 'label' => 'Event handler attribute' ),
		array( 'name' => 'html_javascript_uri', 'regex' => '/javascript\s*:/i', 'weight' => 40, 'label' => 'javascript: URI' ),
	);

	private const JS_PATTERNS = array(
		array( 'name' => 'js_eval', 'regex' => '/\beval\s*\(/i', 'weight' => 30, 'label' => 'eval() in JavaScript' ),
		array( 'name' => 'js_function_constructor', 'regex' => '/new\s+Function\s*\(/i', 'weight' => 35, 'label' => 'new Function() constructor' ),
	);

	/** @var array<int,array{name?:string,regex:string,weight?:int,label?:string}> */
	private array $custom_patterns;

	/** @var string[] */
	private array $whitelist_paths;

	/**
	 * @param array $custom_patterns Array of ['regex'=>string,'weight'=>int,'label'=>string].
	 * @param array $whitelist_paths Array of path prefixes to skip.
	 */
	public function __construct( array $custom_patterns = array(), array $whitelist_paths = array() ) {
		$this->custom_patterns = $custom_patterns;
		$this->whitelist_paths = $whitelist_paths;
	}

	/**
	 * Scan a file and return a score/severity/matches result.
	 *
	 * @return array{score:int,severity:string,matches:array}
	 */
	public function scan( string $file_path, string $extension ): array {
		if ( $this->is_whitelisted( $file_path ) ) {
			return array( 'score' => 0, 'severity' => 'clean', 'matches' => array() );
		}

		$extension = strtolower( ltrim( $extension, '.' ) );

		if ( 'zip' === $extension ) {
			return $this->scan_zip( $file_path );
		}

		if ( ! is_readable( $file_path ) ) {
			return array( 'score' => 0, 'severity' => 'clean', 'matches' => array() );
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content || '' === $content ) {
			return array( 'score' => 0, 'severity' => 'clean', 'matches' => array() );
		}

		$patterns = $this->patterns_for_extension( $extension );

		$matches     = $this->match_patterns( $content, $patterns );
		$total_score = 0;
		foreach ( $matches as $match ) {
			$total_score += $match['weight'];
		}

		if ( in_array( $extension, array( 'php', 'phtml', 'phar' ), true ) ) {
			$non_ascii = $this->check_non_ascii_concentration( $content );
			if ( null !== $non_ascii ) {
				$matches[]   = $non_ascii;
				$total_score += $non_ascii['weight'];
			}
		}

		if ( ! empty( $this->custom_patterns ) ) {
			foreach ( $this->match_patterns( $content, $this->custom_patterns ) as $custom_match ) {
				$matches[]   = $custom_match;
				$total_score += $custom_match['weight'];
			}
		}

		return array(
			'score'    => $total_score,
			'severity' => $this->calculate_severity( $total_score ),
			'matches'  => $matches,
		);
	}

	/**
	 * Scan a ZIP archive by extracting relevant members to a temp dir with
	 * strict count/size limits and path-traversal protection, then scanning
	 * each extracted file.
	 *
	 * @return array{score:int,severity:string,matches:array}
	 */
	private function scan_zip( string $file_path ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'score' => 0, 'severity' => 'clean', 'matches' => array() );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return array( 'score' => 0, 'severity' => 'clean', 'matches' => array() );
		}

		$max_files      = 50;
		$max_total_size = 50 * 1024 * 1024;
		$total_size     = 0;
		$file_count     = 0;

		$temp_dir = get_temp_dir() . 'uxstudio-security-zip-' . wp_generate_password( 12, false, false );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			$zip->close();
			return array( 'score' => 0, 'severity' => 'clean', 'matches' => array() );
		}

		$scan_extensions = array( 'php', 'phtml', 'phar', 'js', 'svg', 'html', 'htm' );
		$files_to_scan   = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}
			if ( '/' === substr( $stat['name'], -1 ) ) {
				continue;
			}

			++$file_count;
			if ( $file_count > $max_files ) {
				break;
			}
			$total_size += $stat['size'];
			if ( $total_size > $max_total_size ) {
				break;
			}

			$ext = strtolower( pathinfo( $stat['name'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $scan_extensions, true ) ) {
				continue;
			}

			// Path traversal protection: strip '..' and normalize separators.
			$safe_name = str_replace( array( '..', '\\' ), array( '', '/' ), $stat['name'] );
			$dest_path = $temp_dir . '/' . $safe_name;

			$parent_dir = dirname( $dest_path );
			if ( ! is_dir( $parent_dir ) ) {
				wp_mkdir_p( $parent_dir );
			}

			$content = $zip->getFromIndex( $i );
			if ( false !== $content ) {
				file_put_contents( $dest_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				$files_to_scan[] = array(
					'path' => $dest_path,
					'ext'  => $ext,
					'name' => $stat['name'],
				);
			}
		}

		$zip->close();

		$all_matches = array();
		$max_score   = 0;

		foreach ( $files_to_scan as $file ) {
			$result = $this->scan( $file['path'], $file['ext'] );
			if ( $result['score'] > 0 ) {
				foreach ( $result['matches'] as $match ) {
					$match['label'] .= ' (in ZIP: ' . $file['name'] . ')';
					$all_matches[]    = $match;
				}
				$max_score = max( $max_score, $result['score'] );
			}
		}

		$this->remove_directory( $temp_dir );

		return array(
			'score'    => $max_score,
			'severity' => $this->calculate_severity( $max_score ),
			'matches'  => $all_matches,
		);
	}

	/**
	 * @return array<int,array{name?:string,regex:string,weight?:int,label?:string}>
	 */
	private function patterns_for_extension( string $extension ): array {
		switch ( $extension ) {
			case 'php':
			case 'phtml':
			case 'phar':
				return array_merge( self::PHP_PATTERNS, self::OBFUSCATION_PATTERNS );
			case 'js':
				return array_merge( self::JS_PATTERNS, self::OBFUSCATION_PATTERNS );
			case 'svg':
				return self::SVG_PATTERNS;
			case 'html':
			case 'htm':
				return self::HTML_PATTERNS;
			default:
				return array();
		}
	}

	/**
	 * @param string $content  File content.
	 * @param array  $patterns Patterns to match against.
	 * @return array<int,array{name:string,weight:int,label:string,line:int,snippet:string,count:int}>
	 */
	private function match_patterns( string $content, array $patterns ): array {
		$matches = array();
		$lines   = explode( "\n", $content );

		foreach ( $patterns as $pattern ) {
			if ( empty( $pattern['regex'] ) ) {
				continue;
			}

			$found = @preg_match_all( $pattern['regex'], $content, $preg_matches, PREG_OFFSET_CAPTURE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $found && $found > 0 ) {
				$offset      = $preg_matches[0][0][1] ?? 0;
				$line_number = substr_count( substr( $content, 0, $offset ), "\n" ) + 1;

				$line_index = $line_number - 1;
				$snippet    = isset( $lines[ $line_index ] ) ? trim( $lines[ $line_index ] ) : '';
				if ( strlen( $snippet ) > 120 ) {
					$snippet = substr( $snippet, 0, 120 ) . '...';
				}

				$matches[] = array(
					'name'    => $pattern['name'] ?? 'custom',
					'weight'  => (int) ( $pattern['weight'] ?? 10 ),
					'label'   => $pattern['label'] ?? ( $pattern['name'] ?? 'Custom pattern' ),
					'line'    => $line_number,
					'snippet' => $snippet,
					'count'   => $found,
				);
			}
		}

		return $matches;
	}

	/**
	 * Detect a high concentration of non-ASCII bytes (obfuscation indicator)
	 * in PHP-family files.
	 */
	private function check_non_ascii_concentration( string $content ): ?array {
		$total_chars = strlen( $content );
		if ( $total_chars < 100 ) {
			return null;
		}

		$non_ascii = preg_match_all( '/[^\x20-\x7E\s]/', $content );
		if ( false === $non_ascii ) {
			return null;
		}

		$ratio = $non_ascii / $total_chars;
		if ( $ratio > 0.3 ) {
			return array(
				'name'    => 'non_ascii_concentration',
				'weight'  => 20,
				'label'   => sprintf( 'High non-ASCII character concentration (%.0f%%)', $ratio * 100 ),
				'line'    => 1,
				'snippet' => '(binary/obfuscated content)',
				'count'   => $non_ascii,
			);
		}

		return null;
	}

	/**
	 * Maps the raw pattern-weight score to the storage severity enum
	 * (low|medium|high|critical). 'clean' rows are never persisted -
	 * callers delete/skip them instead.
	 */
	private function calculate_severity( int $score ): string {
		if ( $score <= 0 ) {
			return 'clean';
		}
		if ( $score < self::THRESHOLD_LOW ) {
			return 'low';
		}
		if ( $score < self::THRESHOLD_MEDIUM ) {
			return 'medium';
		}
		if ( $score < self::THRESHOLD_HIGH ) {
			return 'high';
		}
		return 'critical';
	}

	private function is_whitelisted( string $file_path ): bool {
		$normalized = wp_normalize_path( $file_path );

		foreach ( $this->whitelist_paths as $whitelisted ) {
			$whitelisted = wp_normalize_path( trim( $whitelisted ) );
			if ( '' === $whitelisted ) {
				continue;
			}
			if ( false !== strpos( $normalized, $whitelisted ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively remove a directory (used to clean up ZIP extraction temp dirs).
	 */
	private function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getRealPath() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $item->getRealPath() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
