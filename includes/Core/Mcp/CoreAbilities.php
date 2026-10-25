<?php
/**
 * `uxstudio/*` abilities: site overview, modules, settings, overlaps, logs.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use UxStudio\Core\Migration\Overlaps;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Thin layer over the routes the admin UI uses (see Internal), so every guard stays in one place.
 */
final class CoreAbilities {

	private const MODULE_ID = array(
		'type'      => 'string',
		'minLength' => 1,
		'maxLength' => 64,
	);

	/**
	 * Definitions of this group.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		$R = Scopes::READ;
		$W = Scopes::WRITE;
		$ro = array( 'annotations' => array( 'readonly' => true ) );

		return array(
			Registry::define(
				'uxstudio/site-overview',
				__( 'Site overview', 'ux-studio' ),
				'Versions of WordPress, PHP and UX Studio, which UX Studio modules are enabled, how many plugin overlaps exist and what the last migration run was. Start here.',
				$R,
				array(),
				array(),
				array( self::class, 'site_overview' ),
				$ro
			),
			Registry::define(
				'uxstudio/modules-list',
				__( 'List modules', 'ux-studio' ),
				'All UX Studio modules with id, name, group, enabled state and whether they have settings. Group entries (with children) are tiles that bundle several modules; enable the child modules.',
				$R,
				array(
					'enabled' => array( 'type' => 'boolean', 'description' => 'Only enabled (true) or only disabled (false) modules.' ),
					'group'   => array( 'type' => 'string', 'description' => 'Only modules of this group id.' ),
					'search'  => array( 'type' => 'string', 'description' => 'Text in id, name or description.' ),
				),
				array(),
				array( self::class, 'modules_list' ),
				$ro
			),
			Registry::define(
				'uxstudio/module-settings-get',
				__( 'Get module settings', 'ux-studio' ),
				'Settings schema (field keys, types, options, defaults) and current values of one module. Secrets (API keys, passwords, tokens) are never returned: they are replaced by has_<key> flags.',
				$R,
				array( 'module' => self::MODULE_ID + array( 'description' => 'Module id, e.g. security-optimization.' ) ),
				array( 'module' ),
				array( self::class, 'settings_get' ),
				$ro
			),
			Registry::define(
				'uxstudio/overlaps-list',
				__( 'List plugin overlaps', 'ux-studio' ),
				'Installed plugins that do the same job as an enabled UX Studio module, grouped as full (blocks enabling the module), customiser (needs confirmation), partial (information only) and ignored. migratable=true means the migration tools can move the plugin settings over.',
				$R,
				array(),
				array(),
				array( self::class, 'overlaps_list' ),
				$ro
			),
			Registry::define(
				'uxstudio/csp-inventory',
				__( 'CSP inventory', 'ux-studio' ),
				'Content-Security-Policy state (mode, test period, gate checks, approved sources) and the inventory of reported violations with their risk level.',
				$R,
				array(
					'status'   => array( 'type' => 'string', 'description' => 'Violation status filter, e.g. open or resolved.' ),
					'level'    => array( 'type' => 'string', 'description' => 'Risk level filter.' ),
					'search'   => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
					'paged'    => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				),
				array(),
				array( self::class, 'csp_inventory' ),
				$ro + array( 'module' => 'security-optimization' )
			),
			Registry::define(
				'uxstudio/activity-log-query',
				__( 'Query the Activity Log', 'ux-studio' ),
				'Newest first. Every MCP tool call is logged here as module "mcp" with the token id.',
				$R,
				array(
					'module'  => array( 'type' => 'string', 'description' => 'Module id of the entry, e.g. core, mcp.' ),
					'action'  => array( 'type' => 'string' ),
					'user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'since'   => array( 'type' => 'string', 'description' => 'Site time, "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".' ),
					'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
					'offset'  => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				),
				array(),
				array( self::class, 'activity_log_query' ),
				$ro
			),
			Registry::define(
				'uxstudio/email-log-search',
				__( 'Search the e-mail log', 'ux-studio' ),
				'Outgoing e-mails recorded by the Email Log module (recipient, subject, status, error, source). Bodies are not returned.',
				$R,
				array(
					'search'    => array( 'type' => 'string', 'description' => 'Text in subject, recipient or source.' ),
					'status'    => array( 'type' => 'string', 'enum' => array( 'sent', 'failed', 'pending' ) ),
					'date_from' => array( 'type' => 'string', 'description' => 'YYYY-MM-DD' ),
					'date_to'   => array( 'type' => 'string', 'description' => 'YYYY-MM-DD' ),
					'limit'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				),
				array(),
				array( self::class, 'email_log_search' ),
				$ro + array( 'module' => 'email-log' )
			),

			Registry::define(
				'uxstudio/module-enable',
				__( 'Enable a module', 'ux-studio' ),
				'Enable a UX Studio module (security-relevant modules need the admin scope). Goes through the plugin-overlap guard: when an active plugin already does the same job the call is refused with code uxstudio_plugin_overlap and the plugins listed in data.plugins - use the migration tools to move them over and deactivate them first. A partial overlap needs confirm_overlap=true.',
				$W,
				array(
					'module'          => self::MODULE_ID + array( 'description' => 'Module id.' ),
					'confirm_overlap' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Accept a partial overlap (the user confirmed that both may run).' ),
				),
				array( 'module' ),
				static fn( array $in ): array => self::toggle( (string) $in['module'], true, ! empty( $in['confirm_overlap'] ) ),
				array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) )
			),
			Registry::define(
				'uxstudio/module-disable',
				__( 'Disable a module', 'ux-studio' ),
				'Disable a UX Studio module (security-relevant modules need the admin scope). Its settings stay stored; the module runs its own clean-up (cron events, .htaccess blocks).',
				$W,
				array( 'module' => self::MODULE_ID + array( 'description' => 'Module id.' ) ),
				array( 'module' ),
				static fn( array $in ): array => self::toggle( (string) $in['module'], false, false ),
				array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) )
			),
			Registry::define(
				'uxstudio/module-settings-update',
				__( 'Update module settings', 'ux-studio' ),
				'Change settings of a module through its own save path (validated against its schema, with its side effects such as .htaccess rewrites). Only keys from module-settings-get are accepted; others are refused. Keys that look like credentials are refused unless the token has the admin scope and allow_secrets=true. Settings that decide where stored credentials or data are sent (SMTP host and regions, central app address, AI provider, notification recipients) or that guard the site (login address, firewall, 2FA, CSP, bypass lists) need the admin scope; the refusal lists them. Use dry_run=true to see what would change.',
				$W,
				array(
					'module'        => self::MODULE_ID + array( 'description' => 'Module id.' ),
					'values'        => array( 'type' => 'object', 'description' => 'Setting key => new value. Omitted keys keep their value.' ),
					'dry_run'       => array( 'type' => 'boolean', 'default' => false ),
					'allow_secrets' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Admin scope only: accept credential keys.' ),
				),
				array( 'module', 'values' ),
				array( self::class, 'settings_update' ),
				array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) )
			),
			Registry::define(
				'uxstudio/overlap-ignore',
				__( 'Ignore plugin overlaps', 'ux-studio' ),
				'Hide overlap pairs (keys from overlaps-list) from the panel and the admin notice. Nothing is deactivated.',
				$W,
				array( 'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'minItems' => 1, 'maxItems' => 100 ) ),
				array( 'keys' ),
				static fn( array $in ): array => (array) Internal::data( 'POST', '/overlaps/ignore', array( 'keys' => array_values( $in['keys'] ) ) ),
				array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) )
			),
			Registry::define(
				'uxstudio/overlap-unignore',
				__( 'Show ignored overlaps again', 'ux-studio' ),
				'Reverse of overlap-ignore.',
				$W,
				array( 'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'minItems' => 1, 'maxItems' => 100 ) ),
				array( 'keys' ),
				static fn( array $in ): array => (array) Internal::data( 'POST', '/overlaps/unignore', array( 'keys' => array_values( $in['keys'] ) ) ),
				array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) )
			),
		);
	}

	/**
	 * Scopes of the current caller (a request without a token is a logged-in administrator).
	 *
	 * @return string[]
	 */
	public static function caller_scopes(): array {
		$ctx = Auth::context();
		return $ctx && 'ok' === $ctx['state'] ? Scopes::expand( (array) $ctx['scopes'] ) : Scopes::all();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function site_overview(): array {
		global $wp_version;
		$plugin  = \UxStudio\Plugin::instance();
		$all     = $plugin->modules->all();
		$enabled = $plugin->modules->enabled_ids();
		$grouped = Overlaps::grouped();
		$run     = \UxStudio\Core\Migration\Runner::state();

		return array(
			'site'       => array(
				'name'      => get_bloginfo( 'name' ),
				'url'       => home_url( '/' ),
				'locale'    => get_locale(),
				'multisite' => is_multisite(),
			),
			'versions'   => array(
				'wordpress'  => (string) $wp_version,
				'php'        => PHP_VERSION,
				'ux_studio'  => defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '',
				'api'        => defined( 'UXSTUDIO_API_VERSION' ) ? UXSTUDIO_API_VERSION : 1,
				'abilities_api' => function_exists( 'wp_register_ability' ),
			),
			'modules'    => array(
				'enabled'       => array_values( $enabled ),
				'enabled_count' => count( $enabled ),
				'total'         => count( array_filter( $all, static fn( array $m ): bool => empty( $m['virtual'] ) ) ),
			),
			'overlaps'   => array(
				'full'       => count( $grouped['full'] ),
				'customiser' => count( $grouped['customiser'] ),
				'partial'    => count( $grouped['partial'] ),
				'ignored'    => count( $grouped['ignored'] ),
			),
			'migration'  => array(
				'last_run' => $run ? array_intersect_key( $run, array_flip( array( 'id', 'status', 'dry_run', 'sources' ) ) ) : null,
			),
			'mcp'        => array(
				'scopes'    => self::caller_scopes(),
				'abilities' => count( Registry::visible( self::caller_scopes() ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function modules_list( array $in ): array {
		$rows = (array) Internal::data( 'GET', '/modules' );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( isset( $in['enabled'] ) && (bool) $row['enabled'] !== (bool) $in['enabled'] ) {
				continue;
			}
			if ( ! empty( $in['group'] ) && (string) $row['group'] !== (string) $in['group'] ) {
				continue;
			}
			if ( ! empty( $in['search'] ) ) {
				$hay = strtolower( $row['id'] . ' ' . $row['name'] . ' ' . $row['description'] );
				if ( false === strpos( $hay, strtolower( (string) $in['search'] ) ) ) {
					continue;
				}
			}
			$out[] = array(
				'id'           => $row['id'],
				'name'         => $row['name'],
				'description'  => $row['description'],
				'group'        => $row['group'],
				'enabled'      => (bool) $row['enabled'],
				'has_settings' => (bool) $row['settings'],
				'parent'       => $row['parent'],
				'children'     => $row['children'],
			);
		}
		return array(
			'modules' => $out,
			'total'   => count( $out ),
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function settings_get( array $in ): array {
		$id   = self::module_id( (string) $in['module'] );
		$data = (array) Internal::data( 'GET', '/settings/' . $id );
		$mask   = Secrets::mask_settings( (array) ( $data['values'] ?? array() ) );
		$schema = (array) ( $data['schema'] ?? array() );
		$values = $mask['values'];
		$hidden = array();
		if ( ! in_array( Scopes::ADMIN, self::caller_scopes(), true ) ) {
			// Values that are not credentials but weaken the protection when known (secret login address, bypass IPs).
			$hide   = Sensitive::hide_values( $id, $schema, $values );
			$values = $hide['values'];
			$hidden = $hide['hidden'];
		}
		return array(
			'module'        => $id,
			'name'          => $data['name'] ?? $id,
			'schema'        => $schema,
			'values'        => $values,
			'redacted_keys' => $mask['redacted'],
			'hidden_keys'   => $hidden,
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function overlaps_list( array $in = array() ): array {
		return (array) Internal::data( 'GET', '/overlaps' );
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function csp_inventory( array $in ): array {
		$params = array_filter(
			array(
				'status'   => $in['status'] ?? '',
				'level'    => $in['level'] ?? '',
				'search'   => $in['search'] ?? '',
				'per_page' => $in['per_page'] ?? 50,
				'paged'    => $in['paged'] ?? 1,
			),
			static fn( $v ): bool => '' !== $v && null !== $v
		);
		$list   = Internal::rest( 'GET', '/security-optimization/csp-violations', $params );
		$status = (array) Internal::data( 'GET', '/security-optimization/csp/status' );
		return array(
			'status'     => $status,
			'violations' => $list['data'],
			'total'      => (int) ( $list['meta']['total'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function activity_log_query( array $in ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'uxstudio_activity_log';
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'module', 'action' ) as $col ) {
			if ( ! empty( $in[ $col ] ) ) {
				$where[]  = "{$col} = %s";
				$params[] = sanitize_key( (string) $in[ $col ] );
			}
		}
		if ( ! empty( $in['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $in['user_id'];
		}
		if ( ! empty( $in['since'] ) ) {
			$since = trim( (string) $in['since'] );
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $since ) ) {
				throw new McpError( 'invalid_input', 'since must look like 2026-10-21 or 2026-10-21 08:30:00.' );
			}
			$where[]  = 'created_at >= %s';
			$params[] = $since;
		}
		$limit    = max( 1, min( 200, (int) ( $in['limit'] ?? 50 ) ) );
		$offset   = max( 0, (int) ( $in['offset'] ?? 0 ) );
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows_sql  = "SELECT id, created_at, user_id, module, action, object_type, object_id, meta FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $limit, $offset ) ) ), ARRAY_A );

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['meta'] = $row['meta'] ? json_decode( (string) $row['meta'], true ) : null;
			$items[]     = $row;
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function email_log_search( array $in ): array {
		$module = \UxStudio\Plugin::instance()->modules->instance( 'email-log' );
		if ( ! $module || ! method_exists( $module, 'get_entries_for_hub' ) ) {
			throw new McpError( 'unavailable', 'The Email Log module is not available.' );
		}
		return (array) $module->get_entries_for_hub( $in );
	}

	/**
	 * Enable / disable through the same route as the UI (overlap guard included).
	 *
	 * @param string $id              Module id.
	 * @param bool   $enabled         New state.
	 * @param bool   $confirm_overlap Partial overlap confirmed.
	 * @return array<string, mixed>
	 */
	public static function toggle( string $id, bool $enabled, bool $confirm_overlap ): array {
		$id = self::module_id( $id );
		if ( Sensitive::is_security_module( $id ) && ! in_array( Scopes::ADMIN, self::caller_scopes(), true ) ) {
			throw new McpError(
				'admin_scope_required',
				'Switching this module on or off changes how the site is protected or where data goes, so only a token with the admin scope may do it over MCP: ' . $id . '.',
				array( 'module' => $id, 'required_scope' => Scopes::ADMIN )
			);
		}
		return (array) Internal::data(
			'POST',
			'/modules/' . $id,
			array(
				'enabled'         => $enabled,
				'confirm_overlap' => $confirm_overlap,
			)
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function settings_update( array $in ): array {
		$id     = self::module_id( (string) $in['module'] );
		$values = (array) $in['values'];
		if ( ! $values ) {
			throw new McpError( 'invalid_input', 'values is empty - nothing to change.' );
		}

		$may_secrets = ! empty( $in['allow_secrets'] ) && in_array( Scopes::ADMIN, self::caller_scopes(), true );
		$secret_keys = Secrets::secret_keys( $values );
		if ( $secret_keys && ! $may_secrets ) {
			throw new McpError(
				'secret_not_allowed',
				'These keys look like credentials and cannot be set over MCP with this token: ' . implode( ', ', $secret_keys ) . '. Enter them in the UX Studio admin, or use an admin token with allow_secrets=true.',
				array( 'keys' => $secret_keys )
			);
		}

		$current = (array) Internal::data( 'GET', '/settings/' . $id );
		$schema  = (array) ( $current['schema'] ?? array() );
		$known   = array_column( $schema, 'key' );

		// Keys that decide where credentials/data go or that guard the site: admin scope only.
		$admin_keys = Sensitive::admin_write_keys( $id, $schema, array_map( 'strval', array_keys( $values ) ) );
		if ( $admin_keys && ! in_array( Scopes::ADMIN, self::caller_scopes(), true ) ) {
			throw new McpError(
				'admin_scope_required',
				'These settings decide where stored credentials or data are sent, or protect the site, so only a token with the admin scope may change them over MCP: ' . implode( ', ', $admin_keys ) . '.',
				array( 'keys' => $admin_keys, 'required_scope' => Scopes::ADMIN )
			);
		}
		$unknown = array_values( array_diff( array_map( 'strval', array_keys( $values ) ), $known, $secret_keys ) );
		if ( $unknown ) {
			throw new McpError(
				'unknown_setting',
				'Unknown setting key(s): ' . implode( ', ', $unknown ) . '. Read the schema with module-settings-get first.',
				array( 'unknown' => $unknown, 'known' => $known )
			);
		}

		$plain = array_diff_key( $values, array_flip( $secret_keys ) );
		$clean = Settings::clean( $plain, $schema );
		$old   = (array) ( $current['values'] ?? array() );
		$diff  = array();
		foreach ( $clean as $key => $new ) {
			if ( ( $old[ $key ] ?? null ) !== $new ) {
				$diff[ $key ] = array( 'from' => $old[ $key ] ?? null, 'to' => $new );
			}
		}
		$changed_keys = array_merge( array_keys( $diff ), $secret_keys );

		if ( ! empty( $in['dry_run'] ) ) {
			return array(
				'dry_run'   => true,
				'module'    => $id,
				'changes'   => Secrets::scrub( $diff ),
				'credential_fields' => $secret_keys,
				'unchanged' => array_values( array_diff( array_keys( $clean ), array_keys( $diff ) ) ),
			);
		}
		if ( ! $changed_keys ) {
			return array(
				'dry_run' => false,
				'module'  => $id,
				'saved'   => false,
				'message' => 'The submitted values equal the current ones.',
			);
		}

		$saved = (array) Internal::data( 'POST', '/settings/' . $id, $values );
		$mask  = Secrets::mask_settings( (array) ( $saved['values'] ?? array() ) );
		return array(
			'dry_run' => false,
			'module'  => $id,
			'saved'   => true,
			'changed' => $changed_keys,
			'values'  => in_array( Scopes::ADMIN, self::caller_scopes(), true ) ? $mask['values'] : Sensitive::hide_values( $id, $schema, $mask['values'] )['values'],
		);
	}

	/**
	 * Module id from a client: lowercase letters, digits, dashes.
	 *
	 * @param string $id Raw id.
	 * @throws McpError When it is not a module id.
	 */
	private static function module_id( string $id ): string {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $id ) ) {
			throw new McpError( 'invalid_input', 'module must be a module id such as security-optimization.' );
		}
		return $id;
	}
}
