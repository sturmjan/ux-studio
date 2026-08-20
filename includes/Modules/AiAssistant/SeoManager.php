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
	 * Saves AI-generated SEO meta onto a post.
	 *
	 * @param array{seo_title?:string,seo_description?:string,seo_keywords?:string} $seo
	 */
	public static function save_meta( int $post_id, array $seo ): bool {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return false;
		}

		if ( isset( $seo['seo_title'] ) ) {
			update_post_meta( $post_id, self::META_TITLE, sanitize_text_field( (string) $seo['seo_title'] ) );
		}
		if ( isset( $seo['seo_description'] ) ) {
			update_post_meta( $post_id, self::META_DESCRIPTION, sanitize_text_field( (string) $seo['seo_description'] ) );
		}
		if ( isset( $seo['seo_keywords'] ) ) {
			update_post_meta( $post_id, self::META_KEYWORDS, sanitize_text_field( (string) $seo['seo_keywords'] ) );
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
