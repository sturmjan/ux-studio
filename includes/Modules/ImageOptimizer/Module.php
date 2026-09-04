<?php
/**
 * Image Optimizer module - compress media library images in place, emit
 * WebP/AVIF siblings, serve next-gen variants via .htaccess, auto-optimize on
 * upload and surface unused images for recoverable cleanup.
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
 * Optimizer::resolve_safe_path() and HtaccessDelivery) - never external URLs,
 * never arbitrary paths. Bulk runs are processed in small batches via WP-Cron
 * single events (uxstudio_io_process_batch), never in one long-running request.
 * AVIF encoding is feature-detected and degrades to a no-op where unsupported.
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

		if ( (bool) $this->settings->get( 'auto_optimize', false ) ) {
			// Runs after WP has generated the attachment metadata/thumbnails, so
			// the file is final on disk. Filter: must return metadata untouched.
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_optimize_metadata' ), 20, 2 );
		}
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
				'help'    => __( 'JPEG/WebP/AVIF compression quality, 1-100.', 'ux-studio' ),
				'default' => 82,
			),
			array(
				'key'     => 'max_width',
				'type'    => 'number',
				'label'   => __( 'Max width (px)', 'ux-studio' ),
				'help'    => __( 'Images wider than this are downscaled. 0 disables resizing.', 'ux-studio' ),
				'default' => 2000,
			),
			array(
				'key'     => 'convert_to_webp',
				'type'    => 'toggle',
				'label'   => __( 'Also generate WebP', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'convert_to_avif',
				'type'    => 'toggle',
				'label'   => __( 'Also generate AVIF', 'ux-studio' ),
				'help'    => __( 'AVIF is smaller than WebP but requires server support. Ignored automatically when the server cannot encode AVIF.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'auto_optimize',
				'type'    => 'toggle',
				'label'   => __( 'Optimize new uploads automatically', 'ux-studio' ),
				'help'    => __( 'Compress and generate next-gen siblings whenever an image is uploaded.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'htaccess_delivery',
				'type'    => 'toggle',
				'label'   => __( 'Serve next-gen images via .htaccess', 'ux-studio' ),
				'help'    => __( 'Adds Apache/LiteSpeed rewrite rules to uploads/.htaccess so browsers get AVIF/WebP when available. Removed when disabled. No effect on nginx.', 'ux-studio' ),
				'default' => false,
			),
		);
	}

	/**
	 * Persist settings, then reconcile the .htaccess delivery block with the
	 * new webp/avif/delivery toggles (write when enabled, remove otherwise).
	 *
	 * @param array $input Raw input from REST.
	 * @return array<string, mixed> Stored values.
	 */
	public function save_settings( array $input ): array {
		$values = parent::save_settings( $input );
		$this->sync_htaccess();
		return $values;
	}

	/**
	 * Write or remove the uploads/.htaccess delivery rules based on settings.
	 */
	private function sync_htaccess(): void {
		$delivery = new HtaccessDelivery();
		$enabled  = (bool) $this->settings->get( 'htaccess_delivery', false );
		$webp     = (bool) $this->settings->get( 'convert_to_webp', true ) && Optimizer::supports_webp();
		$avif     = (bool) $this->settings->get( 'convert_to_avif', false ) && Optimizer::supports_avif();

		if ( $enabled && ( $webp || $avif ) ) {
			$delivery->write( $webp, $avif );
		} else {
			$delivery->remove();
		}
	}

	// =====================================================================
	// Capability / status
	// =====================================================================

	/**
	 * Runtime capabilities + delivery status for the SPA.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		return array(
			'supports_webp' => Optimizer::supports_webp(),
			'supports_avif' => Optimizer::supports_avif(),
			'delivery'      => ( new HtaccessDelivery() )->status(),
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
			(int) $this->settings->get( 'max_width', 2000 ),
			(bool) $this->settings->get( 'convert_to_avif', false )
		);

		if ( ! is_wp_error( $result ) ) {
			ActivityLog::log( 'image-optimizer', 'optimize', 'attachment', $attachment_id );
		}

		return $result;
	}

	/**
	 * Auto-optimize hook: optimize a freshly uploaded image, keeping the stored
	 * metadata dimensions consistent when the full-size file was downscaled.
	 * Guards against re-processing already-optimized attachments.
	 *
	 * @param array $metadata      Attachment metadata (returned unchanged bar dims).
	 * @param int   $attachment_id Attachment post id.
	 * @return array Metadata.
	 */
	public function auto_optimize_metadata( $metadata, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		if ( '' !== (string) get_post_meta( $attachment_id, Optimizer::META_OPTIMIZED_SIZE, true ) ) {
			return $metadata;
		}

		$result = $this->optimize( $attachment_id );
		if ( is_wp_error( $result ) ) {
			return $metadata;
		}

		// Keep metadata dimensions in sync if the master file was resized.
		$file = get_attached_file( $attachment_id );
		if ( $file && is_file( $file ) ) {
			$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $size ) ) {
				$metadata['width']  = (int) $size[0];
				$metadata['height'] = (int) $size[1];
			}
		}

		return $metadata;
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
	// Unused images scanner
	// =====================================================================

	/**
	 * Paginated listing of unused images.
	 *
	 * @param int  $page    1-based page.
	 * @param bool $refresh Force a re-scan.
	 */
	public function scan_unused( int $page = 1, bool $refresh = false ): array {
		return ( new UnusedScanner() )->listing( $page, 40, $refresh );
	}

	/**
	 * Move the given unused images to trash (recoverable).
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array{trashed:int,skipped:int}
	 */
	public function trash_unused( array $ids ): array {
		$result = ( new UnusedScanner() )->trash_ids( $ids );
		if ( $result['trashed'] > 0 ) {
			ActivityLog::log( 'image-optimizer', 'trash-unused', 'attachment', 0, $result );
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
