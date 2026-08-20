<?php
/**
 * Bootstrap for the Knowledge Base / FAQ / RAG / Knowledge Hub wave of the
 * AI Assistant module: REST routes + batch-reindex cron hooks.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Modules\AiAssistant\Rag\TrainingSourceManager;

defined( 'ABSPATH' ) || exit;

/**
 * Called from Module::boot() by the orchestrator once this wave is wired in
 * (see the comment in Module::boot()) - never called from within this file.
 * All heavy work (content/product reindex, RAG training source processing)
 * runs in small batches via wp_schedule_single_event(), never inline in a
 * request, matching the ImageOptimizer bulk pattern.
 */
final class KnowledgeBootstrap {

	public const CRON_CONTENT  = 'uxstudio_ai_assistant_reindex_content_batch';
	public const CRON_PRODUCTS = 'uxstudio_ai_assistant_reindex_products_batch';
	public const CRON_TRAINING = 'uxstudio_ai_assistant_training_batch';

	/**
	 * Register REST routes and cron batch handlers.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				( new KnowledgeHubRestController() )->register_routes();
			}
		);

		add_action( self::CRON_CONTENT, array( new ContentIndexer(), 'process_batch' ) );
		add_action( self::CRON_PRODUCTS, array( new ProductIndexer(), 'process_batch' ) );
		add_action(
			self::CRON_TRAINING,
			static function (): void {
				( new TrainingSourceManager() )->process_next_batch();
			}
		);

		// Keep the content/product indexes reasonably fresh as single posts change.
		add_action( 'save_post', array( new ContentIndexer(), 'index_post' ) );
		add_action( 'wp_trash_post', array( new ContentIndexer(), 'remove_post' ) );

		if ( ProductIndexer::is_woocommerce_active() ) {
			add_action( 'woocommerce_update_product', array( new ProductIndexer(), 'index_product' ) );
			add_action( 'woocommerce_new_product', array( new ProductIndexer(), 'index_product' ) );
			add_action( 'wp_trash_post', array( new ProductIndexer(), 'remove_product' ) );
		}
	}
}
