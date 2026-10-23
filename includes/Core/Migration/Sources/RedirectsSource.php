<?php
/**
 * Source: Redirection, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Activatable;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\Staging;
use UxStudio\Modules\Redirects\Importer;
use UxStudio\Modules\Redirects\Module as RedirectsModule;
use UxStudio\Modules\Redirects\NotFoundLog;
use UxStudio\Modules\Redirects\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `redirects` (table `uxstudio_redirects`, every write through Repository::save(),
 * so imported rules get the same loop and chain checks as hand-made ones).
 *
 * BEHAVIOUR-CHANGING: a redirect rule takes over a URL. With the module OFF the rules are stored
 * as the old plugin had them (they do nothing until the module is switched on). With the module
 * ON they are created INACTIVE and Staging::apply() switches on the ones that were active in the
 * old plugin (Activatable::activate).
 *
 *  - Redirection: tables `redirection_items` + `redirection_groups` (the reader is
 *    Importer::redirection_page, shared with the one-click import).
 *  - Safe Redirect Manager: posts of type `redirect_rule`, meta `_redirect_rule_from|to|status_code|
 *    from_regex|notes`, a trailing `*` is a wildcard; status = published.
 *  - Simple 301 Redirects: option `301_redirects` (from => to), wildcard `*` only when option
 *    `301_redirects_wildcard` is `true`.
 *  - 301 Redirects: table `ts_redirects` (old_link, new_link).
 */
final class RedirectsSource extends AbstractSource implements Activatable {

	private const MODULE = 'redirects';

	/** @var array{items:array<int, array<string, mixed>>,skipped:array<string, int>,flags:array<string, int>}|null */
	private ?array $collected = null;

	protected function option_names(): array {
		switch ( $this->slug ) {
			case 'simple-301-redirects':
				return array( '301_redirects' );
			default:
				return array();
		}
	}

	protected function has_data(): bool {
		return $this->collect_count() > 0 || parent::has_data();
	}

	/** Rows in the foreign storage, cheap (no normalising). */
	private function collect_count(): int {
		global $wpdb;
		switch ( $this->slug ) {
			case 'redirection':
				return Importer::redirection_available() ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_items" ) : 0; // phpcs:ignore WordPress.DB
			case '301-redirects':
				return $this->table_exists( $wpdb->prefix . 'ts_redirects' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ts_redirects" ) : 0; // phpcs:ignore WordPress.DB
			case 'safe-redirect-manager':
				return count( $this->srm_posts() );
			default:
				return 0;
		}
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Every convertible rule of the foreign plugin, plus counts of what has no equivalent.
	 *
	 * @return array{items:array<int, array<string, mixed>>,skipped:array<string, int>,flags:array<string, int>}
	 */
	private function collect(): array {
		if ( null !== $this->collected ) {
			return $this->collected;
		}
		$out = array(
			'items'   => array(),
			'skipped' => array(),
			'flags'   => array(),
		);
		switch ( $this->slug ) {
			case 'redirection':
				$after = 0;
				do {
					$page = Importer::redirection_page( $after, 1000 );
					foreach ( $page['items'] as $item ) {
						$out['items'][] = $item;
						foreach ( array( 'flag_case' => 'case', 'flag_query' => 'query' ) as $key => $name ) {
							if ( ( 'flag_case' === $key && ! empty( $item[ $key ] ) ) || ( 'flag_query' === $key && 'exact' !== $item[ $key ] ) ) {
								$out['flags'][ $name ] = ( $out['flags'][ $name ] ?? 0 ) + 1;
							}
						}
					}
					foreach ( $page['skipped'] as $reason => $n ) {
						$out['skipped'][ $reason ] = ( $out['skipped'][ $reason ] ?? 0 ) + $n;
					}
					$after = $page['last'];
				} while ( ! $page['done'] );
				break;
			case 'safe-redirect-manager':
				$this->collect_srm( $out );
				break;
			case 'simple-301-redirects':
				$this->collect_simple( $out );
				break;
			case '301-redirects':
				$this->collect_ts( $out );
				break;
		}
		$out['items'] = $this->first_wins( $out['items'], $out['skipped'] );
		$this->collected = $out;
		return $out;
	}

	/**
	 * The module keeps ONE rule per address. When the old plugin had several exact rules for the same
	 * address, only the first one that applies could ever match, so only that one is migrated (an enabled
	 * rule wins over a disabled one); the others are counted as `duplicate`.
	 *
	 * @param array<int, array<string, mixed>> $items   Rules in the plugin's order.
	 * @param array<string, int>               $skipped Counts of left-out rules (extended).
	 * @return array<int, array<string, mixed>>
	 */
	private function first_wins( array $items, array &$skipped ): array {
		usort(
			$items,
			static fn( array $a, array $b ): int => array( (int) $a['position'], $a['key'] ) <=> array( (int) $b['position'], $b['key'] )
		);
		$chosen = array();
		foreach ( $items as $i => $item ) {
			if ( 'exact' !== $item['match_type'] ) {
				continue;
			}
			$k = $this->address_key( (string) $item['source'] );
			if ( ! isset( $chosen[ $k ] ) || ( ! $items[ $chosen[ $k ] ]['enabled'] && $item['enabled'] ) ) {
				$chosen[ $k ] = $i;
			}
		}
		$out = array();
		foreach ( $items as $i => $item ) {
			if ( 'exact' === $item['match_type'] && $chosen[ $this->address_key( (string) $item['source'] ) ] !== $i ) {
				$skipped['duplicate'] = ( $skipped['duplicate'] ?? 0 ) + 1;
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Approximation of the module's match key: path and query without host, decoded, lower case, no trailing slash.
	 *
	 * @param string $source Source as the plugin stored it.
	 */
	private function address_key( string $source ): string {
		$parts = parse_url( trim( $source ) );
		$path  = false === $parts ? $source : (string) ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$path  = strtolower( rawurldecode( str_replace( '+', ' ', $path ) ) );
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	/**
	 * Safe Redirect Manager rule posts (any status but trash).
	 *
	 * @return array<int, \WP_Post|object>
	 */
	private function srm_posts(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		return (array) get_posts(
			array(
				'post_type'        => 'redirect_rule',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Turn `/path/*` into a regex rule (`^/path/(.*)`) and `/to/*` into `/to/$1`.
	 *
	 * @param string $from  Source with a trailing `*`.
	 * @param string $to    Target.
	 * @return array{0:string,1:string} Regex and target.
	 */
	private function wildcard( string $from, string $to ): array {
		$base  = rtrim( $from, '*' );
		$regex = '^' . ( '/' === substr( $base, 0, 1 ) ? '' : '/' ) . preg_quote( $base, '@' ) . '(.*)';
		$base_to = rtrim( $to, '*' );
		return array( $regex, $base_to !== $to ? $base_to . '$1' : $to );
	}

	/**
	 * @param array<string, mixed> $out Collector.
	 */
	private function collect_srm( array &$out ): void {
		foreach ( $this->srm_posts() as $post ) {
			$id   = (int) $post->ID;
			$from = trim( (string) get_post_meta( $id, '_redirect_rule_from', true ) );
			$to   = trim( (string) get_post_meta( $id, '_redirect_rule_to', true ) );
			$code = (int) get_post_meta( $id, '_redirect_rule_status_code', true );
			$code = $code > 0 ? $code : 302; // The plugin's default.
			if ( '' === $from ) {
				$out['skipped']['empty'] = ( $out['skipped']['empty'] ?? 0 ) + 1;
				continue;
			}
			if ( in_array( $code, array( 403, 404 ), true ) ) {
				$out['skipped']['status:' . $code] = ( $out['skipped'][ 'status:' . $code ] ?? 0 ) + 1;
				continue;
			}
			$regex = (bool) get_post_meta( $id, '_redirect_rule_from_regex', true );
			$type  = 'exact';
			if ( ! $regex && '*' === substr( $from, -1 ) ) {
				list( $from, $to ) = $this->wildcard( $from, $to );
				$type              = 'regex';
			} elseif ( $regex ) {
				$type = 'regex';
			}
			if ( get_post_meta( $id, '_force_https', true ) ) {
				$out['flags']['https'] = ( $out['flags']['https'] ?? 0 ) + 1;
			}
			$out['items'][] = array(
				'key'        => 'srm:' . $id,
				'label'      => (string) get_post_meta( $id, '_redirect_rule_from', true ),
				'source'     => $from,
				'match_type' => $type,
				'target'     => 410 === $code ? '' : $to,
				'code'       => 303 === $code ? 302 : ( in_array( $code, Repository::CODES, true ) ? $code : 301 ),
				'enabled'    => 'publish' === $post->post_status,
				'note'       => (string) get_post_meta( $id, '_redirect_rule_notes', true ),
				'position'   => 0,
			);
		}
	}

	/**
	 * @param array<string, mixed> $out Collector.
	 */
	private function collect_simple( array &$out ): void {
		$rules    = $this->opt( '301_redirects' );
		$wildcard = 'true' === (string) $this->opt( '301_redirects_wildcard' );
		foreach ( is_array( $rules ) ? $rules : array() as $from => $to ) {
			$from = trim( (string) $from );
			$to   = trim( (string) $to );
			if ( '' === $from || '' === $to ) {
				$out['skipped']['empty'] = ( $out['skipped']['empty'] ?? 0 ) + 1;
				continue;
			}
			$type = 'exact';
			if ( false !== strpos( $from, '*' ) ) {
				if ( ! $wildcard ) {
					// With wildcards off the plugin compared the text literally, so the rule never matched.
					$out['skipped']['wildcard_off'] = ( $out['skipped']['wildcard_off'] ?? 0 ) + 1;
					continue;
				}
				$parts = array_map( static fn( string $p ): string => preg_quote( $p, '@' ), explode( '*', rtrim( $from, '/' ) ) );
				$from  = '^' . ( '/' === substr( $from, 0, 1 ) ? '' : '/' ) . implode( '(.*)', $parts );
				$to    = str_replace( '*', '$1', $to );
				$type  = 'regex';
			}
			$out['items'][] = array(
				'key'        => 'simple:' . md5( (string) $from . '>' . (string) $to ),
				'label'      => $from,
				'source'     => $from,
				'match_type' => $type,
				'target'     => $to,
				'code'       => 301,
				'enabled'    => true,
				'note'       => '',
				'position'   => 0,
			);
		}
	}

	/**
	 * @param array<string, mixed> $out Collector.
	 */
	private function collect_ts( array &$out ): void {
		global $wpdb;
		if ( ! $this->table_exists( $wpdb->prefix . 'ts_redirects' ) ) {
			return;
		}
		$rows = $wpdb->get_results( "SELECT id, title, new_link, old_link FROM {$wpdb->prefix}ts_redirects ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$from = trim( (string) $row['old_link'] );
			$to   = trim( (string) $row['new_link'] );
			if ( '' === $from || '' === $to ) {
				$out['skipped']['empty'] = ( $out['skipped']['empty'] ?? 0 ) + 1;
				continue;
			}
			$out['items'][] = array(
				'key'        => 'ts:' . (int) $row['id'],
				'label'      => $from,
				'source'     => $from,
				'match_type' => 'exact',
				'target'     => $to,
				'code'       => 301,
				'enabled'    => true,
				'note'       => (string) ( $row['title'] ?? '' ),
				'position'   => 0,
			);
		}
	}

	public function smoke_checks(): array {
		$paths = array();
		foreach ( $this->collect()['items'] as $item ) {
			if ( 'exact' !== ( $item['match_type'] ?? '' ) || empty( $item['enabled'] ) || 410 === (int) ( $item['code'] ?? 0 ) ) {
				continue;
			}
			$parsed = \UxStudio\Modules\Redirects\Url::parse_source( (string) $item['source'] );
			if ( '' === $parsed['path'] || '' !== $parsed['query'] || '/' === $parsed['path'] ) {
				continue;
			}
			$paths[ $parsed['path'] ] = true;
			if ( count( $paths ) >= 20 ) {
				break;
			}
		}
		if ( ! $paths ) {
			return array();
		}
		return array(
			array(
				'id'    => 'redirects',
				'label' => __( 'Sample of redirects behaves like the old plugin', 'ux-studio' ),
				'type'  => 'redirect_sample',
				'paths' => array_keys( $paths ),
			),
		);
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->collect();
		if ( ! $c['items'] && ! $c['skipped'] ) {
			$m->note( __( 'The plugin has no redirect rules stored, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}

		$labels = array(
			'match:server'     => __( 'rules that redirect a whole domain (server match)', 'ux-studio' ),
			'match:login'      => __( 'rules that depend on the visitor being logged in', 'ux-studio' ),
			'match:referrer'   => __( 'rules that depend on the referring page', 'ux-studio' ),
			'match:agent'      => __( 'rules that depend on the browser', 'ux-studio' ),
			'match:cookie'     => __( 'rules that depend on a cookie', 'ux-studio' ),
			'match:role'       => __( 'rules that depend on the user role', 'ux-studio' ),
			'match:ip'         => __( 'rules that depend on the IP address', 'ux-studio' ),
			'match:language'   => __( 'rules that depend on the language', 'ux-studio' ),
			'match:header'     => __( 'rules that depend on an HTTP header', 'ux-studio' ),
			'match:custom'     => __( 'rules with a custom filter', 'ux-studio' ),
			'match:page'       => __( 'rules that depend on the page type', 'ux-studio' ),
			'match:http'       => __( 'rules that depend on the HTTP request', 'ux-studio' ),
			'action:random'    => __( 'rules that pick a random target', 'ux-studio' ),
			'action:pass'      => __( 'pass-through rules', 'ux-studio' ),
			'action:error'     => __( 'rules that answer with an error other than 410', 'ux-studio' ),
			'action:nothing'   => __( 'rules that do nothing', 'ux-studio' ),
			'action:url'       => __( 'rules without a target', 'ux-studio' ),
			'status:403'       => __( 'rules answering 403 Forbidden', 'ux-studio' ),
			'status:404'       => __( 'rules answering 404 Not Found', 'ux-studio' ),
			'wildcard_off'     => __( 'rules with * that never matched, because wildcards were switched off in the plugin', 'ux-studio' ),
			'empty'            => __( 'rules with an empty source or target', 'ux-studio' ),
			'duplicate'        => __( 'rules with the same address as an earlier rule (only the first one could ever match, the module keeps one rule per address)', 'ux-studio' ),
		);
		foreach ( $c['skipped'] as $reason => $n ) {
			$m->lossy(
				sprintf(
					/* translators: 1: number of rules, 2: kind of rules. */
					__( '%1$d %2$s have no equivalent in the module and are not transferred.', 'ux-studio' ),
					$n,
					$labels[ $reason ] ?? $reason
				)
			);
		}
		if ( ! empty( $c['flags']['case'] ) ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of rules. */
					__( '%d rules were case sensitive. The module ignores letter case in addresses.', 'ux-studio' ),
					$c['flags']['case']
				)
			);
		}
		if ( ! empty( $c['flags']['query'] ) ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of rules. */
					__( '%d rules had their own handling of the query string (ignore or pass on). The module uses its own setting "pass query string".', 'ux-studio' ),
					$c['flags']['query']
				)
			);
		}
		if ( ! empty( $c['flags']['https'] ) ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of rules. */
					__( '%d rules forced https. The module redirects to the address as written.', 'ux-studio' ),
					$c['flags']['https']
				)
			);
		}
		if ( 'redirection' === $this->slug ) {
			$m->lossy( __( 'Hit counters, the redirect log and the 404 log of the plugin are not transferred (the module starts counting from zero).', 'ux-studio' ) );
		}
		if ( 'safe-redirect-manager' === $this->slug ) {
			$m->note( __( 'Rules ending with * become regular-expression rules (the part after the prefix is passed to the target as $1).', 'ux-studio' ) );
		}
		if ( $this->module_enabled( self::MODULE ) ) {
			$m->warn( __( 'The module "Redirects" is already on, so the rules are created switched off. They are switched on at the switch, after the safe preview, so two plugins never redirect the same address.', 'ux-studio' ) );
		}
		return $m;
	}

	protected function data_kind(): string {
		return 'redirect';
	}

	protected function data_label(): string {
		return __( 'Redirects', 'ux-studio' );
	}

	protected function data_total( array $options ): int {
		return count( $this->collect()['items'] );
	}

	protected function data_plan( array $options ): array {
		$items    = $this->collect()['items'];
		$inactive = 0;
		$regex    = 0;
		$gone     = 0;
		foreach ( $items as $item ) {
			$inactive += $item['enabled'] ? 0 : 1;
			$regex    += 'regex' === $item['match_type'] ? 1 : 0;
			$gone     += 410 === (int) $item['code'] ? 1 : 0;
		}
		return array(
			array(
				'key'   => 'redirect',
				'label' => __( 'Redirects', 'ux-studio' ),
				'count' => count( $items ),
			),
			array(
				'key'   => 'inactive',
				'label' => __( 'of them switched off in the plugin', 'ux-studio' ),
				'count' => $inactive,
			),
			array(
				'key'   => 'regex',
				'label' => __( 'of them regular expressions', 'ux-studio' ),
				'count' => $regex,
			),
			array(
				'key'   => 'gone',
				'label' => __( 'of them 410 Gone', 'ux-studio' ),
				'count' => $gone,
			),
		);
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

	public function target_tables(): array {
		global $wpdb;
		$strip = static fn( string $table ): string => substr( $table, strlen( $wpdb->prefix ) );
		return array( $strip( Repository::table() ), $strip( NotFoundLog::table() ) );
	}

	protected function extra_target_options(): array {
		return array( 'uxstudio_redirects_state' );
	}

	protected function prepare_data(): void {
		RedirectsModule::ensure_tables();
	}

	/**
	 * The input Repository::save() takes for an item.
	 *
	 * @param array<string, mixed> $item    Normalized rule.
	 * @param bool                 $enabled Whether the rule starts switched on.
	 * @return array<string, mixed>
	 */
	private function rule_input( array $item, bool $enabled ): array {
		return array(
			'source'     => $item['source'],
			'match_type' => $item['match_type'],
			'target'     => $item['target'],
			'code'       => $item['code'],
			'enabled'    => $enabled,
			'note'       => $item['note'],
			'position'   => $item['position'],
			'origin'     => 'import',
		);
	}

	/**
	 * Does UX Studio already have exactly this rule (regardless of its on / off state)?
	 *
	 * @param array<string, mixed> $item Normalized rule.
	 * @return array{status:string,message:string}
	 */
	private function compare( array $item ): array {
		$last = array(
			'status'  => 'error',
			'message' => '',
		);
		foreach ( array( true, false ) as $enabled ) {
			$res = Repository::save( $this->rule_input( $item, $enabled ), 'update', true );
			if ( 'unchanged' === $res['status'] ) {
				return array(
					'status'  => 'unchanged',
					'message' => '',
				);
			}
			$last = array(
				'status'  => $res['status'],
				'message' => (string) ( $res['errors'][0]['message'] ?? '' ),
			);
			if ( 'error' === $res['status'] || 'would_create' === $res['status'] ) {
				return $last;
			}
		}
		return $last;
	}

	protected function preview_item( array $item, array $ctx ): array {
		if ( ! $this->table_exists( Repository::table() ) ) {
			return $this->validate_without_table( $item );
		}
		$res = $this->compare( $item );
		switch ( $res['status'] ) {
			case 'unchanged':
				return array( 'status' => 'unchanged' );
			case 'would_create':
				return array( 'status' => 'created' );
			case 'would_update':
				return array(
					'status'  => 'skipped',
					'message' => __( 'A different rule for this address already exists in UX Studio, it is kept.', 'ux-studio' ),
				);
			default:
				return array(
					'status'  => 'skipped',
					'message' => $res['message'],
				);
		}
	}

	/**
	 * Dry run before the rules table exists: only the checks that need no table.
	 *
	 * @param array<string, mixed> $item Normalized rule.
	 * @return array{status:string,message?:string}
	 */
	private function validate_without_table( array $item ): array {
		if ( 'regex' === $item['match_type'] && false === @preg_match( Repository::delimit( (string) $item['source'] ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'status'  => 'skipped',
				'message' => __( 'Source is not a valid regular expression.', 'ux-studio' ),
			);
		}
		if ( 410 !== (int) $item['code'] && '' === trim( (string) $item['target'] ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'Target (URL or target_post_id) is required unless code is 410.', 'ux-studio' ),
			);
		}
		return array( 'status' => 'created' );
	}

	protected function apply_item( array $item, array $ctx ): array {
		$cmp = $this->compare( $item );
		if ( 'unchanged' === $cmp['status'] ) {
			return array( 'status' => 'unchanged' );
		}
		if ( 'would_update' === $cmp['status'] ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'A different rule for this address already exists in UX Studio, it is kept.', 'ux-studio' ),
			);
		}
		if ( 'would_create' !== $cmp['status'] ) {
			return array(
				'status'  => 'skipped',
				'message' => $cmp['message'],
			);
		}

		// Module on: the rule is created OFF and switched on at the switch (if it was on in the plugin).
		$staged = Staging::module_enabled( self::MODULE );
		$res    = Repository::save( $this->rule_input( $item, ! $staged && $item['enabled'] ), 'skip', false );
		if ( ! in_array( $res['status'], array( 'created', 'updated' ), true ) || empty( $res['id'] ) ) {
			return array(
				'status'  => 'skipped',
				'message' => (string) ( $res['errors'][0]['message'] ?? __( 'The rule could not be saved.', 'ux-studio' ) ),
			);
		}
		$out = array(
			'status'   => 'created',
			'map_type' => 'redirect',
			'map_id'   => (string) $res['id'],
		);
		if ( $staged && $item['enabled'] ) {
			$out['activate'] = (string) $res['id'];
		}
		return $out;
	}

	protected function verify_item( array $item ): array {
		if ( ! $this->table_exists( Repository::table() ) ) {
			return array(
				'ok'     => false,
				'detail' => __( 'The rules table does not exist yet.', 'ux-studio' ),
			);
		}
		$cmp = $this->compare( $item );
		if ( 'unchanged' === $cmp['status'] ) {
			return array(
				'ok'     => true,
				'detail' => '',
			);
		}
		if ( 'error' === $cmp['status'] && '' !== $cmp['message'] ) {
			// A rule the module refuses (loop, reserved path) was skipped on purpose, say why.
			return array(
				'ok'     => false,
				'detail' => $cmp['message'],
			);
		}
		return array(
			'ok'     => false,
			'detail' => 'would_update' === $cmp['status']
				? __( 'UX Studio has a different rule for this address.', 'ux-studio' )
				: __( 'The rule is not in UX Studio.', 'ux-studio' ),
		);
	}

	public function activate( string $kind, array $ids, array $data = array() ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( 'redirect' !== $kind || ! $ids ) {
			return 0;
		}
		$table = Repository::table();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET enabled = 1, updated_at = %s WHERE id IN ({$in}) AND enabled = 0", current_time( 'mysql' ), ...$ids ) );
		Repository::refresh_state();
		// Rules that were already on count as switched on too.
		return max( $count, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id IN ({$in}) AND enabled = 1", ...$ids ) ) ); // phpcs:ignore WordPress.DB
	}

	public function deactivate( string $kind, array $ids, array $data = array() ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( 'redirect' !== $kind || ! $ids ) {
			return 0;
		}
		$table = Repository::table();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET enabled = 0, updated_at = %s WHERE id IN ({$in}) AND enabled = 1", current_time( 'mysql' ), ...$ids ) );
		Repository::refresh_state();
		return $count;
	}
}
