<?php
/**
 * Image Optimizer - Media Library integration: file size + optimization
 * columns (sortable by size), Optimize / Restore buttons in the attachment
 * details and the "Restore original" bulk action.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class MediaLibrary {

	/** Cached file size of the attachment's main file (used for sorting). */
	public const META_FILESIZE = '_uxstudio_io_filesize';

	private const BULK_RESTORE = 'uxstudio_io_restore';
	private const SORT_KEY     = 'uxstudio_io_size';
	private const MIME         = array( 'image/jpeg', 'image/png', 'image/gif' );

	private Module $module;

	private Optimizer $optimizer;

	/**
	 * @param Module    $module    Owning module.
	 * @param Optimizer $optimizer Optimizer (backup lookup).
	 */
	public function __construct( Module $module, Optimizer $optimizer ) {
		$this->module    = $module;
		$this->optimizer = $optimizer;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'manage_media_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_upload_sortable_columns', array( $this, 'sortable_columns' ) );
		add_filter( 'posts_clauses', array( $this, 'sort_clauses' ), 10, 2 );

		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );

		add_action( 'wp_ajax_uxstudio_io_optimize', array( $this, 'ajax_optimize' ) );
		add_action( 'wp_ajax_uxstudio_io_restore', array( $this, 'ajax_restore' ) );

		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );

		add_action( 'add_attachment', array( $this, 'store_filesize' ) );
		add_action( 'edit_attachment', array( $this, 'store_filesize' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_for_media' ) );
	}

	// ------------------------------------------------------------------
	// Assets.
	// ------------------------------------------------------------------

	/**
	 * Upload list + attachment edit screen.
	 *
	 * @param string $hook Admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( 'upload.php' === $hook || 'post.php' === $hook ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Media modal / grid view.
	 */
	public function enqueue_for_media(): void {
		$this->enqueue_assets();
	}

	/**
	 * Script + style (once per request).
	 */
	private function enqueue_assets(): void {
		static $done = false;
		if ( $done || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$done = true;

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;
		$base    = UXSTUDIO_URL . 'includes/Modules/ImageOptimizer/assets/';

		wp_enqueue_style( 'uxstudio-io-media', $base . 'media-library.css', array(), $version );
		wp_enqueue_script( 'uxstudio-io-media', $base . 'media-library.js', array( 'jquery' ), $version, true );
		wp_localize_script(
			'uxstudio-io-media',
			'uxsImageOptimizer',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'processing'     => __( 'Optimizing...', 'ux-studio' ),
					'restoring'      => __( 'Restoring...', 'ux-studio' ),
					'optimize'       => __( 'Optimize', 'ux-studio' ),
					'restore'        => __( 'Restore original', 'ux-studio' ),
					'confirmRestore' => __( 'Restore the original file? The optimized version will be replaced.', 'ux-studio' ),
					'connection'     => __( 'Connection error.', 'ux-studio' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Data helpers.
	// ------------------------------------------------------------------

	/**
	 * Whether the attachment is an image the optimizer can process.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private function is_supported( int $attachment_id ): bool {
		return in_array( (string) get_post_mime_type( $attachment_id ), self::MIME, true );
	}

	/**
	 * Optimization state of an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{optimized:bool,original:int,current:int,percent:int,webp:int,avif:int,backup:bool}
	 */
	private function info( int $attachment_id ): array {
		$path    = (string) get_attached_file( $attachment_id );
		$current = ( '' !== $path && is_file( $path ) ) ? (int) filesize( $path ) : 0;

		$optimized = '' !== (string) get_post_meta( $attachment_id, Optimizer::META_OPTIMIZED_SIZE, true );
		$original  = $optimized ? (int) get_post_meta( $attachment_id, Optimizer::META_ORIGINAL_SIZE, true ) : $current;
		$final     = $optimized ? (int) get_post_meta( $attachment_id, Optimizer::META_OPTIMIZED_SIZE, true ) : $current;

		$webp = 0;
		$avif = 0;
		if ( '' !== $path ) {
			$parts = pathinfo( $path );
			$stem  = $parts['dirname'] . '/' . $parts['filename'];
			$webp  = is_file( $stem . '.webp' ) ? (int) filesize( $stem . '.webp' ) : 0;
			$avif  = is_file( $stem . '.avif' ) ? (int) filesize( $stem . '.avif' ) : 0;
		}

		return array(
			'optimized' => $optimized,
			'original'  => $original,
			'current'   => $current,
			'percent'   => $optimized && $original > 0 ? (int) round( ( $original - $final ) / $original * 100 ) : 0,
			'webp'      => $webp,
			'avif'      => $avif,
			'backup'    => $this->optimizer->has_backup( $attachment_id ),
		);
	}

	/**
	 * Cache the main file size in post meta (sort key).
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function store_filesize( int $attachment_id ): void {
		$path = get_attached_file( $attachment_id );
		if ( $path && is_file( $path ) ) {
			update_post_meta( $attachment_id, self::META_FILESIZE, (int) filesize( $path ) );
		}
	}

	// ------------------------------------------------------------------
	// Columns.
	// ------------------------------------------------------------------

	/**
	 * Add the size and optimization columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_columns( array $columns ): array {
		$columns['uxstudio_io_size'] = __( 'File size', 'ux-studio' );
		$columns['uxstudio_io']      = __( 'Optimization', 'ux-studio' );

		return $columns;
	}

	/**
	 * Make the size column sortable.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string|array<int, mixed>>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['uxstudio_io_size'] = self::SORT_KEY;

		return $columns;
	}

	/**
	 * Order the media list by the cached file size.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param WP_Query              $query   Query.
	 * @return array<string, string>
	 */
	public function sort_clauses( array $clauses, WP_Query $query ): array {
		global $wpdb;

		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) || self::SORT_KEY !== $query->get( 'orderby' ) ) {
			return $clauses;
		}

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS uxs_io_fs ON ({$wpdb->posts}.ID = uxs_io_fs.post_id AND uxs_io_fs.meta_key = %s)",
			self::META_FILESIZE
		);

		$clauses['orderby'] = 'ASC' === strtoupper( (string) $query->get( 'order' ) )
			? 'COALESCE(CAST(uxs_io_fs.meta_value AS UNSIGNED), 999999999999) ASC'
			: 'COALESCE(CAST(uxs_io_fs.meta_value AS UNSIGNED), 0) DESC';

		return $clauses;
	}

	/**
	 * Render a column cell.
	 *
	 * @param string $column        Column key.
	 * @param int    $attachment_id Attachment id.
	 */
	public function render_column( string $column, int $attachment_id ): void {
		if ( 'uxstudio_io_size' === $column ) {
			$this->render_size( $attachment_id );
		} elseif ( 'uxstudio_io' === $column ) {
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				echo '&mdash;';
				return;
			}
			echo $this->status_html( $attachment_id, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
	}

	/**
	 * File size cell (+ next-gen siblings).
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private function render_size( int $attachment_id ): void {
		$size = (int) get_post_meta( $attachment_id, self::META_FILESIZE, true );
		if ( $size <= 0 ) {
			$this->store_filesize( $attachment_id );
			$size = (int) get_post_meta( $attachment_id, self::META_FILESIZE, true );
		}
		if ( $size <= 0 ) {
			echo '&mdash;';
			return;
		}

		echo '<span class="uxs-io-size">' . esc_html( size_format( $size ) ) . '</span>';

		if ( wp_attachment_is_image( $attachment_id ) ) {
			$info = $this->info( $attachment_id );
			if ( $info['webp'] > 0 ) {
				echo '<br><span class="uxs-io-nextgen">+ ' . esc_html( size_format( $info['webp'] ) ) . ' WebP</span>';
			}
			if ( $info['avif'] > 0 ) {
				echo '<br><span class="uxs-io-nextgen">+ ' . esc_html( size_format( $info['avif'] ) ) . ' AVIF</span>';
			}
		}
	}

	// ------------------------------------------------------------------
	// Optimization status + buttons (list column and attachment details).
	// ------------------------------------------------------------------

	/**
	 * Escaped HTML: status line, savings and Optimize / Restore controls.
	 *
	 * @param int  $attachment_id Attachment id.
	 * @param bool $detail        Attachment-details variant (more text).
	 */
	private function status_html( int $attachment_id, bool $detail ): string {
		if ( ! $this->is_supported( $attachment_id ) ) {
			return '<span class="uxs-io-na">' . esc_html__( 'Not supported', 'ux-studio' ) . '</span>';
		}

		$path = (string) get_attached_file( $attachment_id );
		if ( '' === $path || ! is_file( $path ) ) {
			return '&mdash;';
		}

		$info     = $this->info( $attachment_id );
		$can_edit = current_user_can( 'edit_post', $attachment_id );
		$nonce    = wp_create_nonce( 'uxstudio_io_' . $attachment_id );
		$html     = '<div class="uxs-io" data-id="' . esc_attr( (string) $attachment_id ) . '">';

		if ( $info['optimized'] ) {
			$final = (int) get_post_meta( $attachment_id, Optimizer::META_OPTIMIZED_SIZE, true );
			$html .= '<span class="uxs-io-badge">' . esc_html__( 'Optimized', 'ux-studio' ) . ' &#10003;</span>';
			$html .= '<br><span class="uxs-io-compare">' . esc_html( size_format( $info['original'] ) . ' → ' . size_format( $final ) ) . '</span>';
			$html .= '<br><span class="uxs-io-saved">' . esc_html( sprintf( /* translators: %d: percent saved */ __( 'saved %d%%', 'ux-studio' ), max( 0, $info['percent'] ) ) ) . '</span>';
		} else {
			$html .= '<span class="uxs-io-pending">' . esc_html__( 'Not optimized', 'ux-studio' ) . '</span>';
			if ( $detail ) {
				$html .= '<br><span class="uxs-io-compare">' . esc_html( sprintf( /* translators: %s: file size */ __( 'File size: %s', 'ux-studio' ), size_format( $info['current'] ) ) ) . '</span>';
			}
		}

		if ( $can_edit ) {
			$html .= '<div class="uxs-io-actions">';
			$html .= sprintf(
				'<button type="button" class="button button-small uxs-io-optimize" data-id="%d" data-nonce="%s">%s</button>',
				$attachment_id,
				esc_attr( $nonce ),
				esc_html( $info['optimized'] ? __( 'Optimize again', 'ux-studio' ) : __( 'Optimize', 'ux-studio' ) )
			);
			if ( $info['backup'] ) {
				$html .= sprintf(
					' <a href="#" class="uxs-io-restore" data-id="%d" data-nonce="%s">%s</a>',
					$attachment_id,
					esc_attr( $nonce ),
					esc_html__( 'Restore original', 'ux-studio' )
				);
			}
			$html .= '<span class="uxs-io-status" role="status"></span></div>';
		}

		return $html . '</div>';
	}

	/**
	 * Optimization row in the attachment details.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @param WP_Post              $post   Attachment.
	 * @return array<string, mixed>
	 */
	public function attachment_field( array $fields, WP_Post $post ): array {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $fields;
		}

		$fields['uxstudio_io'] = array(
			'label' => __( 'Optimization', 'ux-studio' ),
			'input' => 'html',
			'html'  => $this->status_html( $post->ID, true ),
		);

		return $fields;
	}

	// ------------------------------------------------------------------
	// AJAX.
	// ------------------------------------------------------------------

	/**
	 * Validate nonce + capability of an AJAX action and return the id.
	 */
	private function ajax_attachment_id(): int {
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right below.
		$nonce         = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( $attachment_id <= 0 || ! wp_verify_nonce( $nonce, 'uxstudio_io_' . $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'ux-studio' ) ), 403 );
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this item.', 'ux-studio' ) ), 403 );
		}

		return $attachment_id;
	}

	/**
	 * Optimize one attachment.
	 */
	public function ajax_optimize(): void {
		$attachment_id = $this->ajax_attachment_id();
		$before        = $this->info( $attachment_id );

		$result = $this->module->optimize( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$saved = max( 0, (int) $result['original_size'] - (int) $result['optimized_size'] );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: saved size, 2: percent */
					__( 'Optimized! Saved %1$s (%2$d%%).', 'ux-studio' ),
					size_format( $saved ),
					$result['original_size'] > 0 ? (int) round( $saved / $result['original_size'] * 100 ) : 0
				),
				'html'    => $this->status_html( $attachment_id, true ),
				'before'  => $before['current'],
			)
		);
	}

	/**
	 * Restore one attachment's original.
	 */
	public function ajax_restore(): void {
		$attachment_id = $this->ajax_attachment_id();

		$result = $this->module->restore( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'The original was restored.', 'ux-studio' ),
				'html'    => $this->status_html( $attachment_id, true ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Bulk action: restore originals.
	// ------------------------------------------------------------------

	/**
	 * Add "Restore original" to the media list bulk actions.
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		$actions[ self::BULK_RESTORE ] = __( 'Restore original (Image Optimizer)', 'ux-studio' );

		return $actions;
	}

	/**
	 * Process the bulk restore. Core verified the bulk-media nonce before this
	 * filter runs; each item is checked with edit_post.
	 *
	 * @param string          $redirect Redirect URL.
	 * @param string          $action   Bulk action.
	 * @param array<int, int> $post_ids Selected attachment ids.
	 */
	public function handle_bulk( string $redirect, string $action, array $post_ids ): string {
		if ( self::BULK_RESTORE !== $action ) {
			return $redirect;
		}

		$restored = 0;
		$skipped  = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! current_user_can( 'edit_post', $post_id ) || ! $this->optimizer->has_backup( $post_id ) ) {
				++$skipped;
				continue;
			}

			if ( is_wp_error( $this->module->restore( $post_id ) ) ) {
				++$skipped;
			} else {
				++$restored;
			}
		}

		return add_query_arg(
			array(
				'uxstudio_io_restored' => $restored,
				'uxstudio_io_skipped'  => $skipped,
			),
			$redirect
		);
	}

	/**
	 * Notice after the bulk restore.
	 */
	public function bulk_notice(): void {
		if ( ! isset( $_GET['uxstudio_io_restored'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$restored = absint( wp_unslash( $_GET['uxstudio_io_restored'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped  = isset( $_GET['uxstudio_io_skipped'] ) ? absint( wp_unslash( $_GET['uxstudio_io_skipped'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: %d: number of images */
			_n( '%d original restored.', '%d originals restored.', $restored, 'ux-studio' ),
			$restored
		);
		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of images */
				__( '%d skipped (no backup of the original, or no permission).', 'ux-studio' ),
				$skipped
			);
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
