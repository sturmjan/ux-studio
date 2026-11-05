<?php
/**
 * Images that another image optimizer plugin has already compressed.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * An image must never be compressed a second time: a lossy JPEG that goes through
 * another encoder loses quality for almost no gain. For attachments that another
 * optimizer already handled, the Image Optimizer only creates the WebP/AVIF
 * siblings (main file and every thumbnail) and leaves the files as they are.
 *
 * Each foreign optimizer is one registry row: the post meta that marks an image as
 * compressed by it and, when it keeps the untouched originals, a callable that finds
 * the backup file of an image. More optimizers (EWWW, Smush, ShortPixel, Imagify)
 * are added with the filter `uxstudio_io_foreign_optimizers`.
 */
final class ForeignOptimizers {

	/** Meta set by this module or the migration wizard: id of the optimizer, or 'none' (restored, compress normally). */
	public const META = '_uxstudio_io_precompressed';

	/**
	 * Known optimizers.
	 *
	 * @return array<string, array{label:string,meta:string,backup:callable|null}>
	 */
	public static function all(): array {
		$map = array(
			'squeeze' => array(
				'label'  => 'Squeeze',
				'meta'   => 'squeeze_is_compressed',
				'backup' => array( self::class, 'squeeze_backup' ),
			),
		);

		return function_exists( 'apply_filters' ) ? (array) apply_filters( 'uxstudio_io_foreign_optimizers', $map ) : $map;
	}

	/**
	 * Id of the optimizer that compressed the attachment, '' when none did.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public static function source_of( int $attachment_id ): string {
		$own = (string) get_post_meta( $attachment_id, self::META, true );
		if ( 'none' === $own ) {
			return '';
		}
		if ( '' !== $own ) {
			return $own;
		}
		foreach ( self::all() as $id => $row ) {
			if ( '' !== (string) get_post_meta( $attachment_id, (string) $row['meta'], true ) ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Display name of an optimizer id.
	 *
	 * @param string $source Optimizer id.
	 */
	public static function label( string $source ): string {
		$all = self::all();

		return (string) ( $all[ $source ]['label'] ?? $source );
	}

	/**
	 * The backup of the untouched original that the foreign optimizer kept, or null.
	 *
	 * @param string $source Optimizer id.
	 * @param string $path   Absolute path of the main file.
	 */
	public static function backup_file( string $source, string $path ): ?string {
		$all = self::all();
		if ( empty( $all[ $source ]['backup'] ) || ! is_callable( $all[ $source ]['backup'] ) ) {
			return null;
		}
		$found = call_user_func( $all[ $source ]['backup'], $path );

		return is_string( $found ) && is_file( $found ) ? $found : null;
	}

	/**
	 * Size of the main file before the foreign optimizer, 0 when unknown.
	 *
	 * @param string $source Optimizer id.
	 * @param string $path   Absolute path of the main file.
	 */
	public static function original_size( string $source, string $path ): int {
		$backup = self::backup_file( $source, $path );

		return null === $backup ? 0 : (int) filesize( $backup );
	}

	/**
	 * Squeeze keeps the original next to the file as "<name>.bak.<ext>". For a "-scaled" file it
	 * may have backed up the unscaled upload instead.
	 *
	 * @param string $path Absolute path of the main file.
	 */
	public static function squeeze_backup( string $path ): ?string {
		$info      = pathinfo( $path );
		$extension = $info['extension'] ?? '';
		foreach ( array_unique( array( $info['filename'], (string) preg_replace( '/-scaled$/', '', $info['filename'] ) ) ) as $name ) {
			$candidate = $info['dirname'] . '/' . $name . '.bak.' . $extension;
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
