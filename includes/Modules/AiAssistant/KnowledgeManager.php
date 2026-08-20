<?php
/**
 * Knowledge base: manual entries + uploaded documents, chunked into the
 * uxstudio_ai_assistant_knowledge table and searched via FULLTEXT.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy ai-assistant module's KnowledgeManager. Documents are
 * uploaded through wp_handle_upload() and registered as real WP media library
 * attachments (see upload_document()) rather than written to an arbitrary
 * path - this keeps them visible/manageable from Media Library and reuses
 * WordPress's own MIME sniffing.
 */
final class KnowledgeManager {

	private const ALLOWED_EXTENSIONS = array( 'pdf', 'docx', 'txt', 'md' );
	private const MAX_FILE_SIZE      = 10 * 1024 * 1024; // 10 MB.

	/**
	 * Search knowledge chunks by FULLTEXT relevance, filtered to the chat
	 * target (public/internal). Called by the chat engine - the return shape
	 * (associative arrays keyed like the table columns) is part of the
	 * cross-module contract and must not change without updating the caller.
	 *
	 * @param string $query       Search query.
	 * @param int    $limit       Max results.
	 * @param string $chat_target 'public' or 'internal'.
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( string $query, int $limit, string $chat_target ): array {
		global $wpdb;

		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		$flag  = 'internal' === $chat_target ? 'use_internal' : 'use_public';
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_knowledge";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $flag is one of two hardcoded column names, never user input.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, document_id, title, content, chunk_index, metadata,
						MATCH(content, title) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance
				 FROM {$table}
				 WHERE {$flag} = 1 AND MATCH(content, title) AGAINST(%s IN NATURAL LANGUAGE MODE)
				 ORDER BY relevance DESC
				 LIMIT %d",
				$query,
				$query,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Add a manual knowledge entry (document_id IS NULL).
	 *
	 * @param string $title        Title.
	 * @param string $content      Content.
	 * @param bool   $use_public   Visible to the public chat.
	 * @param bool   $use_internal Visible to the internal chat.
	 */
	public function add_manual_entry( string $title, string $content, bool $use_public = true, bool $use_internal = false ): int {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_ai_assistant_knowledge",
			array(
				'document_id'  => null,
				'title'        => mb_substr( $title, 0, 500 ),
				'content'      => $content,
				'chunk_index'  => 0,
				'use_public'   => $use_public ? 1 : 0,
				'use_internal' => $use_internal ? 1 : 0,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( null, '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a manual entry's title/content.
	 *
	 * @param int    $id      Entry id.
	 * @param string $title   New title.
	 * @param string $content New content.
	 */
	public function update_manual_entry( int $id, string $title, string $content ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			"{$wpdb->prefix}uxstudio_ai_assistant_knowledge",
			array(
				'title'      => mb_substr( $title, 0, 500 ),
				'content'    => $content,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id, 'document_id' => null ),
			array( '%s', '%s', '%s' ),
			array( '%d', null )
		);

		return false !== $updated;
	}

	/**
	 * Delete a manual entry (never a document-derived chunk).
	 *
	 * @param int $id Entry id.
	 */
	public function delete_manual_entry( int $id ): bool {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}uxstudio_ai_assistant_knowledge WHERE id = %d AND document_id IS NULL",
				$id
			)
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * All manual entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_manual_entries(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, title, content, use_public, use_internal, created_at, updated_at
			 FROM {$wpdb->prefix}uxstudio_ai_assistant_knowledge
			 WHERE document_id IS NULL
			 ORDER BY created_at DESC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Set the use_public/use_internal flags of a manual entry.
	 *
	 * @param int  $id           Entry id.
	 * @param bool $use_public   Public chat target.
	 * @param bool $use_internal Internal chat target.
	 */
	public function set_manual_entry_targets( int $id, bool $use_public, bool $use_internal ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			"{$wpdb->prefix}uxstudio_ai_assistant_knowledge",
			array( 'use_public' => $use_public ? 1 : 0, 'use_internal' => $use_internal ? 1 : 0 ),
			array( 'id' => $id, 'document_id' => null ),
			array( '%d', '%d' ),
			array( '%d', null )
		);

		return false !== $updated;
	}

	/**
	 * Handle a document upload ($_FILES entry), register it in the WP media
	 * library and chunk its extracted text into knowledge rows.
	 *
	 * @param array $file         $_FILES['file'] entry.
	 * @param int   $author_id    Uploading user id.
	 * @param bool  $use_public   Public chat target.
	 * @param bool  $use_internal Internal chat target.
	 * @return array<string, mixed> The stored document row.
	 *
	 * @throws \RuntimeException On validation/processing failure.
	 */
	public function upload_document( array $file, int $author_id, bool $use_public = true, bool $use_internal = false ): array {
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			throw new \RuntimeException( __( 'Upload failed.', 'ux-studio' ) );
		}

		if ( (int) $file['size'] > self::MAX_FILE_SIZE ) {
			throw new \RuntimeException( __( 'The file is too large. Maximum size is 10 MB.', 'ux-studio' ) );
		}

		$file_name = sanitize_file_name( (string) $file['name'] );
		$extension = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: comma-separated list of allowed extensions. */
					__( 'Unsupported file type. Allowed types: %s', 'ux-studio' ),
					implode( ', ', self::ALLOWED_EXTENSIONS )
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		add_filter( 'upload_mimes', array( __CLASS__, 'filter_upload_mimes' ) );
		$moved = wp_handle_upload( $file, array( 'test_form' => false ) );
		remove_filter( 'upload_mimes', array( __CLASS__, 'filter_upload_mimes' ) );

		if ( isset( $moved['error'] ) ) {
			throw new \RuntimeException( (string) $moved['error'] );
		}

		$title         = pathinfo( $file_name, PATHINFO_FILENAME );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => $title,
				'post_status'    => 'inherit',
				'post_author'    => $author_id,
			),
			$moved['file']
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			@unlink( $moved['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort cleanup.
			throw new \RuntimeException( __( 'Could not register the file in the media library.', 'ux-studio' ) );
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $moved['file'] ) );

		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_ai_assistant_documents",
			array(
				'title'        => $title,
				'file_name'    => basename( $moved['file'] ),
				'file_path'    => $moved['file'],
				'file_type'    => $extension,
				'file_size'    => (int) $file['size'],
				'chunk_count'  => 0,
				'use_public'   => $use_public ? 1 : 0,
				'use_internal' => $use_internal ? 1 : 0,
				'status'       => 'processing',
				'author_id'    => $author_id,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
		);

		$document_id = (int) $wpdb->insert_id;
		if ( ! $document_id ) {
			throw new \RuntimeException( __( 'Could not create the document record.', 'ux-studio' ) );
		}

		try {
			$text = DocumentParser::parse( $moved['file'], $extension );
			if ( '' === trim( $text ) ) {
				throw new \RuntimeException( __( 'No text could be extracted from the file.', 'ux-studio' ) );
			}

			$chunks = DocumentParser::chunk( $text );
			if ( empty( $chunks ) ) {
				throw new \RuntimeException( __( 'The document contains no usable text.', 'ux-studio' ) );
			}

			foreach ( $chunks as $index => $chunk_content ) {
				$wpdb->insert(
					"{$wpdb->prefix}uxstudio_ai_assistant_knowledge",
					array(
						'document_id'  => $document_id,
						'title'        => $title,
						'content'      => $chunk_content,
						'chunk_index'  => $index,
						'use_public'   => $use_public ? 1 : 0,
						'use_internal' => $use_internal ? 1 : 0,
						'created_at'   => current_time( 'mysql' ),
						'updated_at'   => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
				);
			}

			$wpdb->update(
				"{$wpdb->prefix}uxstudio_ai_assistant_documents",
				array( 'status' => 'ready', 'chunk_count' => count( $chunks ) ),
				array( 'id' => $document_id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		} catch ( \Throwable $e ) {
			$wpdb->update(
				"{$wpdb->prefix}uxstudio_ai_assistant_documents",
				array( 'status' => 'error', 'error_message' => $e->getMessage() ),
				array( 'id' => $document_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		return (array) $this->get_document( $document_id );
	}

	/**
	 * Restrict wp_handle_upload() to the knowledge base whitelist.
	 *
	 * @param array $mimes Default allowed mimes.
	 */
	public static function filter_upload_mimes( array $mimes ): array {
		return array(
			'pdf'  => 'application/pdf',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'txt'  => 'text/plain',
			'md'   => 'text/plain',
		);
	}

	/**
	 * Delete a document, its chunks, and the underlying media attachment.
	 *
	 * @param int $id Document id.
	 */
	public function delete_document( int $id ): bool {
		global $wpdb;

		$document = $this->get_document( $id );
		if ( null === $document ) {
			return false;
		}

		$attachment_id = attachment_url_to_postid( $document['file_path'] );
		if ( ! $attachment_id && ! empty( $document['file_path'] ) ) {
			// file_path is a filesystem path, not a URL; look up by path instead.
			$attachment_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s", basename( (string) $document['file_path'] ) )
			);
		}
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		} elseif ( ! empty( $document['file_path'] ) && file_exists( $document['file_path'] ) ) {
			@unlink( $document['file_path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort cleanup.
		}

		$wpdb->delete( "{$wpdb->prefix}uxstudio_ai_assistant_knowledge", array( 'document_id' => $id ), array( '%d' ) );
		$deleted = $wpdb->delete( "{$wpdb->prefix}uxstudio_ai_assistant_documents", array( 'id' => $id ), array( '%d' ) );

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Set the use_public/use_internal flags of a document and propagate to its chunks.
	 *
	 * @param int  $id           Document id.
	 * @param bool $use_public   Public chat target.
	 * @param bool $use_internal Internal chat target.
	 */
	public function set_document_targets( int $id, bool $use_public, bool $use_internal ): bool {
		global $wpdb;

		$flags   = array( 'use_public' => $use_public ? 1 : 0, 'use_internal' => $use_internal ? 1 : 0 );
		$updated = $wpdb->update( "{$wpdb->prefix}uxstudio_ai_assistant_documents", $flags, array( 'id' => $id ), array( '%d', '%d' ), array( '%d' ) );
		$wpdb->update( "{$wpdb->prefix}uxstudio_ai_assistant_knowledge", $flags, array( 'document_id' => $id ), array( '%d', '%d' ), array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * All documents, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_documents(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, title, file_name, file_type, file_size, chunk_count, use_public, use_internal, status, error_message, author_id, created_at
			 FROM {$wpdb->prefix}uxstudio_ai_assistant_documents
			 ORDER BY created_at DESC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One document by id.
	 *
	 * @param int $id Document id.
	 */
	public function get_document( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, file_name, file_path, file_type, file_size, chunk_count, use_public, use_internal, status, error_message, author_id, created_at
				 FROM {$wpdb->prefix}uxstudio_ai_assistant_documents WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Knowledge chunks belonging to a document, in order.
	 *
	 * @param int $document_id Document id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_chunks( int $document_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, document_id, title, content, chunk_index, metadata, created_at, updated_at
				 FROM {$wpdb->prefix}uxstudio_ai_assistant_knowledge
				 WHERE document_id = %d
				 ORDER BY chunk_index ASC",
				$document_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
