<?php
/**
 * Admin: one menu page mounting the SPA, assets, script translations.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the single UX Studio admin page and enqueues the SPA bundle
 * only there (never on other admin screens).
 */
final class Admin {

	private const SLUG = 'ux-studio';

	private Modules $modules;

	public function __construct( Modules $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Hook menu + assets.
	 */
	public function register(): void {
		// Priority 9 (before the default 10) so the top-level menu exists in
		// $menu before any module calls add_submenu_page(). Otherwise WordPress
		// cannot auto-create the duplicate first submenu that links to the SPA,
		// and the first module submenu (e.g. File Manager) hijacks the top-level
		// click, producing a broken /wp-admin/ux-studio-file-manager URL.
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Top-level menu entry.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'UX Studio', 'ux-studio' ),
			__( 'UX Studio', 'ux-studio' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-layout', // Replaced by lucide icon via CSS; WP menu API requires a dashicon or SVG.
			59
		);
	}

	/**
	 * SPA mount point.
	 */
	public function render(): void {
		echo '<div id="ux-studio-root"></div>';
	}

	/**
	 * Enqueue the built SPA bundle only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		// The generic settings renderer can open the WP media modal (media field).
		wp_enqueue_media();

		// The build embeds a content hash in filenames (see webpack.config.js) so
		// updates bust caches even where a host strips the ?ver query string.
		// Resolve the actual hashed files at runtime; fall back to the plain names.
		$js_file  = self::resolve_build_file( 'index', 'js' );
		$css_file = self::resolve_build_file( 'style-index', 'css' );

		$asset_file = UXSTUDIO_PATH . 'build/' . preg_replace( '/\.js$/', '.asset.php', $js_file );
		$asset      = is_readable( $asset_file )
			? include $asset_file
			: array( 'dependencies' => array(), 'version' => UXSTUDIO_VERSION );

		wp_enqueue_script(
			'ux-studio-app',
			UXSTUDIO_URL . 'build/' . $js_file,
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style(
			'ux-studio-app',
			UXSTUDIO_URL . 'build/' . $css_file,
			array( 'wp-components' ),
			$asset['version']
		);

		// JS translations. WP loads languages/ux-studio-<locale>-<md5(relative src)>.json
		// where the src is the hashed build/index.<hash>.js; the build/CI merges every
		// lazy-chunk's JSON into that one file (bin/merge-json-translations.php) so
		// code-split module pages are translated too, not just the main bundle.
		wp_set_script_translations( 'ux-studio-app', 'ux-studio', UXSTUDIO_PATH . 'languages' );

		wp_localize_script(
			'ux-studio-app',
			'uxStudioBoot',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'uxstudio/v1' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'version'    => UXSTUDIO_VERSION,
				'apiVersion' => UXSTUDIO_API_VERSION,
				'locale'     => get_user_locale(),
			)
		);

		/**
		 * Extension API: add-ons enqueue their page bundles here (after core app).
		 */
		do_action( 'ux_studio/admin_assets' );
	}

	/**
	 * Resolve a content-hashed build file (e.g. index.<hash>.js), falling back
	 * to the plain name when an unhashed build is present.
	 *
	 * @param string $base File base name (e.g. 'index', 'style-index').
	 * @param string $ext  Extension without the dot (e.g. 'js', 'css').
	 * @return string Basename inside build/ (e.g. 'index.508ec….js').
	 */
	private static function resolve_build_file( string $base, string $ext ): string {
		$matches = glob( UXSTUDIO_PATH . 'build/' . $base . '.*.' . $ext ) ?: array();
		foreach ( $matches as $match ) {
			// Skip source maps / sidecar files; take the first real asset.
			if ( substr( $match, -( strlen( $ext ) + 1 ) ) === '.' . $ext ) {
				return basename( $match );
			}
		}
		return $base . '.' . $ext;
	}
}
