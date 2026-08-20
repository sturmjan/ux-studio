<?php
/**
 * Wires up the internal (admin-side) AI assistant chat REST routes. Called
 * explicitly from Module::boot() once this wave is integrated, same pattern
 * as ChatBootstrap for the public widget.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class InternalChatBootstrap {

	/**
	 * Registers the internal chat REST controller.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		( new InternalChatRestController() )->register_routes();
	}
}
