<?php
/**
 * Notice Board module - public documents board with categories and email
 * subscriptions.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\NoticeBoard;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy notice-board module. Documents always
 * reference an existing WP media library attachment (attachment_id) - there
 * is no custom upload handling. Subscriptions use a double opt-in flow
 * (confirm_token / unsubscribe_token) with the confirm/unsubscribe endpoints
 * public and token-gated, matching the DownloadFiles token pattern.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		DB::ensure_module_tables(
			'notice-board',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_notice_board (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						title VARCHAR(255) NOT NULL DEFAULT '',
						category VARCHAR(191) NOT NULL DEFAULT '',
						attachment_id BIGINT UNSIGNED NULL,
						PRIMARY KEY  (id),
						KEY category (category),
						KEY created_at (created_at)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_notice_board_categories (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						name VARCHAR(191) NOT NULL DEFAULT '',
						slug VARCHAR(191) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						UNIQUE KEY slug (slug)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_notice_board_subscriptions (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						email VARCHAR(255) NOT NULL DEFAULT '',
						confirmed TINYINT(1) NOT NULL DEFAULT 0,
						confirm_token VARCHAR(64) NOT NULL DEFAULT '',
						unsubscribe_token VARCHAR(64) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						UNIQUE KEY email (email),
						KEY confirm_token (confirm_token),
						KEY unsubscribe_token (unsubscribe_token)
					) {$charset};"
				);
			}
		);
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
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'retention_days',
				'type'    => 'number',
				'label'   => __( 'Retention (days)', 'ux-studio' ),
				'help'    => __( 'Informational only: how long documents are intended to stay published. No automatic deletion is performed.', 'ux-studio' ),
				'default' => 365,
			),
			array(
				'key'     => 'from_email',
				'type'    => 'text',
				'label'   => __( 'From email', 'ux-studio' ),
				'help'    => __( 'Sender address used for subscription confirmation emails. Defaults to the site admin email.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	// =====================================================================
	// Documents
	// =====================================================================

	/**
	 * All documents, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_documents(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, title, category, attachment_id FROM {$wpdb->prefix}uxstudio_notice_board ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_document' ), $rows );
	}

	/**
	 * One document by id.
	 *
	 * @param int $id Row id.
	 */
	public function get_document( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, created_at, title, category, attachment_id FROM {$wpdb->prefix}uxstudio_notice_board WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_document( $row ) : null;
	}

	/**
	 * Create a document. attachment_id must reference an existing media
	 * library attachment; it is never a raw upload from client input.
	 *
	 * @param array $data { title: string, category?: string, attachment_id?: int }.
	 */
	public function create_document( array $data ): array {
		global $wpdb;

		$attachment_id = isset( $data['attachment_id'] ) ? absint( $data['attachment_id'] ) : 0;
		if ( $attachment_id && 'attachment' !== get_post_type( $attachment_id ) ) {
			$attachment_id = 0;
		}

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_notice_board",
			array(
				'created_at'    => current_time( 'mysql' ),
				'title'         => mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 255 ),
				'category'      => sanitize_title( (string) ( $data['category'] ?? '' ) ),
				'attachment_id' => $attachment_id ? $attachment_id : null,
			),
			array( '%s', '%s', '%s', $attachment_id ? '%d' : null )
		);

		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'notice-board', 'create', 'document', $id );

		return (array) $this->get_document( $id );
	}

	/**
	 * Delete a document.
	 *
	 * @param int $id Row id.
	 */
	public function delete_document( int $id ): bool {
		global $wpdb;

		$existing = $this->get_document( $id );
		if ( null === $existing ) {
			return false;
		}

		$deleted = $wpdb->delete( "{$wpdb->prefix}uxstudio_notice_board", array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			ActivityLog::log( 'notice-board', 'delete', 'document', $id );
		}

		return (bool) $deleted;
	}

	/**
	 * Normalize a document row for REST output.
	 *
	 * @param array $row Raw row.
	 */
	private function format_document( array $row ): array {
		$attachment_id = null === $row['attachment_id'] ? 0 : (int) $row['attachment_id'];
		return array(
			'id'            => (int) $row['id'],
			'created_at'    => $row['created_at'],
			'title'         => $row['title'],
			'category'      => $row['category'],
			'attachment_id' => $attachment_id,
			'attachment_url' => $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '',
		);
	}

	// =====================================================================
	// Categories
	// =====================================================================

	/**
	 * All categories.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_categories(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, name, slug FROM {$wpdb->prefix}uxstudio_notice_board_categories ORDER BY name ASC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static fn( array $row ): array => array(
				'id'   => (int) $row['id'],
				'name' => $row['name'],
				'slug' => $row['slug'],
			),
			$rows
		);
	}

	/**
	 * Create a category (name -> slug), returns null when the slug already exists.
	 *
	 * @param string $name Category name.
	 */
	public function create_category( string $name ): ?array {
		global $wpdb;

		$name = sanitize_text_field( $name );
		$slug = sanitize_title( $name );
		if ( '' === $name || '' === $slug ) {
			return null;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}uxstudio_notice_board_categories WHERE slug = %s", $slug )
		);
		if ( $exists ) {
			return null;
		}

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_notice_board_categories",
			array(
				'name' => mb_substr( $name, 0, 191 ),
				'slug' => mb_substr( $slug, 0, 191 ),
			),
			array( '%s', '%s' )
		);

		return array(
			'id'   => (int) $wpdb->insert_id,
			'name' => $name,
			'slug' => $slug,
		);
	}

	/**
	 * Delete a category by id.
	 *
	 * @param int $id Category id.
	 */
	public function delete_category( int $id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete( "{$wpdb->prefix}uxstudio_notice_board_categories", array( 'id' => $id ), array( '%d' ) );
		return (bool) $deleted;
	}

	// =====================================================================
	// Subscriptions
	// =====================================================================

	/**
	 * All subscriptions, newest first (admin listing).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_subscriptions(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, email, confirmed FROM {$wpdb->prefix}uxstudio_notice_board_subscriptions ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'created_at' => $row['created_at'],
				'email'      => $row['email'],
				'confirmed'  => (bool) $row['confirmed'],
			),
			$rows
		);
	}

	/**
	 * Subscribe an email address (double opt-in). Rate limiting is handled by
	 * the REST controller before this is called. If the address already
	 * exists, re-sends the confirmation email (unconfirmed) or is a no-op
	 * (already confirmed) rather than erroring, so the public endpoint never
	 * leaks whether an address is already subscribed.
	 *
	 * @param string $email Raw email address.
	 */
	public function subscribe( string $email ): bool {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, confirmed, confirm_token FROM {$wpdb->prefix}uxstudio_notice_board_subscriptions WHERE email = %s", $email ),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			if ( (int) $existing['confirmed'] ) {
				return true;
			}
			$this->send_confirm_email( $email, (string) $existing['confirm_token'] );
			return true;
		}

		$confirm_token     = wp_generate_password( 32, false, false );
		$unsubscribe_token = wp_generate_password( 32, false, false );

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_notice_board_subscriptions",
			array(
				'created_at'        => current_time( 'mysql' ),
				'email'             => $email,
				'confirmed'         => 0,
				'confirm_token'     => $confirm_token,
				'unsubscribe_token' => $unsubscribe_token,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		$this->send_confirm_email( $email, $confirm_token );

		return true;
	}

	/**
	 * Confirm a subscription by token.
	 *
	 * @param string $token Confirm token.
	 */
	public function confirm( string $token ): bool {
		global $wpdb;
		if ( '' === $token ) {
			return false;
		}
		$updated = $wpdb->update(
			"{$wpdb->prefix}uxstudio_notice_board_subscriptions",
			array( 'confirmed' => 1 ),
			array( 'confirm_token' => $token ),
			array( '%d' ),
			array( '%s' )
		);
		return (bool) $updated;
	}

	/**
	 * Unsubscribe by token.
	 *
	 * @param string $token Unsubscribe token.
	 */
	public function unsubscribe( string $token ): bool {
		global $wpdb;
		if ( '' === $token ) {
			return false;
		}
		$deleted = $wpdb->delete(
			"{$wpdb->prefix}uxstudio_notice_board_subscriptions",
			array( 'unsubscribe_token' => $token ),
			array( '%s' )
		);
		return (bool) $deleted;
	}

	/**
	 * Send the double opt-in confirmation email.
	 *
	 * @param string $email         Recipient.
	 * @param string $confirm_token Confirm token.
	 */
	private function send_confirm_email( string $email, string $confirm_token ): void {
		$from_email = (string) $this->settings->get( 'from_email', '' );
		$headers    = array();
		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = 'From: ' . get_bloginfo( 'name' ) . ' <' . $from_email . '>';
		}

		$confirm_url = rest_url( 'uxstudio/v1/notice-board/confirm/' . rawurlencode( $confirm_token ) );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Confirm your notice board subscription', 'ux-studio' ),
			get_bloginfo( 'name' )
		);
		$body = sprintf(
			/* translators: %s: confirmation URL */
			__( "Please confirm your subscription to the notice board by visiting:\n\n%s", 'ux-studio' ),
			$confirm_url
		);

		wp_mail( $email, $subject, $body, $headers );
	}
}
