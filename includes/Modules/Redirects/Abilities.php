<?php
/**
 * WordPress Abilities API (core since 6.9) exposure of the module, so any
 * MCP client wired to the site (AI Assistant's MCP server, the official MCP
 * adapter, /wp-json/wp-abilities/v1) can migrate URLs natively.
 *
 * Intended AI workflow for a site migration:
 * 1. collect the old site's URLs (sitemap / crawl - done by the agent);
 * 2. redirects/list-site-urls + redirects/find-target to map each one;
 * 3. redirects/upsert-rules with dry_run=true, fix errors, then dry_run=false;
 * 4. redirects/test-url (live=true) on a sample, redirects/audit;
 * 5. later: redirects/list-404 (unresolved_only) to catch what was missed.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	private const CATEGORY = 'redirects';

	/**
	 * Definitions collected by ability() while the list is built.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $defs = array();

	/**
	 * Hook registration (abilities API fires its own init actions).
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Ability category.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Redirects', 'ux-studio' ),
				'description' => __( 'SEO-safe URL redirects, 410 rules and the 404 log.', 'ux-studio' ),
			)
		);
	}

	/**
	 * All abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( self::definitions() as $def ) {
			wp_register_ability(
				self::CATEGORY . '/' . $def['slug'],
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => $def['schema'],
					'execute_callback'    => static fn ( $input = null ): array => ( $def['callback'] )( is_array( $input ) ? $input : array() ),
					'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
					'meta'                => array(
						'show_in_rest' => true,
						'public'       => true,
						'mcp'          => array(
							'public' => true,
							'type'   => 'tool',
						),
						'annotations'  => $def['annotations'] + array( 'title' => $def['label'] ),
					),
				)
			);
		}
	}

	/**
	 * The abilities as plain definitions (slug, label, description, schema, callback, annotations),
	 * independent of the WordPress Abilities API, so the UX Studio MCP server can expose them on
	 * WordPress versions that do not have it (it only arrived in 6.9).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		self::$defs = array();
		self::build();
		return self::$defs;
	}

	/**
	 * Build the definitions (each ability() call appends one).
	 */
	private static function build(): void {
		$rule_item = array(
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer', 'description' => 'Existing rule id to update (partial update: omitted fields keep their value).' ),
				'source'         => array( 'type' => 'string', 'description' => 'Old URL. Full URL of the old domain, of this site, or a path relative to this site\'s home URL ("/old-page.html?id=3"). Case (unless case_sensitive), trailing slash and query parameter order are ignored; a source without query matches any query (see query_mode).' ),
				'match_type'     => array( 'type' => 'string', 'enum' => Repository::MATCH_TYPES, 'description' => 'exact (default) or regex. Regex is matched case-insensitively against "/path?sorted=query", without delimiters, e.g. "^/blog/(\\d+)-(.*)$".' ),
				'target'         => array( 'type' => 'string', 'description' => 'New URL: path on this site ("/new-page/") or absolute http(s) URL. Regex targets may use $1..$9.' ),
				'target_post_id' => array( 'type' => 'integer', 'description' => 'Preferred over target: the rule then follows the post\'s permalink forever.' ),
				'code'           => array( 'type' => 'integer', 'enum' => Repository::CODES, 'description' => '301 permanent (default), 308 permanent keeping method, 302/307 temporary, 410 gone (no target; content removed with no equivalent).' ),
				'enabled'        => array( 'type' => 'boolean' ),
				'note'           => array( 'type' => 'string', 'description' => 'Why the redirect exists (shown in admin).' ),
				'conditions'     => self::conditions_schema(),
				'case_sensitive' => array( 'type' => 'boolean', 'default' => false, 'description' => 'true = the letter case of the path must match exactly (/About does not match /about). Default false: case is ignored. Exact rules only; use it e.g. to send /Foo to /foo.' ),
				'query_mode'     => array( 'type' => 'string', 'enum' => Repository::QUERY_MODES, 'default' => 'default', 'description' => 'Query string handling of an exact rule. default = a source without query matches any query (carried to the target unless the module setting "Keep the query string" is off), a source with a query matches only that query. exact = a request with ANY query the source does not name does not match (like Redirection\'s "exact match"). ignore = match on the path, never pass the visitor\'s query on. pass = match on the path and always append the visitor\'s query to the target. ignore/pass need a source WITHOUT query; regex rules ignore this field.' ),
				'position'       => array( 'type' => 'integer', 'description' => 'Order among the rules of ONE source (lower first, then older first). The first rule whose conditions hold wins; a rule without conditions is the fallback: it is always tried after the rules with conditions, whatever the positions.' ),
			),
		);

		self::ability(
			'list-rules',
			__( 'List redirects', 'ux-studio' ),
			'List redirect rules with filters and paging. Returns items + total; every item carries its conditions (empty = applies to everybody), position, case_sensitive, query_mode and the hit counter (hits, last_hit = time of the last redirect served by the rule, null = never). orderby hits/last_hit finds the rules that still get traffic or are dead.',
			array(
				'search'     => array( 'type' => 'string' ),
				'code'       => array( 'type' => 'integer', 'enum' => Repository::CODES ),
				'origin'     => array( 'type' => 'string', 'enum' => Repository::ORIGINS ),
				'match_type' => array( 'type' => 'string', 'enum' => Repository::MATCH_TYPES ),
				'conditional' => array( 'type' => 'boolean', 'description' => 'true = only rules with conditions, false = only rules without.' ),
				'orderby'    => array( 'type' => 'string', 'enum' => array( 'id', 'source', 'hits', 'last_hit', 'created_at', 'updated_at', 'code' ) ),
				'order'      => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
				'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => Repository::BULK_MAX, 'default' => 50 ),
				'offset'     => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
			),
			array(),
			static fn ( array $in ): array => Repository::list( $in ),
			array( 'readonly' => true )
		);

		self::ability(
			'upsert-rules',
			__( 'Create or update redirects', 'ux-studio' ),
			'Create/update up to ' . Repository::BULK_MAX . ' rules in one call. Enforces SEO rules: rejects loops, self-redirects and WordPress system paths; flattens chains (A->B + B->C becomes A->C); binds same-site targets to the post. Returns per-item status (created|updated|unchanged|skipped|would_create|would_update|error) with errors[] and warnings[] (homepage_target = soft-404 risk, temporary_code, target_unverified, source_has_content, chain_flattened, target_conditional, conditional_loop). ALWAYS run with dry_run=true first. A source can have SEVERAL rules told apart by their conditions (login state, role, referrer, user agent, IP/CIDR, cookie, host, HTTP header): the first rule (by position, then id) whose conditions all hold wins, a rule without conditions is the fallback. Upserting a source with different conditions therefore ADDS a rule; to change an existing one pass its id. Conditional rules are never flattened into chains and are answered with no-cache headers.',
			array(
				'items'       => array( 'type' => 'array', 'items' => $rule_item, 'minItems' => 1, 'maxItems' => Repository::BULK_MAX ),
				'dry_run'     => array( 'type' => 'boolean', 'default' => true, 'description' => 'Validate only, write nothing. Defaults to true.' ),
				'on_conflict' => array( 'type' => 'string', 'enum' => array( 'update', 'skip', 'error' ), 'default' => 'update', 'description' => 'When the source already has a rule.' ),
			),
			array( 'items' ),
			static function ( array $in ): array {
				$dry    = (bool) ( $in['dry_run'] ?? true );
				$result = Repository::bulk( (array) $in['items'], (string) ( $in['on_conflict'] ?? 'update' ), $dry, 'ai' );
				if ( ! $dry ) {
					ActivityLog::log( 'redirects', 'ai_upsert', '', 0, $result['summary'] );
				}
				return $result;
			},
			array( 'readonly' => false, 'destructive' => false, 'idempotent' => true )
		);

		self::ability(
			'reset-hits',
			__( 'Reset redirect hit counters', 'ux-studio' ),
			'Zero the hit counter and the last-hit time of the given rules (ids) or of every rule (all=true). The counters show which old URLs still get traffic; reset them after a change to start counting from now. Rules are not changed otherwise.',
			array(
				'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => Repository::BULK_MAX ),
				'all' => array( 'type' => 'boolean', 'default' => false, 'description' => 'true = every rule (then ids is ignored).' ),
			),
			array(),
			static function ( array $in ): array {
				$all = (bool) ( $in['all'] ?? false );
				$ids = array_values( array_filter( array_map( 'intval', (array) ( $in['ids'] ?? array() ) ) ) );
				if ( ! $all && ! $ids ) {
					return array( 'reset' => 0, 'error' => 'Pass ids, or all=true.' );
				}
				$reset = Repository::reset_hits( $all ? null : $ids );
				ActivityLog::log( 'redirects', 'ai_reset_hits', '', 0, array( 'reset' => $reset, 'all' => $all ) );
				return array( 'reset' => $reset );
			},
			array( 'readonly' => false, 'destructive' => true, 'idempotent' => true )
		);

		self::ability(
			'delete-rules',
			__( 'Delete redirects', 'ux-studio' ),
			'Delete rules by id. Prefer code 410 over deleting when an old URL should tell search engines the content is gone.',
			array(
				'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => Repository::BULK_MAX ),
			),
			array( 'ids' ),
			static function ( array $in ): array {
				$deleted = Repository::delete( (array) $in['ids'] );
				ActivityLog::log( 'redirects', 'ai_delete', '', 0, array( 'deleted' => $deleted ) );
				return array( 'deleted' => $deleted );
			},
			array( 'readonly' => false, 'destructive' => true, 'idempotent' => true )
		);

		self::ability(
			'test-url',
			__( 'Test a URL', 'ux-studio' ),
			'Resolve one or more old URLs through the rules: hops, final URL/code, loop, issues (loop|chain|temporary|live_check_failed). live=true also follows each over HTTP and reports real status codes (ok = 200 in max 1 hop, or a direct 410). Rules with conditions are evaluated for the visitor given in "visitor" (default: anonymous, no headers, no cookies); skipped_rule_ids lists the rules of the URL whose conditions did not hold. live=true requests from the server itself, so conditions are evaluated for that request.',
			array(
				'urls'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'minItems' => 1, 'maxItems' => 50 ),
				'live'    => array( 'type' => 'boolean', 'default' => false ),
				'visitor' => array(
					'type'                 => 'object',
					'description'          => 'Simulated visitor for rules with conditions.',
					'properties'           => array(
						'logged_in' => array( 'type' => 'boolean' ),
						'roles'     => array( 'type' => 'string', 'description' => 'Comma separated roles of the logged-in user.' ),
						'referrer'  => array( 'type' => 'string' ),
						'agent'     => array( 'type' => 'string', 'description' => 'User agent.' ),
						'ip'        => array( 'type' => 'string' ),
						'host'      => array( 'type' => 'string' ),
						'cookies'   => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ),
						'headers'   => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ),
					),
					'additionalProperties' => false,
				),
			),
			array( 'urls' ),
			static fn ( array $in ): array => array_map(
				static fn ( $url ): array => Tester::test( (string) $url, (bool) ( $in['live'] ?? false ), isset( $in['visitor'] ) && is_array( $in['visitor'] ) ? $in['visitor'] : null ),
				array_slice( (array) $in['urls'], 0, 50 )
			),
			array( 'readonly' => true )
		);

		self::ability(
			'audit',
			__( 'Audit redirects', 'ux-studio' ),
			'SEO audit of all enabled rules: loops, chains, gone target posts, long-running temporary redirects, mass homepage redirects (soft 404), sources that have live content again; live=true HTTP-checks targets. fix_chains=true re-points chained rules straight to the final URL.',
			array(
				'live'       => array( 'type' => 'boolean', 'default' => false ),
				'live_limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50 ),
				'fix_chains' => array( 'type' => 'boolean', 'default' => false ),
			),
			array(),
			static fn ( array $in ): array => Tester::audit( (bool) ( $in['live'] ?? false ), (int) ( $in['live_limit'] ?? 50 ), (bool) ( $in['fix_chains'] ?? false ) ),
			array( 'readonly' => false, 'destructive' => false, 'idempotent' => true )
		);

		self::ability(
			'list-404',
			__( 'List 404 errors', 'ux-studio' ),
			'URLs visitors/bots hit that do not exist, aggregated with hit counts and last referrer. unresolved_only=true hides URLs a rule already covers. Map the high-hit ones to their best current page (or 410).',
			array(
				'search'          => array( 'type' => 'string' ),
				'min_hits'        => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				'unresolved_only' => array( 'type' => 'boolean', 'default' => true ),
				'orderby'         => array( 'type' => 'string', 'enum' => array( 'hits', 'last_seen' ), 'default' => 'hits' ),
				'limit'           => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50 ),
				'offset'          => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
			),
			array(),
			static fn ( array $in ): array => NotFoundLog::list( $in + array( 'unresolved_only' => true ) ),
			array( 'readonly' => true )
		);

		self::ability(
			'list-site-urls',
			__( 'List current site URLs', 'ux-studio' ),
			'Inventory of THIS site\'s published URLs (kind=posts, all public post types) or archive URLs (kind=terms). Use it as the target list when mapping old URLs.',
			array(
				'kind'      => array( 'type' => 'string', 'enum' => array( 'posts', 'terms' ), 'default' => 'posts' ),
				'post_type' => array( 'type' => 'string' ),
				'taxonomy'  => array( 'type' => 'string' ),
				'search'    => array( 'type' => 'string' ),
				'limit'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
				'offset'    => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
			),
			array(),
			static fn ( array $in ): array => SiteIndex::urls( $in ),
			array( 'readonly' => true )
		);

		self::ability(
			'find-target',
			__( 'Suggest a target', 'ux-studio' ),
			'Suggest the best current pages for old URLs (slug similarity + search; pass the old page title when known). Score 0-100; below ~60 verify the content before redirecting.',
			array(
				'items' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'url'   => array( 'type' => 'string' ),
							'title' => array( 'type' => 'string' ),
						),
						'required'   => array( 'url' ),
					),
				),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5 ),
			),
			array( 'items' ),
			static fn ( array $in ): array => array_map(
				static fn ( $item ): array => array(
					'url'        => (string) ( $item['url'] ?? '' ),
					'candidates' => SiteIndex::candidates( (string) ( $item['url'] ?? '' ), (string) ( $item['title'] ?? '' ), (int) ( $in['limit'] ?? 5 ) ),
				),
				array_slice( (array) $in['items'], 0, 50 )
			),
			array( 'readonly' => true )
		);
	}

	/**
	 * Input schema of a rule's conditions (shared by upsert-rules).
	 *
	 * @return array<string, mixed>
	 */
	private static function conditions_schema(): array {
		return array(
			'type'        => 'array',
			'maxItems'    => Conditions::MAX,
			'description' => 'Optional. The rule applies only to visitors who meet ALL conditions (empty or omitted = everybody; on a partial update an empty array removes them). type: login (value "in" or "out"), role (value: comma separated roles or capabilities, e.g. "administrator,editor"; logged-out visitors never match), referrer / agent / host (value + op equals|contains|regex, default contains, host default equals), ip (value: comma or newline separated IPs and CIDR ranges), cookie / header (name + optional value; no value = only has to exist; op equals|contains|regex). negate=true inverts a condition ("not logged in", "referrer does not contain"). Regexes are case-insensitive, without delimiters.',
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'type'   => array( 'type' => 'string', 'enum' => Conditions::TYPES ),
					'op'     => array( 'type' => 'string', 'enum' => Conditions::OPS ),
					'name'   => array( 'type' => 'string', 'description' => 'Cookie or HTTP header name.' ),
					'value'  => array( 'type' => 'string' ),
					'negate' => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'type' ),
			),
		);
	}

	/**
	 * Collect one ability definition.
	 *
	 * @param string                              $slug        Name without category.
	 * @param string                              $label       Label.
	 * @param string                              $description Description for the model (kept English on purpose).
	 * @param array<string, array<string, mixed>> $properties  Input properties.
	 * @param array<int, string>                  $required    Required properties.
	 * @param callable                            $callback    function( array $input ): array.
	 * @param array<string, bool>                 $annotations readonly/destructive/idempotent.
	 */
	private static function ability( string $slug, string $label, string $description, array $properties, array $required, callable $callback, array $annotations ): void {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
			// Lets a call without any input pass validation (null -> {}).
			'default'              => array(),
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		self::$defs[] = array(
			'slug'        => $slug,
			'label'       => $label,
			'description' => $description,
			'schema'      => $schema,
			'callback'    => $callback,
			'annotations' => $annotations,
		);
	}
}
