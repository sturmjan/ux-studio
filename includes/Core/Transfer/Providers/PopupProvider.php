<?php
/**
 * Popups of the Popup Manager (text, trigger, targeting by role). Never impression or click statistics.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer\Providers;

use UxStudio\Modules\PopupManager\Module;

defined( 'ABSPATH' ) || exit;

/**
 * A popup is matched by its title. The pages a popup is limited to are page ids of this database and do not
 * travel: an imported popup shows on all pages until the targeting is set again.
 */
final class PopupProvider extends AbstractProvider {

	private ?Module $module = null;

	/**
	 * @param Module|null $module Live module instance.
	 */
	public function __construct( ?Module $module = null ) {
		$this->module = $module;
	}

	public function module(): string {
		return 'popup-manager';
	}

	public function kind(): string {
		return 'popups';
	}

	public function label(): string {
		return __( 'Popups', 'ux-studio' );
	}

	public function blocked_reason(): string {
		return post_type_exists( Module::POST_TYPE ) ? '' : __( 'The module has not started yet in this request. Reload the page and try again.', 'ux-studio' );
	}

	protected function key_of( array $item ): string {
		return (string) ( $item['title'] ?? '' );
	}

	protected function current_items(): array {
		if ( ! $this->module || '' !== $this->blocked_reason() ) {
			return array();
		}
		$out = array();
		foreach ( array_reverse( $this->module->list_popups() ) as $popup ) {
			$out[] = array(
				'ref'  => (int) $popup['id'],
				'item' => $popup,
			);
		}
		return $out;
	}

	public function count(): int {
		return count( $this->current_items() );
	}

	protected function clean_item( $raw ): array {
		$fail = static fn( string $m ): array => array(
			'ok'      => false,
			'item'    => array(),
			'error'   => $m,
			'warning' => '',
		);
		if ( ! is_array( $raw ) ) {
			return $fail( __( 'Not a popup.', 'ux-studio' ) );
		}
		$title = self::text( $raw['title'] ?? null, 200 );
		if ( null === $title || '' === trim( $title ) ) {
			return $fail( __( 'The popup has no title.', 'ux-studio' ) );
		}
		$content   = is_array( $raw['content'] ?? null ) ? $raw['content'] : array();
		$trigger   = is_array( $raw['trigger'] ?? null ) ? $raw['trigger'] : array();
		$targeting = is_array( $raw['targeting'] ?? null ) ? $raw['targeting'] : array();
		$text      = static fn( string $key ): string => (string) self::text( $content[ $key ] ?? '', 5000 );
		return array(
			'ok'      => true,
			'item'    => array(
				'title'     => trim( $title ),
				'status'    => 'publish' === ( $raw['status'] ?? '' ) ? 'publish' : 'draft',
				'content'   => array(
					'headline' => $text( 'headline' ),
					'body'     => $text( 'body' ),
					'cta_text' => $text( 'cta_text' ),
					'cta_url'  => $text( 'cta_url' ),
				),
				'trigger'   => array(
					'type'           => (string) ( $trigger['type'] ?? 'delay' ),
					'delay_ms'       => (int) ( $trigger['delay_ms'] ?? 4000 ),
					'scroll_percent' => (int) ( $trigger['scroll_percent'] ?? 50 ),
				),
				'targeting' => array(
					'pages' => array(),
					'roles' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $targeting['roles'] ?? array() ) ) ) ),
				),
			),
			'error'   => '',
			'warning' => '',
		);
	}

	protected function create_item( array $item ) {
		$popup = $this->module ? $this->module->create_popup( $item ) : null;
		return $popup ? (int) $popup['id'] : null;
	}

	protected function update_item( $ref, array $item ): bool {
		if ( ! $this->module ) {
			return false;
		}
		// The pages of an existing popup are kept: they are not in the file.
		$existing = $this->module->get_popup( (int) $ref );
		if ( $existing ) {
			$item['targeting']['pages'] = (array) ( $existing['targeting']['pages'] ?? array() );
		}
		return null !== $this->module->update_popup( (int) $ref, $item );
	}

	protected function remove_item( $ref ): bool {
		return $this->module ? $this->module->delete_popup( (int) $ref ) : false;
	}
}
