<?php
/**
 * OpenAI embeddings (text-embedding-3-small, 1536 dimensions) over the HttpClient port.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Rag;

use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Http\HttpException;
use Ux1\AiCore\Support\Json;

final class OpenAiEmbedding implements EmbeddingProvider {

	public const API_URL    = 'https://api.openai.com/v1/embeddings';
	public const MODEL      = 'text-embedding-3-small';
	public const DIMENSIONS = 1536;
	public const MAX_BATCH  = 100;
	private const TIMEOUT   = 60;

	public function __construct(
		private readonly string $api_key,
		private readonly HttpClient $http
	) {}

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
		$headers    = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
		);

		foreach ( array_chunk( array_values( $texts ), self::MAX_BATCH ) as $batch ) {
			try {
				$response = $this->http->request( 'POST', self::API_URL, $headers, Json::encode( array( 'model' => self::MODEL, 'input' => $batch ) ), self::TIMEOUT );
			} catch ( HttpException $e ) {
				throw new \RuntimeException( 'OpenAI Embedding API error: ' . $e->getMessage(), 0, $e );
			}

			$body = $response->json();
			if ( 200 !== $response->status ) {
				$error   = $body['error'] ?? null;
				$message = is_array( $error ) ? ( $error['message'] ?? null ) : null;
				throw new \RuntimeException( 'OpenAI Embedding API: ' . ( is_string( $message ) && '' !== $message ? $message : 'HTTP ' . $response->status ) );
			}
			if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
				throw new \RuntimeException( 'OpenAI Embedding API: empty response.' );
			}

			$items = $body['data'];
			usort( $items, static fn( array $a, array $b ): int => (int) ( $a['index'] ?? 0 ) <=> (int) ( $b['index'] ?? 0 ) );
			foreach ( $items as $item ) {
				$embeddings[] = $item['embedding'];
			}
		}

		return $embeddings;
	}

	public function is_available(): bool {
		return '' !== $this->api_key;
	}
}
