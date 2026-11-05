<?php
/**
 * Image Optimizer core processing logic.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Compresses/resizes a media library image in place and optionally emits
 * next-gen siblings (WebP and/or AVIF), using whichever of Imagick/GD is
 * available. Operates only on files resolved via get_attached_file() and
 * explicitly re-verified to sit inside wp_upload_dir()['basedir'] before any
 * filesystem write - never on arbitrary paths or external URLs. The very first
 * time an attachment is touched, the untouched original is copied to
 * "<name>-original.<ext>" in the same directory so optimize/restore is always
 * reversible. AVIF encoding is feature-detected and silently degrades to a
 * no-op (webp/avif_done = false) when the runtime cannot encode it.
 */
final class Optimizer {

	public const META_ORIGINAL_SIZE  = '_uxstudio_io_original_size';
	public const META_OPTIMIZED_SIZE = '_uxstudio_io_optimized_size';
	public const META_WEBP_DONE      = '_uxstudio_io_webp_done';
	public const META_AVIF_DONE      = '_uxstudio_io_avif_done';

	/** Relative uploads path of the PNG a JPEG was converted from (PNG -> JPG option). */
	public const META_CONVERTED_FROM = '_uxstudio_io_converted_from';

	/** Number of thumbnails compressed (set once all of them are done). */
	public const META_THUMBS = '_uxstudio_io_thumbs';

	/** Size of the main file before any optimizer touched it (kept for the stats when there is no backup). */
	public const META_MAIN_ORIGINAL = '_uxstudio_io_main_original';

	/** Thumbnail run paused by the time budget: file names already done (continues on the next batch). */
	public const META_THUMBS_PENDING = '_uxstudio_io_thumbs_pending';

	public const SUPPORTED_MIME = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Whether the runtime can encode WebP (GD imagewebp or Imagick WEBP).
	 */
	public static function supports_webp(): bool {
		if ( function_exists( 'imagewebp' ) ) {
			return true;
		}
		return self::imagick_supports( 'WEBP' );
	}

	/**
	 * Whether the runtime can encode AVIF (GD imageavif >= PHP 8.1 built with
	 * AVIF, or Imagick built against a libheif-enabled ImageMagick).
	 */
	public static function supports_avif(): bool {
		if ( function_exists( 'imageavif' ) ) {
			return true;
		}
		return self::imagick_supports( 'AVIF' );
	}

	/**
	 * Whether Imagick can read HEIC/HEIF (needs ImageMagick built with libheif).
	 */
	public static function supports_heic(): bool {
		return self::imagick_supports( 'HEIC' );
	}

	/**
	 * Whether Imagick is loaded and advertises the given format.
	 *
	 * @param string $format Uppercase format name, e.g. 'AVIF'.
	 */
	private static function imagick_supports( string $format ): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}
		try {
			return ! empty( \Imagick::queryFormats( $format ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Optimize a single attachment: resize to max_width, recompress at
	 * quality, optionally emit WebP and/or AVIF siblings, and do the same for
	 * every generated thumbnail (without resizing them). Idempotent-ish: a
	 * backup is only created once (on first run), so running it again
	 * re-compresses the already-optimized main file, not the backup; thumbnails
	 * are always rebuilt from their own backup, so they never degrade.
	 *
	 * @param int  $attachment_id Attachment post id.
	 * @param int  $quality       JPEG/WebP/AVIF quality 1-100.
	 * @param bool $convert_webp  Whether to also emit a .webp sibling.
	 * @param int  $max_width     Max width in pixels; 0 disables resizing.
	 * @param bool $convert_avif  Whether to also emit an .avif sibling.
	 * @param array $opts         Optional: max_height (0 = unlimited), png_to_jpg (bool),
	 *                            jpeg_quality / webp_quality / avif_quality (1-100, default $quality),
	 *                            thumbnails (bool, default true), thumbnail_sizes (string[], empty = all),
	 *                            metadata (array, used instead of the stored metadata, e.g. during upload),
	 *                            deadline (float microtime after which the thumbnail loop pauses),
	 *                            thumbs_only (bool, skip the main file),
	 *                            backup (bool, default true: keep "-original" copies; false = no backups, so no Restore),
	 *                            siblings_only (bool: never re-encode, only emit WebP/AVIF; always on for images that
	 *                            another optimizer already compressed, see ForeignOptimizers).
	 * @return array|WP_Error {attachment_id, original_size, optimized_size, webp_done, avif_done, converted, thumbs, thumbs_pending}
	 */
	public function optimize( int $attachment_id, int $quality, bool $convert_webp, int $max_width, bool $convert_avif = false, array $opts = array() ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'uxstudio_not_attachment', __( 'Not a media library attachment.', 'ux-studio' ) );
		}

		$path = $this->resolve_safe_path( $attachment_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED_MIME, true ) ) {
			return new WP_Error( 'uxstudio_unsupported_type', __( 'Only JPEG, PNG, GIF and WebP images are supported.', 'ux-studio' ) );
		}

		$quality    = max( 1, min( 100, $quality ) );
		$max_width  = max( 0, $max_width );
		$max_height = max( 0, (int) ( $opts['max_height'] ?? 0 ) );
		$qualities  = array(
			'jpeg' => $this->clamp_quality( $opts['jpeg_quality'] ?? $quality, $quality ),
			'webp' => $this->clamp_quality( $opts['webp_quality'] ?? $quality, $quality ),
			'avif' => $this->clamp_quality( $opts['avif_quality'] ?? $quality, $quality ),
		);

		// AVIF is best-effort: only attempt when the runtime can actually encode it.
		$convert_avif = $convert_avif && self::supports_avif();
		$convert_webp = $convert_webp && self::supports_webp();
		// A WebP upload is only re-encoded, it never gets siblings.
		if ( 'image/webp' === $mime ) {
			$convert_avif = false;
			$convert_webp = false;
		}

		// An image that another optimizer already compressed is never encoded again: only siblings are made.
		$foreign       = ForeignOptimizers::source_of( $attachment_id );
		$siblings_only = ! empty( $opts['siblings_only'] ) || '' !== $foreign;
		if ( '' !== $foreign && $foreign !== (string) get_post_meta( $attachment_id, ForeignOptimizers::META, true ) ) {
			update_post_meta( $attachment_id, ForeignOptimizers::META, $foreign );
		}
		// Without backups the original is gone after compression, so nothing that needs it (PNG -> JPEG) may run.
		$backup_on = ! $siblings_only && ( ! isset( $opts['backup'] ) || ! empty( $opts['backup'] ) );
		if ( ! $backup_on ) {
			$opts['png_to_jpg'] = false;
		}
		if ( $siblings_only ) {
			$max_width  = 0;
			$max_height = 0;
		}

		$meta = isset( $opts['metadata'] ) && is_array( $opts['metadata'] ) ? $opts['metadata'] : wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();

		// A paused thumbnail run (time budget) continues without touching the main file again.
		$pending     = get_post_meta( $attachment_id, self::META_THUMBS_PENDING, true );
		$thumbs_only = ! empty( $opts['thumbs_only'] ) || ! empty( $pending );
		$skip_done   = $this->done_map( $pending );

		$backup    = $this->backup_for( $attachment_id, $path );
		$converted = false;
		$pre_size  = (int) filesize( $path );
		$previous  = (int) get_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, true );
		$result    = array(
			'webp_done' => is_file( $this->webp_path( $path ) ),
			'avif_done' => is_file( $this->avif_path( $path ) ),
		);

		if ( $thumbs_only ) {
			$main_original = (int) get_post_meta( $attachment_id, self::META_MAIN_ORIGINAL, true );
			if ( is_file( $backup ) ) {
				$main_original = (int) filesize( $backup );
			}
			$main_original = $main_original > 0 ? $main_original : $pre_size;
		} elseif ( $siblings_only ) {
			$main_original = '' !== $foreign ? ForeignOptimizers::original_size( $foreign, $path ) : 0;
			$main_original = $main_original > 0 ? $main_original : $pre_size;
		} else {
			if ( $backup_on && ! is_file( $backup ) ) {
				if ( ! @copy( $path, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return new WP_Error( 'uxstudio_backup_failed', __( 'Could not create a backup of the original image.', 'ux-studio' ) );
				}
			}
			$main_original = is_file( $backup ) ? (int) filesize( $backup ) : $pre_size;

			// Optional PNG -> JPEG conversion (opaque PNGs only). The PNG stays as
			// the "-original" backup, so Restore can bring it back.
			if ( ! empty( $opts['png_to_jpg'] ) && 'image/png' === $mime ) {
				$jpeg = $this->convert_png_to_jpeg( $path, $qualities['jpeg'] );
				if ( null !== $jpeg ) {
					$old_rel = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
					$this->delete_subsizes( $path, $meta );
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					update_attached_file( $attachment_id, $jpeg );
					wp_update_post(
						array(
							'ID'             => $attachment_id,
							'post_mime_type' => 'image/jpeg',
						)
					);
					update_post_meta( $attachment_id, self::META_CONVERTED_FROM, $old_rel );
					$path      = $jpeg;
					$mime      = 'image/jpeg';
					$converted = true;
				}
			}
		}

		if ( ! $thumbs_only && $siblings_only ) {
			if ( $this->siblings_missing( $path, $convert_webp, $convert_avif ) ) {
				$extra = $this->process_file( $path, $mime, $qualities, 0, 0, $convert_webp, $convert_avif, null, false );
				if ( ! is_wp_error( $extra ) ) {
					$result = $extra;
				}
			}
		} elseif ( ! $thumbs_only ) {
			$result = $this->process_file( $path, $mime, $qualities, $max_width, $max_height, $convert_webp, $convert_avif );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} elseif ( $this->siblings_missing( $path, $convert_webp, $convert_avif ) ) {
			// Already optimized earlier: only add the missing next-gen siblings of the main file.
			$extra = $this->process_file( $path, $mime, $qualities, 0, 0, $convert_webp, $convert_avif, null, false );
			if ( ! is_wp_error( $extra ) ) {
				$result = $extra;
			}
		}

		clearstatcache( true, $path );
		$optimized_size = (int) filesize( $path );

		// Set first, so the auto-optimize filter skips this attachment while metadata is rebuilt below.
		if ( ! $thumbs_only ) {
			update_post_meta( $attachment_id, self::META_MAIN_ORIGINAL, $main_original );
			update_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, $main_original );
		}
		update_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE, $optimized_size );
		update_post_meta( $attachment_id, self::META_WEBP_DONE, $result['webp_done'] ? 1 : 0 );
		update_post_meta( $attachment_id, self::META_AVIF_DONE, $result['avif_done'] ? 1 : 0 );
		update_post_meta( $attachment_id, MediaLibrary::META_FILESIZE, $optimized_size );

		if ( $converted ) {
			// New file name / mime -> thumbnails and metadata must be rebuilt.
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$fresh = wp_generate_attachment_metadata( $attachment_id, $path );
			wp_update_attachment_metadata( $attachment_id, $fresh );
			$meta = is_array( $fresh ) ? $fresh : array();
		}

		$thumbs         = array( 'count' => 0, 'pending' => false, 'done' => array() );
		$with_thumbnail = ! isset( $opts['thumbnails'] ) || ! empty( $opts['thumbnails'] );
		if ( $with_thumbnail ) {
			$thumbs = $this->optimize_thumbnails(
				$path,
				$meta,
				$qualities,
				$convert_webp,
				$convert_avif,
				array_map( 'strval', (array) ( $opts['thumbnail_sizes'] ?? array() ) ),
				(float) ( $opts['deadline'] ?? 0 ),
				$skip_done,
				$backup_on,
				$siblings_only
			);
		}

		if ( $thumbs['pending'] ) {
			update_post_meta( $attachment_id, self::META_THUMBS_PENDING, $thumbs['done'] );
		} else {
			delete_post_meta( $attachment_id, self::META_THUMBS_PENDING );
			if ( $with_thumbnail ) {
				update_post_meta( $attachment_id, self::META_THUMBS, count( $thumbs['done'] ) );
			}
		}

		// Stats cover the whole attachment: main file plus every thumbnail.
		$totals = $this->totals( $path, $meta, $main_original, $thumbs['done'] );
		// Without any backup the new "original" is only what this run saw: keep the larger figure from before.
		if ( $thumbs_only || ! is_file( $backup ) ) {
			$totals['original'] = max( $totals['original'], $previous );
		}
		update_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, $totals['original'] );
		update_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE, $totals['current'] );

		return array(
			'attachment_id'  => $attachment_id,
			'original_size'  => $totals['original'],
			'optimized_size' => $totals['current'],
			'webp_done'      => $result['webp_done'],
			'avif_done'      => $result['avif_done'],
			'converted'      => $converted,
			'thumbs'         => count( $thumbs['done'] ),
			'thumbs_pending' => $thumbs['pending'],
			'siblings_only'  => $siblings_only,
			'backup'         => $backup_on,
		);
	}

	/**
	 * Names of the thumbnails already done in a paused run, with their original size
	 * (the stored value is a map name => size; an older list of names is accepted).
	 *
	 * @param mixed $stored Value of the pending meta.
	 * @return array<string, int>
	 */
	private function done_map( $stored ): array {
		$out = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $value ) {
			if ( is_int( $key ) ) {
				$out[ (string) $value ] = 0;
			} else {
				$out[ (string) $key ] = (int) $value;
			}
		}

		return $out;
	}

	/**
	 * Whether a wanted next-gen sibling of a file does not exist yet.
	 *
	 * @param string $file Absolute file path.
	 * @param bool   $webp WebP wanted.
	 * @param bool   $avif AVIF wanted.
	 */
	private function siblings_missing( string $file, bool $webp, bool $avif ): bool {
		return ( $webp && ! is_file( $this->webp_path( $file ) ) ) || ( $avif && ! is_file( $this->avif_path( $file ) ) );
	}

	/**
	 * Compress the generated thumbnails (and emit their siblings). With backups on, each
	 * one is backed up once next to the file ("<name>-300x200-original.jpg") and always
	 * rebuilt from that backup. In siblings-only mode the files are not touched at all.
	 * Stops early once the deadline has passed (at least one file is processed per call
	 * so a run always makes progress).
	 *
	 * @param string $main    Absolute main file path (directory anchor).
	 * @param array  $meta    Attachment metadata.
	 * @param array{jpeg:int,webp:int,avif:int} $qualities Per-format quality.
	 * @param bool   $webp    Emit .webp siblings.
	 * @param bool   $avif    Emit .avif siblings.
	 * @param string[] $names Size names to process; empty = all.
	 * @param float  $deadline Microtime after which to pause; 0 = no limit.
	 * @param array<string, int> $skip Files already done in an earlier paused run (name => original size).
	 * @param bool   $backup_on Keep a backup of every thumbnail before it is compressed.
	 * @param bool   $siblings_only Never re-encode the thumbnail, only emit the siblings.
	 * @return array{count:int,pending:bool,done:array<string, int>} done = every file name handled so far => original size.
	 */
	private function optimize_thumbnails( string $main, array $meta, array $qualities, bool $webp, bool $avif, array $names, float $deadline, array $skip, bool $backup_on = true, bool $siblings_only = false ): array {
		$done      = $skip;
		$processed = 0;
		$pending   = false;

		foreach ( $this->thumbnail_files( $main, $meta, $names ) as $file ) {
			$name = wp_basename( $file );
			if ( isset( $skip[ $name ] ) ) {
				continue;
			}

			$mime = (string) wp_check_filetype( $file )['type'];
			if ( ! in_array( $mime, self::SUPPORTED_MIME, true ) ) {
				continue;
			}
			$is_webp = 'image/webp' === $mime;
			$thumb_w = $is_webp ? false : $webp;
			$thumb_a = $is_webp ? false : $avif;
			$pre     = (int) filesize( $file );

			// A foreign-compressed thumbnail that already has its siblings needs nothing.
			if ( $siblings_only && ! $this->siblings_missing( $file, $thumb_w, $thumb_a ) ) {
				$done[ $name ] = $pre;
				continue;
			}

			if ( $deadline > 0 && $processed > 0 && microtime( true ) > $deadline ) {
				$pending = true;
				break;
			}
			if ( 0.0 === $deadline ) {
				@set_time_limit( 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			if ( $siblings_only ) {
				$result = $this->process_file( $file, $mime, $qualities, 0, 0, $thumb_w, $thumb_a, null, false );
				++$processed;
				$done[ $name ] = $pre;
				continue;
			}

			$backup = $this->backup_path( $file );
			if ( $backup_on && ! is_file( $backup ) && ! @copy( $file, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			$has_backup = is_file( $backup );

			$result = $this->process_file( $file, $mime, $qualities, 0, 0, $thumb_w, $thumb_a, $has_backup ? $backup : null );
			++$processed;
			$done[ $name ] = $has_backup ? (int) filesize( $backup ) : $pre;
			if ( is_wp_error( $result ) ) {
				continue;
			}

			// Never keep a "compressed" thumbnail that is bigger than the original one.
			clearstatcache( true, $file );
			if ( $has_backup && (int) filesize( $file ) >= (int) filesize( $backup ) ) {
				@copy( $backup, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		return array(
			'count'   => $processed,
			'pending' => $pending,
			'done'    => $done,
		);
	}

	/**
	 * Absolute paths of the thumbnails listed in the metadata that exist inside
	 * the uploads directory (deduplicated; the main file itself is excluded).
	 *
	 * @param string   $main  Absolute main file path (directory anchor).
	 * @param array    $meta  Attachment metadata.
	 * @param string[] $names Size names to keep; empty = all.
	 * @return string[]
	 */
	private function thumbnail_files( string $main, array $meta, array $names = array() ): array {
		$dir   = dirname( $main );
		$base  = realpath( wp_upload_dir()['basedir'] );
		$seen  = array( wp_normalize_path( $main ) => true );
		$files = array();

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size_name => $size ) {
			if ( empty( $size['file'] ) || ( ! empty( $names ) && ! in_array( (string) $size_name, $names, true ) ) ) {
				continue;
			}
			$file = wp_normalize_path( $dir . '/' . wp_basename( (string) $size['file'] ) );
			if ( isset( $seen[ $file ] ) || ! is_file( $file ) ) {
				continue;
			}
			$seen[ $file ] = true;
			$real          = realpath( $file );
			if ( false === $real || false === $base || 0 !== strpos( $real, $base ) ) {
				continue;
			}
			$files[] = $file;
		}

		return $files;
	}

	/**
	 * Original and current size of the whole attachment, thumbnails included. The original
	 * of a thumbnail is the size recorded when it was handled, else its backup, else its
	 * current size.
	 *
	 * @param string             $main          Absolute main file path.
	 * @param array              $meta          Attachment metadata.
	 * @param int                $main_original Size of the main file before any optimizer.
	 * @param array<string, int> $recorded      Thumbnail file name => original size.
	 * @return array{original:int,current:int}
	 */
	private function totals( string $main, array $meta, int $main_original, array $recorded ): array {
		$current  = (int) filesize( $main );
		$original = $main_original;

		foreach ( $this->thumbnail_files( $main, $meta ) as $file ) {
			$size      = (int) filesize( $file );
			$thumb_bak = $this->backup_path( $file );
			$known     = (int) ( $recorded[ wp_basename( $file ) ] ?? 0 );
			$current  += $size;
			if ( $known <= 0 ) {
				$known = is_file( $thumb_bak ) ? (int) filesize( $thumb_bak ) : $size;
			}
			$original += $known;
		}

		return array(
			'original' => $original,
			'current'  => $current,
		);
	}

	/**
	 * Restore the original from its backup and drop the optimization meta and
	 * any generated next-gen siblings. Thumbnails come back from their own
	 * backups; when one has none (for example it was compressed by another
	 * plugin) all thumbnails are regenerated from the restored file. Reverses a
	 * PNG -> JPEG conversion. Also understands backups made by the legacy UX1
	 * plugin (uploads "originals/" folder) and by Squeeze ("<name>.bak.<ext>"),
	 * so images optimized before the migration can be restored too.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function restore( int $attachment_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'uxstudio_not_attachment', __( 'Not a media library attachment.', 'ux-studio' ) );
		}

		$path = $this->resolve_safe_path( $attachment_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$backup     = $this->backup_for( $attachment_id, $path );
		$own_backup = is_file( $backup );
		if ( ! $own_backup ) {
			$backup = $this->legacy_backup( $path );
			if ( null === $backup ) {
				$foreign = ForeignOptimizers::source_of( $attachment_id );
				if ( '' !== $foreign ) {
					// Compressed by another optimizer and its backup is gone: only our own copies can be removed.
					return $this->remove_siblings( $attachment_id, $path, $foreign );
				}
				return new WP_Error( 'uxstudio_no_backup', __( 'No backup exists for this image.', 'ux-studio' ) );
			}
		}

		$base_dir  = (string) realpath( wp_upload_dir()['basedir'] );
		$target    = $path;
		$was_png   = false;
		$backup_ex = strtolower( pathinfo( $backup, PATHINFO_EXTENSION ) );
		$cur_ex    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$meta      = wp_get_attachment_metadata( $attachment_id );
		$meta      = is_array( $meta ) ? $meta : array();

		// Reverse a PNG -> JPEG conversion: bring the PNG back under its own name.
		if ( 'png' === $backup_ex && in_array( $cur_ex, array( 'jpg', 'jpeg' ), true ) ) {
			$target = wp_normalize_path( (string) preg_replace( '/\.jpe?g$/i', '.png', $path ) );
			if ( '' === $target || 0 !== strpos( $target, wp_normalize_path( $base_dir ) ) ) {
				return new WP_Error( 'uxstudio_path_traversal', __( 'Refused to operate on a file outside the uploads directory.', 'ux-studio' ) );
			}
			$was_png = true;
		}

		if ( ! @copy( $backup, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'uxstudio_restore_failed', __( 'Could not restore the original image.', 'ux-studio' ) );
		}

		foreach ( array( $this->webp_path( $path ), $this->avif_path( $path ) ) as $sibling ) {
			if ( is_file( $sibling ) ) {
				@unlink( $sibling ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		if ( $was_png ) {
			$this->delete_subsizes( $path, $meta );
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			update_attached_file( $attachment_id, $target );
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'image/png',
				)
			);
			$this->regenerate_metadata( $attachment_id, $target, $meta );
		} else {
			$files   = $this->thumbnail_files( $path, $meta );
			$missing = false;
			foreach ( $files as $file ) {
				if ( ! is_file( $this->backup_path( $file ) ) ) {
					$missing = true;
					break;
				}
			}
			$touched = ! $own_backup
				|| '' !== (string) get_post_meta( $attachment_id, self::META_THUMBS, true )
				|| '' !== (string) get_post_meta( $attachment_id, self::META_THUMBS_PENDING, true );

			if ( $missing && $touched ) {
				// No backup of a thumbnail we (or another plugin) changed: rebuild them all from the restored file.
				$this->delete_subsizes( $path, $meta );
				$this->regenerate_metadata( $attachment_id, $target, $meta );
			} else {
				foreach ( $files as $file ) {
					foreach ( array( $this->webp_path( $file ), $this->avif_path( $file ) ) as $sibling ) {
						if ( is_file( $sibling ) ) {
							@unlink( $sibling ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						}
					}
					$thumb_bak = $this->backup_path( $file );
					if ( is_file( $thumb_bak ) ) {
						@copy( $thumb_bak, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
			}
		}

		delete_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE );
		delete_post_meta( $attachment_id, self::META_WEBP_DONE );
		delete_post_meta( $attachment_id, self::META_AVIF_DONE );
		delete_post_meta( $attachment_id, self::META_CONVERTED_FROM );
		delete_post_meta( $attachment_id, self::META_THUMBS );
		delete_post_meta( $attachment_id, self::META_THUMBS_PENDING );
		delete_post_meta( $attachment_id, self::META_MAIN_ORIGINAL );
		if ( '' !== ForeignOptimizers::source_of( $attachment_id ) ) {
			// Back to the untouched original: it may be compressed again by this module.
			update_post_meta( $attachment_id, ForeignOptimizers::META, 'none' );
		}
		update_post_meta( $attachment_id, MediaLibrary::META_FILESIZE, (int) filesize( $target ) );

		return array( 'attachment_id' => $attachment_id, 'restored' => true );
	}

	/**
	 * Restore for an image that another optimizer compressed and whose backup does not exist (any more):
	 * the files stay as they are, only the WebP/AVIF copies made by this module are removed.
	 *
	 * @param int    $attachment_id Attachment post id.
	 * @param string $path          Absolute main file path.
	 * @param string $source        Id of the foreign optimizer.
	 */
	private function remove_siblings( int $attachment_id, string $path, string $source ): array {
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$files = array_merge( array( $path ), $this->thumbnail_files( $path, is_array( $meta ) ? $meta : array() ) );
		foreach ( $files as $file ) {
			foreach ( array( $this->webp_path( $file ), $this->avif_path( $file ) ) as $sibling ) {
				if ( is_file( $sibling ) ) {
					@unlink( $sibling ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
		foreach ( array( self::META_OPTIMIZED_SIZE, self::META_ORIGINAL_SIZE, self::META_MAIN_ORIGINAL, self::META_WEBP_DONE, self::META_AVIF_DONE, self::META_THUMBS, self::META_THUMBS_PENDING ) as $key ) {
			delete_post_meta( $attachment_id, $key );
		}

		return array(
			'attachment_id' => $attachment_id,
			'restored'      => false,
			'source'        => $source,
			'message'       => sprintf(
				/* translators: %s: name of the other image optimizer plugin. */
				__( 'The WebP/AVIF copies were removed. There is nothing to restore: the image was compressed by %s and its original is not kept.', 'ux-studio' ),
				ForeignOptimizers::label( $source )
			),
		);
	}

	/**
	 * Rebuild the thumbnails and the metadata of an attachment from its main
	 * file. The "-scaled" big-image step is switched off (the file is already the
	 * size we want) and the pointer to the untouched upload is kept.
	 *
	 * @param int    $attachment_id Attachment post id.
	 * @param string $file          Absolute main file path.
	 * @param array  $old           Previous metadata.
	 */
	private function regenerate_metadata( int $attachment_id, string $file, array $old ): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		add_filter( 'big_image_size_threshold', '__return_false', 99 );
		$new = wp_generate_attachment_metadata( $attachment_id, $file );
		remove_filter( 'big_image_size_threshold', '__return_false', 99 );

		if ( ! is_array( $new ) ) {
			return;
		}
		if ( empty( $new['original_image'] ) && ! empty( $old['original_image'] ) ) {
			$new['original_image'] = $old['original_image'];
		}
		wp_update_attachment_metadata( $attachment_id, $new );
	}

	/**
	 * Remove everything this module wrote next to an attachment that is about to
	 * be deleted: the backups and the WebP/AVIF siblings of the main file and of
	 * every thumbnail (the files WordPress itself manages are left to core).
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function delete_extras( int $attachment_id ): void {
		$path = get_attached_file( $attachment_id );
		$base = realpath( wp_upload_dir()['basedir'] );
		if ( ! $path || false === $base ) {
			return;
		}
		$real_dir = realpath( dirname( $path ) );
		if ( false === $real_dir || 0 !== strpos( $real_dir, $base ) ) {
			return;
		}

		$meta    = wp_get_attachment_metadata( $attachment_id );
		$meta    = is_array( $meta ) ? $meta : array();
		$targets = array( $this->backup_for( $attachment_id, $path ), $this->webp_path( $path ), $this->avif_path( $path ) );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$file      = dirname( $path ) . '/' . wp_basename( (string) $size['file'] );
			$targets[] = $this->backup_path( $file );
			$targets[] = $this->webp_path( $file );
			$targets[] = $this->avif_path( $file );
		}

		foreach ( array_unique( $targets ) as $target ) {
			if ( is_file( $target ) ) {
				@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Whether an attachment has a backup that restore() can use (own or legacy).
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function has_backup( int $attachment_id ): bool {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) ) {
			return false;
		}
		return is_file( $this->backup_for( $attachment_id, $path ) ) || null !== $this->legacy_backup( $path );
	}

	/**
	 * Backup file for an attachment: the "-original" copy next to the current
	 * file, or - after a PNG -> JPEG conversion - next to the original PNG name.
	 *
	 * @param int    $attachment_id Attachment post id.
	 * @param string $path          Current absolute file path.
	 */
	private function backup_for( int $attachment_id, string $path ): string {
		$from = (string) get_post_meta( $attachment_id, self::META_CONVERTED_FROM, true );
		if ( '' !== $from ) {
			$info = pathinfo( $from );
			return dirname( $path ) . '/' . $info['filename'] . '-original.' . ( $info['extension'] ?? 'png' );
		}

		return $this->backup_path( $path );
	}

	/**
	 * Backup written by the legacy UX1 optimizer: uploads/.../originals/<file>
	 * (a converted PNG is stored there with its .png name).
	 *
	 * @param string $path Current absolute file path.
	 * @return string|null Absolute backup path or null.
	 */
	private function legacy_backup( string $path ): ?string {
		$info      = pathinfo( $path );
		$candidate = $info['dirname'] . '/originals/' . $info['basename'];
		if ( is_file( $candidate ) ) {
			return $candidate;
		}

		if ( preg_match( '/\.jpe?g$/i', $path ) ) {
			$png = $info['dirname'] . '/originals/' . $info['filename'] . '.png';
			if ( is_file( $png ) ) {
				return $png;
			}
		}

		// Squeeze plugin sidecar "<name>.bak.<ext>" (PLAN §41), see ForeignOptimizers.
		return ForeignOptimizers::squeeze_backup( $path );
	}

	/**
	 * @param mixed $value    Raw quality.
	 * @param int   $fallback Used when $value is not a usable 1-100 number.
	 */
	private function clamp_quality( $value, int $fallback ): int {
		$value = (int) $value;
		return $value >= 1 ? min( 100, $value ) : $fallback;
	}

	/**
	 * Delete generated thumbnails (and their webp/avif siblings and backups)
	 * listed in attachment metadata.
	 *
	 * @param string $path Main file path (directory anchor).
	 * @param array  $meta Attachment metadata.
	 */
	private function delete_subsizes( string $path, array $meta ): void {
		$dir = dirname( $path );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$file = $dir . '/' . wp_basename( (string) $size['file'] );
			foreach ( array( $file, $this->webp_path( $file ), $this->avif_path( $file ), $this->backup_path( $file ) ) as $target ) {
				if ( is_file( $target ) ) {
					@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
	}

	/**
	 * Convert an opaque PNG to a JPEG next to it (same name, .jpg; a unique name
	 * if that is taken). Returns null when the PNG uses transparency, GD is
	 * missing or encoding fails - the caller then keeps the PNG.
	 *
	 * @param string $path    Absolute path of the PNG.
	 * @param int    $quality JPEG quality 1-100.
	 * @return string|null Absolute path of the new JPEG.
	 */
	private function convert_png_to_jpeg( string $path, int $quality ): ?string {
		if ( ! function_exists( 'imagecreatefrompng' ) || ! function_exists( 'imagejpeg' ) ) {
			return null;
		}

		$alpha_capable = $this->png_has_alpha_channel( $path );
		$image         = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $image ) {
			return null;
		}
		imagepalettetotruecolor( $image );

		$width  = imagesx( $image );
		$height = imagesy( $image );
		if ( $width < 1 || $height < 1 || ( $alpha_capable && $this->gd_has_transparency( $image, $width, $height ) ) ) {
			imagedestroy( $image );
			return null;
		}

		$canvas = imagecreatetruecolor( $width, $height );
		if ( ! $canvas ) {
			imagedestroy( $image );
			return null;
		}
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 255, 255, 255 ) );
		imagecopy( $canvas, $image, 0, 0, 0, 0, $width, $height );
		imagedestroy( $image );

		// Same name with .jpg; only if that is taken by another file get a unique name.
		$dir    = wp_normalize_path( dirname( $path ) );
		$stem   = pathinfo( $path, PATHINFO_FILENAME );
		$target = $dir . '/' . $stem . '.jpg';
		if ( file_exists( $target ) ) {
			$target = $dir . '/' . wp_unique_filename( $dir, $stem . '.jpg' );
		}
		$ok = imagejpeg( $canvas, $target, $quality );
		imagedestroy( $canvas );

		if ( ! $ok || ! is_file( $target ) ) {
			return null;
		}

		return $target;
	}

	/**
	 * PNG colour type other than gray / RGB (palette may carry tRNS, 4 / 6 have
	 * an alpha channel) = may be transparent.
	 *
	 * @param string $path Absolute PNG path.
	 */
	private function png_has_alpha_channel( string $path ): bool {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return true;
		}
		$header = (string) fread( $handle, 26 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( strlen( $header ) < 26 || "\x89PNG\r\n\x1a\n" !== substr( $header, 0, 8 ) ) {
			return true;
		}

		$color_type = ord( $header[25] );

		return 0 !== $color_type && 2 !== $color_type;
	}

	/**
	 * Sample pixels for any non-opaque alpha.
	 *
	 * @param \GdImage|resource $image  GD image.
	 * @param int               $width  Width.
	 * @param int               $height Height.
	 */
	private function gd_has_transparency( $image, int $width, int $height ): bool {
		$step = max( 1, (int) ( min( $width, $height ) / 100 ) );
		for ( $x = 0; $x < $width; $x += $step ) {
			for ( $y = 0; $y < $height; $y += $step ) {
				if ( ( ( imagecolorat( $image, $x, $y ) >> 24 ) & 0x7F ) > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Target size when an image exceeds the max box (aspect ratio kept).
	 *
	 * @param int $width      Current width.
	 * @param int $height     Current height.
	 * @param int $max_width  0 = unlimited.
	 * @param int $max_height 0 = unlimited.
	 * @return array{0:int,1:int}|null Null when no resize is needed.
	 */
	private function fit_dimensions( int $width, int $height, int $max_width, int $max_height ): ?array {
		$ratio = 1.0;
		if ( $max_width > 0 && $width > $max_width ) {
			$ratio = min( $ratio, $max_width / $width );
		}
		if ( $max_height > 0 && $height > $max_height ) {
			$ratio = min( $ratio, $max_height / $height );
		}
		if ( $ratio >= 1.0 ) {
			return null;
		}

		return array( max( 1, (int) round( $width * $ratio ) ), max( 1, (int) round( $height * $ratio ) ) );
	}

	/**
	 * Resolve get_attached_file() and defense-in-depth verify it stays inside
	 * wp_upload_dir()['basedir'].
	 *
	 * @param int $attachment_id Attachment post id.
	 * @return string|WP_Error
	 */
	private function resolve_safe_path( int $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'uxstudio_missing_file', __( 'The file is missing on disk.', 'ux-studio' ) );
		}

		$real_path = realpath( $path );
		$real_base = realpath( wp_upload_dir()['basedir'] );
		if ( false === $real_path || false === $real_base || 0 !== strpos( $real_path, $real_base ) ) {
			return new WP_Error( 'uxstudio_path_traversal', __( 'Refused to operate on a file outside the uploads directory.', 'ux-studio' ) );
		}

		return $real_path;
	}

	/**
	 * @param string $path Absolute file path.
	 */
	private function backup_path( string $path ): string {
		$info = pathinfo( $path );
		return $info['dirname'] . '/' . $info['filename'] . '-original.' . $info['extension'];
	}

	/**
	 * The .webp sibling. A file that already is a .webp has none (a path that never
	 * exists is returned, so a sibling clean-up can never delete the file itself).
	 *
	 * @param string $path Absolute file path.
	 */
	private function webp_path( string $path ): string {
		$info = pathinfo( $path );
		if ( 'webp' === strtolower( $info['extension'] ?? '' ) ) {
			return $path . '.none';
		}
		return $info['dirname'] . '/' . $info['filename'] . '.webp';
	}

	/**
	 * @param string $path Absolute file path.
	 */
	private function avif_path( string $path ): string {
		$info = pathinfo( $path );
		return $info['dirname'] . '/' . $info['filename'] . '.avif';
	}

	/**
	 * Compress one file with Imagick (preferred) or GD.
	 *
	 * @param string      $path         Absolute file path (output; overwritten in place).
	 * @param string      $mime         Mime type.
	 * @param array{jpeg:int,webp:int,avif:int} $quality Per-format quality 1-100.
	 * @param int         $max_width    0 = no width limit.
	 * @param int         $max_height   0 = no height limit.
	 * @param bool        $convert_webp Whether to emit a .webp sibling.
	 * @param bool        $convert_avif Whether to emit an .avif sibling.
	 * @param string|null $source       File to read instead of $path (a thumbnail is rebuilt from its backup).
	 * @param bool        $write        False = leave $path alone and only emit the siblings.
	 * @return array{webp_done:bool,avif_done:bool}|WP_Error
	 */
	private function process_file( string $path, string $mime, array $quality, int $max_width, int $max_height, bool $convert_webp, bool $convert_avif, ?string $source = null, bool $write = true ) {
		if ( extension_loaded( 'imagick' ) ) {
			return $this->process_with_imagick( $path, $mime, $quality, $max_width, $max_height, $convert_webp, $convert_avif, $source, $write );
		}
		if ( extension_loaded( 'gd' ) ) {
			return $this->process_with_gd( $path, $mime, $quality, $max_width, $max_height, $convert_webp, $convert_avif, $source, $write );
		}

		return new WP_Error( 'uxstudio_no_image_library', __( 'Neither the Imagick nor the GD PHP extension is available.', 'ux-studio' ) );
	}

	/**
	 * @param string      $path         Absolute file path (output; overwritten in place).
	 * @param string      $mime         Mime type.
	 * @param array{jpeg:int,webp:int,avif:int} $quality Per-format quality 1-100.
	 * @param int         $max_width    0 = no width limit.
	 * @param int         $max_height   0 = no height limit.
	 * @param bool        $convert_webp Whether to emit a .webp sibling.
	 * @param bool        $convert_avif Whether to emit an .avif sibling.
	 * @param string|null $source       File to read instead of $path.
	 * @param bool        $write        False = only emit the siblings.
	 * @return array{webp_done:bool,avif_done:bool}|WP_Error
	 */
	private function process_with_gd( string $path, string $mime, array $quality, int $max_width, int $max_height, bool $convert_webp, bool $convert_avif, ?string $source = null, bool $write = true ) {
		$image = $this->gd_load( $source ?? $path, $mime );
		if ( ! $image ) {
			return new WP_Error( 'uxstudio_decode_failed', __( 'Could not decode the image.', 'ux-studio' ) );
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );

		$fit = $this->fit_dimensions( $width, $height, $max_width, $max_height );
		if ( null !== $fit ) {
			list( $new_width, $new_height ) = $fit;
			$resized = imagecreatetruecolor( $new_width, $new_height );
			imagealphablending( $resized, false );
			imagesavealpha( $resized, true );
			imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
			imagedestroy( $image );
			$image = $resized;
		}

		switch ( $write ? $mime : '' ) {
			case 'image/webp':
				imagewebp( $image, $path, $quality['webp'] );
				break;
			case 'image/jpeg':
				imagejpeg( $image, $path, $quality['jpeg'] );
				break;
			case 'image/png':
				// GD PNG quality is 0 (none) - 9 (max compression); map 1-100 inversely.
				imagepng( $image, $path, (int) round( ( 100 - $quality['jpeg'] ) / 100 * 9 ) );
				break;
			case 'image/gif':
				imagegif( $image, $path );
				break;
		}

		$webp_done = false;
		if ( $convert_webp && function_exists( 'imagewebp' ) ) {
			$webp_done = (bool) @imagewebp( $image, $this->webp_path( $path ), $quality['webp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$avif_done = false;
		if ( $convert_avif && function_exists( 'imageavif' ) ) {
			// imageavif( image, file, quality, speed ). speed -1 = auto/default.
			$avif_done = (bool) @imageavif( $image, $this->avif_path( $path ), $quality['avif'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		imagedestroy( $image );

		return array( 'webp_done' => $webp_done, 'avif_done' => $avif_done );
	}

	/**
	 * @param string $path Absolute file path.
	 * @param string $mime Mime type.
	 * @return \GdImage|resource|false
	 */
	private function gd_load( string $path, string $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			case 'image/png':
				$img = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $img ) {
					imagepalettetotruecolor( $img );
					imagealphablending( $img, false );
					imagesavealpha( $img, true );
				}
				return $img;
			case 'image/gif':
				return @imagecreatefromgif( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			case 'image/webp':
				if ( ! function_exists( 'imagecreatefromwebp' ) ) {
					return false;
				}
				$img = @imagecreatefromwebp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $img ) {
					imagepalettetotruecolor( $img );
					imagealphablending( $img, false );
					imagesavealpha( $img, true );
				}
				return $img;
			default:
				return false;
		}
	}

	/**
	 * @param string      $path         Absolute file path (output; overwritten in place).
	 * @param string      $mime         Mime type.
	 * @param array{jpeg:int,webp:int,avif:int} $quality Per-format quality 1-100.
	 * @param int         $max_width    0 = no width limit.
	 * @param int         $max_height   0 = no height limit.
	 * @param bool        $convert_webp Whether to emit a .webp sibling.
	 * @param bool        $convert_avif Whether to emit an .avif sibling.
	 * @param string|null $source       File to read instead of $path.
	 * @param bool        $write        False = only emit the siblings.
	 * @return array{webp_done:bool,avif_done:bool}|WP_Error
	 */
	private function process_with_imagick( string $path, string $mime, array $quality, int $max_width, int $max_height, bool $convert_webp, bool $convert_avif, ?string $source = null, bool $write = true ) {
		try {
			$imagick = new \Imagick( $source ?? $path );
			$imagick->setImageCompressionQuality( 'image/webp' === $mime ? $quality['webp'] : $quality['jpeg'] );

			$fit = $this->fit_dimensions( $imagick->getImageWidth(), $imagick->getImageHeight(), $max_width, $max_height );
			if ( null !== $fit ) {
				$imagick->resizeImage( $fit[0], $fit[1], \Imagick::FILTER_LANCZOS, 1, false );
			}

			$imagick->stripImage();
			if ( $write ) {
				$imagick->writeImage( $path );
			}

			$webp_done = false;
			if ( $convert_webp && self::imagick_supports( 'WEBP' ) ) {
				$webp_done = $this->imagick_write_variant( $imagick, 'webp', $quality['webp'], $this->webp_path( $path ) );
			}

			$avif_done = false;
			if ( $convert_avif && self::imagick_supports( 'AVIF' ) ) {
				$avif_done = $this->imagick_write_variant( $imagick, 'avif', $quality['avif'], $this->avif_path( $path ) );
			}

			$imagick->destroy();

			return array( 'webp_done' => $webp_done, 'avif_done' => $avif_done );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_imagick_failed', $e->getMessage() );
		}
	}

	/**
	 * Clone an Imagick instance and write it out in a next-gen format.
	 *
	 * @param \Imagick $source  Source instance (not modified).
	 * @param string   $format  Target format, e.g. 'webp'.
	 * @param int      $quality 1-100.
	 * @param string   $target  Absolute output path.
	 */
	private function imagick_write_variant( \Imagick $source, string $format, int $quality, string $target ): bool {
		try {
			$variant = clone $source;
			$variant->setImageFormat( $format );
			$variant->setImageCompressionQuality( $quality );
			$variant->writeImage( $target );
			$variant->destroy();
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
