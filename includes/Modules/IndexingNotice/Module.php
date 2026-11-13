<?php
/**
 * Indexing & Sharing module (formerly Indexing Notice).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Whether search engines and social networks see the site as it should be:
 *
 *  - the admin bar warning while "Discourage search engines from indexing this site" is on (ported from the
 *    legacy module, unchanged and independent of the options below),
 *  - after a published post is saved, Facebook is asked to fetch its preview again (through UX1 Network app, or with
 *    own keys) and IndexNow tells Bing, Seznam and others that it changed (PLAN.md 39).
 *
 * The id stays `indexing-notice`, so the enabled state, exported settings and the starter pack item keep working.
 */
final class Module extends BaseModule {

	/** Encrypted option holding the Facebook app secret (own keys mode). */
	public const SECRET_FB = 'uxstudio_secret_indexing_notice_fb_app_secret';

	private ?Dispatcher $dispatcher = null;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_notice' ), 1000 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 20, 3 );
		add_action( Dispatcher::CRON_HOOK, array( $this->dispatcher(), 'run' ) );
		add_action( 'template_redirect', array( $this, 'serve_indexnow_key' ), 1 );

		if ( is_admin() ) {
			( new PostActions( $this ) )->register();
		}
	}

	/**
	 * Undo what outlives the request: the waiting event and the queue. Settings and the key stay.
	 */
	public function on_disable(): void {
		Dispatcher::clear();
	}

	/**
	 * The queue runner.
	 */
	public function dispatcher(): Dispatcher {
		if ( null === $this->dispatcher ) {
			$this->dispatcher = new Dispatcher( $this );
		}
		return $this->dispatcher;
	}

	/**
	 * One stored setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 */
	public function setting( string $key, $default = null ) {
		return $this->settings->get( $key, $default );
	}

	/**
	 * The Facebook app secret (own keys mode); never leaves the server.
	 */
	public function fb_secret(): string {
		return Security::get_secret( self::SECRET_FB );
	}

	/**
	 * A post was saved: put its address in the queue (published, viewable, not a revision or autosave).
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post.
	 * @param bool         $update  Whether this is an update.
	 */
	public function on_post_saved( $post_id, $post = null, $update = false ): void {
		unset( $update );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->dispatcher()->enqueue( $post );
	}

	/**
	 * template_redirect: `/<key>.txt` for IndexNow.
	 */
	public function serve_indexnow_key(): void {
		IndexNow::serve( (bool) $this->settings->get( 'indexnow_enabled', true ) );
	}

	/**
	 * REST controller (verify keys, status).
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings schema. Two cards: social previews and IndexNow. The help texts are in help.php.
	 */
	public function settings_schema(): array {
		$social = __( 'Social media previews', 'ux-studio' );
		$index  = __( 'Tell search engines about changes (IndexNow)', 'ux-studio' );
		$on     = array(
			'key'    => 'social_refresh_enabled',
			'truthy' => true,
		);
		$own    = array(
			'key'    => 'social_key_source',
			'equals' => 'own',
		);

		return array(
			array(
				'key'        => 'social_refresh_enabled',
				'group'      => $social,
				'group_help' => __( 'After you save a published page, Facebook fetches its title, description and image again.', 'ux-studio' ),
				'type'       => 'toggle',
				'label'      => __( 'Refresh previews on Facebook after a change', 'ux-studio' ),
				'default'    => false,
			),
			array(
				'key'     => 'social_key_source',
				'group'   => $social,
				'show_if' => $on,
				'type'    => 'select',
				'label'   => __( 'Where the Facebook keys come from', 'ux-studio' ),
				'options' => array(
					'ca'  => __( 'UX1 Network app (nothing to fill in)', 'ux-studio' ),
					'own' => __( 'My own Facebook app', 'ux-studio' ),
				),
				'default' => 'ca',
			),
			array(
				'key'     => 'social_fb_app_id',
				'group'   => $social,
				'show_if' => array( $on, $own ),
				'type'    => 'text',
				'label'   => __( 'App ID', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'social_fb_app_secret',
				'group'   => $social,
				'show_if' => array( $on, $own ),
				'type'    => 'text',
				'label'   => __( 'App secret', 'ux-studio' ),
				'help'    => __( 'Stored encrypted and never shown again. Leave blank to keep the current secret.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'social_linkedin_link',
				'group'   => $social,
				'show_if' => $on,
				'type'    => 'toggle',
				'label'   => __( 'Show a link to the LinkedIn Post Inspector in the editor', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'        => 'indexnow_enabled',
				'group'      => $index,
				'group_help' => __( 'Bing, Seznam and other search engines learn about a changed page at once.', 'ux-studio' ),
				'type'       => 'toggle',
				'label'      => __( 'Send changed pages to IndexNow', 'ux-studio' ),
				'default'    => true,
			),
		);
	}

	/**
	 * Never hand the Facebook secret back to the browser; only whether one is stored.
	 */
	public function settings_values(): array {
		$values                         = parent::settings_values();
		$values['social_fb_app_secret'] = '';
		$values['has_social_fb_app_secret'] = '' !== $this->fb_secret();
		return $values;
	}

	/**
	 * Keep the Facebook secret out of the plain settings option, clean the app id, and make the IndexNow key
	 * the first time the feature is on.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'social_fb_app_secret', $input ) ) {
			$secret = trim( (string) $input['social_fb_app_secret'] );
			if ( '' !== $secret ) {
				Security::store_secret( self::SECRET_FB, $secret );
			}
		}
		unset( $input['social_fb_app_secret'], $input['has_social_fb_app_secret'] );

		if ( array_key_exists( 'social_fb_app_id', $input ) ) {
			$input['social_fb_app_id'] = (string) preg_replace( '/\D/', '', (string) $input['social_fb_app_id'] );
		}
		if ( array_key_exists( 'social_key_source', $input ) && ! in_array( $input['social_key_source'], array( 'ca', 'own' ), true ) ) {
			$input['social_key_source'] = 'ca';
		}

		$saved = parent::save_settings( $input );
		if ( ! empty( $saved['indexnow_enabled'] ) ) {
			IndexNow::key();
		}
		return $saved;
	}

	/* ── Admin bar notice ── */

	/**
	 * Whether the notice should be shown for the current request/user.
	 */
	private function should_run(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! is_admin_bar_showing() ) {
			return false;
		}
		return 0 === (int) get_option( 'blog_public' );
	}

	/**
	 * Add the warning node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_notice( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! $this->should_run() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'uxstudio-indexing-notice',
				'parent' => 'top-secondary',
				'title'  => __( 'Search Engines Discouraged', 'ux-studio' ),
				'href'   => admin_url( 'options-reading.php' ),
			)
		);
	}

	/**
	 * Attach the highlight styling to the admin bar stylesheet.
	 */
	public function enqueue_styles(): void {
		if ( ! $this->should_run() ) {
			return;
		}

		$css = '#wp-admin-bar-uxstudio-indexing-notice a{color:#fff!important;background:#e0281f!important;transition:.1s ease-in-out}'
			. '#wp-admin-bar-uxstudio-indexing-notice a:focus,#wp-admin-bar-uxstudio-indexing-notice a:hover{text-decoration:underline;background:#bd2b24!important}';

		wp_add_inline_style( 'admin-bar', $css );
	}
}
