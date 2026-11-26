<?php
/**
 * Image generation contract: prompt (+ optional reference image) in, image bytes out.
 *
 * Separate from Provider\TextProvider on purpose (binary result, no messages/tokens). Keys, storage, BYOK and credits
 * stay in the host; a provider only gets an API key and an HttpClient.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

interface ImageProvider {

	public function get_id(): string;

	public function get_label(): string;

	/**
	 * What the provider offers, for building form dropdowns.
	 *
	 * @return array{styles:array<string,array{label:string,suffix:string}>,aspect_ratios:list<string>,resolutions:list<string>,qualities:array<string,string>,tiers:array<string,array{label:string,free:bool}>}
	 */
	public function get_capabilities(): array;

	/** Model id generate() would use for this request, without calling the API (cache keys, history rows). */
	public function resolve_model( ImageRequest $request ): string;

	/**
	 * Cost without calling the API (confirmation step).
	 *
	 * @return array{cost_usd:float,is_estimate:bool,model:string}
	 */
	public function estimate_cost( ImageRequest $request ): array;

	/**
	 * Generates one image.
	 *
	 * @throws \RuntimeException "<Provider> API: HTTP <code>: <detail>" on API errors, or a transport / response problem.
	 */
	public function generate( ImageRequest $request ): ImageResult;
}
