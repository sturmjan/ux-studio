<?php
/**
 * Shared HTTP handling for image providers: retry with exponential backoff on 429/5xx (carried over from CA),
 * error messages, image size detection.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Http\HttpException;
use Ux1\AiCore\Http\HttpResponse;

abstract class AbstractImageProvider implements ImageProvider {

	protected const TIMEOUT = 120;

	/** @var callable(int): void Receives the delay in milliseconds. */
	private $sleeper;

	/**
	 * @param callable(int): void|null $sleeper Backoff sleeper (milliseconds); tests pass a no-op. Default usleep().
	 */
	public function __construct(
		protected readonly string $api_key,
		protected readonly HttpClient $http,
		?callable $sleeper = null
	) {
		$this->sleeper = $sleeper ?? static function ( int $ms ): void {
			usleep( $ms * 1000 );
		};
	}

	/** Name used in error messages, e.g. "Gemini". */
	abstract protected function error_name(): string;

	/**
	 * Sends the request, retrying 429/500/502/503/504 up to 4 times (1 s, 2 s, 4 s). Other statuses (400/401/403/404)
	 * return immediately. Transport failures are not retried.
	 *
	 * @param callable(): HttpResponse $call
	 * @throws \RuntimeException On a transport failure.
	 */
	protected function send_with_retry( callable $call ): HttpResponse {
		$delay    = 1000;
		$attempts = 4;
		$response = null;
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			try {
				$response = $call();
			} catch ( HttpException $e ) {
				throw new \RuntimeException( $this->error_name() . ' API: ' . $e->getMessage(), 0, $e );
			}
			if ( $response->is_success() || ! in_array( $response->status, array( 429, 500, 502, 503, 504 ), true ) ) {
				return $response;
			}
			if ( $attempt < $attempts ) {
				( $this->sleeper )( $delay );
				$delay *= 2;
			}
		}
		return $response;
	}

	/** @throws \RuntimeException "<Name> API: HTTP <code>: <detail>" */
	protected function assert_success( HttpResponse $response ): void {
		if ( $response->is_success() ) {
			return;
		}
		$data   = $response->json();
		$detail = $data['error']['message'] ?? substr( trim( $response->body ), 0, 300 );
		throw new \RuntimeException( $this->error_name() . " API: HTTP {$response->status}: {$detail}" );
	}

	/** @return array{0:?int,1:?int} Width and height, or nulls when the bytes are not a recognizable image. */
	protected function image_size( string $binary ): array {
		$size = @getimagesizefromstring( $binary );
		return is_array( $size ) ? array( (int) $size[0], (int) $size[1] ) : array( null, null );
	}
}
