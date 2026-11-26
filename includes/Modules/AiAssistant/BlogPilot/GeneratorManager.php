<?php
/**
 * CRUD for Blog Pilot generators, against the `uxstudio_ai_assistant_blog_generators`
 * and `uxstudio_ai_assistant_blog_generated_posts` tables (schema owned by
 * Module::migrate(), already created for every site with this module enabled -
 * this class does not create tables itself).
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (includes/blog-pilot/GeneratorManager.php), minus the ensureTable() method
 * (schema lives in Module.php in this plugin).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use Ux1\AiCore\Content\ArticleTypes as CoreArticleTypes;

defined( 'ABSPATH' ) || exit;

final class GeneratorManager {

	private string $table;
	private string $posts_table;

	public function __construct() {
		global $wpdb;
		$this->table       = $wpdb->prefix . 'uxstudio_ai_assistant_blog_generators';
		$this->posts_table = $wpdb->prefix . 'uxstudio_ai_assistant_blog_generated_posts';
	}

	/**
	 * Creates a new generator.
	 *
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$this->table,
			array(
				'title'           => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'topics'          => wp_json_encode( self::clean_topics( $data['topics'] ?? array() ) ),
				'article_types'   => wp_json_encode( self::clean_article_types( $data['article_types'] ?? array() ) ),
				'provider'        => sanitize_text_field( (string) ( $data['provider'] ?? '' ) ),
				'model'           => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
				'config'          => wp_json_encode( array_filter( self::sanitize_config( (array) ( $data['config'] ?? array() ) ), static fn( $v ): bool => null !== $v ) ),
				'schedule_type'   => self::clean_schedule_type( (string) ( $data['schedule_type'] ?? 'daily' ) ),
				'schedule_config' => wp_json_encode( $data['schedule_config'] ?? array() ),
				'posts_per_run'   => max( 1, min( 10, (int) ( $data['posts_per_run'] ?? 1 ) ) ),
				'status'          => 'active',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates an existing generator (only the keys present in $data are touched).
	 *
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$update = array( 'updated_at' => current_time( 'mysql' ) );

		if ( isset( $data['title'] ) ) {
			$update['title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['topics'] ) ) {
			$update['topics'] = wp_json_encode( self::clean_topics( $data['topics'] ) );
		}
		if ( isset( $data['article_types'] ) ) {
			$update['article_types'] = wp_json_encode( self::clean_article_types( $data['article_types'] ) );
		}
		if ( isset( $data['provider'] ) ) {
			$update['provider'] = sanitize_text_field( (string) $data['provider'] );
		}
		if ( isset( $data['model'] ) ) {
			$update['model'] = sanitize_text_field( (string) $data['model'] );
		}
		if ( isset( $data['config'] ) ) {
			// Merge into the stored config so keys the form does not send (author, category, ...) survive; null removes a key.
			$existing         = $this->get( $id );
			$merged           = array_merge( $existing ? (array) $existing->config : array(), self::sanitize_config( (array) $data['config'] ) );
			$update['config'] = wp_json_encode( array_filter( $merged, static fn( $v ): bool => null !== $v ) );
		}
		if ( isset( $data['schedule_type'] ) ) {
			$update['schedule_type'] = self::clean_schedule_type( (string) $data['schedule_type'] );
		}
		if ( isset( $data['schedule_config'] ) ) {
			$update['schedule_config'] = wp_json_encode( $data['schedule_config'] );
		}
		if ( isset( $data['posts_per_run'] ) ) {
			$update['posts_per_run'] = max( 1, min( 10, (int) $data['posts_per_run'] ) );
		}
		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_text_field( (string) $data['status'] );
		}

		$result = $wpdb->update( $this->table, $update, array( 'id' => $id ) );
		return false !== $result;
	}

	/**
	 * Deletes a generator and its generated-post log entries.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$wpdb->delete( $this->posts_table, array( 'generator_id' => $id ) );
		$result = $wpdb->delete( $this->table, array( 'id' => $id ) );
		return false !== $result;
	}

	public function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $row ? $this->hydrate_generator( $row ) : null;
	}

	/**
	 * @param array{status?:string,search?:string} $filters
	 * @return array{items:array<int,object>,total:int,page:int,per_page:int}
	 */
	public function get_all( array $filters = array(), int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$where  = '1=1';
		$params = array();

		if ( ! empty( $filters['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $filters['status'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where   .= ' AND title LIKE %s';
			$params[] = $like;
		}

		$count_query = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total       = (int) $wpdb->get_var(
			empty( $params ) ? $count_query : $wpdb->prepare( $count_query, ...$params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$offset = ( $page - 1 ) * $per_page;
		$query  = "SELECT * FROM {$this->table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items'    => array_map( array( $this, 'hydrate_generator' ), $rows ?: array() ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Logs a successfully generated post and bumps the generator's counters.
	 *
	 * @param array{topic?:string,article_type?:string,provider?:string,model?:string,tokens_used?:int,keyword?:string,summary?:string} $meta
	 */
	public function log_generated_post( int $generator_id, int $post_id, array $meta = array() ): void {
		global $wpdb;

		$row = array(
			'generator_id' => $generator_id,
			'post_id'      => $post_id,
			'topic'        => sanitize_text_field( (string) ( $meta['topic'] ?? '' ) ),
			'article_type' => sanitize_text_field( (string) ( $meta['article_type'] ?? '' ) ),
			'provider'     => sanitize_text_field( (string) ( $meta['provider'] ?? '' ) ),
			'model'        => sanitize_text_field( (string) ( $meta['model'] ?? '' ) ),
			'tokens_used'  => (int) ( $meta['tokens_used'] ?? 0 ),
			'keyword'      => sanitize_text_field( (string) ( $meta['keyword'] ?? '' ) ),
			'summary'      => sanitize_textarea_field( (string) ( $meta['summary'] ?? '' ) ),
			'created_at'   => current_time( 'mysql' ),
		);

		if ( false === $wpdb->insert( $this->posts_table, $row ) ) {
			// The keyword/summary columns come with schema version 3; do not lose the log row while that is pending.
			unset( $row['keyword'], $row['summary'] );
			$wpdb->insert( $this->posts_table, $row );
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET total_posts = total_posts + 1, last_run_at = %s, last_error = NULL, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				$generator_id
			)
		);
	}

	public function log_error( int $generator_id, string $error ): void {
		global $wpdb;

		$wpdb->update(
			$this->table,
			array(
				'last_error'  => $error,
				'last_run_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $generator_id )
		);
	}

	/**
	 * @param array<int, string> $post_statuses Only posts whose current WordPress status is one of these (empty = all).
	 * @return array{items:array<int,object>,total:int,page:int,per_page:int}
	 */
	public function get_generated_posts( int $generator_id = 0, int $page = 1, int $per_page = 20, array $post_statuses = array() ): array {
		global $wpdb;

		$where  = '1=1';
		$params = array();

		if ( $generator_id > 0 ) {
			$where   .= ' AND gp.generator_id = %d';
			$params[] = $generator_id;
		}

		$post_statuses = array_values( array_filter( array_map( 'sanitize_key', $post_statuses ) ) );
		if ( $post_statuses ) {
			$where .= ' AND p.post_status IN (' . implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) ) . ')';
			$params = array_merge( $params, $post_statuses );
		}

		$count_query = "SELECT COUNT(*) FROM {$this->posts_table} gp LEFT JOIN {$wpdb->posts} p ON p.ID = gp.post_id WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total       = (int) $wpdb->get_var(
			empty( $params ) ? $count_query : $wpdb->prepare( $count_query, ...$params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$offset = ( $page - 1 ) * $per_page;
		$query  = "SELECT gp.*, p.post_title AS wp_title, p.post_status AS wp_status, p.post_date AS wp_date, g.title AS generator_title
				FROM {$this->posts_table} gp
				LEFT JOIN {$wpdb->posts} p ON p.ID = gp.post_id
				LEFT JOIN {$this->table} g ON g.id = gp.generator_id
				WHERE {$where}
				ORDER BY gp.created_at DESC
				LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items'    => $rows ?: array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * @return array{total_generators:int,active_generators:int,total_posts:int,total_tokens:int,posts_last_7_days:int,last_generation:?string}
	 */
	public function get_stats(): array {
		global $wpdb;

		$total_generators  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$active_generators = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", 'active' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$total_posts  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->posts_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total_tokens = (int) $wpdb->get_var( "SELECT COALESCE(SUM(tokens_used), 0) FROM {$this->posts_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$posts_last_7_days = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->posts_table} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
			)
		);

		$last_generation = $wpdb->get_var( "SELECT MAX(last_run_at) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'total_generators'  => $total_generators,
			'active_generators' => $active_generators,
			'total_posts'       => $total_posts,
			'total_tokens'      => $total_tokens,
			'posts_last_7_days' => $posts_last_7_days,
			'last_generation'   => $last_generation,
		);
	}

	/**
	 * The log row of a generated post (topic, article type, keyword, generator), or null for a post Blog Pilot did not write.
	 */
	public function get_generated_row( int $post_id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->posts_table} WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $row ? $row : null;
	}

	/**
	 * Adds the tokens of a re-written article to its log row.
	 */
	public function add_tokens( int $post_id, int $tokens ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->posts_table} SET tokens_used = tokens_used + %d WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 0, $tokens ),
				$post_id
			)
		);
	}

	/**
	 * Cleans a config coming from the client. Only the keys that are present are returned, so a partial update can be
	 * merged into the stored config; a `publish_plan` of null removes the plan.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public static function sanitize_config( array $config ): array {
		$out = array();

		if ( isset( $config['language'] ) && isset( ArticleTypes::get_languages()[ (string) $config['language'] ] ) ) {
			$out['language'] = (string) $config['language'];
		}
		if ( isset( $config['tone'] ) ) {
			$out['tone'] = mb_substr( sanitize_text_field( (string) $config['tone'] ), 0, 100 );
		}
		if ( isset( $config['length'] ) && isset( ArticleTypes::get_lengths()[ (string) $config['length'] ] ) ) {
			$out['length'] = (string) $config['length'];
		}
		if ( isset( $config['post_status'] ) && in_array( (string) $config['post_status'], PostCreator::STATUSES, true ) ) {
			$out['post_status'] = (string) $config['post_status'];
		}
		foreach ( array( 'category_id', 'author_id' ) as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$out[ $key ] = absint( $config[ $key ] );
			}
		}
		foreach ( array( 'custom_instructions', 'brand_voice' ) as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_textarea_field( (string) $config[ $key ] ), 0, 2000 );
			}
		}
		if ( array_key_exists( 'publish_plan', $config ) ) {
			$out['publish_plan'] = null === $config['publish_plan'] ? null : PublishSlots::normalize( $config['publish_plan'] );
		}
		if ( isset( $config['topic_meta'] ) && is_array( $config['topic_meta'] ) ) {
			$meta = array();
			foreach ( array_slice( $config['topic_meta'], 0, 200, true ) as $title => $item ) {
				$title = mb_substr( sanitize_text_field( (string) $title ), 0, 500 );
				if ( '' === $title || ! is_array( $item ) ) {
					continue;
				}
				$type           = (string) ( $item['article_type'] ?? '' );
				$meta[ $title ] = array(
					'summary'      => mb_substr( sanitize_text_field( (string) ( $item['summary'] ?? '' ) ), 0, 500 ),
					'keyword'      => mb_substr( sanitize_text_field( (string) ( $item['keyword'] ?? '' ) ), 0, 100 ),
					'article_type' => null !== CoreArticleTypes::get( $type ) ? $type : '',
				);
			}
			$out['topic_meta'] = $meta;
		}

		return $out;
	}

	/**
	 * @param mixed $topics
	 * @return array<int, string>
	 */
	private static function clean_topics( $topics ): array {
		$clean = array();
		foreach ( (array) $topics as $topic ) {
			$topic = mb_substr( sanitize_text_field( (string) ( is_array( $topic ) ? ( $topic['title'] ?? '' ) : $topic ) ), 0, 500 );
			if ( '' !== $topic ) {
				$clean[] = $topic;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param mixed $types
	 * @return array<int, string>
	 */
	private static function clean_article_types( $types ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', (array) $types ),
					static fn( string $id ): bool => null !== CoreArticleTypes::get( $id )
				)
			)
		);
	}

	private static function clean_schedule_type( string $type ): string {
		return in_array( $type, array( 'daily', 'weekly', 'monthly' ), true ) ? $type : 'daily';
	}

	/**
	 * Decodes the generator's JSON columns into arrays.
	 */
	private function hydrate_generator( object $row ): object {
		$row->topics          = json_decode( (string) $row->topics, true ) ?: array();
		$row->article_types   = json_decode( (string) $row->article_types, true ) ?: array();
		$row->config          = json_decode( (string) $row->config, true ) ?: array();
		$row->schedule_config = json_decode( (string) $row->schedule_config, true ) ?: array();
		return $row;
	}
}
