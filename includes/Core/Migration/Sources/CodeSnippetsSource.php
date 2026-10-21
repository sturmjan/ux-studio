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
 *  - Code Snippets: table `{prefix}snippets` (name, code, scope, active, priority, tags).
 *  - WPCode: posts of type `wpcode` (code = content, type = taxonomy `wpcode_type`, place = taxonomy
 *    `wpcode_location`) and the legacy header / footer / body boxes (options `ihaf_insert_*`).
 *  - Header Footer Code Manager: table `{prefix}hfcm_scripts` (code with its own tags, placed in the header or footer).
 */
final class CodeSnippetsSource extends AbstractSource implements Activatable {

	private ?SnippetManager $manager = null;

	/** @var array{items:array<int, array<string, mixed>>,skipped:array<string, int>,review:int}|null */
	private ?array $collected = null;

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
	 * @return array{items:array<int, array<string, mixed>>,skipped:array<string, int>,review:int}
	 */
	private function collect(): array {
		if ( null !== $this->collected ) {
			return $this->collected;
		}
		$out = array(
			'items'   => array(),
			'skipped' => array(),
			'review'  => 0,
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
		foreach ( $out['items'] as $item ) {
			$out['review'] += $item['review'] ? 1 : 0;
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
			if ( 'php' === $type ) {
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
			);
		}
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
			if ( ! $auto ) {
				$this->skip( $out, 'manual' );
				continue;
			}
			$place  = $this->wpcode_place( $type, $where );
			$review = false;
			if ( null === $place ) {
				$this->skip( $out, 'place:' . ( '' === $where ? 'none' : $where ) );
				continue;
			}
			if ( 'frontend_cl' === $where ) {
				$review = true;
			}
			if ( get_post_meta( $id, '_wpcode_conditional_logic_enabled', true ) ) {
				$review = true;
			}
			$device = (string) get_post_meta( $id, '_wpcode_device_type', true );
			if ( '' !== $device && 'any' !== $device ) {
				$review = true;
			}
			if ( get_post_meta( $id, '_wpcode_schedule', true ) ) {
				$review = true;
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
			);
		}
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
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return '';
		}
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
		return is_array( $terms ) && $terms ? (string) reset( $terms ) : '';
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
				'header' => 'site_header',
				'footer' => 'site_footer',
			)[ (string) $row['location'] ] ?? null;
			if ( null === $place ) {
				$this->skip( $out, 'place:' . (string) $row['location'] );
				continue;
			}
			if ( 'manual' === (string) $row['display_on'] ) {
				$this->skip( $out, 'manual' );
				continue;
			}
			// The plugin prints the stored text as it is (its JS and CSS snippets carry their own tags).
			$review = 'All' !== (string) $row['display_on'] || 'both' !== (string) ( $row['device_type'] ?? 'both' );
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
			);
		}
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->collect();
		if ( ! $c['items'] && ! $c['skipped'] ) {
			$m->note( __( 'The plugin has no snippets stored, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$labels = array(
			'scope:content'          => __( 'shortcode snippets (content)', 'ux-studio' ),
			'scope:single-use'       => __( 'run-once snippets', 'ux-studio' ),
			'scope:condition'        => __( 'condition snippets', 'ux-studio' ),
			'type:text'              => __( 'plain text snippets', 'ux-studio' ),
			'type:universal'         => __( 'universal (PHP + HTML) snippets', 'ux-studio' ),
			'type:blocks'            => __( 'block snippets', 'ux-studio' ),
			'type:scss'              => __( 'SCSS snippets', 'ux-studio' ),
			'type:unknown'           => __( 'snippets of an unknown type', 'ux-studio' ),
			'manual'                 => __( 'snippets that run only through a shortcode or on demand', 'ux-studio' ),
			'place:before_post'      => __( 'snippets inserted before or after posts', 'ux-studio' ),
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
						'%d snippet used conditions, device or schedule rules or page targeting that the module does not have. It is created switched off so you can review it before it runs everywhere.',
						'%d snippets used conditions, device or schedule rules or page targeting that the module does not have. They are created switched off so you can review them before they run everywhere.',
						$c['review'],
						'ux-studio'
					),
					$c['review']
				)
			);
		}
		$m->note( __( 'Every snippet is created switched off, because it is code. Snippets that were on in the old plugin are switched on at the switch, after the safe preview.', 'ux-studio' ) );
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
			$enabled += ( $item['enabled'] && ! $item['review'] ) ? 1 : 0;
		}
		return array(
			array(
				'key'   => 'snippet',
				'label' => __( 'Code snippets', 'ux-studio' ),
				'count' => count( $items ),
			),
			array(
				'key'   => 'enable',
				'label' => __( 'of them will be switched on at the switch', 'ux-studio' ),
				'count' => $enabled,
			),
			array(
				'key'   => 'review',
				'label' => __( 'of them stay off for your review', 'ux-studio' ),
				'count' => $this->collect()['review'],
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
		if ( $item['enabled'] && ! $item['review'] ) {
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
}
