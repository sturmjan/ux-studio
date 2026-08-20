<?php
/**
 * Token-based PHP syntax / duplicate-declaration validator.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CodeSnippets;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported ~1:1 from the legacy code-snippets module (itself forked from
 * https://github.com/codesnippetspro/code-snippets/). Pure utility class with
 * no framework dependency: walks the token stream produced by token_get_all()
 * to catch (a) truncated/invalid syntax and (b) function/class names that
 * would collide with something already declared, WITHOUT ever eval()'ing or
 * otherwise executing the snippet code. This is the mandatory gate every PHP
 * snippet must pass before it is written to disk.
 */
final class PhpValidator {

	/** @var string Code being validated. */
	private string $code;

	/** @var array<int, string|array<int, string|int>> Token stream. */
	private array $tokens;

	/** @var int Index of the token currently being examined. */
	private int $current;

	/** @var int Total number of tokens. */
	private int $length;

	/** @var array<int|string, string[]> Identifiers already seen, keyed by token type. */
	private array $defined_identifiers = array();

	/** @var array<int|string, string[]> Identifiers excluded from duplicate checks (function_exists/class_exists guards). */
	private array $exceptions = array();

	/**
	 * @param string $code Snippet code for parsing.
	 */
	public function __construct( string $code ) {
		$this->code    = $code;
		$this->tokens  = token_get_all( $this->code );
		$this->length  = count( $this->tokens );
		$this->current = 0;
	}

	/**
	 * Whether the parser has reached the end of the token list.
	 */
	private function end(): bool {
		return $this->current === $this->length;
	}

	/**
	 * Peek at the current token without advancing.
	 *
	 * @return string|array<int, string|int>|null
	 */
	private function peek() {
		return $this->end() ? null : $this->tokens[ $this->current ];
	}

	/**
	 * Advance the pointer by one token, if possible.
	 */
	private function next(): void {
		if ( ! $this->end() ) {
			++$this->current;
		}
	}

	/**
	 * Whether an identifier of the given type has already been declared.
	 *
	 * @param int|string $type       T_FUNCTION, T_CLASS or T_INTERFACE.
	 * @param string     $identifier Identifier name.
	 */
	private function check_duplicate_identifier( $type, string $identifier ): bool {
		if ( ! isset( $this->defined_identifiers[ $type ] ) ) {
			switch ( $type ) {
				case T_FUNCTION:
					$defined_functions                     = get_defined_functions();
					$this->defined_identifiers[ T_FUNCTION ] = array_merge( $defined_functions['internal'], $defined_functions['user'] );
					break;

				case T_CLASS:
					$this->defined_identifiers[ T_CLASS ] = get_declared_classes();
					break;

				case T_INTERFACE:
					$this->defined_identifiers[ T_INTERFACE ] = get_declared_interfaces();
					break;

				default:
					return false;
			}
		}

		$duplicate = in_array( $identifier, $this->defined_identifiers[ $type ], true );
		array_unshift( $this->defined_identifiers[ $type ], $identifier );

		return $duplicate && ! ( isset( $this->exceptions[ $type ] ) && in_array( $identifier, $this->exceptions[ $type ], true ) );
	}

	/**
	 * Validate the code: catches truncated/invalid syntax and duplicate
	 * function/class/interface declarations. Never executes the code.
	 *
	 * @return WP_Error|true
	 */
	public function validate() {
		while ( ! $this->end() ) {
			$token = $this->peek();
			$this->next();

			if ( ! is_array( $token ) ) {
				continue;
			}

			// If this is a function_exists()/class_exists() guard, allow the
			// guarded identifier to be (re)defined without tripping the check.
			if ( T_STRING === $token[0] && 'function_exists' === $token[1] || 'class_exists' === $token[1] ) {
				$type = 'function_exists' === $token[1] ? T_FUNCTION : T_CLASS;

				while ( ! $this->end() && T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
					$token = $this->peek();
					$this->next();
				}

				$this->exceptions[ $type ]   = $this->exceptions[ $type ] ?? array();
				$this->exceptions[ $type ][] = trim( $token[1], '\'"' );
				continue;
			}

			// Consume "::class" so it isn't mistaken for a class declaration.
			if ( T_DOUBLE_COLON === $token[0] ) {
				$token = $this->peek();
				$this->next();

				if ( T_CLASS === $token[0] ) {
					$this->next();
					$token = $this->peek();
				}
			}

			if ( T_CLASS !== $token[0] && T_FUNCTION !== $token[0] ) {
				continue;
			}

			$structure_type = $token[0];

			while (
				! $this->end() && T_STRING !== $token[0] &&
				( T_FUNCTION !== $structure_type || '(' !== $token ) && ( T_CLASS !== $structure_type || '{' !== $token )
			) {
				$token = $this->peek();
				$this->next();
			}

			if ( $this->end() ) {
				return new WP_Error(
					'parse_error',
					__( 'Parse error: syntax error, unexpected end of snippet.', 'ux-studio' ),
					array( 'line' => $token[2] ?? 0 )
				);
			}

			// Anonymous function/class: nothing to check for duplicates.
			if ( ! ( T_FUNCTION === $structure_type && '(' === $token ) && ! ( T_CLASS === $structure_type && '{' === $token ) ) {
				if ( $this->check_duplicate_identifier( $structure_type, $token[1] ) ) {
					switch ( $structure_type ) {
						case T_FUNCTION:
							/* translators: %s: PHP function name */
							$message = __( 'Cannot redeclare function %s.', 'ux-studio' );
							break;
						case T_CLASS:
							/* translators: %s: PHP class name */
							$message = __( 'Cannot redeclare class %s.', 'ux-studio' );
							break;
						case T_INTERFACE:
							/* translators: %s: PHP interface name */
							$message = __( 'Cannot redeclare interface %s.', 'ux-studio' );
							break;
						default:
							/* translators: %s: PHP identifier name */
							$message = __( 'Cannot redeclare %s.', 'ux-studio' );
					}

					return new WP_Error(
						'duplicate_error',
						sprintf( $message, $token[1] ),
						array( 'line' => $token[2] ?? 0 )
					);
				}
			}

			if ( T_CLASS !== $structure_type ) {
				continue;
			}

			while ( ! $this->end() && '{' !== $token ) {
				$token = $this->peek();
				$this->next();
			}

			$depth = 1;
			while ( ! $this->end() && $depth > 0 ) {
				$token = $this->peek();

				if ( '{' === $token ) {
					++$depth;
				} elseif ( '}' === $token ) {
					--$depth;
				}

				$this->next();
			}

			if ( $depth > 0 ) {
				return new WP_Error(
					'syntax_error',
					__( 'Parse error: syntax error, unexpected end of snippet', 'ux-studio' ),
					array( 'line' => $token[2] ?? 0 )
				);
			}
		}

		return true;
	}

	/**
	 * Non-executing syntax sanity check via token_get_all() (catches things
	 * like an unterminated string that produces a ParseError when tokenized).
	 *
	 * @return true|WP_Error
	 */
	public function checkRunTimeError() {
		$code = preg_replace( '/^<\?php/', '', $this->code );

		try {
			token_get_all( (string) $code );
			return true;
		} catch ( \ParseError $parse_error ) {
			return new WP_Error(
				'runtime_error',
				$parse_error->getMessage(),
				array( 'line' => $parse_error->getLine() )
			);
		}
	}
}
