<?php
/**
 * AI Assistant module - chat widget with knowledge base (RAG), FAQ, human
 * handoff, blog auto-generation and multi-provider support.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\DB;
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

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables( 'ai-assistant', 1, array( $this, 'migrate' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		ChatBootstrap::register();
		KnowledgeBootstrap::register();
		HandoffBootstrap::register();
		ContentBootstrap::register();
		BlogPilotBootstrap::register();
		InternalChatBootstrap::register();
		McpBootstrap::register();
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
				PRIMARY KEY (id), KEY session_id (session_id), KEY status (status), KEY created_at (created_at),
				KEY rating (rating), KEY handoff_status (handoff_status), KEY handoff_assigned_to (handoff_assigned_to), KEY last_message_at (last_message_at)
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
				PRIMARY KEY (id), KEY document_id (document_id), FULLTEXT KEY ft_content (content, title)
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
				PRIMARY KEY (id), KEY status (status)
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
				PRIMARY KEY (id), KEY is_active (is_active), KEY sort_order (sort_order), FULLTEXT KEY ft_faq (question, answer)
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
				PRIMARY KEY (id), UNIQUE KEY product_id (product_id), KEY parent_id (parent_id), KEY stock_status (stock_status),
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
				PRIMARY KEY (id), UNIQUE KEY post_id (post_id), KEY post_type (post_type), FULLTEXT KEY ft_content (post_title, content_text)
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
				PRIMARY KEY (id), KEY provider (provider), KEY feature (feature), KEY user_id (user_id), KEY created_at (created_at)
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
				PRIMARY KEY (id), KEY user_id (user_id), KEY created_at (created_at)
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
				PRIMARY KEY (id), KEY type (type), KEY status (status), KEY created_at (created_at)
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
				PRIMARY KEY (id), KEY session_id (session_id), KEY provider (provider), KEY error_type (error_type), KEY created_at (created_at)
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
				PRIMARY KEY (id), KEY chat_target (chat_target), KEY source_type (source_type), KEY source_id (source_id), FULLTEXT KEY ft_chunk (chunk_text)
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
				PRIMARY KEY (id), KEY type (type), KEY status (status)
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
				PRIMARY KEY (id), KEY status (status)
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
				PRIMARY KEY (id), KEY generator_id (generator_id), KEY post_id (post_id)
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
				'help'    => __( '0 = unlimited. Resets on the 1st of each month.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'daily_request_limit',
				'type'    => 'number',
				'label'   => __( 'Daily request limit (site-wide)', 'ux-studio' ),
				'help'    => __( '0 = unlimited.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'user_daily_request_limit',
				'type'    => 'number',
				'label'   => __( 'Daily request limit (per user)', 'ux-studio' ),
				'help'    => __( '0 = unlimited.', 'ux-studio' ),
				'default' => 0,
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
				'key'     => 'widget_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Show chat widget on the frontend', 'ux-studio' ),
				'default' => true,
			),
		);
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

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the raw keys back to the client; expose only whether each is set.
	 */
	public function settings_values(): array {
		$values = parent::settings_values();
		foreach ( self::PROVIDER_SECRETS as $provider_id => $secret_option ) {
			$values[ $provider_id . '_api_key' ]   = '';
			$values[ 'has_' . $provider_id . '_api_key' ] = '' !== Security::get_secret( $secret_option );
		}
		return $values;
	}
}
