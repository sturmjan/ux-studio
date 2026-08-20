<?php
/**
 * REST controller for the "misc" admin-customiser features (currently just
 * Quick Search). Registered on rest_api_init regardless of whether Quick
 * Search itself is enabled - only the frontend widget enqueue is gated by
 * the `quick_search_enabled` toggle (see MiscBootstrap::register()).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Settings;
use UxStudio\Modules\AdminCustomiser\QuickSearch\Integrations;
use UxStudio\Modules\AdminCustomiser\QuickSearch\ThirdPartyIntegrations;
use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET uxstudio/v1/admin-customiser/quick-search?q=... - cross-content fuzzy
 * search (posts/terms/users/media/UX Studio modules + a few third-party
 * plugin shortcuts). Requires `edit_posts` (not `manage_options`) since
 * editors use quick search too, not just admins.
 */
final class MiscRestController extends Controller {

	public function register_routes(): void {
		$this->route(
			'/admin-customiser/quick-search',
			'GET',
			array( $this, 'quick_search' ),
			array(
				'q' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			'edit_posts'
		);
	}

	/**
	 * Search callback.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function quick_search( WP_REST_Request $request ) {
		$term = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $term ) {
			return $this->ok( array() );
		}

		$post_types = (array) ( new Settings( 'uxstudio_admin_customiser' ) )->get( 'quick_search_post_types', array() );

		$groups = array();
		foreach ( ( new Integrations( $post_types ) )->search( $term ) as $key => $group ) {
			$filtered = $this->fuzzy_filter( $group, $term );
			if ( ! empty( $filtered ) ) {
				$groups[ $key ] = $filtered;
			}
		}
		foreach ( ( new ThirdPartyIntegrations() )->search( $term ) as $key => $group ) {
			$filtered = $this->fuzzy_filter( $group, $term );
			if ( ! empty( $filtered ) ) {
				$groups[ $key ] = $filtered;
			}
		}

		$groups = $this->limit_results( $groups, $term, 8 );

		return $this->ok( $groups );
	}

	/**
	 * Drop rows whose label/type don't fuzzy-match the search term.
	 *
	 * @param array  $rows        Result rows.
	 * @param string $search_term Search query.
	 */
	private function fuzzy_filter( array $rows, string $search_term ): array {
		if ( '' === $search_term ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $search_term ) {
					if ( empty( $row['label'] ) ) {
						return false;
					}
					if ( $this->fuzzy_match( $row['label'], $search_term ) ) {
						return true;
					}
					return ! empty( $row['type'] ) && $this->fuzzy_match( $row['type'], $search_term );
				}
			)
		);
	}

	/**
	 * Cap total results across all groups to $max, ranked by fuzzy score.
	 *
	 * @param array  $groups      Grouped results (label => rows).
	 * @param string $search_term Search query.
	 * @param int    $max         Max total rows.
	 */
	private function limit_results( array $groups, string $search_term, int $max ): array {
		$flat = array();
		foreach ( $groups as $key => $rows ) {
			foreach ( $rows as $row ) {
				$row['_group'] = $key;
				$row['_score'] = $this->score( $row, $search_term );
				$flat[]        = $row;
			}
		}

		usort( $flat, static fn( $a, $b ) => $b['_score'] <=> $a['_score'] );
		$flat = array_slice( $flat, 0, $max );

		$grouped = array();
		foreach ( $flat as $row ) {
			$key = $row['_group'];
			unset( $row['_group'], $row['_score'] );
			$grouped[ $key ][] = $row;
		}

		return $grouped;
	}

	/**
	 * @param array  $row         Result row.
	 * @param string $search_term Search query.
	 */
	private function score( array $row, string $search_term ): float {
		$score = 0.0;
		if ( isset( $row['label'] ) ) {
			$score += $this->fuzzy_score( $row['label'], $search_term ) * 2;
		}
		if ( isset( $row['type'] ) ) {
			$score += $this->fuzzy_score( $row['type'], $search_term );
		}
		return $score;
	}

	/**
	 * @param string $haystack    Text to test.
	 * @param string $search_term Search query.
	 */
	private function fuzzy_match( string $haystack, string $search_term ): bool {
		$haystack    = strtolower( $haystack );
		$search_term = strtolower( $search_term );

		if ( false !== strpos( $haystack, $search_term ) ) {
			return true;
		}
		if ( strlen( $search_term ) > 3 ) {
			return $this->fuzzy_score( $haystack, $search_term ) > 0.6;
		}
		return false;
	}

	/**
	 * Levenshtein-distance based similarity score in [0, len].
	 *
	 * @param string $string      Text to test.
	 * @param string $search_term Search query.
	 */
	private function fuzzy_score( string $string, string $search_term ): float {
		$string       = strtolower( $string );
		$search_term  = strtolower( $search_term );
		$len_search   = strlen( $search_term );
		$len_string   = strlen( $string );
		$max_distance = floor( $len_string / 3 );

		if ( 0 === $len_search ) {
			return (float) $max_distance;
		}
		if ( $len_search > $len_string ) {
			return 0.0;
		}

		$distance = levenshtein( $string, $search_term );
		if ( $distance > $max_distance ) {
			return 0.0;
		}

		return 1 - ( $distance / ( $len_string + 0.1 ) );
	}
}
