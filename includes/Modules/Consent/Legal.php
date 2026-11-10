<?php
/**
 * Legal pages of the consent module: the wizard data, the pages, the version archive and the terms stored with orders.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 42.5 and 42.14 (phase F4).
 *
 *  - The wizard data (`LegalProfile`) is saved in the option `uxstudio_consent_legal` (not autoloaded) together with the
 *    ids of the created pages and the current version of each document.
 *  - A page holds only the shortcode `[uxstudio_legal doc="..."]`. The shortcode renders the document live from the
 *    template, the wizard data and `Report::site()`, so a change of the data or of the list of cookies shows at once.
 *  - A version is a snapshot of the rendered document (hidden post type `uxs_legal_version`) with a number, the hash of
 *    the text and the date it came into force. "Create or update the pages" makes a new version only when the hash of
 *    the text differs from the current version.
 *  - The terms and conditions version in force when an order is placed is stored in the order meta.
 */
final class Legal {

	public const OPTION = 'uxstudio_consent_legal';
	public const CPT    = 'uxs_legal_version';

	public const ORDER_VERSION   = '_uxstudio_terms_version';
	public const ORDER_HASH      = '_uxstudio_terms_hash';
	public const ORDER_EFFECTIVE = '_uxstudio_terms_effective';
	public const ORDER_POST      = '_uxstudio_terms_snapshot';

	/** @var array<string, mixed>|null Memo of the option within a request. */
	private static ?array $data = null;

	/**
	 * Hook everything in. Called once from Module::boot().
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'init' ) );
		add_action( 'rest_api_init', array( LegalRest::class, 'register' ) );
		// Order stamp: classic checkout, Store API (block checkout) and the display in the order screen (HPOS and posts).
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'stamp_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( self::class, 'stamp_order_blocks' ), 20, 1 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'show_order_terms' ), 20, 1 );
	}

	/** Post type of the archive and the shortcodes. */
	public static function init(): void {
		register_post_type(
			self::CPT,
			array(
				'label'               => 'UX Studio legal versions',
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor' ),
			)
		);
		add_shortcode( 'uxstudio_legal', array( self::class, 'shortcode' ) );
		add_shortcode( 'uxstudio_cookie_table', array( self::class, 'shortcode_cookie_table' ) );
	}

	// ================================================================== stored data

	/**
	 * The option.
	 *
	 * @return array{profile: array<string, mixed>|null, pages: array<string, int>, current: array<string, array<string, mixed>>}
	 */
	public static function data(): array {
		if ( null === self::$data ) {
			$raw        = (array) get_option( self::OPTION, array() );
			self::$data = array(
				'profile' => isset( $raw['profile'] ) && is_array( $raw['profile'] ) ? $raw['profile'] : null,
				'pages'   => isset( $raw['pages'] ) && is_array( $raw['pages'] ) ? array_map( 'intval', $raw['pages'] ) : array(),
				'current' => isset( $raw['current'] ) && is_array( $raw['current'] ) ? $raw['current'] : array(),
			);
		}
		return self::$data;
	}

	/**
	 * Store the option.
	 *
	 * @param array<string, mixed> $data Whole option.
	 */
	private static function save_data( array $data ): void {
		update_option( self::OPTION, $data, false );
		self::$data = null;
	}

	/** Forget the memo (tests, and after the option was changed behind our back). */
	public static function reset(): void {
		self::$data = null;
	}

	/** Is WooCommerce active? */
	public static function has_shop(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * The profile in use: the stored one over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function profile(): array {
		$stored = self::data()['profile'];
		return array_merge( LegalProfile::defaults( self::has_shop() ), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * The profile for the form: when nothing was saved yet, the fields we can guess are filled in (the site name, the
	 * administrator e-mail, the store address of WooCommerce). They are only suggestions until the person saves.
	 *
	 * @return array<string, mixed>
	 */
	public static function profile_for_form(): array {
		$profile = self::profile();
		if ( null !== self::data()['profile'] ) {
			return $profile;
		}
		$profile['name']  = (string) get_bloginfo( 'name' );
		$profile['email'] = (string) get_option( 'admin_email', '' );
		if ( self::has_shop() ) {
			$parts = array_filter(
				array(
					(string) get_option( 'woocommerce_store_address', '' ),
					trim( (string) get_option( 'woocommerce_store_postcode', '' ) . ' ' . (string) get_option( 'woocommerce_store_city', '' ) ),
				)
			);
			$profile['address'] = implode( ', ', $parts );
		}
		$report = Report::site( self::language( $profile ) );
		foreach ( array( 'statistics' => 'analytics', 'marketing' => 'marketing' ) as $category => $purpose ) {
			if ( ! empty( $report['categories'][ $category ] ) ) {
				$profile['purposes'][] = $purpose;
			}
		}
		$profile['purposes'] = array_values( array_intersect( LegalProfile::purpose_keys(), $profile['purposes'] ) );
		return $profile;
	}

	/**
	 * Save the wizard data. Nothing is saved when anything is wrong.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{ok: bool, errors: array<string, string>}
	 */
	public static function save_profile( array $input ): array {
		$result = LegalProfile::sanitize( $input, self::profile_for_form() );
		if ( array() !== $result['errors'] ) {
			return array(
				'ok'     => false,
				'errors' => $result['errors'],
			);
		}
		$data            = self::data();
		$data['profile'] = $result['values'];
		self::save_data( $data );
		return array(
			'ok'     => true,
			'errors' => array(),
		);
	}

	/**
	 * Language of the documents.
	 *
	 * @param array<string, mixed> $profile Profile.
	 */
	public static function language( array $profile ): string {
		$chosen = (string) ( $profile['language'] ?? 'auto' );
		if ( 'cs' === $chosen || 'en' === $chosen ) {
			return $chosen;
		}
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			// The administrator's own language must not decide what the public pages say: use the site language.
			$locale = (string) get_option( 'WPLANG', '' );
			return Cookies::lang( '' === $locale ? 'en_US' : $locale );
		}
		return Cookies::lang( get_locale() );
	}

	// ================================================================== pages

	/**
	 * Addresses of the published legal pages.
	 *
	 * @return array<string, string> doc => URL
	 */
	public static function page_urls(): array {
		$urls = array();
		foreach ( self::data()['pages'] as $doc => $id ) {
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				$url = get_permalink( $id );
				if ( is_string( $url ) ) {
					$urls[ $doc ] = $url;
				}
			}
		}
		return $urls;
	}

	/**
	 * What the admin screen shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$profile = self::profile_for_form();
		$lang    = self::language( $profile );
		$report  = Report::site( $lang );
		$data    = self::data();
		$admin   = Cookies::lang( (string) get_user_locale() );

		$docs = array();
		foreach ( LegalProfile::DOCS as $doc ) {
			$available = 'terms' !== $doc || ! empty( $profile['has_eshop'] );
			$current   = $data['current'][ $doc ] ?? null;
			$page_id   = (int) ( $data['pages'][ $doc ] ?? 0 );
			$post      = $page_id > 0 ? get_post( $page_id ) : null;
			$page      = null;
			if ( $post instanceof \WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
				$page = array(
					'id'       => $page_id,
					'status'   => $post->post_status,
					'url'      => (string) get_permalink( $page_id ),
					'edit_url' => (string) get_edit_post_link( $page_id, 'raw' ),
					'title'    => get_the_title( $page_id ),
				);
			}
			$missing = LegalProfile::missing( $doc, $profile );
			$changed = false;
			if ( null !== $current && $available && array() === $missing ) {
				$changed = LegalBuilder::hash( LegalBuilder::html( $doc, $lang, $profile, $report ) ) !== (string) $current['hash'];
			}
			$docs[] = array(
				'doc'       => $doc,
				'title'     => LegalBuilder::title( $doc, $lang ),
				'available' => $available,
				'page'      => $page,
				'version'   => null === $current ? 0 : (int) $current['ver'],
				'effective' => null === $current ? 0 : (int) $current['effective'],
				'changed'   => $changed,
				'missing'   => $missing,
				'versions'  => count( self::versions( $doc ) ),
			);
		}

		$purposes = array();
		foreach ( LegalProfile::purposes() as $key => $def ) {
			$purposes[] = array(
				'key'       => $key,
				'label'     => (string) $def[ $admin ]['title'],
				'retention' => (string) $def[ $lang ]['retention'],
				'suggested' => ( 'analytics' === $key && ! empty( $report['categories']['statistics'] ) ) || ( 'marketing' === $key && ! empty( $report['categories']['marketing'] ) ),
			);
		}

		$kinds = array(
			'carrier'    => 'cs' === $admin ? 'Dopravce' : 'Carrier',
			'payment'    => 'cs' === $admin ? 'Platební služba' : 'Payment service',
			'accounting' => 'cs' === $admin ? 'Účetní a daňové služby' : 'Accounting and tax',
			'hosting'    => 'cs' === $admin ? 'Hosting a správa webu' : 'Hosting and site administration',
			'email'      => 'cs' === $admin ? 'Rozesílání e-mailů' : 'E-mail delivery',
			'other'      => 'cs' === $admin ? 'Další poskytovatel' : 'Other provider',
		);

		// Providers found by the scan (without the ones typed in the wizard), as plain lines.
		$auto     = LegalBuilder::recipients( array_merge( $profile, array( 'recipients' => array() ) ), $report, $lang )['html'];
		$detected = '' === $auto ? array() : array_values( array_filter( array_map( 'trim', explode( '|', wp_strip_all_tags( str_replace( '</li>', '|', $auto ) ) ) ) ) );

		return array(
			'stored'         => null !== $data['profile'],
			// Before the first save the name, e-mail and address are only guesses; the wizard may replace them by what LegalDetect finds.
			'guessed'        => null !== $data['profile'] ? array() : array_values( array_filter( array( 'name', 'email', 'address' ), static fn( string $k ): bool => '' !== trim( (string) $profile[ $k ] ) ) ),
			'profile'        => $profile,
			'language'       => $lang,
			'purposes'       => $purposes,
			'recipient_kinds' => $kinds,
			'detected'        => $detected,
			'shop'           => self::has_shop(),
			'unclassified'   => $report['unclassified'],
			'docs'           => $docs,
			'privacy_page'   => (int) get_option( 'wp_page_for_privacy_policy', 0 ),
		);
	}

	/**
	 * Make or update the pages and the versions.
	 *
	 * @param list<string> $docs  Documents to publish.
	 * @param bool         $live  Publish the pages (otherwise a new page is a draft and an existing one keeps its status).
	 * @return array{ok: bool, errors: array<string, array<string, string>>, results: array<string, array<string, mixed>>}
	 */
	public static function publish( array $docs, bool $live ): array {
		$docs    = array_values( array_intersect( LegalProfile::DOCS, $docs ) );
		$profile = self::profile();
		$errors  = array();
		if ( null === self::data()['profile'] ) {
			$errors['profile'] = array( 'profile' => __( 'Save the details of the controller first.', 'ux-studio' ) );
		}
		foreach ( $docs as $doc ) {
			$missing = LegalProfile::missing( $doc, $profile );
			if ( array() !== $missing ) {
				$errors[ $doc ] = $missing;
			}
		}
		if ( array() === $docs ) {
			$errors['docs'] = array( 'docs' => __( 'Choose at least one document.', 'ux-studio' ) );
		}
		if ( array() !== $errors ) {
			return array(
				'ok'      => false,
				'errors'  => $errors,
				'results' => array(),
			);
		}

		$lang    = self::language( $profile );
		$report  = Report::site( $lang );
		$data    = self::data();
		$results = array();
		foreach ( $docs as $doc ) {
			$html    = LegalBuilder::html( $doc, $lang, $profile, $report );
			$hash    = LegalBuilder::hash( $html );
			$current = $data['current'][ $doc ] ?? null;
			$created = false;
			if ( null === $current || (string) $current['hash'] !== $hash || (string) ( $current['lang'] ?? '' ) !== $lang ) {
				$version = self::store_version( $doc, $lang, $html, $hash );
				if ( null !== $version ) {
					$data['current'][ $doc ] = $version;
					$created                 = true;
				}
			}
			$page_id = self::ensure_page( $doc, $lang, $live, (int) ( $data['pages'][ $doc ] ?? 0 ) );
			if ( $page_id > 0 ) {
				$data['pages'][ $doc ] = $page_id;
			}
			$results[ $doc ] = array(
				'created' => $created,
				'version' => (int) ( $data['current'][ $doc ]['ver'] ?? 0 ),
				'page_id' => $page_id,
			);
		}
		self::save_data( $data );
		self::link_pages();
		return array(
			'ok'      => true,
			'errors'  => array(),
			'results' => $results,
		);
	}

	/**
	 * Create the page when it does not exist (or was trashed), publish it when asked. A page whose content the owner has
	 * replaced by their own text is left alone.
	 *
	 * @param string $doc     Document.
	 * @param string $lang    cs | en.
	 * @param bool   $live    Publish.
	 * @param int    $page_id Page id we know, or 0.
	 */
	private static function ensure_page( string $doc, string $lang, bool $live, int $page_id ): int {
		$post = $page_id > 0 ? get_post( $page_id ) : null;
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			$new = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => $live ? 'publish' : 'draft',
					'post_title'   => LegalBuilder::title( $doc, $lang ),
					'post_name'    => LegalBuilder::slug( $doc, $lang ),
					'post_content' => wp_slash( self::page_content( $doc ) ),
				),
				true
			);
			return is_wp_error( $new ) ? 0 : (int) $new;
		}
		if ( $live && 'publish' !== $post->post_status && in_array( $post->post_status, array( 'draft', 'pending', 'private', 'future' ), true ) ) {
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'publish',
				)
			);
		}
		return (int) $post->ID;
	}

	/**
	 * Use an existing page as the page of a document (the migration from Complianz keeps the address of its pages).
	 * Only when no usable page is known yet.
	 *
	 * @param string $doc Document.
	 * @param int    $id  Page id.
	 * @return bool Whether the page was adopted.
	 */
	public static function adopt_page( string $doc, int $id ): bool {
		if ( ! in_array( $doc, LegalProfile::DOCS, true ) || $id <= 0 ) {
			return false;
		}
		$data = self::data();
		$cur  = (int) ( $data['pages'][ $doc ] ?? 0 );
		$post = $cur > 0 ? get_post( $cur ) : null;
		if ( $cur === $id ) {
			return true;
		}
		if ( $post instanceof \WP_Post && 'trash' !== $post->post_status ) {
			return false;
		}
		$data['pages'][ $doc ] = $id;
		self::save_data( $data );
		return true;
	}

	/**
	 * Forget an adopted page (undo of adopt_page()).
	 *
	 * @param string $doc Document.
	 * @param int    $id  Page id.
	 */
	public static function release_page( string $doc, int $id ): void {
		$data = self::data();
		if ( (int) ( $data['pages'][ $doc ] ?? 0 ) === $id ) {
			unset( $data['pages'][ $doc ] );
			self::save_data( $data );
		}
	}

	/**
	 * What a legal page contains: one shortcode block.
	 *
	 * @param string $doc Document.
	 */
	public static function page_content( string $doc ): string {
		return '<!-- wp:shortcode -->[uxstudio_legal doc="' . $doc . '"]<!-- /wp:shortcode -->';
	}

	/**
	 * Tell WordPress, WooCommerce and the banner where the pages are, but only where nothing is set yet and the page is
	 * published.
	 */
	private static function link_pages(): void {
		$urls  = self::page_urls();
		$pages = self::data()['pages'];
		$id    = static fn( string $doc ): int => isset( $urls[ $doc ] ) ? (int) ( $pages[ $doc ] ?? 0 ) : 0;
		if ( $id( 'privacy' ) > 0 && ! self::page_exists( (int) get_option( 'wp_page_for_privacy_policy', 0 ) ) ) {
			update_option( 'wp_page_for_privacy_policy', $id( 'privacy' ) );
		}
		if ( $id( 'terms' ) > 0 && self::has_shop() && ! self::page_exists( (int) get_option( 'woocommerce_terms_page_id', 0 ) ) ) {
			update_option( 'woocommerce_terms_page_id', $id( 'terms' ) );
		}
		$module = Inventory::module();
		if ( null !== $module && '' === trim( (string) $module->settings_value( 'policy_url', '' ) ) && isset( $urls['cookies'] ) ) {
			$module->save_settings( array( 'policy_url' => $urls['cookies'] ) );
		}
	}

	/**
	 * Is there a published page with this id?
	 *
	 * @param int $id Page id.
	 */
	private static function page_exists( int $id ): bool {
		return $id > 0 && 'publish' === get_post_status( $id );
	}

	// ================================================================== versions

	/**
	 * Save a snapshot as the next version of a document.
	 *
	 * @param string $doc  Document.
	 * @param string $lang Language.
	 * @param string $html Document HTML (cookie button as a token).
	 * @param string $hash Hash of the text.
	 * @return array<string, mixed>|null The new "current" entry, or null when the snapshot could not be saved.
	 */
	private static function store_version( string $doc, string $lang, string $html, string $hash ): ?array {
		$last = 0;
		foreach ( self::versions( $doc ) as $row ) {
			$last = max( $last, (int) $row['ver'] );
		}
		$ver  = $last + 1;
		$time = time();
		$id   = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'publish',
				'post_title'   => $doc . ' v' . $ver,
				'post_content' => wp_slash( $html ),
				'meta_input'   => array(
					'_uxs_doc'       => $doc,
					'_uxs_ver'       => $ver,
					'_uxs_hash'      => $hash,
					'_uxs_lang'      => $lang,
					'_uxs_effective' => $time,
				),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return null;
		}
		return array(
			'ver'       => $ver,
			'hash'      => $hash,
			'effective' => $time,
			'post_id'   => (int) $id,
			'lang'      => $lang,
		);
	}

	/**
	 * All versions of a document, newest first (without the text).
	 *
	 * @param string $doc Document.
	 * @return list<array{id: int, ver: int, hash: string, lang: string, effective: int}>
	 */
	public static function versions( string $doc ): array {
		$ids = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => 'any',
				'numberposts'      => 200,
				'fields'           => 'ids',
				'meta_key'         => '_uxs_ver', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a small hidden post type, at most a few dozen rows.
				'orderby'          => 'meta_value_num',
				'order'            => 'DESC',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_uxs_doc',
						'value' => $doc,
					),
				),
				'suppress_filters' => true,
			)
		);
		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = array(
				'id'        => (int) $id,
				'ver'       => (int) get_post_meta( (int) $id, '_uxs_ver', true ),
				'hash'      => (string) get_post_meta( (int) $id, '_uxs_hash', true ),
				'lang'      => (string) get_post_meta( (int) $id, '_uxs_lang', true ),
				'effective' => (int) get_post_meta( (int) $id, '_uxs_effective', true ),
			);
		}
		return $rows;
	}

	/**
	 * One archived version with its text (cookie button removed).
	 *
	 * @param int $id Version post id.
	 * @return array<string, mixed>|null
	 */
	public static function version( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return null;
		}
		return array(
			'id'        => (int) $post->ID,
			'doc'       => (string) get_post_meta( $id, '_uxs_doc', true ),
			'ver'       => (int) get_post_meta( $id, '_uxs_ver', true ),
			'hash'      => (string) get_post_meta( $id, '_uxs_hash', true ),
			'lang'      => (string) get_post_meta( $id, '_uxs_lang', true ),
			'effective' => (int) get_post_meta( $id, '_uxs_effective', true ),
			'html'      => wp_kses_post( str_replace( LegalBuilder::COOKIE_BUTTON, '', (string) $post->post_content ) ),
		);
	}

	/**
	 * The version in force for a document, or null before the first one.
	 *
	 * @param string $doc Document.
	 * @return array<string, mixed>|null
	 */
	public static function current( string $doc ): ?array {
		$row = self::data()['current'][ $doc ] ?? null;
		return is_array( $row ) ? $row : null;
	}

	// ================================================================== output

	/**
	 * [uxstudio_legal doc="cookies|privacy|terms" lang="cs|en"]
	 *
	 * @param mixed $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'doc'  => '',
				'lang' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'uxstudio_legal'
		);
		$doc  = (string) $atts['doc'];
		if ( ! in_array( $doc, LegalProfile::DOCS, true ) ) {
			return '';
		}
		$profile = self::profile();
		if ( null === self::data()['profile'] ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Fill in the details of the controller in UX Studio, Cookie Consent, Legal pages.', 'ux-studio' ) . '</p>' : '';
		}
		$lang = in_array( $atts['lang'], array( 'cs', 'en' ), true ) ? (string) $atts['lang'] : self::language( $profile );
		return self::output( $doc, $lang, $profile );
	}

	/**
	 * [uxstudio_cookie_table lang="cs|en"]: only the tables of services and cookies, for a page of the owner's own.
	 *
	 * @param mixed $atts Attributes.
	 */
	public static function shortcode_cookie_table( $atts ): string {
		$atts = shortcode_atts( array( 'lang' => '' ), is_array( $atts ) ? $atts : array(), 'uxstudio_cookie_table' );
		$lang = in_array( $atts['lang'], array( 'cs', 'en' ), true ) ? (string) $atts['lang'] : self::language( self::profile() );
		return wp_kses_post( LegalBuilder::cookie_tables( Report::site( $lang ), $lang ) );
	}

	/**
	 * The document as shown on the page: version line, text, the notice for the owner as an HTML comment, the archive.
	 *
	 * @param string               $doc     Document.
	 * @param string               $lang    cs | en.
	 * @param array<string, mixed> $profile Profile.
	 */
	public static function output( string $doc, string $lang, array $profile ): string {
		$cs      = 'cs' === $lang;
		$body    = LegalBuilder::html( $doc, $lang, $profile );
		$body    = str_replace( LegalBuilder::COOKIE_BUTTON, do_shortcode( '[uxstudio_cookie_settings]' ), $body );
		$current = self::current( $doc );
		$date    = static fn( int $ts ): string => wp_date( (string) get_option( 'date_format', 'j. n. Y' ), $ts );

		$out = '<div class="uxs-legal uxs-legal-' . esc_attr( $doc ) . '">';
		if ( null !== $current ) {
			$out .= '<p class="uxs-legal-version">' . esc_html(
				$cs
					? sprintf( 'Verze %1$d, účinná od %2$s.', (int) $current['ver'], $date( (int) $current['effective'] ) )
					: sprintf( 'Version %1$d, in force from %2$s.', (int) $current['ver'], $date( (int) $current['effective'] ) )
			) . '</p>';
		}
		$out .= $body;
		$out .= self::archive_html( $doc, $cs, $date );
		$out .= '</div>';
		// Not visible to visitors, kept in the page source for whoever maintains the site. It stays out of the version hash.
		$out .= '<!-- ' . ( $cs
			? 'Text vygenerovaný modulem Cookie Consent (UX Studio). Nejde o právní službu: před zveřejněním ho nechte zkontrolovat právníkem. Odpovědnost za obsah nese provozovatel webu.'
			: 'Text generated by the Cookie Consent module (UX Studio). It is not legal advice: have a lawyer check it before it is published. The site operator is responsible for the content.' ) . ' -->';
		return $out;
	}

	/**
	 * Older versions as a closed list at the bottom of the page.
	 *
	 * @param string   $doc  Document.
	 * @param bool     $cs   Czech.
	 * @param callable $date Date formatter.
	 */
	private static function archive_html( string $doc, bool $cs, callable $date ): string {
		$rows = self::versions( $doc );
		array_shift( $rows ); // The newest is the one on the page.
		if ( array() === $rows ) {
			return '';
		}
		$out  = '<details class="uxs-legal-archive"><summary>' . esc_html( $cs ? 'Dřívější verze' : 'Earlier versions' ) . '</summary>';
		$next = (int) ( self::current( $doc )['effective'] ?? 0 );
		foreach ( $rows as $row ) {
			$full = self::version( $row['id'] );
			if ( null === $full ) {
				continue;
			}
			$until = $next > 0 ? ( $cs ? ' do ' : ' until ' ) . $date( $next ) : '';
			$out  .= '<details><summary>' . esc_html(
				$cs
					? sprintf( 'Verze %1$d, platila od %2$s%3$s', $row['ver'], $date( $row['effective'] ), $until )
					: sprintf( 'Version %1$d, in force from %2$s%3$s', $row['ver'], $date( $row['effective'] ), $until )
			) . '</summary>' . $full['html'] . '</details>';
			$next = $row['effective'];
		}
		return $out . '</details>';
	}

	/**
	 * A preview for the admin: the live text (cookie button replaced by a plain note), checked with kses.
	 *
	 * @param string $doc Document.
	 */
	public static function preview( string $doc ): string {
		$profile = self::profile_for_form();
		$lang    = self::language( $profile );
		$html    = LegalBuilder::html( $doc, $lang, $profile );
		return wp_kses_post( str_replace( LegalBuilder::COOKIE_BUTTON, '[' . ( 'cs' === $lang ? 'tlačítko Nastavení cookies' : 'Cookie settings button' ) . ']', $html ) );
	}

	// ================================================================== WooCommerce orders

	/**
	 * Store the terms version in force on a new order (classic checkout).
	 *
	 * @param mixed $order Order.
	 */
	public static function stamp_order( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$stamp = self::order_stamp();
		if ( null === $stamp || '' !== (string) $order->get_meta( self::ORDER_VERSION ) ) {
			return;
		}
		$order->update_meta_data( self::ORDER_VERSION, $stamp['ver'] );
		$order->update_meta_data( self::ORDER_HASH, $stamp['hash'] );
		$order->update_meta_data( self::ORDER_EFFECTIVE, $stamp['effective'] );
		$order->update_meta_data( self::ORDER_POST, $stamp['post_id'] );
	}

	/**
	 * Same for the block checkout (Store API); the order is saved right after this hook, the meta is saved here too so
	 * it does not depend on that.
	 *
	 * @param mixed $order Order.
	 */
	public static function stamp_order_blocks( $order ): void {
		self::stamp_order( $order );
		if ( is_object( $order ) && method_exists( $order, 'save_meta_data' ) ) {
			$order->save_meta_data();
		}
	}

	/**
	 * The terms version a new order gets: only while the terms page is published.
	 *
	 * @return array{ver: int, hash: string, effective: int, post_id: int}|null
	 */
	public static function order_stamp(): ?array {
		$current = self::current( 'terms' );
		$page    = (int) ( self::data()['pages']['terms'] ?? 0 );
		if ( null === $current || ! self::page_exists( $page ) ) {
			return null;
		}
		return array(
			'ver'       => (int) $current['ver'],
			'hash'      => (string) $current['hash'],
			'effective' => (int) $current['effective'],
			'post_id'   => (int) $current['post_id'],
		);
	}

	/**
	 * Show the terms version in the order screen.
	 *
	 * @param mixed $order Order.
	 */
	public static function show_order_terms( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$ver = (string) $order->get_meta( self::ORDER_VERSION );
		echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Terms and conditions:', 'ux-studio' ) . '</strong> ';
		if ( '' === $ver ) {
			echo esc_html__( 'no version was stored with this order.', 'ux-studio' ) . '</p>';
			return;
		}
		$effective = (int) $order->get_meta( self::ORDER_EFFECTIVE );
		$hash      = (string) $order->get_meta( self::ORDER_HASH );
		echo esc_html(
			sprintf(
				/* translators: 1: version number, 2: date the version came into force, 3: first characters of the text hash. */
				__( 'version %1$s, in force from %2$s, fingerprint %3$s.', 'ux-studio' ),
				$ver,
				wp_date( (string) get_option( 'date_format', 'j. n. Y' ), $effective ),
				substr( $hash, 0, 12 )
			)
		) . '</p>';
	}
}
