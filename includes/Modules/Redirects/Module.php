<?php
/**
 * Redirects module - SEO-safe replacement of the Redirection plugin that an
 * AI agent can drive natively (Abilities API / MCP).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

use UxStudio\Core\DB;
use UxStudio\Core\Retention;
use UxStudio\Modules\BaseModule;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Pieces: Engine (front-end matching on init), Repository (rule store with
 * chain/loop protection), NotFoundLog (aggregated 404s), Tester (URL test +
 * audit), Importer (CSV, Redirection plugin), SiteIndex (current URLs for
 * mapping), Abilities (AI tools), RestController (admin SPA).
 */
final class Module extends BaseModule {

	private const CLEANUP_HOOK = 'uxstudio_redirects_cleanup';

	/** Max descendants re-mapped when a parent page changes its slug. */
	private const MAX_DESCENDANTS = 200;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		self::ensure_tables();

		( new Engine( $this->settings ) )->register();
		Abilities::register();
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( (bool) $this->settings->get( 'log_404', true ) ) {
			add_action( 'template_redirect', array( NotFoundLog::class, 'record' ), 1 );
		}
		if ( (bool) $this->settings->get( 'monitor_slugs', true ) ) {
			add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		}

		Retention::ensure_scheduled( self::CLEANUP_HOOK );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
	}

	/**
	 * Create / upgrade the module's tables. Also called by the migration wizard, which fills the
	 * rules table while the module is still off.
	 */
	public static function ensure_tables(): void {
		DB::ensure_module_tables(
			'redirects',
			3,
			static function ( int $from ): void {
				global $wpdb;
				// v3 (B2): per-rule `case_sensitive`, `query_mode` and `source_cs` (dbDelta adds the columns; existing rules get
				// the defaults, which behave exactly as before). The hit counter columns (`hits`, `last_hit`) have existed since v1.
				// v2 (A2): several rules per source, told apart by their conditions. The v1 table had a UNIQUE key on
				// the source alone; dbDelta cannot turn it into a plain key, so it is dropped first. Existing rows keep
				// working unchanged (no conditions, cond_key '').
				if ( $from >= 1 ) {
					$table = Repository::table();
					// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$was    = $wpdb->suppress_errors( true );
					$unique = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'source_hash' AND Non_unique = 0" );
					if ( $unique ) {
						$wpdb->query( "ALTER TABLE {$table} DROP INDEX source_hash" );
					}
					$wpdb->suppress_errors( $was );
					// phpcs:enable
				}
				dbDelta( Repository::schema() );
				dbDelta( NotFoundLog::schema() );
				Repository::refresh_state();
			}
		);
	}

	/**
	 * Module switched off: stop cron, drop the engine snapshot (rules stay).
	 */
	public function on_disable(): void {
		Retention::unschedule( self::CLEANUP_HOOK );
		Repository::clear_state();
	}

	/**
	 * Register the REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController() )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Daily cron: 404 log retention.
	 */
	public function cleanup(): void {
		NotFoundLog::cleanup( (int) $this->settings->get( 'log_404_retention_days', 90 ) );
	}

	/**
	 * A published post changed its URL (slug, parent, date-based permalink):
	 * 301 the old URL to the post, plus its descendants for hierarchical
	 * types. Rules are bound to the post id, so further changes never chain.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 */
	public function on_post_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}

		$old = (string) get_permalink( $before );
		$new = (string) get_permalink( $after );
		if ( '' === $old || Url::target_key( $old ) === Url::target_key( $new ) ) {
			return;
		}

		$this->map_moved( $post_id, $old, $new );

		if ( is_post_type_hierarchical( $after->post_type ) ) {
			$children = get_pages(
				array(
					'child_of'    => $post_id,
					'post_type'   => $after->post_type,
					'post_status' => 'publish',
					'number'      => self::MAX_DESCENDANTS,
				)
			);
			foreach ( is_array( $children ) ? $children : array() as $child ) {
				$child_new = (string) get_permalink( $child );
				// Same tail, old parent prefix.
				if ( str_starts_with( $child_new, $new ) ) {
					$this->map_moved( (int) $child->ID, $old . substr( $child_new, strlen( $new ) ), $child_new );
				}
			}
		}
	}

	/**
	 * Create old URL -> post rule and drop any rule sitting on the new URL.
	 *
	 * @param int    $post_id Post id.
	 * @param string $old     Old permalink.
	 * @param string $new     New permalink.
	 */
	private function map_moved( int $post_id, string $old, string $new ): void {
		// Content lives at the new URL now - a rule there would hide it
		// (e.g. the slug was changed back).
		Repository::delete_by_source_path( explode( '?', Url::target_key( $new ), 2 )[0] );

		Repository::save(
			array(
				'source'         => Url::to_relative( $old ),
				'target_post_id' => $post_id,
				'code'           => 301,
				'origin'         => 'auto',
				/* translators: %s: new URL path */
				'note'           => sprintf( __( 'URL changed to %s', 'ux-studio' ), Url::to_relative( $new ) ),
			),
			'update'
		);
	}

	/**
	 * Settings schema.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'monitor_slugs',
				'type'    => 'toggle',
				'label'   => __( 'Redirect automatically when a URL changes', 'ux-studio' ),
				'help'    => __( 'When a published post or page changes its slug or parent, a 301 from the old URL is created (also for child pages).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'pass_query',
				'type'    => 'toggle',
				'label'   => __( 'Keep the query string', 'ux-studio' ),
				'help'    => __( 'Carries ?utm_source, gclid and similar parameters over to the target so campaign tracking survives the redirect.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'permanent_cache_ttl',
				'type'    => 'number',
				'label'   => __( 'Browser cache for permanent redirects (seconds)', 'ux-studio' ),
				'help'    => __( 'Browsers cache a 301 forever unless told otherwise. 3600 keeps mistakes fixable; 0 = no caching.', 'ux-studio' ),
				'default' => 3600,
			),
			array(
				'key'     => 'track_hits',
				'type'    => 'toggle',
				'label'   => __( 'Count hits per rule', 'ux-studio' ),
				'help'    => __( 'Shows which old URLs still get traffic (one small database write per redirect).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'log_404',
				'type'    => 'toggle',
				'label'   => __( 'Log 404 errors', 'ux-studio' ),
				'help'    => __( 'Aggregated per URL with hit count and referrer, no IP addresses.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'log_404_retention_days',
				'type'    => 'number',
				'label'   => __( 'Keep 404 entries for (days)', 'ux-studio' ),
				'help'    => __( 'Entries not seen for this long are removed. 0 = keep.', 'ux-studio' ),
				'default' => 90,
			),
		);
	}
}
