<?php
/**
 * Shared behaviour of the sources that copy post meta or user meta.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Data in WordPress meta tables (menu item icons and visibility, expiry dates, last login times).
 * A subclass collects normalized items:
 *
 *   key (unique, `<prefix>:<object id>`), label, id (post or user id),
 *   meta (UX Studio meta key => value to store), review (create only, never switch on).
 *
 * Rules shared by every meta source:
 *  - Nothing existing is overwritten. If the object already has the UX Studio meta with the
 *    same value the item is `unchanged`; with a different value it is `skipped` (kept).
 *  - Behaviour-changing meta (changes_behavior(), the default): with the target module OFF the meta
 *    is written directly (the module does not read it until it is switched on); with the module ON
 *    nothing is written, the meta waits in the staging area and `activate()` writes it at the switch.
 *  - Meta that this run wrote is recorded in the mapping table (`postmeta` / `usermeta`) so the rollback
 *    deletes exactly that.
 */
abstract class AbstractMetaSource extends AbstractSource implements Activatable {

	/** @var array{items:array<int, array<string, mixed>>,skipped:array<string, int>}|null */
	private ?array $collected = null;

	/**
	 * `post` or `user` (the WordPress meta type).
	 */
	abstract protected function meta_type(): string;

	/**
	 * Read the foreign storage.
	 *
	 * @return array{items:array<int, array<string, mixed>>,skipped:array<string, int>}
	 */
	abstract protected function build(): array;

	/**
	 * Cached result of build().
	 *
	 * @return array{items:array<int, array<string, mixed>>,skipped:array<string, int>}
	 */
	protected function collect(): array {
		if ( null === $this->collected ) {
			$this->collected = $this->build();
		}
		return $this->collected;
	}

	public function forget(): void {
		$this->collected = null;
		parent::forget();
	}

	protected function has_data(): bool {
		$c = $this->collect();
		return count( $c['items'] ) > 0 || count( $c['skipped'] ) > 0 || parent::has_data();
	}

	/**
	 * Called after a meta set was written (schedule an event ...). Add mapping rows to `$maps`.
	 *
	 * @param int                                $id    Object id.
	 * @param array<string, mixed>               $item  Item (with `extra`).
	 * @param array<int, array{0:string,1:string}> $maps Mapping rows to record.
	 */
	protected function after_write( int $id, array $item, array &$maps ): void {}

	protected function data_total( array $options ): int {
		return count( $this->collect()['items'] );
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		$all    = $this->collect()['items'];
		$offset = (int) $position;
		return array(
			'items' => array_slice( $all, $offset, $limit ),
			'next'  => $offset + $limit,
			'done'  => $offset + $limit >= count( $all ),
		);
	}

	/**
	 * Compare the object's current meta with the wanted one.
	 *
	 * @param int                  $id   Object id.
	 * @param array<string, mixed> $meta Wanted meta.
	 * @return string `same` (all there), `missing` (none there), `conflict` (some differ) or `partial`.
	 */
	protected function meta_state( int $id, array $meta ): string {
		$same = 0;
		$none = 0;
		foreach ( $meta as $key => $value ) {
			if ( ! metadata_exists( $this->meta_type(), $id, (string) $key ) ) {
				++$none;
				continue;
			}
			if ( (string) get_metadata( $this->meta_type(), $id, (string) $key, true ) === (string) $value ) {
				++$same;
			} else {
				return 'conflict';
			}
		}
		if ( count( $meta ) === $same ) {
			return 'same';
		}
		return count( $meta ) === $none ? 'missing' : 'partial';
	}

	protected function preview_item( array $item, array $ctx ): array {
		switch ( $this->meta_state( (int) $item['id'], (array) $item['meta'] ) ) {
			case 'same':
				return array( 'status' => 'unchanged' );
			case 'conflict':
				return array(
					'status'  => 'skipped',
					'message' => __( 'UX Studio already has a different value here, it is kept.', 'ux-studio' ),
				);
			default:
				return array( 'status' => 'created' );
		}
	}

	protected function apply_item( array $item, array $ctx ): array {
		$id    = (int) $item['id'];
		$state = $this->meta_state( $id, (array) $item['meta'] );
		if ( 'same' === $state ) {
			return array( 'status' => 'unchanged' );
		}
		if ( 'conflict' === $state ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'UX Studio already has a different value here, it is kept.', 'ux-studio' ),
			);
		}
		$module = $this->target_module();
		if ( $this->changes_behavior() && Staging::module_enabled( $module ) ) {
			// Prepared only: the module is on and would act on it while the old plugin still does.
			if ( ! empty( $item['review'] ) ) {
				return array( 'status' => 'skipped', 'message' => __( 'Needs a manual check.', 'ux-studio' ) );
			}
			return array(
				'status'   => 'created',
				'activate' => (string) $id,
				'payload'  => array(
					'meta'  => $item['meta'],
					'extra' => $item['extra'] ?? null,
					'type'  => $this->meta_type(),
				),
			);
		}
		return array(
			'status' => 'created',
			'maps'   => $this->write( $id, $item ),
		);
	}

	/**
	 * Write the meta of an item.
	 *
	 * @param int                  $id   Object id.
	 * @param array<string, mixed> $item Item.
	 * @return array<int, array{0:string,1:string}> Mapping rows of what was created.
	 */
	private function write( int $id, array $item ): array {
		$maps = array();
		foreach ( (array) $item['meta'] as $key => $value ) {
			if ( metadata_exists( $this->meta_type(), $id, (string) $key ) ) {
				continue;
			}
			add_metadata( $this->meta_type(), $id, (string) $key, $value, true );
			$maps[] = array( $this->meta_type() . 'meta', $id . '|' . $key );
		}
		$this->after_write( $id, $item, $maps );
		return $maps;
	}

	protected function verify_item( array $item ): array {
		$id    = (int) $item['id'];
		$state = $this->meta_state( $id, (array) $item['meta'] );
		if ( 'same' === $state ) {
			return array(
				'ok'     => true,
				'detail' => '',
			);
		}
		if ( 'missing' === $state && $this->pending( $id ) ) {
			return array(
				'ok'     => true,
				'detail' => '',
			);
		}
		return array(
			'ok'     => false,
			'detail' => 'conflict' === $state
				? __( 'UX Studio has a different value here.', 'ux-studio' )
				: __( 'The value is not in UX Studio.', 'ux-studio' ),
		);
	}

	/**
	 * Is a write for this object waiting in the staging area?
	 *
	 * @param int $id Object id.
	 */
	private function pending( int $id ): bool {
		foreach ( Staging::all() as $entry ) {
			foreach ( (array) ( $entry['activations'] ?? array() ) as $row ) {
				if ( $row['source'] === $this->slug && in_array( (string) $id, array_map( 'strval', (array) $row['ids'] ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public function activate( string $kind, array $ids, array $data = array() ): int {
		if ( $kind !== $this->data_kind() ) {
			return 0;
		}
		$count = 0;
		foreach ( $ids as $id ) {
			$payload = $data[ (string) $id ] ?? null;
			if ( ! is_array( $payload ) || ! isset( $payload['meta'] ) ) {
				continue;
			}
			$item = array(
				'meta'  => (array) $payload['meta'],
				'extra' => $payload['extra'] ?? null,
			);
			$this->write( (int) $id, $item );
			if ( 'same' === $this->meta_state( (int) $id, $item['meta'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Undo of activate(): remove the meta the switch wrote (only when it still holds the value the
	 * switch wrote, a value changed since then is the admin's and stays).
	 */
	public function deactivate( string $kind, array $ids, array $data = array() ): int {
		if ( $kind !== $this->data_kind() ) {
			return 0;
		}
		$count = 0;
		foreach ( $ids as $id ) {
			$payload = $data[ (string) $id ] ?? null;
			if ( ! is_array( $payload ) || ! isset( $payload['meta'] ) ) {
				continue;
			}
			foreach ( (array) $payload['meta'] as $key => $value ) {
				if ( metadata_exists( $this->meta_type(), (int) $id, (string) $key )
					&& (string) get_metadata( $this->meta_type(), (int) $id, (string) $key, true ) === (string) $value ) {
					'user' === $this->meta_type() ? delete_user_meta( (int) $id, (string) $key ) : delete_post_meta( (int) $id, (string) $key );
				}
			}
			$this->after_unwrite( (int) $id, array( 'extra' => $payload['extra'] ?? null ) );
			++$count;
		}
		return $count;
	}

	/**
	 * Called after the meta of an item was removed again (unschedule an event ...).
	 *
	 * @param int                  $id   Object id.
	 * @param array<string, mixed> $item Item (with `extra`).
	 */
	protected function after_unwrite( int $id, array $item ): void {}
}
