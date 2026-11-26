<?php
/**
 * Input of TopicPlanner: what the host wants topics for.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class TopicRequest {

	public const MAX_COUNT = 50;

	public readonly int $count;

	/**
	 * @param string             $brief           What the articles should be about (the user's prompt / brief).
	 * @param int                $count           How many proposals are wanted; clamped to 1..50.
	 * @param string             $language        Language code of the titles (cs, en, ...).
	 * @param array<int, string> $existing_titles Titles to avoid (existing posts, earlier proposals).
	 * @param array<int, string> $allowed_types   ArticleTypes ids the proposals may use; empty = all article types.
	 * @param string             $audience        Optional target audience hint.
	 * @param string             $tone            Optional tone hint.
	 * @param string             $site_context    Optional standing description of the site (topic, audience, tone).
	 * @param string             $brand_voice     Optional brand voice text.
	 * @param array<int, string> $categories      Optional category names the model should assign proposals to.
	 * @param bool               $return_extra    true = return up to the over-requested number (count + ~30 %) so the
	 *                                            user can pick; false (default) = clamp to $count.
	 * @param string             $today           Y-m-d used for the date hint; '' = today's date.
	 */
	public function __construct(
		public readonly string $brief,
		int $count = 5,
		public readonly string $language = 'cs',
		public readonly array $existing_titles = array(),
		public readonly array $allowed_types = array(),
		public readonly string $audience = '',
		public readonly string $tone = '',
		public readonly string $site_context = '',
		public readonly string $brand_voice = '',
		public readonly array $categories = array(),
		public readonly bool $return_extra = false,
		public readonly string $today = ''
	) {
		$this->count = max( 1, min( self::MAX_COUNT, $count ) );
	}

	/** How many proposals are asked from the model: count plus ~30 % (CA), so de-duplication does not leave a gap. */
	public function requested_count(): int {
		return $this->count + max( 2, (int) ceil( $this->count * 0.3 ) );
	}
}
