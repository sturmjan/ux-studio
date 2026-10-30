<?php
/**
 * Source: Code Snippets, WPCode (Insert Headers and Footers), Header Footer Code Manager.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Activatable;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\CodeSnippets\Module as SnippetsModule;
use UxStudio\Modules\CodeSnippets\PhpValidator;
use UxStudio\Modules\CodeSnippets\Snippet;
use UxStudio\Modules\CodeSnippets\SnippetConditions;
use UxStudio\Modules\CodeSnippets\SnippetManager;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `code-snippets`: code files with an integrity hash in wp-content/uxstudio-snippets,
 * metadata in `uxstudio_code_snippets`. Everything goes through SnippetManager::createSnippet() (the
 * module's own validation and storage), never by writing files directly.
 *
 * BEHAVIOUR-CHANGING, the strictest rule of the migration: a snippet is CODE. Every snippet is
 * created SWITCHED OFF, whether the module is on or off. Those that were active in the old plugin
 * (and whose targeting rules survive) are listed in the staging area and switched on at the switch,
 * after the safe preview (Activatable::activate). Snippets that lost rules on the way (WPCode
 * conditional logic, HFCM page targeting ...) stay off for the admin to review.
 *
 *  - Code Snippets: table `{prefix}snippets` (name, code, scope, active, priority, tags). Scope `content` becomes a
 *    shortcode snippet, `single-use` a run-once snippet (created switched off and NOT switched on at the switch: it runs
 *    code once, so the admin decides when).
 *  - WPCode: posts of type `wpcode` (code = content, type = taxonomy `wpcode_type`, place = taxonomy
 *    `wpcode_location`) and the legacy header / footer / body boxes (options `ihaf_insert_*`). Manual snippets (used
 *    through `[wpcode id=..]`) become shortcode snippets, "before / after post / content" a placement around the content.
 *  - Header Footer Code Manager: table `{prefix}hfcm_scripts` (code with its own tags, placed in the header or footer,
 *    before / after the content, or by shortcode).
 */
final class CodeSnippetsSource extends AbstractSource implements Activatable {

	private ?SnippetManager $manager = null;

	/** @var array{items:array<int, array<string, mixed>>,skipped:array<string, int>,review:int,manual:int}|null */
	private ?array $collected = null;

	/** Run locations that need a plan note of their own. */
	private const PLACEMENTS = array( SnippetManager::LOCATION_BEFORE_CONTENT, SnippetManager::LOCATION_AFTER_CONTENT );

	protected function option_names(): array {
		return 'insert-headers-and-footers' === $this->slug ? array( 'ihaf_insert_header', 'ihaf_insert_footer', 'ihaf_insert_body' ) : array();
	}

	protected function has_data(): bool {
		return count( $this->collect()['items'] ) > 0 || $this->collect()['skipped'] || parent::has_data();
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
	 * Every convertible snippet, plus counts of what has no equivalent.
	 *
	 * @return array{items:array<int, array<string, mixed>>,skipped:array<string, int>,review:int,manual:int}
	 */
	private function collect(): array {
		if ( null !== $this->collected ) {
			return $this->collected;
		}
		$out = array(
			'items'   => array(),
			'skipped' => array(),
			'review'  => 0,
			'manual'  => 0,
			'conditioned' => 0,
		);
		switch ( $this->slug ) {
			case 'code-snippets':
				$this->collect_code_snippets( $out );
				break;
			case 'insert-headers-and-footers':
				$this->collect_wpcode( $out );
				break;
			case 'header-footer-code-manager':
				$this->collect_hfcm( $out );
				break;
		}
		// The module's own normaliser has the last word on every condition (ids as numbers, one canonical form); a set it
		// refuses means the snippet cannot be trusted to run only where it did before, so it goes to review without them.
		foreach ( $out['items'] as $i => $item ) {
			if ( ! empty( $item['conditions'] ) ) {
				$normalized = SnippetConditions::normalize( $item['conditions'] );
				if ( $normalized['errors'] ) {
					$out['items'][ $i ]['conditions'] = array();
					$out['items'][ $i ]['review']     = true;
				} else {
					$out['items'][ $i ]['conditions'] = $normalized['conditions'];
				}
			}
		}
		foreach ( $out['items'] as $item ) {
			$out['review'] += $item['review'] ? 1 : 0;
			$out['manual'] += ! empty( $item['keep_off'] ) ? 1 : 0;
			$out['conditioned'] += empty( $item['conditions'] ) ? 0 : 1;
		}
		$this->collected = $out;
		return $out;
	}

	/**
	 * @param array<string, mixed> $out Collector.
	 * @param string               $reason Why a snippet was left out.
	 */
	private function skip( array &$out, string $reason ): void {
		$out['skipped'][ $reason ] = ( $out['skipped'][ $reason ] ?? 0 ) + 1;
	}

	/**
	 * Code Snippets scopes → type and place of the module.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	private const CS_SCOPES = array(
		'global'         => array( 'php', 'everywhere' ),
		'admin'          => array( 'php', 'admin_only' ),
		'front-end'      => array( 'php', 'frontend_only' ),
		'head-content'   => array( 'html', 'site_header' ),
		'body-content'   => array( 'html', 'site_body_open' ),
		'footer-content' => array( 'html', 'site_footer' ),
		'content'        => array( 'html', SnippetManager::LOCATION_SHORTCODE ),
		'single-use'     => array( 'php', SnippetManager::LOCATION_RUN_ONCE ),
		'admin-css'      => array( 'css', 'admin_header' ),
		'site-css'       => array( 'css', 'site_header' ),
		'site-head-js'   => array( 'js', 'site_header' ),
		'site-footer-js' => array( 'js', 'site_footer' ),
	);

	/**
	 * @param array<string, mixed> $out Collector.
	 */
	private function collect_code_snippets( array &$out ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'snippets';
		if ( ! $this->table_exists( $table ) ) {
			return;
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$scope = (string) ( $row['scope'] ?? 'global' );
			if ( ! isset( self::CS_SCOPES[ $scope ] ) ) {
				$this->skip( $out, 'scope:' . $scope );
				continue;
			}
			list( $type, $place ) = self::CS_SCOPES[ $scope ];
			$code                 = (string) $row['code'];
			if ( 'content' === $scope ) {
				// A content snippet is HTML that may carry PHP tags. Without PHP it is a plain HTML shortcode snippet;
				// with PHP it keeps working as a PHP snippet that starts in HTML mode.
				list( $type, $code ) = $this->content_snippet( $code );
			} elseif ( 'php' === $type ) {
				// A leading open tag was never part of the stored code, but be safe with exports.
				$code = (string) preg_replace( '/^\s*<\?php\s*/i', '', $code );
			}
			$tags = trim( (string) ( $row['tags'] ?? '' ) );
			$desc = trim( (string) ( $row['description'] ?? '' ) );
			if ( '' !== $tags ) {
				$desc = trim( $desc . "\n" . sprintf(
					/* translators: %s: comma separated tags. */
					__( 'Tags: %s', 'ux-studio' ),
					$tags
				) );
			}
			$out['items'][] = array(
				'key'          => 'cs:' . (int) $row['id'],
				'label'        => trim( (string) $row['name'] ),
				'name'         => trim( (string) $row['name'] ),
				'type'         => $type,
				'code'         => $code,
				'description'  => $desc,
				'run_location' => $place,
				'priority'     => (int) ( $row['priority'] ?? 10 ),
				'enabled'      => ! empty( $row['active'] ),
				'review'       => false,
				// A run-once snippet executes code when it is switched on, so only the admin does that.
				'keep_off'     => SnippetManager::LOCATION_RUN_ONCE === $place,
				'post_types'   => array(),
				'conditions'   => array(),
			);
		}
	}

	/**
	 * Type and code of a Code Snippets "content" snippet.
	 *
	 * @param string $code Stored code.
	 * @return array{0:string,1:string}
	 */
	private function content_snippet( string $code ): array {
		if ( 1 !== preg_match( '/<\?(?:php|=)|\?>/i', $code ) ) {
			return array( 'html', $code );
		}
		$code = ltrim( $code );
		if ( 1 === preg_match( '/^<\?php\s*/i', $code ) ) {
			return array( 'php', (string) preg_replace( '/^<\?php\s*/i', '', $code ) );
		}
		// HTML first, PHP inside: the snippet file starts with a close tag so the HTML is printed as it is.
		return array( 'php', "?>\n" . $code );
	}

	/**
	 * @param array<string, mixed> $out Collector.
	 */
	private function collect_wpcode( array &$out ): void {
		foreach ( array(
			'ihaf_insert_header' => array( 'header', 'site_header' ),
			'ihaf_insert_footer' => array( 'footer', 'site_footer' ),
			'ihaf_insert_body'   => array( 'body', 'site_body_open' ),
		) as $option => $def ) {
			$code = trim( (string) $this->opt( $option ) );
			if ( '' === $code ) {
				continue;
			}
			$out['items'][] = array(
				'key'          => 'ihaf:' . $def[0],
				'label'        => $this->legacy_name( $def[0] ),
				'name'         => $this->legacy_name( $def[0] ),
				'type'         => 'html',
				'code'         => $code,
				'description'  => '',
				'run_location' => $def[1],
				'priority'     => 10,
				'enabled'      => true,
				'review'       => false,
				'keep_off'     => false,
				'post_types'   => array(),
				'conditions'   => array(),
			);
		}
		if ( ! function_exists( 'get_posts' ) ) {
			return;
		}
		$posts = (array) get_posts(
			array(
				'post_type'        => 'wpcode',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			$id    = (int) $post->ID;
			$type  = $this->first_term( $id, 'wpcode_type' );
			$where = $this->first_term( $id, 'wpcode_location' );
			if ( ! in_array( $type, array( 'php', 'html', 'js', 'css' ), true ) ) {
				$this->skip( $out, 'type:' . ( '' === $type ? 'unknown' : $type ) );
				continue;
			}
			$auto = (int) get_post_meta( $id, '_wpcode_auto_insert', true );
			// Not inserted automatically: it appears where `[wpcode id=..]` is put, which is a shortcode snippet here.
			$place  = $auto ? $this->wpcode_place( $type, $where ) : SnippetManager::LOCATION_SHORTCODE;
			$review = false;
			if ( null === $place ) {
				$this->skip( $out, 'place:' . ( '' === $where ? 'none' : $where ) );
				continue;
			}
			if ( 'frontend_cl' === $where ) {
				$review = true;
			}
			// Conditional logic and the device type become run conditions where the module can express them exactly; a
			// snippet that uses anything else (taxonomy terms, page URLs, referrers, several OR groups ...) stays off for review.
			$conditions = array();
			if ( get_post_meta( $id, '_wpcode_conditional_logic_enabled', true ) ) {
				$mapped = $this->wpcode_conditions( get_post_meta( $id, '_wpcode_conditional_logic', true ) );
				if ( null === $mapped ) {
					$review = true;
				} else {
					$conditions = $mapped;
				}
			}
			$device = (string) get_post_meta( $id, '_wpcode_device_type', true );
			if ( in_array( $device, array( 'desktop', 'mobile' ), true ) ) {
				$conditions[] = array(
					'type'  => 'device',
					'value' => $device,
				);
			} elseif ( '' !== $device && 'any' !== $device ) {
				$review = true;
			}
			if ( count( $conditions ) > SnippetConditions::MAX ) {
				$review = true;
			}
			// The schedule is a Pro feature of WPCode and its stored format cannot be checked here: review.
			if ( get_post_meta( $id, '_wpcode_schedule', true ) ) {
				$review = true;
			}
			if ( $review ) {
				$conditions = array();
			}
			$priority = get_post_meta( $id, '_wpcode_priority', true );
			$out['items'][] = array(
				'key'          => 'wpcode:' . $id,
				'label'        => trim( (string) $post->post_title ),
				'name'         => trim( (string) $post->post_title ),
				'type'         => $type,
				'code'         => (string) $post->post_content,
				'description'  => (string) get_post_meta( $id, '_wpcode_note', true ),
				'run_location' => $place,
				'priority'     => '' === (string) $priority ? 10 : (int) $priority,
				'enabled'      => 'publish' === $post->post_status,
				'review'       => $review,
				'keep_off'     => false,
				'post_types'   => in_array( $place, self::PLACEMENTS, true ) ? SnippetManager::DEFAULT_POST_TYPES : array(),
				'conditions'   => $conditions,
			);
		}
	}

	/**
	 * WPCode conditional logic as run conditions, or null when the module cannot express it exactly.
	 *
	 * WPCode: `{ show: show|hide, groups: [ [ row, row ... ], ... ] }`, the rows of a group are ANDed, the groups ORed; a
	 * row is `{ type: page|user, option, relation, value }`. Only one group (AND) is mapped; "hide" is mapped only for a
	 * single row (it negates it). Rows: type of page (= / !=), post type (= / !=), logged in (true / false), user role (= / !=).
	 *
	 * @param mixed $rules Value of the meta `_wpcode_conditional_logic`.
	 * @return array<int, array<string, mixed>>|null
	 */
	public function wpcode_conditions( $rules ): ?array {
		if ( ! is_array( $rules ) || ! isset( $rules['show'] ) || ! in_array( $rules['show'], array( 'show', 'hide' ), true ) ) {
			return null;
		}
		$groups = array_values( (array) ( $rules['groups'] ?? array() ) );
		if ( 1 !== count( $groups ) || ! is_array( $groups[0] ) || ! $groups[0] ) {
			return null;
		}
		$kinds = array(
			'is_front_page' => 'front_page',
			'is_home'       => 'blog_home',
			'is_single'     => 'singular',
			'is_author'     => 'author',
			'is_archive'    => 'archive',
			'is_search'     => 'search',
			'is_404'        => '404',
		);
		$out = array();
		foreach ( array_values( $groups[0] ) as $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
			$type     = (string) ( $row['type'] ?? '' );
			$option   = (string) ( $row['option'] ?? '' );
			$relation = (string) ( $row['relation'] ?? '' );
			$value    = $row['value'] ?? '';
			if ( ! in_array( $relation, array( '=', '!=' ), true ) ) {
				return null;
			}
			$negate = '!=' === $relation;
			if ( 'page' === $type && 'type_of_page' === $option && is_string( $value ) && isset( $kinds[ $value ] ) ) {
				$out[] = array(
					'type'   => 'page',
					'value'  => array( $kinds[ $value ] ),
					'negate' => $negate,
				);
			} elseif ( 'page' === $type && 'post_type' === $option && is_string( $value ) && '' !== $value ) {
				// WPCode asks get_post_type() before a loop, which names the type of a single post only.
				$out[] = array(
					'type'   => 'post_type',
					'value'  => array( $value ),
					'scope'  => 'single',
					'negate' => $negate,
				);
			} elseif ( 'user' === $type && 'logged_in' === $option ) {
				$text = strtolower( is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value );
				if ( ! in_array( $text, array( '1', 'true', 'yes', 'on', '', '0', 'false', 'no', 'off' ), true ) ) {
					return null;
				}
				$on    = in_array( $text, array( '1', 'true', 'yes', 'on' ), true );
				$out[] = array(
					'type'  => 'login',
					'value' => ( $on xor $negate ) ? 'in' : 'out',
				);
			} elseif ( 'user' === $type && 'user_role' === $option && ( is_string( $value ) || is_array( $value ) ) && array() !== (array) $value && '' !== $value ) {
				$out[] = array(
					'type'   => 'role',
					'value'  => array_values( (array) $value ),
					'negate' => $negate,
				);
			} else {
				return null;
			}
		}
		if ( 'hide' === $rules['show'] ) {
			// NOT (a AND b) cannot be written as a list of ANDed conditions; NOT (a) can.
			if ( 1 !== count( $out ) ) {
				return null;
			}
			$out[0] = $this->invert( $out[0] );
		}
		$normalized = SnippetConditions::normalize( $out );
		return $normalized['errors'] ? null : $normalized['conditions'];
	}

	/**
	 * A condition turned around.
	 *
	 * @param array<string, mixed> $condition Condition.
	 * @return array<string, mixed>
	 */
	private function invert( array $condition ): array {
		if ( 'login' === $condition['type'] ) {
			$condition['value'] = 'in' === $condition['value'] ? 'out' : 'in';
		} else {
			$condition['negate'] = empty( $condition['negate'] );
		}
		return $condition;
	}

	/**
	 * Name of an imported legacy box.
	 *
	 * @param string $which header|footer|body.
	 */
	private function legacy_name( string $which ): string {
		$names = array(
			'header' => __( 'Header scripts (from WPCode)', 'ux-studio' ),
			'footer' => __( 'Footer scripts (from WPCode)', 'ux-studio' ),
			'body'   => __( 'Body scripts (from WPCode)', 'ux-studio' ),
		);
		return $names[ $which ];
	}

	/**
	 * Slug of the first term of a taxonomy for a post.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy.
	 */
	private function first_term( int $post_id, string $taxonomy ): string {
		global $wpdb;
		// SQL on purpose: with WPCode deactivated its taxonomies are not registered and the term API refuses them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slug = $wpdb->get_var( $wpdb->prepare( "SELECT t.slug FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY t.term_id ASC LIMIT 1", $post_id, $taxonomy ) );
		return null === $slug ? '' : (string) $slug;
	}

	/**
	 * WPCode place → module run location, or null when the module has no such place.
	 *
	 * @param string $type  Module snippet type.
	 * @param string $where WPCode location slug.
	 */
	private function wpcode_place( string $type, string $where ): ?string {
		if ( 'php' === $type ) {
			$map = array(
				'everywhere'    => 'everywhere',
				'frontend_only' => 'frontend_only',
				'admin_only'    => 'admin_only',
				'frontend_cl'   => 'frontend_only',
			);
			return $map[ $where ] ?? null;
		}
		$map = array(
			'site_wide_header' => 'site_header',
			'site_wide_body'   => 'site_body_open',
			'site_wide_footer' => 'site_footer',
			// "Before / after post" is approximated by the same place as "before / after content": around the post
			// content of the page (WPCode puts "post" next to the title / loop item, the module only has the content).
			'before_content'   => SnippetManager::LOCATION_BEFORE_CONTENT,
			'before_post'      => SnippetManager::LOCATION_BEFORE_CONTENT,
			'after_content'    => SnippetManager::LOCATION_AFTER_CONTENT,
			'after_post'       => SnippetManager::LOCATION_AFTER_CONTENT,
		);
		return $map[ $where ] ?? null;
	}

	/**
	 * @param array<string, mixed> $out Collector.
	 */
	private function collect_hfcm( array &$out ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'hfcm_scripts';
		if ( ! $this->table_exists( $table ) ) {
			return;
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY script_id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$place = array(
				'header'         => 'site_header',
				'footer'         => 'site_footer',
				'before_content' => SnippetManager::LOCATION_BEFORE_CONTENT,
				'after_content'  => SnippetManager::LOCATION_AFTER_CONTENT,
			)[ (string) $row['location'] ] ?? null;
			if ( null === $place ) {
				$this->skip( $out, 'place:' . (string) $row['location'] );
				continue;
			}
			// `[hfcm id=..]` snippets are used by hand: a shortcode snippet here.
			$manual = 'manual' === (string) $row['display_on'];
			if ( $manual ) {
				$place = SnippetManager::LOCATION_SHORTCODE;
			}
			// Targeting becomes run conditions where the module can express it exactly (site wide with exclusions, specific
			// posts and pages, post types, home, archives, search, device); categories, tags and "latest posts" stay for review.
			$conditions = $manual ? array() : $this->hfcm_conditions( $row );
			$device     = (string) ( $row['device_type'] ?? 'both' );
			if ( in_array( $device, array( 'desktop', 'mobile' ), true ) && is_array( $conditions ) ) {
				$conditions[] = array(
					'type'  => 'device',
					'value' => $device,
				);
			}
			// The plugin prints the stored text as it is (its JS and CSS snippets carry their own tags).
			$review     = null === $conditions || ! in_array( $device, array( 'both', 'desktop', 'mobile' ), true );
			$conditions = is_array( $conditions ) ? $conditions : array();
			$out['items'][] = array(
				'key'          => 'hfcm:' . (int) $row['script_id'],
				'label'        => trim( (string) $row['name'] ),
				'name'         => trim( (string) $row['name'] ),
				'type'         => 'html',
				'code'         => html_entity_decode( (string) $row['snippet'] ),
				'description'  => '',
				'run_location' => $place,
				'priority'     => 10,
				'enabled'      => 'active' === (string) $row['status'],
				'review'       => $review,
				'keep_off'     => false,
				'post_types'   => in_array( $place, self::PLACEMENTS, true ) ? SnippetManager::DEFAULT_POST_TYPES : array(),
				'conditions'   => $review ? array() : $conditions,
			);
		}
	}

	/**
	 * Header Footer Code Manager targeting (`display_on` and the lists next to it) as run conditions, or null when the
	 * module cannot express it exactly. The device is added by the caller.
	 *
	 * @param array<string, mixed> $row Row of `hfcm_scripts`.
	 * @return array<int, array<string, mixed>>|null
	 */
	public function hfcm_conditions( array $row ): ?array {
		$list = static function ( $json ): array {
			$data = json_decode( (string) $json, true );
			return is_array( $data ) ? array_values( array_filter( array_map( 'strval', $data ), static fn( string $v ): bool => '' !== $v ) ) : array();
		};
		switch ( (string) ( $row['display_on'] ?? '' ) ) {
			case 'All':
				$excluded = array_merge( $list( $row['ex_pages'] ?? '' ), $list( $row['ex_posts'] ?? '' ) );
				return $excluded ? array(
					array(
						'type'   => 'post',
						'value'  => $excluded,
						'negate' => true,
					),
				) : array();
			case 's_pages':
			case 's_posts':
				$ids = $list( $row[ (string) $row['display_on'] ] ?? '' );
				return $ids ? array(
					array(
						'type'  => 'post',
						'value' => $ids,
					),
				) : null;
			case 's_custom_posts':
				$types = $list( $row['s_custom_posts'] ?? '' );
				$scope = array(
					'both'     => 'both',
					'posts'    => 'single',
					'archives' => 'archive',
				)[ (string) ( $row['spt_display_on'] ?? 'both' ) ] ?? null;
				return $types && null !== $scope ? array(
					array(
						'type'  => 'post_type',
						'value' => $types,
						'scope' => $scope,
					),
				) : null;
			case 's_is_home':
				return array(
					array(
						'type'  => 'page',
						'value' => array( 'front_page', 'blog_home' ),
					),
				);
			case 's_is_archive':
				return array(
					array(
						'type'  => 'page',
						'value' => array( 'archive', 'author' ),
					),
				);
			case 's_is_search':
				return array(
					array(
						'type'  => 'page',
						'value' => array( 'search' ),
					),
				);
			default: // s_categories, s_tags, latest_posts.
				return null;
		}
	}

	public function smoke_checks(): array {
		if ( $this->data_total( $this->default_options() ) < 1 ) {
			return array();
		}
		return array(
			array(
				'id'    => 'snippets',
				'label' => __( 'Pages render with the snippets turned on', 'ux-studio' ),
				'type'  => 'pages_clean',
			),
		);
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->collect();
		if ( ! $c['items'] && ! $c['skipped'] ) {
			$m->note( __( 'The plugin has no snippets stored, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$labels = array(
			'scope:condition'        => __( 'condition snippets', 'ux-studio' ),
			'type:text'              => __( 'plain text snippets', 'ux-studio' ),
			'type:universal'         => __( 'universal (PHP + HTML) snippets', 'ux-studio' ),
			'type:blocks'            => __( 'block snippets', 'ux-studio' ),
			'type:scss'              => __( 'SCSS snippets', 'ux-studio' ),
			'type:unknown'           => __( 'snippets of an unknown type', 'ux-studio' ),
);
		foreach ( $c['skipped'] as $reason => $n ) {
			$m->lossy(
				sprintf(
					/* translators: 1: number of snippets, 2: kind of snippets. */
					__( '%1$d %2$s are not transferred, the module has no equivalent.', 'ux-studio' ),
					$n,
					$labels[ $reason ] ?? ( 0 === strpos( $reason, 'place:' ) ? sprintf(
						/* translators: %s: place name used by the plugin. */
						__( 'snippets inserted at "%s"', 'ux-studio' ),
						substr( $reason, 6 )
					) : $reason )
				)
			);
		}
		if ( $c['review'] > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of snippets. */
					_n(
						'%d snippet used a schedule or targeting that the run conditions of the module cannot express (for example several OR groups, taxonomy terms, page URLs, a referrer, categories or tags, latest posts). It is created turned off so you can review it before it runs everywhere.',
						'%d snippets used a schedule or targeting that the run conditions of the module cannot express (for example several OR groups, taxonomy terms, page URLs, a referrer, categories or tags, latest posts). They are created turned off so you can review them before they run everywhere.',
						$c['review'],
						'ux-studio'
					),
					$c['review']
				)
			);
		}
		if ( $c['conditioned'] > 0 ) {
			$m->note(
				sprintf(
					/* translators: %d: number of snippets. */
					_n(
						'%d snippet used page, user, role or device targeting. It is transferred as run conditions (page type, post type, specific posts, logged in or out, role, device), so it runs only where it did before.',
						'%d snippets used page, user, role or device targeting. They are transferred as run conditions (page type, post type, specific posts, logged in or out, role, device), so they run only where they did before.',
						$c['conditioned'],
						'ux-studio'
					),
					$c['conditioned']
				)
			);
		}
		$m->note( __( 'Every snippet is created turned off, because it is code. Snippets that were on in the old plugin are turned on at the switch, after the safe preview.', 'ux-studio' ) );
		$shortcodes = 0;
		$placements = 0;
		foreach ( $c['items'] as $item ) {
			$shortcodes += SnippetManager::LOCATION_SHORTCODE === $item['run_location'] ? 1 : 0;
			$placements += in_array( $item['run_location'], self::PLACEMENTS, true ) ? 1 : 0;
		}
		if ( $shortcodes > 0 ) {
			$m->note(
				sprintf(
					/* translators: %d: number of snippets. */
					_n(
						'%d snippet is used through a shortcode. In UX Studio it is inserted as [uxstudio_snippet id="..."] (the exact shortcode is shown in the snippet list). Shortcodes already written in posts and pages are NOT rewritten, replace them by hand.',
						'%d snippets are used through a shortcode. In UX Studio they are inserted as [uxstudio_snippet id="..."] (the exact shortcode is shown in the snippet list). Shortcodes already written in posts and pages are NOT rewritten, replace them by hand.',
						$shortcodes,
						'ux-studio'
					),
					$shortcodes
				)
			);
		}
		if ( $placements > 0 ) {
			$m->note(
				sprintf(
					/* translators: %d: number of snippets. */
					_n(
						'%d snippet is inserted before or after the post content. It starts on posts and pages; change the post types in the snippet if it should appear on other content. The module inserts around the content of a single post or page, not in lists of posts.',
						'%d snippets are inserted before or after the post content. They start on posts and pages; change the post types in the snippet if they should appear on other content. The module inserts around the content of a single post or page, not in lists of posts.',
						$placements,
						'ux-studio'
					),
					$placements
				)
			);
		}
		if ( $c['manual'] > 0 ) {
			$m->warn(
				sprintf(
					/* translators: %d: number of snippets. */
					_n(
						'%d run-once snippet is created turned off and is NOT turned on at the switch. It executes its code one time on the next page of wp-admin after you switch it on, then switches itself off.',
						'%d run-once snippets are created turned off and are NOT turned on at the switch. Each executes its code one time on the next page of wp-admin after you switch it on, then switches itself off.',
						$c['manual'],
						'ux-studio'
					),
					$c['manual']
				)
			);
		}
		$block = SnippetManager::editBlockReason();
		if ( '' !== $block ) {
			$m->warn( $block );
		}
		return $m;
	}

	protected function data_kind(): string {
		return 'snippet';
	}

	protected function data_label(): string {
		return __( 'Code snippets', 'ux-studio' );
	}

	protected function data_total( array $options ): int {
		return count( $this->collect()['items'] );
	}

	protected function data_plan( array $options ): array {
		$items   = $this->collect()['items'];
		$enabled = 0;
		foreach ( $items as $item ) {
			$enabled += ( $item['enabled'] && ! $item['review'] && empty( $item['keep_off'] ) ) ? 1 : 0;
		}
		return array(
			array(
				'key'   => 'snippet',
				'label' => __( 'Code snippets', 'ux-studio' ),
				'count' => count( $items ),
			),
			array(
				'key'   => 'enable',
				'label' => __( 'of them will be turned on at the switch', 'ux-studio' ),
				'count' => $enabled,
			),
			array(
				'key'   => 'review',
				'label' => __( 'of them stay off for your review', 'ux-studio' ),
				'count' => $this->collect()['review'],
			),
			array(
				'key'   => 'manual',
				'label' => __( 'of them are run-once snippets that you turn on yourself', 'ux-studio' ),
				'count' => $this->collect()['manual'],
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
		return array( 'uxstudio_code_snippets' );
	}

	protected function prepare_data(): void {
		SnippetsModule::ensure_tables();
	}

	/**
	 * The snippet manager (its constructor makes sure the snippets folder exists).
	 */
	private function manager(): SnippetManager {
		if ( null === $this->manager ) {
			$this->manager = new SnippetManager();
		}
		return $this->manager;
	}

	/**
	 * The snippet with this title, if UX Studio has one.
	 *
	 * @param string $name Title.
	 */
	private function find_by_name( string $name ): ?Snippet {
		if ( ! $this->table_exists( $GLOBALS['wpdb']->prefix . 'uxstudio_code_snippets' ) ) {
			return null;
		}
		foreach ( $this->manager()->getAllSnippets() as $snippet ) {
			if ( 0 === strcasecmp( $snippet->getName(), $name ) ) {
				return $snippet;
			}
		}
		return null;
	}

	/**
	 * Is the existing snippet the same code (so the item is already migrated)?
	 *
	 * @param Snippet              $snippet Existing snippet.
	 * @param array<string, mixed> $item    Normalized snippet.
	 */
	private function same( Snippet $snippet, array $item ): bool {
		return $snippet->getType() === $item['type'] && trim( $snippet->getCode() ) === trim( (string) $item['code'] );
	}

	protected function preview_item( array $item, array $ctx ): array {
		$existing = $this->find_by_name( (string) $item['name'] );
		if ( $existing ) {
			return $this->same( $existing, $item )
				? array( 'status' => 'unchanged' )
				: array(
					'status'  => 'skipped',
					'message' => __( 'A snippet with this name already exists in UX Studio, it is kept.', 'ux-studio' ),
				);
		}
		if ( '' === trim( (string) $item['code'] ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'The snippet has no code.', 'ux-studio' ),
			);
		}
		if ( 'php' === $item['type'] ) {
			$check = ( new PhpValidator( (string) $item['code'] ) )->validate();
			if ( is_wp_error( $check ) ) {
				return array(
					'status'  => 'skipped',
					'message' => $check->get_error_message(),
				);
			}
		}
		return array( 'status' => 'created' );
	}

	protected function apply_item( array $item, array $ctx ): array {
		$existing = $this->find_by_name( (string) $item['name'] );
		if ( $existing ) {
			return $this->same( $existing, $item )
				? array( 'status' => 'unchanged' )
				: array(
					'status'  => 'skipped',
					'message' => __( 'A snippet with this name already exists in UX Studio, it is kept.', 'ux-studio' ),
				);
		}
		$res = $this->manager()->createSnippet(
			array(
				'name'         => $item['name'],
				'type'         => $item['type'],
				'code'         => $item['code'],
				'description'  => $item['description'],
				'run_location' => $item['run_location'],
				'priority'     => $item['priority'],
				'post_types'   => $item['post_types'] ?? array(),
				'conditions'   => $item['conditions'] ?? array(),
				'enabled'      => false,
			)
		);
		if ( empty( $res['success'] ) ) {
			$blocked = SnippetManager::editBlockReason();
			// A refusal of the module itself (permissions, DISALLOW_FILE_*) is an error; bad code is a skip.
			return array(
				'status'  => '' !== $blocked ? 'error' : 'skipped',
				'message' => (string) ( $res['message'] ?? '' ),
			);
		}
		$snippet = $res['data']['snippet'];
		$id      = (string) $res['data']['id'];
		$out     = array(
			'status' => 'created',
			'maps'   => array(
				array( 'snippet', $id ),
				array( 'snippet_file', basename( $snippet->getFilePath() ) ),
			),
		);
		if ( $item['enabled'] && ! $item['review'] && empty( $item['keep_off'] ) ) {
			$out['activate'] = $id;
		}
		return $out;
	}

	protected function verify_item( array $item ): array {
		$existing = $this->find_by_name( (string) $item['name'] );
		if ( ! $existing ) {
			return array(
				'ok'     => false,
				'detail' => __( 'The snippet is not in UX Studio.', 'ux-studio' ),
			);
		}
		if ( ! $this->same( $existing, $item ) ) {
			return array(
				'ok'     => false,
				'detail' => __( 'UX Studio has a snippet with this name but different code.', 'ux-studio' ),
			);
		}
		if ( $existing->hasIntegrityIssue() ) {
			return array(
				'ok'     => false,
				'detail' => __( 'The integrity check of the snippet file failed.', 'ux-studio' ),
			);
		}
		return array(
			'ok'     => true,
			'detail' => '',
		);
	}

	public function activate( string $kind, array $ids, array $data = array() ): int {
		if ( 'snippet' !== $kind ) {
			return 0;
		}
		$count = 0;
		foreach ( $ids as $id ) {
			if ( $this->manager()->setSnippetEnabled( (string) $id, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function deactivate( string $kind, array $ids, array $data = array() ): int {
		if ( 'snippet' !== $kind ) {
			return 0;
		}
		$count = 0;
		foreach ( $ids as $id ) {
			if ( $this->manager()->setSnippetEnabled( (string) $id, false ) ) {
				++$count;
			}
		}
		return $count;
	}
}
