<?php
/**
 * Image generation for the AI Assistant: builds the shared core's image provider (ux1-ai-core, Ux1\AiCore\Image) with
 * the plugin's HTTP client and secrets, checks permissions and usage limits, saves the result into the Media Library
 * and logs the usage. Used by the Content Creator tab (ImageRestController) and by the internal staff chat tool.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Image\GeminiImageProvider;
use Ux1\AiCore\Image\ImagePricing;
use Ux1\AiCore\Image\ImageProvider;
use Ux1\AiCore\Image\ImageRequest;
use Ux1\AiCore\Image\ImageResult;
use Ux1\AiCore\Image\ImageTool;
use Ux1\AiCore\Image\OpenAiImageProvider;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Failures are \RuntimeException whose code is the suggested HTTP status (400 bad input, 403 forbidden, 429 limit,
 * 502 provider error, 503 no key configured), so the REST layer and the chat tool can report them uniformly.
 */
final class ImageService {

	/** Image provider ids in display order. */
	public const PROVIDER_IDS = array( 'gemini', 'openai' );

	/** Marks an attachment created by this service (only such attachments are rendered in the chat). */
	public const META_GENERATED = '_uxs_ai_generated';
	public const META_COST      = '_uxs_ai_image_cost_usd';

	/** Usage-log feature name. */
	public const FEATURE = 'image';

	/** Images one chat reply may create (each costs money; protects against a model looping on the tool). */
	public const MAX_CHAT_IMAGES_PER_TURN = 2;

	private const EXTENSIONS = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	);

	private static ?Settings $settings = null;

	private static ?HttpClient $http = null;

	private ?ImageProvider $provider;

	/**
	 * @param ImageProvider|null $provider Injected for tests; null builds the configured one (needs its API key).
	 */
	public function __construct( ?ImageProvider $provider = null ) {
		$this->provider = $provider;
	}

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	private static function http(): HttpClient {
		if ( null === self::$http ) {
			self::$http = new WpHttpClient();
		}
		return self::$http;
	}

	/** Configured image provider id (gemini by default). */
	public static function provider_id(): string {
		$id = (string) self::settings()->get( 'image_provider', 'gemini' );
		return in_array( $id, self::PROVIDER_IDS, true ) ? $id : 'gemini';
	}

	/** Secret option holding the API key of an image provider. */
	public static function secret_option( string $provider_id ): string {
		return ProviderFactory::secret_option( 'image_' . $provider_id );
	}

	public static function has_key( ?string $provider_id = null ): bool {
		return '' !== Security::get_secret( self::secret_option( $provider_id ?? self::provider_id() ) );
	}

	/** "Allow images in chat" setting (off by default: each image costs money). */
	public static function chat_images_enabled(): bool {
		return AssistantConfig::flag( 'chat_images_enabled', false );
	}

	/**
	 * Model ids offered for OpenAI image generation (id => label).
	 *
	 * @return array<string, string>
	 */
	public static function openai_models(): array {
		return OpenAiImageProvider::get_models();
	}

	/**
	 * Core provider for the configured image provider.
	 *
	 * @param bool        $allow_missing_key Build a keyless provider (only to read capabilities and estimate costs).
	 * @throws \RuntimeException (503) When no API key is stored.
	 */
	public static function build_provider( ?string $provider_id = null, bool $allow_missing_key = false ): ImageProvider {
		$provider_id = $provider_id ?? self::provider_id();
		$key         = Security::get_secret( self::secret_option( $provider_id ) );
		if ( '' === $key && ! $allow_missing_key ) {
			throw new \RuntimeException( __( 'No API key is set for the image provider. Add it in the AI Assistant settings.', 'ux-studio' ), 503 );
		}

		if ( 'openai' === $provider_id ) {
			return new OpenAiImageProvider( $key, self::http(), ImagePricing::openai_model( (string) self::settings()->get( 'image_openai_model', '' ) ) );
		}
		return new GeminiImageProvider( $key, self::http() );
	}

	/**
	 * Tools offered to the internal chat. Empty unless the "images in chat" setting is on, an image key exists and the
	 * chat provider can call tools (the Claude Code Bridge cannot).
	 *
	 * @return array<int, array{name:string,description:string,parameters:array<string,mixed>}>
	 */
	public static function chat_tools( string $chat_provider_id ): array {
		if ( ! self::chat_images_enabled() || 'claude_code' === $chat_provider_id || ! self::has_key() ) {
			return array();
		}
		return array( ImageTool::definition() );
	}

	public function provider(): ImageProvider {
		if ( null === $this->provider ) {
			$this->provider = self::build_provider();
		}
		return $this->provider;
	}

	/**
	 * Options for the Content Creator form.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$provider = $this->provider();
		return array(
			'provider'     => $provider->get_id(),
			'label'        => $provider->get_label(),
			'capabilities' => $provider->get_capabilities(),
		);
	}

	/**
	 * Cost estimate for a form input, without calling the API.
	 *
	 * @param array<string, mixed> $input prompt, style, aspect_ratio, resolution, quality, tier.
	 * @return array{cost_usd:float,is_estimate:bool,model:string}
	 */
	public function estimate( array $input ): array {
		return $this->provider()->estimate_cost( $this->request_from_input( $input ) );
	}

	/**
	 * Generates an image from the Content Creator form and saves it into the Media Library.
	 *
	 * @param array<string, mixed> $input  prompt, style, aspect_ratio, resolution, quality, tier.
	 * @param int                  $parent Post to attach the image to (0 = unattached).
	 * @return array<string, mixed> attachment_id, url, edit_url, alt, mime, width, height, model, cost_usd, cost_is_estimate.
	 */
	public function generate( array $input, int $parent = 0 ): array {
		return $this->run( $this->request_from_input( $input ), $parent );
	}

	/**
	 * Generates an image for the chat tool (cheaper defaults from the core's ImageTool).
	 *
	 * @param array<string, mixed> $arguments Tool-call arguments from the model.
	 * @return array<string, mixed> Same shape as generate().
	 */
	public function generate_for_chat( array $arguments ): array {
		try {
			$request = ImageTool::to_request( $arguments, $this->provider()->get_capabilities() );
		} catch ( \InvalidArgumentException $e ) {
			throw new \RuntimeException( $e->getMessage(), 400, $e );
		}
		return $this->run( $request, 0 );
	}

	/**
	 * @param array<string, mixed> $input Raw form input.
	 * @throws \RuntimeException
	 */
	private function request_from_input( array $input ): ImageRequest {
		try {
			return ImageRequest::from_input( $input, $this->provider()->get_capabilities() );
		} catch ( \InvalidArgumentException $e ) {
			throw new \RuntimeException( $e->getMessage(), 400, $e );
		}
	}

	/**
	 * Permission and limit checks, the API call, saving and logging.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	private function run( ImageRequest $request, int $parent ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new \RuntimeException( __( 'You are not allowed to upload files to the Media Library.', 'ux-studio' ), 403 );
		}

		$limit_error = UsageLimiter::check();
		if ( null !== $limit_error ) {
			throw new \RuntimeException( $limit_error, 429 );
		}

		$provider = $this->provider();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the API may take up to the 120 s HTTP timeout.
		}

		try {
			$result = $provider->generate( $request );
		} catch ( \RuntimeException $e ) {
			throw new \RuntimeException( $e->getMessage(), 502, $e );
		}

		$saved = $this->save_to_media( $result, $request, $parent );

		// The usage table has no cost column: log the call (it counts as a request for the daily limits) with the
		// tokens the provider reported (OpenAI) or 0 (Gemini reports none); the USD cost lives on the attachment.
		UsageTracker::log( $provider->get_id(), $result->model, self::FEATURE, (int) $result->input_tokens, (int) $result->output_tokens );

		return $saved + array(
			'model'            => $result->model,
			'cost_usd'         => $result->cost_usd,
			'cost_is_estimate' => $result->cost_is_estimate,
		);
	}

	/**
	 * Stores the bytes as a Media Library attachment.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	private function save_to_media( ImageResult $result, ImageRequest $request, int $parent ): array {
		$extension = self::EXTENSIONS[ $result->mime ] ?? '';
		if ( '' === $extension || '' === $result->binary ) {
			throw new \RuntimeException( __( 'The image provider returned an unsupported image.', 'ux-studio' ), 502 );
		}

		$alt      = trim( mb_substr( (string) preg_replace( '/\s+/', ' ', $request->prompt ), 0, 200 ) );
		$slug     = sanitize_title( mb_substr( $request->prompt, 0, 50 ) );
		$filename = ( '' !== $slug ? $slug : 'ai-image' ) . '-' . gmdate( 'Ymd-His' ) . '.' . $extension;

		$upload = wp_upload_bits( $filename, null, $result->binary );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			throw new \RuntimeException( __( 'The image could not be saved to the uploads folder.', 'ux-studio' ), 500 );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $result->mime,
				'post_title'     => '' !== $alt ? mb_substr( $alt, 0, 80 ) : 'AI image',
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$parent
		);
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			throw new \RuntimeException( __( 'The image could not be added to the Media Library.', 'ux-studio' ), 500 );
		}
		$attachment_id = (int) $attachment_id;

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		update_post_meta( $attachment_id, self::META_GENERATED, 1 );
		update_post_meta( $attachment_id, self::META_COST, $result->cost_usd );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => (string) $upload['url'],
			'edit_url'      => self::media_link( $attachment_id ),
			'alt'           => $alt,
			'mime'          => $result->mime,
			'width'         => $result->width,
			'height'        => $result->height,
		);
	}

	/** Media Library detail of an attachment. */
	public static function media_link( int $attachment_id ): string {
		return admin_url( 'upload.php?item=' . $attachment_id );
	}

	/**
	 * Display data of an attachment created by this service, or null for anything else. The chat renders only what this
	 * returns, never a URL taken from the model's text.
	 *
	 * @return array{id:int,url:string,alt:string,edit_url:string}|null
	 */
	public static function describe( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) || ! get_post_meta( $attachment_id, self::META_GENERATED, true ) ) {
			return null;
		}
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return null;
		}
		return array(
			'id'       => $attachment_id,
			'url'      => (string) $url,
			'alt'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'edit_url' => self::media_link( $attachment_id ),
		);
	}

	/**
	 * Reduces a client-sent image list (ids, or objects with an id) to ids of generated attachments owned by the user.
	 *
	 * @param mixed $images Raw "images" value of a stored/sent chat message.
	 * @return int[]
	 */
	public static function owned_ids( $images, int $user_id ): array {
		if ( ! is_array( $images ) ) {
			return array();
		}
		$ids = array();
		foreach ( array_slice( $images, 0, 20 ) as $image ) {
			$id = (int) ( is_array( $image ) ? ( $image['id'] ?? 0 ) : $image );
			if ( $id > 0 && null !== self::describe( $id ) && (int) get_post_field( 'post_author', $id ) === $user_id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Expands the stored image ids of every message into display objects (unknown ids are dropped).
	 *
	 * @param array<int, mixed> $messages Stored messages.
	 * @return array<int, mixed>
	 */
	public static function expand_message_images( array $messages ): array {
		foreach ( $messages as $i => $message ) {
			if ( ! is_array( $message ) || empty( $message['images'] ) ) {
				continue;
			}
			$described = array();
			foreach ( (array) $message['images'] as $image ) {
				$data = self::describe( (int) ( is_array( $image ) ? ( $image['id'] ?? 0 ) : $image ) );
				if ( null !== $data ) {
					$described[] = $data;
				}
			}
			$messages[ $i ]['images'] = $described;
		}
		return $messages;
	}
}
