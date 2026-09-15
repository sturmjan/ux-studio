<?php
/**
 * REST proxy for the live SEO score panel: forwards editor content to the
 * central app's SeoAnalyzer via SeoAiClient and returns the score as-is.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/ai-assistant/seo/score
 *
 * Debounced from the editor (Gutenberg/Elementor sidebar); idempotent, no
 * side effects here — persistence stays on the central app side (seo_scores),
 * keyed by (site_id, remote_post_id) once the post is saved there via
 * content-sync, same as today's AI-drafted meta_title/meta_desc.
 */
final class SeoScorePanel extends Controller {

	public function register_routes(): void {
		$this->route(
			'/ai-assistant/seo/score',
			'POST',
			array( $this, 'score' ),
			array(
				'title'         => array( 'required' => false, 'type' => 'string' ),
				'content'       => array( 'required' => true, 'type' => 'string' ),
				'meta_title'    => array( 'required' => false, 'type' => 'string' ),
				'meta_desc'     => array( 'required' => false, 'type' => 'string' ),
				'focus_keyword' => array( 'required' => false, 'type' => 'string' ),
				'slug'          => array( 'required' => false, 'type' => 'string' ),
			)
		);
	}

	public function score( WP_REST_Request $request ) {
		$client = new SeoAiClient();
		$result = $client->analyze(
			array(
				'title'         => (string) $request->get_param( 'title' ),
				'content'       => (string) $request->get_param( 'content' ),
				'meta_title'    => (string) $request->get_param( 'meta_title' ),
				'meta_desc'     => (string) $request->get_param( 'meta_desc' ),
				'focus_keyword' => (string) $request->get_param( 'focus_keyword' ),
				'slug'          => (string) $request->get_param( 'slug' ),
			)
		);

		if ( empty( $result['success'] ) ) {
			return new WP_Error(
				'uxstudio_seo_score_bridge',
				(string) ( $result['error'] ?? __( 'SEO analýza selhala.', 'ux-studio' ) ),
				array( 'status' => 424 )
			);
		}

		return new WP_REST_Response( $result, 200 );
	}
}
