<?php
/**
 * Upload Guard detection engine: scores files for signs of injected code.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Scores a file. PHP goes to UploadGuardPhpAnalyzer (token-level, see there
 * why single words never decide). SVG/HTML/JS keep simple patterns - those
 * are only scanned in uploads, where markup with scripts has no business
 * being anyway.
 */
final class UploadGuardDetectionEngine {

	private const THRESHOLD_LOW    = 16;
	private const THRESHOLD_MEDIUM = 31;
	private const THRESHOLD_HIGH   = 51;

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
		array( 'name' => 'long_base64', 'regex' => '/[A-Za-z0-9+\/=]{500,}/', 'weight' => 15, 'label' => 'Long base64-encoded string (>500 chars)' ),
	);

	/** @var array<int,array{name?:string,regex:string,weight?:int,label?:string}> */
	private array $custom_patterns;

	/** Score added by each matching admin-defined pattern (as in legacy). */
	public const CUSTOM_PATTERN_WEIGHT = 25;

	/** Limits for the admin-defined pattern list. */
	private const CUSTOM_PATTERNS_MAX = 50;
	private const CUSTOM_PATTERN_MAX_LENGTH = 500;

	/** Prefix that disables a line that does not compile. */
	private const INVALID_PREFIX = '# invalid: ';

	/**
	 * @param array $custom_patterns Array of ['regex'=>string,'weight'=>int,'label'=>string].
	 */
	public function __construct( array $custom_patterns = array() ) {
		$this->custom_patterns = $custom_patterns;
	}

	/**
	 * Engine with the admin-defined patterns from the module settings.
	 */
	public static function from_settings(): self {
		return new self( self::parse_custom_patterns( self::custom_patterns_setting() ) );
	}

	/**
	 * Raw `upload_guard_custom_patterns` value; until it is first saved in
	 * Studio, the migrated legacy `lwug_custom_patterns`.
	 */
	public static function custom_patterns_setting(): string {
		$settings = (array) get_option( 'uxstudio_security_optimization', array() );
		$raw      = array_key_exists( 'upload_guard_custom_patterns', $settings ) ? $settings['upload_guard_custom_patterns'] : ( $settings['lwug_custom_patterns'] ?? '' );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Does this line compile as a PCRE pattern? A broken pattern must never
	 * reach the scanner, so this is checked on save and again on every load.
	 */
	public static function is_valid_custom_pattern( string $regex ): bool {
		if ( '' === $regex || strlen( $regex ) > self::CUSTOM_PATTERN_MAX_LENGTH ) {
			return false;
		}
		return false !== @preg_match( $regex, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Save-time cleanup of the textarea: valid UTF-8, no control characters,
	 * at most CUSTOM_PATTERNS_MAX active lines. A line that does not compile is
	 * kept but commented out, so the admin sees what was rejected.
	 */
	public static function clean_custom_patterns( string $raw ): string {
		$raw   = wp_check_invalid_utf8( str_replace( "\r", '', $raw ) );
		$raw   = (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $raw );
		$out   = array();
		$count = 0;
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$line = substr( $line, 0, self::CUSTOM_PATTERN_MAX_LENGTH + strlen( self::INVALID_PREFIX ) );
			if ( '#' !== $line[0] ) {
				if ( ! self::is_valid_custom_pattern( $line ) || $count >= self::CUSTOM_PATTERNS_MAX ) {
					$line = self::INVALID_PREFIX . $line;
				} else {
					++$count;
				}
			}
			$out[] = $line;
		}
		return implode( "\n", $out );
	}

	/**
	 * Turn the textarea into pattern definitions; comments, blank and
	 * non-compiling lines are skipped.
	 *
	 * @return array<int,array{name:string,regex:string,weight:int,label:string}>
	 */
	public static function parse_custom_patterns( string $raw ): array {
		$patterns = array();
		foreach ( explode( "\n", str_replace( "\r", '', $raw ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || ! self::is_valid_custom_pattern( $line ) ) {
				continue;
			}
			$patterns[] = array(
				'name'   => 'custom_' . md5( $line ),
				'regex'  => $line,
				'weight' => self::CUSTOM_PATTERN_WEIGHT,
				// Untranslated like the built-in labels: parsed when the scanner is
				// constructed, which may be before the text domain is loaded.
				'label'  => 'Custom pattern: ' . $line,
			);
			if ( count( $patterns ) >= self::CUSTOM_PATTERNS_MAX ) {
				break;
			}
		}
		return $patterns;
	}

	/**
	 * Scan a file and return a score/severity/matches result. `has_code` is
	 * false for PHP files that run nothing (the "Silence is golden" index.php
	 * plugins drop into uploads, data files guarded by a bare exit;).
	 *
	 * @return array{score:int,severity:string,matches:array,has_code:bool}
	 */
	public function scan( string $file_path, string $extension ): array {
		$clean = array(
			'score'    => 0,
			'severity' => 'clean',
			'matches'  => array(),
			'has_code' => false,
		);

		$extension = strtolower( ltrim( $extension, '.' ) );

		if ( 'zip' === $extension ) {
			return $this->scan_zip( $file_path );
		}

		if ( ! is_readable( $file_path ) ) {
			return $clean;
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content || '' === $content ) {
			return $clean;
		}

		$has_code = true;
		if ( in_array( $extension, UploadGuardScope::PHP_EXTENSIONS, true ) ) {
			$analysis = ( new UploadGuardPhpAnalyzer() )->analyse( $content );
			$matches  = $analysis['matches'];
			$has_code = $analysis['has_code'];
		} else {
			$matches = $this->match_patterns( $content, $this->patterns_for_extension( $extension ) );
		}

		if ( ! empty( $this->custom_patterns ) ) {
			$matches = array_merge( $matches, $this->match_patterns( $content, $this->custom_patterns ) );
		}

		$total_score = 0;
		foreach ( $matches as $match ) {
			$total_score += $match['weight'];
		}

		return array(
			'score'    => $total_score,
			'severity' => $this->calculate_severity( $total_score ),
			'matches'  => $matches,
			'has_code' => $has_code,
		);
	}

	/**
	 * Map a raw score to the stored severity (clean|low|medium|high|critical).
	 */
	public function calculate_severity( int $score ): string {
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

	/**
	 * Scan a ZIP archive by extracting relevant members to a temp dir with
	 * strict count/size limits and path-traversal protection, then scanning
	 * each extracted file.
	 *
	 * @return array{score:int,severity:string,matches:array,has_code:bool}
	 */
	private function scan_zip( string $file_path ): array {
		$clean = array(
			'score'    => 0,
			'severity' => 'clean',
			'matches'  => array(),
			'has_code' => false,
		);

		if ( ! class_exists( 'ZipArchive' ) ) {
			return $clean;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return $clean;
		}

		$max_files      = 50;
		$max_total_size = 50 * 1024 * 1024;
		$total_size     = 0;
		$file_count     = 0;

		$temp_dir = get_temp_dir() . 'uxstudio-security-zip-' . wp_generate_password( 12, false, false );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			$zip->close();
			return $clean;
		}

		$scan_extensions = array_merge( UploadGuardScope::PHP_EXTENSIONS, UploadGuardScope::UPLOAD_EXTENSIONS );
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
			'has_code' => true,
		);
	}

	/**
	 * @return array<int,array{name?:string,regex:string,weight?:int,label?:string}>
	 */
	private function patterns_for_extension( string $extension ): array {
		switch ( $extension ) {
			case 'js':
				return self::JS_PATTERNS;
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
