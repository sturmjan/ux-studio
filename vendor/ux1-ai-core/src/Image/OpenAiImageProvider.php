<?php
/**
 * OpenAI gpt-image generation, ported from CA.
 *
 * Without a reference image: JSON POST to /v1/images/generations. With one: multipart POST to /v1/images/edits (the
 * only way to send a file). Both return data[0].b64_json and usage. There is no free tier and no resolution axis; the
 * size follows the aspect ratio (1:1, 3:2, 2:3 only). The model comes from host settings; an unknown one becomes
 * gpt-image-1, as in CA.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Http\HttpResponse;
use Ux1\AiCore\Support\Json;

final class OpenAiImageProvider extends AbstractImageProvider {

	public const GENERATIONS_URL = 'https://api.openai.com/v1/images/generations';
	public const EDITS_URL       = 'https://api.openai.com/v1/images/edits';

	/** The only native sizes; any other aspect ratio falls back to 1:1 (as in CA). */
	public const SIZE_MAP = array(
		'1:1' => '1024x1024',
		'3:2' => '1536x1024',
		'2:3' => '1024x1536',
	);

	private readonly string $model;

	/**
	 * @param string                   $model   Configured model id (gpt-image-2, -1.5, -1-mini, -1); unknown = gpt-image-1.
	 * @param callable(int): void|null $sleeper Backoff sleeper (milliseconds).
	 */
	public function __construct( string $api_key, HttpClient $http, string $model = ImagePricing::OPENAI_DEFAULT_MODEL, ?callable $sleeper = null ) {
		parent::__construct( $api_key, $http, $sleeper );
		$this->model = ImagePricing::openai_model( $model );
	}

	public function get_id(): string {
		return 'openai';
	}

	public function get_label(): string {
		return 'OpenAI (gpt-image)';
	}

	protected function error_name(): string {
		return 'OpenAI';
	}

	/** @return array<string, string> Model id => label. */
	public static function get_models(): array {
		return array(
			'gpt-image-2'      => 'GPT Image 2',
			'gpt-image-1.5'    => 'GPT Image 1.5',
			'gpt-image-1'      => 'GPT Image 1',
			'gpt-image-1-mini' => 'GPT Image 1 Mini (cheapest)',
		);
	}

	public function get_capabilities(): array {
		return array(
			'styles'        => ImageStyles::STYLES,
			'aspect_ratios' => array_keys( self::SIZE_MAP ),
			'resolutions'   => array(), // No resolution axis: the size follows the aspect ratio.
			'qualities'     => array(
				'low'    => 'Nízká (nejlevnější)',
				'medium' => 'Střední',
				'high'   => 'Vysoká (nejdražší)',
				'auto'   => 'Automaticky',
			),
			'tiers'         => array(
				'final' => array( 'label' => 'OpenAI gpt-image (placené)', 'free' => false ),
			),
		);
	}

	public function resolve_model( ImageRequest $request ): string {
		return $this->model;
	}

	public function estimate_cost( ImageRequest $request ): array {
		return ImagePricing::estimate( 'openai', $request, $this->model );
	}

	public function generate( ImageRequest $request ): ImageResult {
		if ( '' === trim( $this->api_key ) ) {
			throw new \RuntimeException( 'OpenAI API: API key is missing.' );
		}

		$quality = ImagePricing::openai_quality( $request->quality );
		$prompt  = ImageStyles::apply( $request->prompt, $request->style );
		$size    = self::SIZE_MAP[ $request->aspect_ratio ] ?? self::SIZE_MAP['1:1'];
		$auth    = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->api_key,
		);

		if ( null !== $request->reference ) {
			$boundary = 'ux1' . bin2hex( random_bytes( 12 ) );
			$body     = $this->multipart(
				$boundary,
				array(
					'model'   => $this->model,
					'prompt'  => $prompt,
					'size'    => $size,
					'quality' => $quality,
					'n'       => '1',
				),
				$request->reference
			);
			$headers  = $auth + array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
			$url      = self::EDITS_URL;
		} else {
			$body    = Json::encode(
				array(
					'model'   => $this->model,
					'prompt'  => $prompt,
					'size'    => $size,
					'quality' => $quality,
					'n'       => 1,
				)
			);
			$headers = $auth + array( 'Content-Type' => 'application/json' );
			$url     = self::GENERATIONS_URL;
		}

		$response = $this->send_with_retry(
			fn (): HttpResponse => $this->http->request( 'POST', $url, $headers, $body, self::TIMEOUT )
		);
		$this->assert_success( $response );

		$data = $response->json();
		$b64  = $data['data'][0]['b64_json'] ?? null;
		if ( null === $b64 ) {
			throw new \RuntimeException( 'OpenAI API: no image returned.' );
		}
		$binary = base64_decode( (string) $b64, true );
		if ( false === $binary ) {
			throw new \RuntimeException( 'OpenAI API: could not decode the image from the response.' );
		}

		list( $width, $height ) = $this->image_size( $binary );

		// Real token usage wins over the fallback table (gpt-image-app config.py::estimate_cost()).
		$input_tokens  = isset( $data['usage']['input_tokens'] ) ? (int) $data['usage']['input_tokens'] : null;
		$output_tokens = isset( $data['usage']['output_tokens'] ) ? (int) $data['usage']['output_tokens'] : null;
		if ( null !== $input_tokens && null !== $output_tokens ) {
			$cost     = ImagePricing::openai_usage_cost( $this->model, $input_tokens, $output_tokens );
			$estimate = false;
		} else {
			$cost     = ImagePricing::openai_fallback_cost( $this->model, $quality );
			$estimate = true;
		}

		return new ImageResult( $binary, 'image/png', $this->get_id(), $this->model, $width, $height, $cost, $estimate, $input_tokens, $output_tokens );
	}

	/** @param array<string, string> $fields */
	private function multipart( string $boundary, array $fields, ReferenceImage $image ): string {
		$eol  = "\r\n";
		$body = '';
		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol . 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol . $value . $eol;
		}
		$body .= '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="image"; filename="reference.' . $image->extension() . '"' . $eol
			. 'Content-Type: ' . $image->mime . $eol . $eol
			. $image->data . $eol
			. '--' . $boundary . '--' . $eol;
		return $body;
	}
}
