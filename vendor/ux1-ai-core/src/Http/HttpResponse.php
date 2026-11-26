<?php
/**
 * Result of an HTTP call: status code and body (for streams only the first bytes).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Http;

final class HttpResponse {

	public function __construct(
		public readonly int $status,
		public readonly string $body = ''
	) {}

	public function is_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/** Decoded JSON body as an array (empty array when the body is not a JSON object/array). */
	public function json(): array {
		$data = json_decode( $this->body, true );
		return is_array( $data ) ? $data : array();
	}
}
