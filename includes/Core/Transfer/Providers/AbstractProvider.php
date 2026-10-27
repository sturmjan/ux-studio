<?php
/**
 * Shared logic of the data providers: natural keys, plan, import and undo on top of three small hooks.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer\Providers;

use UxStudio\Core\Mcp\Secrets;
use UxStudio\Core\Transfer\DataProvider;
use UxStudio\Core\Transfer\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * A provider supplies: `current_items()` (portable items WITH a reference to the stored object),
 * `clean_item()` (strict shape of one item from a file), and `create_item()` / `update_item()` /
 * `remove_item()`. The rest is common.
 */
abstract class AbstractProvider implements DataProvider {

	/** Most items one import takes in. */
	public const MAX_ITEMS = 500;

	public function capability(): string {
		return '';
	}

	public function blocked_reason(): string {
		return '';
	}

	/**
	 * Natural key of an item.
	 *
	 * @param array<string, mixed> $item Item.
	 */
	abstract protected function key_of( array $item ): string;

	/**
	 * Name of an item for the preview.
	 *
	 * @param array<string, mixed> $item Item.
	 */
	protected function label_of( array $item ): string {
		return $this->key_of( $item );
	}

	/**
	 * Items on this site: list of [ 'ref' => stored reference, 'item' => portable item ].
	 *
	 * @return array<int, array{ref:mixed,item:array<string,mixed>}>
	 */
	abstract protected function current_items(): array;

	/**
	 * Strict shape of one item from a file.
	 *
	 * @param mixed $raw Item from the file.
	 * @return array{ok:bool,item:array<string,mixed>,error:string,warning:string}
	 */
	abstract protected function clean_item( $raw ): array;

	/**
	 * @param array<string, mixed> $item Cleaned item.
	 * @return mixed Reference of the new object, or null when it could not be created.
	 */
	abstract protected function create_item( array $item );

	/**
	 * @param mixed                $ref  Reference of the stored object.
	 * @param array<string, mixed> $item Cleaned item.
	 */
	abstract protected function update_item( $ref, array $item ): bool;

	/**
	 * Put a stored object back to the state it had before an import (undo). Default: the same as an update.
	 *
	 * @param mixed                $ref  Reference of the stored object.
	 * @param array<string, mixed> $item Item as it was.
	 */
	protected function restore_item( $ref, array $item ): bool {
		return $this->update_item( $ref, $item );
	}

	/**
	 * @param mixed $ref Reference of the stored object.
	 */
	abstract protected function remove_item( $ref ): bool;

	public function count(): int {
		return count( $this->current_items() );
	}

	public function export(): array {
		return array_column( $this->normalised(), 'item' );
	}

	/**
	 * The part of an item that decides "same or changed" (a provider can leave out what an import does not set).
	 *
	 * @param array<string, mixed> $item Item.
	 * @return array<string, mixed>
	 */
	protected function comparable( array $item ): array {
		return $item;
	}

	/**
	 * Stored items passed through the same strict shape as items from a file, so an unchanged item compares
	 * equal to its own export.
	 *
	 * @return array<int, array{ref:mixed,item:array<string,mixed>}>
	 */
	protected function normalised(): array {
		$out = array();
		foreach ( $this->current_items() as $entry ) {
			$clean = $this->clean_item( $entry['item'] );
			if ( $clean['ok'] ) {
				$entry['item'] = $clean['item'];
				$out[]         = $entry;
			}
		}
		return $out;
	}

	/**
	 * Keys with a repeat counter, so two items with the same title stay two items.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return string[]
	 */
	protected function keys_of( array $items ): array {
		$seen = array();
		$keys = array();
		foreach ( $items as $item ) {
			$key          = $this->key_of( $item );
			$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
			$keys[]       = $seen[ $key ] > 1 ? $key . '#' . $seen[ $key ] : $key;
		}
		return $keys;
	}

	/**
	 * Current items by key.
	 *
	 * @return array<string, array{ref:mixed,item:array<string,mixed>}>
	 */
	protected function current_map(): array {
		$current = $this->normalised();
		$keys    = $this->keys_of( array_column( $current, 'item' ) );
		return array_combine( $keys, $current ) ?: array();
	}

	/**
	 * Clean the incoming list (shape, count) and key it.
	 *
	 * @param array<int, mixed> $incoming Items from the file.
	 * @return array{ok:array<string,array<string,mixed>>,rejected:array<int,array{label:string,message:string}>,warnings:string[]}
	 */
	protected function clean_all( array $incoming ): array {
		$good     = array();
		$rejected = array();
		$warnings = array();
		$incoming = array_slice( array_values( $incoming ), 0, self::MAX_ITEMS );
		$cleaned  = array();
		foreach ( $incoming as $i => $raw ) {
			$result = $this->clean_item( $raw );
			if ( ! $result['ok'] ) {
				$rejected[] = array(
					'label'   => is_array( $raw ) ? $this->safe_label( $raw, $i ) : '#' . ( $i + 1 ),
					'message' => $result['error'],
				);
				continue;
			}
			if ( '' !== $result['warning'] ) {
				$warnings[] = $result['warning'];
			}
			$cleaned[] = $result['item'];
		}
		foreach ( $this->keys_of( $cleaned ) as $i => $key ) {
			$good[ $key ] = $cleaned[ $i ];
		}
		return array(
			'ok'       => $good,
			'rejected' => $rejected,
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * @param array<string, mixed> $raw   Raw item.
	 * @param int                  $index Position.
	 */
	private function safe_label( array $raw, int $index ): string {
		foreach ( array( 'title', 'name', 'source' ) as $field ) {
			if ( isset( $raw[ $field ] ) && is_string( $raw[ $field ] ) && '' !== trim( $raw[ $field ] ) ) {
				return Planner::shorten( $raw[ $field ] );
			}
		}
		return '#' . ( $index + 1 );
	}

	public function plan( array $incoming ): array {
		$clean   = $this->clean_all( $incoming );
		$current = $this->current_map();
		$items   = array();
		foreach ( $clean['ok'] as $key => $item ) {
			$action = 'create';
			if ( isset( $current[ $key ] ) ) {
				$action = Planner::same( $this->comparable( $current[ $key ]['item'] ), $this->comparable( $item ) ) ? 'same' : 'update';
			}
			$items[] = array(
				'key'    => $key,
				'label'  => $this->label_of( $item ),
				'action' => $action,
				'note'   => '',
			);
		}
		return array(
			'items'    => $items,
			'rejected' => $clean['rejected'],
			'warnings' => $clean['warnings'],
		);
	}

	public function import( array $incoming ): array {
		$clean   = $this->clean_all( $incoming );
		$current = $this->current_map();
		$out     = array(
			'created'   => array(),
			'previous'  => array(),
			'failed'    => $clean['rejected'],
			'unchanged' => 0,
		);
		foreach ( $clean['ok'] as $key => $item ) {
			try {
				if ( isset( $current[ $key ] ) ) {
					if ( Planner::same( $this->comparable( $current[ $key ]['item'] ), $this->comparable( $item ) ) ) {
						++$out['unchanged'];
						continue;
					}
					if ( $this->update_item( $current[ $key ]['ref'], $item ) ) {
						$out['previous'][] = $current[ $key ]['item'];
					} else {
						$out['failed'][] = array(
							'label'   => $this->label_of( $item ),
							'message' => __( 'The module refused the change.', 'ux-studio' ),
						);
					}
					continue;
				}
				$ref = $this->create_item( $item );
				if ( null === $ref || false === $ref ) {
					$out['failed'][] = array(
						'label'   => $this->label_of( $item ),
						'message' => __( 'The module refused to create it.', 'ux-studio' ),
					);
				} else {
					$out['created'][] = $ref;
				}
			} catch ( \Throwable $e ) {
				$out['failed'][] = array(
					'label'   => $this->label_of( $item ),
					'message' => __( 'It could not be saved.', 'ux-studio' ),
				);
			}
		}
		return $out;
	}

	public function undo( array $created, array $previous ): array {
		$out = array(
			'removed'  => 0,
			'restored' => 0,
			'failed'   => 0,
		);
		foreach ( $created as $ref ) {
			try {
				$this->remove_item( $ref ) ? ++$out['removed'] : ++$out['failed'];
			} catch ( \Throwable $e ) {
				++$out['failed'];
			}
		}
		if ( $previous ) {
			$current = $this->current_map();
			$keys    = $this->keys_of( $previous );
			foreach ( $previous as $i => $item ) {
				$hit = $current[ $keys[ $i ] ] ?? null;
				try {
					null !== $hit && $this->restore_item( $hit['ref'], $item ) ? ++$out['restored'] : ++$out['failed'];
				} catch ( \Throwable $e ) {
					++$out['failed'];
				}
			}
		}
		return $out;
	}

	/**
	 * Remove keys that carry a credential, an id of this database or an address of this site from nested data.
	 *
	 * @param mixed    $data Any value.
	 * @param string[] $drop Key names removed at any depth.
	 * @return mixed
	 */
	protected static function strip( $data, array $drop ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && ( in_array( $key, $drop, true ) || Secrets::is_secret_key( $key ) ) ) {
				continue;
			}
			$out[ $key ] = self::strip( $value, $drop );
		}
		return $out;
	}

	/**
	 * Bounded text (rejects non-strings, controls and over-long values).
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Longest length.
	 */
	protected static function text( $value, int $max = 500 ): ?string {
		if ( ! is_string( $value ) || strlen( $value ) > $max || false !== strpos( $value, "\0" ) ) {
			return null;
		}
		return $value;
	}
}
