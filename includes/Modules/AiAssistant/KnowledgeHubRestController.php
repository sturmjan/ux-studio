<?php
/**
 * Knowledge Hub REST controller: knowledge base, documents, FAQ, content/
 * product reindexing and RAG training sources. All routes require
 * manage_options (Controller::route()'s default capability).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\AiAssistant\Rag\EmbeddingFactory;
use UxStudio\Modules\AiAssistant\Rag\TrainingSourceManager;
use UxStudio\Modules\AiAssistant\Rag\VectorStore;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST/DELETE uxstudio/v1/ai-assistant/knowledge[/{id}]        - manual knowledge entries
 * POST            uxstudio/v1/ai-assistant/knowledge/upload        - upload a document
 * GET             uxstudio/v1/ai-assistant/knowledge/document/{id} - document + its chunks
 * GET/POST/DELETE uxstudio/v1/ai-assistant/faqs/admin[/{id}]       - FAQ CRUD
 * POST            uxstudio/v1/ai-assistant/faqs/analyze            - suggest FAQs from past chats
 * POST            uxstudio/v1/ai-assistant/faqs/generate           - suggest FAQs from a topic
 * POST/GET        uxstudio/v1/ai-assistant/reindex-products[/status] - batch reindex
 * POST/GET        uxstudio/v1/ai-assistant/reindex-content[/status]  - batch reindex
 * GET/POST        uxstudio/v1/ai-assistant/training-sources        - RAG training sources
 * DELETE          uxstudio/v1/ai-assistant/training-sources/{id}
 * POST            uxstudio/v1/ai-assistant/training-sources/{id}/reindex
 * POST            uxstudio/v1/ai-assistant/rag/index-existing      - vectorize KB/FAQ/product/content
 * GET             uxstudio/v1/ai-assistant/rag/stats                - vector/embedding/index stats
 */
final class KnowledgeHubRestController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// ── Knowledge base (manual entries + documents) ──

		$this->route( '/ai-assistant/knowledge', 'GET', array( $this, 'list_knowledge' ) );
		$this->route(
			'/ai-assistant/knowledge',
			'POST',
			array( $this, 'create_knowledge' ),
			array(
				'title'         => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'content'       => array( 'required' => true, 'type' => 'string' ),
				'use_public'    => array( 'required' => false, 'type' => 'boolean' ),
				'use_internal'  => array( 'required' => false, 'type' => 'boolean' ),
			)
		);
		$this->route(
			'/ai-assistant/knowledge/(?P<id>\d+)',
			'GET',
			array( $this, 'get_knowledge' )
		);
		$this->route(
			'/ai-assistant/knowledge/(?P<id>\d+)',
			'POST',
			array( $this, 'update_knowledge' ),
			array(
				'title'        => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'content'      => array( 'required' => false, 'type' => 'string' ),
				'use_public'   => array( 'required' => false, 'type' => 'boolean' ),
				'use_internal' => array( 'required' => false, 'type' => 'boolean' ),
			)
		);
		$this->route( '/ai-assistant/knowledge/(?P<id>\d+)', 'DELETE', array( $this, 'delete_knowledge' ) );

		$this->route( '/ai-assistant/knowledge/upload', 'POST', array( $this, 'upload_document' ) );
		$this->route( '/ai-assistant/knowledge/document/(?P<id>\d+)', 'GET', array( $this, 'get_document' ) );

		// ── FAQ ──

		$this->route( '/ai-assistant/faqs/admin', 'GET', array( $this, 'list_faqs' ) );
		$this->route(
			'/ai-assistant/faqs/admin',
			'POST',
			array( $this, 'create_faq' ),
			array(
				'question' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'answer'   => array( 'required' => true, 'type' => 'string' ),
				'category' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			)
		);
		$this->route( '/ai-assistant/faqs/admin/(?P<id>\d+)', 'GET', array( $this, 'get_faq' ) );
		$this->route( '/ai-assistant/faqs/admin/(?P<id>\d+)', 'POST', array( $this, 'update_faq' ) );
		$this->route( '/ai-assistant/faqs/admin/(?P<id>\d+)', 'DELETE', array( $this, 'delete_faq' ) );

		$this->route(
			'/ai-assistant/faqs/analyze',
			'POST',
			array( $this, 'analyze_faqs' ),
			array(
				'conv_limit'      => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'max_suggestions' => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			)
		);
		$this->route(
			'/ai-assistant/faqs/generate',
			'POST',
			array( $this, 'generate_faqs' ),
			array(
				'topic'           => array( 'required' => true, 'type' => 'string' ),
				'max_suggestions' => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			)
		);

		// ── Content / product reindexing (batched via cron) ──

		$this->route( '/ai-assistant/reindex-products', 'POST', array( $this, 'reindex_products' ) );
		$this->route( '/ai-assistant/reindex-products/status', 'GET', array( $this, 'reindex_products_status' ) );
		$this->route( '/ai-assistant/reindex-content', 'POST', array( $this, 'reindex_content' ) );
		$this->route( '/ai-assistant/reindex-content/status', 'GET', array( $this, 'reindex_content_status' ) );

		// ── RAG training sources ──

		$this->route( '/ai-assistant/training-sources', 'GET', array( $this, 'list_training_sources' ) );
		$this->route(
			'/ai-assistant/training-sources',
			'POST',
			array( $this, 'create_training_source' ),
			array(
				'type'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'title' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'url'   => array( 'required' => false, 'type' => 'string' ),
				'text'  => array( 'required' => false, 'type' => 'string' ),
			)
		);
		$this->route( '/ai-assistant/training-sources/(?P<id>\d+)', 'DELETE', array( $this, 'delete_training_source' ) );
		$this->route( '/ai-assistant/training-sources/(?P<id>\d+)/reindex', 'POST', array( $this, 'reindex_training_source' ) );

		// ── RAG stats / bulk vectorization of existing data ──

		$this->route(
			'/ai-assistant/rag/index-existing',
			'POST',
			array( $this, 'index_existing' ),
			array(
				'types' => array( 'required' => false, 'type' => 'array' ),
			)
		);
		$this->route( '/ai-assistant/rag/stats', 'GET', array( $this, 'rag_stats' ) );
	}

	// =====================================================================
	// Knowledge base
	// =====================================================================

	public function list_knowledge( WP_REST_Request $request ) {
		$manager = new KnowledgeManager();
		return $this->ok(
			array(
				'documents'      => $manager->get_documents(),
				'manual_entries' => $manager->get_manual_entries(),
			)
		);
	}

	public function create_knowledge( WP_REST_Request $request ) {
		$manager = new KnowledgeManager();
		$id      = $manager->add_manual_entry(
			(string) $request->get_param( 'title' ),
			(string) $request->get_param( 'content' ),
			null === $request->get_param( 'use_public' ) ? true : (bool) $request->get_param( 'use_public' ),
			(bool) $request->get_param( 'use_internal' )
		);

		ActivityLog::log( 'ai-assistant', 'knowledge_create', 'knowledge', $id );

		return $this->ok( array( 'id' => $id ) );
	}

	public function get_knowledge( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$entries = ( new KnowledgeManager() )->get_manual_entries();
		foreach ( $entries as $entry ) {
			if ( (int) $entry['id'] === $id ) {
				return $this->ok( $entry );
			}
		}
		return new WP_Error( 'uxstudio_not_found', __( 'Entry not found.', 'ux-studio' ), array( 'status' => 404 ) );
	}

	public function update_knowledge( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$manager = new KnowledgeManager();

		if ( null !== $request->get_param( 'title' ) || null !== $request->get_param( 'content' ) ) {
			$manager->update_manual_entry( $id, (string) $request->get_param( 'title' ), (string) $request->get_param( 'content' ) );
		}
		if ( null !== $request->get_param( 'use_public' ) || null !== $request->get_param( 'use_internal' ) ) {
			$manager->set_manual_entry_targets( $id, (bool) $request->get_param( 'use_public' ), (bool) $request->get_param( 'use_internal' ) );
		}

		ActivityLog::log( 'ai-assistant', 'knowledge_update', 'knowledge', $id );

		return $this->ok( array( 'updated' => true ) );
	}

	/**
	 * Deletes either a manual entry OR a document (tried in that order) - the
	 * admin UI uses a single "/knowledge/{id}" delete action for both rows in
	 * its unified list, since manual-entry ids and document ids are drawn
	 * from different tables and never collide in the UI's row identity.
	 */
	public function delete_knowledge( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$manager = new KnowledgeManager();

		if ( $manager->delete_manual_entry( $id ) ) {
			ActivityLog::log( 'ai-assistant', 'knowledge_delete', 'knowledge', $id );
			return $this->ok( array( 'deleted' => true ) );
		}

		if ( $manager->delete_document( $id ) ) {
			ActivityLog::log( 'ai-assistant', 'document_delete', 'document', $id );
			return $this->ok( array( 'deleted' => true ) );
		}

		return new WP_Error( 'uxstudio_not_found', __( 'Entry not found.', 'ux-studio' ), array( 'status' => 404 ) );
	}

	/**
	 * Upload a document ($_FILES['file']) into the knowledge base.
	 */
	public function upload_document( WP_REST_Request $request ) {
		if ( empty( $_FILES['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST nonce/capability already enforced by Controller::route().
			return new WP_Error( 'uxstudio_missing_file', __( 'No file was uploaded.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$use_public   = null === $request->get_param( 'use_public' ) ? true : (bool) $request->get_param( 'use_public' );
		$use_internal = (bool) $request->get_param( 'use_internal' );

		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$document = ( new KnowledgeManager() )->upload_document( $_FILES['file'], get_current_user_id(), $use_public, $use_internal );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_upload_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		ActivityLog::log( 'ai-assistant', 'document_upload', 'document', (int) ( $document['id'] ?? 0 ) );

		return $this->ok( $document );
	}

	public function get_document( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$manager = new KnowledgeManager();
		$document = $manager->get_document( $id );

		if ( null === $document ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Document not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$document['chunks'] = $manager->get_chunks( $id );

		return $this->ok( $document );
	}

	// =====================================================================
	// FAQ
	// =====================================================================

	public function list_faqs( WP_REST_Request $request ) {
		$manager = new FaqManager();
		return $this->ok( array( 'items' => $manager->get_all(), 'categories' => $manager->get_categories() ) );
	}

	public function create_faq( WP_REST_Request $request ) {
		$id = ( new FaqManager() )->create(
			array(
				'question'     => (string) $request->get_param( 'question' ),
				'answer'       => (string) $request->get_param( 'answer' ),
				'category'     => (string) $request->get_param( 'category' ),
				'use_public'   => null === $request->get_param( 'use_public' ) ? 1 : (bool) $request->get_param( 'use_public' ),
				'use_internal' => (bool) $request->get_param( 'use_internal' ),
			)
		);

		ActivityLog::log( 'ai-assistant', 'faq_create', 'faq', $id );

		return $this->ok( array( 'id' => $id ) );
	}

	public function get_faq( WP_REST_Request $request ) {
		$faq = ( new FaqManager() )->get( absint( $request->get_param( 'id' ) ) );
		if ( null === $faq ) {
			return new WP_Error( 'uxstudio_not_found', __( 'FAQ not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $faq );
	}

	public function update_faq( WP_REST_Request $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$data = array();

		foreach ( array( 'question', 'answer', 'category' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = (string) $request->get_param( $key );
			}
		}
		foreach ( array( 'sort_order', 'is_active', 'use_public', 'use_internal' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}

		if ( ! ( new FaqManager() )->update( $id, $data ) ) {
			return new WP_Error( 'uxstudio_update_failed', __( 'Could not update the FAQ.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		ActivityLog::log( 'ai-assistant', 'faq_update', 'faq', $id );

		return $this->ok( array( 'updated' => true ) );
	}

	public function delete_faq( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! ( new FaqManager() )->delete( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'FAQ not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		ActivityLog::log( 'ai-assistant', 'faq_delete', 'faq', $id );

		return $this->ok( array( 'deleted' => true ) );
	}

	public function analyze_faqs( WP_REST_Request $request ) {
		$conv_limit      = (int) $request->get_param( 'conv_limit' ) ?: 60;
		$max_suggestions = (int) $request->get_param( 'max_suggestions' ) ?: 10;

		return $this->ok( ( new FaqAnalyzer() )->analyze( $conv_limit, $max_suggestions ) );
	}

	public function generate_faqs( WP_REST_Request $request ) {
		$max_suggestions = (int) $request->get_param( 'max_suggestions' ) ?: 5;

		return $this->ok( ( new FaqAnalyzer() )->generate( (string) $request->get_param( 'topic' ), $max_suggestions ) );
	}

	// =====================================================================
	// Content / product reindexing
	// =====================================================================

	public function reindex_products( WP_REST_Request $request ) {
		ActivityLog::log( 'ai-assistant', 'reindex_products_start' );
		return $this->ok( ( new ProductIndexer() )->queue_reindex() );
	}

	public function reindex_products_status( WP_REST_Request $request ) {
		return $this->ok( ( new ProductIndexer() )->queue_status() + ( new ProductIndexer() )->get_index_stats() );
	}

	public function reindex_content( WP_REST_Request $request ) {
		ActivityLog::log( 'ai-assistant', 'reindex_content_start' );
		return $this->ok( ( new ContentIndexer() )->queue_reindex() );
	}

	public function reindex_content_status( WP_REST_Request $request ) {
		return $this->ok( ( new ContentIndexer() )->queue_status() + ( new ContentIndexer() )->get_index_stats() );
	}

	// =====================================================================
	// RAG training sources
	// =====================================================================

	public function list_training_sources( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' ) ?: 20;

		return $this->ok( ( new TrainingSourceManager() )->get_sources( $page, $per_page ) );
	}

	public function create_training_source( WP_REST_Request $request ) {
		$type  = (string) $request->get_param( 'type' );
		$title = (string) $request->get_param( 'title' );

		if ( ! in_array( $type, array( 'url', 'sitemap', 'text' ), true ) ) {
			return new WP_Error( 'uxstudio_invalid_type', __( 'Invalid source type.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$targets = $this->parse_targets( $request );
		$manager = new TrainingSourceManager();

		switch ( $type ) {
			case 'url':
				$url = esc_url_raw( (string) $request->get_param( 'url' ) );
				if ( '' === $url ) {
					return new WP_Error( 'uxstudio_missing_url', __( 'A URL is required.', 'ux-studio' ), array( 'status' => 400 ) );
				}
				$id = $manager->add_url_source( $url, $title, $targets );
				break;

			case 'sitemap':
				$url = esc_url_raw( (string) $request->get_param( 'url' ) );
				if ( '' === $url ) {
					return new WP_Error( 'uxstudio_missing_url', __( 'A sitemap URL is required.', 'ux-studio' ), array( 'status' => 400 ) );
				}
				$id = $manager->add_sitemap_source( $url, $title, $targets );
				break;

			default:
				$text = sanitize_textarea_field( (string) $request->get_param( 'text' ) );
				if ( '' === $text ) {
					return new WP_Error( 'uxstudio_missing_text', __( 'Text is required.', 'ux-studio' ), array( 'status' => 400 ) );
				}
				$id = $manager->add_text_source( $text, $title, $targets );
				break;
		}

		ActivityLog::log( 'ai-assistant', 'training_source_create', 'training_source', $id );

		return $this->ok( array( 'id' => $id ) );
	}

	public function delete_training_source( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! ( new TrainingSourceManager() )->delete_source( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Source not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		ActivityLog::log( 'ai-assistant', 'training_source_delete', 'training_source', $id );

		return $this->ok( array( 'deleted' => true ) );
	}

	public function reindex_training_source( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		ActivityLog::log( 'ai-assistant', 'training_source_reindex', 'training_source', $id );

		$result = ( new TrainingSourceManager() )->process_source( $id );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'uxstudio_reindex_failed', (string) ( $result['error'] ?? __( 'Reindexing failed.', 'ux-studio' ) ), array( 'status' => 400 ) );
		}

		return $this->ok( $result );
	}

	/**
	 * Vectorize existing knowledge base / FAQ / product / content data.
	 */
	public function index_existing( WP_REST_Request $request ) {
		$types = (array) $request->get_param( 'types' );
		if ( empty( $types ) ) {
			$types = array( 'knowledge', 'faq', 'product', 'content' );
		}

		$targets = $this->parse_targets( $request );
		$manager = new TrainingSourceManager();
		$results = array();

		foreach ( $types as $type ) {
			switch ( sanitize_text_field( (string) $type ) ) {
				case 'knowledge':
					$results['knowledge'] = $manager->index_knowledge_base( $targets );
					break;
				case 'faq':
					$results['faq'] = $manager->index_faqs( $targets );
					break;
				case 'product':
					$results['product'] = $manager->index_products( $targets );
					break;
				case 'content':
					$results['content'] = $manager->index_content( $targets );
					break;
			}
		}

		ActivityLog::log( 'ai-assistant', 'rag_index_existing' );

		return $this->ok( $results );
	}

	public function rag_stats( WP_REST_Request $request ) {
		return $this->ok(
			array(
				'vectors'   => ( new VectorStore() )->get_stats(),
				'embedding' => EmbeddingFactory::get_status(),
				'content'   => ( new ContentIndexer() )->get_index_stats(),
				'products'  => ( new ProductIndexer() )->get_index_stats(),
			)
		);
	}

	/**
	 * Parse chat targets ('public'/'internal') from a request, defaulting to public only.
	 *
	 * @return array<int, string>
	 */
	private function parse_targets( WP_REST_Request $request ): array {
		$targets = array();

		$raw = $request->get_param( 'targets' );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $t ) {
				$t = sanitize_text_field( (string) $t );
				if ( in_array( $t, VectorStore::TARGETS, true ) ) {
					$targets[] = $t;
				}
			}
		} else {
			if ( $request->get_param( 'use_public' ) ) {
				$targets[] = VectorStore::TARGET_PUBLIC;
			}
			if ( $request->get_param( 'use_internal' ) ) {
				$targets[] = VectorStore::TARGET_INTERNAL;
			}
		}

		return empty( $targets ) ? array( VectorStore::TARGET_PUBLIC ) : array_values( array_unique( $targets ) );
	}
}
