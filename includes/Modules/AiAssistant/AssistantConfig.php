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

		$defaults = self::widget_defaults( $language );

		return array(
			'type'                  => in_array( $type, array( 'bubble', 'sidebar' ), true ) ? $type : 'bubble',
			'position'              => in_array( $pos, array( 'bottom-right', 'bottom-left' ), true ) ? $pos : 'bottom-right',
			'color'                 => self::color( 'widget_color', '#2271b1' ),
			'textColor'             => self::color( 'widget_text_color', '#ffffff' ),
			'title'                 => $text( 'widget_title', $defaults['title'] ),
			'greeting'              => $area( 'widget_greeting', $defaults['greeting'] ),
			'placeholder'           => $text( 'widget_placeholder', $defaults['placeholder'] ),
			'gdprText'              => $area( 'widget_gdpr_text', $defaults['gdpr'] ),
			'autoOpen'              => self::flag( 'widget_auto_open', false ),
			'autoOpenDelay'         => self::int_in( 'widget_auto_open_delay', 5, 0, 120 ),
			'autoOpenSound'         => self::flag( 'widget_auto_open_sound', true ),
			'autoOpenCooldown'      => self::int_in( 'widget_auto_open_cooldown', 30, 0, 365 ),
			'inactivityTimeout'     => self::int_in( 'widget_inactivity_timeout', 60, 0, 600 ),
			'handoffTimeout'        => self::int_in( 'widget_handoff_timeout', 120, 0, 3600 ),
			'handoffTimeoutMessage' => $area( 'widget_handoff_timeout_message', $defaults['handoff'] ),
		);
	}

	/**
	 * Texts of the visitor chat widget (wp_localize_script `i18n`), in the chat language chosen by the site owner.
	 * Keys match DEFAULT_TEXTS in assets/js/ai-assistant-widget.js; the widget falls back to English per key.
	 *
	 * @param string $language 'cs' or 'en'.
	 * @return array<string, string>
	 */
	public static function widget_i18n( string $language ): array {
		$strings = self::cached_for_language(
			'i18n',
			$language,
			static function ( string $lang ): array {
				return self::build_widget_strings( $lang );
			}
		);

		// Currency symbol of the shop (product cards); empty when there is no shop.
		$currency = '';
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$currency = html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		}
		$strings['currency'] = $currency;

		return $strings;
	}

	/**
	 * Result of a callback that reads the translation catalog, kept per chat language and per translation file
	 * (plugin version + modification time of the .mo), so the catalog is not read on every page view.
	 *
	 * @param string                               $name     Name of the cached value.
	 * @param string                               $language 'cs' or 'en'.
	 * @param callable(string): array<string, string> $build    Builds the value for a language.
	 * @return array<string, string>
	 */
	private static function cached_for_language( string $name, string $language, callable $build ): array {
		$language  = ChatLocale::normalize( $language );
		$mo        = UXSTUDIO_PATH . 'languages/ux-studio-cs_CZ.mo';
		$signature = ( defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '' ) . '|' . ( is_file( $mo ) ? (string) filemtime( $mo ) : '' );
		$key       = 'uxs_ai_' . $name . '_' . $language . '_' . substr( md5( $signature ), 0, 12 );
		$cached    = get_transient( $key );
		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}
		$value = $build( $language );
		set_transient( $key, $value, DAY_IN_SECONDS );
		return $value;
	}

	/**
	 * Default widget texts (title, greeting, placeholder, GDPR notice, handoff timeout message) in the chat language.
	 *
	 * @param string $language 'cs' or 'en'.
	 * @return array<string, string>
	 */
	private static function widget_defaults( string $language ): array {
		return self::cached_for_language(
			'defaults',
			$language,
			static function ( string $lang ): array {
				return array(
					'title'       => PromptTemplates::default_title( $lang ),
					'greeting'    => PromptTemplates::default_greeting( $lang ),
					'placeholder' => PromptTemplates::default_placeholder( $lang ),
					'gdpr'        => PromptTemplates::default_gdpr_text( $lang ),
					'handoff'     => PromptTemplates::default_handoff_timeout_message( $lang ),
				);
			}
		);
	}

	/**
	 * The widget texts in the chat language (see widget_i18n()).
	 *
	 * @param string $language 'cs' or 'en'.
	 * @return array<string, string>
	 */
	private static function build_widget_strings( string $language ): array {
		return ChatLocale::run(
			$language,
			static function (): array {
				return array(
					'openChat' => __( 'Open chat', 'ux-studio' ),
					'closeChat' => __( 'Close chat', 'ux-studio' ),
					'writeUs' => __( 'Write to us', 'ux-studio' ),
					'writeUsIntro' => __( 'Write to us and we will get back to you as soon as possible.', 'ux-studio' ),
					'callOperatorAria' => __( 'Call an operator', 'ux-studio' ),
					'talkToOperator' => __( 'Talk to an operator', 'ux-studio' ),
					'callbackTitle' => __( 'We will call you back', 'ux-studio' ),
					'callbackIntro' => __( 'Leave us your phone number and we will call you back.', 'ux-studio' ),
					'enlargeChat' => __( 'Enlarge chat', 'ux-studio' ),
					'shrinkChat' => __( 'Shrink chat', 'ux-studio' ),
					'newChat' => __( 'New chat', 'ux-studio' ),
					'startNewChat' => __( 'Start a new chat', 'ux-studio' ),
					'gdprDefault' => __( 'This chat uses AI. Sending a message means you agree to your query being processed.', 'ux-studio' ),
					'greetingDefault' => __( 'Hello! How can I help you?', 'ux-studio' ),
					'placeholderDefault' => __( 'Type your question...', 'ux-studio' ),
					'messageLabel' => __( 'Message', 'ux-studio' ),
					'send' => __( 'Send', 'ux-studio' ),
					'faqTitle' => __( 'Frequently asked questions', 'ux-studio' ),
					'hideQuestions' => __( 'Hide questions', 'ux-studio' ),
					'showMoreQuestions' => __( 'Show more questions (%d)', 'ux-studio' ),
					'next' => __( 'Next', 'ux-studio' ),
					'show' => __( 'Show', 'ux-studio' ),
					'waitingOperator' => __( 'Waiting for an operator to join...', 'ux-studio' ),
					'operatorName' => __( 'Operator', 'ux-studio' ),
					'operatorOnline' => __( '%s is online', 'ux-studio' ),
					'cancel' => __( 'Cancel', 'ux-studio' ),
					'handoffReason' => __( 'The customer asked for an operator using the button.', 'ux-studio' ),
					'handoffRecorded' => __( 'Your request has been recorded. Please wait for an operator to join.', 'ux-studio' ),
					'handoffFailed' => __( 'Could not call an operator. Please try again.', 'ux-studio' ),
					'handoffCancelled' => __( 'The operator request was cancelled. We are continuing with the AI assistant.', 'ux-studio' ),
					'handoffSendFailed' => __( 'Could not send the message to the operator.', 'ux-studio' ),
					'handoffTimeoutDefault' => __( 'Sorry, an operator is not available right now. Please leave us a message or a phone number and we will get back to you as soon as possible.', 'ux-studio' ),
					'writeMessage' => __( 'Write a message', 'ux-studio' ),
					'callBack' => __( 'Call me back', 'ux-studio' ),
					'continueAssistant' => __( 'Continue with the assistant', 'ux-studio' ),
					'backToChat' => __( 'Back to chat', 'ux-studio' ),
					'resumeText' => __( 'How can I help you?', 'ux-studio' ),
					'serverError' => __( 'Sorry, there was an error communicating with the server.', 'ux-studio' ),
					'nameLabel' => __( 'Name', 'ux-studio' ),
					'namePlaceholder' => __( 'Your name', 'ux-studio' ),
					'emailLabel' => __( 'E-mail', 'ux-studio' ),
					'emailPlaceholder' => __( 'you@example.com', 'ux-studio' ),
					'phoneLabel' => __( 'Phone', 'ux-studio' ),
					'phoneNoteCallback' => __( '(we will call you back)', 'ux-studio' ),
					'phonePlaceholder' => __( 'e.g. +1 555 123 4567', 'ux-studio' ),
					'yourMessageLabel' => __( 'Message', 'ux-studio' ),
					'yourMessagePlaceholder' => __( 'Your message...', 'ux-studio' ),
					'sendMessage' => __( 'Send message', 'ux-studio' ),
					'fillAll' => __( 'Please fill in all fields.', 'ux-studio' ),
					'sending' => __( 'Sending...', 'ux-studio' ),
					'messageSent' => __( 'Message sent! We will get back to you as soon as possible.', 'ux-studio' ),
					'sendFailed' => __( 'Could not send the message.', 'ux-studio' ),
					'connectionError' => __( 'Connection error. Please try again.', 'ux-studio' ),
					'phoneNumberLabel' => __( 'Phone number', 'ux-studio' ),
					'noteLabel' => __( 'Note', 'ux-studio' ),
					'optional' => __( '(optional)', 'ux-studio' ),
					'callNotePlaceholder' => __( 'When can we call you?', 'ux-studio' ),
					'fillPhone' => __( 'Please enter your phone number.', 'ux-studio' ),
					'callbackThanks' => __( 'Thank you! We will get back to you as soon as possible.', 'ux-studio' ),
					'ratingQuestion' => __( 'How would you rate our help?', 'ux-studio' ),
					'starsLabel' => __( '%d of 5 stars', 'ux-studio' ),
					'feedbackPlaceholder' => __( 'Would you like to add anything? (optional)', 'ux-studio' ),
					'sendRating' => __( 'Send rating', 'ux-studio' ),
					'ratingThanks' => __( 'Thank you for your rating!', 'ux-studio' ),
					'inactivityPrompt' => __( 'Is there anything else I can help you with?', 'ux-studio' ),
					'yes' => __( 'Yes', 'ux-studio' ),
					'no' => __( 'No', 'ux-studio' ),
					'askAnything' => __( 'Ask me anything!', 'ux-studio' ),
					'goodbye' => __( 'Thank you for visiting! We would appreciate your rating.', 'ux-studio' ),
				);
			}
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
