<?php
/**
 * CRUD + processing pipeline for uxstudio_ai_assistant_training_sources
 * (web/sitemap/text sources) and vector-indexing of existing KB/FAQ/product/
 * content data.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

use UxStudio\Modules\AiAssistant\KnowledgeBootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy ai-assistant module's rag/TrainingManager. Each
 * source can target the public chat, the internal chat, or both
 * (use_public/use_internal); the embedding for a chunk is computed once and
 * stored once per target, so serving both chats from one source costs no
 * extra embedding API calls. Pending sources are processed in small batches
 * via wp_schedule_single_event() (see process_next_batch(), driven by
 * KnowledgeBootstrap's cron hook), never inline within an HTTP request.
 */
final class TrainingSourceManager {

	private const BATCH_SIZE = 5;

	private ChunkingEngine $chunker;
	private EmbeddingProviderInterface $embedding;
	private VectorStore $store;
	private string $sources_table;

	public function __construct() {
		global $wpdb;
		$this->chunker       = new ChunkingEngine();
		$this->embedding     = EmbeddingFactory::create();
		$this->store         = new VectorStore();
		$this->sources_table = "{$wpdb->prefix}uxstudio_ai_assistant_training_sources";
	}

	/**
	 * @param array<int, string> $targets
	 * @return array{use_public:int,use_internal:int}
	 */
	private function targets_to_flags( array $targets ): array {
		return array(
			'use_public'   => in_array( VectorStore::TARGET_PUBLIC, $targets, true ) ? 1 : 0,
			'use_internal' => in_array( VectorStore::TARGET_INTERNAL, $targets, true ) ? 1 : 0,
		);
	}

	/**
	 * @param array<string, mixed> $source Source row.
	 * @return array<int, string>
	 */
	private function resolve_targets_from_source( array $source ): array {
		$targets = array();
		if ( 1 === (int) ( $source['use_public'] ?? 0 ) ) {
			$targets[] = VectorStore::TARGET_PUBLIC;
		}
		if ( 1 === (int) ( $source['use_internal'] ?? 0 ) ) {
			$targets[] = VectorStore::TARGET_INTERNAL;
		}
		return empty( $targets ) ? array( VectorStore::TARGET_PUBLIC ) : $targets;
	}

	/**
	 * Queue a URL source for crawling + vectorization.
	 *
	 * @param array<int, string> $targets Chat targets ('public'/'internal').
	 */
	public function add_url_source( string $url, string $title = '', array $targets = array( 'public' ) ): int {
		global $wpdb;

		if ( '' === $title ) {
			$parsed = wp_parse_url( $url );
			$title  = ( $parsed['host'] ?? '' ) . ( $parsed['path'] ?? '' );
		}

		$wpdb->insert(
			$this->sources_table,
			array_merge(
				array( 'type' => 'url', 'title' => $title, 'source_url' => $url, 'status' => 'pending', 'created_at' => current_time( 'mysql' ) ),
				$this->targets_to_flags( $targets )
			)
		);

		$source_id = (int) $wpdb->insert_id;
		$this->schedule_processing();

		return $source_id;
	}

	/**
	 * Queue a sitemap source (all its URLs get crawled on processing).
	 *
	 * @param array<int, string> $targets Chat targets.
	 */
	public function add_sitemap_source( string $sitemap_url, string $title = '', array $targets = array( 'public' ) ): int {
		global $wpdb;

		if ( '' === $title ) {
			$title = 'Sitemap: ' . $sitemap_url;
		}

		$wpdb->insert(
			$this->sources_table,
			array_merge(
				array( 'type' => 'sitemap', 'title' => $title, 'source_url' => $sitemap_url, 'status' => 'pending', 'created_at' => current_time( 'mysql' ) ),
				$this->targets_to_flags( $targets )
			)
		);

		$source_id = (int) $wpdb->insert_id;
		$this->schedule_processing();

		return $source_id;
	}

	/**
	 * Queue a raw-text source.
	 *
	 * @param array<int, string> $targets Chat targets.
	 */
	public function add_text_source( string $text, string $title = '', array $targets = array( 'public' ) ): int {
		global $wpdb;

		if ( '' === $title ) {
			$title = mb_substr( wp_strip_all_tags( $text ), 0, 100 ) . '...';
		}

		$wpdb->insert(
			$this->sources_table,
			array_merge(
				array( 'type' => 'text', 'title' => $title, 'content' => $text, 'status' => 'pending', 'created_at' => current_time( 'mysql' ) ),
				$this->targets_to_flags( $targets )
			)
		);

		$source_id = (int) $wpdb->insert_id;
		$this->schedule_processing();

		return $source_id;
	}

	/**
	 * Change a source's chat targets and re-queue it for processing.
	 *
	 * @param array<int, string> $targets Chat targets.
	 */
	public function update_source_targets( int $source_id, array $targets ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->sources_table,
			array_merge( $this->targets_to_flags( $targets ), array( 'status' => 'pending' ) ),
			array( 'id' => $source_id )
		);

		if ( false === $updated ) {
			return false;
		}

		$this->schedule_processing();
		return true;
	}

	/**
	 * Process one source: crawl/load -> chunk -> embed (once) -> store (per target).
	 *
	 * @return array{success:bool,chunks?:int,vectors?:int,error?:string}
	 */
	public function process_source( int $source_id ): array {
		global $wpdb;

		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->sources_table} WHERE id = %d", $source_id ), ARRAY_A );
		if ( ! $source ) {
			return array( 'success' => false, 'error' => __( 'Source not found.', 'ux-studio' ) );
		}

		$targets = $this->resolve_targets_from_source( $source );
		$wpdb->update( $this->sources_table, array( 'status' => 'processing' ), array( 'id' => $source_id ) );

		try {
			$this->store->delete_by_source( null, 'training', $source_id );

			$text     = '';
			$metadata = array( 'source_id' => $source_id );

			switch ( $source['type'] ) {
				case 'url':
					$crawler = new WebCrawler();
					$result  = $crawler->crawl_url( $source['source_url'] );
					if ( ! $result['success'] ) {
						throw new \RuntimeException( $result['error'] ?? __( 'Crawling failed.', 'ux-studio' ) );
					}
					$text               = $result['text'];
					$metadata['url']    = $source['source_url'];
					$metadata['title']  = $result['title'];
					$wpdb->update( $this->sources_table, array( 'content' => $text, 'last_crawled_at' => current_time( 'mysql' ) ), array( 'id' => $source_id ) );
					break;

				case 'sitemap':
					$crawler = new WebCrawler();
					$urls    = $crawler->parse_sitemap( $source['source_url'] );
					if ( empty( $urls ) ) {
						throw new \RuntimeException( __( 'The sitemap contains no URLs.', 'ux-studio' ) );
					}

					$all_text  = array();
					$processed = 0;
					foreach ( array_slice( $urls, 0, 30 ) as $url ) {
						$result = $crawler->crawl_url( $url );
						if ( $result['success'] ) {
							$all_text[] = "=== {$result['title']} ({$url}) ===\n{$result['text']}";
							++$processed;
						}
					}

					$text                        = implode( "\n\n", $all_text );
					$metadata['url']             = $source['source_url'];
					$metadata['urls_processed']  = $processed;
					$wpdb->update( $this->sources_table, array( 'content' => $text, 'last_crawled_at' => current_time( 'mysql' ) ), array( 'id' => $source_id ) );
					break;

				case 'text':
					$text              = $source['content'] ?? '';
					$metadata['title'] = $source['title'];
					break;

				default:
					throw new \RuntimeException( __( 'Unknown source type.', 'ux-studio' ) );
			}

			if ( '' === $text ) {
				throw new \RuntimeException( __( 'Empty content.', 'ux-studio' ) );
			}

			$stats = $this->process_text( $text, 'training', $source_id, $metadata, $targets );

			$wpdb->update(
				$this->sources_table,
				array( 'chunk_count' => $stats['chunks'], 'vector_count' => $stats['vectors'], 'status' => 'completed', 'error_message' => null ),
				array( 'id' => $source_id )
			);

			return array( 'success' => true, 'chunks' => $stats['chunks'], 'vectors' => $stats['vectors'] );
		} catch ( \Throwable $e ) {
			$wpdb->update( $this->sources_table, array( 'status' => 'error', 'error_message' => $e->getMessage() ), array( 'id' => $source_id ) );
			return array( 'success' => false, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Vectorize all knowledge base chunks.
	 *
	 * @param array<int, string> $targets Chat targets.
	 * @return array{success:bool,chunks?:int,vectors?:int,error?:string}
	 */
	public function index_knowledge_base( array $targets = array( 'public' ) ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_knowledge";

		$this->store->delete_by_source( null, 'knowledge' );
		$items = $wpdb->get_results( "SELECT id, title, content FROM {$table} WHERE content != ''", ARRAY_A );
		if ( empty( $items ) ) {
			return array( 'success' => true, 'chunks' => 0, 'vectors' => 0 );
		}

		return $this->index_items(
			$items,
			'knowledge',
			$targets,
			static fn( array $item ): string => trim( ( $item['title'] ?? '' ) . "\n\n" . ( $item['content'] ?? '' ) ),
			static fn( array $item ): array => array( 'title' => $item['title'] ?? '' )
		);
	}

	/**
	 * Vectorize all FAQs.
	 *
	 * @param array<int, string> $targets Chat targets.
	 * @return array{success:bool,chunks?:int,vectors?:int,error?:string}
	 */
	public function index_faqs( array $targets = array( 'public' ) ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_faqs";

		$this->store->delete_by_source( null, 'faq' );
		$items = $wpdb->get_results( "SELECT id, question, answer FROM {$table}", ARRAY_A );
		if ( empty( $items ) ) {
			return array( 'success' => true, 'chunks' => 0, 'vectors' => 0 );
		}

		return $this->index_items(
			$items,
			'faq',
			$targets,
			static fn( array $item ): string => "Question: {$item['question']}\nAnswer: {$item['answer']}",
			static fn( array $item ): array => array( 'question' => $item['question'] )
		);
	}

	/**
	 * Vectorize the indexed WooCommerce product catalog.
	 *
	 * @param array<int, string> $targets Chat targets.
	 * @return array{success:bool,chunks?:int,vectors?:int,error?:string}
	 */
	public function index_products( array $targets = array( 'public' ) ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_product_index";

		$this->store->delete_by_source( null, 'product' );
		$items = $wpdb->get_results( "SELECT product_id, name, description_text, categories, attributes, price, permalink FROM {$table}", ARRAY_A );
		if ( empty( $items ) ) {
			return array( 'success' => true, 'chunks' => 0, 'vectors' => 0 );
		}

		return $this->index_items(
			array_map( static fn( array $i ): array => array_merge( $i, array( 'id' => $i['product_id'] ) ), $items ),
			'product',
			$targets,
			static function ( array $item ): string {
				$parts = array( $item['name'] );
				if ( ! empty( $item['description_text'] ) ) {
					$parts[] = $item['description_text'];
				}
				if ( ! empty( $item['categories'] ) ) {
					$parts[] = "Categories: {$item['categories']}";
				}
				if ( ! empty( $item['attributes'] ) ) {
					$parts[] = "Attributes: {$item['attributes']}";
				}
				if ( ! empty( $item['price'] ) ) {
					$parts[] = "Price: {$item['price']}";
				}
				return implode( "\n", $parts );
			},
			static fn( array $item ): array => array( 'title' => $item['name'], 'url' => $item['permalink'] ?? '' )
		);
	}

	/**
	 * Vectorize the indexed post/page content.
	 *
	 * @param array<int, string> $targets Chat targets.
	 * @return array{success:bool,chunks?:int,vectors?:int,error?:string}
	 */
	public function index_content( array $targets = array( 'public' ) ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_content_index";

		$this->store->delete_by_source( null, 'content' );
		$items = $wpdb->get_results( "SELECT post_id, post_title, content_text, permalink FROM {$table}", ARRAY_A );
		if ( empty( $items ) ) {
			return array( 'success' => true, 'chunks' => 0, 'vectors' => 0 );
		}

		return $this->index_items(
			array_map( static fn( array $i ): array => array_merge( $i, array( 'id' => $i['post_id'] ) ), $items ),
			'content',
			$targets,
			static fn( array $item ): string => $item['post_title'] . "\n\n" . ( $item['content_text'] ?? '' ),
			static fn( array $item ): array => array( 'title' => $item['post_title'], 'url' => $item['permalink'] ?? '' )
		);
	}

	/**
	 * Shared "iterate rows -> process_text() -> accumulate stats" loop for the index_*() methods.
	 *
	 * @param array<int, array<string, mixed>> $items         Rows (each must include an 'id' key).
	 * @param array<int, string>                $targets       Chat targets.
	 * @param callable                          $text_builder  fn(array $item): string.
	 * @param callable                          $meta_builder  fn(array $item): array.
	 * @return array{success:bool,chunks:int,vectors:int}
	 */
	private function index_items( array $items, string $source_type, array $targets, callable $text_builder, callable $meta_builder ): array {
		$total_chunks  = 0;
		$total_vectors = 0;

		foreach ( $items as $item ) {
			$stats = $this->process_text( $text_builder( $item ), $source_type, (int) $item['id'], $meta_builder( $item ), $targets );
			$total_chunks  += $stats['chunks'];
			$total_vectors += $stats['vectors'];
		}

		return array( 'success' => true, 'chunks' => $total_chunks, 'vectors' => $total_vectors );
	}

	/**
	 * WP-Cron callback: process the next batch of pending sources, rescheduling if more remain.
	 */
	public function process_next_batch(): void {
		global $wpdb;

		$pending = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM {$this->sources_table} WHERE status = %s ORDER BY created_at ASC LIMIT %d", 'pending', self::BATCH_SIZE ),
			ARRAY_A
		);

		foreach ( (array) $pending as $row ) {
			$this->process_source( (int) $row['id'] );
		}

		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->sources_table} WHERE status = %s", 'pending' ) );
		if ( $remaining > 0 ) {
			$this->schedule_processing();
		}
	}

	/**
	 * Paginated source list for the admin UI.
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int}
	 */
	public function get_sources( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->sources_table}" );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, title, source_url, chunk_count, vector_count, use_public, use_internal, status, error_message, last_crawled_at, created_at
				 FROM {$this->sources_table}
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array( 'items' => is_array( $items ) ? $items : array(), 'total' => $total, 'pages' => (int) ceil( $total / max( 1, $per_page ) ) );
	}

	/**
	 * Delete a source and its vectors (across all targets).
	 */
	public function delete_source( int $source_id ): bool {
		global $wpdb;

		$this->store->delete_by_source( null, 'training', $source_id );
		return (bool) $wpdb->delete( $this->sources_table, array( 'id' => $source_id ) );
	}

	/**
	 * Core pipeline: text -> chunk -> embed (once per chunk) -> store (once per target).
	 *
	 * @param array<string, mixed> $metadata Metadata attached to every stored chunk.
	 * @param array<int, string>   $targets  Chat targets.
	 * @return array{chunks:int,vectors:int}
	 */
	private function process_text( string $text, string $source_type, int $source_id, array $metadata, array $targets ): array {
		$chunks = $this->chunker->chunk( $text );
		if ( empty( $chunks ) ) {
			return array( 'chunks' => 0, 'vectors' => 0 );
		}

		$targets = array_values( array_unique( array_map( static fn( string $t ): string => VectorStore::normalize_target( $t ), $targets ) ) );
		if ( empty( $targets ) ) {
			$targets = array( VectorStore::TARGET_PUBLIC );
		}

		$texts      = array_map( static fn( array $c ): string => $c['text'], $chunks );
		$embeddings = $this->embedding->embed_batch( $texts );

		if ( count( $embeddings ) !== count( $chunks ) ) {
			throw new \RuntimeException( __( 'Embedding count does not match chunk count.', 'ux-studio' ) );
		}

		$items = array();
		foreach ( $chunks as $i => $chunk ) {
			foreach ( $targets as $target ) {
				$items[] = array(
					'chat_target' => $target,
					'source_type' => $source_type,
					'source_id'   => $source_id,
					'chunk_text'  => $chunk['text'],
					'chunk_index' => $chunk['index'],
					'vector'      => $embeddings[ $i ],
					'metadata'    => $metadata,
				);
			}
		}

		$inserted = $this->store->insert_batch( $items, $this->embedding->get_model_name() );

		return array( 'chunks' => count( $chunks ), 'vectors' => $inserted );
	}

	/**
	 * Schedule the next pending-sources batch if not already scheduled.
	 */
	private function schedule_processing(): void {
		if ( ! wp_next_scheduled( KnowledgeBootstrap::CRON_TRAINING ) ) {
			wp_schedule_single_event( time() + 30, KnowledgeBootstrap::CRON_TRAINING );
		}
	}
}
