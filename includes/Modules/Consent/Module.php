<?php
/**
 * Cookie Consent module - own cookie banner, blocking of tracking scripts, Google Consent Mode v2.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Core\Settings;
use UxStudio\Modules\BaseModule;
use UxStudio\Modules\SecurityOptimization\CspInlineScripts;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md section 42, phase F1. The module is a light replacement for cookie plugins:
 *
 *  - the banner is static HTML-free: a JSON config block + one small deferred script that builds the dialog, so the
 *    page stays identical for every visitor and a page cache can keep it;
 *  - the visitor's choice lives only in a first-party cookie (`uxs_consent`, see ConsentMode), nothing about the
 *    visitor is stored on the server;
 *  - tracking scripts and third-party iframes are held back by rewriting their tags (Blocker), and Google tags get
 *    Consent Mode v2 defaults (ConsentMode);
 *  - Pixel & Tag Manager and GTM Kit are wired in directly (Gate, GtmKit).
 *
 * Later phases (scanner, migration, TCF) are not part of this class. The legal pages (F4) live in Legal.
 */
final class Module extends BaseModule {

	/** Script handle / style handle of the banner. */
	private const HANDLE = 'uxs-consent';

	/** @var bool|null Memo of runs_here() once the main query exists. */
	private ?bool $runs_here = null;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		Gate::boot( $this );
		GtmKit::register( $this );
		Inventory::boot( $this );
		Records::boot();
		Legal::boot();
		Scan::boot();
		ComplianzCompat::boot();

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( Geo::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( PreviewRest::class, 'register' ) );

		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'print_consent_defaults' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'print_config' ), 21 );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 99, 3 );
		add_action( 'template_redirect', array( $this, 'maybe_buffer' ), 1 );

		foreach ( array( 'the_content', 'widget_text_content', 'widget_block_content', 'embed_oembed_html' ) as $hook ) {
			add_filter( $hook, array( $this, 'filter_iframes' ), 'the_content' === $hook ? 20 : 99 );
		}
	}

	/**
	 * Late setup: the home URL and the CSP registry are ready.
	 */
	public function init(): void {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		Blocker::configure(
			array(
				'site_host'       => is_string( $host ) ? strtolower( $host ) : '',
				'rules'           => Services::parse_rules( (string) $this->settings->get( 'custom_rules', '' ) ),
				'gcm_loads_early' => 'advanced' === $this->consent_mode(),
				'nocookie'        => (bool) $this->settings->get( 'youtube_nocookie', true ),
			)
		);

		add_shortcode( 'uxstudio_cookie_settings', array( $this, 'shortcode' ) );

		// A strict CSP needs the hash of our one inline script (same text as printed, see print_consent_defaults()).
		CspInlineScripts::register(
			'consent-mode',
			__( 'Cookie Consent: Google Consent Mode defaults', 'ux-studio' ),
			function (): array {
				return 'off' === $this->consent_mode() ? array() : array( $this->defaults_body() );
			}
		);
	}

	/**
	 * Module switched off: stop the weekly inventory scan and the daily purge of consent records. Settings, the stored
	 * inventory and the records stay.
	 */
	public function on_disable(): void {
		Inventory::unschedule();
		Records::unschedule();
	}

	/**
	 * One stored setting, for the helpers that sit next to the module.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function settings_value( string $key, $default = null ) {
		return $this->settings->get( $key, $default );
	}

	/**
	 * Consent Mode setting.
	 */
	public function consent_mode(): string {
		$mode = (string) $this->settings->get( 'consent_mode', 'basic' );
		return in_array( $mode, array( 'off', 'basic', 'advanced' ), true ) ? $mode : 'basic';
	}

	/**
	 * Does the banner run on this request? Only on the public site: not in the admin, REST, AJAX, cron, feeds, embeds,
	 * the Customizer or a page-builder editor.
	 */
	public function runs_here(): bool {
		if ( null !== $this->runs_here ) {
			return $this->runs_here;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		if ( ! did_action( 'wp' ) ) {
			// Too early to know the kind of page (is_feed() and friends need the main query); not memoised.
			return ! ( defined( 'WP_INSTALLING' ) && WP_INSTALLING );
		}
		$run = ! ( is_feed() || is_robots() || is_embed() || is_customize_preview() || is_trackback() );
		if ( $run ) {
			foreach ( array( 'elementor-preview', 'et_fb', 'fl_builder', 'brizy-edit', 'ct_builder', 'bricks', 'vc_editable', 'wp_scrape_key' ) as $flag ) {
				if ( isset( $_GET[ $flag ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only tests for the presence of an editor flag.
					$run = false;
					break;
				}
			}
		}
		/**
		 * Lets a site switch the banner and the blocking off on a request (for example a page-builder preview).
		 *
		 * @param bool $run Whether the consent module acts on this request.
		 */
		$this->runs_here = (bool) apply_filters( 'uxstudio_consent_runs_here', $run );
		return $this->runs_here;
	}

	/**
	 * The text of the Consent Mode defaults script (printed and hashed from this one function).
	 */
	public function defaults_body(): string {
		return ConsentMode::body( $this->policy_version(), (int) $this->settings->get( 'wait_for_update', 500 ) );
	}

	/**
	 * Version of the consent policy: a stored choice for another version is asked again.
	 */
	private function policy_version(): int {
		return max( 1, min( 9999, (int) $this->settings->get( 'policy_version', 1 ) ) );
	}

	/**
	 * Print the Consent Mode defaults first in the head, before any Google tag.
	 */
	public function print_consent_defaults(): void {
		if ( 'off' === $this->consent_mode() || ! $this->runs_here() ) {
			return;
		}
		// The body is built from integers only (see ConsentMode::body()), nothing to escape.
		echo '<script id="uxc-consent-default">' . $this->defaults_body() . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * URLs of the banner script and style (the minified ones unless SCRIPT_DEBUG), each with a version from the file time.
	 *
	 * The style of the chosen template (`tcss`) is a second, small file loaded after the base one; the classic template
	 * lives in the base style, so for it `tcss` is an empty string and nothing extra is loaded.
	 *
	 * @return array{js:string,css:string,js_ver:string,css_ver:string,tcss:string,tcss_ver:string}
	 */
	public function asset_urls(): array {
		$min  = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$base = UXSTUDIO_PATH . 'assets/consent/';
		$url  = UXSTUDIO_URL . 'assets/consent/';
		$ver  = static fn( string $file ): string => is_readable( $base . $file ) ? (string) filemtime( $base . $file ) : UXSTUDIO_VERSION;
		$tcss = Templates::style_file( $this->template(), $min );
		return array(
			'js'       => $url . 'consent' . $min . '.js',
			'css'      => $url . 'consent' . $min . '.css',
			'js_ver'   => $ver( 'consent' . $min . '.js' ),
			'css_ver'  => $ver( 'consent' . $min . '.css' ),
			'tcss'     => '' === $tcss ? '' : $url . $tcss,
			'tcss_ver' => '' === $tcss ? '' : $ver( $tcss ),
		);
	}

	/**
	 * The chosen banner template, always one of TEMPLATES.
	 */
	public function template(): string {
		return Templates::normalize( (string) $this->settings->get( 'template', 'classic' ) );
	}

	/**
	 * Banner script and style.
	 */
	public function enqueue(): void {
		if ( ! $this->runs_here() ) {
			return;
		}
		$assets = $this->asset_urls();

		wp_register_style( self::HANDLE, $assets['css'], array(), $assets['css_ver'] );
		$vars = $this->css_variables();
		if ( '' !== $vars ) {
			wp_add_inline_style( self::HANDLE, $vars );
		}
		wp_enqueue_style( self::HANDLE );
		if ( '' !== $assets['tcss'] ) {
			// Only the style of the chosen template goes to the page, after the base one.
			wp_enqueue_style( self::HANDLE . '-tpl', $assets['tcss'], array( self::HANDLE ), $assets['tcss_ver'] );
		}

		wp_enqueue_script(
			self::HANDLE,
			$assets['js'],
			array(),
			$assets['js_ver'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * What the live preview in the admin needs: the config the banner script would get with the given UNSAVED values
	 * applied over the stored settings, and the colour variables. It runs the very same code as the frontend (the stored
	 * option is swapped for the merged values while it runs, and nothing is written), so the preview cannot drift from
	 * the real banner. Everything that talks to the outside is left out: consent records (`rec`), the region check
	 * (`geo`), the old banner takeover (`leg`) and the cookie list (`ck`).
	 *
	 * @param array  $values Unsaved values by setting key (sanitized here with the schema; unknown keys are dropped).
	 * @param string $view   main | detail | fab | ph, which part of the banner the preview shows.
	 * @return array{config:array<string,mixed>,css:string,assets:array<string,string>}
	 */
	public function preview_config( array $values, string $view ): array {
		$option = 'uxstudio_' . str_replace( '-', '_', $this->id );
		$merged = array_merge( $this->settings->all(), Settings::clean( $values, $this->settings_schema() ) );
		$swap   = static fn() => $merged;
		add_filter( 'pre_option_' . $option, $swap );
		$this->reload_settings();
		try {
			$config = $this->frontend_config();
			$css    = $this->css_variables();
			$assets = $this->asset_urls(); // Inside the swap: the style of the template being previewed, not the saved one.
		} finally {
			remove_filter( 'pre_option_' . $option, $swap );
			$this->reload_settings();
		}
		unset( $config['rec'], $config['geo'], $config['leg'], $config['ck'] );
		$config['preview'] = in_array( $view, array( 'main', 'detail', 'fab', 'ph' ), true ) ? $view : 'main';
		return array(
			'config' => $config,
			'css'    => $css,
			'assets' => $assets,
		);
	}

	/**
	 * Colour and radius overrides, only the ones that differ from the stylesheet defaults.
	 */
	public function css_variables(): string {
		$values = array();
		foreach ( Templates::COLOURS as $key => $pair ) {
			$values[ $key ] = (string) sanitize_hex_color( (string) $this->settings->get( $key, $pair[1] ) );
		}
		$css    = Templates::variables( $this->template(), $values );
		$radius = max( 0, min( 32, (int) $this->settings->get( 'radius', 8 ) ) );
		if ( 8 !== $radius ) {
			$css .= '--uxc-r:' . $radius . 'px;';
		}
		return '' === $css ? '' : '.uxc,.uxc-fab,.uxc-ph{' . $css . '}';
	}

	/**
	 * Hold back a registered script (by its URL, or by a handle override).
	 *
	 * @param string $tag    Tag HTML (script plus its translations and inline code).
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 */
	public function filter_script_tag( $tag, $handle = '', $src = '' ) {
		if ( ! is_string( $tag ) || ! (bool) $this->settings->get( 'block_scripts', true ) || ! $this->runs_here() ) {
			return $tag;
		}
		/**
		 * Decide a script by its handle. Return a category (preferences, statistics, marketing) to hold it back, an
		 * empty string or `necessary` to never hold it back, or null to let the URL decide.
		 *
		 * @param string|null $category Category or null.
		 * @param string      $handle   Script handle.
		 * @param string      $src      Script URL.
		 */
		$override = apply_filters( 'uxstudio_consent_script_category', null, (string) $handle, (string) $src );
		if ( is_string( $override ) ) {
			if ( in_array( $override, Services::CATEGORIES, true ) ) {
				return Blocker::gate_scripts( $tag, $override, (string) $handle );
			}
			return $tag;
		}
		return Blocker::filter_script_tag( $tag, (string) $src );
	}

	/**
	 * Replace third-party iframes (YouTube, maps...) by placeholders the banner script fills.
	 *
	 * @param mixed $html Content or embed HTML.
	 * @return mixed
	 */
	public function filter_iframes( $html ) {
		if ( ! is_string( $html ) || ! (bool) $this->settings->get( 'block_iframes', true ) || ! $this->runs_here() ) {
			return $html;
		}
		return Blocker::gate_iframes( $html );
	}

	/**
	 * Start the optional page buffer (safety net for hard-coded snippets).
	 */
	public function maybe_buffer(): void {
		if ( ! (bool) $this->settings->get( 'buffer_guard', false ) || ! $this->runs_here() ) {
			return;
		}
		ob_start( array( Blocker::class, 'filter_page' ) );
	}

	/**
	 * Shortcode [uxstudio_cookie_settings label="..."]: a button that reopens the banner.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode( $atts ): string {
		$atts  = shortcode_atts( array( 'label' => '' ), is_array( $atts ) ? $atts : array(), 'uxstudio_cookie_settings' );
		$label = '' !== trim( (string) $atts['label'] ) ? (string) $atts['label'] : __( 'Cookie settings', 'ux-studio' );
		return '<button type="button" class="uxc-open" data-uxc-open>' . esc_html( $label ) . '</button>';
	}

	/**
	 * Print the config the banner script reads (a JSON block is data, so a strict CSP does not touch it).
	 */
	public function print_config(): void {
		if ( ! $this->runs_here() ) {
			return;
		}
		$json = wp_json_encode( $this->frontend_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return;
		}
		echo '<script type="application/json" id="uxc-config">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with the HEX flags, cannot close the element.
	}

	/**
	 * A banner text. A template may have its own shorter DEFAULT; the text the owner wrote always wins, and so does a text
	 * saved unchanged from the form (which stores the default of the language the owner works in).
	 *
	 * @param string                $key      Setting key.
	 * @param string                $default  Generic default.
	 * @param array<string, string> $by_tpl   Default by template.
	 */
	private function text( string $key, string $default, array $by_tpl ): string {
		$value = (string) $this->settings->get( $key, $default );
		$tpl   = $this->template();
		return ( $value === $default && isset( $by_tpl[ $tpl ] ) ) ? $by_tpl[ $tpl ] : $value;
	}

	/**
	 * Settings and texts for the banner script. Same for every visitor.
	 *
	 * @return array<string, mixed>
	 */
	public function frontend_config(): array {
		$s       = $this->settings;
		$eu_only = 'eu' === (string) $s->get( 'display_to', 'all' );
		$policy  = trim( (string) $s->get( 'policy_url', '' ) );
		if ( '' === $policy && function_exists( 'get_privacy_policy_url' ) ) {
			$policy = get_privacy_policy_url();
		}

		$lists = array();
		foreach ( Services::CATEGORIES as $category ) {
			$names = array();
			foreach ( preg_split( '/\R/', (string) $s->get( 'services_' . $category, '' ) ) ?: array() as $line ) {
				$line = sanitize_text_field( $line );
				if ( '' !== $line ) {
					$names[] = $line;
				}
			}
			$lists[ $category ] = $names;
		}
		foreach ( GtmKit::services() + $this->pixel_services() as $label => $category ) {
			$lists[ $category ][] = $label;
		}
		foreach ( Blocker::seen() as $category => $labels ) {
			foreach ( $labels as $label ) {
				$lists[ $category ][] = $label;
			}
		}
		// Own necessary cookies plus the services the inventory found and the owner classified (F2), the same on every page.
		foreach ( Inventory::banner_services() as $category => $labels ) {
			foreach ( $labels as $label ) {
				$lists[ $category ][] = $label;
			}
		}

		$categories = array(
			array(
				'id' => 'necessary',
				'n'  => __( 'Necessary', 'ux-studio' ),
				'd'  => __( 'Needed for the site to work, for example to remember your cookie choice, keep forms and logins secure or hold a shopping cart. They cannot be switched off.', 'ux-studio' ),
				's'  => array(),
			),
			array(
				'id' => 'preferences',
				'n'  => __( 'Preferences', 'ux-studio' ),
				'd'  => __( 'Remember your settings and earlier choices, such as a chat conversation, so the site works the way you like.', 'ux-studio' ),
			),
			array(
				'id' => 'statistics',
				'n'  => __( 'Statistics', 'ux-studio' ),
				'd'  => __( 'Show us which pages are visited and how the site is used, so we can improve it. The figures are used in aggregate.', 'ux-studio' ),
			),
			array(
				'id' => 'marketing',
				'n'  => __( 'Marketing', 'ux-studio' ),
				'd'  => __( 'Measure advertising and show offers that may interest you, here and on other sites. Also covers embedded video, maps and social content.', 'ux-studio' ),
			),
		);
		foreach ( $categories as $i => $category ) {
			$categories[ $i ]['s'] = array_values( array_unique( $lists[ $category['id'] ] ?? array() ) );
		}

		$config = array(
			'v'      => $this->policy_version(),
			'days'   => max( 1, min( 365, (int) $s->get( 'expiry_days', 365 ) ) ),
			'gcm'    => 'off' !== $this->consent_mode(),
			'tpl'    => $this->template(),
			'layout' => 'bar' === (string) $s->get( 'layout', 'box' ) ? 'bar' : 'box',
			'pos'    => 'right' === (string) $s->get( 'position', 'left' ) ? 'right' : 'left',
			'fab'    => (bool) $s->get( 'show_reopen', true ),
			'c'      => $categories,
			't'      => array(
				'title'    => $this->text( 'title', __( 'Your privacy choices', 'ux-studio' ), array( 'cookie' => __( 'A word about cookies', 'ux-studio' ) ) ),
				'msg'      => $this->text(
					'message',
					__( 'We use cookies so this site works and, if you agree, to measure visits and show relevant offers. Choose what you allow. You can change your decision at any time.', 'ux-studio' ),
					array( 'slim' => __( 'We use cookies to run this site and, with your consent, to measure visits and show relevant offers.', 'ux-studio' ) )
				),
				'accept'   => $this->text( 'btn_accept', __( 'Accept all', 'ux-studio' ), array( 'cookie' => __( 'Sure', 'ux-studio' ) ) ),
				'reject'   => $this->text( 'btn_reject', __( 'Reject all', 'ux-studio' ), array( 'cookie' => __( 'No thanks', 'ux-studio' ) ) ),
				'settings' => (string) $s->get( 'btn_settings', __( 'Customize', 'ux-studio' ) ),
				'save'     => (string) $s->get( 'btn_save', __( 'Save choices', 'ux-studio' ) ),
				'close'    => __( 'Close', 'ux-studio' ),
				'always'   => __( 'Always on', 'ux-studio' ),
				'services' => __( 'Services', 'ux-studio' ),
				'none'     => __( 'None in use', 'ux-studio' ),
				'ph'       => __( 'This content comes from %s. Loading it may set cookies and share data with that service.', 'ux-studio' ),
				'load'     => __( 'Load content', 'ux-studio' ),
				'fab'      => __( 'Cookie settings', 'ux-studio' ),
				'policy'   => (string) $s->get( 'policy_label', __( 'Cookie policy', 'ux-studio' ) ),
				'cookies'  => __( 'Cookies', 'ux-studio' ),
				'keep'     => __( 'Stored for', 'ux-studio' ),
				'prov'     => __( 'Provider', 'ux-studio' ),
				'ctry'     => __( 'Country', 'ux-studio' ),
				'pol'      => __( 'Privacy policy of the provider', 'ux-studio' ),
				'nock'     => __( 'No cookies of its own.', 'ux-studio' ),
				'cid'      => __( 'Consent ID (quote it if you ask us about your choice):', 'ux-studio' ),
			),
		);
		if ( 'cookiebot' === $config['tpl'] ) {
			// Only this template has tabs; its texts do not travel with the others.
			$config['t'] += array(
				'tab_c' => __( 'Consent', 'ux-studio' ),
				'tab_d' => __( 'Details', 'ux-studio' ),
				'tab_a' => __( 'About cookies', 'ux-studio' ),
				'tabs'  => __( 'Cookie banner sections', 'ux-studio' ),
				'about' => __( 'Cookies are small files that a website stores in your browser. The necessary ones keep the site working. All the others are used only if you allow them, and you can change or withdraw your choice at any time with the cookie settings button.', 'ux-studio' ),
			);
		}
		if ( '' !== $policy ) {
			$config['policy'] = esc_url_raw( $policy );
		}
		// Cookies of the services are loaded when the visitor opens the detail, not carried in every page.
		$config['ck'] = esc_url_raw( rest_url( 'uxstudio/v1/consent/cookies' ) );
		if ( Records::enabled() ) {
			// Where the banner reports a decision (consent records, phase F5). Absent = nothing is reported.
			$config['rec'] = esc_url_raw( rest_url( 'uxstudio/v1/consent/record' ) );
		}
		// A returning visitor of the old banner (Complianz) keeps the choice; only while the owner has this switched on.
		$legacy = strtolower( trim( (string) $s->get( 'legacy_prefix', '' ) ) );
		if ( 1 === preg_match( '/^[a-z0-9_]{1,20}$/', $legacy ) ) {
			$config['leg'] = array(
				'p' => $legacy,
				'v' => max( 0, (int) $s->get( 'legacy_policy_id', 0 ) ),
			);
		}
		if ( $eu_only ) {
			$config['geo'] = esc_url_raw( rest_url( 'uxstudio/v1/consent/region' ) );
		}
		if ( 'right' === (string) $s->get( 'reopen_position', 'left' ) ) {
			$config['fabpos'] = 'right';
		}
		return $config;
	}

	/**
	 * Services of the Pixel & Tag Manager module, label => category, from its stored IDs.
	 *
	 * @return array<string, string>
	 */
	private function pixel_services(): array {
		$plugin = \UxStudio\Plugin::instance();
		if ( ! isset( $plugin->modules ) || ! in_array( 'pixel-tag-manager', $plugin->modules->enabled_ids(), true ) ) {
			return array();
		}
		$ptm = (array) get_option( 'uxstudio_pixel_tag_manager', array() );
		$out = array();
		$ga  = strtoupper( (string) ( $ptm['google_analytics'] ?? '' ) );
		if ( '' !== $ga ) {
			$out[ 0 === strpos( $ga, 'AW-' ) ? 'Google Ads' : 'Google Analytics' ] = 0 === strpos( $ga, 'AW-' ) ? 'marketing' : 'statistics';
		}
		if ( '' !== (string) ( $ptm['facebook_pixel'] ?? '' ) ) {
			$out['Facebook Pixel'] = 'marketing';
		}
		if ( '' !== (string) ( $ptm['pinterest_tag'] ?? '' ) ) {
			$out['Pinterest Tag'] = 'marketing';
		}
		return $out;
	}

	/**
	 * Settings are saved from the admin; a cached page still carries the old banner config, so empty the common page caches.
	 *
	 * @param array $input Raw input.
	 * @return array<string, mixed>
	 */
	public function save_settings( array $input ): array {
		$values = parent::save_settings( $input );
		self::purge_page_caches();
		return $values;
	}

	/**
	 * Ask the usual page-cache plugins to forget their pages. Each call is guarded, an absent plugin does nothing.
	 */
	public static function purge_page_caches(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'wpfc_clear_all_cache' );
	}

	/**
	 * Settings schema for the generic renderer (the page groups them by section).
	 */
	public function settings_schema(): array {
		$display = __( 'Display', 'ux-studio' );
		$texts   = __( 'Texts', 'ux-studio' );
		$look    = __( 'Appearance', 'ux-studio' );
		$block   = __( 'Blocking', 'ux-studio' );
		$google  = __( 'Google Consent Mode', 'ux-studio' );
		$records = __( 'Consent records', 'ux-studio' );

		return array(
			// --- Display ---
			array(
				'key'     => 'display_to',
				'section' => $display,
				'type'    => 'select',
				'label'   => __( 'Show the banner to', 'ux-studio' ),
				'options' => array(
					'all' => __( 'All visitors', 'ux-studio' ),
					'eu'  => __( 'Visitors from the EU and Europe only', 'ux-studio' ),
				),
				'default' => 'all',
			),
			array(
				'key'     => 'policy_version',
				'section' => $display,
				'type'    => 'number',
				'label'   => __( 'Consent version', 'ux-studio' ),
				'default' => 1,
			),
			array(
				'key'     => 'expiry_days',
				'section' => $display,
				'type'    => 'number',
				'label'   => __( 'Remember the choice for (days)', 'ux-studio' ),
				'default' => 365,
			),
			array(
				'key'     => 'legacy_prefix',
				'section' => $display,
				'type'    => 'text',
				'label'   => __( 'Take over choices from the old banner (cookie name prefix)', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'legacy_policy_id',
				'section' => $display,
				'type'    => 'number',
				'label'   => __( 'Policy number of the old banner', 'ux-studio' ),
				'default' => 0,
				'show_if' => array( 'key' => 'legacy_prefix', 'truthy' => true ),
			),
			array(
				'key'     => 'show_reopen',
				'section' => $display,
				'type'    => 'toggle',
				'label'   => __( 'Show a small button to change the choice later', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'reopen_position',
				'section' => $display,
				'type'    => 'select',
				'label'   => __( 'Position of that button', 'ux-studio' ),
				'options' => array(
					'left'  => __( 'Bottom left', 'ux-studio' ),
					'right' => __( 'Bottom right', 'ux-studio' ),
				),
				'default' => 'left',
				'show_if' => array( 'key' => 'show_reopen', 'truthy' => true ),
			),
			// --- Texts ---
			array(
				'key'     => 'title',
				'section' => $texts,
				'type'    => 'text',
				'label'   => __( 'Title', 'ux-studio' ),
				'default' => __( 'Your privacy choices', 'ux-studio' ),
			),
			array(
				'key'     => 'message',
				'section' => $texts,
				'type'    => 'textarea',
				'label'   => __( 'Message', 'ux-studio' ),
				'default' => __( 'We use cookies so this site works and, if you agree, to measure visits and show relevant offers. Choose what you allow. You can change your decision at any time.', 'ux-studio' ),
			),
			array(
				'key'     => 'policy_url',
				'section' => $texts,
				'type'    => 'text',
				'label'   => __( 'Cookie policy address', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'policy_label',
				'section' => $texts,
				'type'    => 'text',
				'label'   => __( 'Cookie policy link text', 'ux-studio' ),
				'default' => __( 'Cookie policy', 'ux-studio' ),
			),
			array(
				'key'     => 'btn_accept',
				'section' => $texts,
				'type'    => 'text',
				'label'   => __( 'Accept button', 'ux-studio' ),
				'default' => __( 'Accept all', 'ux-studio' ),
			),
			array(
				'key'     => 'btn_reject',
				'section' => $texts,
				'type'    => 'text',
				'label'   => __( 'Reject button', 'ux-studio' ),
				'default' => __( 'Reject all', 'ux-studio' ),
			),
			array(
				'key'     => 'btn_settings',
				'section' => $texts,
				'type'    => 'text',
				'label'   => __( 'Customize button', 'ux-studio' ),
				'default' => __( 'Customize', 'ux-studio' ),
			),
			array(
				'key'     => 'btn_save',
				'section' => $texts,
				'type'    => 'text',
				'label'   => __( 'Save button', 'ux-studio' ),
				'default' => __( 'Save choices', 'ux-studio' ),
			),
			array(
				'key'     => 'services_preferences',
				'section' => $texts,
				'type'    => 'textarea',
				'label'   => __( 'Extra services: Preferences', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'services_statistics',
				'section' => $texts,
				'type'    => 'textarea',
				'label'   => __( 'Extra services: Statistics', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'services_marketing',
				'section' => $texts,
				'type'    => 'textarea',
				'label'   => __( 'Extra services: Marketing', 'ux-studio' ),
				'default' => '',
			),
			// --- Appearance ---
			array(
				'key'          => 'template',
				'section'      => $look,
				'type'         => 'image_choice',
				'label'        => __( 'Template', 'ux-studio' ),
				'help'         => __( 'The look of the banner. It works the same in every template.', 'ux-studio' ),
				'options'      => array(
					'classic'   => __( 'Classic', 'ux-studio' ),
					'cookiebot' => __( 'Tabs', 'ux-studio' ),
					'cookie'    => __( 'Card', 'ux-studio' ),
					'slim'      => __( 'Slim bar', 'ux-studio' ),
				),
				'images'       => array(
					'classic'   => UXSTUDIO_URL . 'assets/consent/templates/classic.svg',
					'cookiebot' => UXSTUDIO_URL . 'assets/consent/templates/cookiebot.svg',
					'cookie'    => UXSTUDIO_URL . 'assets/consent/templates/cookie.svg',
					'slim'      => UXSTUDIO_URL . 'assets/consent/templates/slim.svg',
				),
				'descriptions' => array(
					'classic'   => __( 'A box in a corner or a bar across the bottom.', 'ux-studio' ),
					'cookiebot' => __( 'A wide panel with tabs and switches.', 'ux-studio' ),
					'cookie'    => __( 'A light card with round buttons.', 'ux-studio' ),
					'slim'      => __( 'One thin bar across the page.', 'ux-studio' ),
				),
				'default'      => 'classic',
			),
			array(
				'key'     => 'layout',
				'section' => $look,
				'type'    => 'select',
				'show_if' => array( 'key' => 'template', 'equals' => 'classic' ),
				'label'   => __( 'Layout', 'ux-studio' ),
				'options' => array(
					'box' => __( 'Box in a corner', 'ux-studio' ),
					'bar' => __( 'Bar across the bottom', 'ux-studio' ),
				),
				'default' => 'box',
			),
			array(
				'key'     => 'position',
				'section' => $look,
				'type'    => 'select',
				'label'   => __( 'Side of the box', 'ux-studio' ),
				'options' => array(
					'left'  => __( 'Left', 'ux-studio' ),
					'right' => __( 'Right', 'ux-studio' ),
				),
				'default' => 'left',
				'show_if' => array( 'key' => 'template', 'in' => array( 'classic', 'cookie' ) ),
			),
			array(
				'key'     => 'color_bg',
				'section' => $look,
				'type'    => 'color',
				'label'   => __( 'Background', 'ux-studio' ),
				'default' => '#ffffff',
			),
			array(
				'key'     => 'color_text',
				'section' => $look,
				'type'    => 'color',
				'label'   => __( 'Text', 'ux-studio' ),
				'default' => '#1d2327',
			),
			array(
				'key'     => 'color_accent',
				'section' => $look,
				'type'    => 'color',
				'label'   => __( 'Buttons', 'ux-studio' ),
				'default' => '#1d4ed8',
			),
			array(
				'key'     => 'color_accent_text',
				'section' => $look,
				'type'    => 'color',
				'label'   => __( 'Button text', 'ux-studio' ),
				'default' => '#ffffff',
			),
			array(
				'key'     => 'radius',
				'section' => $look,
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'ux-studio' ),
				'default' => 8,
			),
			// --- Blocking ---
			array(
				'key'     => 'block_scripts',
				'section' => $block,
				'type'    => 'toggle',
				'label'   => __( 'Hold back tracking scripts until the visitor agrees', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'block_iframes',
				'section' => $block,
				'type'    => 'toggle',
				'label'   => __( 'Replace embedded videos and maps by a "Load content" box', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'youtube_nocookie',
				'section' => $block,
				'type'    => 'toggle',
				'label'   => __( 'Use the privacy-enhanced YouTube address', 'ux-studio' ),
				'default' => true,
				'show_if' => array( 'key' => 'block_iframes', 'truthy' => true ),
			),
			array(
				'key'     => 'buffer_guard',
				'section' => $block,
				'type'    => 'toggle',
				'label'   => __( 'Also catch snippets pasted directly into the page', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'custom_rules',
				'section' => $block,
				'type'    => 'textarea',
				'label'   => __( 'Own rules', 'ux-studio' ),
				'default' => '',
			),
			// --- Google Consent Mode ---
			array(
				'key'     => 'consent_mode',
				'section' => $google,
				'type'    => 'select',
				'label'   => __( 'Google Consent Mode v2', 'ux-studio' ),
				'options' => array(
					'off'      => __( 'Off', 'ux-studio' ),
					'basic'    => __( 'Basic: Google tags wait for consent', 'ux-studio' ),
					'advanced' => __( 'Advanced: Google tags load at once and follow the signal', 'ux-studio' ),
				),
				'default' => 'basic',
			),
			array(
				'key'     => 'wait_for_update',
				'section' => $google,
				'type'    => 'number',
				'label'   => __( 'Wait for the saved choice (milliseconds)', 'ux-studio' ),
				'default' => 500,
				'show_if' => array( 'key' => 'consent_mode', 'in' => array( 'basic', 'advanced' ) ),
			),
			// --- Consent records (F5) ---
			array(
				'key'     => 'log_enabled',
				'section' => $records,
				'type'    => 'toggle',
				'label'   => __( 'Keep a record of every choice (proof of consent)', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'log_retention_days',
				'section' => $records,
				'type'    => 'number',
				'label'   => __( 'Keep records for (days)', 'ux-studio' ),
				'default' => Records::DEFAULT_DAYS,
				'show_if' => array( 'key' => 'log_enabled', 'truthy' => true ),
			),
		);
	}
}
