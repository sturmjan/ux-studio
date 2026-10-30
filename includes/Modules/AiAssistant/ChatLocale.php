<?php
/**
 * Runs code with the plugin's texts in the CHAT language (setting `language`), not in the language of the
 * current request.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * The visitor chat must speak the language the site owner picked in "Chat language", whatever the locale of the
 * page, the visitor or the logged-in admin is. `switch_to_locale()` is not enough for that: it only switches to
 * languages WordPress itself has installed, so a Czech chat on a site installed in English would stay English.
 *
 * Instead, while a callback runs, the `gettext` filters answer from the plugin's own `.mo` file of the chat
 * language (the one shipped in `languages/`, or an override in `WP_LANG_DIR/plugins/`). Texts are still written
 * as ordinary `__( 'English text', 'ux-studio' )` calls, so string extraction keeps working. English needs no file:
 * the filter returns the original text, which also undoes a Czech admin locale.
 */
final class ChatLocale {

	private const DOMAIN = 'ux-studio';

	/** Supported chat languages => locale of the .mo file. */
	private const LOCALES = array(
		'cs' => 'cs_CZ',
		'en' => 'en_US',
	);

	/** @var array<string, \MO|null> Loaded catalogs per chat language (null = no file, i.e. English). */
	private static array $catalogs = array();

	/** @var string Chat language of the callback that is running, '' outside of it. */
	private static string $active = '';

	/**
	 * Chat language from the setting, always 'cs' or 'en'.
	 *
	 * @param mixed $value Raw value of the `language` setting.
	 */
	public static function normalize( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return isset( self::LOCALES[ $value ] ) ? $value : 'cs';
	}

	/**
	 * Run a callback with `__()` answering in the given chat language.
	 *
	 * @template T
	 * @param string       $language 'cs' or 'en'.
	 * @param callable():T $callback Code that calls __() / _x().
	 * @return T
	 */
	public static function run( string $language, callable $callback ) {
		$language = self::normalize( $language );
		$previous = self::$active;

		self::$active = $language;
		add_filter( 'gettext', array( self::class, 'filter_gettext' ), 9999, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'filter_gettext_with_context' ), 9999, 4 );
		try {
			return $callback();
		} finally {
			remove_filter( 'gettext', array( self::class, 'filter_gettext' ), 9999 );
			remove_filter( 'gettext_with_context', array( self::class, 'filter_gettext_with_context' ), 9999 );
			self::$active = $previous;
			if ( '' !== $previous ) {
				// Nested call: the outer one still needs its filters.
				add_filter( 'gettext', array( self::class, 'filter_gettext' ), 9999, 3 );
				add_filter( 'gettext_with_context', array( self::class, 'filter_gettext_with_context' ), 9999, 4 );
			}
		}
	}

	/**
	 * Wrap a REST callback of a public chat route so that its answers (error messages, texts of the e-mails it
	 * sends) come in the chat language, not in the locale of the site.
	 *
	 * @param callable                 $callback REST callback.
	 * @param (callable():string)|null $language Returns the chat language; default: the `language` setting.
	 * @return callable
	 */
	public static function wrap( callable $callback, ?callable $language = null ): callable {
		return static function ( ...$args ) use ( $callback, $language ) {
			$lang = null !== $language
				? (string) $language()
				: (string) ( new \UxStudio\Core\Settings( 'uxstudio_ai_assistant' ) )->get( 'language', 'cs' );
			return self::run( $lang, static fn() => $callback( ...$args ) );
		};
	}

	/**
	 * Translate one text into the chat language outside of a run() block (used by REST handlers).
	 *
	 * @param string $text     English source text.
	 * @param string $language 'cs' or 'en'.
	 */
	public static function translate( string $text, string $language ): string {
		return self::lookup( $text, '', self::normalize( $language ) );
	}

	/**
	 * `gettext` filter: answer from the catalog of the chat language.
	 *
	 * @param string $translation Text translated for the request locale.
	 * @param string $text        Original (English) text.
	 * @param string $domain      Text domain.
	 */
	public static function filter_gettext( $translation, $text, $domain ): string {
		if ( self::DOMAIN !== $domain || '' === self::$active ) {
			return (string) $translation;
		}
		return self::lookup( (string) $text, '', self::$active );
	}

	/**
	 * `gettext_with_context` filter.
	 *
	 * @param string $translation Text translated for the request locale.
	 * @param string $text        Original (English) text.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 */
	public static function filter_gettext_with_context( $translation, $text, $context, $domain ): string {
		if ( self::DOMAIN !== $domain || '' === self::$active ) {
			return (string) $translation;
		}
		return self::lookup( (string) $text, (string) $context, self::$active );
	}

	/**
	 * Translation of an English text in a chat language; the text itself when there is none.
	 */
	private static function lookup( string $text, string $context, string $language ): string {
		$catalog = self::catalog( $language );
		if ( null === $catalog ) {
			return $text;
		}
		$found = $catalog->translate( $text, '' === $context ? null : $context );
		return ( is_string( $found ) && '' !== $found ) ? $found : $text;
	}

	/**
	 * Catalog (.mo) of a chat language, or null for English / a missing file.
	 */
	private static function catalog( string $language ): ?\MO {
		if ( array_key_exists( $language, self::$catalogs ) ) {
			return self::$catalogs[ $language ];
		}
		self::$catalogs[ $language ] = null;
		$locale                      = self::LOCALES[ $language ] ?? '';
		if ( '' === $locale || 'en_US' === $locale ) {
			return null;
		}

		if ( ! class_exists( '\MO' ) ) {
			require_once ABSPATH . WPINC . '/pomo/mo.php';
		}
		$candidates = array(
			WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale . '.mo',
			UXSTUDIO_PATH . 'languages/' . self::DOMAIN . '-' . $locale . '.mo',
		);
		foreach ( $candidates as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$mo = new \MO();
			if ( $mo->import_from_file( $file ) ) {
				self::$catalogs[ $language ] = $mo;
				break;
			}
		}
		return self::$catalogs[ $language ];
	}
}
