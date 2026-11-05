<?php
/**
 * Source: Squeeze (image compression).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\ImageOptimizer\ForeignOptimizers;
use UxStudio\Modules\ImageOptimizer\MediaLibrary;
use UxStudio\Modules\ImageOptimizer\Module as ImageOptimizerModule;
use UxStudio\Modules\ImageOptimizer\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `image-optimizer`. Squeeze compressed the images (main file and thumbnails) and kept the
 * untouched originals next to them as "<name>.bak.<ext>". The wizard never compresses those images again:
 *
 *  - every attachment with the meta `squeeze_is_compressed` gets `_uxstudio_io_precompressed` = squeeze, so the
 *    optimizer, the bulk run and auto-optimize only make WebP/AVIF copies of it and leave the files as they are,
 *  - the optimization stats (size before = the .bak, size now = the file plus its thumbnails) are written,
 *  - option "keep_backups" off deletes the .bak files of the migrated images (only inside uploads, never in a
 *    dry run) and reports the freed space,
 *  - option "generate_webp" queues the WebP/AVIF run for the migrated images.
 *
 * Settings: Squeeze `jpeg_quality` / `webp_quality` fill the module's own JPEG / WebP quality only while those
 * are still at their default (0 = use the general quality); `auto_compress` becomes "Optimize new uploads automatically".
 *
 * HARMLESS: marking images and writing stats changes nothing visible, so the data phase is written directly.
 * Idempotent: an image that is already marked is reported as already there.
 */
final class SqueezeSource extends AbstractSource {

	private const MODULE = 'image-optimizer';

	private const META = 'squeeze_is_compressed';

	private const FREED_OPTION = 'uxstudio_io_wizard_freed';

	protected const BATCH = 200;

	/** @var int Bytes a dry run would free (a dry run is one request, so a property is enough). */
	private static int $would_free = 0;

	protected function option_names(): array {
		return array( 'squeeze_options' );
	}

	protected function has_data(): bool {
		return $this->count_rows() > 0 || parent::has_data();
	}

	public function options(): array {
		return array(
			array(
				'key'     => 'keep_backups',
				'label'   => __( 'Keep the original backups made by Squeeze (they allow Restore, but take disk space)', 'ux-studio' ),
				'help'    => __( 'Squeeze saved the untouched original of every image next to it as name.bak.jpg. Switch this off to delete those files and free the space. After that the images cannot be restored to their originals.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'generate_webp',
				'label'   => __( 'Create the WebP/AVIF copies right away', 'ux-studio' ),
				'help'    => __( 'Queues a run that makes the WebP/AVIF copies of the main file and every thumbnail of the migrated images. The images themselves are not compressed again.', 'ux-studio' ),
				'default' => true,
			),
		);
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'squeeze_options' );
		if ( ! is_array( $o ) ) {
			if ( $this->count_rows() > 0 ) {
				$m->note( __( 'The compressed images are taken over without being compressed again; only their WebP/AVIF copies are made.', 'ux-studio' ) );
			} else {
				$m->note( __( 'The plugin has no settings or compressed images, there is nothing to transfer.', 'ux-studio' ) );
			}
			return $m;
		}

		$ours = (array) get_option( 'uxstudio_image_optimizer', array() );
		foreach ( array(
			'jpeg_quality' => __( 'JPEG quality', 'ux-studio' ),
			'webp_quality' => __( 'WebP quality', 'ux-studio' ),
		) as $key => $label ) {
			$value = isset( $o[ $key ] ) ? (int) $o[ $key ] : 0;
			if ( $value >= 1 && $value <= 100 && (int) ( $ours[ $key ] ?? 0 ) <= 0 ) {
				$m->set( self::MODULE, $key, $value, $label, 'squeeze_options:' . $key );
			}
		}
		if ( ! empty( $o['auto_compress'] ) ) {
			$m->set( self::MODULE, 'auto_optimize', true, __( 'Optimize new uploads automatically', 'ux-studio' ), 'squeeze_options:auto_compress' );
		}
		$m->lossy( __( 'Which thumbnail sizes Squeeze compressed is not transferred: the images it compressed are marked and never compressed again, new images follow the module setting "Thumbnail sizes to optimize".', 'ux-studio' ) );
		$m->note( __( 'The compressed images are taken over without being compressed again; only their WebP/AVIF copies are made.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * Attachments Squeeze compressed.
	 */
	private function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", self::META ) ); // phpcs:ignore WordPress.DB
	}

	protected function data_kind(): string {
		return 'image';
	}

	protected function data_label(): string {
		return __( 'Compressed images', 'ux-studio' );
	}

	protected function data_total( array $options ): int {
		return $this->count_rows();
	}

	protected function data_plan( array $options ): array {
		return array(
			array(
				'key'   => 'image',
				'label' => __( 'Compressed images', 'ux-studio' ),
				'count' => $this->count_rows(),
			),
		);
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		global $wpdb;
		$after = (int) $position;
		if ( 0 === $after ) {
			self::$would_free = 0;
			if ( null === $position ) {
				// First batch of a run: the counter of freed space starts again.
				delete_option( self::FREED_OPTION );
			}
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' AND post_id > %d ORDER BY post_id ASC LIMIT %d", self::META, $after, $limit ) ); // phpcs:ignore WordPress.DB
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

		$items = array();
		foreach ( $ids as $id ) {
			$items[] = array(
				'key'   => 'att:' . $id,
				'label' => (string) get_the_title( $id ),
				'id'    => $id,
			);
		}

		return array(
			'items' => $items,
			'next'  => empty( $ids ) ? $after : (int) end( $ids ),
			'done'  => count( $ids ) < $limit,
		);
	}

	/**
	 * Absolute path of the attachment's file when it exists inside uploads, else null.
	 *
	 * @param int $id Attachment id.
	 */
	private function safe_path( int $id ): ?string {
		$path = get_attached_file( $id );
		$base = realpath( wp_upload_dir()['basedir'] );
		$real = $path ? realpath( $path ) : false;
		if ( false === $real || false === $base || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $base ) . '/' ) ) {
			return null;
		}
		return $real;
	}

	/**
	 * Squeeze backup of a file, only when it really is a ".bak." sidecar inside uploads.
	 *
	 * @param string $path Absolute main file path.
	 */
	private function backup_of( string $path ): ?string {
		$bak  = ForeignOptimizers::squeeze_backup( $path );
		$base = realpath( wp_upload_dir()['basedir'] );
		$real = null === $bak ? false : realpath( $bak );
		if ( false === $real || false === $base || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $base ) . '/' ) || false === strpos( basename( $real ), '.bak.' ) ) {
			return null;
		}
		return $real;
	}

	/**
	 * Sizes of the attachment: the file and its thumbnails now, and before Squeeze (backup size where known).
	 *
	 * @param int    $id   Attachment id.
	 * @param string $path Absolute main file path.
	 * @return array{main:int,current:int,original:int}
	 */
	private function sizes( int $id, string $path ): array {
		$main = (int) filesize( $path );
		$bak  = $this->backup_of( $path );
		$orig = null === $bak ? $main : (int) filesize( $bak );
		$cur  = $main;
		$meta = wp_get_attachment_metadata( $id );
		$seen = array( wp_basename( $path ) => true );
		foreach ( (array) ( is_array( $meta ) ? ( $meta['sizes'] ?? array() ) : array() ) as $size ) {
			$name = isset( $size['file'] ) ? wp_basename( (string) $size['file'] ) : '';
			$file = dirname( $path ) . '/' . $name;
			if ( '' === $name || isset( $seen[ $name ] ) || ! is_file( $file ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$cur          += (int) filesize( $file );
			$orig         += (int) filesize( $file );
		}
		return array(
			'main'     => $main,
			'current'  => $cur,
			'original' => $orig,
		);
	}

	protected function preview_item( array $item, array $ctx ): array {
		$id   = (int) $item['id'];
		$path = $this->safe_path( $id );
		if ( null === $path ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'The file is missing on disk.', 'ux-studio' ),
			);
		}
		$bak    = $this->backup_of( $path );
		$delete = empty( $ctx['options']['keep_backups'] ) && null !== $bak;
		if ( $delete ) {
			self::$would_free += (int) filesize( $bak );
		}
		return array( 'status' => ! $this->is_marked( $id ) || $delete ? 'updated' : 'unchanged' );
	}

	/**
	 * Whether the attachment is already taken over.
	 *
	 * @param int $id Attachment id.
	 */
	private function is_marked( int $id ): bool {
		return 'squeeze' === (string) get_post_meta( $id, ForeignOptimizers::META, true ) && '' !== (string) get_post_meta( $id, Optimizer::META_OPTIMIZED_SIZE, true );
	}

	protected function apply_item( array $item, array $ctx ): array {
		$id   = (int) $item['id'];
		$path = $this->safe_path( $id );
		if ( null === $path ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'The file is missing on disk.', 'ux-studio' ),
			);
		}

		$changed = false;
		if ( ! $this->is_marked( $id ) ) {
			$sizes = $this->sizes( $id, $path );
			update_post_meta( $id, ForeignOptimizers::META, 'squeeze' );
			update_post_meta( $id, Optimizer::META_MAIN_ORIGINAL, null === $this->backup_of( $path ) ? $sizes['main'] : (int) filesize( (string) $this->backup_of( $path ) ) );
			update_post_meta( $id, Optimizer::META_ORIGINAL_SIZE, $sizes['original'] );
			update_post_meta( $id, Optimizer::META_OPTIMIZED_SIZE, $sizes['current'] );
			update_post_meta( $id, Optimizer::META_WEBP_DONE, is_file( preg_replace( '/\.[^.]+$/', '.webp', $path ) ) ? 1 : 0 );
			update_post_meta( $id, Optimizer::META_AVIF_DONE, is_file( preg_replace( '/\.[^.]+$/', '.avif', $path ) ) ? 1 : 0 );
			update_post_meta( $id, MediaLibrary::META_FILESIZE, $sizes['main'] );
			$changed = true;
		}

		if ( empty( $ctx['options']['keep_backups'] ) ) {
			$bak = $this->backup_of( $path );
			if ( null !== $bak ) {
				$size = (int) filesize( $bak );
				if ( @unlink( $bak ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					update_option( self::FREED_OPTION, (int) get_option( self::FREED_OPTION, 0 ) + $size, false );
					// The original is gone: the stats now describe what is left.
					$sizes = $this->sizes( $id, $path );
					update_post_meta( $id, Optimizer::META_ORIGINAL_SIZE, $sizes['original'] );
					update_post_meta( $id, Optimizer::META_MAIN_ORIGINAL, $sizes['main'] );
					$changed = true;
				}
			}
		}

		if ( ! empty( $ctx['options']['generate_webp'] ) ) {
			ImageOptimizerModule::enqueue_ids( array( $id ) );
		}

		return array( 'status' => $changed ? 'updated' : 'unchanged' );
	}

	protected function verify_item( array $item ): array {
		if ( 'squeeze' === (string) get_post_meta( (int) $item['id'], ForeignOptimizers::META, true ) ) {
			return array(
				'ok'     => true,
				'detail' => '',
			);
		}
		return array(
			'ok'     => false,
			'detail' => __( 'The image is not marked as already compressed.', 'ux-studio' ),
		);
	}

	protected function extra_summary_rows( bool $dry_run, string $run_id ): array {
		$bytes = $dry_run ? self::$would_free : (int) get_option( self::FREED_OPTION, 0 );
		if ( $bytes <= 0 ) {
			return array();
		}
		return array(
			array(
				'module'  => '',
				'key'     => 'freed',
				'label'   => $dry_run ? __( 'Disk space that will be freed', 'ux-studio' ) : __( 'Disk space freed', 'ux-studio' ),
				'secret'  => false,
				'from'    => '',
				'to'      => size_format( $bytes ),
				'changed' => true,
				'write'   => ! $dry_run,
				'dropped' => false,
				'staged'  => false,
				'origin'  => 'image',
				'target'  => 'freed',
			),
		);
	}
}
