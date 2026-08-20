<?php
/**
 * Wires up the chat REST routes and the frontend widget. Called explicitly
 * from Module::boot() once this wave is integrated (not auto-registered here,
 * so it can land independently of that change).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ChatBootstrap {

	/**
	 * Registers the chat REST controller and, on the frontend only, the
	 * widget assets - gated by the "widget_enabled" setting.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		if ( self::widget_enabled() ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_widget_assets' ) );
			add_action( 'wp_footer', array( self::class, 'render_widget_root' ) );
		}
	}

	public static function register_rest_routes(): void {
		( new ChatRestController() )->register_routes();
	}

	private static function widget_enabled(): bool {
		return (bool) ( new Settings( 'uxstudio_ai_assistant' ) )->get( 'widget_enabled', true );
	}

	public static function enqueue_widget_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$css_rel = 'assets/css/ai-assistant-widget.css';
		$js_rel  = 'assets/js/ai-assistant-widget.js';
		$css_path = UXSTUDIO_PATH . $css_rel;
		$js_path  = UXSTUDIO_PATH . $js_rel;
		$version  = (string) max(
			file_exists( $css_path ) ? filemtime( $css_path ) : 0,
			file_exists( $js_path ) ? filemtime( $js_path ) : 0
		);
		if ( '' === $version || '0' === $version ) {
			$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '1.0.0';
		}

		wp_enqueue_style( 'uxstudio-ai-assistant-widget', UXSTUDIO_URL . $css_rel, array(), $version );
		wp_enqueue_script( 'uxstudio-ai-assistant-widget', UXSTUDIO_URL . $js_rel, array(), $version, true );

		$settings = new Settings( 'uxstudio_ai_assistant' );
		$language = (string) $settings->get( 'language', 'cs' );
		if ( ! in_array( $language, array( 'cs', 'en' ), true ) ) {
			$language = 'cs';
		}

		$product_id = 0;
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = get_the_ID() ?: 0;
		}

		wp_localize_script(
			'uxstudio-ai-assistant-widget',
			'uxstudioAiAssistantWidget',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'uxstudio/v1/ai-assistant' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'productId' => $product_id,
				'config'    => array(
					'type'               => 'bubble',
					'position'           => 'bottom-right',
					'color'              => '#2271b1',
					'textColor'          => '#ffffff',
					'title'              => PromptTemplates::default_title( $language ),
					'greeting'           => PromptTemplates::default_greeting( $language ),
					'placeholder'        => PromptTemplates::default_placeholder( $language ),
					'gdprText'           => PromptTemplates::default_gdpr_text( $language ),
					'autoOpen'           => false,
					'autoOpenDelay'      => 5,
					'autoOpenSound'      => true,
					'autoOpenCooldown'   => 30,
					'inactivityTimeout'  => 60,
				),
			)
		);
	}

	public static function render_widget_root(): void {
		echo '<div id="uxstudio-ai-assistant-widget-root"></div>';
	}
}
