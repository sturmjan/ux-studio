<?php
/**
 * Result of one image generation (CA's unified result array as a value object).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

final class ImageResult {

	public function __construct(
		public readonly string $binary,
		public readonly string $mime,
		public readonly string $provider,
		public readonly string $model,
		public readonly ?int $width,
		public readonly ?int $height,
		public readonly float $cost_usd,
		public readonly bool $cost_is_estimate,
		public readonly ?int $input_tokens = null,
		public readonly ?int $output_tokens = null
	) {}

	/**
	 * Same keys as CA's generateImage() result, plus provider.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'success'          => true,
			'error'            => null,
			'binary'           => $this->binary,
			'mime'             => $this->mime,
			'width'            => $this->width,
			'height'           => $this->height,
			'cost_usd'         => $this->cost_usd,
			'cost_is_estimate' => $this->cost_is_estimate,
			'input_tokens'     => $this->input_tokens,
			'output_tokens'    => $this->output_tokens,
			'model'            => $this->model,
			'provider'         => $this->provider,
		);
	}
}
