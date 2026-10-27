<?php
/**
 * Form definitions of the Form Builder (title, description, fields, settings, status). Never submissions.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer\Providers;

use UxStudio\Modules\Forms\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Submissions, their files, the action log and the revision history stay where they are: they are personal
 * data and history, not definitions. A form is matched by its title. The per-form webhook secret is not
 * exported (the module makes a new one), nor are ids of this database (logo, author, redirect page).
 * The custom "after submit" script is stripped by the module itself for a user without `unfiltered_html`.
 */
final class FormsProvider extends AbstractProvider {

	private const DROP = array( 'webhook_secret', 'logo_id', 'logo_url', 'author_id', 'redirect_page_id', 'created_by' );

	private ?Module $module = null;

	/**
	 * @param Module|null $module Live module instance.
	 */
	public function __construct( ?Module $module = null ) {
		$this->module = $module;
	}

	public function module(): string {
		return 'forms';
	}

	public function kind(): string {
		return 'forms';
	}

	public function label(): string {
		return __( 'Forms', 'ux-studio' );
	}

	public function blocked_reason(): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'uxstudio_forms' ) )
			? ''
			: __( 'The forms table does not exist yet. Switch the Form Builder on and open it once.', 'ux-studio' );
	}

	public function count(): int {
		global $wpdb;
		return '' === $this->blocked_reason() ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_forms" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	protected function key_of( array $item ): string {
		return (string) ( $item['title'] ?? '' );
	}

	protected function current_items(): array {
		if ( '' !== $this->blocked_reason() || ! $this->module ) {
			return array();
		}
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}uxstudio_forms ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = array();
		foreach ( (array) $ids as $id ) {
			$form = $this->module->get_form( (int) $id );
			if ( $form ) {
				$out[] = array(
					'ref'  => (int) $id,
					'item' => $form,
				);
			}
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
			return $fail( __( 'Not a form.', 'ux-studio' ) );
		}
		$title = self::text( $raw['title'] ?? null, 190 );
		if ( null === $title || '' === trim( $title ) ) {
			return $fail( __( 'The form has no title.', 'ux-studio' ) );
		}
		$description = self::text( $raw['description'] ?? '', 5000 );
		$fields      = $raw['fields'] ?? array();
		$settings    = $raw['settings'] ?? array();
		if ( null === $description || ! is_array( $fields ) || ! is_array( $settings ) ) {
			return $fail( __( 'The form has a part in a shape that is not accepted.', 'ux-studio' ) );
		}
		$status = in_array( $raw['status'] ?? 'draft', array( 'active', 'draft', 'archived' ), true ) ? (string) $raw['status'] : 'draft';
		$warning = '';
		if ( isset( $settings['tracking']['script'] ) && '' !== trim( (string) $settings['tracking']['script'] ) && ! current_user_can( 'unfiltered_html' ) ) {
			$warning = __( 'A form has its own script after submit. It is left out, because you may not add scripts to the site.', 'ux-studio' );
		}
		return array(
			'ok'      => true,
			'item'    => array(
				'title'       => $title,
				'description' => $description,
				'status'      => $status,
				'fields'      => array_values( $fields ),
				'settings'    => (array) self::strip( $settings, self::DROP ),
			),
			'error'   => '',
			'warning' => $warning,
		);
	}

	protected function create_item( array $item ) {
		if ( ! $this->module ) {
			return null;
		}
		$form = $this->module->create_form( $item );
		return $form ? (int) $form['id'] : null;
	}

	protected function update_item( $ref, array $item ): bool {
		if ( ! $this->module ) {
			return false;
		}
		// The title is the key, so it stays; revisions of the form record the version that is replaced. The webhook
		// secret of THIS form is kept: it is not in the file and receivers of the webhook rely on it.
		$existing = $this->module->get_form( (int) $ref );
		if ( $existing && ! empty( $existing['settings']['webhook_secret'] ) ) {
			$item['settings']['webhook_secret'] = $existing['settings']['webhook_secret'];
		}
		return null !== $this->module->update_form( (int) $ref, $item );
	}

	protected function remove_item( $ref ): bool {
		return $this->module ? $this->module->delete_form( (int) $ref ) : false;
	}
}
