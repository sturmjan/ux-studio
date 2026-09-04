<?php
/**
 * Elementor Import module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ElementorImport;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\AiAssistant\HtmlToElementor;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Imports Elementor content into WordPress pages/posts and exports it back to
 * JSON. Ported and extended from the legacy ux1 module (free + pro merged).
 *
 * Sources: JSON/ZIP upload, pasted JSON, a remote URL (HTML -> Elementor via
 * the shared {@see HtmlToElementor} converter) and pasted HTML.
 *
 * Modes: new_page (create a fresh draft), replace (overwrite an existing post's
 * Elementor content) and append (add to the end of it).
 *
 * The module always boots. Every write/export path guards on Elementor being
 * active; when it is not, the REST layer replies 424 Failed Dependency with a
 * clear "requires Elementor" message instead of ever fataling.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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
	 * Whether Elementor is active. Guards on both the version constant and the
	 * loaded action so it is robust regardless of load order.
	 */
	public function is_elementor_active(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || (bool) did_action( 'elementor/loaded' );
	}

	/**
	 * Whether the shared HTML -> Elementor converter is available (ships with
	 * the AI Assistant module). URL and HTML imports require it.
	 */
	public function is_converter_available(): bool {
		return class_exists( HtmlToElementor::class );
	}

	// ---------------------------------------------------------------------
	// Import: JSON / ZIP file upload (new draft only).
	// ---------------------------------------------------------------------

	/**
	 * Import a validated template payload as a new Elementor draft.
	 *
	 * @param array $template Decoded template data (must contain a 'content' array).
	 * @return array|\WP_Error Result payload or error.
	 */
	public function import_template( array $template ) {
		if ( empty( $template['content'] ) || ! is_array( $template['content'] ) ) {
			return new \WP_Error(
				'uxstudio_invalid_template',
				__( 'Invalid template file. The "content" array with Elementor data is missing.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		return $this->store_elementor(
			$template['content'],
			'new_page',
			0,
			array(
				'title'         => (string) ( $template['title'] ?? __( 'Imported template', 'ux-studio' ) ),
				'post_type'     => (string) ( $template['post_type'] ?? 'page' ),
				'page_settings' => is_array( $template['page_settings'] ?? null ) ? $template['page_settings'] : array(),
				'source'        => 'file',
			)
		);
	}

	/**
	 * Read and decode a template from an uploaded .json or .zip file.
	 *
	 * @param array $file Entry from $request->get_file_params().
	 * @return array|\WP_Error Decoded template or error.
	 */
	public function read_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'uxstudio_no_file', __( 'No file was uploaded.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		// Reject oversized uploads early (5 MB is generous for a template JSON/ZIP).
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size > 5 * 1024 * 1024 ) {
			return new \WP_Error( 'uxstudio_file_too_large', __( 'The uploaded file is too large (max 5 MB).', 'ux-studio' ), array( 'status' => 413 ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'json' === $ext ) {
			$raw = file_get_contents( $file['tmp_name'] );
			return $this->decode_json( (string) $raw );
		}

		if ( 'zip' === $ext ) {
			return $this->read_zip( $file['tmp_name'] );
		}

		return new \WP_Error(
			'uxstudio_invalid_file_type',
			__( 'Only .json and .zip files are allowed.', 'ux-studio' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Extract the first JSON template from a ZIP archive.
	 *
	 * @param string $path Absolute path to the uploaded ZIP.
	 * @return array|\WP_Error
	 */
	private function read_zip( string $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'uxstudio_no_zip', __( 'ZIP support is not available on this server.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'uxstudio_bad_zip', __( 'The ZIP archive could not be opened.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$json = '';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = (string) $zip->getNameIndex( $i );
			if ( 'json' === strtolower( (string) pathinfo( $entry, PATHINFO_EXTENSION ) ) ) {
				$json = (string) $zip->getFromIndex( $i );
				break;
			}
		}
		$zip->close();

		if ( '' === $json ) {
			return new \WP_Error( 'uxstudio_zip_no_json', __( 'The ZIP archive does not contain a JSON template.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		return $this->decode_json( $json );
	}

	/**
	 * Decode a raw JSON string into a template array.
	 *
	 * @param string $raw Raw JSON.
	 * @return array|\WP_Error
	 */
	private function decode_json( string $raw ) {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'uxstudio_bad_json', __( 'The file does not contain valid JSON.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		return $data;
	}

	// ---------------------------------------------------------------------
	// Import: remote URL and pasted HTML (via the shared converter).
	// ---------------------------------------------------------------------

	/**
	 * Fetch a remote page, convert its HTML to Elementor and store it.
	 *
	 * The caller (REST controller) is responsible for SSRF validation of $url
	 * before this runs.
	 *
	 * @param string $url     Remote page URL (already validated).
	 * @param array  $options Converter options (e.g. 'selector').
	 * @param string $mode    new_page|replace|append.
	 * @param int    $post_id Target post id (ignored for new_page).
	 * @return array|\WP_Error
	 */
	public function import_from_url( string $url, array $options, string $mode, int $post_id ) {
		if ( ! $this->is_converter_available() ) {
			return new \WP_Error(
				'uxstudio_no_converter',
				__( 'HTML import requires the AI Assistant module (HTML → Elementor converter).', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		try {
			$result = HtmlToElementor::convert_from_url( $url, $options );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'uxstudio_fetch_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		if ( empty( $result['elements'] ) || ! is_array( $result['elements'] ) ) {
			return new \WP_Error(
				'uxstudio_no_content',
				__( 'Could not extract content from the page. Try providing a CSS selector for the main content.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$title = ! empty( $result['title'] )
			? (string) $result['title']
			/* translators: %s: source hostname. */
			: sprintf( __( 'Import from %s', 'ux-studio' ), $host );

		return $this->store_elementor(
			$result['elements'],
			$mode,
			$post_id,
			array(
				'title'  => $title,
				'source' => 'url',
			)
		);
	}

	/**
	 * Convert pasted HTML to Elementor and store it.
	 *
	 * @param string $html    Raw HTML markup.
	 * @param string $title   Page title (used for new_page).
	 * @param string $mode    new_page|replace|append.
	 * @param int    $post_id Target post id (ignored for new_page).
	 * @return array|\WP_Error
	 */
	public function import_from_html( string $html, string $title, string $mode, int $post_id ) {
		if ( ! $this->is_converter_available() ) {
			return new \WP_Error(
				'uxstudio_no_converter',
				__( 'HTML import requires the AI Assistant module (HTML → Elementor converter).', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$elements = HtmlToElementor::convert( $html );
		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return new \WP_Error(
				'uxstudio_no_content',
				__( 'Could not build any Elementor widgets from the supplied HTML.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		return $this->store_elementor(
			$elements,
			$mode,
			$post_id,
			array(
				'title'  => '' !== $title ? $title : __( 'Imported HTML', 'ux-studio' ),
				'source' => 'html',
			)
		);
	}

	// ---------------------------------------------------------------------
	// Core write path shared by every import source.
	// ---------------------------------------------------------------------

	/**
	 * Store Elementor content on a post according to the requested mode.
	 *
	 * Writes _elementor_edit_mode, _elementor_data, _elementor_version and,
	 * for new pages, _elementor_template_type. Backs up any existing
	 * _elementor_data to _elementor_data_backup before overwriting, clears
	 * Elementor CSS caches and records an audit entry.
	 *
	 * @param array  $content Elementor elements array.
	 * @param string $mode    new_page|replace|append.
	 * @param int    $post_id Target post id (ignored for new_page).
	 * @param array  $opts    title, post_type, page_settings, source.
	 * @return array|\WP_Error
	 */
	public function store_elementor( array $content, string $mode, int $post_id, array $opts = array() ) {
		$mode = in_array( $mode, array( 'new_page', 'replace', 'append' ), true ) ? $mode : 'new_page';

		if ( 'new_page' === $mode ) {
			$title     = sanitize_text_field( (string) ( $opts['title'] ?? __( 'Imported page', 'ux-studio' ) ) );
			$post_type = in_array( $opts['post_type'] ?? 'page', array( 'page', 'post' ), true ) ? (string) $opts['post_type'] : 'page';

			$post_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_content' => '',
					'post_type'    => $post_type,
					'post_status'  => 'draft',
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return new \WP_Error( 'uxstudio_insert_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
			}

			update_post_meta( $post_id, '_elementor_template_type', 'wp-' . $post_type );
		}

		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'uxstudio_no_post', __( 'The target post was not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$data = $content;

		if ( 'append' === $mode ) {
			$existing      = get_post_meta( $post_id, '_elementor_data', true );
			$existing_data = ! empty( $existing ) ? json_decode( (string) $existing, true ) : array();
			if ( is_array( $existing_data ) ) {
				$data = array_merge( $existing_data, $data );
			}
		}

		$data = $this->regenerate_element_ids( $data );

		// Back up any existing content before we overwrite it (replace/append).
		$old_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! empty( $old_data ) ) {
			update_post_meta( $post_id, '_elementor_data_backup', $old_data );
		}

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $data ) ) );
		update_post_meta( $post_id, '_elementor_version', $this->elementor_version() );

		if ( ! empty( $opts['page_settings'] ) && is_array( $opts['page_settings'] ) ) {
			update_post_meta( $post_id, '_elementor_page_settings', $opts['page_settings'] );
		}

		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		$this->clear_elementor_cache();

		$widgets = $this->count_widgets( $data );

		ActivityLog::log(
			'elementor-import',
			$mode,
			$post->post_type,
			$post_id,
			array(
				'widgets' => $widgets,
				'source'  => (string) ( $opts['source'] ?? 'json' ),
			)
		);

		return array(
			'post_id'         => $post_id,
			'title'           => get_the_title( $post_id ),
			'widgets_created' => $widgets,
			'mode'            => $mode,
			'edit_url'        => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'url'             => get_permalink( $post_id ),
		);
	}

	// ---------------------------------------------------------------------
	// Export.
	// ---------------------------------------------------------------------

	/**
	 * Export an existing post's Elementor content to a portable JSON structure.
	 *
	 * @param int $post_id Post id.
	 * @return array|\WP_Error
	 */
	public function export_page( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'uxstudio_no_post', __( 'The page was not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return new \WP_Error( 'uxstudio_no_elementor', __( 'This page has no Elementor content to export.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$content = json_decode( (string) $raw, true );
		if ( ! is_array( $content ) ) {
			return new \WP_Error( 'uxstudio_corrupt_data', __( 'The stored Elementor data is corrupted.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( is_string( $page_settings ) && '' !== $page_settings ) {
			$decoded       = json_decode( $page_settings, true );
			$page_settings = is_array( $decoded ) ? $decoded : array();
		} elseif ( ! is_array( $page_settings ) ) {
			$page_settings = array();
		}

		ActivityLog::log( 'elementor-import', 'export', $post->post_type, (int) $post_id );

		return array(
			'version'           => '1.0',
			'type'              => 'uxstudio-elementor-page',
			'title'             => $post->post_title,
			'post_type'         => $post->post_type,
			'elementor_version' => $this->elementor_version(),
			'export_date'       => gmdate( 'c' ),
			'content'           => $content,
			'page_settings'     => $page_settings,
			'filename'          => sanitize_file_name( ( $post->post_name ?: 'elementor-export' ) ) . '.json',
		);
	}

	// ---------------------------------------------------------------------
	// SSRF guard for server-side URL fetches.
	// ---------------------------------------------------------------------

	/**
	 * Whether a URL is safe to fetch server-side. Returns true only for http(s)
	 * URLs whose every resolved IP (v4 and v6) lies outside private/reserved
	 * ranges. Fail-closed: an unresolvable host returns false.
	 *
	 * @param string $url URL to check.
	 */
	public function is_safe_import_url( string $url ): bool {
		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host = (string) $parts['host'];
		$ips  = array();

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$a = gethostbynamel( $host );
			if ( is_array( $a ) ) {
				$ips = array_merge( $ips, $a );
			}
			if ( function_exists( 'dns_get_record' ) ) {
				$aaaa = dns_get_record( $host, DNS_AAAA );
				if ( is_array( $aaaa ) ) {
					foreach ( $aaaa as $rec ) {
						if ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			// NO_PRIV_RANGE = RFC1918 + fc00::/7; NO_RES_RANGE = loopback, link-local (169.254.*), reserved.
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		return true;
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * Current Elementor version, or empty string when unavailable.
	 */
	private function elementor_version(): string {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
	}

	/**
	 * Clear Elementor's generated CSS cache if the plugin is loaded.
	 */
	private function clear_elementor_cache(): void {
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/**
	 * Regenerate element IDs recursively to avoid collisions.
	 *
	 * @param array $elements Elementor elements.
	 * @return array
	 */
	private function regenerate_element_ids( array $elements ): array {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$element['id'] = dechex( random_int( 0x10000000, 0x7FFFFFFF ) );
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->regenerate_element_ids( $element['elements'] );
			}
		}
		unset( $element );
		return $elements;
	}

	/**
	 * Count widget elements recursively.
	 *
	 * @param array $elements Elementor elements.
	 */
	private function count_widgets( array $elements ): int {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				$count++;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_widgets( $element['elements'] );
			}
		}
		return $count;
	}
}
