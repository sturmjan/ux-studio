<?php
/**
 * Notice Board module - public notice board (documents/announcements) with a
 * body, publish/expiry dates + auto-archiving, multiple media-library
 * attachments, categories, per-category email subscriptions (double opt-in),
 * notification emails on publish, an RSS feed and a frontend shortcode.
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
 * Ported/redesigned from the legacy notice-board module. Attachments always
 * reference existing WP media library attachments (their IDs) - there is no
 * custom upload handling, so nothing untrusted is ever written to disk.
 *
 * Storage: three custom tables (module DB version 2), created lazily via
 * DB::ensure_module_tables():
 *   - {prefix}uxstudio_notice_board             (notices)
 *   - {prefix}uxstudio_notice_board_categories  (categories)
 *   - {prefix}uxstudio_notice_board_subscriptions (subscribers)
 *
 * Subscriptions carry a JSON list of category slugs the address wants (empty =
 * all categories), so a single row per email supports per-category delivery
 * without a unique-key migration on the existing table.
 */
final class Module extends BaseModule {

	private const FEED_SLUG   = 'uxstudio-notice-board';
	private const QUERY_VAR   = 'uxs_nb_cat';

	/** Legacy names kept for content / feed readers already in the wild. */
	private const FEED_SLUG_LEGACY      = 'uredni-nastenka';
	private const SHORTCODE_LEGACY      = 'uredni_nastenka';
	private const SHORTCODE             = 'uxstudio_notice_board';
	private const DETAIL_QUERY_ARG      = 'dokument';
	private const LEGACY_REST_NAMESPACE = 'wpextended/v1';
	private const DB_VERSION  = 3;

	/**
	 * Minimum gap (hours) between two confirmation e-mails to one address, so
	 * the public subscribe form can't be used to mail-bomb someone.
	 */
	private const CONFIRM_RESEND_HOURS = 6;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables( 'notice-board', self::DB_VERSION, array( $this, 'migrate' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'init', array( $this, 'register_feed' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_shortcode( self::SHORTCODE_LEGACY, array( $this, 'render_shortcode' ) );

		// Auto-archive expired notices once per day (throttled by a transient).
		if ( ! get_transient( 'uxstudio_nb_archive_check' ) ) {
			$this->run_auto_archive();
			$this->run_retention_cleanup();
			set_transient( 'uxstudio_nb_archive_check', 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * dbDelta migrator for this module's tables. dbDelta reconciles the desired
	 * schema against what exists, adding any missing columns/indexes - so this
	 * covers both a fresh install and an upgrade from the v1 schema.
	 *
	 * @param int $from Previously installed module schema version.
	 */
	public function migrate( int $from ): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_notice_board (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				title VARCHAR(255) NOT NULL DEFAULT '',
				body LONGTEXT NULL,
				category VARCHAR(191) NOT NULL DEFAULT '',
				reference VARCHAR(191) NOT NULL DEFAULT '',
				attachments LONGTEXT NULL,
				attachment_id BIGINT UNSIGNED NULL,
				publish_date DATE NULL,
				expiry_date DATE NULL,
				is_archived TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY category (category),
				KEY is_archived (is_archived),
				KEY publish_date (publish_date)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_notice_board_categories (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(191) NOT NULL DEFAULT '',
				slug VARCHAR(191) NOT NULL DEFAULT '',
				sort_order INT NOT NULL DEFAULT 0,
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
				categories LONGTEXT NULL,
				pending_categories LONGTEXT NULL,
				confirm_sent_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY confirm_token (confirm_token),
				KEY unsubscribe_token (unsubscribe_token)
			) {$charset};"
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
				'key'     => 'notify_on_publish',
				'type'    => 'toggle',
				'label'   => __( 'Email subscribers when a notice is published', 'ux-studio' ),
				'help'    => __( 'Sends a notification to confirmed subscribers of the matching category (and to "all categories" subscribers).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'from_email',
				'show_if' => array( 'key' => 'notify_on_publish', 'truthy' => true ),
				'type'    => 'text',
				'label'   => __( 'From email', 'ux-studio' ),
				'help'    => __( 'Sender address for subscription and notification emails. Defaults to the site admin email.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'per_page',
				'type'    => 'number',
				'label'   => __( 'Notices per page (shortcode / feed)', 'ux-studio' ),
				'default' => 20,
			),
			array(
				'key'     => 'retention_days',
				'type'    => 'number',
				'label'   => __( 'Delete archived notices after (days)', 'ux-studio' ),
				'help'    => __( 'Archived notices whose expiry date is older than this are permanently deleted. 0 = keep forever.', 'ux-studio' ),
				'default' => 0,
			),
		);
	}

	/* ================================================================== *
	 * Notices
	 * ================================================================== */

	/**
	 * All notices for the admin listing, newest first.
	 *
	 * @param bool $include_archived Include archived notices.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_notices( bool $include_archived = true ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_notice_board";
		$sql   = "SELECT * FROM {$table}";
		if ( ! $include_archived ) {
			$sql .= ' WHERE is_archived = 0';
		}
		$sql .= ' ORDER BY is_archived ASC, publish_date DESC, id DESC';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_notice' ), $rows );
	}

	/**
	 * One notice by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function get_notice( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}uxstudio_notice_board WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_notice( $row ) : null;
	}

	/**
	 * Create a notice.
	 *
	 * @param array $data Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_notice( array $data ) {
		global $wpdb;

		$title = mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 255 );
		if ( '' === $title ) {
			return new WP_Error( 'uxstudio_nb_invalid_title', __( 'A title is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$publish_date = $this->sanitize_date( $data['publish_date'] ?? '' );
		if ( null === $publish_date ) {
			$publish_date = current_time( 'Y-m-d' );
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_notice_board",
			array(
				'created_at'   => $now,
				'updated_at'   => $now,
				'title'        => $title,
				'body'         => wp_kses_post( (string) ( $data['body'] ?? '' ) ),
				'category'     => sanitize_title( (string) ( $data['category'] ?? '' ) ),
				'reference'    => mb_substr( sanitize_text_field( (string) ( $data['reference'] ?? '' ) ), 0, 191 ),
				'attachments'  => wp_json_encode( $this->sanitize_attachment_ids( $data['attachments'] ?? array() ) ),
				'publish_date' => $publish_date,
				'expiry_date'  => $this->sanitize_date( $data['expiry_date'] ?? '' ),
				'is_archived'  => ! empty( $data['is_archived'] ) ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'notice-board', 'create', 'notice', $id, array( 'title' => $title ) );

		$notice = (array) $this->get_notice( $id );
		$this->maybe_notify_subscribers( $notice );

		return $notice;
	}

	/**
	 * Update a notice (partial: only provided keys are written).
	 *
	 * @param int   $id   Row id.
	 * @param array $data Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_notice( int $id, array $data ) {
		global $wpdb;

		$existing = $this->get_notice( $id );
		if ( null === $existing ) {
			return new WP_Error( 'uxstudio_nb_not_found', __( 'Notice not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$update = array();
		$format = array();

		if ( array_key_exists( 'title', $data ) ) {
			$title = mb_substr( sanitize_text_field( (string) $data['title'] ), 0, 255 );
			if ( '' === $title ) {
				return new WP_Error( 'uxstudio_nb_invalid_title', __( 'A title is required.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$update['title'] = $title;
			$format[]        = '%s';
		}
		if ( array_key_exists( 'body', $data ) ) {
			$update['body'] = wp_kses_post( (string) $data['body'] );
			$format[]       = '%s';
		}
		if ( array_key_exists( 'category', $data ) ) {
			$update['category'] = sanitize_title( (string) $data['category'] );
			$format[]           = '%s';
		}
		if ( array_key_exists( 'reference', $data ) ) {
			$update['reference'] = mb_substr( sanitize_text_field( (string) $data['reference'] ), 0, 191 );
			$format[]            = '%s';
		}
		if ( array_key_exists( 'attachments', $data ) ) {
			$update['attachments'] = wp_json_encode( $this->sanitize_attachment_ids( $data['attachments'] ) );
			$format[]              = '%s';
		}
		if ( array_key_exists( 'publish_date', $data ) ) {
			$update['publish_date'] = $this->sanitize_date( $data['publish_date'] );
			$format[]               = '%s';
		}
		if ( array_key_exists( 'expiry_date', $data ) ) {
			$update['expiry_date'] = $this->sanitize_date( $data['expiry_date'] );
			$format[]              = '%s';
		}
		if ( array_key_exists( 'is_archived', $data ) ) {
			$update['is_archived'] = ! empty( $data['is_archived'] ) ? 1 : 0;
			$format[]              = '%d';
		}

		if ( empty( $update ) ) {
			return $existing;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		$wpdb->update( "{$wpdb->prefix}uxstudio_notice_board", $update, array( 'id' => $id ), $format, array( '%d' ) );
		ActivityLog::log( 'notice-board', 'update', 'notice', $id );

		return (array) $this->get_notice( $id );
	}

	/**
	 * Delete a notice.
	 *
	 * @param int $id Row id.
	 */
	public function delete_notice( int $id ): bool {
		global $wpdb;

		if ( null === $this->get_notice( $id ) ) {
			return false;
		}

		$deleted = $wpdb->delete( "{$wpdb->prefix}uxstudio_notice_board", array( 'id' => $id ), array( '%d' ) );
		if ( $deleted ) {
			ActivityLog::log( 'notice-board', 'delete', 'notice', $id );
		}
		return (bool) $deleted;
	}

	/**
	 * Active notices for the frontend / feed: published, not archived, publish
	 * date reached. Newest first.
	 *
	 * @param string $category Optional category slug filter.
	 * @param int    $limit    Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_active_notices( string $category = '', int $limit = 20 ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_notice_board";
		$today = current_time( 'Y-m-d' );
		$limit = max( 1, min( 200, $limit ) );

		if ( '' !== $category ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_archived = 0 AND category = %s AND ( publish_date IS NULL OR publish_date <= %s ) ORDER BY publish_date DESC, id DESC LIMIT %d",
				sanitize_title( $category ),
				$today,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_archived = 0 AND ( publish_date IS NULL OR publish_date <= %s ) ORDER BY publish_date DESC, id DESC LIMIT %d",
				$today,
				$limit
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_notice' ), $rows );
	}

	/**
	 * Public, paginated, filterable query for the frontend board.
	 * Only published notices (publish date reached) are ever returned.
	 *
	 * @param array $args archived(bool), search, categories(slug[]), date_from, date_to (Y-m-d), page, per_page.
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int}
	 */
	public function query_notices( array $args ): array {
		global $wpdb;
		$table    = "{$wpdb->prefix}uxstudio_notice_board";
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );

		$where  = array( 'is_archived = %d', '( publish_date IS NULL OR publish_date <= %s )' );
		$params = array( ! empty( $args['archived'] ) ? 1 : 0, current_time( 'Y-m-d' ) );

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( title LIKE %s OR body LIKE %s OR reference LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$slugs = array_values( array_filter( array_map( 'sanitize_title', (array) ( $args['categories'] ?? array() ) ) ) );
		if ( ! empty( $slugs ) ) {
			$where[] = 'category IN ( ' . implode( ', ', array_fill( 0, count( $slugs ), '%s' ) ) . ' )';
			$params  = array_merge( $params, $slugs );
		}

		$from = $this->sanitize_date( $args['date_from'] ?? '' );
		$to   = $this->sanitize_date( $args['date_to'] ?? '' );
		if ( null !== $from ) {
			$where[]  = 'publish_date >= %s';
			$params[] = $from;
		}
		if ( null !== $to ) {
			$where[]  = 'publish_date <= %s';
			$params[] = $to;
		}

		$where_sql = implode( ' AND ', $where );
		$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY publish_date DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		return array(
			'items' => array_map( array( $this, 'format_notice' ), $rows ),
			'total' => $total,
			'pages' => max( 1, (int) ceil( $total / $per_page ) ),
			'page'  => $page,
		);
	}

	/**
	 * Published notice by id for the public detail view (archived allowed,
	 * not-yet-published hidden).
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function get_public_notice( int $id ): ?array {
		$notice = $this->get_notice( $id );
		if ( null === $notice ) {
			return null;
		}
		if ( '' !== (string) $notice['publish_date'] && (string) $notice['publish_date'] > current_time( 'Y-m-d' ) ) {
			return null;
		}
		return $notice;
	}

	/**
	 * Number of published active / archived notices (tab counters).
	 *
	 * @return array{active:int,archived:int}
	 */
	public function count_public_notices(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_notice_board";
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT is_archived, COUNT(*) AS c FROM {$table} WHERE ( publish_date IS NULL OR publish_date <= %s ) GROUP BY is_archived", current_time( 'Y-m-d' ) ),
			ARRAY_A
		);
		$out = array(
			'active'   => 0,
			'archived' => 0,
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ ! empty( $row['is_archived'] ) ? 'archived' : 'active' ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * URL of the page that hosts the board (first published page/post with one
	 * of the shortcodes; legacy option `ux1_notice_board_page_id` wins). Cached.
	 */
	public function board_page_url(): string {
		$legacy_id = (int) get_option( 'ux1_notice_board_page_id', 0 );
		if ( $legacy_id > 0 && 'publish' === get_post_status( $legacy_id ) ) {
			return (string) get_permalink( $legacy_id );
		}
		$cached = get_transient( 'uxstudio_nb_page_id' );
		if ( false === $cached ) {
			global $wpdb;
			$cached = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( 'page', 'post' ) AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT 1",
					'%[' . $wpdb->esc_like( self::SHORTCODE ) . '%',
					'%[' . $wpdb->esc_like( self::SHORTCODE_LEGACY ) . '%'
				)
			);
			set_transient( 'uxstudio_nb_page_id', $cached, HOUR_IN_SECONDS );
		}
		$url = (int) $cached > 0 ? get_permalink( (int) $cached ) : '';
		return $url ? (string) $url : home_url( '/' );
	}

	/**
	 * Open data "Informace na úřední desce" (OFN Úřední deska, JSON-LD) of all
	 * published, non-archived notices. Structure and keys are fixed by the
	 * standard (and identical to the legacy output), so they stay Czech.
	 *
	 * @return array<string, mixed>
	 */
	public function open_data(): array {
		$page_url   = $this->board_page_url();
		$result     = $this->query_notices(
			array(
				'archived' => false,
				'per_page' => 100,
			)
		);
		$categories = array();
		foreach ( $this->list_categories() as $cat ) {
			$categories[ (string) $cat['slug'] ] = (string) $cat['name'];
		}

		$items = $result['items'];
		for ( $p = 2; $p <= $result['pages'] && $p <= 5; $p++ ) {
			$more  = $this->query_notices(
				array(
					'archived' => false,
					'per_page' => 100,
					'page'     => $p,
				)
			);
			$items = array_merge( $items, $more['items'] );
		}

		$informace = array();
		foreach ( $items as $item ) {
			$posted = '' !== (string) $item['publish_date'] ? (string) $item['publish_date'] : substr( (string) $item['created_at'], 0, 10 );
			$entry  = array(
				'typ'      => array( 'Digitální objekt', 'Informace na úřední desce' ),
				'url'      => add_query_arg( self::DETAIL_QUERY_ARG, (int) $item['id'], $page_url ),
				'název'    => array( 'cs' => (string) $item['title'] ),
				'vyvěšení' => array(
					'typ'   => 'Časový okamžik',
					'datum' => $posted,
				),
			);
			if ( '' !== (string) $item['expiry_date'] ) {
				$entry['relevantní_do'] = array(
					'typ'   => 'Časový okamžik',
					'datum' => (string) $item['expiry_date'],
				);
			}
			if ( '' !== (string) $item['reference'] ) {
				$entry['číslo_jednací'] = (string) $item['reference'];
			}
			if ( '' !== (string) $item['category'] ) {
				$entry['agenda'] = array(
					array(
						'typ'   => 'Agenda',
						'název' => array( 'cs' => $categories[ (string) $item['category'] ] ?? (string) $item['category'] ),
					),
				);
			}
			if ( ! empty( $item['attachments'] ) ) {
				$entry['dokument'] = array();
				foreach ( (array) $item['attachments'] as $att ) {
					$entry['dokument'][] = array(
						'typ'       => 'Digitální objekt',
						'url'       => (string) $att['url'],
						'název'     => array( 'cs' => (string) $att['filename'] ),
						'typ_média' => 'http://www.iana.org/assignments/media-types/' . ( '' !== (string) $att['mime'] ? (string) $att['mime'] : 'application/octet-stream' ),
					);
				}
			}
			$informace[] = $entry;
		}

		return array(
			'@context'     => 'https://ofn.gov.cz/úřední-desky/2021-07-20/kontexty/úřední-deska.jsonld',
			'typ'          => 'Úřední deska',
			'stránka'      => $page_url,
			'provozovatel' => array(
				'typ'   => 'Orgán veřejné moci',
				'název' => array( 'cs' => get_bloginfo( 'name' ) ),
			),
			'informace'    => $informace,
		);
	}

	/**
	 * Archive notices whose expiry date has passed.
	 */
	public function run_auto_archive(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_notice_board";
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_archived = 1, updated_at = %s WHERE expiry_date IS NOT NULL AND expiry_date < %s AND is_archived = 0",
				current_time( 'mysql' ),
				current_time( 'Y-m-d' )
			)
		);
	}

	/**
	 * Permanently delete archived notices whose expiry date is older than the
	 * configured retention window. No-op when retention is 0.
	 */
	public function run_retention_cleanup(): void {
		$retention = (int) $this->settings->get( 'retention_days', 0 );
		if ( $retention <= 0 ) {
			return;
		}
		global $wpdb;
		$table  = "{$wpdb->prefix}uxstudio_notice_board";
		$cutoff = gmdate( 'Y-m-d', time() - ( $retention * DAY_IN_SECONDS ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE is_archived = 1 AND expiry_date IS NOT NULL AND expiry_date < %s",
				$cutoff
			)
		);
	}

	/**
	 * Normalize a notice row for output.
	 *
	 * @param array $row Raw row.
	 * @return array<string, mixed>
	 */
	private function format_notice( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'created_at'   => (string) ( $row['created_at'] ?? '' ),
			'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
			'title'        => (string) ( $row['title'] ?? '' ),
			'body'         => (string) ( $row['body'] ?? '' ),
			'category'     => (string) ( $row['category'] ?? '' ),
			'reference'    => (string) ( $row['reference'] ?? '' ),
			'attachments'  => $this->format_attachments( (string) ( $row['attachments'] ?? '' ) ),
			'publish_date' => null === ( $row['publish_date'] ?? null ) ? '' : (string) $row['publish_date'],
			'expiry_date'  => null === ( $row['expiry_date'] ?? null ) ? '' : (string) $row['expiry_date'],
			'is_archived'  => ! empty( $row['is_archived'] ),
		);
	}

	/**
	 * Decode a JSON list of attachment IDs into rich attachment descriptors.
	 *
	 * @param string $raw JSON-encoded array of attachment ids.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_attachments( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$ids = json_decode( $raw, true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$path = get_attached_file( $id );
			$out[] = array(
				'id'       => $id,
				'url'      => (string) wp_get_attachment_url( $id ),
				'filename' => $path ? basename( (string) $path ) : (string) get_the_title( $id ),
				'mime'     => (string) get_post_mime_type( $id ),
				'size'     => ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Validate a raw list of attachment ids: keep only positive ids that point
	 * to a real media-library attachment.
	 *
	 * @param mixed $raw Raw attachments input.
	 * @return array<int, int>
	 */
	private function sanitize_attachment_ids( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			$id = is_array( $item ) ? (int) ( $item['id'] ?? 0 ) : (int) $item;
			if ( $id > 0 && 'attachment' === get_post_type( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Validate a Y-m-d date, returning null for empty/invalid input.
	 *
	 * @param mixed $value Raw date.
	 */
	private function sanitize_date( $value ): ?string {
		$value = (string) $value;
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$parts = explode( '-', $value );
		if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
			return null;
		}
		return $value;
	}

	/* ================================================================== *
	 * Categories
	 * ================================================================== */

	/**
	 * All categories with their active-notice counts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_categories(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, name, slug, sort_order FROM {$wpdb->prefix}uxstudio_notice_board_categories ORDER BY sort_order ASC, name ASC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$counts = array();
		$raw    = $wpdb->get_results( "SELECT category, COUNT(*) AS c FROM {$wpdb->prefix}uxstudio_notice_board GROUP BY category", ARRAY_A );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $r ) {
				$counts[ (string) $r['category'] ] = (int) $r['c'];
			}
		}

		return array_map(
			static function ( array $row ) use ( $counts ): array {
				return array(
					'id'         => (int) $row['id'],
					'name'       => (string) $row['name'],
					'slug'       => (string) $row['slug'],
					'sort_order' => (int) $row['sort_order'],
					'count'      => (int) ( $counts[ (string) $row['slug'] ] ?? 0 ),
				);
			},
			$rows
		);
	}

	/**
	 * Create a category. Returns null when the slug already exists / is empty.
	 *
	 * @param string $name       Category name.
	 * @param int    $sort_order Sort order.
	 * @return array<string, mixed>|null
	 */
	public function create_category( string $name, int $sort_order = 0 ): ?array {
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
				'name'       => mb_substr( $name, 0, 191 ),
				'slug'       => mb_substr( $slug, 0, 191 ),
				'sort_order' => $sort_order,
			),
			array( '%s', '%s', '%d' )
		);

		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'notice-board', 'create', 'category', $id, array( 'slug' => $slug ) );

		return array(
			'id'         => $id,
			'name'       => $name,
			'slug'       => $slug,
			'sort_order' => $sort_order,
			'count'      => 0,
		);
	}

	/**
	 * Update a category's name and/or sort order.
	 *
	 * @param int   $id   Category id.
	 * @param array $data Raw input (name, sort_order).
	 * @return array<string, mixed>|null
	 */
	public function update_category( int $id, array $data ): ?array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_notice_board_categories";

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, slug, sort_order FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$update = array();
		$format = array();
		if ( array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' !== $name ) {
				$update['name'] = mb_substr( $name, 0, 191 );
				$format[]       = '%s';
			}
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$update['sort_order'] = (int) $data['sort_order'];
			$format[]             = '%d';
		}

		if ( ! empty( $update ) ) {
			$wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );
			ActivityLog::log( 'notice-board', 'update', 'category', $id );
		}

		$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, slug, sort_order FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $fresh ) ? array(
			'id'         => (int) $fresh['id'],
			'name'       => (string) $fresh['name'],
			'slug'       => (string) $fresh['slug'],
			'sort_order' => (int) $fresh['sort_order'],
			'count'      => 0,
		) : null;
	}

	/**
	 * Delete a category by id.
	 *
	 * @param int $id Category id.
	 */
	public function delete_category( int $id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete( "{$wpdb->prefix}uxstudio_notice_board_categories", array( 'id' => $id ), array( '%d' ) );
		if ( $deleted ) {
			ActivityLog::log( 'notice-board', 'delete', 'category', $id );
		}
		return (bool) $deleted;
	}

	/* ================================================================== *
	 * Subscriptions
	 * ================================================================== */

	/**
	 * All subscriptions, newest first (admin listing).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_subscriptions(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, email, confirmed, categories FROM {$wpdb->prefix}uxstudio_notice_board_subscriptions ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static function ( array $row ): array {
				$cats = json_decode( (string) ( $row['categories'] ?? '' ), true );
				return array(
					'id'         => (int) $row['id'],
					'created_at' => (string) $row['created_at'],
					'email'      => (string) $row['email'],
					'confirmed'  => (bool) $row['confirmed'],
					'categories' => is_array( $cats ) ? array_values( array_map( 'strval', $cats ) ) : array(),
				);
			},
			$rows
		);
	}

	/**
	 * Subscribe an email address (double opt-in). Idempotent and never reveals
	 * whether an address already exists:
	 *  - unconfirmed address: category set updated, confirmation re-sent;
	 *  - confirmed address: the new category set is only parked in
	 *    pending_categories and applied once the owner clicks a fresh
	 *    confirmation link - anyone can submit the form for any address.
	 * Confirmation mails to one address are throttled (CONFIRM_RESEND_HOURS).
	 *
	 * @param string   $email      Raw email address.
	 * @param string[] $categories Category slugs (empty = all categories).
	 */
	public function subscribe( string $email, array $categories = array() ): bool {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_notice_board_subscriptions";

		$email = sanitize_email( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$categories = $this->sanitize_category_slugs( $categories );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, confirmed, confirm_token, confirm_sent_at FROM {$table} WHERE email = %s", $email ),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			$id        = (int) $existing['id'];
			$confirmed = (bool) (int) $existing['confirmed'];
			$token     = (string) $existing['confirm_token'];
			$last      = (string) ( $existing['confirm_sent_at'] ?? '' );
			$can_mail  = '' === $last || strtotime( $last . ' UTC' ) < time() - self::CONFIRM_RESEND_HOURS * HOUR_IN_SECONDS;

			if ( $confirmed && ! $can_mail ) {
				// Throttled: leave the confirmed row (and the link already
				// mailed) untouched.
				return true;
			}

			if ( $confirmed ) {
				// Park the change; rotate the token so only the new mail applies it.
				$token = wp_generate_password( 32, false, false );
				$wpdb->update(
					$table,
					array(
						'pending_categories' => wp_json_encode( $categories ),
						'confirm_token'      => $token,
					),
					array( 'id' => $id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->update(
					$table,
					array( 'categories' => wp_json_encode( $categories ) ),
					array( 'id' => $id ),
					array( '%s' ),
					array( '%d' )
				);
			}

			if ( $can_mail ) {
				$this->send_confirm_email( $email, $token, $confirmed );
				$this->mark_confirm_sent( $id );
			}
			return true;
		}

		$confirm_token     = wp_generate_password( 32, false, false );
		$unsubscribe_token = wp_generate_password( 32, false, false );

		$wpdb->insert(
			$table,
			array(
				'created_at'        => current_time( 'mysql' ),
				'email'             => $email,
				'confirmed'         => 0,
				'confirm_token'     => $confirm_token,
				'unsubscribe_token' => $unsubscribe_token,
				'categories'        => wp_json_encode( $categories ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		$this->send_confirm_email( $email, $confirm_token );
		$this->mark_confirm_sent( (int) $wpdb->insert_id );
		return true;
	}

	/**
	 * Remember when a confirmation mail was last sent (UTC).
	 *
	 * @param int $id Subscription id.
	 */
	private function mark_confirm_sent( int $id ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}uxstudio_notice_board_subscriptions",
			array( 'confirm_sent_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
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
		// Confirms a new subscription, or applies a category change parked in
		// pending_categories by subscribe() for an already confirmed address.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}uxstudio_notice_board_subscriptions
				SET confirmed = 1, categories = COALESCE(pending_categories, categories), pending_categories = NULL
				WHERE confirm_token = %s",
				$token
			)
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
	 * Delete a subscription by id (admin).
	 *
	 * @param int $id Subscription id.
	 */
	public function delete_subscription( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( "{$wpdb->prefix}uxstudio_notice_board_subscriptions", array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Whitelist category slugs against the existing categories.
	 *
	 * @param mixed $raw Raw slug list.
	 * @return array<int, string>
	 */
	private function sanitize_category_slugs( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$valid = wp_list_pluck( $this->list_categories(), 'slug' );
		$out   = array();
		foreach ( $raw as $slug ) {
			$slug = sanitize_title( (string) $slug );
			if ( '' !== $slug && in_array( $slug, $valid, true ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/* ================================================================== *
	 * Email
	 * ================================================================== */

	/**
	 * Build From: headers from the configured / admin sender address.
	 *
	 * @return array<int, string>
	 */
	private function email_headers(): array {
		$headers    = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_email = (string) $this->settings->get( 'from_email', '' );
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			$from_email = (string) get_option( 'admin_email' );
		}
		if ( is_email( $from_email ) ) {
			$headers[] = 'From: ' . get_bloginfo( 'name' ) . ' <' . $from_email . '>';
		}
		return $headers;
	}

	/**
	 * Send the double opt-in confirmation email.
	 *
	 * @param string $email         Recipient.
	 * @param string $confirm_token Confirm token.
	 * @param bool   $change        Confirming a category change of a confirmed address.
	 */
	private function send_confirm_email( string $email, string $confirm_token, bool $change = false ): void {
		$confirm_url = rest_url( 'uxstudio/v1/notice-board/confirm/' . rawurlencode( $confirm_token ) );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Confirm your notice board subscription', 'ux-studio' ),
			get_bloginfo( 'name' )
		);

		$body  = '<p>' . ( $change
			? esc_html__( 'A change of the categories you follow on the notice board was requested. Confirm it by clicking the link below:', 'ux-studio' )
			: esc_html__( 'Please confirm your subscription to the notice board by clicking the link below:', 'ux-studio' ) ) . '</p>';
		$body .= '<p><a href="' . esc_url( $confirm_url ) . '">' . esc_html__( 'Confirm subscription', 'ux-studio' ) . '</a></p>';
		$body .= '<p style="color:#787c82;font-size:13px;">' . esc_html__( 'If you did not request this, you can ignore this email.', 'ux-studio' ) . '</p>';

		wp_mail( $email, $subject, $body, $this->email_headers() );
	}

	/**
	 * Notify matching confirmed subscribers about a freshly published notice.
	 * Recipients: subscribers with no category filter (all) or whose filter
	 * contains this notice's category.
	 *
	 * @param array $notice Formatted notice.
	 */
	private function maybe_notify_subscribers( array $notice ): void {
		if ( empty( $notice ) || ! empty( $notice['is_archived'] ) ) {
			return;
		}
		if ( ! (bool) $this->settings->get( 'notify_on_publish', true ) ) {
			return;
		}
		// Not yet published (future publish date) => no notification yet.
		$publish = (string) ( $notice['publish_date'] ?? '' );
		if ( '' !== $publish && $publish > current_time( 'Y-m-d' ) ) {
			return;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT email, unsubscribe_token, categories FROM {$wpdb->prefix}uxstudio_notice_board_subscriptions WHERE confirmed = 1",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		$category = (string) ( $notice['category'] ?? '' );
		$subject  = sprintf(
			/* translators: 1: site name, 2: notice title */
			__( '[%1$s] New notice: %2$s', 'ux-studio' ),
			get_bloginfo( 'name' ),
			$notice['title']
		);
		$headers = $this->email_headers();

		foreach ( $rows as $row ) {
			$cats = json_decode( (string) ( $row['categories'] ?? '' ), true );
			$cats = is_array( $cats ) ? array_map( 'strval', $cats ) : array();
			// Empty set = all categories.
			if ( ! empty( $cats ) && ! in_array( $category, $cats, true ) ) {
				continue;
			}
			$email = sanitize_email( (string) $row['email'] );
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}
			$body = $this->build_notification_email( $notice, (string) $row['unsubscribe_token'] );
			wp_mail( $email, $subject, $body, $headers );
		}
	}

	/**
	 * Build the HTML body for a new-notice notification.
	 *
	 * @param array  $notice            Formatted notice.
	 * @param string $unsubscribe_token Recipient's unsubscribe token.
	 */
	private function build_notification_email( array $notice, string $unsubscribe_token ): string {
		$unsub_url = rest_url( 'uxstudio/v1/notice-board/unsubscribe/' . rawurlencode( $unsubscribe_token ) );

		$body  = '<h2>' . esc_html__( 'New notice published', 'ux-studio' ) . '</h2>';
		$body .= '<p><strong>' . esc_html( (string) $notice['title'] ) . '</strong></p>';
		if ( '' !== (string) $notice['reference'] ) {
			$body .= '<p>' . esc_html__( 'Reference:', 'ux-studio' ) . ' ' . esc_html( (string) $notice['reference'] ) . '</p>';
		}
		if ( '' !== (string) $notice['publish_date'] ) {
			$body .= '<p>' . esc_html__( 'Published:', 'ux-studio' ) . ' ' . esc_html( mysql2date( (string) get_option( 'date_format' ), (string) $notice['publish_date'] ) ) . '</p>';
		}
		if ( '' !== (string) $notice['body'] ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( (string) $notice['body'] ), 50 );
			$body   .= '<p>' . esc_html( $excerpt ) . '</p>';
		}
		$body .= '<hr><p style="color:#a7aaad;font-size:12px;"><a href="' . esc_url( $unsub_url ) . '">' . esc_html__( 'Unsubscribe', 'ux-studio' ) . '</a></p>';

		return $body;
	}

	/* ================================================================== *
	 * RSS feed
	 * ================================================================== */

	/**
	 * Register the RSS feed endpoint (accessible at ?feed=uxstudio-notice-board,
	 * optionally &uxs_nb_cat=<slug>).
	 */
	public function register_feed(): void {
		add_feed( self::FEED_SLUG, array( $this, 'render_feed' ) );
		// Legacy slug: /feed/uredni-nastenka/ is already subscribed to in feed readers.
		add_feed( self::FEED_SLUG_LEGACY, array( $this, 'render_feed' ) );

		// add_feed() only takes effect in pretty permalinks after a rewrite
		// flush; module toggles don't flush, so heal once when our feed rule
		// is missing from the stored rules (cheap check on an autoloaded option).
		// Deferred to wp_loaded so every plugin's post types/rules registered
		// later on init are included in the regenerated set.
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && ! empty( $rules ) && false === strpos( implode( ' ', array_keys( $rules ) ), self::FEED_SLUG_LEGACY ) ) {
			add_action(
				'wp_loaded',
				static function (): void {
					flush_rewrite_rules( false );
				}
			);
		}
	}

	/**
	 * Register the per-category feed query var.
	 *
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Render the RSS 2.0 feed and exit.
	 */
	public function render_feed(): void {
		$category = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
		$limit    = (int) $this->settings->get( 'per_page', 20 );
		$notices  = $this->get_active_notices( $category, $limit );

		$feed_title = get_bloginfo( 'name' ) . ' — ' . __( 'Notice board', 'ux-studio' );

		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
		echo '<channel>' . "\n";
		echo '<title>' . esc_html( $feed_title ) . '</title>' . "\n";
		echo '<link>' . esc_url( home_url( '/' ) ) . '</link>' . "\n";
		echo '<description>' . esc_html__( 'Latest notices', 'ux-studio' ) . '</description>' . "\n";
		echo '<lastBuildDate>' . esc_html( gmdate( 'r' ) ) . '</lastBuildDate>' . "\n";

		foreach ( $notices as $notice ) {
			$pub = '' !== (string) $notice['publish_date'] ? (string) $notice['publish_date'] : (string) $notice['created_at'];
			$ts  = strtotime( $pub );
			echo '<item>' . "\n";
			echo '<title>' . esc_html( (string) $notice['title'] ) . '</title>' . "\n";
			echo '<guid isPermaLink="false">uxstudio-notice-' . (int) $notice['id'] . '</guid>' . "\n";
			echo '<pubDate>' . esc_html( gmdate( 'r', $ts ? $ts : time() ) ) . '</pubDate>' . "\n";
			echo '<description><![CDATA[' . wp_kses_post( (string) $notice['body'] ) . ']]></description>' . "\n";
			if ( '' !== (string) $notice['category'] ) {
				echo '<category>' . esc_html( (string) $notice['category'] ) . '</category>' . "\n";
			}
			foreach ( (array) $notice['attachments'] as $att ) {
				echo '<enclosure url="' . esc_url( (string) $att['url'] ) . '" length="' . (int) $att['size'] . '" type="' . esc_attr( (string) $att['mime'] ) . '" />' . "\n";
			}
			echo '</item>' . "\n";
		}

		echo '</channel>' . "\n";
		echo '</rss>';
		exit;
	}

	/* ================================================================== *
	 * Frontend shortcode
	 * ================================================================== */

	/**
	 * [uxstudio_notice_board] (legacy alias [uredni_nastenka]) - public board:
	 * Active / Archive tabs, search, document-type filter, date range,
	 * pagination, document detail (?dokument=ID) and a subscribe form.
	 *
	 * Attributes: category="slug" (fixed category, hides the type filter),
	 * limit="20" (per page), subscribe="1", filters="1" (search/filter/archive UI).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category'  => '',
				'limit'     => (string) (int) $this->settings->get( 'per_page', 20 ),
				'subscribe' => '1',
				'filters'   => '1',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$this->run_auto_archive_if_due();

		ob_start();
		$this->render_shortcode_styles();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only view.
		$detail_id = isset( $_GET[ self::DETAIL_QUERY_ARG ] ) ? absint( wp_unslash( $_GET[ self::DETAIL_QUERY_ARG ] ) ) : 0;
		if ( $detail_id > 0 ) {
			$this->render_detail( $detail_id );
			return (string) ob_get_clean();
		}

		$fixed_cat = sanitize_title( (string) $atts['category'] );
		$per_page  = max( 1, min( 100, (int) $atts['limit'] ) );
		$filters   = '1' === (string) $atts['filters'];
		$names     = array();
		foreach ( $this->list_categories() as $cat ) {
			$names[ (string) $cat['slug'] ] = (string) $cat['name'];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only filters.
		$archived = $filters && isset( $_GET['nb_tab'] ) && 'archive' === sanitize_key( wp_unslash( $_GET['nb_tab'] ) );
		$search   = $filters && isset( $_GET['nb_q'] ) ? sanitize_text_field( wp_unslash( $_GET['nb_q'] ) ) : '';
		$sel_cats = array();
		if ( '' !== $fixed_cat ) {
			$sel_cats = array( $fixed_cat );
		} elseif ( $filters && isset( $_GET['nb_cat'] ) ) {
			$sel_cats = array_map( 'sanitize_title', array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['nb_cat'] ) ) );
		}
		$from = $filters && isset( $_GET['nb_from'] ) ? sanitize_text_field( wp_unslash( $_GET['nb_from'] ) ) : '';
		$to   = $filters && isset( $_GET['nb_to'] ) ? sanitize_text_field( wp_unslash( $_GET['nb_to'] ) ) : '';
		$page = isset( $_GET['nb_page'] ) ? max( 1, absint( wp_unslash( $_GET['nb_page'] ) ) ) : 1;
		// phpcs:enable

		$result = $this->query_notices(
			array(
				'archived'   => $archived,
				'search'     => $search,
				'categories' => $sel_cats,
				'date_from'  => $from,
				'date_to'    => $to,
				'page'       => $page,
				'per_page'   => $per_page,
			)
		);

		$base   = $this->current_base_url();
		$counts = $this->count_public_notices();
		?>
		<div class="uxs-notice-board">
			<?php if ( $filters ) : ?>
				<nav class="uxs-nb-tabs" aria-label="<?php esc_attr_e( 'Notice board', 'ux-studio' ); ?>">
					<a class="uxs-nb-tab<?php echo $archived ? '' : ' is-active'; ?>" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Active', 'ux-studio' ); ?> <span class="uxs-nb-tab__count"><?php echo (int) $counts['active']; ?></span></a>
					<a class="uxs-nb-tab<?php echo $archived ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'nb_tab', 'archive', $base ) ); ?>"><?php esc_html_e( 'Archive', 'ux-studio' ); ?> <span class="uxs-nb-tab__count"><?php echo (int) $counts['archived']; ?></span></a>
				</nav>

				<form class="uxs-nb-filters" method="get" action="<?php echo esc_url( $base ); ?>" role="search">
					<?php foreach ( array( 'page_id', 'p' ) as $keep ) : // Plain permalinks keep the page in the query string. ?>
						<?php if ( isset( $_GET[ $keep ] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
							<input type="hidden" name="<?php echo esc_attr( $keep ); ?>" value="<?php echo esc_attr( (string) absint( wp_unslash( $_GET[ $keep ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( $archived ) : ?>
						<input type="hidden" name="nb_tab" value="archive">
					<?php endif; ?>
					<input type="search" name="nb_q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search notices…', 'ux-studio' ); ?>" aria-label="<?php esc_attr_e( 'Search notices…', 'ux-studio' ); ?>">
					<?php if ( '' === $fixed_cat && ! empty( $names ) ) : ?>
						<details class="uxs-nb-types">
							<summary><?php esc_html_e( 'Document type', 'ux-studio' ); ?><?php echo $sel_cats ? ' (' . (int) count( $sel_cats ) . ')' : ''; ?></summary>
							<div class="uxs-nb-types__list">
								<?php foreach ( $names as $slug => $name ) : ?>
									<label><input type="checkbox" name="nb_cat[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $sel_cats, true ) ); ?>> <?php echo esc_html( $name ); ?></label>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endif; ?>
					<label><?php esc_html_e( 'From', 'ux-studio' ); ?> <input type="date" name="nb_from" value="<?php echo esc_attr( (string) ( $this->sanitize_date( $from ) ?? '' ) ); ?>"></label>
					<label><?php esc_html_e( 'To', 'ux-studio' ); ?> <input type="date" name="nb_to" value="<?php echo esc_attr( (string) ( $this->sanitize_date( $to ) ?? '' ) ); ?>"></label>
					<button type="submit"><?php esc_html_e( 'Filter', 'ux-studio' ); ?></button>
					<?php if ( '' !== $search || ( $sel_cats && '' === $fixed_cat ) || '' !== $from || '' !== $to ) : ?>
						<a class="uxs-nb-reset" href="<?php echo esc_url( $archived ? add_query_arg( 'nb_tab', 'archive', $base ) : $base ); ?>"><?php esc_html_e( 'Reset', 'ux-studio' ); ?></a>
					<?php endif; ?>
				</form>
			<?php endif; ?>

			<?php if ( empty( $result['items'] ) ) : ?>
				<p class="uxs-nb-empty"><?php esc_html_e( 'No notices at the moment.', 'ux-studio' ); ?></p>
			<?php else : ?>
				<ul class="uxs-nb-list">
					<?php foreach ( $result['items'] as $notice ) : ?>
						<li class="uxs-nb-item">
							<h3 class="uxs-nb-item__title"><a href="<?php echo esc_url( add_query_arg( self::DETAIL_QUERY_ARG, (int) $notice['id'], $base ) ); ?>"><?php echo esc_html( (string) $notice['title'] ); ?></a></h3>
							<?php $this->render_notice_meta( $notice, $names ); ?>
							<?php if ( '' !== (string) $notice['body'] ) : ?>
								<div class="uxs-nb-item__body"><?php echo wp_kses_post( wpautop( (string) $notice['body'] ) ); ?></div>
							<?php endif; ?>
							<?php $this->render_notice_files( $notice ); ?>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( $result['pages'] > 1 ) : ?>
					<nav class="uxs-nb-pager" aria-label="<?php esc_attr_e( 'Pagination', 'ux-studio' ); ?>">
						<?php
						$keep_args = array_filter(
							array(
								'nb_tab'  => $archived ? 'archive' : '',
								'nb_q'    => $search,
								'nb_cat'  => '' === $fixed_cat ? $sel_cats : array(),
								'nb_from' => $from,
								'nb_to'   => $to,
							)
						);
						for ( $i = 1; $i <= $result['pages']; $i++ ) :
							$url = add_query_arg( array_merge( $keep_args, $i > 1 ? array( 'nb_page' => $i ) : array() ), $base );
							?>
							<a class="uxs-nb-pager__link<?php echo $i === $result['page'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $i === $result['page'] ? ' aria-current="page"' : ''; ?>><?php echo (int) $i; ?></a>
						<?php endfor; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>

			<p class="uxs-nb-links">
				<a href="<?php echo esc_url( rest_url( 'uxstudio/v1/notice-board/open-data' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open data (JSON-LD)', 'ux-studio' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/?feed=' . self::FEED_SLUG ) ); ?>" target="_blank" rel="noopener">RSS</a>
			</p>

			<?php if ( '1' === (string) $atts['subscribe'] ) : ?>
				<?php $this->render_subscribe_form(); ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Document detail view (?dokument=ID).
	 *
	 * @param int $id Notice id.
	 */
	private function render_detail( int $id ): void {
		$base   = $this->current_base_url();
		$notice = $this->get_public_notice( $id );
		?>
		<div class="uxs-notice-board">
			<a class="uxs-nb-back" href="<?php echo esc_url( $base ); ?>">&larr; <?php esc_html_e( 'Back to notice board', 'ux-studio' ); ?></a>
			<?php if ( null === $notice ) : ?>
				<p class="uxs-nb-empty"><?php esc_html_e( 'Notice not found.', 'ux-studio' ); ?></p>
			<?php else : ?>
				<?php
				$names = array();
				foreach ( $this->list_categories() as $cat ) {
					$names[ (string) $cat['slug'] ] = (string) $cat['name'];
				}
				?>
				<article class="uxs-nb-detail">
					<h2 class="uxs-nb-item__title"><?php echo esc_html( (string) $notice['title'] ); ?></h2>
					<?php $this->render_notice_meta( $notice, $names, true ); ?>
					<?php if ( '' !== (string) $notice['body'] ) : ?>
						<div class="uxs-nb-item__body"><?php echo wp_kses_post( wpautop( (string) $notice['body'] ) ); ?></div>
					<?php endif; ?>
					<?php $this->render_notice_files( $notice ); ?>
					<?php if ( $notice['is_archived'] ) : ?>
						<p class="uxs-nb-archived" role="status"><?php esc_html_e( 'This notice has been taken down and is kept in the archive.', 'ux-studio' ); ?></p>
					<?php endif; ?>
				</article>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Date / reference / category line of a notice.
	 *
	 * @param array                $notice      Formatted notice.
	 * @param array<string,string> $names       Category slug => name.
	 * @param bool                 $with_expiry Also show the removal date.
	 */
	private function render_notice_meta( array $notice, array $names, bool $with_expiry = false ): void {
		$category = (string) $notice['category'];
		?>
		<p class="uxs-nb-item__meta">
			<?php if ( '' !== (string) $notice['publish_date'] ) : ?>
				<span><?php echo $with_expiry ? esc_html__( 'Posted', 'ux-studio' ) . ': ' : ''; ?><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $notice['publish_date'] ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( $with_expiry && '' !== (string) $notice['expiry_date'] ) : ?>
				<span><?php esc_html_e( 'Removed', 'ux-studio' ); ?>: <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $notice['expiry_date'] ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== (string) $notice['reference'] ) : ?>
				<span class="uxs-nb-item__ref"><?php echo esc_html( (string) $notice['reference'] ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $category ) : ?>
				<span class="uxs-nb-item__cat"><?php echo esc_html( $names[ $category ] ?? $category ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Attachment links of a notice.
	 *
	 * @param array $notice Formatted notice.
	 */
	private function render_notice_files( array $notice ): void {
		if ( empty( $notice['attachments'] ) ) {
			return;
		}
		?>
		<ul class="uxs-nb-item__files">
			<?php foreach ( (array) $notice['attachments'] as $att ) : ?>
				<li>
					<a href="<?php echo esc_url( (string) $att['url'] ); ?>" rel="noopener" target="_blank">
						<?php echo esc_html( (string) $att['filename'] ); ?>
					</a>
					<?php if ( (int) $att['size'] > 0 ) : ?>
						<span class="uxs-nb-item__size">(<?php echo esc_html( size_format( (int) $att['size'] ) ); ?>)</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * URL of the current page without the board's own query args (filters,
	 * detail), used as the base for tab / filter / detail links.
	 */
	private function current_base_url(): string {
		$url = is_singular() ? get_permalink( (int) get_queried_object_id() ) : home_url( '/' );
		return (string) $url;
	}

	/**
	 * Archive expired notices at most once per hour when a board is rendered
	 * (the daily boot-time run alone could leave expired notices visible).
	 */
	private function run_auto_archive_if_due(): void {
		if ( get_transient( 'uxstudio_nb_archive_render' ) ) {
			return;
		}
		$this->run_auto_archive();
		set_transient( 'uxstudio_nb_archive_render', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Emit the shortcode's scoped inline styles once per request.
	 */
	private function render_shortcode_styles(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<style>
			.uxs-notice-board{max-width:820px;margin:0 auto}
			.uxs-nb-list{list-style:none;margin:0;padding:0}
			.uxs-nb-item{padding:16px 0;border-bottom:1px solid #e2e4e7}
			.uxs-nb-item__title{margin:0 0 4px;font-size:1.15em}
			.uxs-nb-item__meta{margin:0 0 8px;color:#646970;font-size:.85em;display:flex;gap:12px;flex-wrap:wrap}
			.uxs-nb-item__cat{background:#f0f0f1;border-radius:3px;padding:1px 8px}
			.uxs-nb-item__files{list-style:none;margin:8px 0 0;padding:0;display:flex;flex-direction:column;gap:4px}
			.uxs-nb-item__files a{text-decoration:none}
			.uxs-nb-empty{color:#646970;font-style:italic}
			.uxs-nb-tabs{display:flex;gap:4px;border-bottom:1px solid #e2e4e7;margin-bottom:12px}
			.uxs-nb-tab{padding:8px 14px;text-decoration:none;border-bottom:2px solid transparent;color:inherit}
			.uxs-nb-tab.is-active{border-bottom-color:currentColor;font-weight:600}
			.uxs-nb-tab__count{background:#f0f0f1;border-radius:9px;padding:0 7px;font-size:.8em}
			.uxs-nb-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px}
			.uxs-nb-filters input[type=search]{min-width:200px;padding:6px 10px}
			.uxs-nb-types{position:relative}
			.uxs-nb-types summary{cursor:pointer;padding:6px 10px;border:1px solid #c3c4c7;border-radius:3px;background:#fff}
			.uxs-nb-types__list{position:absolute;z-index:5;background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:8px;display:flex;flex-direction:column;gap:4px;min-width:200px;max-height:260px;overflow:auto}
			.uxs-nb-item__title a{text-decoration:none;color:inherit}
			.uxs-nb-item__size{color:#646970;font-size:.85em}
			.uxs-nb-pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:16px}
			.uxs-nb-pager__link{padding:4px 10px;border:1px solid #c3c4c7;border-radius:3px;text-decoration:none}
			.uxs-nb-pager__link.is-active{font-weight:700;background:#f0f0f1}
			.uxs-nb-links{display:flex;gap:16px;margin-top:16px;font-size:.9em}
			.uxs-nb-back{display:inline-block;margin-bottom:12px;text-decoration:none}
			.uxs-nb-archived{margin-top:16px;padding:10px 14px;background:#f6f7f7;border-left:4px solid #dba617}
			.uxs-nb-subscribe{margin-top:24px;padding:16px;border:1px solid #e2e4e7;border-radius:6px;background:#fbfbfc}
			.uxs-nb-subscribe input[type=email]{padding:6px 10px;min-width:220px}
			.uxs-nb-subscribe button{padding:6px 16px;cursor:pointer}
			.uxs-nb-subscribe__msg{margin-top:8px;font-size:.9em}
		</style>
		<?php
	}

	/**
	 * Render the public subscribe form (progressive enhancement: posts to the
	 * public REST subscribe endpoint via fetch, with a graceful fallback).
	 */
	private function render_subscribe_form(): void {
		$endpoint = esc_url( rest_url( 'uxstudio/v1/notice-board/subscribe' ) );
		$dom_id   = 'uxs-nb-subscribe-' . wp_rand( 1000, 9999 );
		?>
		<form class="uxs-nb-subscribe" id="<?php echo esc_attr( $dom_id ); ?>" method="post" action="<?php echo $endpoint; ?>">
			<label for="<?php echo esc_attr( $dom_id ); ?>-email"><?php esc_html_e( 'Get notified of new notices by email:', 'ux-studio' ); ?></label><br>
			<input type="email" id="<?php echo esc_attr( $dom_id ); ?>-email" name="email" required placeholder="<?php esc_attr_e( 'your@email.com', 'ux-studio' ); ?>">
			<button type="submit"><?php esc_html_e( 'Subscribe', 'ux-studio' ); ?></button>
			<div class="uxs-nb-subscribe__msg" role="status" aria-live="polite"></div>
		</form>
		<script>
		( function () {
			var form = document.getElementById( <?php echo wp_json_encode( $dom_id ); ?> );
			if ( ! form ) { return; }
			var msg = form.querySelector( '.uxs-nb-subscribe__msg' );
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var email = form.querySelector( 'input[name=email]' ).value;
				msg.textContent = <?php echo wp_json_encode( __( 'Sending…', 'ux-studio' ) ); ?>;
				fetch( form.getAttribute( 'action' ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { email: email } )
				} ).then( function () {
					form.reset();
					msg.textContent = <?php echo wp_json_encode( __( 'Check your inbox for a confirmation link.', 'ux-studio' ) ); ?>;
				} ).catch( function () {
					msg.textContent = <?php echo wp_json_encode( __( 'Something went wrong. Please try again later.', 'ux-studio' ) ); ?>;
				} );
			} );
		} )();
		</script>
		<?php
	}
}
