<?php
/**
 * Source: Complianz (free and premium).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Activatable;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\PluginInfo;
use UxStudio\Core\Migration\Staging;
use UxStudio\Modules\Consent\ComplianzMap;
use UxStudio\Modules\Consent\Legal;
use UxStudio\Modules\Consent\LegalProfile;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 42.7 / 42.16. Target module `consent`.
 *
 *  - Settings: option `cmplz_options` (consent mode, expiry, blocker, company data), the default row of the table
 *    `{prefix}cmplz_cookiebanners` (texts, colours, position), the Script Center option `complianz_options_custom-scripts`
 *    (blocking rules). The mapping itself is the pure `ComplianzMap`.
 *  - Legal profile of phase F4: name, address, e-mail and phone are copied into `uxstudio_consent_legal` where the profile is blank.
 *  - Pages and posts: Complianz shortcodes and blocks are rewritten at the SWITCH (kind `page`, Activatable). The page, its
 *    slug and its address stay; only the content changes. The original content is kept in the post meta
 *    `_uxs_cz_original` (and in a WordPress revision) and written back by deactivate(), so undoing the switch restores the page.
 *  - Returning visitors: `legacy_prefix` and `legacy_policy_id` make consent.js read the old `cmplz_*` cookies once.
 *  - Google Analytics that Complianz inserts itself moves to Pixel & Tag Manager (prepared like every other value).
 *
 * Everything that would silently stop working after the switch (scripts added in the Script Center, a Google Tag Manager
 * container or another analytics tool that only Complianz inserts) is a failing test of the safe preview, which the admin
 * has to accept explicitly ("switch anyway").
 */
final class ComplianzSource extends AbstractSource implements Activatable {

	private const MODULE = 'consent';

	/** Post meta: content before the conversion, and the hash of what was written. */
	private const META_ORIGINAL = '_uxs_cz_original';
	private const META_WRITTEN  = '_uxs_cz_written';
	private const META_DOCS     = '_uxs_cz_docs';

	/** @var array<string, mixed>|null Test seam: replaces what raw() reads from the database. */
	public static ?array $raw_override = null;

	/** @var array<string, mixed>|null */
	private ?array $raw = null;

	/** @var array<string, mixed>|null */
	private ?array $result = null;

	/** @var list<array<string, mixed>>|null */
	private ?array $items = null;

	public function forget(): void {
		$this->raw    = null;
		$this->result = null;
		$this->items  = null;
		parent::forget();
	}

	protected function option_names(): array {
		return array( 'cmplz_options', 'complianz_options_custom-scripts' );
	}

	protected function data_kind(): string {
		return 'page';
	}

	protected function data_label(): string {
		return __( 'Pages and posts with Complianz shortcodes and blocks', 'ux-studio' );
	}

	public function extra_modules(): array {
		return '' !== (string) ( $this->analysis()['ga_target'] ?? '' ) ? array( 'pixel-tag-manager' ) : array();
	}

	/* ── Reading ── */

	/**
	 * What Complianz left in the database.
	 *
	 * @return array<string, mixed>
	 */
	private function raw(): array {
		if ( null !== self::$raw_override ) {
			return self::$raw_override;
		}
		if ( null !== $this->raw ) {
			return $this->raw;
		}
		global $wpdb;
		$opt = $this->opt( 'cmplz_options' );
		$scr = $this->opt( 'complianz_options_custom-scripts' );

		$banner = null;
		$banners = 0;
		$table  = $wpdb->prefix . 'cmplz_cookiebanners';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB
			$row     = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY `default` DESC, ID ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
			$banner  = is_array( $row ) ? $row : null;
			$banners = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		$posts = $wpdb->get_results(
			"SELECT ID, post_type, post_status, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','pending','future') AND post_type NOT IN ('revision','nav_menu_item','customize_changeset','oembed_cache','uxs_legal_version') AND (post_content LIKE '%[cmplz-%' OR post_content LIKE '%wp:complianz/%') ORDER BY ID ASC LIMIT 500", // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$prefix = 'cmplz_';
		if ( function_exists( 'is_multisite' ) && is_multisite() && is_main_site() && empty( $opt['set_cookies_on_root'] ) ) {
			$prefix = 'cmplz_rt_';
		}

		$this->raw = array(
			'opt'       => is_array( $opt ) ? $opt : array(),
			'scripts'   => is_array( $scr ) ? $scr : array(),
			'banner'    => $banner,
			'banners'   => $banners,
			'policy_id' => max( 0, (int) get_option( 'complianz_active_policy_id', 1 ) ),
			'prefix'    => $prefix,
			'posts'     => is_array( $posts ) ? $posts : array(),
		);
		return $this->raw;
	}

	/**
	 * The legal profile as it will be: what is stored, with blanks filled from Complianz.
	 *
	 * @param array<string, string> $fill Fields from Complianz.
	 * @return array{profile: array<string, mixed>, errors: array<string, string>, existing: bool}
	 */
	private function profile_with( array $fill ): array {
		$stored   = get_option( Legal::OPTION, array() );
		$existing = is_array( $stored ) && isset( $stored['profile'] ) && is_array( $stored['profile'] );
		$base     = array_merge( LegalProfile::defaults( class_exists( 'WooCommerce' ) ), $existing ? $stored['profile'] : array() );
		$in       = array();
		foreach ( $fill as $key => $value ) {
			if ( '' === trim( (string) ( $base[ $key ] ?? '' ) ) ) {
				$in[ $key ] = $value;
			}
		}
		$res = LegalProfile::sanitize( $in, $base );
		return array(
			'profile'  => $res['values'],
			'errors'   => $res['errors'],
			'existing' => $existing,
		);
	}

	/**
	 * Everything computed from the data once: the pure mapping, the profile, which documents are ready, the items.
	 *
	 * @return array<string, mixed>
	 */
	private function analysis(): array {
		if ( null !== $this->result ) {
			return $this->result;
		}
		$r    = $this->raw();
		$post = $this->pages_by_doc();
		$map  = ComplianzMap::settings(
			(array) $r['opt'],
			is_array( $r['banner'] ?? null ) ? $r['banner'] : null,
			(array) $r['scripts'],
			array(
				'policy_id'   => (int) ( $r['policy_id'] ?? 0 ),
				'cookie_page' => $post['cookies']['url'] ?? '',
			)
		);
		$prof  = $this->profile_with( $map['profile'] );
		$ready = array();
		foreach ( LegalProfile::DOCS as $doc ) {
			$ready[ $doc ] = array() === LegalProfile::missing( $doc, $prof['profile'] );
		}

		$items = array();
		$left  = array();
		foreach ( (array) $r['posts'] as $row ) {
			$conv = ComplianzMap::convert_content( (string) $row['post_content'], $ready );
			$title = '' !== trim( (string) $row['post_title'] ) ? (string) $row['post_title'] : '#' . (int) $row['ID'];
			if ( $conv['changed'] ) {
				$items[] = array(
					'key'       => 'post:' . (int) $row['ID'],
					'label'     => $title,
					'id'        => (int) $row['ID'],
					'converted' => $conv['converted'],
				);
			}
			foreach ( $conv['left'] as $what ) {
				$left[] = $title . ' (' . $what . ')';
			}
		}
		$this->result = array(
			'map'       => $map,
			'profile'   => $prof,
			'ready'     => $ready,
			'items'     => $items,
			'left'      => $left,
			'ga_target' => '',
		);
		// Google Analytics goes to Pixel & Tag Manager when that module has no ID of its own yet.
		if ( '' !== $map['ga'] ) {
			$ptm = get_option( 'uxstudio_pixel_tag_manager', array() );
			$cur = is_array( $ptm ) ? trim( (string) ( $ptm['google_analytics'] ?? '' ) ) : '';
			if ( '' === $cur || $cur === $map['ga'] ) {
				$this->result['ga_target'] = $map['ga'];
			}
		}
		return $this->result;
	}

	/**
	 * Published pages that hold one of our documents after the conversion: doc => id and URL (only for the cookie page the URL is needed).
	 *
	 * @return array<string, array{id: int, url: string}>
	 */
	private function pages_by_doc(): array {
		$out = array();
		foreach ( (array) $this->raw()['posts'] as $row ) {
			if ( 'page' !== $row['post_type'] || 'publish' !== $row['post_status'] ) {
				continue;
			}
			$conv = ComplianzMap::convert_content( (string) $row['post_content'], array( 'cookies' => true, 'privacy' => true ) );
			if ( 1 === preg_match_all( '/\[uxstudio_legal doc="(cookies|privacy)"\]/', $conv['content'], $m ) && $conv['changed'] ) {
				foreach ( $m[1] as $doc ) {
					if ( ! isset( $out[ $doc ] ) ) {
						$url          = function_exists( 'get_permalink' ) ? get_permalink( (int) $row['ID'] ) : '';
						$out[ $doc ] = array(
							'id'  => (int) $row['ID'],
							'url' => is_string( $url ) ? $url : '',
						);
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Things that would silently stop working after the switch.
	 *
	 * @return list<string>
	 */
	private function blockers(): array {
		$a   = $this->analysis();
		$map = $a['map'];
		$out = array();
		foreach ( $map['add_scripts'] as $s ) {
			$code  = trim( preg_replace( '/\s+/', ' ', $s['code'] ) ?? '' );
			$out[] = sprintf(
				/* translators: 1: name of the script, 2: category, 3: the code. */
				__( 'Script "%1$s" (category %2$s) was added in the Script Center of Complianz and has no equivalent here, so it will stop running. Code: %3$s', 'ux-studio' ),
				'' !== $s['name'] ? $s['name'] : '-',
				$s['category'],
				'' !== $code ? ( strlen( $code ) > 400 ? substr( $code, 0, 400 ) . '...' : $code ) : '-'
			);
		}
		if ( '' !== $map['gtm'] && ! $this->container_inserted_elsewhere() ) {
			$out[] = sprintf(
				/* translators: %s: container id. */
				__( 'Complianz inserts the Google Tag Manager container %s itself and no other active plugin (GTM Kit, GTM4WP) inserts it. Without a replacement all measurement through this container stops at the switch. Add the container with GTM Kit or in the theme first.', 'ux-studio' ),
				$map['gtm']
			);
		}
		if ( '' !== $map['other_stats'] ) {
			$out[] = sprintf(
				/* translators: %s: name of the analytics tool. */
				__( 'Complianz inserts the analytics tool "%s" itself. UX Studio cannot insert it, so the measurement stops at the switch unless something else inserts it.', 'ux-studio' ),
				$map['other_stats']
			);
		}
		return $out;
	}

	/**
	 * Does another active plugin insert a GTM container?
	 */
	private function container_inserted_elsewhere(): bool {
		foreach ( array( 'gtm-kit', 'duracelltomi-google-tag-manager' ) as $slug ) {
			$info = PluginInfo::find( $slug );
			if ( $info && ! empty( $info['active'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ── Mapping ── */

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$r = $this->raw();
		if ( ! $r['opt'] && ! $r['banner'] && ! $r['posts'] ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$a   = $this->analysis();
		$res = $a['map'];

		$labels = array(
			'consent_mode'     => __( 'Google Consent Mode v2', 'ux-studio' ),
			'expiry_days'      => __( 'Remember the choice for (days)', 'ux-studio' ),
			'block_scripts'    => __( 'Hold back tracking scripts until the visitor agrees', 'ux-studio' ),
			'block_iframes'    => __( 'Replace embedded videos and maps by a "Load content" box', 'ux-studio' ),
			'display_to'       => __( 'Show the banner to', 'ux-studio' ),
			'policy_url'       => __( 'Cookie policy address', 'ux-studio' ),
			'title'            => __( 'Title', 'ux-studio' ),
			'message'          => __( 'Message', 'ux-studio' ),
			'btn_accept'       => __( 'Accept button', 'ux-studio' ),
			'btn_reject'       => __( 'Reject button', 'ux-studio' ),
			'btn_settings'     => __( 'Customize button', 'ux-studio' ),
			'btn_save'         => __( 'Save button', 'ux-studio' ),
			'layout'           => __( 'Layout', 'ux-studio' ),
			'position'         => __( 'Side of the box', 'ux-studio' ),
			'color_bg'         => __( 'Background', 'ux-studio' ),
			'color_text'       => __( 'Text', 'ux-studio' ),
			'color_accent'     => __( 'Buttons', 'ux-studio' ),
			'color_accent_text' => __( 'Button text', 'ux-studio' ),
			'radius'           => __( 'Corner radius (px)', 'ux-studio' ),
			'show_reopen'      => __( 'Show a small button to change the choice later', 'ux-studio' ),
			'custom_rules'     => __( 'Own rules', 'ux-studio' ),
		);
		foreach ( $res['settings'] as $key => $value ) {
			$m->set( self::MODULE, $key, $value, $labels[ $key ] ?? $key, 'cmplz:' . $key );
		}
		// Returning visitors: read the old consent cookies once.
		$m->set( self::MODULE, 'legacy_prefix', (string) $r['prefix'], __( 'Take over choices from the old banner (cookie name prefix)', 'ux-studio' ), 'cmplz:cookies' );
		$m->set( self::MODULE, 'legacy_policy_id', (int) $r['policy_id'], __( 'Policy number of the old banner', 'ux-studio' ), 'cmplz:policy_id' );
		$m->note( __( 'Visitors who already made a choice in Complianz keep it: the first time they load a page, their Complianz cookies are read once and turned into the choice of this banner (only for the same policy number and a dismissed banner). Switch it off by clearing the cookie prefix in the module.', 'ux-studio' ) );

		if ( '' !== $a['ga_target'] ) {
			$m->set( 'pixel-tag-manager', 'google_analytics', $a['ga_target'], __( 'Google Analytics', 'ux-studio' ), 'cmplz:ua_code' );
			$m->warn(
				sprintf(
					/* translators: %s: Google Analytics id. */
					__( 'Complianz inserts Google Analytics (%s) itself. The ID is moved to the Pixel & Tag Manager module, which inserts it after the switch (held back until consent, like the rest).', 'ux-studio' ),
					$a['ga_target']
				)
			);
		} elseif ( '' !== $res['ga'] ) {
			$m->warn(
				sprintf(
					/* translators: %s: Google Analytics id. */
					__( 'Complianz inserts Google Analytics (%s) itself. Pixel & Tag Manager already has a different ID, so nothing was changed there; check that this ID is still measured after the switch.', 'ux-studio' ),
					$res['ga']
				)
			);
		}

		if ( $res['profile'] ) {
			$p = $a['profile'];
			$m->option(
				self::MODULE,
				Legal::OPTION,
				array( 'profile' => $p['profile'] ),
				array( 'profile' => __( 'Details of the controller for the legal pages', 'ux-studio' ) ),
				'cmplz:company'
			);
			foreach ( $p['errors'] as $field => $message ) {
				$m->lossy( sprintf( /* translators: 1: field, 2: problem. */ __( 'Company detail "%1$s" from Complianz was not accepted: %2$s', 'ux-studio' ), $field, $message ) );
			}
			$m->note( __( 'Company number (IČO), VAT number and the data protection officer are not stored in Complianz; fill them in under Legal pages in the module.', 'ux-studio' ) );
		}

		foreach ( $res['lossy'] as $text ) {
			$m->lossy( $text );
		}
		foreach ( $res['warnings'] as $text ) {
			$m->warn( $text );
		}
		foreach ( $res['notes'] as $text ) {
			$m->note( $text );
		}
		if ( (int) ( $r['banners'] ?? 0 ) > 1 ) {
			$m->lossy( __( 'Complianz has more than one banner (other languages or A/B tests). Only the default banner is transferred.', 'ux-studio' ) );
		}
		if ( function_exists( 'pll__' ) || defined( 'WPML_PLUGIN_BASENAME' ) ) {
			$m->lossy( __( 'The banner texts translated with WPML or Polylang are not transferred.', 'ux-studio' ) );
		}
		$m->lossy( __( 'The list of cookies and services with your own descriptions is not transferred; the module builds its own list from the scan and the catalogue.', 'ux-studio' ) );
		foreach ( $this->blockers() as $text ) {
			$m->warn( __( 'BLOCKS THE SWITCH UNLESS YOU CONFIRM IT:', 'ux-studio' ) . ' ' . $text );
		}
		if ( $res['add_scripts'] && is_dir( UXSTUDIO_PATH . 'includes/Modules/CodeSnippets' ) ) {
			$m->note( __( 'Scripts from the Script Center can be recreated by hand in the Code Snippets module (nothing is moved automatically).', 'ux-studio' ) );
		}

		foreach ( array_slice( $a['left'], 0, 10 ) as $text ) {
			$m->lossy( sprintf( /* translators: %s: page and element. */ __( 'Left as it is, not converted: %s', 'ux-studio' ), $text ) );
		}
		foreach ( LegalProfile::DOCS as $doc ) {
			if ( 'terms' === $doc ) {
				continue;
			}
			foreach ( $a['items'] as $item ) {
				if ( ! empty( $item['converted']['document'] ) && empty( $a['ready'][ $doc ] ) ) {
					$m->warn( __( 'Some legal pages stay as they are because the company details are incomplete (name, address and e-mail are needed). Fill them in and run the migration again.', 'ux-studio' ) );
					break 2;
				}
			}
		}
		if ( $a['items'] ) {
			$m->note( __( 'The content of pages that hold Complianz shortcodes or blocks is rewritten at the switch (the address stays). The original content is kept and written back if you undo the switch.', 'ux-studio' ) );
		}
		return $m;
	}

	public function smoke_checks(): array {
		$a      = $this->analysis();
		$checks = array();
		$pages  = array();
		foreach ( $a['items'] as $item ) {
			$url = function_exists( 'get_permalink' ) ? get_permalink( (int) $item['id'] ) : '';
			if ( is_string( $url ) && '' !== $url && ! empty( $item['converted']['document'] ) ) {
				$pages[] = $url;
			}
		}
		$checks[] = array(
			'id'    => 'banner',
			'label' => __( 'One cookie banner, converted legal pages render', 'ux-studio' ),
			'type'  => 'consent_banner',
			'pages' => array_slice( $pages, 0, 5 ),
		);
		$blockers = $this->blockers();
		if ( $blockers ) {
			$checks[] = array(
				'id'       => 'blockers',
				'label'    => __( 'Nothing that only Complianz provided is lost', 'ux-studio' ),
				'type'     => 'consent_blockers',
				'problems' => $blockers,
			);
		}
		return $checks;
	}

	/* ── Data phase: pages and posts ── */

	protected function data_plan( array $options ): array {
		return array(
			array(
				'key'   => 'page',
				'label' => $this->data_label(),
				'count' => count( $this->analysis()['items'] ),
			),
		);
	}

	protected function data_total( array $options ): int {
		return count( $this->analysis()['items'] );
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		$all    = $this->analysis()['items'];
		$offset = (int) $position;
		return array(
			'items' => array_slice( $all, $offset, $limit ),
			'next'  => $offset + $limit,
			'done'  => $offset + $limit >= count( $all ),
		);
	}

	/**
	 * Is this post waiting in the staging area?
	 *
	 * @param int $id Post id.
	 */
	private function pending( int $id ): bool {
		foreach ( Staging::all() as $entry ) {
			foreach ( (array) ( $entry['activations'] ?? array() ) as $row ) {
				if ( $row['source'] === $this->slug && in_array( (string) $id, array_map( 'strval', (array) $row['ids'] ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	protected function preview_item( array $item, array $ctx ): array {
		return array( 'status' => $this->pending( (int) $item['id'] ) ? 'unchanged' : 'created' );
	}

	protected function apply_item( array $item, array $ctx ): array {
		$id = (int) $item['id'];
		if ( $this->pending( $id ) ) {
			return array( 'status' => 'unchanged' );
		}
		// Always prepared, never written now: our shortcode only works once the module is on, and Complianz still serves the page.
		return array(
			'status'   => 'created',
			'activate' => (string) $id,
			'payload'  => array( 'converted' => $item['converted'] ),
		);
	}

	protected function verify_item( array $item ): array {
		$id = (int) $item['id'];
		if ( $this->pending( $id ) ) {
			return array(
				'ok'     => true,
				'detail' => '',
			);
		}
		return array(
			'ok'     => false,
			'detail' => __( 'The page still holds Complianz shortcodes and is not prepared for the switch.', 'ux-studio' ),
		);
	}

	/**
	 * Documents that can be produced now, from the stored profile.
	 *
	 * @return array<string, bool>
	 */
	private function ready_now(): array {
		Legal::reset();
		$profile = Legal::profile();
		$ready   = array();
		foreach ( LegalProfile::DOCS as $doc ) {
			$ready[ $doc ] = array() === LegalProfile::missing( $doc, $profile );
		}
		return $ready;
	}

	public function activate( string $kind, array $ids, array $data = array() ): int {
		if ( 'page' !== $kind ) {
			return 0;
		}
		$ready = $this->ready_now();
		$count = 0;
		$docs  = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$conv = ComplianzMap::convert_content( (string) $post->post_content, $ready );
			if ( ! $conv['changed'] ) {
				continue;
			}
			if ( '' === (string) get_post_meta( $id, self::META_ORIGINAL, true ) ) {
				update_post_meta( $id, self::META_ORIGINAL, wp_slash( (string) $post->post_content ) );
			}
			$res = wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $conv['content'] ),
				),
				true
			);
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$stored = get_post( $id );
			update_post_meta( $id, self::META_WRITTEN, md5( $stored instanceof \WP_Post ? (string) $stored->post_content : $conv['content'] ) );
			$mine = array();
			if ( 'page' === $post->post_type && 'publish' === $post->post_status && preg_match_all( '/\[uxstudio_legal doc="(cookies|privacy)"\]/', $conv['content'], $m ) ) {
				foreach ( array_unique( $m[1] ) as $doc ) {
					if ( Legal::adopt_page( $doc, $id ) ) {
						$mine[]         = $doc;
						$docs[ $doc ] = true;
					}
				}
			}
			update_post_meta( $id, self::META_DOCS, implode( ',', $mine ) );
			++$count;
		}
		if ( $docs ) {
			try {
				Legal::reset();
				Legal::publish( array_keys( $docs ), true );
			} catch ( \Throwable $e ) {
				unset( $e ); // The pages work without the archived version; it is made on the next "Create or update the pages".
			}
		}
		return $count;
	}

	public function deactivate( string $kind, array $ids, array $data = array() ): int {
		if ( 'page' !== $kind ) {
			return 0;
		}
		$count = 0;
		foreach ( $ids as $id ) {
			$id       = (int) $id;
			$post     = get_post( $id );
			$original = get_post_meta( $id, self::META_ORIGINAL, true );
			$written  = (string) get_post_meta( $id, self::META_WRITTEN, true );
			if ( ! $post instanceof \WP_Post || ! is_string( $original ) || '' === $original || '' === $written ) {
				continue;
			}
			foreach ( array_filter( explode( ',', (string) get_post_meta( $id, self::META_DOCS, true ) ) ) as $doc ) {
				Legal::release_page( $doc, $id );
			}
			// Someone edited the page after the switch: their text stays, the original is still in the revisions.
			if ( md5( (string) $post->post_content ) !== $written ) {
				continue;
			}
			$res = wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $original ),
				),
				true
			);
			if ( ! is_wp_error( $res ) ) {
				delete_post_meta( $id, self::META_ORIGINAL );
				delete_post_meta( $id, self::META_WRITTEN );
				delete_post_meta( $id, self::META_DOCS );
				++$count;
			}
		}
		return $count;
	}
}
