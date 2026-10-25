<?php
/**
 * Node-side REST endpoints: receive & apply operations pushed by a hub.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\BotThrottle\Log as BotThrottleLog;
use UxStudio\Modules\CronControl\Module as CronControlModule;
use UxStudio\Modules\EmailLog\Module as EmailLogModule;
use UxStudio\Modules\SecurityOptimization\IpBanStore;
use UxStudio\Plugin;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * These routes are authenticated ONLY by HMAC (shared node_api_key), not by a
 * WP cookie/nonce, because the caller is another site (the hub), not a logged
 * in browser. Every route's permission_callback runs HmacAuth::verify_request.
 * All routes live under uxstudio/v1/content-sync/node.
 */
final class NodeController {

	private const NS   = 'uxstudio/v1';
	private const BASE = '/content-sync/node';

	/** Option: unix time of the last signed ping from the hub / central app. */
	public const LAST_PING_OPTION = 'uxstudio_node_last_ping';

	/**
	 * Register node routes.
	 */
	public function register_routes(): void {
		$verify = array( $this, 'verify' );

		register_rest_route( self::NS, self::BASE . '/ping', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ping' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/posts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_posts' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => $verify,
			),
		) );

		register_rest_route( self::NS, self::BASE . '/posts/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_post' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_post' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_post' ),
				'permission_callback' => $verify,
			),
		) );

		register_rest_route( self::NS, self::BASE . '/categories', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_categories' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_category' ),
				'permission_callback' => $verify,
			),
		) );

		register_rest_route( self::NS, self::BASE . '/categories/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_category' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_category' ),
				'permission_callback' => $verify,
			),
		) );

		register_rest_route( self::NS, self::BASE . '/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_terms' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/media/upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_media' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/media', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_media' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/media/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_media' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/media/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_media' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/acf/field-groups', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_acf_field_groups' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/email-log', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_email_log' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/ip-bans', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_ip_bans' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/ip-bans/sync', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'sync_ip_bans' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/bot-throttle/bans', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_bot_throttle_bans' ),
			'permission_callback' => $verify,
		) );

		// Central app switches its WP-Cron takeover on/off (CA ApiClient::setCronControl).
		register_rest_route( self::NS, self::BASE . '/cron-control', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'set_cron_control' ),
			'permission_callback' => $verify,
		) );

		// Content structure + revisions + download files (legacy parity).
		register_rest_route( self::NS, self::BASE . '/posts/(?P<id>\d+)/revisions', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_revisions' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/post-types', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_post_types' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/taxonomies', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_taxonomies' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/posts/(?P<id>\d+)/download-files', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_download_file' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/posts/(?P<id>\d+)/download-files/(?P<filename>[^/]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_download_file' ),
			'permission_callback' => $verify,
		) );

		// Health monitoring polled by the Central App.
		$health = new NodeHealth();
		$health_routes = array(
			'/updates'          => 'updates',
			'/php-errors'       => 'php_errors',
			'/pagespeed'        => 'pagespeed',
			'/email-health'     => 'email_health',
			'/security-monitor' => 'security_monitor',
			'/vulnerabilities'  => 'vulnerabilities',
			'/guide'            => 'guide',
			'/service-requests' => 'service_requests',
		);
		foreach ( $health_routes as $path => $method ) {
			register_rest_route( self::NS, self::BASE . $path, array(
				'methods'             => 'GET',
				'callback'            => array( $health, $method ),
				'permission_callback' => $verify,
			) );
		}

		// Package distribution (HIGH RISK - HMAC only, see NodePackages).
		$packages = new NodePackages();
		register_rest_route( self::NS, self::BASE . '/packages/ping', array(
			'methods'             => 'GET',
			'callback'            => array( $packages, 'ping' ),
			'permission_callback' => $verify,
		) );
		register_rest_route( self::NS, self::BASE . '/packages/status', array(
			'methods'             => 'GET',
			'callback'            => array( $packages, 'status' ),
			'permission_callback' => $verify,
		) );
		register_rest_route( self::NS, self::BASE . '/packages/install', array(
			'methods'             => 'POST',
			'callback'            => array( $packages, 'install' ),
			'permission_callback' => $verify,
		) );
		register_rest_route( self::NS, self::BASE . '/packages/configure-fs-method', array(
			'methods'             => 'POST',
			'callback'            => array( $packages, 'configure_fs_method' ),
			'permission_callback' => $verify,
		) );
	}

	/**
	 * Revisions of a post (latest 50), with content, for the hub's diff view.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_revisions( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		if ( ! $this->is_editable_post( get_post( $post_id ) ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}

		$items = array();
		foreach ( wp_get_post_revisions( $post_id, array( 'order' => 'DESC', 'posts_per_page' => 50 ) ) as $rev ) {
			$items[] = array(
				'id'      => $rev->ID,
				'author'  => get_the_author_meta( 'display_name', (int) $rev->post_author ),
				'date'    => $rev->post_date,
				'title'   => $rev->post_title,
				'excerpt' => wp_trim_words( $rev->post_content, 30, '...' ),
				'content' => $rev->post_content,
			);
		}

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * Public post types with labels, supports and attached taxonomies.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_post_types( WP_REST_Request $request ): WP_REST_Response {
		$result = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$result[] = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'labels'       => array(
					'singular' => $pt->labels->singular_name,
					'add_new'  => $pt->labels->add_new_item,
					'edit'     => $pt->labels->edit_item,
				),
				'hierarchical' => (bool) $pt->hierarchical,
				'has_archive'  => (bool) $pt->has_archive,
				'supports'     => get_all_post_type_supports( $pt->name ),
				'taxonomies'   => get_object_taxonomies( $pt->name ),
			);
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Public taxonomies, each with all of its terms.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_taxonomies( WP_REST_Request $request ): WP_REST_Response {
		$result = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$result[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
				'post_types'   => (array) $tax->object_type,
				'terms'        => $this->format_terms( $tax->name ),
			);
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Attach an uploaded file to a post's "download files" list (legacy storage:
	 * uploads/ux1-download-files/{post_id}/, post meta `_ux1_download_files`).
	 *
	 * @param WP_REST_Request $request Request (multipart `file`).
	 */
	public function upload_download_file( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		if ( ! $this->is_editable_post( get_post( $post_id ) ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_REST_Response( array( 'message' => __( 'No file was sent.', 'ux-studio' ) ), 400 );
		}
		$file = $files['file'];

		if ( (int) $file['size'] > 20 * 1024 * 1024 ) {
			return new WP_REST_Response( array( 'message' => __( 'File is too large (max 20 MB).', 'ux-studio' ) ), 400 );
		}

		$allowed_ext = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt', 'rtf', 'odt', 'ods', 'jpg', 'jpeg', 'png', 'gif' );
		$ext         = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed_ext, true ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Unsupported file type.', 'ux-studio' ) ), 400 );
		}

		$upload_dir = WP_CONTENT_DIR . '/uploads/ux1-download-files/' . $post_id;
		if ( ! is_dir( $upload_dir ) ) {
			wp_mkdir_p( $upload_dir );
			// Block direct access and listing (Apache; on nginx deny this path in the server config).
			file_put_contents( $upload_dir . '/.htaccess', 'Deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $upload_dir . '/index.php', '<?php // Silence is golden' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$safe_name = sanitize_file_name( (string) $file['name'] );
		$dest      = $upload_dir . '/' . $safe_name;

		// Never overwrite an existing file.
		$counter = 1;
		$base    = pathinfo( $safe_name, PATHINFO_FILENAME );
		while ( file_exists( $dest ) ) {
			$safe_name = $base . '-' . $counter . '.' . $ext;
			$dest      = $upload_dir . '/' . $safe_name;
			++$counter;
		}

		// move_uploaded_file() fails for cURL multipart relayed by WP REST; fall back to copy().
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) && ! @copy( $file['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return new WP_REST_Response( array( 'message' => __( 'Could not save the file.', 'ux-studio' ) ), 500 );
		}

		$file_list   = $this->download_file_list( $post_id );
		$file_list[] = array(
			'filename' => $safe_name,
			'size'     => (int) $file['size'],
			'uploaded' => current_time( 'mysql' ),
		);
		update_post_meta( $post_id, '_ux1_download_files', wp_json_encode( $file_list ) );

		ActivityLog::log( 'content-sync', 'node_download_file_upload', 'post', $post_id, array( 'file' => $safe_name ) );

		return new WP_REST_Response(
			array(
				'filename' => $safe_name,
				'size'     => (int) $file['size'],
				'files'    => $file_list,
			),
			201
		);
	}

	/**
	 * Remove one file from a post's "download files" list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_download_file( WP_REST_Request $request ): WP_REST_Response {
		$post_id  = (int) $request['id'];
		$filename = sanitize_file_name( rawurldecode( (string) $request['filename'] ) );
		if ( ! $this->is_editable_post( get_post( $post_id ) ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}
		if ( '' === $filename || $filename !== basename( $filename ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Invalid file path.', 'ux-studio' ) ), 400 );
		}

		$upload_dir = WP_CONTENT_DIR . '/uploads/ux1-download-files/' . $post_id;
		$filepath   = $upload_dir . '/' . $filename;

		// Path traversal guard: the resolved file must live inside the post's directory.
		$real_dir  = realpath( $upload_dir );
		$real_file = realpath( $filepath );
		if ( false !== $real_file ) {
			if ( false === $real_dir || 0 !== strpos( $real_file, $real_dir . DIRECTORY_SEPARATOR ) ) {
				return new WP_REST_Response( array( 'message' => __( 'Invalid file path.', 'ux-studio' ) ), 400 );
			}
			wp_delete_file( $real_file );
		}

		$file_list = array_values(
			array_filter(
				$this->download_file_list( $post_id ),
				static fn ( $f ): bool => ( $f['filename'] ?? '' ) !== $filename
			)
		);
		update_post_meta( $post_id, '_ux1_download_files', wp_json_encode( $file_list ) );

		ActivityLog::log( 'content-sync', 'node_download_file_delete', 'post', $post_id, array( 'file' => $filename ) );

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'filename' => $filename,
				'files'    => $file_list,
			),
			200
		);
	}

	/**
	 * Decoded `_ux1_download_files` meta of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, array<string, mixed>>
	 */
	private function download_file_list( int $post_id ): array {
		$raw  = get_post_meta( $post_id, '_ux1_download_files', true );
		$list = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Filtered/paginated email-log entries for the hub's aggregated "Log
	 * emailů" view. Returns an empty list (not an error) when the email-log
	 * module isn't enabled here - same as a site simply having sent nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_email_log( WP_REST_Request $request ): WP_REST_Response {
		$modules = Plugin::instance()->modules;
		$module  = in_array( 'email-log', $modules->enabled_ids(), true ) ? $modules->instance( 'email-log' ) : null;

		if ( ! $module instanceof EmailLogModule ) {
			return new WP_REST_Response( array( 'items' => array(), 'total' => 0 ), 200 );
		}

		return new WP_REST_Response(
			$module->get_entries_for_hub(
				array(
					'limit'     => $request->get_param( 'limit' ),
					'status'    => $request->get_param( 'status' ),
					'search'    => $request->get_param( 'search' ),
					'date_from' => $request->get_param( 'date_from' ),
					'date_to'   => $request->get_param( 'date_to' ),
				)
			),
			200
		);
	}

	/**
	 * Informational report of this site's IP bans (local + central) for CA's
	 * per-site detail popup - read-only, CA never mutates local bans here.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_ip_bans( WP_REST_Request $request ): WP_REST_Response {
		$report = ( new IpBanStore() )->get_report();

		return new WP_REST_Response(
			array(
				'bans'            => $report['bans'],
				'local_count'     => $report['local_count'],
				'central_count'   => $report['central_count'],
				'firewall_active' => (bool) ( new Settings( 'uxstudio_security_optimization' ) )->get( 'ip_firewall_enabled', false ),
			),
			200
		);
	}

	/**
	 * Replace this site's central-origin bans with the authoritative set CA
	 * just pushed. Local bans are never touched.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function sync_ip_bans( WP_REST_Request $request ): WP_REST_Response {
		$data  = (array) $request->get_json_params();
		$items = is_array( $data['bans'] ?? null ) ? $data['bans'] : array();

		$result = ( new IpBanStore() )->replace_central_bans( $items );

		ActivityLog::log( 'content-sync', 'central_ip_bans_synced', 'ip_ban', 0, $result );

		return new WP_REST_Response(
			array(
				'success' => true,
				'synced'  => $result['synced'],
				'skipped' => $result['skipped'],
			),
			200
		);
	}

	/**
	 * Body {enabled: bool}: true = the central app takes over WP-Cron (Cron
	 * Control mode central_app, module switched on if needed), false = give it
	 * back. Logic lives in the Cron Control module.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_cron_control( WP_REST_Request $request ): WP_REST_Response {
		$data    = (array) $request->get_json_params();
		$enabled = array_key_exists( 'enabled', $data ) ? filter_var( $data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;
		if ( null === $enabled ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'The "enabled" field must be a boolean.', 'ux-studio' ) ), 400 );
		}

		$result = CronControlModule::apply_central_command( $enabled );

		return new WP_REST_Response( $result, $result['success'] ? 200 : 409 );
	}

	/**
	 * Cursor-based pull of this site's reportable Bot Throttle bans (keyed-hash
	 * IP, no raw address) for the central app's fleet-wide correlation feed.
	 * Rows recorded before a fleet secret was configured (`report_hash` null)
	 * are permanently excluded - see `BotThrottle\Guard::report_hash()`.
	 *
	 * @param WP_REST_Request $request Request, `since` = last id already pulled.
	 */
	public function get_bot_throttle_bans( WP_REST_Request $request ): WP_REST_Response {
		$since = max( 0, (int) $request->get_param( 'since' ) );
		$items = BotThrottleLog::bans_since( $since, 200 );

		$last_id = $since;
		foreach ( $items as $row ) {
			$last_id = max( $last_id, (int) $row['id'] );
		}

		return new WP_REST_Response(
			array(
				'items'   => $items,
				'last_id' => $last_id,
			),
			200
		);
	}

	/**
	 * HMAC permission callback shared by every node route (and SsoController).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function verify( WP_REST_Request $request ) {
		$secret = Security::get_secret( Module::SECRET_NODE_KEY );
		return HmacAuth::verify_request( $request, $secret );
	}

	/**
	 * Capability announcement + author list for the hub.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function ping( WP_REST_Request $request ): WP_REST_Response {
		// When the central app last called (the onboarding wizard shows it). The site's own self-check does not count.
		if ( '' === (string) $request->get_header( 'X-UXS-Selfcheck' ) ) {
			update_option( self::LAST_PING_OPTION, time(), false );
		}

		$authors = get_users(
			array(
				'role__in' => array( 'administrator', 'editor', 'author' ),
				'fields'   => array( 'ID', 'display_name', 'user_login' ),
				'orderby'  => 'display_name',
			)
		);

		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$post_types[] = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'hierarchical' => (bool) $pt->hierarchical,
			);
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'site'       => home_url( '/' ),
				'acf_active' => AcfBridge::is_active(),
				'post_types' => $post_types,
				'authors'    => array_map(
					static fn ( $u ): array => array(
						'id'    => (int) $u->ID,
						'name'  => $u->display_name,
						'login' => $u->user_login,
					),
					$authors
				),
			),
			200
		);
	}

	/**
	 * List posts.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_posts( WP_REST_Request $request ): WP_REST_Response {
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) );
		$allowed   = get_post_types( array( 'public' => true ) );
		unset( $allowed['attachment'] );
		if ( ! isset( $allowed[ $post_type ] ) ) {
			$post_type = 'post';
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ),
			'paged'          => max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$search = $request->get_param( 'search' );
		if ( $search ) {
			$args['s'] = sanitize_text_field( (string) $search );
		}

		// Optional status filter (comma separated), restricted to known statuses.
		$status_param = sanitize_text_field( (string) $request->get_param( 'status' ) );
		if ( '' !== $status_param ) {
			$known    = array( 'publish', 'draft', 'pending', 'private', 'future' );
			$statuses = array_values( array_intersect( array_map( 'trim', explode( ',', $status_param ) ), $known ) );
			if ( ! empty( $statuses ) ) {
				$args['post_status'] = $statuses;
			}
		}

		$category = (int) $request->get_param( 'category' );
		if ( $category > 0 ) {
			$args['cat'] = $category;
		}

		// Custom taxonomy filter: ?taxonomy=slug&term_id=N.
		$taxonomy = sanitize_key( (string) $request->get_param( 'taxonomy' ) );
		$term_id  = (int) $request->get_param( 'term_id' );
		if ( '' !== $taxonomy && $term_id > 0 && taxonomy_exists( $taxonomy ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			);
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $p ) {
			$thumb_id = (int) get_post_thumbnail_id( $p->ID );
			$items[]  = array(
				'id'                 => $p->ID,
				'title'              => $p->post_title,
				'status'             => $p->post_status,
				'post_type'          => $p->post_type,
				'date'               => $p->post_date,
				'modified'           => $p->post_modified,
				'categories'         => wp_list_pluck( wp_get_post_categories( $p->ID, array( 'fields' => 'all' ) ), 'name' ),
				'author'             => get_the_author_meta( 'display_name', (int) $p->post_author ),
				'featured_image_url' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : null,
				'elementor'          => 'builder' === get_post_meta( $p->ID, '_elementor_edit_mode', true ),
			);
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $args['paged'],
			),
			200
		);
	}

	/**
	 * Fetch one post detail.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_post( WP_REST_Request $request ): WP_REST_Response {
		$post = get_post( (int) $request['id'] );
		if ( ! $this->is_editable_post( $post ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}
		return new WP_REST_Response( $this->format_post( $post ), 200 );
	}

	/**
	 * Create (or upsert-by-hub-origin) a post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_post( WP_REST_Request $request ): WP_REST_Response {
		$data = (array) $request->get_json_params();

		// Idempotency: if this hub post was already synced here, update it.
		$hub_id = sanitize_text_field( (string) ( $data['hub_post_id'] ?? '' ) );
		if ( '' !== $hub_id ) {
			$existing = $this->find_by_hub_id( $hub_id );
			if ( $existing > 0 ) {
				$request->set_param( 'id', $existing );
				return $this->update_post( $request );
			}
		}

		$post_type = sanitize_key( (string) ( $data['post_type'] ?? 'post' ) );
		$allowed   = get_post_types( array( 'public' => true ) );
		unset( $allowed['attachment'] );
		if ( ! isset( $allowed[ $post_type ] ) ) {
			$post_type = 'post';
		}

		$author = (int) ( $data['author_id'] ?? 0 );
		if ( $author <= 0 ) {
			$author = (int) Module::setting( 'node_default_author', 0 );
		}
		if ( $author <= 0 ) {
			$author = 1;
		}

		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'post_content' => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
			'post_status'  => $this->safe_status( (string) ( $data['status'] ?? 'draft' ) ),
			'post_excerpt' => sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ),
			'post_author'  => $author,
		);
		if ( isset( $data['post_parent'] ) ) {
			$post_data['post_parent'] = (int) $data['post_parent'];
		}
		if ( ! empty( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( (string) $data['slug'] );
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'message' => $post_id->get_error_message() ), 500 );
		}
		$post_id = (int) $post_id;

		$this->apply_terms_and_meta( $post_id, $data );
		if ( '' !== $hub_id ) {
			update_post_meta( $post_id, '_uxstudio_cs_hub_id', $hub_id );
		}

		ActivityLog::log( 'content-sync', 'node_create_post', 'post', $post_id );

		return new WP_REST_Response( $this->format_post( get_post( $post_id ) ), 201 );
	}

	/**
	 * Update a post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $this->is_editable_post( $post ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}

		$data      = (array) $request->get_json_params();
		$post_data = array( 'ID' => $post_id );
		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( (string) $data['content'] );
		}
		if ( isset( $data['status'] ) ) {
			$post_data['post_status'] = $this->safe_status( (string) $data['status'] );
		}
		if ( isset( $data['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( (string) $data['excerpt'] );
		}
		if ( isset( $data['post_parent'] ) ) {
			$post_data['post_parent'] = (int) $data['post_parent'];
		}
		if ( ! empty( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( (string) $data['slug'] );
		}

		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}

		$this->apply_terms_and_meta( $post_id, $data );

		ActivityLog::log( 'content-sync', 'node_update_post', 'post', $post_id );

		return new WP_REST_Response( $this->format_post( get_post( $post_id ) ), 200 );
	}

	/**
	 * Delete a post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $this->is_editable_post( $post ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}

		$force = (bool) $request->get_param( 'force' );
		if ( ! wp_delete_post( $post_id, $force ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Could not delete the post.', 'ux-studio' ) ), 500 );
		}

		ActivityLog::log( 'content-sync', 'node_delete_post', 'post', $post_id );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $post_id ), 200 );
	}

	/**
	 * List categories.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_categories( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->format_terms( 'category' ), 200 );
	}

	/**
	 * Create a category.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_category( WP_REST_Request $request ): WP_REST_Response {
		$data = (array) $request->get_json_params();
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_REST_Response( array( 'message' => __( 'Category name is required.', 'ux-studio' ) ), 400 );
		}

		$args = array( 'slug' => sanitize_title( (string) ( $data['slug'] ?? $name ) ) );
		if ( ! empty( $data['parent'] ) ) {
			$args['parent'] = (int) $data['parent'];
		}

		$result = wp_insert_term( $name, 'category', $args );
		if ( is_wp_error( $result ) ) {
			// An existing term with the same slug is a benign, idempotent outcome.
			$existing = get_term_by( 'slug', $args['slug'], 'category' );
			if ( $existing ) {
				return new WP_REST_Response( $this->term_shape( $existing ), 200 );
			}
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response( $this->term_shape( get_term( $result['term_id'], 'category' ) ), 201 );
	}

	/**
	 * Update a category.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_category( WP_REST_Request $request ): WP_REST_Response {
		$term_id = (int) $request['id'];
		$term    = get_term( $term_id, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->not_found( __( 'Category not found.', 'ux-studio' ) );
		}

		$data = (array) $request->get_json_params();
		$args = array();
		if ( isset( $data['name'] ) ) {
			$args['name'] = sanitize_text_field( (string) $data['name'] );
		}
		if ( isset( $data['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $data['slug'] );
		}
		if ( isset( $data['parent'] ) ) {
			$args['parent'] = (int) $data['parent'];
		}

		$result = wp_update_term( $term_id, 'category', $args );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response( $this->term_shape( get_term( $term_id, 'category' ) ), 200 );
	}

	/**
	 * Delete a category.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_category( WP_REST_Request $request ): WP_REST_Response {
		$term_id = (int) $request['id'];
		$term    = get_term( $term_id, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->not_found( __( 'Category not found.', 'ux-studio' ) );
		}
		if ( $term_id === (int) get_option( 'default_category' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Cannot delete the default category.', 'ux-studio' ) ), 400 );
		}

		$result = wp_delete_term( $term_id, 'category' );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $term_id ), 200 );
	}

	/**
	 * List terms of a taxonomy.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_terms( WP_REST_Request $request ): WP_REST_Response {
		$taxonomy = sanitize_key( (string) $request['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->not_found( __( 'Taxonomy not found.', 'ux-studio' ) );
		}
		return new WP_REST_Response( $this->format_terms( $taxonomy ), 200 );
	}

	/**
	 * Sideload an uploaded file into the media library.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function upload_media( WP_REST_Request $request ): WP_REST_Response {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_REST_Response( array( 'message' => __( 'No file was sent.', 'ux-studio' ) ), 400 );
		}
		$file = $files['file'];

		$finfo     = new \finfo( FILEINFO_MIME_TYPE );
		$real_mime = $finfo->file( $file['tmp_name'] );
		if ( ! in_array( $real_mime, get_allowed_mime_types(), true ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Disallowed file type.', 'ux-studio' ) ), 400 );
		}
		if ( (int) $file['size'] > 10 * 1024 * 1024 ) {
			return new WP_REST_Response( array( 'message' => __( 'File too large (max 10 MB).', 'ux-studio' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( $file['name'] ),
				'type'     => $real_mime,
				'tmp_name' => $file['tmp_name'],
				'error'    => $file['error'],
				'size'     => $file['size'],
			),
			0
		);
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_REST_Response( array( 'message' => $attachment_id->get_error_message() ), 500 );
		}

		ActivityLog::log( 'content-sync', 'node_upload_media', 'attachment', (int) $attachment_id );

		return new WP_REST_Response(
			array(
				'attachment_id' => (int) $attachment_id,
				'url'           => wp_get_attachment_url( (int) $attachment_id ),
				'filename'      => basename( (string) get_attached_file( (int) $attachment_id ) ),
			),
			201
		);
	}

	/**
	 * List image attachments, optionally only those with an empty ALT text
	 * (bulk ALT text generation from the hub) - paginated, oldest first so
	 * repeated batches make steady progress.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_media( WP_REST_Request $request ): WP_REST_Response {
		$per_page     = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page         = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$missing_alt  = ! empty( $request->get_param( 'missing_alt' ) );

		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => $missing_alt, // "total" (found_posts) is only reported when not filtering below.
		);
		if ( $missing_alt ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			);
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $attachment_id ) {
			$items[] = array(
				'id'         => (int) $attachment_id,
				'url'        => wp_get_attachment_url( (int) $attachment_id ),
				'title'      => get_the_title( $attachment_id ),
				'alt_text'   => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			);
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => $missing_alt ? null : (int) $query->found_posts,
			),
			200
		);
	}

	/**
	 * Update an attachment's ALT text (and/or title).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_media( WP_REST_Request $request ): WP_REST_Response {
		$attachment_id = (int) $request['id'];
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->not_found( __( 'Media not found.', 'ux-studio' ) );
		}

		$data = (array) $request->get_json_params();
		if ( isset( $data['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $data['alt_text'] ) );
		}
		if ( isset( $data['title'] ) ) {
			wp_update_post( array( 'ID' => $attachment_id, 'post_title' => sanitize_text_field( (string) $data['title'] ) ) );
		}

		ActivityLog::log( 'content-sync', 'node_update_media', 'attachment', $attachment_id );

		return new WP_REST_Response(
			array(
				'id'       => $attachment_id,
				'alt_text' => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			),
			200
		);
	}

	/**
	 * Delete an attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_media( WP_REST_Request $request ): WP_REST_Response {
		$attachment_id = (int) $request['id'];
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->not_found( __( 'Media not found.', 'ux-studio' ) );
		}
		if ( ! wp_delete_attachment( $attachment_id, true ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Could not delete the media.', 'ux-studio' ) ), 500 );
		}
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $attachment_id ), 200 );
	}

	/**
	 * Expose active ACF field groups for a post type.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_acf_field_groups( WP_REST_Request $request ): WP_REST_Response {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return new WP_REST_Response( array(), 200 );
		}
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) );
		$groups    = acf_get_field_groups( array( 'post_type' => $post_type ) );
		$out       = array();
		foreach ( (array) $groups as $group ) {
			if ( empty( $group['active'] ) ) {
				continue;
			}
			$fields = acf_get_fields( $group['key'] );
			$out[]  = array(
				'key'    => $group['key'],
				'title'  => $group['title'],
				'fields' => $this->format_acf_fields( is_array( $fields ) ? $fields : array() ),
			);
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Apply categories, custom taxonomies, featured image and ACF to a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Incoming payload.
	 */
	private function apply_terms_and_meta( int $post_id, array $data ): void {
		if ( isset( $data['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', (array) $data['categories'] ) );
		}
		if ( ! empty( $data['taxonomies'] ) && is_array( $data['taxonomies'] ) ) {
			foreach ( $data['taxonomies'] as $taxonomy => $term_ids ) {
				$tax = sanitize_key( (string) $taxonomy );
				if ( taxonomy_exists( $tax ) ) {
					wp_set_object_terms( $post_id, array_map( 'intval', (array) $term_ids ), $tax );
				}
			}
		}
		if ( array_key_exists( 'featured_image_id', $data ) ) {
			$thumb = (int) $data['featured_image_id'];
			if ( $thumb > 0 ) {
				set_post_thumbnail( $post_id, $thumb );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
		if ( ! empty( $data['acf'] ) && is_array( $data['acf'] ) ) {
			AcfBridge::apply( $post_id, $data['acf'] );
		}

		// SEO fields, when the hub sends them (e.g. the central app's AI
		// Agent / AI Rewriter drafts) - reuses the same storage +
		// SEO-plugin-detection as the local AI Assistant Content Creator,
		// so an AI-drafted meta title/description isn't silently dropped
		// on sites that already run Yoast/Rank Math/SEOPress.
		if ( ( isset( $data['meta_title'] ) || isset( $data['meta_desc'] ) || isset( $data['focus_keyword'] ) )
			&& class_exists( \UxStudio\Modules\AiAssistant\SeoManager::class ) ) {
			\UxStudio\Modules\AiAssistant\SeoManager::save_meta(
				$post_id,
				array(
					'seo_title'       => (string) ( $data['meta_title'] ?? '' ),
					'seo_description' => (string) ( $data['meta_desc'] ?? '' ),
					'seo_keywords'    => (string) ( $data['focus_keyword'] ?? '' ),
				)
			);
		}
	}

	/**
	 * Find a local post previously synced from a given hub origin id.
	 *
	 * @param string $hub_id Hub origin id.
	 */
	private function find_by_hub_id( string $hub_id ): int {
		$found = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => '_uxstudio_cs_hub_id',
						'value' => $hub_id,
					),
				),
			)
		);
		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Format a post for detail responses.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function format_post( WP_Post $post ): array {
		$thumb = get_post_thumbnail_id( $post->ID );
		$data  = array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'content'            => $post->post_content,
			'excerpt'            => $post->post_excerpt,
			'status'             => $post->post_status,
			'post_type'          => $post->post_type,
			'date'               => $post->post_date,
			'modified'           => $post->post_modified,
			'link'               => get_permalink( $post->ID ),
			'category_ids'       => wp_get_post_categories( $post->ID ),
			'author_id'          => (int) $post->post_author,
			'featured_image_id'  => $thumb ? (int) $thumb : null,
			'featured_image_url' => $thumb ? wp_get_attachment_url( $thumb ) : null,
			'post_parent'        => (int) $post->post_parent,
		);
		if ( function_exists( 'get_fields' ) ) {
			$data['acf'] = AcfBridge::read( $post->ID );
		}
		return $data;
	}

	/**
	 * Format all terms of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map( array( $this, 'term_shape' ), $terms );
	}

	/**
	 * Shape a term object.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	private function term_shape( $term ): array {
		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
		);
	}

	/**
	 * Format ACF field definitions (recursive for group/repeater).
	 *
	 * @param array $fields Field defs.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_acf_fields( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$item = array(
				'key'   => $field['key'] ?? '',
				'name'  => $field['name'] ?? '',
				'label' => $field['label'] ?? '',
				'type'  => $field['type'] ?? 'text',
			);
			if ( ! empty( $field['choices'] ) ) {
				$item['choices'] = $field['choices'];
			}
			if ( isset( $field['required'] ) ) {
				$item['required'] = (bool) $field['required'];
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$item['sub_fields'] = $this->format_acf_fields( $field['sub_fields'] );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Clamp an incoming status to a known-safe set.
	 *
	 * @param string $status Requested status.
	 */
	private function safe_status( string $status ): string {
		$allowed = array( 'publish', 'draft', 'pending', 'private', 'future' );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Whether a post exists and is a normal editable post (not a revision/attachment).
	 *
	 * @param WP_Post|null $post Post.
	 */
	private function is_editable_post( ?WP_Post $post ): bool {
		return $post instanceof WP_Post && 'revision' !== $post->post_type && 'attachment' !== $post->post_type;
	}

	/**
	 * 404 response.
	 *
	 * @param string $message Message.
	 */
	private function not_found( string $message ): WP_REST_Response {
		return new WP_REST_Response( array( 'message' => $message ), 404 );
	}
}
