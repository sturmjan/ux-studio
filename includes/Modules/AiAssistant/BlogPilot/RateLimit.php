<?php
/**
 * Per-user budget for the Blog Pilot routes that call the AI (topic suggestions, rewriting an article). On top of the
 * general write limit of Controller::route(): these calls cost money, so the budget is much smaller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	/** AI calls a user may start per window. */
	public const LIMIT = 20;

	/** Window length in seconds. */
	public const WINDOW = 600;

	/**
	 * Spends one call of the user's budget.
	 *
	 * @return bool False when the budget is used up.
	 */
	public static function allow( string $bucket ): bool {
		$key   = 'uxstudio_bp_rl_' . sanitize_key( $bucket ) . '_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::WINDOW );
		return true;
	}
}
