<?php
/**
 * Redirect rules (source, target, code, conditions). Never hit counters or the 404 log.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer\Providers;

use UxStudio\Core\Transfer\Envelope;
use UxStudio\Modules\Redirects\Conditions;
use UxStudio\Modules\Redirects\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * A rule is identified by match type + source + conditions (the same key the module's own unique index
 * uses). A rule that points to a post by id is exported with the address of that post, because ids do not
 * travel. Writes go through `Repository::save()`, which validates sources and targets, refuses loops and
 * unsafe regular expressions and refreshes the engine state.
 */
final class RedirectsProvider extends AbstractProvider {

	public function module(): string {
		return 'redirects';
	}

	public function kind(): string {
		return 'rules';
	}

	public function label(): string {
		return __( 'Redirect rules', 'ux-studio' );
	}

	public function blocked_reason(): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Repository::table() ) )
			? ''
			: __( 'The redirects table does not exist yet. Switch the Redirects module on and open it once.', 'ux-studio' );
	}

	public function count(): int {
		global $wpdb;
		$table = Repository::table();
		return '' === $this->blocked_reason() ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	protected function key_of( array $item ): string {
		return $item['match_type'] . '|' . $item['source'] . '|' . Envelope::canonical( $item['conditions'] );
	}

	protected function label_of( array $item ): string {
		return $item['source'] . ' → ' . ( 410 === $item['code'] ? '410' : $item['target'] );
	}

	protected function current_items(): array {
		if ( '' !== $this->blocked_reason() ) {
			return array();
		}
		global $wpdb;
		$table = Repository::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY position ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$rule   = Repository::format( $row );
			$target = (string) $rule['target'];
			if ( (int) $rule['target_post_id'] > 0 ) {
				$target = self::relative( (string) $rule['target_url'] );
			}
			$out[] = array(
				'ref'  => (int) $rule['id'],
				'item' => array(
					'source'         => $rule['source'],
					'match_type'     => $rule['match_type'],
					'target'         => $target,
					'code'           => $rule['code'],
					'conditions'     => $rule['conditions'],
					'case_sensitive' => $rule['case_sensitive'],
					'query_mode'     => $rule['query_mode'],
					'enabled'        => $rule['enabled'],
					'note'           => $rule['note'],
					'position'       => $rule['position'],
				),
			);
		}
		return $out;
	}

	/**
	 * An address of this site as a path; any other address stays as it is.
	 *
	 * @param string $url Address.
	 */
	private static function relative( string $url ): string {
		$home = untrailingslashit( home_url() );
		if ( '' !== $home && 0 === strpos( $url, $home . '/' ) ) {
			return substr( $url, strlen( $home ) );
		}
		return $url;
	}

	protected function clean_item( $raw ): array {
		$fail = static fn( string $m ): array => array(
			'ok'      => false,
			'item'    => array(),
			'error'   => $m,
			'warning' => '',
		);
		if ( ! is_array( $raw ) ) {
			return $fail( __( 'Not a rule.', 'ux-studio' ) );
		}
		$source = self::text( $raw['source'] ?? null, 2000 );
		$target = self::text( $raw['target'] ?? '', 2000 );
		$match  = $raw['match_type'] ?? 'exact';
		$code   = $raw['code'] ?? 301;
		if ( null === $source || '' === trim( $source ) || null === $target || ! in_array( $match, Repository::MATCH_TYPES, true ) || ! is_int( $code ) || ! in_array( $code, Repository::CODES, true ) ) {
			return $fail( __( 'The rule has a part in a shape that is not accepted.', 'ux-studio' ) );
		}
		$conditions = $raw['conditions'] ?? array();
		if ( ! is_array( $conditions ) ) {
			return $fail( __( 'The rule has conditions in a shape that is not accepted.', 'ux-studio' ) );
		}
		$normalized = Conditions::normalize( $conditions );
		if ( $normalized['errors'] ) {
			return $fail( __( 'The conditions of the rule are not valid.', 'ux-studio' ) );
		}
		$mode = (string) ( $raw['query_mode'] ?? 'default' );
		return array(
			'ok'      => true,
			'item'    => array(
				'source'         => trim( $source ),
				'match_type'     => (string) $match,
				'target'         => $target,
				'code'           => $code,
				'conditions'     => $normalized['conditions'],
				'case_sensitive' => ! empty( $raw['case_sensitive'] ),
				'query_mode'     => in_array( $mode, Repository::QUERY_MODES, true ) ? $mode : 'default',
				'enabled'        => ! array_key_exists( 'enabled', $raw ) || ! empty( $raw['enabled'] ),
				'note'           => mb_substr( (string) self::text( $raw['note'] ?? '', 255 ), 0, 255 ),
				'position'       => (int) ( $raw['position'] ?? 0 ),
			),
			'error'   => '',
			'warning' => '',
		);
	}

	protected function create_item( array $item ) {
		$result = Repository::save( $item + array( 'origin' => 'import' ), 'error' );
		return in_array( $result['status'], array( 'created', 'updated' ), true ) && $result['id'] ? (int) $result['id'] : null;
	}

	protected function update_item( $ref, array $item ): bool {
		$result = Repository::save( array( 'id' => (int) $ref ) + $item, 'update' );
		return in_array( $result['status'], array( 'updated', 'unchanged' ), true );
	}

	protected function remove_item( $ref ): bool {
		return Repository::delete( array( (int) $ref ) ) > 0;
	}
}
