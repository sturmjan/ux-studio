<?php
/**
 * Heuristic scanner for image attachments not referenced anywhere on the site.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only detection of "unused" image attachments plus a recoverable trash
 * action. Detection is deliberately conservative and multi-source: an image is
 * only reported as unused when it is not found as a featured image, in any
 * post/widget/option content (by wp-image-{id}, wp-att-{id}, data-id="{id}" or
 * by its uploads URL), as a post_parent attachment, in term meta or in the
 * site logo/icon. Because the heuristic can never be perfect, this class NEVER
 * hard-deletes: trash_ids() re-verifies each id is still detected as unused and
 * then calls wp_trash_post(), which is fully recoverable from the WordPress
 * trash. Results are cached in a transient to keep the listing responsive.
 */
final class UnusedScanner {

	private const CACHE_KEY = 'uxstudio_io_unused_ids';
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Paginated listing of unused images with display metadata.
	 *
	 * @param int  $page     1-based page.
	 * @param int  $per_page Items per page (clamped 1-100).
	 * @param bool $refresh  Force a re-scan (ignore cache).
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int,total_size:int}
	 */
	public function listing( int $page = 1, int $per_page = 40, bool $refresh = false ): array {
		$per_page = max( 1, min( 100, $per_page ) );
		$ids      = $this->unused_ids( $refresh );
		$total    = count( $ids );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = max( 1, min( $page, $pages ) );
		$slice    = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );

		$items      = array();
		$total_size = 0;
		foreach ( $ids as $id ) {
			$total_size += $this->file_size( $id );
		}

		if ( ! empty( $slice ) ) {
			update_meta_cache( 'post', $slice );
			foreach ( $slice as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				$items[] = array(
					'id'          => $id,
					'title'       => get_the_title( $id ),
					'filename'    => basename( (string) get_attached_file( $id ) ),
					'file_size'   => $this->file_size( $id ),
					'thumb_url'   => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
					'edit_url'    => (string) get_edit_post_link( $id, 'raw' ),
					'upload_date' => $post->post_date,
					'mime_type'   => $post->post_mime_type,
				);
			}
		}

		return array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $pages,
			'total_size'  => $total_size,
		);
	}

	/**
	 * Move the given attachment ids to trash (recoverable), re-verifying each is
	 * still detected as unused first.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array{trashed:int,skipped:int}
	 */
	public function trash_ids( array $ids ): array {
		$unused  = $this->unused_ids( false );
		$trashed = 0;
		$skipped = 0;

		foreach ( $ids as $raw ) {
			$id = absint( $raw );
			if ( ! $id || 'attachment' !== get_post_type( $id ) || ! in_array( $id, $unused, true ) ) {
				++$skipped;
				continue;
			}
			if ( wp_trash_post( $id ) ) {
				++$trashed;
			} else {
				++$skipped;
			}
		}

		$this->clear_cache();

		return array( 'trashed' => $trashed, 'skipped' => $skipped );
	}

	/**
	 * Drop the cached scan so the next listing re-scans.
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * On-disk size of an attachment's main file (best-effort).
	 *
	 * @param int $id Attachment id.
	 */
	private function file_size( int $id ): int {
		$file = get_attached_file( $id );
		return ( $file && is_file( $file ) ) ? (int) filesize( $file ) : 0;
	}

	/**
	 * Compute (and cache) the list of unused image attachment ids.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return int[]
	 */
	public function unused_ids( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$all  = $this->all_image_ids();
		$used = $this->used_image_ids();

		$unused = array_values( array_diff( $all, $used ) );
		set_transient( self::CACHE_KEY, $unused, self::CACHE_TTL );

		return $unused;
	}

	/**
	 * All image attachment ids in the library.
	 *
	 * @return int[]
	 */
	private function all_image_ids(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			 AND post_mime_type LIKE 'image/%'
			 AND post_status = 'inherit'
			 ORDER BY ID DESC"
		);
		return array_map( 'intval', $rows );
	}

	/**
	 * Union of every image id we can prove is referenced somewhere.
	 *
	 * @return int[]
	 */
	private function used_image_ids(): array {
		$used = array();

		$this->collect_featured( $used );
		$this->collect_from_content( $used );
		$this->collect_post_parents( $used );
		$this->collect_term_meta( $used );
		$this->collect_site_logo( $used );
		$this->collect_from_options( $used );

		return array_values( array_unique( array_filter( array_map( 'intval', $used ) ) ) );
	}

	/**
	 * Featured images (_thumbnail_id).
	 *
	 * @param int[] $used Accumulator (by reference).
	 */
	private function collect_featured( array &$used ): void {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT DISTINCT CAST(meta_value AS UNSIGNED)
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_thumbnail_id' AND meta_value > 0"
		);
		foreach ( $ids as $id ) {
			$used[] = (int) $id;
		}
	}

	/**
	 * Ids referenced inside post_content: wp-image-{id}, wp-att-{id},
	 * data-id="{id}" and uploads URLs resolved back to ids.
	 *
	 * @param int[] $used Accumulator (by reference).
	 */
	private function collect_from_content( array &$used ): void {
		global $wpdb;

		$contents = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_status NOT IN ('trash','auto-draft')
			 AND post_type NOT IN ('revision')
			 AND ( post_content LIKE '%wp-image-%'
			    OR post_content LIKE '%wp-att-%'
			    OR post_content LIKE '%data-id=%'
			    OR post_content LIKE '%/uploads/%' )"
		);

		$this->scan_strings_for_ids( (array) $contents, $used );
	}

	/**
	 * Extract attachment ids from a set of content strings.
	 *
	 * @param string[] $strings Content blobs.
	 * @param int[]    $used    Accumulator (by reference).
	 */
	private function scan_strings_for_ids( array $strings, array &$used ): void {
		$upload_dir = wp_upload_dir();
		$baseurl    = isset( $upload_dir['baseurl'] ) ? (string) $upload_dir['baseurl'] : '';

		foreach ( $strings as $content ) {
			if ( ! is_string( $content ) || '' === $content ) {
				continue;
			}

			foreach ( array( '/wp-image-(\d+)/i', '/wp-att-(\d+)/i', '/data-id=["\'](\d+)["\']/i' ) as $pattern ) {
				if ( preg_match_all( $pattern, $content, $m ) ) {
					foreach ( $m[1] as $id ) {
						$used[] = (int) $id;
					}
				}
			}

			// Resolve uploads URLs (ignoring the -WxH size suffix) back to ids.
			if ( '' !== $baseurl && preg_match_all( '/' . preg_quote( $baseurl, '/' ) . '\/[^\s"\'<>]+\.(?:jpe?g|png|gif|webp|avif)/i', $content, $urls ) ) {
				foreach ( $urls[0] as $url ) {
					$normalized = preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $url );
					$id         = attachment_url_to_postid( (string) $normalized );
					if ( $id ) {
						$used[] = (int) $id;
					}
				}
			}
		}
	}

	/**
	 * Images attached to a post via post_parent.
	 *
	 * @param int[] $used Accumulator (by reference).
	 */
	private function collect_post_parents( array &$used ): void {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			 AND post_mime_type LIKE 'image/%'
			 AND post_parent > 0"
		);
		foreach ( $ids as $id ) {
			$used[] = (int) $id;
		}
	}

	/**
	 * Term meta that commonly stores attachment ids (category/product images).
	 *
	 * @param int[] $used Accumulator (by reference).
	 */
	private function collect_term_meta( array &$used ): void {
		global $wpdb;
		$keys         = array( 'image', 'thumbnail_id', 'product_image', 'category_image', '_thumbnail_id' );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows         = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->termmeta}
				 WHERE meta_key IN ($placeholders) AND meta_value REGEXP '^[0-9]+$'",
				...$keys
			)
		);
		foreach ( $rows as $id ) {
			$used[] = (int) $id;
		}
	}

	/**
	 * Site icon and custom logo.
	 *
	 * @param int[] $used Accumulator (by reference).
	 */
	private function collect_site_logo( array &$used ): void {
		$icon = (int) get_option( 'site_icon' );
		if ( $icon ) {
			$used[] = $icon;
		}
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			$used[] = $logo;
		}
	}

	/**
	 * Widget/block and other option content that embeds uploads URLs or
	 * wp-image classes (covers block widgets, page builders storing in options).
	 *
	 * @param int[] $used Accumulator (by reference).
	 */
	private function collect_from_options( array &$used ): void {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT option_value FROM {$wpdb->options}
			 WHERE ( option_name LIKE 'widget_%' OR option_name LIKE 'theme_mods_%' )
			 AND ( option_value LIKE '%wp-image-%'
			    OR option_value LIKE '%data-id=%'
			    OR option_value LIKE '%/uploads/%' )"
		);
		$this->scan_strings_for_ids( (array) $rows, $used );
	}
}
