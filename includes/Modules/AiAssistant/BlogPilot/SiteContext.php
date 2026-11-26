<?php
/**
 * What Blog Pilot knows about the site when it asks the AI for topics or articles: name, description, the titles that
 * already exist (so topics and articles do not repeat them) and a few published pages the article may link to.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

defined( 'ABSPATH' ) || exit;

final class SiteContext {

	/**
	 * Titles of the latest posts of every status that still counts as "already written".
	 *
	 * @return array<int, string>
	 */
	public static function existing_titles( int $limit = 150 ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN (%s, %s, %s, %s) AND post_title <> %s ORDER BY post_date DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'post',
				'publish',
				'future',
				'draft',
				'pending',
				'',
				$limit
			)
		);

		return array_values( array_filter( array_map( 'strval', is_array( $rows ) ? $rows : array() ), 'strlen' ) );
	}

	/**
	 * Published posts the model may link to.
	 *
	 * @return array<int, array{title:string,url:string}>
	 */
	public static function link_candidates( int $limit = 15 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_title <> %s ORDER BY post_date DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'post',
				'publish',
				'',
				max( 1, min( 50, $limit ) )
			)
		);

		$links = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$url = get_permalink( (int) $row->ID );
			if ( is_string( $url ) && '' !== $url ) {
				$links[] = array(
					'title' => (string) $row->post_title,
					'url'   => $url,
				);
			}
		}
		return $links;
	}

	/**
	 * Name, description and date used by the article prompt.
	 *
	 * @return array{site_name:string,site_context:string,today:string,link_candidates:array<int,array{title:string,url:string}>}
	 */
	public static function for_article(): array {
		return array(
			'site_name'       => (string) get_bloginfo( 'name' ),
			'site_context'    => (string) get_bloginfo( 'description' ),
			'today'           => (string) wp_date( 'Y-m-d' ),
			'link_candidates' => self::link_candidates(),
		);
	}

	/**
	 * Default brief of the "Suggest topics" form: a sentence built from the site name and tagline.
	 */
	public static function default_brief(): string {
		$name        = trim( (string) get_bloginfo( 'name' ) );
		$description = trim( (string) get_bloginfo( 'description' ) );

		if ( '' === $name && '' === $description ) {
			return '';
		}
		if ( '' === $description ) {
			/* translators: %s: site name */
			return sprintf( __( 'Blog articles for the website "%s".', 'ux-studio' ), $name );
		}
		/* translators: 1: site name, 2: site tagline */
		return sprintf( __( 'Blog articles for the website "%1$s" (%2$s).', 'ux-studio' ), $name, $description );
	}
}
