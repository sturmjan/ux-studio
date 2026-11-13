<?php
/**
 * Which addresses may be sent to Facebook and to IndexNow (PLAN.md 39.5, 39.7, 40.4).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision on a few facts about the post and the site, tested without WordPress (tests/indexing-queue.php).
 * The facts are gathered by Dispatcher::facts_for().
 *
 *   blog_public  int   the "Discourage search engines" setting (0 = discouraged, typical for a development domain),
 *   status       string post status,
 *   viewable     bool  is_post_type_viewable() of the post type,
 *   password     bool  the post is protected by a password,
 *   noindex      bool  the post says noindex (Rank Math, Yoast meta),
 *   maintenance  bool  the maintenance or coming soon page is shown to visitors,
 *   paused       bool  the site is a copy or was moved away (UX1 Network app link paused).
 */
final class Eligibility {

	/**
	 * Why an address must not be sent; an empty string when it may.
	 *
	 * @param array<string, mixed> $facts Facts (see class comment).
	 */
	public static function reason( array $facts ): string {
		if ( ! empty( $facts['paused'] ) ) {
			return 'paused';
		}
		if ( 0 === (int) ( $facts['blog_public'] ?? 1 ) ) {
			return 'not_public_site';
		}
		if ( empty( $facts['viewable'] ) ) {
			return 'not_viewable';
		}
		if ( 'publish' !== (string) ( $facts['status'] ?? '' ) ) {
			return 'not_published';
		}
		if ( ! empty( $facts['password'] ) ) {
			return 'password';
		}
		if ( ! empty( $facts['noindex'] ) ) {
			return 'noindex';
		}
		if ( ! empty( $facts['maintenance'] ) ) {
			return 'maintenance';
		}
		return '';
	}

	/**
	 * Plain-language wording of a reason code.
	 *
	 * @param string $reason Code from reason().
	 */
	public static function label( string $reason ): string {
		switch ( $reason ) {
			case 'paused':
				return __( 'This site is a copy or was moved, so nothing is sent.', 'ux-studio' );
			case 'not_public_site':
				return __( 'Search engines are discouraged on this site (Settings > Reading), so nothing is sent.', 'ux-studio' );
			case 'not_viewable':
				return __( 'This content type has no public page.', 'ux-studio' );
			case 'not_published':
				return __( 'Only published content is sent.', 'ux-studio' );
			case 'password':
				return __( 'The page is protected by a password.', 'ux-studio' );
			case 'noindex':
				return __( 'The page is set to noindex.', 'ux-studio' );
			case 'maintenance':
				return __( 'The maintenance page is shown to visitors right now.', 'ux-studio' );
			default:
				return '';
		}
	}

	/**
	 * Does the post meta of Rank Math or Yoast say noindex?
	 *
	 * @param mixed $rank_math Value of the post meta `rank_math_robots` (a list of directives).
	 * @param mixed $yoast     Value of the post meta `_yoast_wpseo_meta-robots-noindex` (1 = noindex, 2 = index).
	 */
	public static function meta_says_noindex( $rank_math, $yoast ): bool {
		if ( is_array( $rank_math ) && in_array( 'noindex', array_map( 'strval', $rank_math ), true ) ) {
			return true;
		}
		return '1' === (string) $yoast;
	}
}
