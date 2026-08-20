<?php
/**
 * OpenAI embeddings (text-embedding-3-small, 1536 dims).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

defined( 'ABSPATH' ) || exit;

final class OpenAiEmbedding implements EmbeddingProviderInterface {

	private const API_URL    = 'https://api.openai.com/v1/embeddings';
	private const MODEL      = 'text-embedding-3-small';
	private const DIMENSIONS = 1536;
	private const MAX_BATCH  = 100;

	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	public function get_id(): string {
		return 'openai';
	}

	public function get_model_name(): string {
		return self::MODEL;
	}

	public function get_dimensions(): int {
		return self::DIMENSIONS;
	}

	public function embed( string $text ): array {
		$result = $this->embed_batch( array( $text ) );
		return $result[0] ?? array();
	}

	public function embed_batch( array $texts ): array {
		if ( empty( $texts ) ) {
			return array();
		}

		$embeddings = array();

		foreach ( array_chunk( $texts, self::MAX_BATCH ) as $batch ) {
			$response = wp_remote_post(
				self::API_URL,
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model' => self::MODEL,
							'input' => array_values( $batch ),
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException( 'OpenAI Embedding API error: ' . $response->get_error_message() );
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code ) {
				throw new \RuntimeException( 'OpenAI Embedding API: ' . ( $body['error']['message'] ?? "HTTP {$code}" ) );
			}

			if ( empty( $body['data'] ) ) {
				throw new \RuntimeException( 'OpenAI Embedding API: empty response.' );
			}

			foreach ( $body['data'] as $item ) {
				$embeddings[] = $item['embedding'];
			}
		}

		return $embeddings;
	}

	public function is_available(): bool {
		return '' !== $this->api_key;
	}
}
