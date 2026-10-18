<?php
/**
 * Upload Guard PHP analyzer: token-level heuristics for injected PHP.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Works on PHP tokens, so a word in a comment or inside a string is never a
 * call. Lone risky calls (exec, base64_decode, eval) score low - libraries
 * use them all the time. High scores need the shape of a backdoor:
 *
 *  - request data reaching code execution: directly, through a variable it
 *    was assigned to (`$x = $_GET; $x['f']($x['c'])`), through extract(), or
 *    as the callback of call_user_func();
 *  - decoded or encoded data reaching execution (`eval(base64_decode(..))`,
 *    hex/base64 literals that decode to `eval(`);
 *  - hiding tricks: include of an image/.ico, code pushed off-screen by
 *    hundreds of spaces, reversed function names, chr() chains, preg /e,
 *    a dangerous function passed as a string callback, md5($_POST) password.
 *
 * Detection ideas partly follow php-malware-finder (LGPLv3) rules, re-done on
 * tokens; its sample set is the regression corpus.
 */
final class UploadGuardPhpAnalyzer {

	/** Run a string as PHP code. assert() only counts in combinations (no-op on PHP 8). */
	private const EXECUTORS = array( 'eval', 'assert', 'create_function' );

	private const SHELL = array( 'system', 'shell_exec', 'passthru', 'exec', 'proc_open', 'popen', 'pcntl_exec' );

	private const DECODERS = array( 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'convert_uudecode', 'hex2bin', 'strrev' );

	private const INPUTS = array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES' );

	/** Functions whose FIRST argument is a callback. */
	private const CALLBACK_FIRST = array( 'call_user_func', 'call_user_func_array', 'register_shutdown_function', 'register_tick_function', 'array_map', 'ob_start', 'set_error_handler', 'set_exception_handler', 'forward_static_call', 'forward_static_call_array', 'iterator_apply', 'spl_autoload_register' );

	/** Functions that take a callback anywhere in their arguments. */
	private const CALLBACK_ANY = array( 'array_filter', 'array_walk', 'array_walk_recursive', 'array_reduce', 'usort', 'uasort', 'uksort', 'preg_replace_callback' );

	/** Make request data safe for a shell command / a number. */
	private const SANITIZERS = array( 'escapeshellarg', 'escapeshellcmd', 'intval', 'absint', 'floatval', 'basename', 'sanitize_file_name', 'sanitize_key', 'sanitize_text_field' );

	/**
	 * Calls that pass request data through unchanged (or merely decoded): a
	 * variable assigned from them keeps carrying request data. Any other call
	 * on the right-hand side (plugin_basename, sanitize_*, intval...) clears it.
	 */
	private const PASS_THROUGH = array( 'wp_unslash', 'stripslashes', 'trim', 'urldecode', 'rawurldecode', 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'strrev', 'hex2bin', 'explode', 'substr', 'json_decode', 'unserialize', 'file_get_contents', 'getallheaders', 'implode', 'array_values' );

	/** Dangerous names written backwards: strrev obfuscation. */
	private const REVERSED = array( 'lave', 'metsys', 'cexe_llehs', 'urhtssap', 'edoced_46esab', 'etalfnizg', 'tressa', 'noitcnuf_etaerc', 'slabolg', 'ecalper_gerp' );

	/** An encoded string literal at least this long counts as a blob. */
	private const BLOB_MIN_LENGTH = 1000;

	/** Leading whitespace on a line longer than this pushes code off-screen. */
	private const OFFSCREEN_INDENT = 150;

	/** @var array<int,array{0:int,1:string,2:int}> Significant tokens: [id, text, line]; id 0 = single char. */
	private array $tokens = array();

	/** @var array<string,bool> Variables holding request data. */
	private array $tainted = array();

	/** @var array<string,array> Collected matches by name. */
	private array $found = array();

	/** @var string[] */
	private array $lines = array();

	/**
	 * @return array{matches:array,has_code:bool}
	 */
	public function analyse( string $content ): array {
		$this->found   = array();
		$this->tainted = array();
		$this->lines   = explode( "\n", $content );

		// `<?` short tags run on servers with short_open_tag=On, but with it Off
		// (here) the tokenizer would see plain HTML - read them as `<?php`.
		if ( ! ini_get( 'short_open_tag' ) ) {
			$content = (string) preg_replace( '/<\?(?!php\b|=|xml)/i', '<?php ', $content );
		}

		$has_code = $this->tokenize( $content );
		if ( ! $has_code ) {
			return array(
				'matches'  => array(),
				'has_code' => false,
			);
		}

		$this->scan_statements();
		$this->scan_layout( $content );

		$matches = array_values( $this->found );
		foreach ( $matches as &$match ) {
			$snippet          = trim( $this->lines[ $match['line'] - 1 ] ?? '' );
			$match['snippet'] = strlen( $snippet ) > 120 ? substr( $snippet, 0, 120 ) . '...' : $snippet;
		}
		unset( $match );
		usort( $matches, static fn( array $a, array $b ): int => $b['weight'] <=> $a['weight'] );

		return array(
			'matches'  => $matches,
			'has_code' => true,
		);
	}

	/**
	 * Keep significant tokens only; report whether anything executes at all.
	 * `exit(0);` alone (Sucuri/Wordfence data files) does not count as code.
	 */
	private function tokenize( string $content ): bool {
		$ignored  = array( T_OPEN_TAG, T_CLOSE_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML );
		$inert    = array( T_EXIT, T_LNUMBER, '(', ')', ';' );
		$has_code = false;
		$line     = 1;

		$this->tokens = array();
		foreach ( token_get_all( $content ) as $token ) {
			if ( is_array( $token ) ) {
				$line = $token[2];
				if ( in_array( $token[0], $ignored, true ) ) {
					continue;
				}
				$this->tokens[] = array( $token[0], $token[1], $line );
				$has_code       = $has_code || ! in_array( $token[0], $inert, true );
				continue;
			}
			$this->tokens[] = array( 0, $token, $line );
			$has_code       = $has_code || ! in_array( $token, $inert, true );
		}
		return $has_code;
	}

	/**
	 * Walk statements (split on ; { }) collecting features, then score each.
	 */
	private function scan_statements(): void {
		$count      = count( $this->tokens );
		$statements = array();
		$start      = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$text = $this->tokens[ $i ][1];
			if ( 0 === $this->tokens[ $i ][0] && ( ';' === $text || '{' === $text || '}' === $text ) ) {
				if ( $i > $start ) {
					$statements[] = array( $start, $i );
				}
				$start = $i + 1;
			}
		}
		if ( $start < $count ) {
			$statements[] = array( $start, $count );
		}

		$file = array(
			'executor'      => 0,
			'blob'          => 0,
			'extract_input' => 0,
			'dynamic'       => 0,
			'chr_line'      => 0,
			'code_chars'    => 0,
			'odd_chars'     => 0,
		);

		foreach ( $statements as $range ) {
			$this->score_statement( $this->statement( $range[0], $range[1], $file ) );
		}

		$this->score_file( $file );
	}

	/**
	 * Collect the features of one statement.
	 *
	 * @param int   $from First token index.
	 * @param int   $to   Index after the last token.
	 * @param array $file File-level features.
	 */
	private function statement( int $from, int $to, array &$file ): array {
		// Request data tracked through variables stays inside one function.
		for ( $k = $from; $k < $to; $k++ ) {
			if ( T_FUNCTION === $this->tokens[ $k ][0] || T_FN === $this->tokens[ $k ][0] ) {
				$this->tainted = array();
				break;
			}
		}

		$s = array(
			'line'      => $this->tokens[ $from ][2],
			'executor'  => null,
			'shell'     => null,
			'decoder'   => null,
			'include'   => 0,
			'input'      => false,
			'direct'     => false, // A superglobal itself, not data passed through a variable.
			'other_call' => false, // A call outside PASS_THROUGH (clears taint on assignment).
			'chr'        => 0,
			'concat'     => 0,
			'dynamic'   => 0,
			'sanitized' => false,
			'blob'      => false,
			'cb_input'  => null,
			'cb_string' => null,
			'hash_cmp'  => false,
			'hex_hash'  => false,
		);

		$callback_ctx = 0; // Line of a callback-taking call seen in this statement.
		for ( $i = $from; $i < $to; $i++ ) {
			list( $id, $text, $line ) = $this->tokens[ $i ];
			$prev = $i > $from ? $this->tokens[ $i - 1 ] : null;
			$next = $i + 1 < $to ? $this->tokens[ $i + 1 ] : null;

			if ( T_VARIABLE === $id ) {
				// $known[ $_GET['k'] ] only uses request data as a key into
				// the site's own array - the value is not request data.
				if ( $this->is_input_var( $text ) && ! $this->in_foreign_subscript( $i, $from ) ) {
					$s['input']  = true;
					$s['direct'] = $s['direct'] || in_array( $text, self::INPUTS, true );
				}
				$this->count_chars( $file, $text );
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id ) {
				$this->inspect_literal( $text, $line, $s, $file, $prev, $callback_ctx, $i, $from );
				continue;
			}

			if ( in_array( $id, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$s['include'] = $s['include'] ?: $line;
				if ( $next && T_CONSTANT_ENCAPSED_STRING === $next[0] ) {
					$this->inspect_include_path( $next[1], $line );
				}
				continue;
			}

			if ( 0 === $id && '.' === $text ) {
				++$s['concat'];
				continue;
			}

			if ( 0 === $id && '(' === $text && $prev && $this->is_dynamic_callee( $i, $from ) ) {
				$s['dynamic']    = $s['dynamic'] ?: $line;
				$file['dynamic'] = $file['dynamic'] ?: $line;
				continue;
			}

			$name = $this->call_name( $i, $from, $to );
			if ( '' === $name ) {
				if ( in_array( $id, array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$this->count_chars( $file, $text );
				}
				continue;
			}
			$this->count_chars( $file, $text );
			if ( ! in_array( $name, self::PASS_THROUGH, true ) ) {
				$s['other_call'] = true;
			}

			$first_arg = $this->tokens[ $i + 2 ] ?? null;
			if ( in_array( $name, self::EXECUTORS, true ) ) {
				$s['executor'] = $s['executor'] ?? array( $name, $line );
				if ( 'assert' !== $name ) {
					$file['executor'] = $file['executor'] ?: $line;
				}
			} elseif ( in_array( $name, self::SHELL, true ) ) {
				$s['shell'] = $s['shell'] ?? array( $name, $line );
			} elseif ( in_array( $name, self::DECODERS, true ) ) {
				$s['decoder'] = $s['decoder'] ?? array( $name, $line );
			} elseif ( in_array( $name, self::SANITIZERS, true ) ) {
				$s['sanitized'] = true;
			} elseif ( 'getallheaders' === $name ) {
				$s['input']  = true;
				$s['direct'] = true;
			} elseif ( 'extract' === $name && $first_arg && in_array( $first_arg[1], self::INPUTS, true ) ) {
				$file['extract_input'] = $file['extract_input'] ?: $line;
			} elseif ( 'chr' === $name ) {
				++$s['chr'];
			} elseif ( in_array( $name, array( 'md5', 'sha1' ), true ) ) {
				$s['hash_cmp'] = true;
			}

			if ( in_array( $name, self::CALLBACK_FIRST, true ) || in_array( $name, self::CALLBACK_ANY, true ) ) {
				$callback_ctx = $line;
				// call_user_func( $_POST['f'], ... ): the callback itself comes from the request.
				if ( in_array( $name, self::CALLBACK_FIRST, true ) && $first_arg && T_VARIABLE === $first_arg[0]
					&& ( in_array( $first_arg[1], self::INPUTS, true ) || isset( $this->tainted[ $first_arg[1] ] ) ) ) {
					$s['cb_input'] = in_array( $first_arg[1], self::INPUTS, true ) ? array( 'direct', $line ) : array( 'tainted', $line );
				}
			}
		}

		// `$v = <request data, passed through or decoded>` taints $v; assigning
		// anything else (or request data run through a real function) clears it.
		$first = $this->tokens[ $from ] ?? null;
		$eq    = $this->tokens[ $from + 1 ] ?? null;
		if ( $first && T_VARIABLE === $first[0] && $eq && '=' === $eq[1] && '$this' !== $first[1] ) {
			if ( $s['input'] && ! $s['other_call'] && ! $s['sanitized'] ) {
				$this->tainted[ $first[1] ] = true;
			} else {
				unset( $this->tainted[ $first[1] ] );
			}
		}

		// chr(101).chr(118).chr(97)...: a list of chr() in an array is not this.
		if ( $s['chr'] >= 8 && $s['concat'] >= $s['chr'] - 1 ) {
			$file['chr_line'] = $file['chr_line'] ?: $s['line'];
		}

		return $s;
	}

	/**
	 * Strings: blobs, encoded code, reversed names, dangerous callbacks, hashes.
	 *
	 * @param array      $s            Statement features.
	 * @param array      $file         File features.
	 * @param array|null $prev         Previous token.
	 * @param int        $callback_ctx Line of a callback-taking call in this statement, 0 = none.
	 */
	private function inspect_literal( string $literal, int $line, array &$s, array &$file, ?array $prev, int $callback_ctx, int $i, int $from ): void {
		$quote = $literal[0] ?? '';
		$body  = ( "'" === $quote || '"' === $quote ) ? substr( $literal, 1, -1 ) : $literal;
		$lower = strtolower( $body );

		if ( 'php://input' === $lower ) {
			$s['input']  = true;
			$s['direct'] = true;
		}
		if ( preg_match( '/^[0-9a-f]{32}$|^[0-9a-f]{40}$/i', $body ) ) {
			$s['hex_hash'] = true;
		}
		if ( $callback_ctx && in_array( $lower, array_merge( self::EXECUTORS, self::SHELL ), true ) ) {
			$s['cb_string'] = $s['cb_string'] ?? array( $lower, $line );
		}

		if ( strlen( $literal ) >= self::BLOB_MIN_LENGTH && $this->is_encoded_blob( $body ) ) {
			$s['blob']    = true;
			$file['blob'] = $file['blob'] ?: $line;
		}

		if ( in_array( $lower, self::REVERSED, true ) ) {
			/* translators: %s: reversed text like metsys */
			$this->add( 'reversed_name', 40, sprintf( __( 'Function name written backwards: "%s"', 'ux-studio' ), $body ), $line );
		}

		// preg_replace( '/.../e', ... ) runs the replacement as PHP (PHP < 7).
		if ( $prev && '(' === $prev[1] && $i - 2 >= $from && in_array( strtolower( $this->tokens[ $i - 2 ][1] ), array( 'preg_replace', 'preg_filter', 'mb_ereg_replace' ), true )
			&& preg_match( '/^(.).*\1[imsxuADSUXJ]*e[imsxuADSUXJ]*$/s', $body ) ) {
			$this->add( 'preg_e', 45, __( 'preg_replace() with the /e modifier executes code', 'ux-studio' ), $line );
		}

		$decoded = $this->decode_literal( $body, '"' === $quote );
		if ( '' !== $decoded && preg_match( '/\b(eval|assert|system|shell_exec|passthru|base64_decode|gzinflate|create_function)\s*\(|<\?php/i', $decoded ) ) {
			$this->add( 'encoded_code', 45, __( 'Encoded string that decodes to PHP code (eval/system/base64_decode)', 'ux-studio' ), $line );
		}
	}

	/**
	 * Decode \x / octal escapes (double-quoted) or base64; '' when nothing to decode.
	 */
	private function decode_literal( string $body, bool $double ): string {
		if ( $double && preg_match_all( '/\\\\(x[0-9a-fA-F]{1,2}|[0-7]{2,3})/', $body ) >= 4 ) {
			return stripcslashes( $body );
		}
		if ( strlen( $body ) >= 16 && preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $body ) ) {
			$raw = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false === $raw ? '' : $raw;
		}
		return '';
	}

	/**
	 * include "\x2fhome/...favicon.ico": including an image or an escaped path.
	 */
	private function inspect_include_path( string $literal, int $line ): void {
		$body    = substr( $literal, 1, -1 );
		$escaped = '"' === $literal[0] && preg_match_all( '/\\\\(x[0-9a-fA-F]{1,2}|[0-7]{2,3})/', $body ) >= 4;
		$path    = $escaped ? stripcslashes( $body ) : $body;
		$ext     = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( $escaped || in_array( $ext, array( 'ico', 'png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp', 'txt', 'log', 'zip' ), true ) ) {
			/* translators: %s: included path */
			$this->add( 'include_disguised', 50, sprintf( __( 'Includes a disguised file as PHP: %s', 'ux-studio' ), wp_basename( $path ) ), $line );
		}
	}

	/**
	 * @param array $s Statement features.
	 */
	private function score_statement( array $s ): void {
		$runner = $s['executor'] ?? $s['shell'];

		if ( $runner && $s['decoder'] ) {
			/* translators: 1: function like eval, 2: decoder like base64_decode */
			$this->add( 'run_decoded', 60, sprintf( __( 'Decoded data is executed: %1$s(%2$s(...))', 'ux-studio' ), $runner[0], $s['decoder'][0] ), $runner[1] );
		}
		$safe_shell = ! $s['executor'] && $s['sanitized'];
		if ( $runner && $s['input'] && ! $safe_shell ) {
			/* translators: %s: function like eval or system */
			$this->add( 'run_input', 60, sprintf( __( 'Request data ($_GET/$_POST/...) is passed to %s()', 'ux-studio' ), $runner[0] ), $runner[1] );
		}
		if ( $s['include'] && $s['input'] && ! $s['sanitized'] ) {
			// Through a variable it is usually a validated page/plugin name
			// (wp-admin does this) - worth a look, not an alarm.
			if ( $s['direct'] ) {
				$this->add( 'include_input', 60, __( 'A file is included from a path taken from the request', 'ux-studio' ), $s['include'] );
			} else {
				$this->add( 'include_input_var', 20, __( 'A file is included from a path derived from request data', 'ux-studio' ), $s['include'] );
			}
		}
		if ( $s['executor'] && $s['blob'] ) {
			$this->add( 'run_blob', 60, __( 'An encoded blob is executed in the same statement', 'ux-studio' ), $s['executor'][1] );
		}
		if ( $s['dynamic'] && $s['input'] && ! $s['sanitized'] ) {
			$this->add( 'dynamic_input', $s['direct'] ? 60 : 45, __( 'A function chosen by a variable is called with request data', 'ux-studio' ), $s['dynamic'] );
		} elseif ( $s['dynamic'] && $s['decoder'] ) {
			$this->add( 'dynamic_decoded', 40, __( 'A function chosen by a variable is called with decoded data', 'ux-studio' ), $s['dynamic'] );
		}
		if ( $s['cb_input'] ) {
			$this->add( 'callback_input', 'direct' === $s['cb_input'][0] ? 60 : 45, __( 'The function to call is taken from the request (call_user_func)', 'ux-studio' ), $s['cb_input'][1] );
		}
		if ( $s['cb_string'] ) {
			/* translators: %s: function like assert */
			$this->add( 'callback_string', 45, sprintf( __( 'Dangerous function "%s" passed as a callback', 'ux-studio' ), $s['cb_string'][0] ), $s['cb_string'][1] );
		}
		if ( $s['hash_cmp'] && $s['input'] && $s['hex_hash'] ) {
			$this->add( 'password_gate', 25, __( 'Request data compared with a hard-coded hash (password-protected shell)', 'ux-studio' ), $s['line'] );
		}
		if ( $s['executor'] && 'assert' !== $s['executor'][0] ) {
			/* translators: %s: eval or create_function */
			$this->add( 'executor', 10, sprintf( __( '%s() call', 'ux-studio' ), $s['executor'][0] ), $s['executor'][1] );
		}
		if ( $s['shell'] ) {
			/* translators: %s: shell function like exec */
			$this->add( 'shell', 8, sprintf( __( 'Shell command call: %s()', 'ux-studio' ), $s['shell'][0] ), $s['shell'][1] );
		}
	}

	/**
	 * @param array $file File-level features.
	 */
	private function score_file( array $file ): void {
		if ( $file['extract_input'] ) {
			if ( $file['dynamic'] ) {
				$this->add( 'extract_dynamic', 60, __( 'extract() of request data plus a call through a variable', 'ux-studio' ), $file['extract_input'] );
			} else {
				$this->add( 'extract_input', 35, __( 'extract() turns request data into variables', 'ux-studio' ), $file['extract_input'] );
			}
		}
		if ( $file['blob'] ) {
			if ( $file['executor'] ) {
				$this->add( 'blob_with_executor', 40, __( 'Long encoded string in a file that also runs eval()', 'ux-studio' ), $file['blob'] );
			} else {
				$this->add( 'blob', 5, __( 'Long encoded string', 'ux-studio' ), $file['blob'] );
			}
		}
		if ( $file['chr_line'] ) {
			$this->add( 'chr_chain', 20, __( 'Text assembled from many chr() calls', 'ux-studio' ), $file['chr_line'] );
		}
		if ( $file['code_chars'] >= 200 && $file['odd_chars'] / $file['code_chars'] > 0.3 ) {
			$this->add( 'obfuscated_names', $file['executor'] ? 40 : 15, __( 'Function and variable names are mostly non-ASCII (obfuscation)', 'ux-studio' ), 1 );
		}
	}

	/**
	 * Code pushed far to the right so an editor shows an empty line.
	 */
	private function scan_layout( string $content ): void {
		if ( preg_match( '/^([ \t]{' . self::OFFSCREEN_INDENT . ',})\S/m', $content, $m, PREG_OFFSET_CAPTURE )
			|| preg_match( '/<\?php[ \t]{' . self::OFFSCREEN_INDENT . ',}\S/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$line = substr_count( substr( $content, 0, (int) $m[0][1] ), "\n" ) + 1;
			$this->add( 'offscreen_code', 35, __( 'Code hidden off-screen behind hundreds of spaces', 'ux-studio' ), $line );
		}
	}

	private function is_input_var( string $name ): bool {
		return in_array( $name, self::INPUTS, true ) || isset( $this->tainted[ $name ] );
	}

	/**
	 * Is the variable at $i a subscript of another, non-request variable?
	 */
	private function in_foreign_subscript( int $i, int $from ): bool {
		$depth = 0;
		for ( $j = $i - 1; $j >= $from; $j-- ) {
			$t = $this->tokens[ $j ][1];
			if ( ']' === $t || ')' === $t ) {
				++$depth;
			} elseif ( '(' === $t ) {
				if ( 0 === $depth ) {
					return false; // Inside a call's arguments, not a subscript.
				}
				--$depth;
			} elseif ( '[' === $t ) {
				if ( $depth > 0 ) {
					--$depth;
					continue;
				}
				// Unmatched [: find the base of this subscript chain.
				for ( $k = $j - 1; $k >= $from; $k-- ) {
					$b = $this->tokens[ $k ];
					if ( ']' === $b[1] ) {
						// Skip a preceding [..] in the chain.
						$d = 1;
						for ( $k--; $k >= $from && $d > 0; $k-- ) {
							$d += ']' === $this->tokens[ $k ][1] ? 1 : ( '[' === $this->tokens[ $k ][1] ? -1 : 0 );
						}
						++$k;
						continue;
					}
					return T_VARIABLE === $b[0] && ! $this->is_input_var( $b[1] );
				}
				return false;
			}
		}
		return false;
	}

	/**
	 * Name of the function called at token $i, or '' when it is not a plain
	 * function call (method call, declaration, `new`, constant...).
	 */
	private function call_name( int $i, int $from, int $to ): string {
		list( $id, $text ) = $this->tokens[ $i ];
		$next = $this->tokens[ $i + 1 ] ?? null;
		if ( ! $next || '(' !== $next[1] || $i + 1 >= $to ) {
			return '';
		}
		$names = array( T_STRING, T_EVAL, T_EXIT );
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
			$names[] = T_NAME_FULLY_QUALIFIED;
		}
		if ( ! in_array( $id, $names, true ) ) {
			return '';
		}
		$prev = $i > $from ? $this->tokens[ $i - 1 ] : null;
		if ( $prev && $this->is_member_or_decl( $prev ) ) {
			return '';
		}
		return T_EVAL === $id ? 'eval' : strtolower( ltrim( $text, '\\' ) );
	}

	/**
	 * Is the `(` at $i calling something computed: $f(, $x['f'](, ${..}( ?
	 * Method calls ($obj->$m(), Foo::$m()) and closures are not counted.
	 */
	private function is_dynamic_callee( int $i, int $from ): bool {
		$j    = $i - 1;
		$prev = $this->tokens[ $j ];

		if ( T_VARIABLE === $prev[0] ) {
			$before = $j > $from ? $this->tokens[ $j - 1 ] : null;
			return ! ( $before && $this->is_member_or_decl( $before ) ) && '$this' !== $prev[1];
		}

		if ( ']' === $prev[1] || '}' === $prev[1] ) {
			// Walk back to the matching bracket chain and find its base.
			$depth = 0;
			for ( ; $j >= $from; $j-- ) {
				$t = $this->tokens[ $j ][1];
				if ( ']' === $t || '}' === $t ) {
					++$depth;
				} elseif ( '[' === $t || '{' === $t || ( T_CURLY_OPEN === $this->tokens[ $j ][0] ) || ( T_DOLLAR_OPEN_CURLY_BRACES === $this->tokens[ $j ][0] ) ) {
					--$depth;
					if ( 0 === $depth ) {
						$base = $this->tokens[ $j - 1 ] ?? null;
						if ( $base && ( ']' === $base[1] || '}' === $base[1] ) ) {
							$depth = 0;
							continue; // $a[1][2](: keep walking.
						}
						if ( '$' === $t || ( $base && '$' === $base[1] ) ) {
							return true; // ${'name'}( / $$name(.
						}
						if ( ! $base || T_VARIABLE !== $base[0] ) {
							return false; // [..]( is an array literal / closure use.
						}
						$before = $j - 2 >= $from ? $this->tokens[ $j - 2 ] : null;
						return ! ( $before && $this->is_member_or_decl( $before ) );
					}
				}
			}
		}
		return false;
	}

	/**
	 * Token that makes the following name a member access or a declaration.
	 *
	 * @param array $token Token.
	 */
	private function is_member_or_decl( array $token ): bool {
		$ids = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$ids[] = T_NULLSAFE_OBJECT_OPERATOR;
		}
		return in_array( $token[0], $ids, true );
	}

	/**
	 * A long literal made of base64 or \x escapes, not prose or markup.
	 */
	private function is_encoded_blob( string $body ): bool {
		if ( preg_match( '/^[A-Za-z0-9+\/=\r\n]+$/', $body ) ) {
			return true;
		}
		return (int) preg_match_all( '/\\\\x[0-9a-fA-F]{2}/', $body ) * 4 > strlen( $body ) / 2;
	}

	/**
	 * @param array  $file File features (by reference).
	 * @param string $text Identifier.
	 */
	private function count_chars( array &$file, string $text ): void {
		$file['code_chars'] += strlen( $text );
		$file['odd_chars']  += (int) preg_match_all( '/[^\x20-\x7E]/', $text );
	}

	private function add( string $name, int $weight, string $label, int $line ): void {
		if ( isset( $this->found[ $name ] ) ) {
			++$this->found[ $name ]['count'];
			return;
		}
		$this->found[ $name ] = array(
			'name'   => $name,
			'weight' => $weight,
			'label'  => $label,
			'line'   => max( 1, $line ),
			'count'  => 1,
		);
	}
}
