<?php
/**
 * AI Assistant module - chat widget with knowledge base (RAG), FAQ, human
 * handoff, blog auto-generation and multi-provider support.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\DB;
use UxStudio\Core\Retention;
use UxStudio\Core\Security;
use UxStudio\Modules\AiAssistant\Mcp\McpBootstrap;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * This is the module's foundation layer: schema, provider factory, usage
 * tracking/limiting, error logging and the base settings screen. Later waves
 * add their own REST controllers/bootstrap classes for chat, knowledge base,
 * handoff, blog pilot and MCP - wired in from boot() (see the comment below).
 */
final class Module extends BaseModule {

	private const SECRET_CLAUDE   = 'uxstudio_secret_ai_assistant_claude_api_key';
	private const SECRET_OPENAI   = 'uxstudio_secret_ai_assistant_openai_api_key';
	private const SECRET_DEEPSEEK = 'uxstudio_secret_ai_assistant_deepseek_api_key';

	/**
	 * provider id => secret option name.
	 *
	 * @var array<string, string>
	 */
	private const PROVIDER_SECRETS = array(
		'claude'   => self::SECRET_CLAUDE,
		'openai'   => self::SECRET_OPENAI,
		'deepseek' => self::SECRET_DEEPSEEK,
	);

	/** Daily purge of the usage/error log and internal chat history (see Core\Retention). */
	private const RETENTION_HOOK = 'uxstudio_ai_assistant_retention';

	private const DEFAULT_CHAT_HISTORY_RETENTION_DAYS = 365;

	/** Allowed range of numeric widget settings: key => [min, max]. */
	private const NUMBER_RANGES = array(
		'widget_auto_open_delay'    => array( 0, 120 ),
		'widget_auto_open_cooldown' => array( 0, 365 ),
		'widget_inactivity_timeout' => array( 0, 600 ),
		'widget_handoff_timeout'    => array( 0, 3600 ),
	);

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables( 'ai-assistant', 1, array( $this, 'migrate' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_action( self::RETENTION_HOOK, array( $this, 'purge_old_rows' ) );
		Retention::ensure_scheduled( self::RETENTION_HOOK );

		ChatBootstrap::register();
		KnowledgeBootstrap::register();
		HandoffBootstrap::register();
		ContentBootstrap::register();
		SeoBootstrap::register();
		BlogPilotBootstrap::register();
		InternalChatBootstrap::register();
		McpBootstrap::register();
	}

	/**
	 * Unschedule the retention purge when the module is switched off.
	 */
	public function on_disable(): void {
		Retention::unschedule( self::RETENTION_HOOK );
	}

	/**
	 * Cron callback: drop usage/error-log rows and internal chat history older
	 * than their retention settings (0 = keep forever). All three tables
	 * index created_at.
	 */
	public function purge_old_rows(): void {
		$usage_days = max( 0, (int) $this->settings->get( 'usage_retention_days', Retention::DEFAULT_DAYS ) );
		Retention::purge( 'uxstudio_ai_assistant_usage', $usage_days );
		Retention::purge( 'uxstudio_ai_assistant_error_log', $usage_days );
		Retention::purge( 'uxstudio_ai_assistant_chat_history', max( 0, (int) $this->settings->get( 'chat_history_retention_days', self::DEFAULT_CHAT_HISTORY_RETENTION_DAYS ) ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Creates all 14 AI Assistant tables. Called via DB::ensure_module_tables()
	 * so it only ever runs for sites where this module is enabled.
	 *
	 * @param int $from Previously installed module schema version (unused - v1 is the initial schema).
	 */
	public function migrate( int $from ): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_conversations (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id varchar(64) NOT NULL,
				visitor_ip varchar(45) DEFAULT '',
				visitor_user_agent varchar(500) DEFAULT '',
				page_url varchar(500) DEFAULT '',
				messages longtext,
				status varchar(20) NOT NULL DEFAULT 'active',
				gdpr_consent tinyint(1) NOT NULL DEFAULT 0,
				rating tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
				rating_feedback text,
				handoff_status varchar(20) NOT NULL DEFAULT 'ai',
				handoff_requested_at datetime DEFAULT NULL,
				handoff_assigned_to bigint(20) UNSIGNED DEFAULT NULL,
				handoff_started_at datetime DEFAULT NULL,
				operator_unread int UNSIGNED NOT NULL DEFAULT 0,
				customer_unread int UNSIGNED NOT NULL DEFAULT 0,
				last_message_at datetime DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY status (status),
				KEY created_at (created_at),
				KEY rating (rating),
				KEY handoff_status (handoff_status),
				KEY handoff_assigned_to (handoff_assigned_to),
				KEY last_message_at (last_message_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_knowledge (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				document_id bigint(20) UNSIGNED DEFAULT NULL,
				title varchar(500) NOT NULL DEFAULT '',
				content longtext NOT NULL,
				chunk_index int NOT NULL DEFAULT 0,
				metadata text,
				use_public tinyint(1) NOT NULL DEFAULT 1,
				use_internal tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY document_id (document_id),
				FULLTEXT KEY ft_content (content, title)
			) {$charset} ENGINE=InnoDB;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_documents (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				title varchar(500) NOT NULL DEFAULT '',
				file_name varchar(255) NOT NULL DEFAULT '',
				file_path varchar(500) NOT NULL DEFAULT '',
				file_type varchar(20) NOT NULL DEFAULT '',
				file_size bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				chunk_count int NOT NULL DEFAULT 0,
				use_public tinyint(1) NOT NULL DEFAULT 1,
				use_internal tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'processing',
				error_message text,
				author_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_faqs (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				question varchar(500) NOT NULL,
				answer text NOT NULL,
				category varchar(100) DEFAULT '',
				sort_order int NOT NULL DEFAULT 0,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				use_public tinyint(1) NOT NULL DEFAULT 1,
				use_internal tinyint(1) NOT NULL DEFAULT 0,
				views int NOT NULL DEFAULT 0,
				helpful_yes int NOT NULL DEFAULT 0,
				helpful_no int NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY is_active (is_active),
				KEY sort_order (sort_order),
				FULLTEXT KEY ft_faq (question, answer)
			) {$charset} ENGINE=InnoDB;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_product_index (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id bigint(20) UNSIGNED NOT NULL,
				parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				product_type varchar(20) NOT NULL DEFAULT 'simple',
				name varchar(500) NOT NULL DEFAULT '',
				sku varchar(100) DEFAULT '',
				price decimal(10,2) DEFAULT NULL,
				categories text,
				attributes text,
				description_text text,
				image_url varchar(500) DEFAULT '',
				permalink varchar(500) DEFAULT '',
				stock_status varchar(20) NOT NULL DEFAULT 'instock',
				indexed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY product_id (product_id),
				KEY parent_id (parent_id),
				KEY stock_status (stock_status),
				FULLTEXT KEY ft_product (name, description_text)
			) {$charset} ENGINE=InnoDB;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_content_index (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id bigint(20) UNSIGNED NOT NULL,
				post_type varchar(20) NOT NULL DEFAULT 'post',
				post_title varchar(500) NOT NULL DEFAULT '',
				content_text longtext,
				categories text,
				attributes text,
				image_url varchar(500) DEFAULT '',
				permalink varchar(500) DEFAULT '',
				indexed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY post_id (post_id),
				KEY post_type (post_type),
				FULLTEXT KEY ft_content (post_title, content_text)
			) {$charset} ENGINE=InnoDB;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_usage (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id varchar(64) DEFAULT '',
				user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				provider varchar(50) NOT NULL DEFAULT '',
				model varchar(100) NOT NULL DEFAULT '',
				feature varchar(50) NOT NULL DEFAULT 'chat',
				input_tokens int UNSIGNED NOT NULL DEFAULT 0,
				output_tokens int UNSIGNED NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY provider (provider),
				KEY feature (feature),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_chat_history (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id bigint(20) UNSIGNED NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				messages longtext NOT NULL,
				provider varchar(50) NOT NULL DEFAULT '',
				model varchar(100) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_inquiries (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				type varchar(20) NOT NULL DEFAULT 'email',
				session_id varchar(64) DEFAULT '',
				name varchar(255) DEFAULT '',
				email varchar(255) DEFAULT '',
				phone varchar(50) DEFAULT '',
				message text,
				page_url varchar(500) DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'new',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY type (type),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_error_log (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id varchar(64) DEFAULT '',
				provider varchar(50) DEFAULT '',
				model varchar(100) DEFAULT '',
				error_type varchar(50) NOT NULL DEFAULT 'unknown',
				error_message text,
				http_status smallint UNSIGNED DEFAULT NULL,
				response_excerpt text,
				user_message text,
				page_url varchar(500) DEFAULT '',
				visitor_ip varchar(45) DEFAULT '',
				context longtext,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY provider (provider),
				KEY error_type (error_type),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_vectors (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				chat_target varchar(20) NOT NULL DEFAULT 'public',
				source_type varchar(50) NOT NULL,
				source_id bigint(20) UNSIGNED DEFAULT NULL,
				chunk_text text NOT NULL,
				chunk_index int NOT NULL DEFAULT 0,
				vector longtext NOT NULL,
				embedding_model varchar(100) NOT NULL DEFAULT '',
				metadata text,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY chat_target (chat_target),
				KEY source_type (source_type),
				KEY source_id (source_id),
				FULLTEXT KEY ft_chunk (chunk_text)
			) {$charset} ENGINE=InnoDB;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_training_sources (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				type varchar(50) NOT NULL,
				title varchar(500) NOT NULL DEFAULT '',
				source_url varchar(2000) DEFAULT '',
				content longtext,
				chunk_count int NOT NULL DEFAULT 0,
				vector_count int NOT NULL DEFAULT 0,
				use_public tinyint(1) NOT NULL DEFAULT 1,
				use_internal tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				error_message text,
				last_crawled_at datetime DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY type (type),
				KEY status (status)
			) {$charset} ENGINE=InnoDB;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_blog_generators (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL DEFAULT '',
				topics text,
				article_types text,
				provider varchar(50) NOT NULL DEFAULT '',
				model varchar(100) NOT NULL DEFAULT '',
				config text,
				schedule_type varchar(20) NOT NULL DEFAULT 'daily',
				schedule_config text,
				posts_per_run int NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'active',
				total_posts int NOT NULL DEFAULT 0,
				last_run_at datetime DEFAULT NULL,
				last_error text,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_ai_assistant_blog_generated_posts (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				generator_id bigint(20) UNSIGNED NOT NULL,
				post_id bigint(20) UNSIGNED NOT NULL,
				topic varchar(500) DEFAULT '',
				article_type varchar(100) DEFAULT '',
				provider varchar(50) DEFAULT '',
				model varchar(100) DEFAULT '',
				tokens_used int UNSIGNED NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY generator_id (generator_id),
				KEY post_id (post_id)
			) {$charset};"
		);
	}

	/**
	 * Settings schema: default provider + per-provider API key/model, plus
	 * usage limits and general chat behaviour.
	 */
	public function settings_schema(): array {
		$providers      = ProviderFactory::get_all_providers();
		$provider_names = wp_list_pluck( $providers, 'label' );

		return array(
			array(
				'key'     => 'default_provider',
				'type'    => 'select',
				'label'   => __( 'Default AI provider', 'ux-studio' ),
				'options' => $provider_names,
				'default' => 'claude',
			),
			array(
				'key'     => 'claude_api_key',
				'type'    => 'text',
				'label'   => __( 'Claude API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'claude_model',
				'type'    => 'select',
				'label'   => __( 'Claude model', 'ux-studio' ),
				'options' => $providers['claude']['models'],
				'default' => array_key_first( $providers['claude']['models'] ),
			),
			array(
				'key'     => 'openai_api_key',
				'type'    => 'text',
				'label'   => __( 'OpenAI API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'openai_model',
				'type'    => 'select',
				'label'   => __( 'OpenAI model', 'ux-studio' ),
				'options' => $providers['openai']['models'],
				'default' => array_key_first( $providers['openai']['models'] ),
			),
			array(
				'key'     => 'deepseek_api_key',
				'type'    => 'text',
				'label'   => __( 'DeepSeek API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'deepseek_model',
				'type'    => 'select',
				'label'   => __( 'DeepSeek model', 'ux-studio' ),
				'options' => $providers['deepseek']['models'],
				'default' => array_key_first( $providers['deepseek']['models'] ),
			),
			array(
				'key'     => 'monthly_token_limit',
				'type'    => 'number',
				'label'   => __( 'Monthly token limit', 'ux-studio' ),
				'help'    => __( 'Site-wide, all AI features incl. the public chat. Default 2,000,000 protects against a runaway bill from the anonymous chat. 0 = unlimited. Resets on the 1st of each month.', 'ux-studio' ),
				'default' => UsageLimiter::DEFAULT_MONTHLY_TOKEN_LIMIT,
			),
			array(
				'key'     => 'daily_request_limit',
				'type'    => 'number',
				'label'   => __( 'Daily request limit (site-wide)', 'ux-studio' ),
				'help'    => __( 'All AI requests per day incl. the public chat. Default 500. 0 = unlimited.', 'ux-studio' ),
				'default' => UsageLimiter::DEFAULT_DAILY_REQUEST_LIMIT,
			),
			array(
				'key'     => 'user_daily_request_limit',
				'type'    => 'number',
				'label'   => __( 'Daily request limit (per user)', 'ux-studio' ),
				'help'    => __( 'Logged-in users only - anonymous chat visitors are bound by the site-wide limits and a per-IP rate limit. 0 = unlimited.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'notification_email_hourly_limit',
				'type'    => 'number',
				'label'   => __( 'Notification e-mails per hour', 'ux-studio' ),
				'help'    => __( 'Cap on admin e-mails triggered from the public chat (contact form, callback, operator request). Requests above the cap are still saved, just not e-mailed. 0 = no e-mails.', 'ux-studio' ),
				'default' => PublicGuard::DEFAULT_EMAILS_PER_HOUR,
			),
			array(
				'key'     => 'usage_retention_days',
				'type'    => 'number',
				'label'   => __( 'Keep usage statistics and error log (days)', 'ux-studio' ),
				'help'    => __( 'Older rows are deleted daily. 0 = keep forever.', 'ux-studio' ),
				'default' => Retention::DEFAULT_DAYS,
			),
			array(
				'key'     => 'chat_history_retention_days',
				'type'    => 'number',
				'label'   => __( 'Keep internal chat history (days)', 'ux-studio' ),
				'help'    => __( 'Saved internal (admin) chat conversations started earlier are deleted daily. 0 = keep forever.', 'ux-studio' ),
				'default' => self::DEFAULT_CHAT_HISTORY_RETENTION_DAYS,
			),
			array(
				'key'     => 'mcp_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable MCP (tool calling)', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'language',
				'type'    => 'select',
				'label'   => __( 'Chat language', 'ux-studio' ),
				'options' => array(
					'cs' => __( 'Czech', 'ux-studio' ),
					'en' => __( 'English', 'ux-studio' ),
				),
				'default' => 'cs',
			),
			array(
				'key'     => 'contact_email',
				'type'    => 'text',
				'label'   => __( 'Contact e-mail', 'ux-studio' ),
				'help'    => __( 'Receives messages and callback requests from the chat contact form. Empty or invalid = the site admin e-mail.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'operator_notifications_enabled',
				'type'    => 'toggle',
				'label'   => __( 'E-mail me when a customer asks for an operator', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'operator_notification_email',
				'type'    => 'text',
				'label'   => __( 'Operator notification e-mail', 'ux-studio' ),
				'help'    => __( 'Empty or invalid = the site admin e-mail.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'custom_system_prompt',
				'type'    => 'textarea',
				'label'   => __( 'Custom system prompt', 'ux-studio' ),
				'help'    => __( 'Appended to the default system prompt of the public chat and the content generator.', 'ux-studio' ),
				'default' => '',
			),

			// --- Widget tab -------------------------------------------------
			array(
				'key'     => 'widget_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Show chat widget on the frontend', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'widget_type',
				'type'    => 'select',
				'label'   => __( 'Widget type', 'ux-studio' ),
				'options' => array(
					'bubble'  => __( 'Floating bubble', 'ux-studio' ),
					'sidebar' => __( 'Sidebar panel', 'ux-studio' ),
				),
				'default' => 'bubble',
			),
			array(
				'key'     => 'widget_position',
				'type'    => 'select',
				'label'   => __( 'Position', 'ux-studio' ),
				'options' => array(
					'bottom-right' => __( 'Bottom right', 'ux-studio' ),
					'bottom-left'  => __( 'Bottom left', 'ux-studio' ),
				),
				'default' => 'bottom-right',
			),
			array(
				'key'     => 'widget_color',
				'type'    => 'color',
				'label'   => __( 'Primary colour', 'ux-studio' ),
				'default' => '#2271b1',
			),
			array(
				'key'     => 'widget_text_color',
				'type'    => 'color',
				'label'   => __( 'Text colour', 'ux-studio' ),
				'default' => '#ffffff',
			),
			array(
				'key'     => 'widget_title',
				'type'    => 'text',
				'label'   => __( 'Widget title', 'ux-studio' ),
				'help'    => __( 'Shown in the chat window header. Empty = default text for the chat language.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'widget_greeting',
				'type'    => 'textarea',
				'label'   => __( 'Greeting message', 'ux-studio' ),
				'help'    => __( 'Empty = default text for the chat language.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'widget_placeholder',
				'type'    => 'text',
				'label'   => __( 'Input placeholder', 'ux-studio' ),
				'help'    => __( 'Empty = default text for the chat language.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'widget_gdpr_text',
				'type'    => 'textarea',
				'label'   => __( 'GDPR notice', 'ux-studio' ),
				'help'    => __( 'Empty = default text for the chat language.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'widget_auto_open',
				'type'    => 'toggle',
				'label'   => __( 'Open the chat automatically after the page loads', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_auto_open_delay',
				'type'    => 'number',
				'label'   => __( 'Auto-open delay (seconds)', 'ux-studio' ),
				'help'    => __( '0 to 120.', 'ux-studio' ),
				'default' => 5,
			),
			array(
				'key'     => 'widget_auto_open_sound',
				'type'    => 'toggle',
				'label'   => __( 'Play a sound on auto-open', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'widget_auto_open_cooldown',
				'type'    => 'number',
				'label'   => __( 'Do not auto-open after closing (days)', 'ux-studio' ),
				'help'    => __( 'If the visitor closes the chat manually, auto-open is disabled for this many days (stored in a cookie). 0 = auto-open every time.', 'ux-studio' ),
				'default' => 30,
			),
			array(
				'key'     => 'widget_inactivity_timeout',
				'type'    => 'number',
				'label'   => __( 'Inactivity timeout (seconds)', 'ux-studio' ),
				'help'    => __( 'After this time of inactivity the chat asks whether it can help further. 0 = off.', 'ux-studio' ),
				'default' => 60,
			),
			array(
				'key'     => 'widget_handoff_timeout',
				'type'    => 'number',
				'label'   => __( 'Operator wait timeout (seconds)', 'ux-studio' ),
				'help'    => __( 'If no operator joins in this time, the customer sees an apology with the contact form. 0 = no timeout.', 'ux-studio' ),
				'default' => 120,
			),
			array(
				'key'     => 'widget_handoff_timeout_message',
				'type'    => 'textarea',
				'label'   => __( 'Message after the timeout', 'ux-studio' ),
				'help'    => __( 'Empty = default text for the chat language.', 'ux-studio' ),
				'default' => '',
			),

			// --- Internal chatbot -------------------------------------------
			array(
				'key'     => 'chatbot_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable the internal chatbot', 'ux-studio' ),
				'help'    => __( 'When off, the Internal Chat tab refuses new messages.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'chatbot_provider',
				'type'    => 'select',
				'label'   => __( 'Internal chatbot provider (override)', 'ux-studio' ),
				'help'    => __( 'Empty = the default provider.', 'ux-studio' ),
				'options' => $provider_names,
				'default' => '',
			),
			array(
				'key'     => 'chatbot_model',
				'type'    => 'text',
				'label'   => __( 'Internal chatbot model (override)', 'ux-studio' ),
				'help'    => __( 'Empty = the model configured for the chosen provider.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'chatbot_greeting',
				'type'    => 'textarea',
				'label'   => __( 'Internal chatbot greeting', 'ux-studio' ),
				'help'    => __( 'Shown as the first message of a new internal conversation.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'chatbot_system_prompt',
				'type'    => 'textarea',
				'label'   => __( 'Internal chatbot system prompt', 'ux-studio' ),
				'help'    => __( 'Appended to the default prompt of the internal chatbot.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'internal_rag_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Internal knowledge base (RAG)', 'ux-studio' ),
				'help'    => __( 'The internal chat uses vectors trained for the internal chat target.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'internal_guide_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Site guide (Guide module)', 'ux-studio' ),
				'help'    => __( 'The internal chat knows the Guide module setup checklist.', 'ux-studio' ),
				'default' => true,
			),

			// --- Content generation defaults --------------------------------
			array(
				'key'     => 'default_tone',
				'type'    => 'select',
				'label'   => __( 'Default tone of generated content', 'ux-studio' ),
				'options' => self::tone_options(),
				'default' => 'neutral',
			),
			array(
				'key'     => 'default_length',
				'type'    => 'select',
				'label'   => __( 'Default length of generated content', 'ux-studio' ),
				'options' => self::length_options(),
				'default' => 'medium',
			),
			array(
				'key'     => 'content_language',
				'type'    => 'text',
				'label'   => __( 'Content language (override)', 'ux-studio' ),
				'help'    => __( 'Free text, e.g. "Slovak". Empty = the chat language.', 'ux-studio' ),
				'default' => '',
			),

			// --- Indexing ---------------------------------------------------
			array(
				'key'     => 'index_post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Post types to index', 'ux-studio' ),
				'help'    => __( 'Content of these post types is searchable by the chat. Products are indexed separately.', 'ux-studio' ),
				'options' => self::indexable_post_types(),
				'default' => array( 'post', 'page' ),
			),
			array(
				'key'     => 'index_acf_types',
				'type'    => 'multiselect',
				'label'   => __( 'ACF field types to index', 'ux-studio' ),
				'help'    => __( 'Shared by the content and product index. Structural types (tab, group, ...) are always skipped.', 'ux-studio' ),
				'options' => array_combine( AssistantConfig::ACF_DATA_TYPES, AssistantConfig::ACF_DATA_TYPES ),
				'default' => AssistantConfig::to_list( AssistantConfig::DEFAULT_ACF_TYPES ),
			),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function tone_options(): array {
		return array(
			'neutral'      => __( 'Neutral', 'ux-studio' ),
			'professional' => __( 'Professional', 'ux-studio' ),
			'formal'       => __( 'Formal', 'ux-studio' ),
			'trustworthy'  => __( 'Trustworthy', 'ux-studio' ),
			'friendly'     => __( 'Friendly', 'ux-studio' ),
			'witty'        => __( 'Witty', 'ux-studio' ),
			'persuasive'   => __( 'Persuasive', 'ux-studio' ),
			'enthusiastic' => __( 'Enthusiastic', 'ux-studio' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function length_options(): array {
		return array(
			'short'  => __( 'Short', 'ux-studio' ),
			'medium' => __( 'Medium', 'ux-studio' ),
			'long'   => __( 'Long', 'ux-studio' ),
		);
	}

	/**
	 * Public post types that can be added to the content index (products have their own indexer).
	 *
	 * @return array<string, string>
	 */
	private static function indexable_post_types(): array {
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'product', 'product_variation' ), true ) ) {
				continue;
			}
			$options[ $type->name ] = $type->label . ' (' . $type->name . ')';
		}
		return $options;
	}

	/**
	 * Intercept the three API key fields before they reach the plain settings
	 * option; everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		foreach ( self::PROVIDER_SECRETS as $provider_id => $secret_option ) {
			$field = $provider_id . '_api_key';
			if ( array_key_exists( $field, $input ) && '' !== (string) $input[ $field ] ) {
				Security::store_secret( $secret_option, (string) $input[ $field ] );
			}
			unset( $input[ $field ] );
		}

		// E-mails: keep only valid addresses (an invalid one would silently swallow notifications).
		foreach ( array( 'contact_email', 'operator_notification_email' ) as $email_key ) {
			if ( array_key_exists( $email_key, $input ) ) {
				$email                = sanitize_email( (string) $input[ $email_key ] );
				$input[ $email_key ] = is_email( $email ) ? $email : '';
			}
		}

		// Numeric widget settings: clamp to the ranges the widget supports.
		foreach ( self::NUMBER_RANGES as $num_key => $range ) {
			if ( array_key_exists( $num_key, $input ) ) {
				$input[ $num_key ] = max( $range[0], min( $range[1], (int) $input[ $num_key ] ) );
			}
		}

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the raw keys back to the client; expose only whether each is set.
	 */
	public function settings_values(): array {
		$values = parent::settings_values();

		// Migrated legacy values: '1'/'0' strings for toggles (a '0' string is truthy in JS)
		// and comma-separated strings for the indexing lists.
		foreach ( $this->settings_schema() as $field ) {
			if ( 'toggle' === $field['type'] ) {
				$values[ $field['key'] ] = filter_var( $values[ $field['key'] ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		foreach ( array( 'index_post_types', 'index_acf_types' ) as $list_key ) {
			$values[ $list_key ] = AssistantConfig::to_list( $values[ $list_key ] );
		}

		foreach ( self::PROVIDER_SECRETS as $provider_id => $secret_option ) {
			$values[ $provider_id . '_api_key' ]   = '';
			$values[ 'has_' . $provider_id . '_api_key' ] = '' !== Security::get_secret( $secret_option );
		}
		return $values;
	}
}
