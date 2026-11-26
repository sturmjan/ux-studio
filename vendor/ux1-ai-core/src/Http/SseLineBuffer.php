<?php
/**
 * Splits a byte stream into Server-Sent-Events "data:" payloads.
 *
 * Network chunks are NOT aligned to lines (nor to UTF-8 characters): an incomplete trailing line is kept until the
 * next chunk completes it, otherwise split deltas or characters get lost.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Http;

final class SseLineBuffer {

	private string $pending = '';

	/**
	 * Feeds a chunk and returns the complete "data: ..." payloads it finished (without the "data: " prefix).
	 *
	 * @return array<int, string>
	 */
	public function push( string $chunk ): array {
		$this->pending .= $chunk;
		$payloads       = array();
		while ( false !== ( $newline = strpos( $this->pending, "\n" ) ) ) {
			$line          = trim( substr( $this->pending, 0, $newline ) );
			$this->pending = substr( $this->pending, $newline + 1 );
			if ( 0 === strpos( $line, 'data: ' ) ) {
				$payloads[] = substr( $line, 6 );
			}
		}
		return $payloads;
	}
}
