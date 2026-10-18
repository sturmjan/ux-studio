<?php
/**
 * "AI Assistant - product description" box on the WooCommerce product edit
 * screen (port of the legacy admin/WooMetabox.php). Generation goes through the
 * existing REST endpoint POST uxstudio/v1/ai-assistant/content/generate-woo;
 * the result is previewed as plain text and inserted into the product editors
 * only when the user clicks the button.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class WooMetabox {

	private const HANDLE = 'uxstudio-ai-woo-metabox';

	public function register(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The generator endpoint needs manage_options, so the box is only offered to
	 * users who can actually call it.
	 */
	private function allowed(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_products' );
	}

	public function add_metabox(): void {
		if ( ! $this->allowed() ) {
			return;
		}
		add_meta_box(
			'uxstudio_ai_product_metabox',
			__( 'AI Assistant - product description', 'ux-studio' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'high'
		);
	}

	public function render(): void {
		$tones   = Module::tone_options();
		$lengths = Module::length_options();
		?>
		<div class="uxstudio-ai-woo">
			<p>
				<label for="uxstudio-ai-woo-desc"><strong><?php esc_html_e( 'Describe the product briefly', 'ux-studio' ); ?></strong></label>
				<textarea id="uxstudio-ai-woo-desc" rows="3" class="widefat" placeholder="<?php esc_attr_e( 'E.g.: Handmade ceramic mug, 350 ml, dishwasher safe...', 'ux-studio' ); ?>"></textarea>
			</p>
			<p>
				<label for="uxstudio-ai-woo-length"><?php esc_html_e( 'Length', 'ux-studio' ); ?></label>
				<select id="uxstudio-ai-woo-length">
					<?php foreach ( $lengths as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( AssistantConfig::default_length(), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="uxstudio-ai-woo-tone" style="margin-left:12px;"><?php esc_html_e( 'Tone', 'ux-studio' ); ?></label>
				<select id="uxstudio-ai-woo-tone">
					<?php foreach ( $tones as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( AssistantConfig::default_tone(), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<button type="button" id="uxstudio-ai-woo-generate" class="button button-primary" disabled><?php esc_html_e( 'Generate product description', 'ux-studio' ); ?></button>
				<span class="spinner" id="uxstudio-ai-woo-spinner" style="float:none;"></span>
				<span id="uxstudio-ai-woo-error" role="alert" style="color:#b32d2e;"></span>
			</p>
			<div id="uxstudio-ai-woo-result" hidden>
				<p><strong><?php esc_html_e( 'Short description:', 'ux-studio' ); ?></strong></p>
				<div id="uxstudio-ai-woo-short" style="white-space:pre-wrap;background:#f6f7f7;padding:8px;"></div>
				<p><strong><?php esc_html_e( 'Full description:', 'ux-studio' ); ?></strong></p>
				<div id="uxstudio-ai-woo-full" style="white-space:pre-wrap;background:#f6f7f7;padding:8px;max-height:260px;overflow:auto;"></div>
				<p>
					<button type="button" id="uxstudio-ai-woo-apply" class="button button-primary"><?php esc_html_e( 'Insert into product', 'ux-studio' ); ?></button>
					<button type="button" id="uxstudio-ai-woo-again" class="button"><?php esc_html_e( 'Generate again', 'ux-studio' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $this->allowed() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/woo-metabox.js', __FILE__ ),
			array(),
			defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false,
			true
		);
		wp_localize_script(
			self::HANDLE,
			'uxStudioAiWoo',
			array(
				'url'  => esc_url_raw( rest_url( 'uxstudio/v1/ai-assistant/content/generate-woo' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n' => array(
					'generating' => __( 'Generating...', 'ux-studio' ),
					'failed'     => __( 'Generation failed.', 'ux-studio' ),
					'inserted'   => __( 'The description was inserted into the product.', 'ux-studio' ),
				),
			)
		);
	}
}
