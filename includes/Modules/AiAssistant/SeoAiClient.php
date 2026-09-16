<?php
/**
 * HMAC-signed HTTP client for the SEO scoring bridge (RankMath Content AI
 * parity, centrani-app PLAN.md §22, F1/F3).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Security;
use UxStudio\Modules\ContentSync\HmacAuth;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;

defined( 'ABSPATH' ) || exit;

/**
 * Reuses the content-sync hub<->node channel in the OPPOSITE direction: this
 * node signs a request to the central app (analogous to how the hub signs
 * requests to this node in SyncClient). The channel is symmetric — same
 * secret (node_api_key, = the central app's `sites.api_key` for this site),
 * same HmacAuth::sign() algorithm — so no new secret or pairing flow is
 * needed. The central app's base URL reuses the existing
 * content-sync `central_app_url` setting.
 */
final class SeoAiClient {

	private string $base_url;
	private string $secret;
	private int $timeout;

	public function __construct( int $timeout = 15 ) {
		$this->base_url = untrailingslashit( (string) ContentSyncModule::setting( 'central_app_url', '' ) );
		$this->secret   = Security::get_secret( ContentSyncModule::SECRET_NODE_KEY );
		$this->timeout  = $timeout;
	}

	/**
	 * Whether the bridge has everything it needs to make a call.
	 */
	public function is_configured(): bool {
		return '' !== $this->base_url && '' !== $this->secret;
	}

	/**
	 * SEO analyze — mirrors core\Seo\SeoAnalyzer::analyze()'s input/output
	 * contract on the central app.
	 *
	 * @param array<string, string> $fields title, content, meta_title, meta_desc, focus_keyword, slug.
	 * @return array<string, mixed> {success,score,grade,checks,serp_preview,computed_at} or {success:false,error}.
	 */
	public function analyze( array $fields ): array {
		return $this->call(
			'analyze',
			array(
				'title'         => (string) ( $fields['title'] ?? '' ),
				'content'       => (string) ( $fields['content'] ?? '' ),
				'meta_title'    => (string) ( $fields['meta_title'] ?? '' ),
				'meta_desc'     => (string) ( $fields['meta_desc'] ?? '' ),
				'focus_keyword' => (string) ( $fields['focus_keyword'] ?? '' ),
				'slug'          => (string) ( $fields['slug'] ?? '' ),
			)
		);
	}

	/**
	 * Topic research (AI-only — see centrani-app's core/Seo/TopicResearch.php):
	 * related/LSI keywords + subtopics for a seed keyword, no real search
	 * volume/difficulty. Cached 30 days on the central app side.
	 *
	 * @return array<string, mixed> {success,seed_keyword,related_keywords,subtopics,cached} or {success:false,error}.
	 */
	public function topic_research( string $seed_keyword, string $language = 'cs' ): array {
		return $this->call(
			'topic_research',
			array(
				'seed_keyword' => $seed_keyword,
				'language'     => $language,
			)
		);
	}

	/**
	 * JSON-LD schema markup (Article + auto-detected FAQ, optional Product/Recipe).
	 *
	 * @param array<string, mixed> $fields Same shape as analyze(), plus optional image_url/site_name/product/recipe.
	 * @return array<string, mixed> {success,schema:array} or {success:false,error}.
	 */
	public function schema( array $fields ): array {
		return $this->call( 'schema', $fields );
	}

	/**
	 * Cross-site internal link suggestions within the site's client portfolio
	 * (empty when `sites.portfolio_key` isn't set on the central app — safe
	 * default, no suggestions across unrelated clients' sites).
	 *
	 * @return array<string, mixed> {success,suggestions:array} or {success:false,error}.
	 */
	public function link_suggestions( string $content, string $exclude_url = '' ): array {
		return $this->call(
			'link_suggestions',
			array(
				'content'     => $content,
				'exclude_url' => $exclude_url,
			)
		);
	}

	/**
	 * Signed POST to a seo_ai_api action, normalised into {success,...}|{success:false,error}.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function call( string $action, array $payload ): array {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'error'   => __( 'Centrální aplikace není propojená (chybí central_app_url nebo node_api_key v nastavení Content Sync).', 'ux-studio' ),
			);
		}

		$url  = $this->base_url . '/?page=seo_ai_api&action=' . $action . '&site_url=' . rawurlencode( home_url( '/' ) );
		$body = (string) wp_json_encode( $payload );

		$timestamp = time();
		$nonce     = wp_generate_password( 24, false );
		$signature = HmacAuth::sign( 'POST', $url, $body, $timestamp, $nonce, $this->secret );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'Content-Type'             => 'application/json',
					HmacAuth::HEADER_SIGNATURE => $signature,
					HmacAuth::HEADER_TIMESTAMP => (string) $timestamp,
					HmacAuth::HEADER_NONCE     => $nonce,
					HmacAuth::HEADER_VERSION   => '1',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return array(
				'success' => false,
				'error'   => is_array( $data ) && isset( $data['error'] )
					? (string) $data['error']
					: sprintf( /* translators: %d: HTTP status code */ __( 'Centrální aplikace vrátila HTTP %d.', 'ux-studio' ), $code ),
			);
		}

		return $data;
	}
}
