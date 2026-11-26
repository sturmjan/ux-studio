<?php
/**
 * Image cost estimation (USD), pure. Ported from CA's providers (Gemini price table from nanobanana-app, OpenAI
 * token prices from gpt-image-app config.py). Gemini returns no usage for images, so its cost is always an estimate;
 * OpenAI is exact when the response carries token usage.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

final class ImagePricing {

	public const GEMINI_PREVIEW_MODEL = 'gemini-2.5-flash-image';
	public const GEMINI_FINAL_MODEL   = 'gemini-3-pro-image';
	public const OPENAI_DEFAULT_MODEL = 'gpt-image-1';

	/** Gemini 3 Pro price per image by resolution. The preview tier is free (host tracks the daily quota). */
	public const GEMINI_FINAL = array(
		'1K' => 0.134,
		'2K' => 0.134,
		'4K' => 0.24,
	);

	/** OpenAI price per million input / output tokens. */
	public const OPENAI_TOKENS = array(
		'gpt-image-2'      => array( 'input' => 8.0, 'output' => 30.0 ),
		'gpt-image-1.5'    => array( 'input' => 8.0, 'output' => 32.0 ),
		'gpt-image-1-mini' => array( 'input' => 2.5, 'output' => 8.0 ),
		'gpt-image-1'      => array( 'input' => 10.0, 'output' => 40.0 ),
	);

	/** Approximate OpenAI price per ~1024x1024 image by quality; used when the API returns no usage. */
	public const OPENAI_FALLBACK = array(
		'gpt-image-1'      => array( 'low' => 0.011, 'medium' => 0.042, 'high' => 0.167, 'auto' => 0.042 ),
		'gpt-image-1-mini' => array( 'low' => 0.005, 'medium' => 0.011, 'high' => 0.036, 'auto' => 0.011 ),
		'gpt-image-1.5'    => array( 'low' => 0.012, 'medium' => 0.045, 'high' => 0.178, 'auto' => 0.045 ),
		'gpt-image-2'      => array( 'low' => 0.011, 'medium' => 0.042, 'high' => 0.167, 'auto' => 0.042 ),
	);

	/** Known OpenAI model or the default (CA: an unknown configured model silently becomes gpt-image-1). */
	public static function openai_model( string $configured ): string {
		$configured = trim( $configured );
		return array_key_exists( $configured, self::OPENAI_TOKENS ) ? $configured : self::OPENAI_DEFAULT_MODEL;
	}

	public static function openai_quality( string $quality ): string {
		return in_array( $quality, ImageRequest::QUALITIES, true ) ? $quality : 'medium';
	}

	public static function gemini_cost( string $tier, string $resolution ): float {
		if ( 'preview' === $tier ) {
			return 0.0;
		}
		return self::GEMINI_FINAL[ $resolution ] ?? self::GEMINI_FINAL['1K'];
	}

	public static function openai_fallback_cost( string $model, string $quality ): float {
		$table = self::OPENAI_FALLBACK[ self::openai_model( $model ) ];
		return $table[ self::openai_quality( $quality ) ] ?? $table['medium'];
	}

	/** Exact cost from token usage. */
	public static function openai_usage_cost( string $model, int $input_tokens, int $output_tokens ): float {
		$price = self::OPENAI_TOKENS[ self::openai_model( $model ) ];
		return ( $input_tokens / 1_000_000 * $price['input'] ) + ( $output_tokens / 1_000_000 * $price['output'] );
	}

	/**
	 * Estimate without calling the API.
	 *
	 * @param string $provider 'gemini' or 'openai'.
	 * @param string $model    OpenAI only: the host-configured model (unknown values fall back to the default).
	 * @return array{cost_usd:float,is_estimate:bool,model:string}
	 * @throws \InvalidArgumentException On an unknown provider.
	 */
	public static function estimate( string $provider, ImageRequest $request, string $model = '' ): array {
		if ( 'gemini' === $provider ) {
			return array(
				'cost_usd'    => self::gemini_cost( $request->tier, $request->resolution ),
				'is_estimate' => true,
				'model'       => 'preview' === $request->tier ? self::GEMINI_PREVIEW_MODEL : self::GEMINI_FINAL_MODEL,
			);
		}
		if ( 'openai' === $provider ) {
			$model = self::openai_model( $model );
			return array(
				'cost_usd'    => self::openai_fallback_cost( $model, $request->quality ),
				'is_estimate' => true,
				'model'       => $model,
			);
		}
		throw new \InvalidArgumentException( 'Unknown image provider: ' . $provider );
	}
}
