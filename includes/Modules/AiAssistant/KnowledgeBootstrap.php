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

	/** One-time re-embedding of vectors made by the old (inconsistent) fallback embedding, see maybe_schedule_revectorize(). */
	public const CRON_REVECTORIZE = 'uxstudio_ai_assistant_revectorize_batch';

	/** Set once the legacy-vector check ran (so the COUNT query runs once per site, not per request). */
	public const OPTION_REVECTORIZE_CHECKED = 'uxstudio_ai_rag_fallback_v2_checked';

	/** Failed re-embedding runs in a row. */
	public const OPTION_REVECTORIZE_FAILURES = 'uxstudio_ai_rag_revectorize_failures';

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

		add_action(
			self::CRON_REVECTORIZE,
			static function (): void {
				( new TrainingSourceManager() )->revectorize_legacy_batch();
			}
		);
		add_action( 'init', array( self::class, 'maybe_schedule_revectorize' ), 20 );

		// Keep the content/product indexes reasonably fresh as single posts change.
		add_action( 'save_post', array( new ContentIndexer(), 'index_post' ) );
		add_action( 'wp_trash_post', array( new ContentIndexer(), 'remove_post' ) );

		if ( ProductIndexer::is_woocommerce_active() ) {
			add_action( 'woocommerce_update_product', array( new ProductIndexer(), 'index_product' ) );
			add_action( 'woocommerce_new_product', array( new ProductIndexer(), 'index_product' ) );
			add_action( 'wp_trash_post', array( new ProductIndexer(), 'remove_product' ) );
		}
	}

	/**
	 * One-time upgrade step (PLAN.md 45.5): the fallback embedding changed its model id (tf-idf-256 ->
	 * tf-idf-256-v2) because its old vectors were inconsistent, and search now reads only vectors of the current
	 * model. Vectors stored with a legacy id would silently stop matching, so schedule a background re-embedding
	 * of them (from the stored chunk text). Costs one option read per request; the COUNT runs once per site.
	 */
	public static function maybe_schedule_revectorize(): void {
		if ( get_option( self::OPTION_REVECTORIZE_CHECKED ) ) {
			return;
		}

		update_option( self::OPTION_REVECTORIZE_CHECKED, gmdate( 'c' ), true );

		if ( ( new TrainingSourceManager() )->has_legacy_vectors() && ! wp_next_scheduled( self::CRON_REVECTORIZE ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_REVECTORIZE );
		}
	}
}
