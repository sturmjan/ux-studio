<?php
/**
 * Categories of the Notice Board (name, order). Never the notices, their documents or the subscribers.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer\Providers;

use UxStudio\Modules\NoticeBoard\Module;

defined( 'ABSPATH' ) || exit;

/**
 * A category is matched by its slug (derived from the name by the module).
 */
final class NoticeCategoriesProvider extends AbstractProvider {

	private ?Module $module = null;

	/**
	 * @param Module|null $module Live module instance.
	 */
	public function __construct( ?Module $module = null ) {
		$this->module = $module;
	}

	public function module(): string {
		return 'notice-board';
	}

	public function kind(): string {
		return 'categories';
	}

	public function label(): string {
		return __( 'Notice categories', 'ux-studio' );
	}

	private function table_exists(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'uxstudio_notice_board_categories' ) );
	}

	public function blocked_reason(): string {
		return $this->table_exists() ? '' : __( 'The categories table does not exist yet. Switch the Notice Board on and open it once.', 'ux-studio' );
	}

	public function count(): int {
		return count( $this->current_items() );
	}

	protected function key_of( array $item ): string {
		return (string) ( $item['slug'] ?? '' );
	}

	protected function label_of( array $item ): string {
		return (string) $item['name'];
	}

	protected function current_items(): array {
		if ( ! $this->module || ! $this->table_exists() ) {
			return array();
		}
		$out = array();
		foreach ( $this->module->list_categories() as $category ) {
			$out[] = array(
				'ref'  => (int) $category['id'],
				'item' => $category,
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
			return $fail( __( 'Not a category.', 'ux-studio' ) );
		}
		$name = self::text( $raw['name'] ?? null, 191 );
		$slug = null === $name ? '' : sanitize_title( $name );
		if ( null === $name || '' === trim( $name ) || '' === $slug ) {
			return $fail( __( 'The category has no name.', 'ux-studio' ) );
		}
		return array(
			'ok'      => true,
			'item'    => array(
				'name'       => trim( $name ),
				'slug'       => $slug,
				'sort_order' => (int) ( $raw['sort_order'] ?? 0 ),
			),
			'error'   => '',
			'warning' => '',
		);
	}

	protected function create_item( array $item ) {
		$category = $this->module ? $this->module->create_category( $item['name'], $item['sort_order'] ) : null;
		return $category ? (int) $category['id'] : null;
	}

	protected function update_item( $ref, array $item ): bool {
		return $this->module && null !== $this->module->update_category( (int) $ref, array( 'name' => $item['name'], 'sort_order' => $item['sort_order'] ) );
	}

	protected function remove_item( $ref ): bool {
		return $this->module ? $this->module->delete_category( (int) $ref ) : false;
	}
}
