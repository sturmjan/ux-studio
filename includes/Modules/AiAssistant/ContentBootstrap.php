<?php
/**
 * Wires up the Content Creator REST routes. Called explicitly from
 * Module::boot() once this wave is integrated (not auto-registered here, so
 * it can land independently of that change) - see the "Later waves add:"
 * comment in Module::boot().
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class ContentBootstrap {

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		( new ContentRestController() )->register_routes();
	}
}
