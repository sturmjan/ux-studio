<?php
/**
 * Optional reference image (product / style). One per request, as in CA.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

final class ReferenceImage {

	public const MAX_BYTES     = 8 * 1024 * 1024;
	public const ALLOWED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/** @throws \InvalidArgumentException On empty data, oversized data or a mime type outside ALLOWED_MIMES. */
	public function __construct(
		public readonly string $data,
		public readonly string $mime = 'image/png'
	) {
		if ( '' === $data ) {
			throw new \InvalidArgumentException( 'Reference image is empty.' );
		}
		if ( strlen( $data ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'Reference image is too large (max 8 MB).' );
		}
		if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported reference image type: ' . $mime );
		}
	}

	/** File extension for multipart uploads (as in CA: png unless jpeg/webp/gif). */
	public function extension(): string {
		return match ( $this->mime ) {
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
			default      => 'png',
		};
	}

	/** Hash for host cache keys. */
	public function hash(): string {
		return hash( 'sha256', $this->data );
	}
}
