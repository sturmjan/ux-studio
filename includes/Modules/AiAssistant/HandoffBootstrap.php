<?php
/**
 * Wires up the handoff REST routes. Called explicitly from Module::boot()
 * once this wave is integrated (not auto-registered here, so it can land
 * independently of that change) - see the "Later waves add" comment there.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class HandoffBootstrap {

	/**
	 * Registers the handoff REST controller. The public chat widget's assets
	 * are already enqueued by ChatBootstrap::register() - the handoff UI
	 * (call-an-operator button, status banner, polling) lives inside
	 * ai-assistant-widget.js and only needs these routes to become live.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		( new HandoffRestController() )->register_routes();
	}
}
