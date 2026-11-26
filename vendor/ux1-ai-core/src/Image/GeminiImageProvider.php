<?php
/**
 * Google Gemini image generation (generateContent with an IMAGE response modality), ported from CA.
 *
 * Two tiers: 'preview' (gemini-2.5-flash-image, "Nano Banana", free tier, ~1024 px) and 'final' (gemini-3-pro-image,
 * "Nano Banana Pro", paid, 1K/2K/4K). The free tier daily quota (500 images) is a host concern: check it before
 * calling generate() with tier 'preview' and count it afterwards; the core keeps no state.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

use Ux1\AiCore\Http\HttpResponse;
use Ux1\AiCore\Support\Json;

final class GeminiImageProvider extends AbstractImageProvider {

	public const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/** Free tier images per day (informational, enforced by the host). */
	public const FREE_TIER_DAILY_LIMIT = 500;

	public function get_id(): string {
		return 'gemini';
	}

	public function get_label(): string {
		return 'Google Gemini (Nano Banana)';
	}

	protected function error_name(): string {
		return 'Gemini';
	}

	public function get_capabilities(): array {
		return array(
			'styles'        => ImageStyles::STYLES,
			'aspect_ratios' => array( '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9' ),
			'resolutions'   => array( '1K', '2K', '4K' ),
			'qualities'     => array(),
			'tiers'         => array(
				'preview' => array( 'label' => 'Náhled zdarma (Gemini 2.5 Flash)', 'free' => true ),
				'final'   => array( 'label' => 'Finální kvalita (Gemini 3 Pro)', 'free' => false ),
			),
		);
	}

	public function resolve_model( ImageRequest $request ): string {
		return 'preview' === $request->tier ? ImagePricing::GEMINI_PREVIEW_MODEL : ImagePricing::GEMINI_FINAL_MODEL;
	}

	public function estimate_cost( ImageRequest $request ): array {
		return ImagePricing::estimate( 'gemini', $request );
	}

	public function generate( ImageRequest $request ): ImageResult {
		if ( '' === trim( $this->api_key ) ) {
			throw new \RuntimeException( 'Gemini API: API key is missing.' );
		}

		$model = $this->resolve_model( $request );

		$image_config = array( 'aspectRatio' => $request->aspect_ratio );
		if ( ImagePricing::GEMINI_FINAL_MODEL === $model ) {
			$image_config['imageSize'] = $request->resolution;
		}

		// The reference image goes first as an inlineData part, then the text (one reference per request, as in CA).
		$parts = array();
		if ( null !== $request->reference ) {
			$parts[] = array(
				'inlineData' => array(
					'mimeType' => $request->reference->mime,
					'data'     => base64_encode( $request->reference->data ),
				),
			);
		}
		$parts[] = array( 'text' => ImageStyles::apply( $request->prompt, $request->style ) );

		$body = Json::encode(
			array(
				'contents'         => array( array( 'parts' => $parts ) ),
				'generationConfig' => array(
					'responseModalities' => array( 'IMAGE' ),
					'imageConfig'        => $image_config,
				),
			)
		);

		$url      = self::API_BASE . $model . ':generateContent';
		$headers  = array(
			'Accept'         => 'application/json',
			'Content-Type'   => 'application/json',
			'x-goog-api-key' => $this->api_key,
		);
		$response = $this->send_with_retry(
			fn (): HttpResponse => $this->http->request( 'POST', $url, $headers, $body, self::TIMEOUT )
		);
		$this->assert_success( $response );

		$parts  = (array) ( $response->json()['candidates'][0]['content']['parts'] ?? array() );
		$inline = null;
		foreach ( $parts as $part ) {
			if ( isset( $part['inlineData']['data'] ) ) {
				$inline = $part['inlineData'];
				break;
			}
		}
		if ( null === $inline ) {
			$texts  = array_filter( array_column( $parts, 'text' ) );
			$reason = $texts ? implode( ' ', $texts ) : 'no image in the response';
			throw new \RuntimeException( 'Gemini API: no image returned: ' . $reason );
		}

		$binary = base64_decode( (string) $inline['data'], true );
		if ( false === $binary ) {
			throw new \RuntimeException( 'Gemini API: could not decode the image from the response.' );
		}

		list( $width, $height ) = $this->image_size( $binary );

		// Gemini reports no usage for images: the price is always the table value (estimate).
		return new ImageResult(
			$binary,
			(string) ( $inline['mimeType'] ?? 'image/png' ),
			$this->get_id(),
			$model,
			$width,
			$height,
			ImagePricing::gemini_cost( $request->tier, $request->resolution ),
			true
		);
	}
}
