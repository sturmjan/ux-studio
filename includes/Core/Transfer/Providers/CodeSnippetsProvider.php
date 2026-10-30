<?php
/**
 * Code snippets (PHP, JS, CSS, HTML). Executable content: handled with the most care of all providers.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer\Providers;

use UxStudio\Modules\CodeSnippets\SnippetConditions;
use UxStudio\Modules\CodeSnippets\SnippetManager;

defined( 'ABSPATH' ) || exit;

/**
 * - Exporting AND importing need `unfiltered_html` (on multisite that means a super admin) on top of
 *   `manage_options`, and the same file-editor switches as the snippet editor itself (`DISALLOW_FILE_EDIT`,
 *   `DISALLOW_FILE_MODS`, `edit_plugins`).
 * - Imported snippets ALWAYS arrive switched off, also when they replace an existing snippet of the same
 *   name. Nothing from a file runs until somebody reads it and switches it on.
 * - The module's own validation runs on every snippet (PHP syntax and duplicate declarations are checked
 *   without running the code).
 * - A snippet is matched by its name (not case-sensitive, as the module requires unique names).
 */
final class CodeSnippetsProvider extends AbstractProvider {

	private ?SnippetManager $manager = null;

	public function module(): string {
		return 'code-snippets';
	}

	public function kind(): string {
		return 'snippets';
	}

	public function label(): string {
		return __( 'Code snippets', 'ux-studio' );
	}

	public function capability(): string {
		return 'unfiltered_html';
	}

	private function manager(): SnippetManager {
		if ( null === $this->manager ) {
			$this->manager = new SnippetManager();
		}
		return $this->manager;
	}

	private function table_exists(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'uxstudio_code_snippets' ) );
	}

	public function blocked_reason(): string {
		global $wpdb;
		if ( ! $this->table_exists() ) {
			return __( 'The snippets table does not exist yet. Switch the Code Snippets module on and open it once.', 'ux-studio' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}uxstudio_code_snippets LIKE 'conditions'" ) ) {
			return __( 'The snippets table is from an older version of the module. Switch the Code Snippets module on and open it once.', 'ux-studio' );
		}
		return SnippetManager::editBlockReason();
	}

	public function count(): int {
		global $wpdb;
		return $this->table_exists() ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_code_snippets" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	protected function key_of( array $item ): string {
		return strtolower( (string) ( $item['name'] ?? '' ) );
	}

	protected function label_of( array $item ): string {
		return (string) $item['name'];
	}

	protected function comparable( array $item ): array {
		unset( $item['enabled'] ); // An import never changes whether a snippet runs.
		return $item;
	}

	protected function current_items(): array {
		if ( ! $this->table_exists() ) {
			return array();
		}
		$out = array();
		foreach ( $this->manager()->getAllSnippets() as $snippet ) {
			$out[] = array(
				'ref'  => (string) $snippet->getId(),
				'item' => array(
					'name'         => $snippet->getName(),
					'type'         => $snippet->getType(),
					'description'  => $snippet->getDescription(),
					'run_location' => $snippet->getRunLocation(),
					'priority'     => $snippet->getPriority(),
					'post_types'   => $snippet->getPostTypes(),
					'conditions'   => $snippet->getConditions(),
					'code'         => $snippet->getCode(),
					'enabled'      => $snippet->isEnabled(),
				),
			);
		}
		return $out;
	}

	protected function clean_item( $raw ): array {
		$fail = static fn( string $m ): array => array(
			'ok'      => false,
			'item'    => array(),
			'error'   => $m,
			'warning' => '',
		);
		if ( ! is_array( $raw ) ) {
			return $fail( __( 'Not a snippet.', 'ux-studio' ) );
		}
		$name = self::text( $raw['name'] ?? null, 191 );
		$code = self::text( $raw['code'] ?? null, 500000 );
		$type = $raw['type'] ?? 'php';
		if ( null === $name || '' === trim( $name ) || null === $code || '' === trim( $code ) || ! in_array( $type, array( 'php', 'js', 'css', 'html' ), true ) ) {
			return $fail( __( 'The snippet has no name, no code or an unknown type.', 'ux-studio' ) );
		}
		$location = (string) ( $raw['run_location'] ?? '' );
		if ( '' !== $location && ! in_array( $location, SnippetManager::allowedLocations( (string) $type ), true ) ) {
			return $fail( __( 'The snippet has a place to run that does not fit its type.', 'ux-studio' ) );
		}
		$conditions = $raw['conditions'] ?? array();
		if ( ! is_array( $conditions ) ) {
			return $fail( __( 'The snippet has conditions in a shape that is not accepted.', 'ux-studio' ) );
		}
		$normalized = SnippetConditions::normalize( $conditions );
		if ( $normalized['errors'] ) {
			return $fail( __( 'The conditions of the snippet are not valid.', 'ux-studio' ) );
		}
		return array(
			'ok'      => true,
			'item'    => array(
				'name'         => trim( $name ),
				'type'         => (string) $type,
				'description'  => (string) self::text( $raw['description'] ?? '', 5000 ),
				'run_location' => $location,
				'priority'     => (int) ( $raw['priority'] ?? 10 ),
				'post_types'   => array_values( array_filter( array_map( 'sanitize_key', (array) ( $raw['post_types'] ?? array() ) ) ) ),
				'conditions'   => $normalized['conditions'],
				'code'         => $code,
				'enabled'      => ! empty( $raw['enabled'] ),
			),
			'error'   => '',
			'warning' => '',
		);
	}

	public function plan( array $incoming ): array {
		$plan             = parent::plan( $incoming );
		$plan['warnings'][] = __( 'Snippets are imported turned off. Read them and switch them on yourself.', 'ux-studio' );
		return $plan;
	}

	protected function create_item( array $item ) {
		$result = $this->manager()->createSnippet( array( 'enabled' => false ) + $item );
		return ! empty( $result['success'] ) ? (string) $result['data']['id'] : null;
	}

	protected function update_item( $ref, array $item ): bool {
		$result = $this->manager()->updateSnippet( (string) $ref, array( 'enabled' => false ) + $item );
		return ! empty( $result['success'] );
	}

	protected function restore_item( $ref, array $item ): bool {
		// Undo gives the snippet back as it was, including whether it was running.
		$result = $this->manager()->updateSnippet( (string) $ref, $item );
		return ! empty( $result['success'] );
	}

	protected function remove_item( $ref ): bool {
		$result = $this->manager()->deleteSnippet( (string) $ref );
		return ! empty( $result['success'] );
	}
}
