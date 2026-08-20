<?php
/**
 * A single code snippet: validation, integrity hashing and file persistence.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CodeSnippets;

defined( 'ABSPATH' ) || exit;

/**
 * Snippets are stored as PHP FILES on disk (never as a DB blob fed to eval()).
 * Every file carries an `@security_hash` computed from `hash('sha256', code .
 * secret . salt)`, where `secret` is derived from wp_salt('auth'). The hash is
 * verified with hash_equals() before the file is ever include()'d, so a file
 * modified outside the application (FTP, backup restore, etc.) will not run
 * unless the attacker also knows the site's auth salt. Metadata (title, type,
 * enabled, run_location) lives in the `uxstudio_code_snippets` DB table, NOT
 * in the file header, so tampering with the header alone cannot flip a
 * snippet to enabled or change where it runs - only DB access can do that.
 */
final class Snippet {

	private string $id;
	private string $name;
	private string $type;
	private string $code;
	private bool $enabled;
	private string $filePath;
	private string $description;
	private string $runLocation;
	private int $priority;
	private bool $isValid;
	private bool $hasIntegrityIssue = false;
	private array $metadata;

	/**
	 * @param string $id          Snippet id (numeric, from the metadata table).
	 * @param string $name        Snippet title.
	 * @param string $type        php|js|css|html.
	 * @param string $code        Raw snippet code (unwrapped).
	 * @param bool   $enabled     Whether the snippet is enabled.
	 * @param string $filePath    Absolute path to the snippet file.
	 * @param string $description Optional description.
	 * @param string $runLocation Run location key.
	 * @param int    $priority    Hook priority for output snippets.
	 */
	public function __construct(
		string $id,
		string $name,
		string $type,
		string $code,
		bool $enabled,
		string $filePath,
		string $description = '',
		string $runLocation = '',
		int $priority = 10
	) {
		$this->id = sanitize_key( $id );

		$decoded_name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$this->name   = sanitize_text_field( $decoded_name );

		$this->type      = sanitize_key( $type );
		$this->code       = $this->normalizeCode( $code );
		$this->enabled    = $enabled;
		$this->filePath   = $filePath;

		$decoded_description = html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$this->description    = sanitize_textarea_field( $decoded_description );
		$this->runLocation    = sanitize_key( $runLocation );
		$this->priority       = $priority;

		$this->metadata = $this->initializeMetadata();

		$validation    = $this->validate();
		$this->isValid = $validation['valid'];
	}

	/**
	 * Build the created/updated metadata block.
	 */
	private function initializeMetadata(): array {
		$user = wp_get_current_user();
		$now  = current_time( 'mysql' );

		return array(
			'created_by' => $user->user_login,
			'created_at' => $now,
			'updated_at' => $now,
			'updated_by' => $user->user_login,
		);
	}

	/**
	 * Refresh the "updated" metadata fields.
	 */
	private function updateMetadata(): void {
		$user                        = wp_get_current_user();
		$this->metadata['updated_at'] = current_time( 'mysql' );
		$this->metadata['updated_by'] = $user->user_login;
	}

	/**
	 * Validate the code according to its type. PHP goes through PhpValidator
	 * (token-based syntax + duplicate declaration check, no eval()).
	 *
	 * @return array{valid:bool,message:string}
	 */
	public function validate(): array {
		if ( '' === trim( $this->code ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Snippet code cannot be empty', 'ux-studio' ),
			);
		}

		switch ( $this->type ) {
			case 'php':
				return $this->validatePhpCode();
			case 'js':
				return $this->validateJsCode();
			case 'css':
				return $this->validateCssCode();
			case 'html':
				return $this->validateHtmlCode();
			default:
				return array(
					'valid'   => false,
					'message' => __( 'Invalid snippet type', 'ux-studio' ),
				);
		}
	}

	/**
	 * Validate PHP code with PhpValidator. Never executes the code.
	 *
	 * @return array{valid:bool,message:string}
	 */
	private function validatePhpCode(): array {
		$validator = new PhpValidator( $this->code );

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
	 * @return array{valid:bool,message:string}
	 */
	private function validateJsCode(): array {
		if ( preg_match( '/<\?php|\?>|<\?/', $this->code ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'JavaScript code contains PHP tags', 'ux-studio' ),
			);
		}

		$brackets = substr_count( $this->code, '{' ) - substr_count( $this->code, '}' );
		if ( 0 !== $brackets ) {
			return array(
				'valid'   => false,
				'message' => __( 'JavaScript code has unmatched braces', 'ux-studio' ),
			);
		}

		return array(
			'valid'   => true,
			'message' => __( 'JavaScript code is valid', 'ux-studio' ),
		);
	}

	/**
	 * @return array{valid:bool,message:string}
	 */
	private function validateCssCode(): array {
		if ( preg_match( '/<\?php|\?>|<\?/', $this->code ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'CSS code contains PHP tags', 'ux-studio' ),
			);
		}

		$brackets = substr_count( $this->code, '{' ) - substr_count( $this->code, '}' );
		if ( 0 !== $brackets ) {
			return array(
				'valid'   => false,
				'message' => __( 'CSS code has unmatched braces', 'ux-studio' ),
			);
		}

		return array(
			'valid'   => true,
			'message' => __( 'CSS code is valid', 'ux-studio' ),
		);
	}

	/**
	 * @return array{valid:bool,message:string}
	 */
	private function validateHtmlCode(): array {
		return array(
			'valid'   => true,
			'message' => __( 'HTML code is valid', 'ux-studio' ),
		);
	}

	/**
	 * Build the file header (docblock) with metadata + integrity hash. The
	 * header is written for human-readability/debugging only - the DB table
	 * is the authoritative source for title/type/enabled/run_location.
	 */
	private function generateHeader(): string {
		$this->updateMetadata();

		$header  = "<?php\n";
		$header .= "if ( ! defined( 'ABSPATH' ) ) { return; }\n\n";
		$header .= "/**\n";
		$header .= " * UX Studio Code Snippet\n";
		$header .= " *\n";
		$header .= ' * @name: ' . $this->name . "\n";
		$header .= ' * @type: ' . $this->type . "\n";
		$header .= ' * @enabled: ' . ( $this->enabled ? 'true' : 'false' ) . "\n";
		$header .= ' * @description: ' . $this->description . "\n";
		$header .= ' * @run_location: ' . $this->runLocation . "\n";
		$header .= ' * @priority: ' . $this->priority . "\n";
		$header .= ' * @created_by: ' . $this->metadata['created_by'] . "\n";
		$header .= ' * @created_at: ' . $this->metadata['created_at'] . "\n";
		$header .= ' * @updated_by: ' . $this->metadata['updated_by'] . "\n";
		$header .= ' * @updated_at: ' . $this->metadata['updated_at'] . "\n";
		$header .= ' * @security_hash: ' . $this->generateSecurityHash() . "\n";
		$header .= " */\n\n";

		return $header;
	}

	/**
	 * Integrity hash of the CODE only: hash('sha256', code . secret . salt).
	 * `secret` is derived from wp_salt('auth'), which only the server (via
	 * wp-config.php) knows - so a file edited outside the application cannot
	 * be made to pass verification without also knowing the site's salts.
	 */
	private function generateSecurityHash(): string {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'uxstudio_fallback_key' );
		return hash( 'sha256', $this->code . $secret . 'uxstudio_snippet_integrity' );
	}

	/**
	 * Verify the on-disk file still matches the expected integrity hash.
	 * MUST be called (with hash_equals()) before every include().
	 */
	public function verifyIntegrity(): bool {
		if ( ! file_exists( $this->filePath ) ) {
			return false;
		}

		$content = file_get_contents( $this->filePath );
		if ( false === $content ) {
			return false;
		}

		if ( false === strpos( $content, '/**' ) ) {
			return false;
		}

		if ( preg_match( '/@security_hash: ([a-f0-9]{64})/', $content, $matches ) ) {
			$stored_hash  = $matches[1];
			$current_hash = $this->generateSecurityHash();
			return hash_equals( $stored_hash, $current_hash );
		}

		return false;
	}

	/**
	 * Wrap the code with the tags required for non-PHP snippet types.
	 */
	private function wrapCode(): string {
		switch ( $this->type ) {
			case 'js':
				return "?>\n<script>\n" . $this->code . "\n</script>";
			case 'css':
				return "?>\n<style>\n" . $this->code . "\n</style>";
			case 'html':
				return "?>\n" . $this->code;
			case 'php':
			default:
				return $this->code;
		}
	}

	/**
	 * Persist the snippet to disk: path-containment check, atomic write
	 * (temp file + rename), then re-verify integrity of what was written.
	 */
	public function save(): bool {
		$real_dir    = realpath( dirname( $this->filePath ) );
		$allowed_dir = realpath( WP_CONTENT_DIR . '/uxstudio-snippets' );

		if ( false === $real_dir || false === $allowed_dir || 0 !== strpos( $real_dir, $allowed_dir ) ) {
			return false;
		}

		$dir = dirname( $this->filePath );
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		if ( ! is_writable( $dir ) ) {
			return false;
		}

		$content  = $this->generateHeader();
		$content .= $this->wrapCode();

		$temp_file      = $this->filePath . '.tmp';
		$bytes_written = file_put_contents( $temp_file, $content, LOCK_EX );
		if ( false === $bytes_written ) {
			return false;
		}

		$written_content = file_get_contents( $temp_file );
		if ( $written_content !== $content ) {
			unlink( $temp_file );
			return false;
		}

		if ( ! rename( $temp_file, $this->filePath ) ) {
			unlink( $temp_file );
			return false;
		}

		chmod( $this->filePath, 0644 );

		if ( ! $this->verifyIntegrity() ) {
			unlink( $this->filePath );
			return false;
		}

		return true;
	}

	/**
	 * Delete the snippet file.
	 */
	public function delete(): bool {
		if ( file_exists( $this->filePath ) ) {
			return unlink( $this->filePath );
		}
		return false;
	}

	/**
	 * REST/API payload (never includes raw code for list views; callers that
	 * need the code use getCode() explicitly).
	 */
	public function toApiArray(): array {
		return array(
			'id'                  => $this->id,
			'name'                => $this->getName(),
			'type'                => $this->type,
			'enabled'             => $this->enabled,
			'description'         => $this->getDescription(),
			'run_location'        => $this->runLocation,
			'priority'            => $this->priority,
			'is_valid'            => $this->isValid,
			'has_integrity_issue' => $this->hasIntegrityIssue,
			'created_at'          => $this->metadata['created_at'] ?? '',
			'updated_at'          => $this->metadata['updated_at'] ?? '',
			'created_by'          => $this->metadata['created_by'] ?? '',
			'updated_by'          => $this->metadata['updated_by'] ?? '',
		);
	}

	public function getId(): string {
		return $this->id;
	}

	public function getName(): string {
		return html_entity_decode( esc_html( $this->name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public function getType(): string {
		return $this->type;
	}

	public function getCode(): string {
		return $this->code;
	}

	public function isEnabled(): bool {
		return $this->enabled;
	}

	public function getFilePath(): string {
		return $this->filePath;
	}

	public function getDescription(): string {
		return html_entity_decode( esc_html( $this->description ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public function getRunLocation(): string {
		return $this->runLocation;
	}

	public function getPriority(): int {
		return $this->priority;
	}

	public function isValid(): bool {
		return $this->isValid;
	}

	public function getMetadata(): array {
		return $this->metadata;
	}

	public function hasIntegrityIssue(): bool {
		return $this->hasIntegrityIssue;
	}

	public function setIntegrityIssue( bool $has_issue ): void {
		$this->hasIntegrityIssue = $has_issue;
	}

	/**
	 * Whether the snippet should be considered eligible for execution. The
	 * executor ALSO re-verifies the file hash independently before every
	 * include() - this flag is a fast pre-filter, not the security boundary.
	 */
	public function canExecute(): bool {
		return $this->enabled && $this->isValid && '' !== trim( $this->code ) && ! $this->hasIntegrityIssue;
	}

	/**
	 * Strip BOM and normalize invisible unicode spaces that would otherwise
	 * cause confusing "syntax error" reports.
	 */
	private function normalizeCode( string $code ): string {
		if ( 0 === strpos( $code, "\xEF\xBB\xBF" ) ) {
			$code = substr( $code, 3 );
		}

		return str_replace(
			array(
				"\xC2\xA0",     // NBSP.
				"\xE2\x80\x89", // Thin space.
				"\xE2\x80\xAF", // Narrow no-break space.
			),
			' ',
			$code
		);
	}
}
