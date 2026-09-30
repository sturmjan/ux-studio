<?php
/**
 * SVG Upload module (ported from the legacy free + pro modules, merged).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SvgUpload;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Allows SVG uploads for selected roles, validating and sanitizing every file
 * on both the upload and sideload paths before it reaches the media library.
 */
final class Module extends BaseModule {

	private const MAX_BYTES = 1048576; // 1 MB.

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'validate_svg_upload' ), 10, 4 );
		add_filter( 'upload_mimes', array( $this, 'add_svg_mime_type' ) );

		// Validate + sanitize on the upload path.
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'validate_svg_file' ), 10, 1 );
		add_filter( 'wp_handle_upload', array( $this, 'sanitize_svg_content' ), 10, 2 );

		// Same guards for the sideload path (media_handle_sideload) so SVGs pulled
		// in by other modules are validated and sanitized too.
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'validate_svg_file' ), 10, 1 );
		add_filter( 'wp_handle_sideload', array( $this, 'sanitize_svg_content' ), 10, 2 );
	}

	/**
	 * Add SVG mime types to the allowed upload types (only for permitted users).
	 *
	 * @param array $mimes Allowed mime types.
	 * @return array
	 */
	public function add_svg_mime_type( $mimes ): array {
		$mimes = is_array( $mimes ) ? $mimes : array();

		if ( ! $this->has_upload_permission() ) {
			return $mimes;
		}

		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';

		return $mimes;
	}

	/**
	 * Verify and allow SVG file uploads.
	 *
	 * @param array  $data     File type data.
	 * @param string $file     Full path of the file.
	 * @param string $filename Name of the file.
	 * @param array  $mimes    Allowed mime types.
	 * @return array Filtered file data.
	 */
	public function validate_svg_upload( $data, $file, $filename, $mimes = array() ): array {
		$mimes    = is_array( $mimes ) ? $mimes : array();
		$filetype = wp_check_filetype( (string) $filename, $mimes );

		if ( 'svg' !== $filetype['ext'] && 'svgz' !== $filetype['ext'] ) {
			return (array) $data;
		}

		if ( ! $this->has_upload_permission() ) {
			return array( 'error' => __( 'You do not have permission to upload SVG files.', 'ux-studio' ) );
		}

		return array(
			'ext'             => $filetype['ext'],
			'type'            => $filetype['type'],
			'proper_filename' => $data['proper_filename'] ?? $filename,
		);
	}

	/**
	 * Validate an uploaded SVG before processing. Whether the file is an SVG is
	 * decided from its extension and its content - never from the
	 * client-supplied $file['type'], which an attacker controls.
	 *
	 * @param array $file Uploaded file data.
	 * @return array Modified file data.
	 */
	public function validate_svg_file( $file ): array {
		$file = (array) $file;
		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $path || ! $this->is_svg( (string) ( $file['name'] ?? '' ), $path ) ) {
			return $file;
		}

		if ( ! $this->has_upload_permission() ) {
			$file['error'] = __( 'You do not have permission to upload SVG files.', 'ux-studio' );
			return $file;
		}

		if ( ( isset( $file['size'] ) && $file['size'] > self::MAX_BYTES ) || (int) @filesize( $path ) > self::MAX_BYTES ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$file['error'] = __( 'SVG file size must be less than 1MB.', 'ux-studio' );
			return $file;
		}

		$svg = $this->read_svg( $path );
		if ( null === $svg || ! Sanitizer::validate( $svg['content'] ) ) {
			$file['error'] = __( 'Invalid SVG file.', 'ux-studio' );
			return $file;
		}

		return $file;
	}

	/**
	 * Sanitize SVG content after WordPress has moved it into place.
	 *
	 * @param array  $file   Uploaded file data.
	 * @param string $action Action being performed.
	 * @return array Modified file data.
	 */
	public function sanitize_svg_content( $file, $action ): array {
		$file = (array) $file;
		$path = (string) ( $file['file'] ?? '' );
		if ( '' === $path || ! is_file( $path ) || ! $this->is_svg( wp_basename( $path ), $path ) ) {
			return $file;
		}

		$svg       = $this->read_svg( $path );
		$sanitized = null !== $svg ? Sanitizer::sanitize( $svg['content'] ) : '';

		// Fail-closed: if sanitization emptied the file, remove it and surface an error.
		if ( '' === $sanitized ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$file['error'] = __( 'The SVG file could not be sanitized safely.', 'ux-studio' );
			return $file;
		}

		file_put_contents( $path, $svg['gzip'] ? (string) gzencode( $sanitized ) : $sanitized ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $file;
	}

	/**
	 * Whether a file is (or pretends to be) an SVG: .svg/.svgz extension, or
	 * content whose root element is <svg> whatever the extension says.
	 *
	 * @param string $name File name (for the extension).
	 * @param string $path File on disk (for the content sniff).
	 */
	private function is_svg( string $name, string $path ): bool {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext || 'svgz' === $ext ) {
			return true;
		}
		$svg = $this->read_svg( $path, 4096 );
		return null !== $svg
			&& (bool) preg_match( '/^\s*(?:<\?xml[^>]*>\s*|<!--.*?-->\s*|<!DOCTYPE[^>]*>\s*)*<svg[\s>\/]/is', $svg['content'] );
	}

	/**
	 * Read a (possibly gzip-compressed, i.e. .svgz) SVG. Decompression is
	 * capped at MAX_BYTES so a gzip bomb cannot exhaust memory.
	 *
	 * @param string $path  File path.
	 * @param int    $limit Bytes of raw file to read (0 = whole file).
	 * @return array{content:string,gzip:bool}|null
	 */
	private function read_svg( string $path, int $limit = 0 ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = $limit > 0 ? file_get_contents( $path, false, null, 0, $limit ) : file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		if ( 0 === strncmp( $raw, "\x1f\x8b", 2 ) ) {
			if ( $limit > 0 ) {
				// Partial gzip stream can't be decoded; judge the whole (capped) file.
				$raw = (string) file_get_contents( $path, false, null, 0, self::MAX_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			$decoded = @gzdecode( $raw, self::MAX_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false === $decoded ? null : array(
				'content' => $decoded,
				'gzip'    => true,
			);
		}
		return array(
			'content' => $raw,
			'gzip'    => false,
		);
	}

	/**
	 * Whether the current user may upload SVGs (per-role allowlist).
	 */
	public function has_upload_permission(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		$allowed = (array) $this->settings->get( 'allowed_roles', array( 'administrator' ) );
		$user    = wp_get_current_user();
		$roles   = (array) $user->roles;

		$permission = array() !== $roles && (bool) array_intersect( $allowed, $roles );

		/**
		 * Filter whether the current user may upload SVG files.
		 *
		 * @param bool $permission Current decision.
		 */
		return (bool) apply_filters( 'ux_studio/svg_upload/has_upload_permission', $permission );
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'allowed_roles',
				'type'    => 'multiselect',
				'label'   => __( 'Allowed roles', 'ux-studio' ),
				'help'    => __( 'Only users with one of these roles may upload SVG files. Defaults to administrators only.', 'ux-studio' ),
				'options' => $this->get_role_options(),
				'default' => array( 'administrator' ),
			),
		);
	}

	/**
	 * Roles that can hold the upload_files capability, for the picker.
	 *
	 * @return array<string, string>
	 */
	private function get_role_options(): array {
		$options = array();
		foreach ( wp_roles()->roles as $role_id => $role ) {
			$role_object = get_role( $role_id );
			if ( ! $role_object || ! $role_object->has_cap( 'upload_files' ) ) {
				continue;
			}
			$options[ $role_id ] = translate_user_role( $role['name'] );
		}
		return $options;
	}
}
