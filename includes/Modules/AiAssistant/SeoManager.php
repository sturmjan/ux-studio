<?php
/**
 * SEO meta storage for AI-generated titles/descriptions/keywords.
 *
 * The legacy ux1-wordpress-customizer module rendered its own jQuery
 * post-edit metabox for this (includes/SeoManager.php); that admin UI is
 * superseded here by the React Content Creator tab, which calls
 * ContentRestController::generate_seo() and, optionally, save_seo_meta()
 * to persist the result onto a post. This class keeps the storage/plugin
 * detection logic, without re-registering a metabox.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class SeoManager {

	public const META_TITLE       = '_uxstudio_ai_seo_title';
	public const META_DESCRIPTION = '_uxstudio_ai_seo_description';
	public const META_KEYWORDS    = '_uxstudio_ai_seo_keywords';

	/**
	 * Postmeta keys used by each detected SEO plugin to render its own
	 * title/description/focus-keyword - keyed by detect_seo_plugin()'s
	 * return value. Only plugins that store meta as plain postmeta are
	 * listed here; All in One SEO (v4+) keeps its data in a dedicated
	 * `wp_aioseo_posts` table instead and is intentionally not covered.
	 *
	 * @return array{title?:string,description?:string,keywords?:string}
	 */
	private static function plugin_meta_keys( string $plugin ): array {
		switch ( $plugin ) {
			case 'Yoast SEO':
				return array(
					'title'       => '_yoast_wpseo_title',
					'description' => '_yoast_wpseo_metadesc',
					'keywords'    => '_yoast_wpseo_focuskw',
				);
			case 'Rank Math':
				return array(
					'title'       => 'rank_math_title',
					'description' => 'rank_math_description',
					'keywords'    => 'rank_math_focus_keyword',
				);
			case 'SEOPress':
				return array(
					'title'       => '_seopress_titles_title',
					'description' => '_seopress_titles_desc',
				);
			default:
				return array();
		}
	}

	/**
	 * Saves AI-generated SEO meta onto a post.
	 *
	 * Writes into UX Studio's own `_uxstudio_ai_seo_*` keys (always, as an
	 * audit trail / fallback when no SEO plugin is active) AND, when a
	 * supported SEO plugin is detected, into that plugin's own meta keys -
	 * otherwise the AI-generated title/description silently never affects
	 * the rendered `<title>`/meta description on sites that already run
	 * Yoast/Rank Math/SEOPress.
	 *
	 * @param array{seo_title?:string,seo_description?:string,seo_keywords?:string} $seo
	 */
	public static function save_meta( int $post_id, array $seo ): bool {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return false;
		}

		$title       = isset( $seo['seo_title'] ) ? sanitize_text_field( (string) $seo['seo_title'] ) : null;
		$description = isset( $seo['seo_description'] ) ? sanitize_text_field( (string) $seo['seo_description'] ) : null;
		$keywords    = isset( $seo['seo_keywords'] ) ? sanitize_text_field( (string) $seo['seo_keywords'] ) : null;

		if ( null !== $title ) {
			update_post_meta( $post_id, self::META_TITLE, $title );
		}
		if ( null !== $description ) {
			update_post_meta( $post_id, self::META_DESCRIPTION, $description );
		}
		if ( null !== $keywords ) {
			update_post_meta( $post_id, self::META_KEYWORDS, $keywords );
		}

		$plugin_keys = self::plugin_meta_keys( self::detect_seo_plugin() );
		if ( null !== $title && isset( $plugin_keys['title'] ) ) {
			update_post_meta( $post_id, $plugin_keys['title'], $title );
		}
		if ( null !== $description && isset( $plugin_keys['description'] ) ) {
			update_post_meta( $post_id, $plugin_keys['description'], $description );
		}
		if ( null !== $keywords && isset( $plugin_keys['keywords'] ) ) {
			update_post_meta( $post_id, $plugin_keys['keywords'], $keywords );
		}

		return true;
	}

	/**
	 * @return array{seo_title:string,seo_description:string,seo_keywords:string}
	 */
	public static function get_meta( int $post_id ): array {
		return array(
			'seo_title'       => (string) get_post_meta( $post_id, self::META_TITLE, true ),
			'seo_description' => (string) get_post_meta( $post_id, self::META_DESCRIPTION, true ),
			'seo_keywords'    => (string) get_post_meta( $post_id, self::META_KEYWORDS, true ),
		);
	}

	/**
	 * Detects a known SEO plugin, so the UI can hint that these AI-generated
	 * fields are a convenience copy rather than the plugin's own meta.
	 */
	public static function detect_seo_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'All in One SEO';
		}
		if ( defined( 'FLAVOR_SEO_VERSION' ) ) {
			return 'SEOPress';
		}
		return '';
	}
}
