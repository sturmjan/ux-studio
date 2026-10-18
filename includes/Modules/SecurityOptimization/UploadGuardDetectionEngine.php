<?php
/**
 * Upload Guard detection engine: scores files for signs of injected code.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * PHP is analysed on tokens, not with regexes over raw text: a word in a
 * comment (Predis' "@method eval(...)") or inside a string (the old signature
 * list of this very engine) is not a call. Single risky calls score low -
 * real libraries use exec() and base64_decode() all the time. What scores
 * high is the shape of malware: a decoder or request data fed straight into
 * eval/shell/include, or an encoded blob next to an executor.
 *
 * SVG/HTML/JS keep simple patterns - those are only scanned in uploads,
 * where markup with scripts has no business being anyway.
 */
final class UploadGuardDetectionEngine {

	private const THRESHOLD_LOW    = 16;
	private const THRESHOLD_MEDIUM = 31;
	private const THRESHOLD_HIGH   = 51;

	/** Run a string as PHP code. assert() only counts in combinations (it is a no-op on PHP 8). */
	private const EXECUTORS = array( 'eval', 'assert', 'create_function' );

	private const SHELL = array( 'system', 'shell_exec', 'passthru', 'exec', 'proc_open', 'popen', 'pcntl_exec' );

	private const DECODERS = array( 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'convert_uudecode', 'hex2bin' );

	private const INPUTS = array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES' );

	/** An encoded string literal at least this long counts as a blob. */
	private const BLOB_MIN_LENGTH = 1000;

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

	/**
	 * @param array $custom_patterns Array of ['regex'=>string,'weight'=>int,'label'=>string].
	 */
	public function __construct( array $custom_patterns = array() ) {
		$this->custom_patterns = $custom_patterns;
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
			$analysis = $this->analyse_php( $content );
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
	 * Token-level analysis of PHP source.
	 *
	 * @return array{matches:array,has_code:bool}
	 */
	private function analyse_php( string $content ): array {
		$tokens = token_get_all( $content );
		$lines  = explode( "\n", $content );

		$ignored = array( T_OPEN_TAG, T_CLOSE_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML );
		$no_call = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$no_call[] = T_NULLSAFE_OBJECT_OPERATOR;
		}
		$name_ids = array( T_STRING );
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
			$name_ids[] = T_NAME_FULLY_QUALIFIED;
		}

		$has_code   = false;
		$line       = 1;
		$prev       = null; // Previous significant token.
		$statement  = $this->empty_statement();
		$statements = array();
		$file       = array(
			'executor'   => 0, // Line of the first eval()/create_function() call, 0 = none.
			'blob'       => 0,
			'code_chars' => 0,
			'odd_chars'  => 0,
		);

		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			$id    = is_array( $token ) ? $token[0] : null;
			$text  = is_array( $token ) ? $token[1] : $token;
			if ( is_array( $token ) ) {
				$line = $token[2];
			}

			if ( null !== $id && in_array( $id, $ignored, true ) ) {
				continue;
			}
			// An opening tag, exit(0); and a closing tag in front of data is how
			// Sucuri/Wordfence guard their data files: nothing runs, not code.
			if ( ! in_array( $id ?? $text, array( T_EXIT, T_LNUMBER, '(', ')', ';' ), true ) ) {
				$has_code = true;
			}

			if ( ';' === $text || '{' === $text || '}' === $text ) {
				$statements[] = $statement;
				$statement    = $this->empty_statement();
				$prev         = $token;
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id ) {
				if ( $this->is_encoded_blob( $text ) ) {
					$file['blob']      = $file['blob'] ?: $line;
					$statement['blob'] = true;
				}
				$prev = $token;
				continue;
			}

			if ( T_VARIABLE === $id ) {
				if ( in_array( $text, self::INPUTS, true ) ) {
					$statement['input'] = true;
				}
				// $f( $_POST[...] ): calling a function whose name sits in a variable.
				if ( '(' === $this->next_significant( $tokens, $i ) && ! $this->is_one_of( $prev, $no_call ) ) {
					$statement['dynamic'] = $statement['dynamic'] ?: $line;
				}
				$this->count_chars( $file, $text );
				$prev = $token;
				continue;
			}

			if ( in_array( $id, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$statement['include'] = $statement['include'] ?: $line;
				$prev                 = $token;
				continue;
			}

			$name = '';
			if ( T_EVAL === $id ) {
				// `function eval(...)` declares a method (Predis' Client::eval), not a call.
				$name = $this->is_one_of( $prev, $no_call ) ? '' : 'eval';
			} elseif ( in_array( $id, $name_ids, true ) ) {
				$this->count_chars( $file, $text );
				if ( '(' === $this->next_significant( $tokens, $i ) && ! $this->is_one_of( $prev, $no_call ) ) {
					$name = strtolower( ltrim( $text, '\\' ) );
				}
			}

			if ( in_array( $name, self::EXECUTORS, true ) ) {
				$statement['executor'] = $statement['executor'] ?: array( $name, $line );
				if ( 'assert' !== $name ) {
					$file['executor'] = $file['executor'] ?: $line;
				}
			} elseif ( in_array( $name, self::SHELL, true ) ) {
				$statement['shell'] = $statement['shell'] ?: array( $name, $line );
			} elseif ( in_array( $name, self::DECODERS, true ) ) {
				$statement['decoder'] = $statement['decoder'] ?: array( $name, $line );
			}

			$prev = $token;
		}
		$statements[] = $statement;

		return array(
			'matches'  => $this->score_php( $statements, $file, $lines ),
			'has_code' => $has_code,
		);
	}

	/**
	 * Turn collected features into weighted matches. Combinations are what
	 * reach high/critical; lone calls stay low so libraries aren't reported.
	 *
	 * @param array<int,array> $statements Per-statement features.
	 * @param array            $file       File-level features.
	 * @param string[]         $lines      Source lines for snippets.
	 * @return array<int,array>
	 */
	private function score_php( array $statements, array $file, array $lines ): array {
		$found = array();
		$add   = static function ( string $name, int $weight, string $label, int $line ) use ( &$found ): void {
			if ( isset( $found[ $name ] ) ) {
				++$found[ $name ]['count'];
				return;
			}
			$found[ $name ] = array(
				'name'   => $name,
				'weight' => $weight,
				'label'  => $label,
				'line'   => $line,
				'count'  => 1,
			);
		};

		foreach ( $statements as $s ) {
			$runner = $s['executor'] ?: $s['shell'];
			if ( $runner && $s['decoder'] ) {
				/* translators: 1: function like eval, 2: decoder like base64_decode */
				$add( 'run_decoded', 60, sprintf( __( 'Decoded data is executed: %1$s(%2$s(...))', 'ux-studio' ), $runner[0], $s['decoder'][0] ), $runner[1] );
			}
			if ( $runner && $s['input'] ) {
				/* translators: %s: function like eval or system */
				$add( 'run_input', 60, sprintf( __( 'Request data ($_GET/$_POST/...) is passed to %s()', 'ux-studio' ), $runner[0] ), $runner[1] );
			}
			if ( $s['include'] && $s['input'] ) {
				$add( 'include_input', 60, __( 'A file is included from a path taken from the request', 'ux-studio' ), $s['include'] );
			}
			if ( $s['executor'] && $s['blob'] ) {
				$add( 'run_blob', 60, __( 'An encoded blob is executed in the same statement', 'ux-studio' ), $s['executor'][1] );
			}
			if ( $s['dynamic'] && $s['input'] && ! $runner ) {
				$add( 'dynamic_input', 25, __( 'A function whose name is in a variable is called with request data', 'ux-studio' ), $s['dynamic'] );
			}
			if ( $s['executor'] && 'assert' !== $s['executor'][0] ) {
				/* translators: %s: eval or create_function */
				$add( 'executor', 10, sprintf( __( '%s() call', 'ux-studio' ), $s['executor'][0] ), $s['executor'][1] );
			}
			if ( $s['shell'] ) {
				/* translators: %s: shell function like exec */
				$add( 'shell', 8, sprintf( __( 'Shell command call: %s()', 'ux-studio' ), $s['shell'][0] ), $s['shell'][1] );
			}
		}

		if ( $file['blob'] ) {
			if ( $file['executor'] ) {
				$add( 'blob_with_executor', 40, __( 'Long encoded string in a file that also runs eval()', 'ux-studio' ), $file['blob'] );
			} else {
				$add( 'blob', 5, __( 'Long encoded string', 'ux-studio' ), $file['blob'] );
			}
		}

		if ( $file['code_chars'] >= 200 && $file['odd_chars'] / $file['code_chars'] > 0.3 ) {
			$add( 'obfuscated_names', $file['executor'] ? 40 : 15, __( 'Function and variable names are mostly non-ASCII (obfuscation)', 'ux-studio' ), 1 );
		}

		$matches = array_values( $found );
		foreach ( $matches as &$match ) {
			$snippet          = trim( $lines[ $match['line'] - 1 ] ?? '' );
			$match['snippet'] = strlen( $snippet ) > 120 ? substr( $snippet, 0, 120 ) . '...' : $snippet;
		}
		unset( $match );

		usort( $matches, static fn( array $a, array $b ): int => $b['weight'] <=> $a['weight'] );
		return $matches;
	}

	/**
	 * @return array{executor:array|false,shell:array|false,decoder:array|false,include:int,dynamic:int,input:bool,blob:bool}
	 */
	private function empty_statement(): array {
		return array(
			'executor' => false,
			'shell'    => false,
			'decoder'  => false,
			'include'  => 0,
			'dynamic'  => 0,
			'input'    => false,
			'blob'     => false,
		);
	}

	/**
	 * Count identifier characters and how many of them are non-ASCII.
	 *
	 * @param array  $file File-level features (by reference).
	 * @param string $text Identifier.
	 */
	private function count_chars( array &$file, string $text ): void {
		$file['code_chars'] += strlen( $text );
		$file['odd_chars']  += (int) preg_match_all( '/[^\x20-\x7E]/', $text );
	}

	/**
	 * A long string literal made of base64 or \x escapes, not prose or markup.
	 */
	private function is_encoded_blob( string $literal ): bool {
		if ( strlen( $literal ) < self::BLOB_MIN_LENGTH ) {
			return false;
		}
		$body = trim( $literal, "'\"" );
		if ( preg_match( '/^[A-Za-z0-9+\/=\r\n]+$/', $body ) ) {
			return true;
		}
		return (int) preg_match_all( '/\\\\x[0-9a-fA-F]{2}/', $body ) * 4 > strlen( $body ) / 2;
	}

	/**
	 * Text of the next token that is not whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Current index.
	 */
	private function next_significant( array $tokens, int $i ): string {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$token = $tokens[ $j ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return is_array( $token ) ? $token[1] : $token;
		}
		return '';
	}

	/**
	 * @param array|string|null $token Token.
	 * @param int[]             $ids   Token ids.
	 */
	private function is_one_of( $token, array $ids ): bool {
		return is_array( $token ) && in_array( $token[0], $ids, true );
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
