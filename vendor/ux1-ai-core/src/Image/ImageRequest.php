<?php
/**
 * Validated image generation request (prompt + style, aspect ratio, resolution, quality, tier, reference image).
 *
 * The constructor is strict. from_input() is lenient like CA's controller: an unknown or unsupported value falls
 * back to a default instead of failing, so raw form input can be passed in.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

final class ImageRequest {

	public const MAX_PROMPT_CHARS = 4000;
	public const QUALITIES        = array( 'low', 'medium', 'high', 'auto' );
	public const RESOLUTIONS      = array( '1K', '2K', '4K', 'auto' );
	public const TIERS            = array( 'preview', 'final' );

	/**
	 * @param string $resolution '1K'|'2K'|'4K'; 'auto' for providers without a resolution axis (OpenAI).
	 * @param string $tier       'preview' (Gemini free tier) or 'final'. Ignored by OpenAI.
	 * @throws \InvalidArgumentException On an invalid value.
	 */
	public function __construct(
		public readonly string $prompt,
		public readonly string $style = 'none',
		public readonly string $aspect_ratio = '1:1',
		public readonly string $resolution = '1K',
		public readonly string $quality = 'medium',
		public readonly string $tier = 'final',
		public readonly ?ReferenceImage $reference = null
	) {
		if ( '' === trim( $prompt ) ) {
			throw new \InvalidArgumentException( 'Prompt is empty.' );
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $prompt ) : strlen( $prompt );
		if ( $length > self::MAX_PROMPT_CHARS ) {
			throw new \InvalidArgumentException( 'Prompt is too long (max ' . self::MAX_PROMPT_CHARS . ' characters).' );
		}
		if ( ! ImageStyles::exists( $style ) ) {
			throw new \InvalidArgumentException( 'Unknown style: ' . $style );
		}
		if ( 1 !== preg_match( '/^[1-9]\d?:[1-9]\d?$/', $aspect_ratio ) ) {
			throw new \InvalidArgumentException( 'Invalid aspect ratio: ' . $aspect_ratio );
		}
		if ( ! in_array( $resolution, self::RESOLUTIONS, true ) ) {
			throw new \InvalidArgumentException( 'Invalid resolution: ' . $resolution );
		}
		if ( ! in_array( $quality, self::QUALITIES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid quality: ' . $quality );
		}
		if ( ! in_array( $tier, self::TIERS, true ) ) {
			throw new \InvalidArgumentException( 'Invalid tier: ' . $tier );
		}
	}

	/**
	 * Builds a request from raw input (keys: prompt, style, aspect_ratio, resolution, quality, tier, reference),
	 * coercing values the provider does not offer, the way CA's controller does. The prompt is still validated.
	 *
	 * @param array<string, mixed> $input        Raw input; 'reference' may be a ReferenceImage.
	 * @param array<string, mixed> $capabilities ImageProvider::get_capabilities().
	 * @throws \InvalidArgumentException On an empty/too long prompt.
	 */
	public static function from_input( array $input, array $capabilities ): self {
		$style = (string) ( $input['style'] ?? 'none' );
		if ( ! isset( $capabilities['styles'][ $style ] ) ) {
			$style = 'none';
		}

		$ratios = (array) ( $capabilities['aspect_ratios'] ?? array() );
		$ratio  = (string) ( $input['aspect_ratio'] ?? '1:1' );
		if ( ! in_array( $ratio, $ratios, true ) ) {
			$ratio = (string) ( $ratios[0] ?? '1:1' );
		}

		$tiers = (array) ( $capabilities['tiers'] ?? array() );
		$tier  = (string) ( $input['tier'] ?? 'final' );
		if ( ! isset( $tiers[ $tier ] ) ) {
			$tier = (string) ( array_key_first( $tiers ) ?? 'final' );
		}

		$resolutions = (array) ( $capabilities['resolutions'] ?? array() );
		if ( empty( $resolutions ) ) {
			$resolution = 'auto';
		} else {
			$resolution = (string) ( $input['resolution'] ?? '2K' );
			if ( ! in_array( $resolution, $resolutions, true ) ) {
				$resolution = '2K';
			}
		}

		$qualities = (array) ( $capabilities['qualities'] ?? array() );
		$quality   = (string) ( $input['quality'] ?? 'medium' );
		if ( ! empty( $qualities ) ) {
			if ( ! isset( $qualities[ $quality ] ) ) {
				$quality = (string) array_key_first( $qualities );
			}
		} elseif ( ! in_array( $quality, self::QUALITIES, true ) ) {
			$quality = 'medium';
		}

		$reference = $input['reference'] ?? null;

		return new self(
			trim( (string) ( $input['prompt'] ?? '' ) ),
			$style,
			$ratio,
			$resolution,
			$quality,
			$tier,
			$reference instanceof ReferenceImage ? $reference : null
		);
	}

	/** Cache key over everything that changes the output (provider and model supplied by the host). */
	public function cache_key( string $provider, string $model ): string {
		return hash(
			'sha256',
			implode(
				"\0",
				array( $provider, $model, $this->prompt, $this->style, $this->aspect_ratio, $this->resolution, $this->quality, $this->tier, $this->reference?->hash() ?? '' )
			)
		);
	}
}
