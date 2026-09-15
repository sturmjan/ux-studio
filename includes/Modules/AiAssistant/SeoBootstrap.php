<?php
/**
 * Wires up the SEO score bridge REST route (RankMath Content AI parity,
 * PLAN.md §17, F1). Same pattern as ContentBootstrap.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class SeoBootstrap {

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		( new SeoScorePanel() )->register_routes();
	}
}
