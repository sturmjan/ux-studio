<?php
/**
 * Download Files module - protected downloadable files served via a
 * tokenized, download-counted link, backed by the standard WP media library.
 *
 * Unified concept (studio + legacy):
 *  - Studio side: a managed "download library" of media-library attachments,
 *    each served through a per-row HMAC token (never a raw path from client
 *    input), with a persistent download counter and optional login gating.
 *  - Legacy side: a frontend [download_files] shortcode that renders a styled
 *    list of those files - selectable by explicit ids, by a free-form category
 *    label, or by association with a post - whose links all go through the same
 *    tokenized/counted serve endpoint so stats and access checks stay unified.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DownloadFiles;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Files are never uploaded through a custom endpoint: the admin UI picks an
 * existing attachment via the WP media library (wp.media) and this module
 * only ever persists the resulting attachment_id, never a filesystem path
 * from client input. Downloads are served through a per-row HMAC token
 * (see build_download_token()/verify_download_token()) rather than a WP
 * nonce, because a "download link" is meant to keep working indefinitely
 * and nonces expire.
 */
final class Module extends BaseModule {

	/**
	 * Module schema version. Bumped from 1 -> 2 to add the `category` and
	 * `post_id` columns that power the frontend shortcode's filtering /
	 * post-association. dbDelta() adds the new columns in place on upgrade.
	 */
	private const SCHEMA_VERSION = 2;

	/**
	 * Guard so the shortcode's inline <style> block is printed at most once
	 * per request even when several [download_files] shortcodes appear.
	 */
	private static bool $style_printed = false;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_shortcode( 'download_files', array( $this, 'render_shortcode' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'download-files',
			self::SCHEMA_VERSION,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				// dbDelta() diffs this definition against the live table and
				// ADDs any missing columns, so the same statement handles both
				// the initial create and the v1 -> v2 column additions.
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_download_files (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						title VARCHAR(255) NOT NULL DEFAULT '',
						attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
						require_login TINYINT(1) NOT NULL DEFAULT 0,
						category VARCHAR(100) NOT NULL DEFAULT '',
						post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						KEY attachment_id (attachment_id),
						KEY category (category),
						KEY post_id (post_id)
					) {$charset};"
				);
			}
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
				'key'     => 'require_login_default',
				'type'    => 'toggle',
				'label'   => __( 'Require login by default', 'ux-studio' ),
				'help'    => __( 'Default value of "require login" for newly added files.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'heading_text',
				'type'    => 'text',
				'label'   => __( 'Shortcode heading', 'ux-studio' ),
				'help'    => __( 'Default heading shown above the file list rendered by the [download_files] shortcode. Override per-instance with the heading="" attribute; use heading="" to hide it.', 'ux-studio' ),
				'default' => __( 'Files to download', 'ux-studio' ),
			),
			array(
				'key'     => 'hide_login_required',
				'type'    => 'toggle',
				'label'   => __( 'Hide login-only files from guests', 'ux-studio' ),
				'help'    => __( 'When on, files that require login are omitted from the shortcode list for visitors who are not logged in (the download endpoint still enforces login regardless).', 'ux-studio' ),
				'default' => true,
			),
		);
	}

	/**
	 * All files, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_files(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, title, attachment_id, download_count, require_login, category, post_id FROM {$wpdb->prefix}uxstudio_download_files ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_row' ), $rows );
	}

	/**
	 * One file row by id.
	 *
	 * @param int $id Row id.
	 */
	public function get_file( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, created_at, title, attachment_id, download_count, require_login, category, post_id FROM {$wpdb->prefix}uxstudio_download_files WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_row( $row ) : null;
	}

	/**
	 * Create a new protected file entry.
	 *
	 * @param array $data { title: string, attachment_id: int, require_login?: bool, category?: string, post_id?: int }.
	 * @return array<string, mixed>
	 */
	public function create_file( array $data ): array {
		global $wpdb;

		$require_login = array_key_exists( 'require_login', $data )
			? (bool) $data['require_login']
			: (bool) $this->settings->get( 'require_login_default', true );

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_download_files",
			array(
				'created_at'     => current_time( 'mysql' ),
				'title'          => mb_substr( (string) $data['title'], 0, 255 ),
				'attachment_id'  => absint( $data['attachment_id'] ),
				'download_count' => 0,
				'require_login'  => $require_login ? 1 : 0,
				'category'       => isset( $data['category'] ) ? mb_substr( sanitize_text_field( (string) $data['category'] ), 0, 100 ) : '',
				'post_id'        => isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%d' )
		);

		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'download-files', 'create', 'attachment', absint( $data['attachment_id'] ), array( 'file_id' => $id ) );

		return (array) $this->get_file( $id );
	}

	/**
	 * Update an existing file entry (title / require_login / category / post_id).
	 *
	 * @param int   $id   Row id.
	 * @param array $data Fields to update.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_file( int $id, array $data ) {
		global $wpdb;

		$existing = $this->get_file( $id );
		if ( null === $existing ) {
			return new WP_Error( 'uxstudio_not_found', __( 'File not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$update = array();
		$format = array();

		if ( array_key_exists( 'title', $data ) ) {
			$update['title'] = mb_substr( (string) $data['title'], 0, 255 );
			$format[]        = '%s';
		}
		if ( array_key_exists( 'require_login', $data ) ) {
			$update['require_login'] = (bool) $data['require_login'] ? 1 : 0;
			$format[]                = '%d';
		}
		if ( array_key_exists( 'category', $data ) ) {
			$update['category'] = mb_substr( sanitize_text_field( (string) $data['category'] ), 0, 100 );
			$format[]           = '%s';
		}
		if ( array_key_exists( 'post_id', $data ) ) {
			$update['post_id'] = absint( $data['post_id'] );
			$format[]          = '%d';
		}

		if ( empty( $update ) ) {
			return $existing;
		}

		$wpdb->update(
			"{$wpdb->prefix}uxstudio_download_files",
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		ActivityLog::log( 'download-files', 'update', 'attachment', (int) $existing['attachment_id'], array( 'file_id' => $id ) );

		return (array) $this->get_file( $id );
	}

	/**
	 * Delete a file entry (does not delete the underlying media attachment).
	 *
	 * @param int $id Row id.
	 */
	public function delete_file( int $id ): bool {
		global $wpdb;

		$existing = $this->get_file( $id );
		if ( null === $existing ) {
			return false;
		}

		$deleted = $wpdb->delete( "{$wpdb->prefix}uxstudio_download_files", array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			ActivityLog::log( 'download-files', 'delete', 'attachment', (int) $existing['attachment_id'], array( 'file_id' => $id ) );
		}

		return (bool) $deleted;
	}

	/**
	 * Deterministic per-row download token. HMAC of the row id keyed with the
	 * site's auth salt; unlike a nonce this never expires, which is what a
	 * "download link" is expected to do.
	 *
	 * @param int $id Row id.
	 */
	public function build_download_token( int $id ): string {
		return hash_hmac( 'sha256', (string) $id, wp_salt( 'auth' ) );
	}

	/**
	 * Constant-time verification of a download token.
	 *
	 * @param int    $id    Row id.
	 * @param string $token Token to verify.
	 */
	public function verify_download_token( int $id, string $token ): bool {
		return hash_equals( $this->build_download_token( $id ), $token );
	}

	/**
	 * Stream the protected file to the client after validating the token,
	 * optional login requirement and that the resolved path stays inside the
	 * uploads directory. Called from RestController::serve(); this method
	 * never returns on success (it exits after streaming), and returns a
	 * WP_Error on any failure so the controller can render it.
	 *
	 * @param int    $id    Row id (uxstudio_download_files.id, NOT the attachment id).
	 * @param string $token Token from the ?token= query param.
	 * @return WP_Error|void
	 */
	public function serve( int $id, string $token ) {
		$file = $this->get_file( $id );
		if ( null === $file ) {
			return new WP_Error( 'uxstudio_not_found', __( 'File not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( ! $this->verify_download_token( $id, $token ) ) {
			return new WP_Error( 'uxstudio_invalid_token', __( 'Invalid or missing download token.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		if ( $file['require_login'] && ! is_user_logged_in() ) {
			return new WP_Error( 'uxstudio_login_required', __( 'You must be logged in to download this file.', 'ux-studio' ), array( 'status' => 401 ) );
		}

		$attachment_id = (int) $file['attachment_id'];
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'uxstudio_missing_attachment', __( 'The underlying media attachment is missing.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'uxstudio_missing_file', __( 'The file is missing on disk.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		// Mandatory defense-in-depth check: even though get_attached_file()
		// should always resolve inside the uploads dir, explicitly reject
		// anything that doesn't before touching the filesystem further.
		$real_path = realpath( $path );
		$real_base = realpath( wp_upload_dir()['basedir'] );
		if ( false === $real_path || false === $real_base || 0 !== strpos( $real_path, $real_base ) ) {
			return new WP_Error( 'uxstudio_path_traversal', __( 'Refused to serve a file outside the uploads directory.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}uxstudio_download_files SET download_count = download_count + 1 WHERE id = %d",
				$id
			)
		);

		ActivityLog::log( 'download-files', 'download', 'attachment', $attachment_id, array( 'file_id' => $id ) );

		$filetype     = wp_check_filetype( basename( $real_path ) );
		$content_type = $filetype['type'] ?: 'application/octet-stream';
		$filename     = sanitize_file_name( basename( $real_path ) );

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $real_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a validated, in-uploads-dir file to the client is the intended purpose of this endpoint.
		readfile( $real_path );
		exit;
	}

	/**
	 * Distinct non-empty category labels currently in use, ascending. Powers
	 * the admin UI's category suggestions.
	 *
	 * @return array<int, string>
	 */
	public function list_categories(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT DISTINCT category FROM {$wpdb->prefix}uxstudio_download_files WHERE category <> '' ORDER BY category ASC"
		);
		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Frontend shortcode: [download_files ids="1,2" category="brochures" post="123" heading="…"].
	 *
	 * Selection precedence: explicit ids, else category, else post association
	 * (defaulting to the current post when rendered inside the loop). Links go
	 * through the tokenized serve endpoint so downloads stay counted and access
	 * checked server-side. Login-only files are hidden from guests when the
	 * "hide_login_required" setting is on.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML (escaped).
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'ids'      => '',
				'category' => '',
				'post'     => '',
				'heading'  => null,
			),
			is_array( $atts ) ? $atts : array(),
			'download_files'
		);

		$files = $this->files_for_shortcode( $atts );
		if ( empty( $files ) ) {
			return '';
		}

		$heading = null === $atts['heading']
			? (string) $this->settings->get( 'heading_text', __( 'Files to download', 'ux-studio' ) )
			: sanitize_text_field( (string) $atts['heading'] );

		ob_start();
		$this->print_shortcode_styles();
		?>
		<div class="uxs-downloads">
			<?php if ( '' !== $heading ) : ?>
				<h3 class="uxs-downloads__title"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>
			<ul class="uxs-downloads__list">
				<?php foreach ( $files as $file ) : ?>
					<li class="uxs-downloads__item">
						<a
							class="uxs-downloads__link uxs-downloads__link--<?php echo esc_attr( $file['type_class'] ); ?>"
							href="<?php echo esc_url( $file['download_url'] ); ?>"
							<?php echo 'pdf' === $file['ext'] ? 'target="_blank" rel="noopener"' : ''; ?>
						>
							<span class="uxs-downloads__icon" aria-hidden="true"><?php echo $file['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, self-generated inline SVG (see file_type_info()). ?></span>
							<span class="uxs-downloads__name"><?php echo esc_html( $file['label'] ); ?></span>
							<?php if ( '' !== $file['meta'] ) : ?>
								<span class="uxs-downloads__meta"><?php echo esc_html( $file['meta'] ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Resolve the shortcode's selected rows and decorate each for rendering.
	 *
	 * @param array $atts Sanitized-ish shortcode attributes.
	 * @return array<int, array<string, mixed>>
	 */
	private function files_for_shortcode( array $atts ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_download_files";

		$ids = array_values(
			array_filter(
				array_map( 'absint', preg_split( '/[\s,]+/', (string) $atts['ids'], -1, PREG_SPLIT_NO_EMPTY ) )
			)
		);
		$category = mb_substr( sanitize_text_field( (string) $atts['category'] ), 0, 100 );
		$post_id  = absint( $atts['post'] );

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// FIELD() preserves the caller's explicit ordering of ids.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, created_at, title, attachment_id, download_count, require_login, category, post_id FROM {$table} WHERE id IN ({$placeholders}) ORDER BY FIELD(id,{$placeholders})",
					array_merge( $ids, $ids )
				),
				ARRAY_A
			);
		} elseif ( '' !== $category ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, created_at, title, attachment_id, download_count, require_login, category, post_id FROM {$table} WHERE category = %s ORDER BY id DESC",
					$category
				),
				ARRAY_A
			);
		} else {
			if ( 0 === $post_id ) {
				$post_id = (int) get_the_ID();
			}
			if ( 0 === $post_id ) {
				return array();
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, created_at, title, attachment_id, download_count, require_login, category, post_id FROM {$table} WHERE post_id = %d ORDER BY id DESC",
					$post_id
				),
				ARRAY_A
			);
		}

		$rows = is_array( $rows ) ? $rows : array();

		$hide_login = (bool) $this->settings->get( 'hide_login_required', true );
		$logged_in  = is_user_logged_in();

		$out = array();
		foreach ( $rows as $row ) {
			$file = $this->format_row( $row );

			if ( $hide_login && $file['require_login'] && ! $logged_in ) {
				continue;
			}

			$decorated = $this->decorate_for_display( $file );
			if ( null !== $decorated ) {
				$out[] = $decorated;
			}
		}

		return $out;
	}

	/**
	 * Add display fields (label, extension, human size, icon) to a formatted
	 * row for the frontend list. Returns null if the underlying attachment is
	 * gone so dangling entries are silently skipped.
	 *
	 * @param array $file Formatted row from format_row().
	 * @return array<string, mixed>|null
	 */
	private function decorate_for_display( array $file ): ?array {
		$attachment_id = (int) $file['attachment_id'];
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}

		$path     = get_attached_file( $attachment_id );
		$basename = $path ? basename( $path ) : '';
		$ext      = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

		$size_str = '';
		if ( $path && is_file( $path ) ) {
			$bytes    = filesize( $path );
			$size_str = false === $bytes ? '' : size_format( $bytes );
		}

		$meta = '';
		if ( '' !== $ext && '' !== $size_str ) {
			$meta = strtoupper( $ext ) . ' · ' . $size_str;
		} elseif ( '' !== $ext ) {
			$meta = strtoupper( $ext );
		} elseif ( '' !== $size_str ) {
			$meta = $size_str;
		}

		$label = '' !== (string) $file['title'] ? (string) $file['title'] : $basename;
		$info  = $this->file_type_info( $ext );

		$file['label']      = $label;
		$file['ext']        = $ext;
		$file['meta']       = $meta;
		$file['icon']       = $info['icon'];
		$file['type_class'] = $info['class'];

		return $file;
	}

	/**
	 * Print the shortcode's inline stylesheet exactly once per request.
	 * Deliberately inline (per module rules: no src/style.scss edits, no
	 * separate enqueued asset) and dependency-free (no dashicons).
	 */
	private function print_shortcode_styles(): void {
		if ( self::$style_printed ) {
			return;
		}
		self::$style_printed = true;
		?>
		<style>
			.uxs-downloads{margin:1.5rem 0}
			.uxs-downloads__title{margin:0 0 .75rem;font-size:1.05rem;font-weight:600}
			.uxs-downloads__list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem}
			.uxs-downloads__item{margin:0}
			.uxs-downloads__link{display:flex;align-items:center;gap:.75rem;padding:.65rem .85rem;border:1px solid rgba(0,0,0,.12);border-radius:8px;text-decoration:none;color:inherit;background:#fff;transition:border-color .15s ease,box-shadow .15s ease}
			.uxs-downloads__link:hover{border-color:rgba(0,0,0,.28);box-shadow:0 1px 4px rgba(0,0,0,.08)}
			.uxs-downloads__icon{flex:0 0 auto;display:inline-flex;color:#555}
			.uxs-downloads__name{flex:1 1 auto;font-weight:500;word-break:break-word}
			.uxs-downloads__meta{flex:0 0 auto;font-size:.8rem;color:#777;white-space:nowrap}
			.uxs-downloads__link--pdf .uxs-downloads__icon{color:#d93025}
			.uxs-downloads__link--doc .uxs-downloads__icon{color:#2a5699}
			.uxs-downloads__link--xls .uxs-downloads__icon{color:#217346}
			.uxs-downloads__link--ppt .uxs-downloads__icon{color:#c43e1c}
			.uxs-downloads__link--zip .uxs-downloads__icon{color:#b8860b}
			.uxs-downloads__link--img .uxs-downloads__icon{color:#6b3fa0}
		</style>
		<?php
	}

	/**
	 * Map a file extension to a CSS class + inline SVG icon. Icons are static,
	 * self-authored SVG (no dashicons), safe to echo without escaping.
	 *
	 * @param string $ext Lowercase file extension.
	 * @return array{class:string,icon:string}
	 */
	private function file_type_info( string $ext ): array {
		$doc       = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
		$doc_lines = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
		$table     = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><rect x="8" y="12" width="8" height="6" rx="1"/></svg>';
		$img       = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
		$zip       = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/></svg>';
		$pres      = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><rect x="8" y="12" width="8" height="4" rx="1"/></svg>';

		$types = array(
			'pdf' => array( 'class' => 'pdf', 'icon' => $doc ),
			'doc' => array( 'class' => 'doc', 'icon' => $doc_lines ),
			'xls' => array( 'class' => 'xls', 'icon' => $table ),
			'ppt' => array( 'class' => 'ppt', 'icon' => $pres ),
			'img' => array( 'class' => 'img', 'icon' => $img ),
			'zip' => array( 'class' => 'zip', 'icon' => $zip ),
			'txt' => array( 'class' => 'txt', 'icon' => $doc_lines ),
		);

		$aliases = array(
			'docx' => 'doc',
			'rtf'  => 'doc',
			'odt'  => 'doc',
			'xlsx' => 'xls',
			'csv'  => 'xls',
			'ods'  => 'xls',
			'pptx' => 'ppt',
			'jpeg' => 'img',
			'jpg'  => 'img',
			'png'  => 'img',
			'gif'  => 'img',
			'webp' => 'img',
			'svg'  => 'img',
			'rar'  => 'zip',
			'7z'   => 'zip',
			'gz'   => 'zip',
		);

		$key = $aliases[ $ext ] ?? $ext;
		return $types[ $key ] ?? array( 'class' => 'other', 'icon' => $doc );
	}

	/**
	 * Normalize a raw DB row for REST output (types, download url).
	 *
	 * @param array $row Raw row from $wpdb.
	 * @return array<string, mixed>
	 */
	private function format_row( array $row ): array {
		$id = (int) $row['id'];
		return array(
			'id'             => $id,
			'created_at'     => $row['created_at'],
			'title'          => $row['title'],
			'attachment_id'  => (int) $row['attachment_id'],
			'download_count' => (int) $row['download_count'],
			'require_login'  => (bool) $row['require_login'],
			'category'       => (string) ( $row['category'] ?? '' ),
			'post_id'        => (int) ( $row['post_id'] ?? 0 ),
			'download_url'   => rest_url( 'uxstudio/v1/download-files/serve/' . $id ) . '?token=' . $this->build_download_token( $id ),
		);
	}
}
