<?php
/**
 * Admin Menu Organizer engine: reorganizes the WordPress admin menu into
 * user-defined categories, with custom links, separators, promoted
 * sub-items, per-item overrides and role-based visibility.
 *
 * Ported 1:1 (runtime logic) from the legacy
 * ux1-wordpress-customizer/modules/admin-customiser/pro/includes/AdminMenuOrganizer.php,
 * renamed to snake_case methods and PHP 8.1 syntax. The jQuery-UI
 * drag-and-drop editor UI is NOT ported - the React SPA (MenuOrganizerTab.tsx)
 * provides a functionally equivalent editor with simpler controls (see
 * Page.tsx for details). The config JSON shape below matches the legacy
 * `menu_config` payload field-for-field so no data migration is needed for
 * sites moving from ux1 to ux-studio.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class MenuOrganizer {

	/** Single WP option holding the entire menu-organizer config (JSON-shaped array). */
	private const CONFIG_OPTION = 'uxstudio_admin_customiser_menu_config';

	/** Shared module settings option (schema-based, owns the master enable toggle). */
	private const SETTINGS_OPTION = 'uxstudio_admin_customiser';

	/**
	 * Snapshot of the original top-level $menu before reorganization replaced
	 * it. The "current menu" REST endpoint needs the real items as its source
	 * list; once reorganization runs, $menu only contains synthetic category
	 * tops (horizontal layout) or flattened items with separators (vertical).
	 *
	 * @var array|null
	 */
	private ?array $menu_snapshot = null;

	/**
	 * Snapshot of the original $submenu (for resolving native children of
	 * top-level items in the source list / horizontal flyouts).
	 *
	 * @var array|null
	 */
	private ?array $submenu_snapshot = null;

	/* ═══════════════════════════════════════════════════
	   Enable state
	   ═══════════════════════════════════════════════════ */

	/**
	 * Master switch lives in the module's shared settings schema
	 * (`menu_organizer_enabled`) so it's consistent with every other
	 * Admin Customiser sub-feature toggle.
	 */
	public function is_enabled(): bool {
		return (bool) ( new Settings( self::SETTINGS_OPTION ) )->get( 'menu_organizer_enabled', false );
	}

	/* ═══════════════════════════════════════════════════
	   Static data: default categories, dashicons, auto-categorize patterns
	   ═══════════════════════════════════════════════════ */

	/**
	 * Default category map (id => label).
	 */
	public static function default_categories(): array {
		return array(
			'dashboard'  => __( 'Dashboard', 'ux-studio' ),
			'content'    => __( 'Content', 'ux-studio' ),
			'media'      => __( 'Media', 'ux-studio' ),
			'appearance' => __( 'Appearance', 'ux-studio' ),
			'commerce'   => __( 'Commerce', 'ux-studio' ),
			'marketing'  => __( 'Marketing', 'ux-studio' ),
			'users'      => __( 'Users', 'ux-studio' ),
			'system'     => __( 'System', 'ux-studio' ),
			'other'      => __( 'Other', 'ux-studio' ),
		);
	}

	/**
	 * Curated list of dashicon class names accepted for category/item icon
	 * overrides. Anything outside this list is rejected by sanitize_icon_value()
	 * so we never end up echoing arbitrary attacker-controlled CSS classes.
	 */
	public static function dashicons_list(): array {
		return array(
			'dashicons-admin-appearance', 'dashicons-admin-collapse', 'dashicons-admin-comments',
			'dashicons-admin-customizer', 'dashicons-admin-generic', 'dashicons-admin-home',
			'dashicons-admin-links', 'dashicons-admin-media', 'dashicons-admin-multisite',
			'dashicons-admin-network', 'dashicons-admin-page', 'dashicons-admin-plugins',
			'dashicons-admin-post', 'dashicons-admin-settings', 'dashicons-admin-site',
			'dashicons-admin-tools', 'dashicons-admin-users', 'dashicons-album',
			'dashicons-analytics', 'dashicons-archive', 'dashicons-art', 'dashicons-awards',
			'dashicons-backup', 'dashicons-bank', 'dashicons-block-default', 'dashicons-book',
			'dashicons-book-alt', 'dashicons-businessman', 'dashicons-button', 'dashicons-calculator',
			'dashicons-calendar', 'dashicons-calendar-alt', 'dashicons-camera', 'dashicons-car',
			'dashicons-cart', 'dashicons-category', 'dashicons-chart-area', 'dashicons-chart-bar',
			'dashicons-chart-line', 'dashicons-chart-pie', 'dashicons-clipboard', 'dashicons-clock',
			'dashicons-cloud', 'dashicons-code-standards', 'dashicons-color-picker', 'dashicons-columns',
			'dashicons-controls-play', 'dashicons-cover-image', 'dashicons-dashboard', 'dashicons-database',
			'dashicons-desktop', 'dashicons-dismiss', 'dashicons-download', 'dashicons-edit',
			'dashicons-edit-large', 'dashicons-edit-page', 'dashicons-email', 'dashicons-email-alt',
			'dashicons-embed-generic', 'dashicons-exit', 'dashicons-external', 'dashicons-facebook',
			'dashicons-feedback', 'dashicons-filter', 'dashicons-flag', 'dashicons-food',
			'dashicons-format-image', 'dashicons-forms', 'dashicons-games', 'dashicons-google',
			'dashicons-grid-view', 'dashicons-groups', 'dashicons-hammer', 'dashicons-heading',
			'dashicons-heart', 'dashicons-hidden', 'dashicons-hourglass', 'dashicons-id',
			'dashicons-image-filter', 'dashicons-images-alt', 'dashicons-images-alt2', 'dashicons-index-card',
			'dashicons-info', 'dashicons-insert', 'dashicons-instagram', 'dashicons-laptop',
			'dashicons-layout', 'dashicons-lightbulb', 'dashicons-linkedin', 'dashicons-list-view',
			'dashicons-location', 'dashicons-location-alt', 'dashicons-lock', 'dashicons-marker',
			'dashicons-media-archive', 'dashicons-media-audio', 'dashicons-media-code',
			'dashicons-media-default', 'dashicons-media-document', 'dashicons-media-interactive',
			'dashicons-media-spreadsheet', 'dashicons-media-text', 'dashicons-media-video',
			'dashicons-megaphone', 'dashicons-menu', 'dashicons-menu-alt', 'dashicons-menu-alt2',
			'dashicons-menu-alt3', 'dashicons-microphone', 'dashicons-migrate', 'dashicons-minus',
			'dashicons-money', 'dashicons-money-alt', 'dashicons-move', 'dashicons-nametag',
			'dashicons-networking', 'dashicons-no', 'dashicons-no-alt', 'dashicons-open-folder',
			'dashicons-palmtree', 'dashicons-paperclip', 'dashicons-pdf', 'dashicons-performance',
			'dashicons-phone', 'dashicons-pinterest', 'dashicons-playlist-audio', 'dashicons-playlist-video',
			'dashicons-plus', 'dashicons-plus-alt', 'dashicons-plus-alt2', 'dashicons-portfolio',
			'dashicons-post-status', 'dashicons-pressthis', 'dashicons-printer', 'dashicons-privacy',
			'dashicons-products', 'dashicons-randomize', 'dashicons-redo', 'dashicons-reddit',
			'dashicons-remove', 'dashicons-rest-api', 'dashicons-rss', 'dashicons-saved',
			'dashicons-schedule', 'dashicons-screenoptions', 'dashicons-search', 'dashicons-share',
			'dashicons-shield', 'dashicons-shield-alt', 'dashicons-shortcode', 'dashicons-slides',
			'dashicons-smartphone', 'dashicons-smiley', 'dashicons-sort', 'dashicons-sos',
			'dashicons-spotify', 'dashicons-star-empty', 'dashicons-star-filled', 'dashicons-star-half',
			'dashicons-sticky', 'dashicons-store', 'dashicons-superhero', 'dashicons-tablet',
			'dashicons-tag', 'dashicons-tagcloud', 'dashicons-testimonial', 'dashicons-text',
			'dashicons-thumbs-down', 'dashicons-thumbs-up', 'dashicons-tickets', 'dashicons-tide',
			'dashicons-translation', 'dashicons-trash', 'dashicons-twitch', 'dashicons-twitter',
			'dashicons-undo', 'dashicons-universal-access', 'dashicons-unlock', 'dashicons-update',
			'dashicons-upload', 'dashicons-vault', 'dashicons-video-alt', 'dashicons-video-alt2',
			'dashicons-video-alt3', 'dashicons-visibility', 'dashicons-warning', 'dashicons-welcome-add-page',
			'dashicons-welcome-comments', 'dashicons-welcome-learn-more', 'dashicons-welcome-view-site',
			'dashicons-welcome-widgets-menus', 'dashicons-welcome-write-blog', 'dashicons-whatsapp',
			'dashicons-wordpress', 'dashicons-wordpress-alt', 'dashicons-xing', 'dashicons-yes',
			'dashicons-yes-alt', 'dashicons-youtube', 'dashicons-arrow-right-alt2',
		);
	}

	/**
	 * Match a slug against default category patterns.
	 */
	public static function auto_categorize( string $slug ): string {
		$patterns = array(
			'dashboard'  => array(
				'/^index\.php$/',
				'/^update-core\.php$/',
				'/^my-sites\.php$/',
			),
			'commerce'   => array(
				'/woocommerce/i', '/^wc-/i', '/^edit\.php\?post_type=product/', '/^edit\.php\?post_type=shop_/',
				'/^post-new\.php\?post_type=product/', '/^post-new\.php\?post_type=shop_/',
				'/^edit-tags\.php\?taxonomy=product_/', '/^edd-/i', '/^wpforo/i', '/^wcfm/i', '/^stripe/i',
				'/^memberpress/i', '/^learnpress/i', '/^lifterlms/i', '/^tutor/i', '/^booking/i',
				'/^easy-digital-downloads/i',
			),
			'marketing'  => array(
				'/wpseo/i', '/^seo/i', '/yoast/i', '/rank[-_]?math/i', '/seopress/i', '/^aioseo/i',
				'/^mailpoet/i', '/^newsletter/i', '/^mailchimp/i', '/^mc4wp/i', '/^klaviyo/i',
				'/^sendinblue/i', '/^brevo/i', '/^google-analytics/i', '/^monsterinsights/i',
				'/^googletag/i', '/^gtm/i', '/^facebook-for/i', '/^pixelyoursite/i', '/popup/i',
				'/^optinmonster/i', '/^hubspot/i', '/^activecampaign/i', '/^convertkit/i',
			),
			'appearance' => array(
				'/^themes\.php$/', '/^nav-menus\.php$/', '/^widgets\.php$/', '/^customize\.php/',
				'/^site-editor\.php/', '/^theme-editor\.php/', '/^elementor/i', '/^fl[-_]?builder/i',
				'/beaver[-_]?builder/i', '/^et[-_]?divi/i', '/^divi/i', '/^oxygen/i', '/^brizy/i',
				'/^breakdance/i', '/^kadence/i', '/^astra/i', '/^generatepress/i', '/^wpbakery/i',
				'/^vc[-_]/i', '/^edit\.php\?post_type=elementor_/', '/^edit\.php\?post_type=wp_template/',
				'/^edit\.php\?post_type=wp_block/', '/^edit\.php\?post_type=oxy_/',
			),
			'media'      => array(
				'/^upload\.php/', '/^media-new\.php$/', '/^smush/i', '/^shortpixel/i', '/^ewww/i',
				'/^imagify/i', '/^nextgen/i', '/^envira/i', '/^foogallery/i',
			),
			'users'      => array(
				'/^users\.php$/', '/^profile\.php$/', '/^user-new\.php$/', '/^buddypress/i', '/^bbp[-_]/i',
				'/^bbpress/i', '/^um[-_]/i', '/^ultimate[-_]?member/i', '/^members/i',
				'/^user-role-editor/i', '/^pms-/i',
			),
			'content'    => array(
				'/^edit\.php/', '/^post-new\.php/', '/^edit-comments\.php$/', '/^edit-tags\.php/',
				'/^acf-/i', '/^cptui/i', '/^pods/i', '/^mb[-_]/i', '/^toolset/i', '/^wpcf7/i',
				'/^wpforms/i', '/^gf[-_]/i', '/^forminator/i', '/^fluentform/i', '/^ninja[-_]?forms/i',
				'/^formidable/i',
			),
			'system'     => array(
				'/^plugins\.php$/', '/^plugin-install\.php$/', '/^plugin-editor\.php$/', '/^tools\.php$/',
				'/^import\.php$/', '/^export\.php$/', '/^export-personal-data\.php$/',
				'/^erase-personal-data\.php$/', '/^site-health\.php$/', '/^options-/', '/^options\.php/',
				'/^privacy\.php$/', '/^ux-studio/', '/^wordfence/i', '/itsec/i', '/^better-wp-security/i',
				'/^sucuri/i', '/^all-in-one-wp-security/i', '/^updraftplus/i', '/^backwpup/i',
				'/^duplicator/i', '/^backup/i', '/^migrate/i', '/^wp-?rocket/i', '/^w3-?total-?cache/i',
				'/^litespeed/i', '/^autoptimize/i', '/^wp-?optimize/i', '/^perfmatters/i', '/^redis/i',
				'/^wpml/i', '/^polylang/i', '/^trp[-_]/i', '/^weglot/i', '/^loco/i', '/^wp-mail-smtp/i',
				'/^fluent-?smtp/i', '/^post-?smtp/i', '/^easy-?wp-?smtp/i', '/^code-?snippets/i',
				'/^wp-?cli/i', '/^query-?monitor/i', '/^debug-?bar/i', '/^health-?check/i',
				'/^redirection/i', '/^safe-?svg/i',
			),
		);

		foreach ( $patterns as $cat => $list ) {
			foreach ( $list as $pattern ) {
				if ( preg_match( $pattern, $slug ) ) {
					return $cat;
				}
			}
		}

		if ( preg_match( '/^edit\.php\?post_type=/', $slug ) ) {
			return 'content';
		}
		if ( preg_match( '/^post-new\.php\?post_type=/', $slug ) ) {
			return 'content';
		}
		if ( preg_match( '/^edit-tags\.php/', $slug ) ) {
			return 'content';
		}
		if ( preg_match( '/(^|_)settings(_|$)|options-|^settings_/i', $slug ) ) {
			return 'system';
		}
		if ( preg_match( '/^admin\.php\?page=/', $slug ) ) {
			return 'system';
		}

		return 'other';
	}

	/* ═══════════════════════════════════════════════════
	   Config load / save
	   ═══════════════════════════════════════════════════ */

	/**
	 * Load saved menu config, merged over defaults. Does NOT include the
	 * `enabled` flag - that lives in the shared module settings row
	 * (`menu_organizer_enabled`), see is_enabled().
	 */
	public function get_config(): array {
		$config = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$defaults = array(
			'layout'            => 'vertical',
			'categories'        => self::default_categories(),
			'category_order'    => array(),
			'assignments'       => array(),
			'order'             => array(),
			'category_icons'    => array(),
			'category_roles'    => array(),
			'item_titles'       => array(),
			'item_icons'        => array(),
			'item_urls'         => array(),
			'item_roles'        => array(),
			'custom_links'      => array(),
			'promoted_subs'     => array(),
			'separators'        => array(),
			'hidden_items'      => array(),
			'show_icons_level1' => true,
			'show_icons_level2' => true,
			'show_icons_level3' => true,
			'native_flyouts'    => true,
			'seen_slugs'        => array(),
		);

		$config = array_merge( $defaults, $config );

		if ( empty( $config['categories'] ) || ! is_array( $config['categories'] ) ) {
			$config['categories'] = self::default_categories();
		}

		return $config;
	}

	/**
	 * Sanitize + persist a config payload from the REST controller (the JSON
	 * body shape mirrors legacy `handleOrganizerSave()`'s $data payload, minus
	 * the `enabled` flag which now lives in the module settings schema).
	 *
	 * @param array $input Raw input (already decoded JSON).
	 */
	public function save_config( array $input ): array {
		$config = $this->get_config();

		if ( isset( $input['layout'] ) ) {
			$config['layout'] = 'horizontal' === $input['layout'] ? 'horizontal' : 'vertical';
		}
		if ( array_key_exists( 'show_icons_level1', $input ) ) {
			$config['show_icons_level1'] = (bool) $input['show_icons_level1'];
		}
		if ( array_key_exists( 'show_icons_level2', $input ) ) {
			$config['show_icons_level2'] = (bool) $input['show_icons_level2'];
		}
		if ( array_key_exists( 'show_icons_level3', $input ) ) {
			$config['show_icons_level3'] = (bool) $input['show_icons_level3'];
		}
		if ( array_key_exists( 'native_flyouts', $input ) ) {
			$config['native_flyouts'] = (bool) $input['native_flyouts'];
		}

		// Categories: id => label.
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$clean_cats = array();
			foreach ( $input['categories'] as $cat_id => $label ) {
				$key = sanitize_key( (string) $cat_id );
				if ( '' === $key ) {
					continue;
				}
				$clean_cats[ $key ] = sanitize_text_field( (string) $label );
			}
			if ( empty( $clean_cats ) ) {
				$clean_cats['other'] = __( 'Other', 'ux-studio' );
			}
			$config['categories'] = $clean_cats;
		}

		// Assignments: slug => cat_id.
		if ( isset( $input['assignments'] ) && is_array( $input['assignments'] ) ) {
			$clean_a = array();
			foreach ( $input['assignments'] as $slug => $cat ) {
				$clean_a[ sanitize_text_field( (string) $slug ) ] = sanitize_key( (string) $cat );
			}
			$config['assignments'] = $clean_a;
		}

		// Order: cat_id => [slugs...].
		if ( isset( $input['order'] ) && is_array( $input['order'] ) ) {
			$clean_o = array();
			foreach ( $input['order'] as $cat => $slugs ) {
				$cat_key            = sanitize_key( (string) $cat );
				$clean_o[ $cat_key ] = array();
				if ( is_array( $slugs ) ) {
					foreach ( $slugs as $slug ) {
						$clean_o[ $cat_key ][] = sanitize_text_field( (string) $slug );
					}
				}
			}
			$config['order'] = $clean_o;
		}

		// category_icons: cat_id => dashicon class.
		if ( isset( $input['category_icons'] ) && is_array( $input['category_icons'] ) ) {
			$clean_ci = array();
			foreach ( $input['category_icons'] as $cat => $icon ) {
				$clean_ci[ sanitize_key( (string) $cat ) ] = $this->sanitize_icon_value( $icon );
			}
			$config['category_icons'] = array_filter( $clean_ci, static fn( $v ) => '' !== $v );
		}

		// item_titles: slug => label.
		if ( isset( $input['item_titles'] ) && is_array( $input['item_titles'] ) ) {
			$clean_t = array();
			foreach ( $input['item_titles'] as $slug => $title ) {
				$title = sanitize_text_field( (string) $title );
				if ( '' !== $title ) {
					$clean_t[ sanitize_text_field( (string) $slug ) ] = $title;
				}
			}
			$config['item_titles'] = $clean_t;
		}

		// item_icons: slug => dashicon class.
		if ( isset( $input['item_icons'] ) && is_array( $input['item_icons'] ) ) {
			$clean_ii = array();
			foreach ( $input['item_icons'] as $slug => $icon ) {
				$icon = $this->sanitize_icon_value( $icon );
				if ( '' !== $icon ) {
					$clean_ii[ sanitize_text_field( (string) $slug ) ] = $icon;
				}
			}
			$config['item_icons'] = $clean_ii;
		}

		// item_urls: slug => target URL (absolute http(s) or internal admin slug).
		if ( isset( $input['item_urls'] ) && is_array( $input['item_urls'] ) ) {
			$clean_iu = array();
			foreach ( $input['item_urls'] as $slug => $url ) {
				$slug = sanitize_text_field( (string) $slug );
				$url  = is_string( $url ) ? trim( $url ) : '';
				if ( '' === $slug || '' === $url ) {
					continue;
				}
				if ( preg_match( '#^https?://#i', $url ) ) {
					$url = esc_url_raw( $url );
				} else {
					$url = preg_replace( '/[^A-Za-z0-9_\-.\/?=&%]/', '', $url );
				}
				if ( '' !== $url ) {
					$clean_iu[ $slug ] = $url;
				}
			}
			$config['item_urls'] = $clean_iu;
		}

		// category_order: ordered array of cat_id; also reorders `categories`.
		if ( isset( $input['category_order'] ) && is_array( $input['category_order'] ) ) {
			$clean_co = array();
			foreach ( $input['category_order'] as $cat ) {
				$cat_key = sanitize_key( (string) $cat );
				if ( '' !== $cat_key ) {
					$clean_co[] = $cat_key;
				}
			}
			$config['category_order'] = $clean_co;
			if ( ! empty( $clean_co ) && ! empty( $config['categories'] ) ) {
				$reordered = array();
				foreach ( $clean_co as $cat_key ) {
					if ( isset( $config['categories'][ $cat_key ] ) ) {
						$reordered[ $cat_key ] = $config['categories'][ $cat_key ];
					}
				}
				foreach ( $config['categories'] as $k => $v ) {
					if ( ! isset( $reordered[ $k ] ) ) {
						$reordered[ $k ] = $v;
					}
				}
				$config['categories'] = $reordered;
			}
		}

		$valid_roles = array_keys( self::role_list() );

		// category_roles: cat_id => [role keys].
		if ( isset( $input['category_roles'] ) && is_array( $input['category_roles'] ) ) {
			$clean_cr = array();
			foreach ( $input['category_roles'] as $cat => $roles ) {
				$cat_key = sanitize_key( (string) $cat );
				if ( ! is_array( $roles ) ) {
					continue;
				}
				$picked = array();
				foreach ( $roles as $r ) {
					$r = sanitize_key( (string) $r );
					if ( in_array( $r, $valid_roles, true ) ) {
						$picked[] = $r;
					}
				}
				if ( ! empty( $picked ) ) {
					$clean_cr[ $cat_key ] = array_values( array_unique( $picked ) );
				}
			}
			$config['category_roles'] = $clean_cr;
		}

		// item_roles: slug => [role keys].
		if ( isset( $input['item_roles'] ) && is_array( $input['item_roles'] ) ) {
			$clean_ir = array();
			foreach ( $input['item_roles'] as $slug => $roles ) {
				if ( ! is_array( $roles ) ) {
					continue;
				}
				$picked = array();
				foreach ( $roles as $r ) {
					$r = sanitize_key( (string) $r );
					if ( in_array( $r, $valid_roles, true ) ) {
						$picked[] = $r;
					}
				}
				if ( ! empty( $picked ) ) {
					$clean_ir[ sanitize_text_field( (string) $slug ) ] = array_values( array_unique( $picked ) );
				}
			}
			$config['item_roles'] = $clean_ir;
		}

		// separators: array of { id, label, category }.
		if ( isset( $input['separators'] ) && is_array( $input['separators'] ) ) {
			$clean_sep = array();
			foreach ( $input['separators'] as $sep ) {
				if ( ! is_array( $sep ) || empty( $sep['id'] ) ) {
					continue;
				}
				$sep_id = sanitize_key( (string) $sep['id'] );
				if ( '' === $sep_id ) {
					continue;
				}
				$clean_sep[] = array(
					'id'       => $sep_id,
					'label'    => isset( $sep['label'] ) ? sanitize_text_field( (string) $sep['label'] ) : '',
					'category' => isset( $sep['category'] ) ? sanitize_key( (string) $sep['category'] ) : 'other',
				);
			}
			$config['separators'] = $clean_sep;
		}

		// custom_links: array of {id, title, url, icon, target, category}.
		if ( isset( $input['custom_links'] ) && is_array( $input['custom_links'] ) ) {
			$clean_cl = array();
			foreach ( $input['custom_links'] as $cl ) {
				if ( ! is_array( $cl ) ) {
					continue;
				}
				$id    = isset( $cl['id'] ) ? sanitize_key( (string) $cl['id'] ) : '';
				$title = isset( $cl['title'] ) ? sanitize_text_field( (string) $cl['title'] ) : '';
				$url   = isset( $cl['url'] ) ? esc_url_raw( (string) $cl['url'] ) : '';
				if ( '' === $id || '' === $title || '' === $url ) {
					continue;
				}
				$clean_cl[] = array(
					'id'       => $id,
					'title'    => $title,
					'url'      => $url,
					'icon'     => isset( $cl['icon'] ) ? $this->sanitize_icon_value( $cl['icon'] ) : '',
					'target'   => isset( $cl['target'] ) && '_blank' === $cl['target'] ? '_blank' : '',
					'category' => isset( $cl['category'] ) ? sanitize_key( (string) $cl['category'] ) : 'other',
				);
			}
			$config['custom_links'] = $clean_cl;
		}

		// promoted_subs: assoc sub_slug => { parent, title, icon, category }. Always overwritten.
		$clean_ps = array();
		if ( isset( $input['promoted_subs'] ) && is_array( $input['promoted_subs'] ) ) {
			foreach ( $input['promoted_subs'] as $sub_slug => $ps ) {
				if ( ! is_array( $ps ) ) {
					continue;
				}
				$sub_slug = trim( (string) $sub_slug );
				$parent   = isset( $ps['parent'] ) ? trim( (string) $ps['parent'] ) : '';
				$title    = isset( $ps['title'] ) ? sanitize_text_field( (string) $ps['title'] ) : '';
				if ( '' === $sub_slug || '' === $parent || '' === $title ) {
					continue;
				}
				$clean_ps[ $sub_slug ] = array(
					'parent'   => $parent,
					'title'    => $title,
					'icon'     => isset( $ps['icon'] ) ? $this->sanitize_icon_value( $ps['icon'] ) : '',
					'category' => isset( $ps['category'] ) ? sanitize_key( (string) $ps['category'] ) : 'other',
				);
			}
		}
		$config['promoted_subs'] = $clean_ps;

		// hidden_items: list of native top-level slugs removed from the tree. Always overwritten.
		$clean_hi = array();
		if ( isset( $input['hidden_items'] ) && is_array( $input['hidden_items'] ) ) {
			foreach ( $input['hidden_items'] as $slug ) {
				$slug = trim( (string) $slug );
				if ( '' !== $slug ) {
					$clean_hi[ $slug ] = true;
				}
			}
		}
		$config['hidden_items'] = array_values( array_keys( $clean_hi ) );

		// Baseline snapshot for new-plugin detection.
		$current               = $this->get_current_menu_items();
		$config['seen_slugs'] = array_values( array_map( static fn( $it ) => $it['slug'], $current ) );

		update_option( self::CONFIG_OPTION, $config );

		return $config;
	}

	/**
	 * Export the current config (used by the REST "export-default" endpoint;
	 * the SPA turns this into a downloaded JSON file - no filesystem writes
	 * on the server side, unlike the legacy "Save as default" button).
	 */
	public function export_default_config(): array {
		return $this->get_config();
	}

	/* ═══════════════════════════════════════════════════
	   Runtime: menu reorganization
	   ═══════════════════════════════════════════════════ */

	/**
	 * Reorganize global $menu by categories. Hooked on `admin_menu`.
	 */
	public function reorganize_admin_menu(): void {
		global $menu, $submenu;

		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return;
		}
		if ( ! $this->is_enabled() ) {
			return;
		}

		$config = $this->get_config();

		$layout         = $config['layout'];
		$categories     = $config['categories'];
		$assignments    = $config['assignments'];
		$order          = $config['order'];
		$category_icons = $config['category_icons'];
		$item_titles    = $config['item_titles'];
		$item_icons     = $config['item_icons'];
		$item_urls      = $config['item_urls'];
		$item_roles_cfg = $config['item_roles'];
		$cat_roles_cfg  = $config['category_roles'];
		$custom_links   = $config['custom_links'];
		$promoted_subs  = $config['promoted_subs'];
		$separators     = $config['separators'];
		$hidden_items   = array_flip( $config['hidden_items'] );

		$user       = wp_get_current_user();
		$user_roles = ( $user && is_array( $user->roles ) ) ? $user->roles : array();
		$bypass     = current_user_can( 'manage_options' );

		$can_see_slug = static function ( string $slug ) use ( $item_roles_cfg, $user_roles, $bypass ): bool {
			if ( $bypass ) {
				return true;
			}
			$req = isset( $item_roles_cfg[ $slug ] ) ? (array) $item_roles_cfg[ $slug ] : array();
			if ( empty( $req ) ) {
				return true;
			}
			return (bool) array_intersect( $req, $user_roles );
		};
		$can_see_cat = static function ( string $cat ) use ( $cat_roles_cfg, $user_roles, $bypass ): bool {
			if ( $bypass ) {
				return true;
			}
			$req = isset( $cat_roles_cfg[ $cat ] ) ? (array) $cat_roles_cfg[ $cat ] : array();
			if ( empty( $req ) ) {
				return true;
			}
			return (bool) array_intersect( $req, $user_roles );
		};

		// Idempotency guard: don't re-bucket already-reorganized synthetic entries
		// (this hook runs on admin_menu, parent_file and submenu_file).
		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug = $item[2] ?? '';
			$cls  = $item[4] ?? '';
			if ( str_starts_with( $slug, 'uxs-amo-cat-' )
				|| str_starts_with( $slug, 'separator-uxs-amo-' )
				|| str_contains( $cls, 'uxs-amo-cat-top' )
				|| str_contains( $cls, 'uxs-amo-sep' ) ) {
				return;
			}
		}

		if ( null === $this->menu_snapshot ) {
			$this->menu_snapshot    = $menu;
			$this->submenu_snapshot = is_array( $submenu ) ? $submenu : array();
		}

		$fallback_cat = null;
		if ( isset( $categories['other'] ) ) {
			$fallback_cat = 'other';
		} else {
			$first = array_keys( $categories );
			if ( ! empty( $first ) ) {
				$fallback_cat = $first[0];
			}
		}

		$buckets = array();

		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item[4] ) && str_contains( $item[4], 'wp-menu-separator' ) ) {
				continue;
			}
			$slug = $item[2] ?? '';
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $hidden_items[ $slug ] ) ) {
				continue;
			}

			$cat = $assignments[ $slug ] ?? self::auto_categorize( $slug );
			if ( ! isset( $categories[ $cat ] ) ) {
				if ( null === $fallback_cat ) {
					continue;
				}
				$cat = $fallback_cat;
			}

			if ( ! $can_see_slug( $slug ) || ! $can_see_cat( $cat ) ) {
				continue;
			}

			if ( ! empty( $item_titles[ $slug ] ) ) {
				$item[0] = $item_titles[ $slug ];
			}
			if ( ! empty( $item_icons[ $slug ] ) ) {
				$item[6] = $item_icons[ $slug ];
			}

			$buckets[ $cat ][] = $item;
		}

		// Custom links.
		foreach ( $custom_links as $cl ) {
			if ( empty( $cl['id'] ) || empty( $cl['url'] ) || empty( $cl['title'] ) ) {
				continue;
			}
			$cl_slug = 'custom:' . $cl['id'];
			$cat     = $assignments[ $cl_slug ] ?? ( $cl['category'] ?? $fallback_cat );
			if ( ! isset( $categories[ $cat ] ) ) {
				if ( null === $fallback_cat ) {
					continue;
				}
				$cat = $fallback_cat;
			}
			if ( ! $can_see_slug( $cl_slug ) || ! $can_see_cat( $cat ) ) {
				continue;
			}
			$cl_icon  = ! empty( $cl['icon'] ) ? $cl['icon'] : 'dashicons-admin-links';
			$cl_class = 'menu-top menu-icon-generic uxs-amo-custom-link';
			if ( ! empty( $cl['target'] ) && '_blank' === $cl['target'] ) {
				$cl_class .= ' uxs-amo-target-blank';
			}
			$buckets[ $cat ][] = array(
				$cl['title'],
				'read',
				$cl['url'],
				'',
				$cl_class,
				'menu-uxs-amo-custom-' . $cl['id'],
				$cl_icon,
			);
		}

		// Promoted native sub-items.
		foreach ( $promoted_subs as $sub_slug => $ps ) {
			if ( ! is_array( $ps ) || empty( $ps['parent'] ) || empty( $ps['title'] ) ) {
				continue;
			}
			$cat = $assignments[ $sub_slug ] ?? ( $ps['category'] ?? $fallback_cat );
			if ( ! isset( $categories[ $cat ] ) ) {
				if ( null === $fallback_cat ) {
					continue;
				}
				$cat = $fallback_cat;
			}
			if ( ! $can_see_slug( $sub_slug ) || ! $can_see_cat( $cat ) ) {
				continue;
			}

			$cap = 'read';
			if ( ! empty( $submenu[ $ps['parent'] ] ) && is_array( $submenu[ $ps['parent'] ] ) ) {
				foreach ( $submenu[ $ps['parent'] ] as $s ) {
					if ( isset( $s[2] ) && $s[2] === $sub_slug ) {
						$cap = $s[1] ?? 'read';
						break;
					}
				}
			}
			if ( ! current_user_can( $cap ) ) {
				continue;
			}

			$title = ! empty( $item_titles[ $sub_slug ] ) ? $item_titles[ $sub_slug ] : $ps['title'];
			$icon  = ! empty( $item_icons[ $sub_slug ] ) ? $item_icons[ $sub_slug ] : ( $ps['icon'] ?: 'dashicons-arrow-right-alt2' );

			$buckets[ $cat ][] = array(
				$title,
				$cap,
				$sub_slug,
				$title,
				'menu-top uxs-amo-promoted-sub',
				'menu-uxs-amo-promoted-' . md5( $sub_slug ),
				$icon,
			);
		}

		// User-injected separators.
		foreach ( $separators as $sep ) {
			if ( ! is_array( $sep ) || empty( $sep['id'] ) ) {
				continue;
			}
			$sep_id    = sanitize_key( $sep['id'] );
			$sep_slug  = 'uxs-amo-sep:' . $sep_id;
			$sep_label = isset( $sep['label'] ) ? (string) $sep['label'] : '';
			$cat       = isset( $sep['category'] ) ? sanitize_key( $sep['category'] ) : (string) $fallback_cat;
			if ( ! isset( $categories[ $cat ] ) ) {
				if ( null === $fallback_cat ) {
					continue;
				}
				$cat = $fallback_cat;
			}
			if ( ! $can_see_cat( $cat ) ) {
				continue;
			}
			$sep_class = 'wp-menu-separator uxs-amo-user-sep';
			if ( '' !== $sep_label ) {
				$sep_class .= ' uxs-amo-user-sep-labeled';
			}
			$buckets[ $cat ][] = array(
				$sep_label,
				'read',
				$sep_slug,
				$sep_label,
				$sep_class,
				'menu-uxs-amo-sep-' . $sep_id,
				'',
			);
		}

		// Sort items within each bucket by saved order.
		foreach ( $buckets as $cat_id => $items ) {
			if ( ! empty( $order[ $cat_id ] ) && is_array( $order[ $cat_id ] ) ) {
				$order_map = array_flip( $order[ $cat_id ] );
				usort(
					$buckets[ $cat_id ],
					static function ( $a, $b ) use ( $order_map ) {
						$ai = $order_map[ $a[2] ] ?? PHP_INT_MAX;
						$bi = $order_map[ $b[2] ] ?? PHP_INT_MAX;
						return $ai <=> $bi;
					}
				);
			}
		}

		if ( 'horizontal' === $layout ) {
			$this->build_horizontal_menu( $buckets, $categories, $category_icons, $config, $item_urls );
			return;
		}

		// Vertical layout: flat list with category separators between groups.
		$new_menu = array();
		$pos      = 0;
		$first    = true;
		foreach ( $categories as $cat_id => $cat_label ) {
			if ( empty( $buckets[ $cat_id ] ) ) {
				continue;
			}
			if ( ! $first ) {
				$new_menu[ $pos++ ] = array( '', 'read', 'separator-uxs-amo-' . $cat_id, '', 'wp-menu-separator uxs-amo-sep' );
			}
			$first = false;
			foreach ( $buckets[ $cat_id ] as $item ) {
				$orig_slug = $item[2] ?? '';
				if ( '' !== $orig_slug && ! empty( $item_urls[ $orig_slug ] ) ) {
					$item[2] = $item_urls[ $orig_slug ];
				}
				$new_menu[ $pos++ ] = $item;
			}
		}
		$menu = $new_menu;
	}

	/**
	 * Re-run reorganization at menu-render time. Hooked on `parent_file` and
	 * `submenu_file` filters (both fire from menu-header.php right before the
	 * sidebar paints), so we beat other plugins that swap $menu between the
	 * two filter calls.
	 *
	 * @param mixed $value Filter value, passed through unmodified.
	 */
	public function reorganize_at_render( $value ) {
		$this->reorganize_admin_menu();
		return $value;
	}

	/**
	 * Build a horizontal menu where each category is a top-level item and the
	 * bucket items become its dropdown submenu, using synthetic parent slugs
	 * (`uxs-amo-cat-{id}`) so real $submenu entries aren't clobbered.
	 */
	private function build_horizontal_menu( array $buckets, array $categories, array $category_icons, array $config, array $item_urls ): void {
		global $menu, $submenu;

		$show_l2 = empty( $config['show_icons_level2'] ) ? false : true;
		$show_l3 = empty( $config['show_icons_level3'] ) ? false : true;
		$flyouts = empty( $config['native_flyouts'] ) ? false : true;

		$native_subs = is_array( $submenu ) ? $submenu : array();

		$new_menu = array();
		$pos      = 0;

		foreach ( $categories as $cat_id => $cat_label ) {
			if ( empty( $buckets[ $cat_id ] ) ) {
				continue;
			}

			$cat_icon = ! empty( $category_icons[ $cat_id ] ) ? $category_icons[ $cat_id ] : 'dashicons-menu-alt';

			$only_cls = ( 1 === count( $buckets[ $cat_id ] ) && isset( $buckets[ $cat_id ][0][4] ) ) ? $buckets[ $cat_id ][0][4] : '';
			if ( 1 === count( $buckets[ $cat_id ] ) && ! str_contains( $only_cls, 'uxs-amo-user-sep' ) ) {
				$only      = $buckets[ $cat_id ][0];
				$only_slug = $only[2] ?? '';
				$href_slug = ( '' !== $only_slug && ! empty( $item_urls[ $only_slug ] ) ) ? $item_urls[ $only_slug ] : $only_slug;
				$only_cap  = $only[1] ?? 'read';

				$new_menu[ $pos++ ] = array(
					$cat_label,
					$only_cap,
					$href_slug,
					$cat_label,
					'menu-top uxs-amo-cat-top uxs-amo-cat-single',
					'menu-uxs-amo-cat-' . $cat_id,
					$cat_icon,
				);
				continue;
			}

			$parent_slug = 'uxs-amo-cat-' . sanitize_key( $cat_id );
			$parent_cap  = $buckets[ $cat_id ][0][1] ?? 'read';

			$new_menu[ $pos++ ] = array(
				$cat_label,
				$parent_cap,
				$parent_slug,
				$cat_label,
				'menu-top uxs-amo-cat-top',
				'menu-uxs-amo-cat-' . $cat_id,
				$cat_icon,
			);

			$sub = array();
			foreach ( $buckets[ $cat_id ] as $item ) {
				$cls_raw = $item[4] ?? '';
				if ( str_contains( $cls_raw, 'uxs-amo-user-sep' ) ) {
					$sep_label = isset( $item[0] ) ? trim( (string) $item[0] ) : '';
					$sep_html  = '' !== $sep_label
						? '</a><span class="uxs-amo-sub-sep is-labeled">' . esc_html( $sep_label ) . '</span>'
						: '</a><span class="uxs-amo-sub-sep"></span>';
					$sub_entry    = array( $sep_html, 'read', $item[2] );
					$sub_entry[4] = 'uxs-amo-sub-sep-li';
					$sub[]        = $sub_entry;
					continue;
				}
				$title = isset( $item[0] ) ? wp_strip_all_tags( $item[0] ) : $item[2];
				$title = trim( (string) preg_replace( '/\s+\d+\s*$/', '', $title ) );
				$cap   = $item[1] ?? 'read';
				$slug  = $item[2];
				$icon  = $item[6] ?? '';

				$icon_html = '';
				if ( $show_l2 && $icon ) {
					if ( str_starts_with( $icon, 'dashicons-' ) ) {
						$icon_html = '<span class="uxs-amo-sub-icon dashicons ' . esc_attr( $icon ) . '"></span>';
					} elseif ( str_starts_with( $icon, 'http' ) || str_starts_with( $icon, 'data:' ) ) {
						$icon_html = '<img class="uxs-amo-sub-icon-img" src="' . esc_url( $icon ) . '" alt="" />';
					}
				}

				$label = '' !== $title ? esc_html( $title ) : esc_html( $slug );

				$flyout_html = '';
				$li_classes  = '';
				if ( $flyouts && ! empty( $native_subs[ $slug ] ) && is_array( $native_subs[ $slug ] ) ) {
					$children = array();
					foreach ( $native_subs[ $slug ] as $sub_item ) {
						if ( ! is_array( $sub_item ) || ! isset( $sub_item[0], $sub_item[1], $sub_item[2] ) ) {
							continue;
						}
						if ( ! current_user_can( $sub_item[1] ) ) {
							continue;
						}
						$sub_title = wp_strip_all_tags( $sub_item[0] );
						$sub_title = trim( (string) preg_replace( '/\s+\d+\s*$/', '', $sub_title ) );
						if ( '' === $sub_title ) {
							continue;
						}
						$sub_slug = $sub_item[2];

						if ( str_contains( $sub_slug, '.php' ) || str_contains( $sub_slug, '.html' ) ) {
							$sub_url = $sub_slug;
						} else {
							$parent_file = $slug;
							$sub_url     = ( str_contains( $parent_file, '.php' ) || str_contains( $parent_file, '.html' ) )
								? add_query_arg( array( 'page' => $sub_slug ), $parent_file )
								: add_query_arg( array( 'page' => $sub_slug ), 'admin.php' );
						}
						$sub_url = admin_url( $sub_url );

						$sub_icon_html = $show_l3 ? '<span class="uxs-amo-fly-icon dashicons dashicons-arrow-right-alt2"></span>' : '';

						$children[] = '<li class="uxs-amo-fly-item"><a href="' . esc_url( $sub_url ) . '">' . $sub_icon_html . '<span class="uxs-amo-fly-label">' . esc_html( $sub_title ) . '</span></a></li>';
					}
					if ( ! empty( $children ) ) {
						$flyout_html = '</a><ul class="uxs-amo-flyout">' . implode( '', $children ) . '</ul>';
						$li_classes  = 'uxs-amo-has-flyout';
					}
				}

				$caret    = '' !== $flyout_html ? '<span class="uxs-amo-caret dashicons dashicons-arrow-right-alt2"></span>' : '';
				$rendered = $icon_html . '<span class="uxs-amo-sub-label">' . $label . '</span>' . $caret . $flyout_html;

				$item_href = ! empty( $item_urls[ $slug ] ) ? $item_urls[ $slug ] : $slug;
				$sub_entry = array( $rendered, $cap, $item_href );
				if ( isset( $item[3] ) ) {
					$sub_entry[3] = $item[3];
				}
				if ( '' !== $li_classes ) {
					$sub_entry[4] = $li_classes;
				}
				$sub[] = $sub_entry;
			}
			$submenu[ $parent_slug ] = $sub;
		}

		$menu = $new_menu;
	}

	/* ═══════════════════════════════════════════════════
	   Styling / body class / footer scripts
	   ═══════════════════════════════════════════════════ */

	/**
	 * Inline CSS for category headers, separators and (when active) the
	 * horizontal layout. Hooked on `admin_head`.
	 */
	public function output_menu_styles(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$config = $this->get_config();
		?>
		<style>
			#adminmenu li.uxs-amo-sep {
				height: 1px !important; margin: 6px 12px !important; padding: 0 !important;
				background: rgba(255,255,255,.12) !important; line-height: 0 !important; min-height: 0 !important;
			}
			#adminmenu li.uxs-amo-user-sep {
				height: 1px !important; margin: 8px 12px !important; padding: 0 !important;
				background: rgba(255,255,255,.18) !important; line-height: 0 !important; min-height: 0 !important; position: relative !important;
			}
			#adminmenu li.uxs-amo-user-sep.uxs-amo-user-sep-labeled {
				height: auto !important; background: transparent !important; margin: 10px 0 4px !important; padding: 0 12px !important;
				color: rgba(255,255,255,.5) !important; font-size: 10px !important; text-transform: uppercase !important;
				letter-spacing: .06em !important; font-weight: 600 !important; line-height: 1.4 !important;
			}
			#adminmenu li.uxs-amo-user-sep > div { display: none !important; }
			#adminmenu li.uxs-amo-user-sep.uxs-amo-user-sep-labeled > div {
				display: block !important; border-bottom: 1px solid rgba(255,255,255,.12) !important; padding-bottom: 4px !important;
			}
		</style>
		<?php
		if ( 'horizontal' === $config['layout'] ) {
			$this->output_horizontal_styles();
		}
	}

	/**
	 * CSS that turns the left sidebar into a horizontal top bar with dropdown submenus.
	 */
	private function output_horizontal_styles(): void {
		?>
		<style>
			body.uxs-amo-horizontal {
				--uxs-amo-bar-h: 40px;
			}
			html body.uxs-amo-horizontal #wpwrap #adminmenumain { float: none !important; width: 100vw !important; position: static !important; overflow: visible !important; }
			html body.uxs-amo-horizontal #wpwrap #adminmenuback,
			html body.uxs-amo-horizontal #wpwrap #adminmenuwrap {
				position: fixed !important; top: 32px !important; left: 0 !important; right: 0 !important; width: 100vw !important;
				max-width: 100vw !important; height: var(--uxs-amo-bar-h) !important; z-index: 99 !important; overflow: visible !important;
				box-shadow: 0 1px 0 rgba(0,0,0,.15);
			}
			html body.uxs-amo-horizontal #wpwrap #adminmenu {
				display: flex !important; flex-direction: row !important; flex-wrap: nowrap !important; width: 100vw !important;
				max-width: 100vw !important; height: var(--uxs-amo-bar-h) !important; overflow: visible !important; margin: 0 !important; padding: 0 4px !important;
			}
			html body.uxs-amo-horizontal #adminmenu > li {
				flex: 0 0 auto !important; position: relative !important; height: var(--uxs-amo-bar-h) !important; width: auto !important;
				border: 0 !important; margin: 0 !important; padding: 0 !important;
			}
			html body.uxs-amo-horizontal #adminmenu > li > a.menu-top {
				display: flex !important; align-items: center !important; gap: 6px !important; height: var(--uxs-amo-bar-h) !important;
				padding: 0 12px !important; margin: 0 !important; font-weight: 500; border: 0 !important;
				min-height: var(--uxs-amo-bar-h) !important; white-space: nowrap;
			}
			html body.uxs-amo-horizontal #adminmenu div.wp-menu-image { position: static !important; width: 20px !important; height: 20px !important; padding: 0 !important; flex: 0 0 auto; }
			html body.uxs-amo-horizontal #adminmenu div.wp-menu-image::before { font-size: 16px !important; padding: 0 !important; width: 16px !important; height: 16px !important; line-height: 20px !important; display: inline-block !important; }
			html body.uxs-amo-horizontal #adminmenu div.wp-menu-name { padding: 0 !important; line-height: 1.2 !important; font-size: 13px !important; }
			html body.uxs-amo-horizontal #adminmenu > li.uxs-amo-sep { width: 1px !important; height: 18px !important; margin: 11px 4px !important; background: rgba(255,255,255,.18) !important; flex: 0 0 auto !important; padding: 0 !important; }
			#adminmenu .wp-submenu li.uxs-amo-sub-sep-li { padding: 0 !important; margin: 4px 0 !important; background: transparent !important; }
			#adminmenu .wp-submenu li.uxs-amo-sub-sep-li > a { display: none !important; }
			#adminmenu .wp-submenu .uxs-amo-sub-sep { display: block !important; height: 1px !important; margin: 0 12px !important; background: rgba(255,255,255,.18) !important; line-height: 0 !important; }
			#adminmenu .wp-submenu .uxs-amo-sub-sep.is-labeled {
				height: auto !important; background: transparent !important; padding: 8px 12px 2px !important; margin: 0 !important;
				color: rgba(255,255,255,.5) !important; font-size: 10px !important; text-transform: uppercase !important;
				letter-spacing: .06em !important; font-weight: 600 !important; border-bottom: 1px solid rgba(255,255,255,.12) !important; line-height: 1.4 !important;
			}
			html body.uxs-amo-horizontal ul#adminmenu > li:not(.uxs-amo-cat-top) > a.wp-has-current-submenu::after,
			html body.uxs-amo-horizontal ul#adminmenu > li:not(.uxs-amo-cat-top) > a.current::after,
			html body.uxs-amo-horizontal #adminmenu > li.wp-has-submenu.wp-not-current-submenu:not(.uxs-amo-cat-top):hover::after,
			html body.uxs-amo-horizontal #adminmenu > li.wp-has-submenu.wp-not-current-submenu:not(.uxs-amo-cat-top):focus-within::after {
				display: none !important; content: none !important; border: 0 !important;
			}
			html body.uxs-amo-horizontal #adminmenu > li.uxs-amo-cat-top > a.menu-top::after {
				content: "" !important; display: inline-block !important; position: static !important; width: 0 !important; height: 0 !important;
				margin: 0 0 0 6px !important; padding: 0 !important; border-left: 4px solid transparent !important; border-right: 4px solid transparent !important;
				border-top: 4px solid currentColor !important; border-bottom: 0 !important; opacity: .6; background: transparent !important;
			}
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu li.menu-top > ul.wp-submenu,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu li.menu-top > ul.wp-submenu-wrap {
				position: absolute !important; top: 100% !important; left: 0 !important; right: auto !important; width: 260px !important;
				min-width: 260px !important; max-width: 340px !important; height: auto !important; max-height: none !important; margin: 0 !important;
				padding: 6px 0 !important; box-shadow: 0 6px 18px rgba(0,0,0,.18); border-radius: 0 0 6px 6px; z-index: 9999 !important;
				display: none !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; overflow: visible !important;
			}
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu .wp-submenu li,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu .wp-submenu-wrap li { width: 100% !important; min-width: 0 !important; padding: 0 !important; margin: 0 !important; float: none !important; display: block !important; }
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu .wp-submenu a,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu .wp-submenu-wrap a { padding: 7px 12px !important; line-height: 1.4 !important; font-size: 13px !important; white-space: normal !important; display: flex !important; align-items: center !important; gap: 8px !important; width: auto !important; min-width: 0 !important; }
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu > li.menu-top:hover > .wp-submenu,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu > li.menu-top:hover > .wp-submenu-wrap,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu > li.menu-top.opensub > .wp-submenu,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu > li.menu-top.opensub > .wp-submenu-wrap,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu > li.menu-top:focus-within > .wp-submenu,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu > li.menu-top:focus-within > .wp-submenu-wrap {
				display: block !important; opacity: 1 !important; visibility: visible !important; pointer-events: auto !important;
			}
			html body.uxs-amo-horizontal #wpcontent, html body.uxs-amo-horizontal #wpfooter {
				margin-left: 0 !important; padding-left: 20px !important; padding-right: 20px !important; box-sizing: border-box !important;
			}
			html body.uxs-amo-horizontal #wpbody { padding-top: var(--uxs-amo-bar-h) !important; }
			html body.uxs-amo-horizontal .wrap { margin-left: 0 !important; margin-right: 0 !important; }
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu > li.menu-top,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu > li.menu-top > a.menu-top {
				background: transparent !important; box-shadow: none !important; border: 0 !important;
			}
			html body.uxs-amo-horizontal.uxs-amo-no-icons-l1 #adminmenu > li.menu-top > a.menu-top > div.wp-menu-image { display: none !important; }
			html body.uxs-amo-horizontal.uxs-amo-no-icons-l2 #adminmenu .wp-submenu .uxs-amo-sub-icon,
			html body.uxs-amo-horizontal.uxs-amo-no-icons-l2 #adminmenu .wp-submenu .uxs-amo-sub-icon-img { display: none !important; }
			html body.uxs-amo-horizontal.uxs-amo-no-icons-l3 #adminmenu .uxs-amo-flyout .uxs-amo-fly-icon { display: none !important; }
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu .uxs-amo-flyout {
				position: absolute !important; top: -6px !important; left: 100% !important; min-width: 220px !important; max-width: 320px !important;
				margin: 0 !important; padding: 6px 0 !important; border-radius: 0 6px 6px 6px !important; box-shadow: 0 6px 18px rgba(0,0,0,.18) !important;
				display: none !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; z-index: 10000 !important; list-style: none !important;
			}
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu .wp-submenu li.uxs-amo-has-flyout:hover > .uxs-amo-flyout,
			html body.wp-admin.uxs-amo-horizontal #wpwrap #adminmenu .wp-submenu li.uxs-amo-has-flyout:focus-within > .uxs-amo-flyout {
				display: block !important; opacity: 1 !important; visibility: visible !important; pointer-events: auto !important;
			}
			body.uxs-amo-horizontal #collapse-menu { display: none !important; }
			@media (max-width: 782px) {
				html body.uxs-amo-horizontal #wpwrap #adminmenuback, html body.uxs-amo-horizontal #wpwrap #adminmenuwrap { top: 46px !important; }
				html body.uxs-amo-horizontal #wpcontent, html body.uxs-amo-horizontal #wpfooter { padding-left: 10px !important; padding-right: 10px !important; }
			}
		</style>
		<?php
	}

	/**
	 * Add a body class so CSS/JS elsewhere can detect the active layout. Hooked on `admin_body_class`.
	 */
	public function add_body_class( string $classes ): string {
		if ( ! $this->is_enabled() ) {
			return $classes;
		}
		$config = $this->get_config();
		if ( 'horizontal' === $config['layout'] ) {
			$classes .= ' uxs-amo-horizontal';
			if ( empty( $config['show_icons_level1'] ) ) {
				$classes .= ' uxs-amo-no-icons-l1';
			}
			if ( empty( $config['show_icons_level2'] ) ) {
				$classes .= ' uxs-amo-no-icons-l2';
			}
			if ( empty( $config['show_icons_level3'] ) ) {
				$classes .= ' uxs-amo-no-icons-l3';
			}
		}
		return $classes;
	}

	/**
	 * Open custom links flagged _blank in a new tab (WP doesn't expose a
	 * `target` arg on menu entries). Hooked on `admin_footer`.
	 */
	public function output_custom_link_script(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$config = $this->get_config();
		if ( empty( $config['custom_links'] ) ) {
			return;
		}
		?>
		<script>
		(function () {
			var links = document.querySelectorAll('#adminmenu .uxs-amo-target-blank > a, #wp-admin-bar-root-default .uxs-amo-target-blank > a');
			for (var i = 0; i < links.length; i++) {
				links[i].setAttribute('target', '_blank');
				links[i].setAttribute('rel', 'noopener noreferrer');
			}
		})();
		</script>
		<?php
	}

	/**
	 * Inject a manual dropdown toggle on items with native submenus when the
	 * vertical sidebar layout is active, with state persisted in localStorage.
	 * Hooked on `admin_footer`.
	 */
	public function output_sidebar_expand_script(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$config = $this->get_config();
		if ( 'vertical' !== $config['layout'] ) {
			return;
		}
		?>
		<style id="uxs-amo-vexpand-css">
			#adminmenu .uxs-amo-vexpand {
				position: absolute; top: 0; right: 0; width: 28px; height: 34px; display: flex; align-items: center; justify-content: center;
				background: transparent; border: 0; cursor: pointer; color: inherit; opacity: .55; padding: 0; z-index: 2; transition: opacity .15s ease, transform .2s ease;
			}
			#adminmenu .uxs-amo-vexpand:hover { opacity: 1; }
			#adminmenu .uxs-amo-vexpand .dashicons { width: 16px; height: 16px; font-size: 16px; line-height: 1; transition: transform .2s ease; }
			#adminmenu li.uxs-amo-open > .uxs-amo-vexpand .dashicons { transform: rotate(180deg); }
			#adminmenu li.menu-top.uxs-amo-open > .wp-submenu {
				display: block !important; position: static !important; left: auto !important; top: auto !important; width: auto !important; box-shadow: none !important; margin-left: 0 !important;
			}
			#adminmenu li.menu-top.uxs-amo-has-vexpand > a.menu-top::after { display: none !important; content: none !important; border: 0 !important; }
			#adminmenu li.menu-top.uxs-amo-has-vexpand > a.menu-top { padding-right: 30px !important; }
			.folded #adminmenu .uxs-amo-vexpand { display: none !important; }
		</style>
		<script>
		(function () {
			var STORAGE_KEY = 'uxs_amo_open_menus';
			function readOpen() {
				try {
					var raw = window.localStorage.getItem(STORAGE_KEY);
					if (!raw) return {};
					var parsed = JSON.parse(raw);
					return (parsed && typeof parsed === 'object') ? parsed : {};
				} catch (e) { return {}; }
			}
			function writeOpen(map) {
				try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(map)); } catch (e) {}
			}
			function decorate() {
				var menu = document.getElementById('adminmenu');
				if (!menu) return;
				var open = readOpen();
				var items = menu.querySelectorAll(':scope > li.menu-top.wp-has-submenu');
				for (var i = 0; i < items.length; i++) {
					var li = items[i];
					if (li.classList.contains('wp-menu-separator')) continue;
					if (li.classList.contains('uxs-amo-has-vexpand')) continue;
					li.style.position = 'relative';
					var key = li.id || '';
					if (!key) {
						var anchor = li.querySelector(':scope > a.menu-top');
						key = anchor && anchor.getAttribute('href') ? anchor.getAttribute('href') : ('i' + i);
					}
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'uxs-amo-vexpand';
					btn.setAttribute('aria-label', 'Toggle submenu');
					btn.setAttribute('data-amo-key', key);
					btn.innerHTML = '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
					li.appendChild(btn);
					li.classList.add('uxs-amo-has-vexpand');
					if (open[key]) {
						li.classList.add('uxs-amo-open');
					}
				}
				menu.addEventListener('click', function (e) {
					var btn = e.target.closest && e.target.closest('.uxs-amo-vexpand');
					if (!btn) return;
					e.preventDefault();
					e.stopPropagation();
					var li = btn.parentNode;
					var key = btn.getAttribute('data-amo-key');
					var map = readOpen();
					if (li.classList.toggle('uxs-amo-open')) {
						map[key] = 1;
					} else {
						delete map[key];
					}
					writeOpen(map);
				});
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', decorate);
			} else {
				decorate();
			}
		})();
		</script>
		<?php
	}

	/* ═══════════════════════════════════════════════════
	   Source list (for the REST "current-menu" endpoint)
	   ═══════════════════════════════════════════════════ */

	/**
	 * Return the current top-level admin menu items (slug, title, icon,
	 * native children), read from the pre-reorganization snapshot so the
	 * editor's source list always reflects real top-level items.
	 *
	 * Called from the REST "current-menu" endpoint, which runs on a plain
	 * REST request - WordPress never builds $menu/$submenu there (that only
	 * happens while rendering an actual wp-admin page), so we bootstrap them
	 * on demand via ensure_menu_globals_built() before reading.
	 */
	public function get_current_menu_items(): array {
		global $menu, $submenu;

		if ( null === $this->menu_snapshot && empty( $menu ) ) {
			$this->ensure_menu_globals_built();
		}

		$source_menu    = is_array( $this->menu_snapshot ) ? $this->menu_snapshot : $menu;
		$source_submenu = is_array( $this->submenu_snapshot ) ? $this->submenu_snapshot : $submenu;

		$items = array();
		if ( ! is_array( $source_menu ) ) {
			return $items;
		}

		foreach ( $source_menu as $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) ) {
				continue;
			}
			if ( isset( $item[4] ) && str_contains( $item[4], 'wp-menu-separator' ) ) {
				continue;
			}
			if ( isset( $item[4] ) && ( str_contains( $item[4], 'uxs-amo-cat-header' ) || str_contains( $item[4], 'uxs-amo-cat-top' ) || str_contains( $item[4], 'uxs-amo-sep' ) ) ) {
				continue;
			}
			if ( isset( $item[2] ) && str_starts_with( $item[2], 'uxs-amo-cat-' ) ) {
				continue;
			}

			$title = isset( $item[0] ) ? wp_strip_all_tags( $item[0] ) : $item[2];
			$title = trim( (string) preg_replace( '/\s+\d+\s*$/', '', $title ) );

			$slug     = $item[2];
			$children = array();
			if ( is_array( $source_submenu ) && ! empty( $source_submenu[ $slug ] ) && is_array( $source_submenu[ $slug ] ) ) {
				foreach ( $source_submenu[ $slug ] as $sub ) {
					if ( ! is_array( $sub ) || empty( $sub[2] ) ) {
						continue;
					}
					$sub_title = isset( $sub[0] ) ? wp_strip_all_tags( $sub[0] ) : $sub[2];
					$sub_title = trim( (string) preg_replace( '/\s+\d+\s*$/', '', $sub_title ) );
					if ( '' === $sub_title ) {
						continue;
					}
					if ( mb_strtolower( $sub_title ) === mb_strtolower( $title ) && $sub[2] === $slug ) {
						continue;
					}
					$children[] = array(
						'slug'  => $sub[2],
						'title' => $sub_title,
					);
				}
			}

			$items[] = array(
				'slug'     => $slug,
				'title'    => '' !== $title ? $title : $slug,
				'icon'     => $item[6] ?? '',
				'children' => $children,
			);
		}

		return $items;
	}

	/* ═══════════════════════════════════════════════════
	   Conflict detection / disarm
	   ═══════════════════════════════════════════════════ */

	/**
	 * Remove other plugins' admin menu manipulators when our organizer is
	 * active. Currently handles: Adminify Menu Editor (parent_file filter).
	 * Hooked on `admin_init`.
	 */
	public function disarm_conflicts(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( class_exists( '\\WPAdminify\\Inc\\Modules\\MenuEditor\\MenuEditor' ) ) {
			$instance = \WPAdminify\Inc\Modules\MenuEditor\MenuEditor::get_instance();
			remove_filter( 'parent_file', array( $instance, 'set_menu' ), 800 );
			remove_filter( 'parent_file', array( $instance, 'apply_menu' ), 900 );
		}
	}

	/**
	 * Detect plugins/modules that interfere with the admin sidebar menu.
	 *
	 * @return array<int, array{id:string,name:string,severity:string,message:string}>
	 */
	public function detect_conflicts(): array {
		$conflicts = array();

		if ( class_exists( '\\WPAdminify\\Inc\\Modules\\MenuEditor\\MenuEditor' ) ) {
			$conflicts[] = array(
				'id'       => 'adminify-menu-editor',
				'name'     => 'Adminify - Menu Editor',
				'severity' => 'handled',
				'message'  => __( 'This plugin rewrites the admin menu order via the parent_file filter. Its hooks are automatically removed while the menu organizer is active.', 'ux-studio' ),
			);
		}

		if ( class_exists( 'WPMenuEditor' ) || class_exists( '\\WPMenuEditor' ) ) {
			$conflicts[] = array(
				'id'       => 'admin-menu-editor',
				'name'     => 'Admin Menu Editor (Janis Elsts)',
				'severity' => 'handled',
				'message'  => __( 'This plugin rewrites the global $menu at render time on the submenu_file filter. The organizer reorganizes after it, but running both menu editors together is not recommended.', 'ux-studio' ),
			);
		}

		$hooks_to_scan  = array( 'admin_menu', 'parent_file', 'submenu_file' );
		$known_classes  = array(
			MenuOrganizer::class,
			MenuOrganizerBootstrap::class,
			'WPAdminify\\Inc\\Modules\\MenuEditor\\MenuEditor',
			'WPAdminify\\Pro\\AdminPages_Output',
			'WSAdminMenuEditor',
			'wsMenuEditorExtras',
		);
		$other_modifiers = array();
		foreach ( $hooks_to_scan as $hook ) {
			global $wp_filter;
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			$callbacks = $wp_filter[ $hook ]->callbacks ?? array();
			foreach ( $callbacks as $priority => $items ) {
				if ( $priority < 100 ) {
					continue;
				}
				foreach ( $items as $item ) {
					$cb = $item['function'] ?? null;
					if ( is_array( $cb ) && is_object( $cb[0] ) ) {
						$cls  = get_class( $cb[0] );
						$skip = false;
						foreach ( $known_classes as $known ) {
							if ( 0 === strpos( $cls, $known ) || $cls === $known ) {
								$skip = true;
								break;
							}
						}
						if ( $skip ) {
							continue;
						}
						$other_modifiers[ $cls . '::' . $cb[1] ] = $hook . ' @' . $priority;
					}
				}
			}
		}
		if ( ! empty( $other_modifiers ) ) {
			$list = '';
			foreach ( $other_modifiers as $sig => $where ) {
				$list .= sprintf( '<li><code>%s</code> <em>(%s)</em></li>', esc_html( $sig ), esc_html( $where ) );
			}
			$conflicts[] = array(
				'id'       => 'generic-modifiers',
				'name'     => __( 'Other menu modifiers', 'ux-studio' ),
				'severity' => 'warn',
				'message'  => __( 'Detected other callbacks with high priority on the admin_menu, parent_file or submenu_file hooks. If reorganization misbehaves, this is likely the culprit:', 'ux-studio' ) . '<ul style="margin-top:6px;">' . $list . '</ul>',
			);
		}

		return $conflicts;
	}

	/* ═══════════════════════════════════════════════════
	   Helpers
	   ═══════════════════════════════════════════════════ */

	/**
	 * Return WordPress roles as { key => label } for role pickers.
	 */
	public static function role_list(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		$roles = wp_roles()->roles;
		$out   = array();
		foreach ( $roles as $key => $r ) {
			$out[ $key ] = isset( $r['name'] ) ? translate_user_role( $r['name'] ) : $key;
		}
		return $out;
	}

	/**
	 * Sanitize an icon value to a known dashicon class name, or empty string.
	 * Rejects arbitrary input so we never echo attacker-controlled CSS classes.
	 *
	 * @param mixed $icon Raw icon value.
	 */
	private function sanitize_icon_value( $icon ): string {
		if ( ! is_string( $icon ) ) {
			return '';
		}
		$icon = trim( $icon );
		if ( '' === $icon ) {
			return '';
		}
		return in_array( $icon, self::dashicons_list(), true ) ? $icon : '';
	}

	/**
	 * Best-effort bootstrap of the $menu/$submenu globals outside of a normal
	 * wp-admin page load. WordPress core only builds these while rendering
	 * wp-admin/admin.php (which requires wp-admin/menu.php and then fires the
	 * `admin_menu` action so every plugin registers its pages) - a REST
	 * request never does this. We replicate the same steps here so the
	 * "current-menu" REST endpoint can return the real, complete admin menu
	 * tree. Wrapped defensively: if core files are missing/unreadable this is
	 * a no-op and the endpoint simply returns an empty list.
	 */
	private function ensure_menu_globals_built(): void {
		global $menu, $submenu, $_wp_menu_nopriv, $_wp_submenu_nopriv, $parent_file, $submenu_file, $plugin_page, $typenow, $pagenow;

		if ( ! function_exists( 'add_menu_page' ) ) {
			$admin_includes = ABSPATH . 'wp-admin/includes/admin.php';
			if ( is_readable( $admin_includes ) ) {
				require_once $admin_includes;
			}
		}

		$pagenow = is_string( $pagenow ) && '' !== $pagenow ? $pagenow : 'admin.php';

		$core_menu_file = ABSPATH . 'wp-admin/menu.php';
		if ( ! is_array( $menu ) && is_readable( $core_menu_file ) ) {
			// wp-admin/menu.php builds the core $menu/$submenu arrays and ends
			// with `do_action( 'admin_menu', '' )`, which is exactly what
			// invites every other plugin to register its own pages too.
			require $core_menu_file;
		} elseif ( is_array( $menu ) ) {
			// Core menu already present (e.g. another module bootstrapped it
			// first this request) - still need third-party pages registered.
			do_action( 'admin_menu', '' );
		}
	}
}
