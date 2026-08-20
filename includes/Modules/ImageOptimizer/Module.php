<?php
/**
 * Image Optimizer module - compress media library images in place and emit
 * WebP siblings.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy image-optimizer module as a group-C
 * module. Only ever touches files inside wp_upload_dir()['basedir'] (see
 * Optimizer::resolve_safe_path()) - never external URLs, never arbitrary
 * paths. Bulk runs are processed in small batches via WP-Cron single events
 * (uxstudio_io_process_batch), never in one long-running request.
 */
final class Module extends BaseModule {

	private const CRON_HOOK    = 'uxstudio_io_process_batch';
	private const QUEUE_OPTION = 'uxstudio_io_queue';
	private const TOTAL_OPTION = 'uxstudio_io_total';
	private const BATCH_SIZE   = 5;

	private Optimizer $optimizer;

	/**
	 * @param string $id   Module id.
	 * @param array  $meta meta.json contents.
	 */
	public function __construct( string $id, array $meta ) {
		parent::__construct( $id, $meta );
		$this->optimizer = new Optimizer();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( self::CRON_HOOK, array( $this, 'process_batch' ) );
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
				'key'     => 'quality',
				'type'    => 'number',
				'label'   => __( 'Quality', 'ux-studio' ),
				'help'    => __( 'JPEG/WebP compression quality, 1-100.', 'ux-studio' ),
				'default' => 82,
			),
			array(
				'key'     => 'convert_to_webp',
				'type'    => 'toggle',
				'label'   => __( 'Also generate WebP', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'max_width',
				'type'    => 'number',
				'label'   => __( 'Max width (px)', 'ux-studio' ),
				'help'    => __( 'Images wider than this are downscaled. 0 disables resizing.', 'ux-studio' ),
				'default' => 2000,
			),
		);
	}

	// =====================================================================
	// Single-image operations
	// =====================================================================

	/**
	 * Optimize a single attachment using the current settings.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function optimize( int $attachment_id ) {
		$result = $this->optimizer->optimize(
			$attachment_id,
			(int) $this->settings->get( 'quality', 82 ),
			(bool) $this->settings->get( 'convert_to_webp', true ),
			(int) $this->settings->get( 'max_width', 2000 )
		);

		if ( ! is_wp_error( $result ) ) {
			ActivityLog::log( 'image-optimizer', 'optimize', 'attachment', $attachment_id );
		}

		return $result;
	}

	/**
	 * Restore an attachment's original from its backup.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function restore( int $attachment_id ) {
		$result = $this->optimizer->restore( $attachment_id );

		if ( ! is_wp_error( $result ) ) {
			ActivityLog::log( 'image-optimizer', 'restore', 'attachment', $attachment_id );
		}

		return $result;
	}

	// =====================================================================
	// Bulk queue
	// =====================================================================

	/**
	 * Build the queue of not-yet-optimized image attachments and schedule the
	 * first batch. Safe to call again while a run is in progress (re-syncs
	 * the queue with anything still missing the optimized-size meta).
	 *
	 * @return array{queued:int,total:int}
	 */
	public function bulk_start(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Optimizer::META_OPTIMIZED_SIZE,
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows'  => true,
			)
		);

		$ids = array_map( 'intval', $ids );

		update_option( self::QUEUE_OPTION, $ids, false );
		update_option( self::TOTAL_OPTION, count( $ids ), false );

		if ( ! empty( $ids ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}

		return array( 'queued' => count( $ids ), 'total' => count( $ids ) );
	}

	/**
	 * Current bulk progress.
	 *
	 * @return array{queued:int,total:int,done:int,running:bool}
	 */
	public function bulk_status(): array {
		$queue   = (array) get_option( self::QUEUE_OPTION, array() );
		$total   = (int) get_option( self::TOTAL_OPTION, 0 );
		$queued  = count( $queue );
		return array(
			'queued'  => $queued,
			'total'   => $total,
			'done'    => max( 0, $total - $queued ),
			'running' => $queued > 0,
		);
	}

	/**
	 * Process one batch of the queue (hooked on the cron event); reschedules
	 * itself if items remain.
	 */
	public function process_batch(): void {
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}

		$batch = array_splice( $queue, 0, self::BATCH_SIZE );
		foreach ( $batch as $attachment_id ) {
			$this->optimize( (int) $attachment_id );
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! empty( $queue ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}
}
