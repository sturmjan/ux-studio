<?php
/**
 * Snippet persistence: file storage + metadata table, validation, discovery.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CodeSnippets;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `wp-content/uxstudio-snippets/` directory (file storage - code is
 * NEVER stored as a DB blob fed to eval()) and the `uxstudio_code_snippets`
 * table (metadata only: title/type/enabled/run_location/file_path). The DB
 * row is created first so its auto-increment id can be used to derive a safe
 * file name; the file is the only place the code and its integrity hash
 * live. Every read that will lead to execution re-verifies the file's own
 * hash - the DB is just an index, not a trust boundary.
 */
final class SnippetManager {

	private string $snippetsDir;

	public function __construct() {
		$this->snippetsDir = WP_CONTENT_DIR . '/uxstudio-snippets';
		$this->initializeDirectory();
	}

	/**
	 * Fully-qualified metadata table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_code_snippets';
	}

	/**
	 * Ensure a file path resolves within the allowed snippets directory.
	 * Guards every file read/write against path traversal.
	 */
	private function isPathSecure( string $filePath ): bool {
		$real_dir_path = realpath( dirname( $filePath ) );
		$allowed_dir   = realpath( $this->snippetsDir );

		if ( false === $real_dir_path || false === $allowed_dir ) {
			return false;
		}

		return 0 === strpos( $real_dir_path, $allowed_dir );
	}

	/**
	 * Sanitize a title into a filesystem-safe slug fragment (no HTML entities).
	 */
	private function sanitizeFileName( string $name ): string {
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = preg_replace( '/[^a-zA-Z0-9\s\-_]/', '', $name );
		$name = preg_replace( '/[\s\-_]+/', '-', (string) $name );
		$name = trim( (string) $name, '-' );
		return strtolower( $name );
	}

	/**
	 * Build a safe, contained file path from a numeric id + title.
	 *
	 * @return string|false
	 */
	private function generateSecureFilePath( string $id, string $name ) {
		if ( ! preg_match( '/^[a-zA-Z0-9-]+$/', $id ) ) {
			return false;
		}

		$sanitized_name = $this->sanitizeFileName( $name );
		if ( '' === $sanitized_name ) {
			$sanitized_name = 'snippet';
		}
		$sanitized_name = substr( $sanitized_name, 0, 50 );

		$file_path = $this->snippetsDir . DIRECTORY_SEPARATOR . $id . '-' . $sanitized_name . '.php';

		if ( ! $this->isPathSecure( $file_path ) ) {
			return false;
		}

		return $file_path;
	}

	/**
	 * Create the snippets directory plus its web-access guards if missing:
	 * an index.php (silences directory listing) and a .htaccess denying all
	 * direct HTTP access (Apache 2.2 + 2.4 syntax; irrelevant on nginx, where
	 * the directory must instead be blocked at the server config level, same
	 * as any other wp-content subdirectory that should not be web-served).
	 */
	private function initializeDirectory(): void {
		if ( ! file_exists( $this->snippetsDir ) && ! wp_mkdir_p( $this->snippetsDir ) ) {
			return;
		}

		if ( ! is_writable( $this->snippetsDir ) ) {
			chmod( $this->snippetsDir, 0755 );
			if ( ! is_writable( $this->snippetsDir ) ) {
				return;
			}
		}

		$this->createGuardFiles();
	}

	/**
	 * Write the index.php + .htaccess guard files (idempotent).
	 */
	private function createGuardFiles(): void {
		$index_file = $this->snippetsDir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = $this->snippetsDir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess = "# Deny all direct web access to code snippet files.\n";
			$htaccess .= "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n";
			$htaccess .= "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess_file, $htaccess );
		}
	}

	/**
	 * Default run_location per snippet type (used when the client omits it).
	 */
	private function getDefaultRunLocation( string $type ): string {
		$defaults = array(
			'php'  => 'everywhere',
			'js'   => 'site_footer',
			'css'  => 'site_header',
			'html' => 'site_header',
		);
		return $defaults[ $type ] ?? 'everywhere';
	}

	/**
	 * Validate + sanitize an incoming snippet payload. PHP code is rejected
	 * outright if PhpValidator finds a syntax/duplicate-declaration problem.
	 *
	 * @return array{valid:bool,message:string,sanitized_data:array}
	 */
	private function validateAndSanitizeData( array $data ): array {
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name ) {
			return array(
				'valid'          => false,
				'message'        => __( 'Snippet name is required', 'ux-studio' ),
				'sanitized_data' => array(),
			);
		}

		$code = isset( $data['code'] ) ? (string) $data['code'] : '';
		if ( '' === trim( $code ) ) {
			return array(
				'valid'          => false,
				'message'        => __( 'Snippet code is required', 'ux-studio' ),
				'sanitized_data' => array(),
			);
		}

		$type = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'php';
		if ( ! in_array( $type, array( 'php', 'js', 'css', 'html' ), true ) ) {
			return array(
				'valid'          => false,
				'message'        => __( 'Invalid snippet type', 'ux-studio' ),
				'sanitized_data' => array(),
			);
		}

		if ( 'php' === $type ) {
			$validation = $this->validatePhpCode( $code );
			if ( ! $validation['valid'] ) {
				return array(
					'valid'          => false,
					'message'        => $validation['message'],
					'sanitized_data' => array(),
				);
			}
		}

		$sanitized = array(
			'name'         => $name,
			'type'         => $type,
			'code'         => $code,
			'enabled'      => isset( $data['enabled'] ) ? (bool) $data['enabled'] : false,
			'description'  => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			'run_location' => isset( $data['run_location'] ) && '' !== $data['run_location']
				? sanitize_key( $data['run_location'] )
				: $this->getDefaultRunLocation( $type ),
			'priority'     => isset( $data['priority'] ) ? (int) $data['priority'] : 10,
		);

		return array(
			'valid'          => true,
			'message'        => '',
			'sanitized_data' => $sanitized,
		);
	}

	/**
	 * Run PhpValidator against the given code (syntax + duplicate declaration
	 * checks only - never executes the code).
	 *
	 * @return array{valid:bool,message:string}
	 */
	private function validatePhpCode( string $code ): array {
		$validator = new PhpValidator( $code );

		$result = $validator->validate();
		if ( is_wp_error( $result ) ) {
			return array(
				'valid'   => false,
				'message' => $result->get_error_message(),
			);
		}

		$result = $validator->checkRunTimeError();
		if ( is_wp_error( $result ) ) {
			return array(
				'valid'   => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'valid'   => true,
			'message' => __( 'PHP code is valid', 'ux-studio' ),
		);
	}

	/**
	 * All snippets (metadata from DB, code + integrity check from file).
	 * Runtime reads are intentionally open to anonymous visitors so frontend
	 * snippets can execute - admin/REST mutations remain capability-gated.
	 *
	 * @return Snippet[]
	 */
	public function getAllSnippets(): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, no user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		$snippets = array();
		foreach ( (array) $rows as $row ) {
			$snippet = $this->loadSnippetFromRow( $row );
			if ( $snippet ) {
				$snippets[] = $snippet;
			}
		}

		return $snippets;
	}

	/**
	 * A single snippet by id.
	 */
	public function getSnippet( string $id ): ?Snippet {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only; id is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return $this->loadSnippetFromRow( $row );
	}

	/**
	 * Admin-only variants (defense in depth alongside REST capability checks).
	 *
	 * @return Snippet[]
	 */
	public function getAdminSnippets(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		return $this->getAllSnippets();
	}

	public function getAdminSnippet( string $id ): ?Snippet {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		return $this->getSnippet( $id );
	}

	/**
	 * Whether a snippet with this title already exists (case-insensitive).
	 */
	public function snippetNameExists( string $name, ?string $excludeId = null ): bool {
		global $wpdb;
		$table = $this->table();
		if ( null !== $excludeId ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only; values are bound.
			$count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE LOWER(title) = LOWER(%s) AND id != %d", $name, (int) $excludeId )
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only; value is bound.
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE LOWER(title) = LOWER(%s)", $name ) );
		}
		return ( (int) $count ) > 0;
	}

	/**
	 * Create a new snippet: insert the metadata row (to get the id), derive
	 * a contained file path from that id, validate + write the file, then
	 * backfill the row's file_path. Rolls back the DB row if the file write
	 * fails so no orphan metadata rows are left behind.
	 *
	 * @return array{success:bool,message:string,data:?array}
	 */
	public function createSnippet( array $data ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have sufficient permissions to create snippets.', 'ux-studio' ),
				'data'    => null,
			);
		}

		if ( ! is_writable( $this->snippetsDir ) ) {
			return array(
				'success' => false,
				'message' => __( 'Snippets directory is not writable', 'ux-studio' ),
				'data'    => null,
			);
		}

		$validation = $this->validateAndSanitizeData( $data );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'message' => $validation['message'],
				'data'    => null,
			);
		}
		$sanitized = $validation['sanitized_data'];

		if ( $this->snippetNameExists( $sanitized['name'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'A snippet with this name already exists', 'ux-studio' ),
				'data'    => null,
			);
		}

		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'title'        => $sanitized['name'],
				'type'         => $sanitized['type'],
				'run_location' => $sanitized['run_location'],
				'enabled'      => $sanitized['enabled'] ? 1 : 0,
				'file_path'    => '',
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to create snippet record', 'ux-studio' ),
				'data'    => null,
			);
		}

		$id        = (string) $wpdb->insert_id;
		$file_path = $this->generateSecureFilePath( $id, $sanitized['name'] );

		if ( false === $file_path ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			return array(
				'success' => false,
				'message' => __( 'Failed to generate secure file path', 'ux-studio' ),
				'data'    => null,
			);
		}

		$snippet = new Snippet(
			$id,
			$sanitized['name'],
			$sanitized['type'],
			$sanitized['code'],
			$sanitized['enabled'],
			$file_path,
			$sanitized['description'],
			$sanitized['run_location'],
			$sanitized['priority']
		);

		if ( ! $snippet->save() ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			return array(
				'success' => false,
				'message' => __( 'Failed to create snippet file', 'ux-studio' ),
				'data'    => null,
			);
		}

		$wpdb->update( $table, array( 'file_path' => $file_path ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		ActivityLog::log( 'code-snippets', 'create', 'snippet', (int) $id, array( 'name' => $sanitized['name'], 'type' => $sanitized['type'] ) );

		return array(
			'success' => true,
			'message' => __( 'Snippet created successfully', 'ux-studio' ),
			'data'    => array(
				'id'      => $id,
				'snippet' => $snippet,
			),
		);
	}

	/**
	 * Update an existing snippet. The file path (and therefore the id-based
	 * file name) never changes on update, only its content.
	 *
	 * @return array{success:bool,message:string,data:?array}
	 */
	public function updateSnippet( string $id, array $data ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have sufficient permissions to update snippets.', 'ux-studio' ),
				'data'    => null,
			);
		}

		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only; id is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return array(
				'success' => false,
				'message' => __( 'Snippet not found', 'ux-studio' ),
				'data'    => null,
			);
		}

		$validation = $this->validateAndSanitizeData( $data );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'message' => $validation['message'],
				'data'    => null,
			);
		}
		$sanitized = $validation['sanitized_data'];

		if ( $this->snippetNameExists( $sanitized['name'], $id ) ) {
			return array(
				'success' => false,
				'message' => __( 'A snippet with this name already exists', 'ux-studio' ),
				'data'    => null,
			);
		}

		if ( ! $this->isPathSecure( $row['file_path'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Snippet file path is invalid', 'ux-studio' ),
				'data'    => null,
			);
		}

		$snippet = new Snippet(
			$id,
			$sanitized['name'],
			$sanitized['type'],
			$sanitized['code'],
			$sanitized['enabled'],
			$row['file_path'],
			$sanitized['description'],
			$sanitized['run_location'],
			$sanitized['priority']
		);

		if ( ! $snippet->save() ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to update snippet file', 'ux-studio' ),
				'data'    => null,
			);
		}

		$wpdb->update(
			$table,
			array(
				'title'        => $sanitized['name'],
				'type'         => $sanitized['type'],
				'run_location' => $sanitized['run_location'],
				'enabled'      => $sanitized['enabled'] ? 1 : 0,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		ActivityLog::log( 'code-snippets', 'update', 'snippet', (int) $id, array( 'name' => $sanitized['name'], 'type' => $sanitized['type'] ) );

		return array(
			'success' => true,
			'message' => __( 'Snippet updated successfully', 'ux-studio' ),
			'data'    => array(
				'id'      => $id,
				'snippet' => $snippet,
			),
		);
	}

	/**
	 * Toggle the enabled flag, rewriting the file (so its integrity hash
	 * stays consistent with its content) and the metadata row.
	 */
	public function setSnippetEnabled( string $id, bool $enabled ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$snippet = $this->getSnippet( $id );
		if ( ! $snippet ) {
			return false;
		}

		$updated = new Snippet(
			$id,
			$snippet->getName(),
			$snippet->getType(),
			$snippet->getCode(),
			$enabled,
			$snippet->getFilePath(),
			$snippet->getDescription(),
			$snippet->getRunLocation(),
			$snippet->getPriority()
		);

		if ( ! $updated->save() ) {
			return false;
		}

		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		ActivityLog::log( 'code-snippets', $enabled ? 'enable' : 'disable', 'snippet', (int) $id );

		return true;
	}

	/**
	 * Delete a snippet: file first, then the metadata row.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function deleteSnippet( string $id ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have sufficient permissions to delete snippets.', 'ux-studio' ),
			);
		}

		$snippet = $this->getSnippet( $id );
		if ( ! $snippet ) {
			return array(
				'success' => false,
				'message' => __( 'Snippet not found', 'ux-studio' ),
			);
		}

		$snippet->delete();

		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		ActivityLog::log( 'code-snippets', 'delete', 'snippet', (int) $id, array( 'name' => $snippet->getName() ) );

		return array(
			'success' => true,
			'message' => __( 'Snippet deleted successfully', 'ux-studio' ),
		);
	}

	/**
	 * Hydrate a Snippet entity: metadata comes from the DB row (authoritative,
	 * not tamperable via file edits alone), code + integrity hash come from
	 * the file itself. If the file is missing, unreadable, outside the
	 * allowed directory, or its hash does not match, the snippet is either
	 * skipped (unreadable/insecure path) or loaded with an integrity-issue
	 * flag that blocks execution while still surfacing it in the admin UI.
	 */
	private function loadSnippetFromRow( array $row ): ?Snippet {
		$file_path = (string) ( $row['file_path'] ?? '' );
		if ( '' === $file_path || ! $this->isPathSecure( $file_path ) ) {
			return null;
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return null;
		}

		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			return null;
		}

		$type = sanitize_key( (string) $row['type'] );
		$code = $this->extractCode( $content, $type );

		$snippet = new Snippet(
			(string) $row['id'],
			(string) $row['title'],
			$type,
			$code,
			(bool) $row['enabled'],
			$file_path,
			'',
			(string) $row['run_location'],
			10
		);

		if ( ! $snippet->verifyIntegrity() ) {
			$snippet->setIntegrityIssue( true );
		}

		return $snippet;
	}

	/**
	 * Extract the user's code from a stored snippet file: skip past the
	 * generated docblock header and, for non-PHP types, strip the leading
	 * `?>` and the <script>/<style> wrapper tags.
	 */
	private function extractCode( string $content, string $type ): string {
		$doc_end = strpos( $content, '*/' );
		if ( false === $doc_end ) {
			return '';
		}

		$code_section = substr( $content, $doc_end + strlen( '*/' ) );
		$code_section = ltrim( $code_section, "\r\n" );

		if ( in_array( $type, array( 'html', 'css', 'js' ), true ) ) {
			$code_section = $this->stripLeadingPhpClose( $code_section );
		}

		switch ( $type ) {
			case 'js':
				if ( preg_match( '/<script[^>]*>\s*(.*?)(?:\r?\n)?<\/script>\s*$/is', $code_section, $matches ) ) {
					return $matches[1];
				}
				return $code_section;
			case 'css':
				if ( preg_match( '/<style[^>]*>\s*(.*?)(?:\r?\n)?<\/style>\s*$/is', $code_section, $matches ) ) {
					return $matches[1];
				}
				return $code_section;
			case 'html':
			case 'php':
			default:
				return $code_section;
		}
	}

	/**
	 * Strip a leading `?>` that precedes non-PHP snippet bodies.
	 */
	private function stripLeadingPhpClose( string $code ): string {
		if ( 0 === strpos( $code, '?>' ) ) {
			$code = substr( $code, 2 );
			$code = ltrim( $code, "\r\n" );
		}
		return $code;
	}
}
