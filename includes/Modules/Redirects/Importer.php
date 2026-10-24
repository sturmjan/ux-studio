<?php
/**
 * CSV import/export and a one-click import from the Redirection plugin's
 * tables (this module replaces it). Every row goes through
 * Repository::save(), so imported rules get the same chain/loop checks.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

use UxStudio\Core\Csv;

defined( 'ABSPATH' ) || exit;

final class Importer {

	/** Max rows per import call. */
	public const MAX_ROWS = 5000;

	/**
	 * Import CSV text. Columns: source, target, code, note (header optional,
	 * comma or semicolon separated). Empty target + code 410 = gone.
	 *
	 * @param string $csv         CSV text.
	 * @param string $on_conflict update|skip|error.
	 * @param bool   $dry_run     Validate only.
	 * @return array{summary:array<string, int>, results:array<int, array<string, mixed>>}
	 */
	public static function csv( string $csv, string $on_conflict, bool $dry_run ): array {
		$csv   = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv ) ) ?: array();
		$delim = isset( $lines[0] ) && substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ',';

		$columns = array( 'source', 'target', 'code', 'note' );
		$items   = array();
		foreach ( $lines as $n => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map( 'trim', str_getcsv( $line, $delim, '"', '' ) );
			if ( 0 === $n && in_array( strtolower( $cells[0] ?? '' ), array( 'source', 'url', 'from', 'old' ), true ) ) {
				$columns = array_map(
					static fn ( string $c ): string => array( 'url' => 'source', 'from' => 'source', 'old' => 'source', 'to' => 'target', 'new' => 'target', 'status' => 'code' )[ strtolower( $c ) ] ?? strtolower( $c ),
					$cells
				);
				continue;
			}
			$item = array();
			foreach ( $columns as $i => $column ) {
				if ( isset( $cells[ $i ] ) ) {
					// Undo the formula guard our own export adds.
					$item[ $column ] = str_starts_with( $cells[ $i ], "'" ) ? substr( $cells[ $i ], 1 ) : $cells[ $i ];
				}
			}
			if ( empty( $item['code'] ) ) {
				$item['code'] = '' === ( $item['target'] ?? '' ) ? 410 : 301;
			}
			// Conditions travel as one JSON cell (the export writes them the same way).
			if ( isset( $item['conditions'] ) ) {
				$item['conditions'] = '' === trim( (string) $item['conditions'] ) ? array() : (array) json_decode( (string) $item['conditions'], true );
			}
			$items[] = $item;
		}
		return self::run( $items, $on_conflict, $dry_run );
	}

	/**
	 * Whether the Redirection plugin's table exists.
	 */
	public static function redirection_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Import URL redirects (and 410s) from the Redirection plugin. Its login / role / referrer / agent / cookie /
	 * IP / server / HTTP header match types become rules with conditions; language, custom filter and page type
	 * matches have no equivalent and are skipped.
	 *
	 * @param string $on_conflict update|skip|error.
	 * @param bool   $dry_run     Validate only.
	 * @return array{summary:array<string, int>, results:array<int, array<string, mixed>>}
	 */
	public static function redirection( string $on_conflict, bool $dry_run ): array {
		if ( ! self::redirection_available() ) {
			return array( 'summary' => array(), 'results' => array() );
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'redirection_items';
		$groups = self::redirection_group_status();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY position ASC, id ASC LIMIT %d", self::MAX_ROWS ), ARRAY_A );

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items = array_merge( $items, self::redirection_rules( $row, $groups )['items'] );
		}
		return self::run( $items, $on_conflict, $dry_run );
	}

	/**
	 * The plugin's global defaults for the match flags (rules that do not carry their own `match_data` use them).
	 * Without saved options the plugin itself falls back to: case sensitive, trailing slash significant, exact query.
	 *
	 * @return array{flag_case:bool, flag_trailing:bool, flag_query:string}
	 */
	public static function redirection_flag_defaults(): array {
		$options = get_option( 'redirection_options', array() );
		$options = is_array( $options ) ? $options : array();
		$query   = (string) ( $options['flag_query'] ?? 'exact' );
		return array(
			'flag_case'     => ! empty( $options['flag_case'] ),
			'flag_trailing' => ! empty( $options['flag_trailing'] ),
			'flag_query'    => in_array( $query, array( 'exact', 'exactorder', 'ignore', 'pass' ), true ) ? $query : 'exact',
		);
	}

	/**
	 * Status of the Redirection groups (`id => enabled`). A rule in a disabled group does not
	 * redirect in the plugin, so it must not become active here either.
	 *
	 * @return array<int, bool>
	 */
	public static function redirection_group_status(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_groups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, status FROM {$table}", ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['id'] ] = 'enabled' === $row['status'];
		}
		return $out;
	}

	/**
	 * One Redirection row as rule inputs. A plain URL rule is one rule. A rule with a match type (login, role,
	 * referrer, user agent, cookie, IP, server, HTTP header) has a target for "matched" and one for "not matched";
	 * each becomes its own rule with the condition (the second one negated), and a state without a target makes no
	 * rule, exactly as the plugin then does nothing. `skipped` names the reason when the row has no equivalent here
	 * (`match:<type>` for language, custom filter and page type, `action:<type>` for random and pass-through
	 * targets, `empty` for a conditional rule without any target).
	 *
	 * @param array<string, mixed> $row    Row of `redirection_items`.
	 * @param array<int, bool>     $groups Group id => enabled.
	 * @return array{items:array<int, array<string, mixed>>, skipped:string}
	 */
	public static function redirection_rules( array $row, array $groups = array(), ?array $defaults = null ): array {
		$match    = (string) $row['match_type'];
		$code     = (int) $row['action_code'];
		$atype    = (string) $row['action_type'];
		$data     = json_decode( (string) ( $row['match_data'] ?? '' ), true );
		$flags    = is_array( $data ) && isset( $data['source'] ) && is_array( $data['source'] ) ? $data['source'] : array();
		$group    = (int) ( $row['group_id'] ?? 0 );
		$defaults = $defaults ?? self::redirection_flag_defaults();
		$regex    = (bool) (int) $row['regex'];

		// The plugin's flags: a rule's own `match_data.source`, otherwise the plugin's global default. NOTE the naming:
		// `flag_case` = true means "ignore case" (case INsensitive), the plugin's default (false) is case sensitive.
		$ignore_case = array_key_exists( 'flag_case', $flags ) && is_bool( $flags['flag_case'] ) ? $flags['flag_case'] : $defaults['flag_case'];
		$trailing    = array_key_exists( 'flag_trailing', $flags ) && is_bool( $flags['flag_trailing'] ) ? $flags['flag_trailing'] : $defaults['flag_trailing'];
		$query       = isset( $flags['flag_query'] ) && in_array( $flags['flag_query'], array( 'exact', 'exactorder', 'ignore', 'pass' ), true ) ? (string) $flags['flag_query'] : $defaults['flag_query'];
		$mode        = 'default';
		$unmapped    = false;
		if ( ! $regex ) {
			// exact / exactorder -> exact (the order of the parameters is not compared, see the plan), ignore / pass as they are.
			// A source that itself names parameters is compared parameter by parameter by the plugin (extra ones allowed
			// with ignore / pass); the module has no such subset match, so it keeps its exact comparison there.
			$mode = in_array( $query, array( 'ignore', 'pass' ), true ) ? $query : 'exact';
			if ( in_array( $mode, array( 'ignore', 'pass' ), true ) && str_contains( (string) $row['url'], '?' ) ) {
				$mode     = 'default';
				$unmapped = true;
			}
		}
		$last_access = (string) ( $row['last_access'] ?? '' );
		$last_hit    = Repository::clean_datetime( function_exists( 'get_date_from_gmt' ) && '' !== $last_access ? get_date_from_gmt( $last_access ) : $last_access );

		$base = array(
			'key'            => 'item:' . (int) $row['id'],
			'label'          => (string) $row['url'],
			'source'         => (string) $row['url'],
			'match_type'     => $regex ? 'regex' : 'exact',
			'target'         => '',
			'code'           => in_array( $code, Repository::CODES, true ) ? $code : ( 303 === $code ? 302 : 301 ),
			'enabled'        => 'enabled' === $row['status'] && ( ! isset( $groups[ $group ] ) || $groups[ $group ] ),
			'note'           => (string) ( $row['title'] ?? '' ),
			'position'       => (int) $row['position'],
			'conditions'     => array(),
			'case_sensitive' => ! $ignore_case,
			'query_mode'     => $mode,
			'hits'           => max( 0, (int) ( $row['last_count'] ?? 0 ) ),
			'last_hit'       => $last_hit,
			// What the plugin matched on that the module still does not (counted by the migration plan).
			'query_unmapped' => $unmapped,
			'query_order'    => ! $regex && 'exactorder' === $query,
			'trailing_slash' => ! $regex && ! $trailing,
		);

		if ( 'url' === $match ) {
			if ( 'url' === $atype ) {
				$base['target'] = (string) $row['action_data'];
			} elseif ( ! ( 'error' === $atype && 410 === $code ) ) {
				return array( 'items' => array(), 'skipped' => 'action:' . $atype );
			}
			return array( 'items' => array( $base ), 'skipped' => '' );
		}

		if ( ! in_array( $match, array( 'login', 'role', 'referrer', 'agent', 'cookie', 'header', 'ip', 'server' ), true ) ) {
			return array( 'items' => array(), 'skipped' => 'match:' . $match );
		}
		if ( 'url' !== $atype ) {
			return array( 'items' => array(), 'skipped' => 'action:' . $atype );
		}
		$extra = @unserialize( (string) $row['action_data'], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the plugin's own storage format, no objects allowed.
		if ( ! is_array( $extra ) ) {
			return array( 'items' => array(), 'skipped' => 'empty' );
		}
		$op = ! empty( $extra['regex'] ) ? 'regex' : 'equals';
		switch ( $match ) {
			case 'login':
				$pairs = array(
					array( array( 'type' => 'login', 'value' => 'in' ), (string) ( $extra['logged_in'] ?? '' ) ),
					array( array( 'type' => 'login', 'value' => 'out' ), (string) ( $extra['logged_out'] ?? '' ) ),
				);
				break;
			default:
				switch ( $match ) {
					case 'referrer':
						$cond = array( 'type' => 'referrer', 'op' => $op, 'value' => (string) ( $extra['referrer'] ?? '' ) );
						break;
					case 'agent':
						$cond = array( 'type' => 'agent', 'op' => $op, 'value' => (string) ( $extra['agent'] ?? '' ) );
						break;
					case 'cookie':
					case 'header':
						$cond = array( 'type' => $match, 'name' => (string) ( $extra['name'] ?? '' ), 'op' => $op, 'value' => (string) ( $extra['value'] ?? '' ) );
						break;
					case 'role':
						$cond = array( 'type' => 'role', 'value' => (string) ( $extra['role'] ?? '' ) );
						break;
					case 'ip':
						$cond = array( 'type' => 'ip', 'value' => (array) ( $extra['ip'] ?? array() ) );
						break;
					default: // server.
						$cond = array( 'type' => 'host', 'op' => 'equals', 'value' => (string) ( $extra['server'] ?? '' ) );
				}
				$pairs = array(
					array( $cond, (string) ( $extra['url_from'] ?? '' ) ),
					array( $cond + array( 'negate' => true ), (string) ( $extra['url_notfrom'] ?? '' ) ),
				);
		}

		$items = array();
		foreach ( $pairs as $n => $pair ) {
			if ( '' === trim( $pair[1] ) ) {
				continue; // The plugin does nothing in this state.
			}
			$item               = $base;
			$item['key']        = $base['key'] . ( $n > 0 ? ':else' : '' );
			$item['target']     = trim( $pair[1] );
			$item['conditions'] = array( $pair[0] );
			$items[]            = $item;
		}
		return array( 'items' => $items, 'skipped' => $items ? '' : 'empty' );
	}

	/**
	 * A page of Redirection rows for the migration wizard (by id, so a batch can resume).
	 *
	 * @param int $after_id Last id of the previous page (0 = start).
	 * @param int $limit    Rows per page.
	 * @return array{items:array<int, array<string, mixed>>,skipped:array<string, int>,last:int,done:bool}
	 */
	public static function redirection_page( int $after_id, int $limit ): array {
		$out = array(
			'items'   => array(),
			'skipped' => array(),
			'last'    => $after_id,
			'done'    => true,
		);
		if ( ! self::redirection_available() ) {
			return $out;
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'redirection_items';
		$groups = self::redirection_group_status();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $after_id, $limit ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as $row ) {
			$out['last'] = (int) $row['id'];
			$converted   = self::redirection_rules( $row, $groups );
			foreach ( $converted['items'] as $item ) {
				$out['items'][] = $item;
			}
			if ( '' !== $converted['skipped'] ) {
				$out['skipped'][ $converted['skipped'] ] = ( $out['skipped'][ $converted['skipped'] ] ?? 0 ) + 1;
			}
		}
		$out['done'] = count( $rows ) < $limit;
		return $out;
	}

	/**
	 * All rules as CSV (same columns the importer reads).
	 */
	public static function export_csv(): string {
		global $wpdb;
		$table = Repository::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, array( 'source', 'target', 'code', 'note', 'match_type', 'enabled', 'hits', 'conditions', 'position', 'case_sensitive', 'query_mode', 'last_hit' ), ',', '"', '' );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$target = 410 === (int) $row['code'] ? '' : ( 'regex' === $row['match_type'] ? (string) $row['target'] : Repository::resolve_target( $row ) );
			$mode   = '' === (string) ( $row['query_mode'] ?? '' ) ? 'default' : (string) $row['query_mode'];
			fputcsv( $out, Csv::safe_row( array( $row['source'], $target, $row['code'], $row['note'], $row['match_type'], $row['enabled'], $row['hits'], (string) ( $row['conditions'] ?? '' ), $row['position'], (int) ( $row['case_sensitive'] ?? 0 ), $mode, (string) ( $row['last_hit'] ?? '' ) ) ), ',', '"', '' );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	/**
	 * Save items one by one, collect results.
	 *
	 * @param array<int, array<string, mixed>> $items       Rule inputs.
	 * @param string                           $on_conflict update|skip|error.
	 * @param bool                             $dry_run     Validate only.
	 * @return array{summary:array<string, int>, results:array<int, array<string, mixed>>}
	 */
	private static function run( array $items, string $on_conflict, bool $dry_run ): array {
		$summary = array();
		$results = array();
		foreach ( array_slice( $items, 0, self::MAX_ROWS ) as $index => $item ) {
			$item['origin'] = 'import';
			$result         = Repository::save( $item, $on_conflict, $dry_run );
			$summary[ $result['status'] ] = ( $summary[ $result['status'] ] ?? 0 ) + 1;
			// Keep the response small: only rows worth looking at.
			if ( $result['errors'] || $result['warnings'] ) {
				$result['index'] = $index;
				$results[]       = $result;
			}
		}
		return array(
			'summary' => $summary,
			'results' => $results,
		);
	}
}
