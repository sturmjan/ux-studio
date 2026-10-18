<?php
/**
 * Read-side helpers for the AI Assistant settings that the legacy plugin
 * stored under the same keys (widget_*, contact_email, operator_*, index_*,
 * default_tone/length, content_language, chatbot_*).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The Migrator copies legacy settings verbatim, so a value can be a real bool
 * or a legacy '1'/'0'/'' string, and the indexing lists a comma-separated
 * string or an array. Everything is normalised here, once, so call sites stay
 * simple and a migrated value behaves exactly as it did in the legacy plugin.
 */
final class AssistantConfig {

	/** Legacy default ACF types (same list as the legacy Indexing tab). */
	public const DEFAULT_ACF_TYPES = 'text,textarea,number,email,wysiwyg,select,checkbox,radio,true_false,date_picker,range,repeater,taxonomy';

	/** ACF types that are never indexed whatever the setting says (structural, no text). */
	private const ACF_ALWAYS_SKIP = array( 'tab', 'accordion', 'message', 'group', 'flexible_content', 'clone' );

	/** Every ACF data type the Indexing setting can switch on/off. */
	public const ACF_DATA_TYPES = array(
		'text',
		'textarea',
		'number',
		'email',
		'url',
		'wysiwyg',
		'select',
		'checkbox',
		'radio',
		'true_false',
		'date_picker',
		'color_picker',
		'range',
		'link',
		'image',
		'gallery',
		'file',
		'google_map',
		'post_object',
		'relationship',
		'page_link',
		'repeater',
		'oembed',
		'taxonomy',
		'user',
	);

	public const TONES = array( 'neutral', 'professional', 'formal', 'trustworthy', 'friendly', 'witty', 'persuasive', 'enthusiastic' );

	public const LENGTHS = array( 'short', 'medium', 'long' );

	private static ?Settings $settings = null;

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	/**
	 * Boolean setting; accepts true/false, 1/0 and the legacy '1'/'0'/'' strings.
	 *
	 * @param string $key     Setting key.
	 * @param bool   $default Value when the key was never stored.
	 */
	public static function flag( string $key, bool $default ): bool {
		$all = self::settings()->all();
		if ( ! array_key_exists( $key, $all ) ) {
			return $default;
		}
		return filter_var( $all[ $key ], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Integer setting clamped to a range (a migrated/hand-edited value can be anything).
	 */
	public static function int_in( string $key, int $default, int $min, int $max ): int {
		$value = self::settings()->get( $key, $default );
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Plain-text setting; empty string when unset.
	 */
	public static function text( string $key ): string {
		$value = self::settings()->get( $key, '' );
		return is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
	}

	/**
	 * Validated e-mail setting, or '' when unset/invalid.
	 */
	public static function email( string $key ): string {
		$value = self::settings()->get( $key, '' );
		if ( ! is_string( $value ) ) {
			return '';
		}
		$email = sanitize_email( $value );
		return ( '' !== $email && is_email( $email ) ) ? $email : '';
	}

	/**
	 * Recipient of the chat contact form / callback notifications.
	 */
	public static function contact_email(): string {
		$email = self::email( 'contact_email' );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Recipient of "customer wants a human operator" notifications, or '' when
	 * the operator notifications are switched off.
	 */
	public static function operator_email(): string {
		if ( ! self::flag( 'operator_notifications_enabled', true ) ) {
			return '';
		}
		$email = self::email( 'operator_notification_email' );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Hex colour setting with fallback.
	 */
	private static function color( string $key, string $default ): string {
		$color = sanitize_hex_color( (string) self::settings()->get( $key, '' ) );
		return $color ? $color : $default;
	}

	/**
	 * Config handed to the public widget (wp_localize_script 'config'). Text
	 * defaults follow the chat language; every value is sanitised again here
	 * because the widget renders on the public frontend.
	 *
	 * @param string $language 'cs' or 'en'.
	 * @return array<string, mixed>
	 */
	public static function widget_config( string $language ): array {
		$type = (string) self::settings()->get( 'widget_type', 'bubble' );
		$pos  = (string) self::settings()->get( 'widget_position', 'bottom-right' );

		$text = static function ( string $key, string $default ): string {
			$value = self::text( $key );
			return '' !== $value ? $value : $default;
		};

		// Textareas keep their line breaks (strip tags only).
		$area = static function ( string $key, string $default ): string {
			$value = self::settings()->get( $key, '' );
			$value = is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
			return '' !== $value ? $value : $default;
		};

		return array(
			'type'                  => in_array( $type, array( 'bubble', 'sidebar' ), true ) ? $type : 'bubble',
			'position'              => in_array( $pos, array( 'bottom-right', 'bottom-left' ), true ) ? $pos : 'bottom-right',
			'color'                 => self::color( 'widget_color', '#2271b1' ),
			'textColor'             => self::color( 'widget_text_color', '#ffffff' ),
			'title'                 => $text( 'widget_title', PromptTemplates::default_title( $language ) ),
			'greeting'              => $area( 'widget_greeting', PromptTemplates::default_greeting( $language ) ),
			'placeholder'           => $text( 'widget_placeholder', PromptTemplates::default_placeholder( $language ) ),
			'gdprText'              => $area( 'widget_gdpr_text', PromptTemplates::default_gdpr_text( $language ) ),
			'autoOpen'              => self::flag( 'widget_auto_open', false ),
			'autoOpenDelay'         => self::int_in( 'widget_auto_open_delay', 5, 0, 120 ),
			'autoOpenSound'         => self::flag( 'widget_auto_open_sound', true ),
			'autoOpenCooldown'      => self::int_in( 'widget_auto_open_cooldown', 30, 0, 365 ),
			'inactivityTimeout'     => self::int_in( 'widget_inactivity_timeout', 60, 0, 600 ),
			'handoffTimeout'        => self::int_in( 'widget_handoff_timeout', 120, 0, 3600 ),
			'handoffTimeoutMessage' => $area( 'widget_handoff_timeout_message', PromptTemplates::default_handoff_timeout_message( $language ) ),
		);
	}

	/**
	 * Comma-separated string (legacy) or array (new multiselect) -> clean list.
	 *
	 * @param mixed $value Raw stored value.
	 * @return array<int, string>
	 */
	public static function to_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$list = array();
		foreach ( $value as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' !== $item ) {
				$list[] = $item;
			}
		}
		return array_values( array_unique( $list ) );
	}

	/**
	 * Post types the content index covers (setting `index_post_types`).
	 *
	 * @param array<int, string> $default Used when the setting was never saved.
	 * @return array<int, string>
	 */
	public static function index_post_types( array $default ): array {
		$all = self::settings()->all();
		return array_key_exists( 'index_post_types', $all ) ? self::to_list( $all['index_post_types'] ) : $default;
	}

	/**
	 * ACF field types to skip when indexing (everything not enabled in `index_acf_types`).
	 *
	 * @return array<int, string>
	 */
	public static function acf_skip_types(): array {
		$all     = self::settings()->all();
		$allowed = self::to_list( array_key_exists( 'index_acf_types', $all ) ? $all['index_acf_types'] : self::DEFAULT_ACF_TYPES );

		return array_values( array_merge( self::ACF_ALWAYS_SKIP, array_diff( self::ACF_DATA_TYPES, $allowed ) ) );
	}

	/**
	 * Default tone for generated content (setting `default_tone`).
	 */
	public static function default_tone(): string {
		$tone = sanitize_key( (string) self::settings()->get( 'default_tone', 'neutral' ) );
		return '' !== $tone ? $tone : 'neutral';
	}

	/**
	 * Default length for generated content (setting `default_length`).
	 */
	public static function default_length(): string {
		$length = (string) self::settings()->get( 'default_length', 'medium' );
		return in_array( $length, self::LENGTHS, true ) ? $length : 'medium';
	}

	/**
	 * Free-text content language override (setting `content_language`, e.g.
	 * "čeština" / "Slovak"), or '' to follow the chat language.
	 */
	public static function content_language(): string {
		return mb_substr( self::text( 'content_language' ), 0, 60 );
	}
}
