<?php
/**
 * Pure rules of the admin notice history: cleaning of what the browser reports, the stable key, the guess of
 * the source plugin, state transitions, retention and the CSV row.
 *
 * No WordPress calls here (except the shared Csv helper, which is pure too), so tests/notice-history.php
 * covers every rule without a database.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser\NoticeHistory;

use UxStudio\Core\Csv;

defined( 'ABSPATH' ) || exit;

final class Rules {

	public const TYPES   = array( 'error', 'warning', 'success', 'info' );
	public const STATES  = array( 'active', 'dismissed', 'gone' );
	public const METHODS = array( 'panel', 'plugin' );
	/** Filter periods: id => length in seconds (0 = no limit). */
	public const PERIODS = array(
		'24h' => 86400,
		'7d'  => 604800,
		'30d' => 2592000,
		'all' => 0,
	);

	public const TEXT_MAX      = 2000;
	public const TITLE_MAX     = 160;
	public const KEY_TEXT_MAX  = 240;
	/** Notices accepted in one log request. */
	public const BATCH_MAX     = 40;
	/** A notice that was not seen for this many days is "gone". */
	public const GONE_DAYS     = 7;
	/** Rows not seen for this many days are deleted. */
	public const RETENTION_DAYS = 90;
	/** Rows kept at most (the newest by last_seen). */
	public const ROW_CAP       = 1000;
	public const PER_PAGE      = 20;
	public const PER_PAGE_MAX  = 100;

	/** Element id of the critical PHP version notice (never moved to the panel, never recorded). */
	public const PHP_NOTICE_ID = 'uxstudio-php-version-notice';

	/** Source slug used by the filter for "no source could be guessed". */
	public const UNKNOWN_SOURCE = '_unknown';

	/**
	 * @param mixed $type Reported type.
	 */
	public static function type( $type ): string {
		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		return in_array( $type, self::TYPES, true ) ? $type : 'info';
	}

	/**
	 * Plain text from whatever the browser sent: markup and scripts removed, entities decoded, control
	 * characters dropped, whitespace collapsed, cut to $max characters (an ellipsis counts into the limit).
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum length in characters.
	 */
	public static function clean_text( $value, int $max = self::TEXT_MAX ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		// Bound the work first: a hostile payload cannot make us run regexes over megabytes.
		$value = substr( $value, 0, $max * 8 );
		$value = preg_replace( '#<(script|style)\b[^>]*>.*?(</\1\s*>|$)#is', ' ', $value ) ?? '';
		$value = preg_replace( '#<\s*(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', ' ', $value ) ?? '';
		$value = strip_tags( $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Entities may have decoded into markup (&lt;b&gt;): strip once more, what is left is plain text.
		$value = strip_tags( $value );
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$value = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $value, 'UTF-8', 'UTF-8' ) : '';
		}
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value ) ?? '';
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );
		if ( mb_strlen( $value ) > $max ) {
			$value = rtrim( mb_substr( $value, 0, max( 0, $max - 1 ) ) ) . "\u{2026}";
		}
		return $value;
	}

	/**
	 * The text the key is made of: lower case, every run of digits collapsed (counts, versions and dates
	 * change between page loads, the notice stays the same), whitespace collapsed, first 240 characters.
	 *
	 * @param string $text Plain text.
	 */
	public static function normalize_for_key( string $text ): string {
		$text = mb_strtolower( $text );
		$text = preg_replace( '/\d+/u', '#', $text ) ?? '';
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		return mb_substr( $text, 0, self::KEY_TEXT_MAX );
	}

	/**
	 * Stable key of a notice (CHAR(40)): hash of its type and the normalised text.
	 *
	 * @param string $type Notice type.
	 * @param string $text Plain text.
	 */
	public static function key( string $type, string $text ): string {
		return sha1( self::type( $type ) . '|' . self::normalize_for_key( $text ) );
	}

	/**
	 * The key the panel script itself uses (lower-cased text, 240 characters) - kept so the page can recognise
	 * a notice that was hidden by the panel earlier.
	 *
	 * @param mixed $value Raw client key.
	 */
	public static function client_key( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $value ) ?? '';
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );
		return mb_substr( $value, 0, self::KEY_TEXT_MAX );
	}

	/** Admin screen id as WordPress names them (`plugins`, `toplevel_page_x`, `edit-post`). */
	public static function screen( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return 1 === preg_match( '/^[a-z0-9_\-:.]{1,100}$/', $value ) ? $value : '';
	}

	/**
	 * Validate one reported notice. Null when it has nothing worth recording.
	 *
	 * @param mixed $raw Item from the request.
	 * @return array{ckey:string,type:string,title:string,text:string,markup:string,id:string,plugin:string,screen:string,dismissible:bool}|null
	 */
	public static function sanitize_item( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$text = self::clean_text( $raw['text'] ?? '' );
		if ( '' === $text ) {
			return null;
		}
		$markup = is_string( $raw['markup'] ?? null ) ? $raw['markup'] : '';
		$id     = is_string( $raw['id'] ?? null ) ? $raw['id'] : '';
		$plugin = is_string( $raw['plugin'] ?? null ) ? $raw['plugin'] : '';
		$ckey   = self::client_key( $raw['ckey'] ?? $raw['key'] ?? '' );
		// The critical PHP version notice of UX Studio is never part of the history.
		if ( self::PHP_NOTICE_ID === $id ) {
			return null;
		}
		return array(
			'ckey'        => '' !== $ckey ? $ckey : mb_substr( mb_strtolower( $text ), 0, self::KEY_TEXT_MAX ),
			'type'        => self::type( $raw['type'] ?? '' ),
			'title'       => self::clean_text( $raw['title'] ?? '', self::TITLE_MAX ),
			'text'        => $text,
			'markup'      => substr( trim( preg_replace( '/[^A-Za-z0-9 _:.\-]/', ' ', substr( $markup, 0, 2000 ) ) ?? '' ), 0, 600 ),
			'id'          => substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $id ) ?? '', 0, 100 ),
			'plugin'      => substr( preg_replace( '/[^A-Za-z0-9_\-.\/]/', '', $plugin ) ?? '', 0, 120 ),
			'screen'      => self::screen( $raw['screen'] ?? '' ),
			'dismissible' => ! empty( $raw['dismissible'] ),
		);
	}

	/**
	 * Markup fingerprints of well known plugins: pattern (against the classes and the id of the notice),
	 * directory slug of the plugin and the name used when the plugin is not in the list of active ones.
	 *
	 * @var array<int, array{0:string,1:string,2:string}>
	 */
	private const CLASS_RULES = array(
		array( '/(^|[\s_-])e-notice|(^|[\s_-])elementor/i', 'elementor', 'Elementor' ),
		array( '/updraft/i', 'updraftplus', 'UpdraftPlus' ),
		array( '/wordfence|(^|[\s_-])wfls?([\s_-]|$)/i', 'wordfence', 'Wordfence Security' ),
		array( '/yoast|wpseo/i', 'wordpress-seo', 'Yoast SEO' ),
		array( '/rank[-_]?math/i', 'seo-by-rank-math', 'Rank Math SEO' ),
		array( '/aioseo/i', 'all-in-one-seo-pack', 'All in One SEO' ),
		array( '/woocommerce|(^|[\s_-])wc-(admin|notice|message)/i', 'woocommerce', 'WooCommerce' ),
		array( '/(^|[\s_-])acf([\s_-]|$)/i', 'advanced-custom-fields', 'Advanced Custom Fields' ),
		array( '/jetpack/i', 'jetpack', 'Jetpack' ),
		array( '/akismet/i', 'akismet', 'Akismet' ),
		array( '/wpcf7|contact-form-7/i', 'contact-form-7', 'Contact Form 7' ),
		array( '/wpforms/i', 'wpforms-lite', 'WPForms' ),
		array( '/litespeed/i', 'litespeed-cache', 'LiteSpeed Cache' ),
		array( '/(^|[\s_-])(wpr|rocket)([\s_-]|$)|wp-rocket/i', 'wp-rocket', 'WP Rocket' ),
		array( '/wp-?mail-?smtp|wpms/i', 'wp-mail-smtp', 'WP Mail SMTP' ),
	);

	/**
	 * Best-effort guess of the plugin behind a notice. Always an estimate (the UI labels it so).
	 *
	 * @param array<string, mixed>  $item    Output of sanitize_item().
	 * @param array<string, string> $plugins Active plugins: directory slug => name.
	 * @return array{slug:string,name:string,how:string} Empty slug and name when nothing matched.
	 */
	public static function guess_source( array $item, array $plugins ): array {
		$hay   = trim( (string) ( $item['markup'] ?? '' ) . ' ' . (string) ( $item['id'] ?? '' ) );
		$text  = (string) ( $item['text'] ?? '' );
		$title = (string) ( $item['title'] ?? '' );
		$none  = array(
			'slug' => '',
			'name' => '',
			'how'  => '',
		);

		// UX Studio's own notices.
		if ( 1 === preg_match( '/(^|[\s_-])(uxstudio|uxs)([\s_-]|$)/i', $hay ) || 1 === preg_match( '/^ux studio\b/i', $title ) ) {
			return array(
				'slug' => 'ux-studio',
				'name' => 'UX Studio',
				'how'  => 'own',
			);
		}

		// WordPress core: the update nag with its fixed sentence.
		if ( 1 === preg_match( '/\bWordPress\s+\d+(\.\d+)*\s+(is available|je dostupn|je k dispozici)/iu', $text )
			|| ( 1 === preg_match( '/(^|\s)update-nag(\s|$)/', $hay ) && 1 === preg_match( '/^\s*WordPress\b/u', $text ) ) ) {
			return array(
				'slug' => 'wordpress',
				'name' => 'WordPress',
				'how'  => 'core',
			);
		}

		// An explicit data-plugin / data-slug ("dir/file.php" or "slug").
		$plugin = (string) ( $item['plugin'] ?? '' );
		if ( '' !== $plugin ) {
			$token = false !== strpos( $plugin, '/' ) ? substr( $plugin, 0, (int) strpos( $plugin, '/' ) ) : preg_replace( '/\.php$/', '', $plugin );
			$found = self::resolve_plugin( (string) $token, $plugins );
			if ( null !== $found ) {
				return $found + array( 'how' => 'data' );
			}
			// Not among the active plugins (or the directory is named differently): still the best slug there is.
			$slug = str_replace( '_', '-', strtolower( (string) $token ) );
			if ( 1 === preg_match( '/^[a-z0-9][a-z0-9-]{1,98}$/', $slug ) ) {
				return array(
					'slug' => $slug,
					'name' => self::pretty_slug( $slug ),
					'how'  => 'data',
				);
			}
		}

		// Freemius notices carry the slug of the plugin in a class.
		if ( 1 === preg_match( '/(?:^|\s)fs-slug-([a-z0-9][a-z0-9_-]*)/i', $hay, $m ) ) {
			$found = self::resolve_plugin( strtolower( $m[1] ), $plugins );
			if ( null !== $found ) {
				return $found + array( 'how' => 'class' );
			}
			$slug = str_replace( '_', '-', strtolower( $m[1] ) );
			return array(
				'slug' => $slug,
				'name' => self::pretty_slug( $slug ),
				'how'  => 'class',
			);
		}

		foreach ( self::CLASS_RULES as $rule ) {
			if ( 1 === preg_match( $rule[0], $hay ) ) {
				$found = self::resolve_plugin( $rule[1], $plugins );
				return ( $found ?? array(
					'slug' => $rule[1],
					'name' => $rule[2],
				) ) + array( 'how' => 'class' );
			}
		}

		// The title label ("UpdraftPlus") or the name of an active plugin in the text.
		if ( '' !== $title ) {
			foreach ( $plugins as $slug => $name ) {
				if ( 0 === strcasecmp( trim( $name ), $title ) ) {
					return array(
						'slug' => (string) $slug,
						'name' => (string) $name,
						'how'  => 'title',
					);
				}
			}
		}
		$best = null;
		foreach ( $plugins as $slug => $name ) {
			$name = trim( (string) $name );
			if ( mb_strlen( $name ) < 4 ) {
				continue;
			}
			if ( 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/iu', $title . ' ' . $text ) ) {
				if ( null === $best || mb_strlen( $name ) > mb_strlen( $best['name'] ) ) {
					$best = array(
						'slug' => (string) $slug,
						'name' => $name,
						'how'  => 'text',
					);
				}
			}
		}
		return $best ?? $none;
	}

	/**
	 * Active plugin for a slug-like token: the exact directory first, then a directory that starts with it.
	 *
	 * @param string                $token   Slug from the markup.
	 * @param array<string, string> $plugins Directory slug => name.
	 * @return array{slug:string,name:string}|null
	 */
	private static function resolve_plugin( string $token, array $plugins ): ?array {
		$token = strtolower( trim( $token ) );
		if ( '' === $token ) {
			return null;
		}
		$variants = array_unique( array( $token, str_replace( '_', '-', $token ) ) );
		foreach ( $variants as $v ) {
			foreach ( $plugins as $slug => $name ) {
				if ( strtolower( (string) $slug ) === $v ) {
					return array(
						'slug' => (string) $slug,
						'name' => (string) $name,
					);
				}
			}
		}
		foreach ( $variants as $v ) {
			foreach ( $plugins as $slug => $name ) {
				$s = strtolower( (string) $slug );
				if ( 0 === strpos( $s, $v . '-' ) || 0 === strpos( $v, $s . '-' ) ) {
					return array(
						'slug' => (string) $slug,
						'name' => (string) $name,
					);
				}
			}
		}
		return null;
	}

	/** "my-cool_plugin" => "My Cool Plugin". */
	private static function pretty_slug( string $slug ): string {
		return mb_convert_case( trim( str_replace( array( '-', '_' ), ' ', $slug ) ), MB_CASE_TITLE, 'UTF-8' );
	}

	/**
	 * State when a notice is seen again. A notice that was gone or closed by the plugin is on the page again,
	 * so it is active again (and the comeback is counted).
	 *
	 * @param string $state       Stored state.
	 * @param int    $reappeared  Stored comeback counter.
	 * @return array{state:string,reappeared:int}
	 */
	public static function on_seen( string $state, int $reappeared ): array {
		if ( 'active' === $state ) {
			return array(
				'state'      => 'active',
				'reappeared' => $reappeared,
			);
		}
		return array(
			'state'      => 'active',
			'reappeared' => $reappeared + 1,
		);
	}

	/**
	 * State after the user closed the notice. `panel` = hidden by the panel itself (can be shown again),
	 * `plugin` = closed through the plugin's own control (the plugin keeps that, it cannot be undone here).
	 *
	 * @param mixed $method Reported method.
	 * @return array{state:string,method:string}
	 */
	public static function on_dismiss( $method ): array {
		return array(
			'state'  => 'dismissed',
			'method' => in_array( $method, self::METHODS, true ) ? (string) $method : 'panel',
		);
	}

	/**
	 * Can the notice be shown in the panel again? Only a notice hidden by the panel itself.
	 *
	 * @param string $state  Stored state.
	 * @param string $method Stored dismiss method.
	 */
	public static function can_reshow( string $state, string $method ): bool {
		return 'dismissed' === $state && 'panel' === $method;
	}

	/**
	 * Active notices not seen since the cutoff are gone.
	 *
	 * @param array<int, array{id:int|string,state:string,last_seen:string}> $rows   Rows.
	 * @param string                                                         $cutoff `Y-m-d H:i:s`, same zone as last_seen.
	 * @return int[] Ids to mark as gone.
	 */
	public static function gone_ids( array $rows, string $cutoff ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( 'active' === $row['state'] && $row['last_seen'] < $cutoff ) {
				$out[] = (int) $row['id'];
			}
		}
		return $out;
	}

	/** Cutoff datetime (formatted by the caller) for the "gone" sweep, as a timestamp. */
	public static function gone_cutoff( int $now, int $days = self::GONE_DAYS ): int {
		return $now - max( 1, $days ) * 86400;
	}

	/**
	 * Rows to delete: not seen since the cutoff, and everything beyond the newest $cap rows.
	 *
	 * @param array<int, array{id:int|string,last_seen:string}> $rows   Rows.
	 * @param string                                            $cutoff `Y-m-d H:i:s`.
	 * @param int                                               $cap    Rows to keep at most.
	 * @return int[]
	 */
	public static function purge_ids( array $rows, string $cutoff, int $cap = self::ROW_CAP ): array {
		$delete = array();
		$kept   = array();
		foreach ( $rows as $row ) {
			if ( $row['last_seen'] < $cutoff ) {
				$delete[] = (int) $row['id'];
			} else {
				$kept[] = $row;
			}
		}
		if ( count( $kept ) > $cap ) {
			usort(
				$kept,
				static function ( array $a, array $b ): int {
					return array( $b['last_seen'], (int) $b['id'] ) <=> array( $a['last_seen'], (int) $a['id'] );
				}
			);
			foreach ( array_slice( $kept, max( 0, $cap ) ) as $row ) {
				$delete[] = (int) $row['id'];
			}
		}
		return $delete;
	}

	/**
	 * Validated list filters (the REST args schema enforces the same rules, this is the second line).
	 *
	 * @param array<string, mixed> $raw Raw parameters.
	 * @return array{state:string,type:string,source:string,period:string,q:string,page:int,per_page:int}
	 */
	public static function sanitize_filters( array $raw ): array {
		$state  = is_string( $raw['state'] ?? null ) ? $raw['state'] : '';
		$type   = is_string( $raw['type'] ?? null ) ? $raw['type'] : '';
		$source = is_string( $raw['source'] ?? null ) ? strtolower( trim( $raw['source'] ) ) : '';
		$period = is_string( $raw['period'] ?? null ) ? $raw['period'] : 'all';
		$q      = self::clean_text( $raw['q'] ?? '', 100 );
		return array(
			'state'    => in_array( $state, self::STATES, true ) ? $state : '',
			'type'     => in_array( $type, self::TYPES, true ) ? $type : '',
			'source'   => 1 === preg_match( '/^[a-z0-9_\-.]{1,100}$/', $source ) ? $source : '',
			'period'   => isset( self::PERIODS[ $period ] ) ? $period : 'all',
			'q'        => $q,
			'page'     => max( 1, min( 100000, (int) ( $raw['page'] ?? 1 ) ) ),
			'per_page' => max( 1, min( self::PER_PAGE_MAX, (int) ( $raw['per_page'] ?? self::PER_PAGE ) ) ),
		);
	}

	/** Header of the CSV export (English keys; the endpoint translates them). */
	public const CSV_COLUMNS = array( 'id', 'type', 'title', 'text', 'source', 'source_guess', 'screen', 'state', 'first_seen', 'last_seen', 'seen_count', 'dismissed_at', 'dismissed_by', 'dismiss_method' );

	/**
	 * One CSV row, every cell safe against formula injection.
	 *
	 * @param array<string, mixed> $row Stored row (dismissed_by already resolved to a name or an id).
	 * @return string[]
	 */
	public static function csv_row( array $row ): array {
		$cells = array();
		foreach ( self::CSV_COLUMNS as $column ) {
			if ( 'source' === $column ) {
				$value = (string) ( $row['source_name'] ?? '' );
			} elseif ( 'source_guess' === $column ) {
				$value = (string) ( $row['source_slug'] ?? '' );
			} else {
				$value = $row[ $column ] ?? '';
			}
			$cells[] = Csv::safe_cell( $value );
		}
		return $cells;
	}
}
