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
	private const BACKUP_OPTION = 'uxstudio_io_backup';
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

		if ( is_admin() ) {
			( new MediaLibrary( $this, $this->optimizer ) )->register();
		}

		if ( (bool) $this->settings->get( 'auto_optimize', false ) ) {
			// Runs after WP has generated the attachment metadata/thumbnails, so
			// the file is final on disk. Filter: must return metadata untouched.
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_optimize_metadata' ), 20, 2 );
		}

		// Backups and next-gen siblings next to an attachment go away with it.
		add_action( 'delete_attachment', array( $this, 'cleanup_attachment' ) );

		$mode = $this->delivery_mode();
		if ( 'htaccess' === $mode ) {
			// on_disable() strips the delivery block; after re-enabling, restore it
			// (admin only - is_active() reads uploads/.htaccess).
			add_action( 'admin_init', array( $this, 'restore_htaccess_delivery' ) );
		} elseif ( 'picture' === $mode ) {
			( new PictureDelivery() )->register();
		}

		if ( (bool) $this->settings->get( 'convert_heic', false ) && Optimizer::supports_heic() ) {
			add_filter( 'upload_mimes', array( $this, 'allow_heic_upload' ) );
			add_filter( 'wp_handle_upload', array( new HeicConverter( $this->general_quality() ), 'convert' ), 10, 2 );
		}
	}

	/**
	 * How next-gen images reach visitors: 'htaccess' (rewrite rules in
	 * uploads/.htaccess), 'picture' (<picture> markup) or 'off'. Sites that saved
	 * the old on/off toggle (htaccess_delivery) keep their behaviour until the
	 * new setting is saved.
	 */
	public function delivery_mode(): string {
		$all  = $this->settings->all();
		$mode = $all['delivery_mode'] ?? null;
		if ( is_string( $mode ) && in_array( $mode, array( 'htaccess', 'picture', 'off' ), true ) ) {
			return $mode;
		}

		return ! empty( $all['htaccess_delivery'] ) ? 'htaccess' : 'off';
	}

	/**
	 * Filter upload_mimes: let HEIC/HEIF through (older WordPress rejects them)
	 * when they will be converted to JPEG.
	 *
	 * @param array<string, string> $mimes Allowed mime types.
	 * @return array<string, string>
	 */
	public function allow_heic_upload( $mimes ) {
		if ( ! is_array( $mimes ) ) {
			return $mimes;
		}
		$mimes['heic'] = $mimes['heic'] ?? 'image/heic';
		$mimes['heif'] = $mimes['heif'] ?? 'image/heif';

		return $mimes;
	}

	/**
	 * General quality (1-100) from the settings.
	 */
	private function general_quality(): int {
		return max( 1, min( 100, (int) $this->settings->get( 'quality', 82 ) ) );
	}

	/**
	 * delete_attachment: remove our backups and siblings of the attachment.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function cleanup_attachment( $attachment_id ): void {
		$this->optimizer->delete_extras( (int) $attachment_id );
	}

	/**
	 * Whether the attachment is excluded from automatic and bulk optimization
	 * (setting "excluded": attachment IDs or file name patterns with *).
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function is_excluded( int $attachment_id ): bool {
		$rules = preg_split( '/\r\n|\r|\n/', (string) $this->settings->get( 'excluded', '' ) );
		if ( ! is_array( $rules ) ) {
			return false;
		}

		$file   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$base   = wp_basename( $file );
		$no_big = (string) preg_replace( '/-scaled(\.[^.]+)$/', '$1', $base );

		foreach ( $rules as $rule ) {
			$rule = trim( $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( ctype_digit( $rule ) ) {
				if ( (int) $rule === $attachment_id ) {
					return true;
				}
				continue;
			}
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '$#i';
			if ( 1 === preg_match( $regex, $base ) || 1 === preg_match( $regex, $no_big ) || 1 === preg_match( $regex, $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Module switched off / plugin deactivated: stop serving next-gen images
	 * via uploads/.htaccess (the rules would otherwise outlive the module) and
	 * drop any pending bulk-optimize batch. Settings and the queue are kept.
	 */
	public function on_disable(): void {
		( new HtaccessDelivery() )->purge();
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * admin_init: re-write the delivery block if it is enabled in settings but
	 * missing from uploads/.htaccess (e.g. after the module was re-enabled).
	 */
	public function restore_htaccess_delivery(): void {
		if ( ! ( new HtaccessDelivery() )->is_active() ) {
			$this->sync_htaccess();
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
				'help'    => __( 'JPEG/WebP/AVIF compression quality, 1-100. Used for every format that has no quality of its own below.', 'ux-studio' ),
				'default' => 82,
			),
			array(
				'key'     => 'jpeg_quality',
				'type'    => 'number',
				'label'   => __( 'JPEG quality', 'ux-studio' ),
				'help'    => __( '1-100. 0 = use the general Quality.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'webp_quality',
				'type'    => 'number',
				'label'   => __( 'WebP quality', 'ux-studio' ),
				'help'    => __( '1-100. 0 = use the general Quality.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'avif_quality',
				'type'    => 'number',
				'label'   => __( 'AVIF quality', 'ux-studio' ),
				'help'    => __( '1-100. 0 = use the general Quality. AVIF reaches the same look at a lower number than JPEG.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'max_width',
				'type'    => 'number',
				'label'   => __( 'Max width (px)', 'ux-studio' ),
				'help'    => __( 'Images wider than this are downscaled. 0 disables resizing.', 'ux-studio' ),
				'default' => 2000,
			),
			array(
				'key'     => 'max_height',
				'type'    => 'number',
				'label'   => __( 'Max height (px)', 'ux-studio' ),
				'help'    => __( 'Images taller than this are downscaled (aspect ratio is kept, together with the max width). 0 = no height limit.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'convert_png_to_jpg',
				'type'    => 'toggle',
				'label'   => __( 'Convert PNG to JPEG', 'ux-studio' ),
				'help'    => __( 'PNG images without transparency are saved as JPEG, which is usually much smaller. The file name changes (.png becomes .jpg), so links to an already published PNG stop working - best used together with automatic optimization of new uploads. The PNG is kept as the backup and Restore brings it back.', 'ux-studio' ),
				'default' => false,
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
				'key'     => 'thumbnail_sizes',
				'type'    => 'multiselect',
				'label'   => __( 'Thumbnail sizes to optimize', 'ux-studio' ),
				'help'    => __( 'The smaller copies WordPress makes of every image are compressed too, and get WebP/AVIF copies. Pick the sizes to process. Nothing selected = all sizes.', 'ux-studio' ),
				'options' => $this->thumbnail_size_options(),
				'default' => array(),
			),
			array(
				'key'     => 'backup_originals',
				'type'    => 'toggle',
				'label'   => __( 'Keep a backup of the original images', 'ux-studio' ),
				'help'    => __( 'Before an image is compressed, the untouched file is copied next to it. Only with a backup can Restore bring the original back. Backups take disk space. Used for new uploads; the bulk run asks again before it starts.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'auto_optimize',
				'type'    => 'toggle',
				'label'   => __( 'Optimize new uploads automatically', 'ux-studio' ),
				'help'    => __( 'Compress and generate next-gen siblings whenever an image is uploaded.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'convert_heic',
				'type'    => 'toggle',
				'label'   => __( 'Convert HEIC photos to JPEG on upload', 'ux-studio' ),
				'help'    => __( 'iPhone photos in HEIC format are saved as JPEG while they are uploaded. Needs a server whose Imagick can read HEIC, otherwise nothing changes.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'excluded',
				'type'    => 'textarea',
				'label'   => __( 'Excluded images', 'ux-studio' ),
				'help'    => __( 'One per line: an attachment ID, or a file name with * as a wildcard (for example logo-*.png). These images are skipped by automatic and bulk optimization.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'delivery_mode',
				'type'    => 'select',
				'label'   => __( 'Serve next-gen images', 'ux-studio' ),
				'help'    => __( 'How visitors get the WebP/AVIF copies. .htaccess: rewrite rules in uploads/.htaccess (Apache/LiteSpeed). Picture tag: images in pages are wrapped in a picture element, which also works on nginx and behind a CDN.', 'ux-studio' ),
				'options' => array(
					'off'      => __( 'Off', 'ux-studio' ),
					'htaccess' => __( '.htaccess rewrite rules', 'ux-studio' ),
					'picture'  => __( 'Picture tag in the page', 'ux-studio' ),
				),
				'default' => 'off',
			),
		);
	}

	/**
	 * Registered image sizes as multiselect options (name => "name (W x H)").
	 *
	 * @return array<string, string>
	 */
	private function thumbnail_size_options(): array {
		$options = array();
		foreach ( wp_get_registered_image_subsizes() as $name => $data ) {
			$options[ (string) $name ] = sprintf( '%1$s (%2$d x %3$d)', $name, (int) $data['width'], (int) $data['height'] );
		}

		return $options;
	}

	/**
	 * Read a setting, falling back to the key the legacy UX1 module used for it
	 * (the migrator copies legacy values verbatim, e.g. enable_webp).
	 *
	 * @param string $key        Current key.
	 * @param string $legacy_key Legacy key.
	 * @param mixed  $default    Default when neither is stored.
	 * @return mixed
	 */
	public function setting( string $key, string $legacy_key, $default ) {
		$all = $this->settings->all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		if ( array_key_exists( $legacy_key, $all ) ) {
			return $all[ $legacy_key ];
		}

		return $default;
	}

	/**
	 * Current values; settings migrated from the legacy module (enable_webp /
	 * enable_avif) show up under the current keys until saved again.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_values(): array {
		$values                  = parent::settings_values();
		$all                     = $this->settings->all();
		$values['delivery_mode'] = $this->delivery_mode();
		foreach ( array( 'convert_to_webp' => 'enable_webp', 'convert_to_avif' => 'enable_avif' ) as $key => $legacy ) {
			if ( ! array_key_exists( $key, $all ) && array_key_exists( $legacy, $all ) ) {
				$values[ $key ] = (bool) $all[ $legacy ];
			}
		}

		return $values;
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
		$enabled  = 'htaccess' === $this->delivery_mode();
		$webp     = (bool) $this->setting( 'convert_to_webp', 'enable_webp', true ) && Optimizer::supports_webp();
		$avif     = (bool) $this->setting( 'convert_to_avif', 'enable_avif', false ) && Optimizer::supports_avif();

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
			'supports_heic' => Optimizer::supports_heic(),
			'delivery'      => array_merge( ( new HtaccessDelivery() )->status(), array( 'mode' => $this->delivery_mode() ) ),
		);
	}

	// =====================================================================
	// Single-image operations
	// =====================================================================

	/**
	 * Optimize a single attachment using the current settings.
	 *
	 * @param int        $attachment_id Attachment post id.
	 * @param array|null $metadata      Metadata to use instead of the stored one (upload in progress).
	 * @param float      $deadline      Microtime after which the thumbnail loop pauses (0 = no limit).
	 * @param bool       $thumbs_only   Leave the main file alone, only process thumbnails.
	 * @param bool|null  $backup        Keep a backup of the originals; null = the setting.
	 */
	public function optimize( int $attachment_id, ?array $metadata = null, float $deadline = 0.0, bool $thumbs_only = false, ?bool $backup = null ) {
		$general = $this->general_quality();
		$resize  = (bool) $this->setting( 'enable_resize', 'enable_resize', true );

		$result = $this->optimizer->optimize(
			$attachment_id,
			$general,
			(bool) $this->setting( 'convert_to_webp', 'enable_webp', true ),
			$resize ? (int) $this->settings->get( 'max_width', 2000 ) : 0,
			(bool) $this->setting( 'convert_to_avif', 'enable_avif', false ),
			array(
				'max_height'      => $resize ? (int) $this->settings->get( 'max_height', 0 ) : 0,
				'png_to_jpg'      => (bool) $this->settings->get( 'convert_png_to_jpg', false ),
				'jpeg_quality'    => (int) $this->settings->get( 'jpeg_quality', 0 ),
				'webp_quality'    => (int) $this->settings->get( 'webp_quality', 0 ),
				'avif_quality'    => (int) $this->settings->get( 'avif_quality', 0 ),
				'thumbnail_sizes' => (array) $this->settings->get( 'thumbnail_sizes', array() ),
				'metadata'        => $metadata,
				'deadline'        => $deadline,
				'thumbs_only'     => $thumbs_only,
				'backup'          => null === $backup ? (bool) $this->settings->get( 'backup_originals', true ) : $backup,
			)
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

		if ( $this->is_excluded( $attachment_id ) ) {
			return $metadata;
		}

		$result = $this->optimize( $attachment_id, $metadata );
		if ( is_wp_error( $result ) ) {
			return $metadata;
		}

		// PNG -> JPEG changed the file: the optimizer already rebuilt the metadata.
		if ( ! empty( $result['converted'] ) ) {
			$fresh = wp_get_attachment_metadata( $attachment_id );
			return is_array( $fresh ) ? $fresh : $metadata;
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
	 * the queue with anything still missing the optimized-size meta). Images
	 * optimized before thumbnails were handled are queued again, but only for
	 * their thumbnails. Excluded images are left out. The backup choice is stored
	 * with the queue, so the whole run follows it.
	 *
	 * @param bool|null $backup Keep backups of the originals; null = the setting.
	 * @return array{queued:int,total:int,backup:bool}
	 */
	public function bulk_start( ?bool $backup = null ): array {
		$query = array(
			'post_type'      => 'attachment',
			'post_mime_type' => Optimizer::SUPPORTED_MIME,
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		$fresh = get_posts(
			$query + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Optimizer::META_OPTIMIZED_SIZE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$thumbs = get_posts(
			$query + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => Optimizer::META_OPTIMIZED_SIZE,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Optimizer::META_THUMBS,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$ids = array_values(
			array_filter(
				array_unique( array_map( 'intval', array_merge( $fresh, $thumbs ) ) ),
				fn( int $id ): bool => ! $this->is_excluded( $id )
			)
		);

		update_option( self::QUEUE_OPTION, $ids, false );
		update_option( self::TOTAL_OPTION, count( $ids ), false );
		// Stored as 1/0: update_option() ignores a first-time false.
		update_option( self::BACKUP_OPTION, ( null === $backup ? (bool) $this->settings->get( 'backup_originals', true ) : $backup ) ? 1 : 0, false );

		if ( ! empty( $ids ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}

		return array( 'queued' => count( $ids ), 'total' => count( $ids ), 'backup' => (bool) (int) get_option( self::BACKUP_OPTION, 1 ) );
	}

	/**
	 * Add images to the bulk queue without rebuilding it (the migration wizard queues the images it took
	 * over, so they get their WebP/AVIF copies). Needs no module instance; the queue is only worked off
	 * while the module is on.
	 *
	 * @param int[] $ids Attachment ids.
	 */
	public static function enqueue_ids( array $ids ): void {
		$queue = array_map( 'intval', (array) get_option( self::QUEUE_OPTION, array() ) );
		$new   = array_values( array_diff( array_map( 'intval', $ids ), $queue ) );
		if ( empty( $new ) ) {
			return;
		}
		update_option( self::QUEUE_OPTION, array_merge( $queue, $new ), false );
		update_option( self::TOTAL_OPTION, (int) get_option( self::TOTAL_OPTION, 0 ) + count( $new ), false );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Current bulk progress.
	 *
	 * @return array{queued:int,total:int,done:int,running:bool,backup:bool}
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
			'backup'  => (bool) (int) get_option( self::BACKUP_OPTION, 1 ),
		);
	}

	/**
	 * Process one batch of the queue (hooked on the cron event); reschedules
	 * itself if items remain. A batch is at most BATCH_SIZE images and stops
	 * earlier once the time budget is used up; an image whose thumbnails did not
	 * fit in the budget goes back to the front of the queue and continues in the
	 * next batch (the optimizer remembers which thumbnails are done).
	 */
	public function process_batch(): void {
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}

		$max      = (int) ini_get( 'max_execution_time' );
		$deadline = microtime( true ) + ( $max > 0 ? min( 20.0, $max * 0.5 ) : 20.0 );
		$handled  = 0;

		while ( ! empty( $queue ) && $handled < self::BATCH_SIZE && ( 0 === $handled || microtime( true ) < $deadline ) ) {
			$attachment_id = (int) array_shift( $queue );
			++$handled;
			if ( $this->is_excluded( $attachment_id ) ) {
				continue;
			}

			$thumbs_only = '' !== (string) get_post_meta( $attachment_id, Optimizer::META_OPTIMIZED_SIZE, true )
				&& '' === (string) get_post_meta( $attachment_id, Optimizer::META_THUMBS, true );
			$result      = $this->optimize( $attachment_id, null, $deadline, $thumbs_only, (bool) (int) get_option( self::BACKUP_OPTION, 1 ) );
			if ( ! is_wp_error( $result ) && ! empty( $result['thumbs_pending'] ) ) {
				array_unshift( $queue, $attachment_id );
			}
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! empty( $queue ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}
}
