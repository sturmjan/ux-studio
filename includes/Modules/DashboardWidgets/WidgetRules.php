<?php
/**
 * Dashboard widget rules: who sees which widget of the wp-admin dashboard. Pure functions, no WordPress needed.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * Data model (setting `widget_rules`): `{ "<widget id>": { "hidden": bool, "roles": ["administrator", ...] } }`.
 *
 *  - `hidden` true: nobody sees the widget;
 *  - `roles` not empty: the widget is shown only to users who have at least one of these roles;
 *  - an entry with the defaults (not hidden, no roles = everybody) is never stored.
 *
 * The snapshot (`uxstudio_dashboard_widget_snapshot`) lists the widgets the site registers:
 * `{ "scan_at": ts, "widgets": { "<id>": { id, title, context, priority, source, source_name, guess, first_seen, last_seen } } }`.
 * A widget whose `last_seen` is older than `scan_at` was not registered by the latest scan: it is "not found".
 */
final class WidgetRules {

	public const MAX_ENTRIES = 300;
	public const MAX_ID      = 120;
	public const MAX_ROLES   = 60;

	/** Version of the rules format (the migration of the older per-role lists writes it). */
	public const VERSION = 1;

	/** Bulk actions the REST layer accepts. */
	public const ACTIONS = array( 'hide', 'show', 'roles', 'reset' );

	/**
	 * A widget id with only the allowed characters, or '' when it is not usable.
	 *
	 * @param mixed $id Raw id.
	 */
	public static function clean_id( $id ): string {
		if ( ! is_string( $id ) && ! is_int( $id ) ) {
			return '';
		}
		$id = trim( (string) $id );
		if ( '' === $id || strlen( $id ) > self::MAX_ID || 1 !== preg_match( '/^[A-Za-z0-9_\-:.]+$/', $id ) ) {
			return '';
		}
		return $id;
	}

	/**
	 * Role slugs from a raw value: strings only, unique, optionally limited to the roles the site has.
	 *
	 * @param mixed         $raw         Raw list.
	 * @param string[]|null $valid_roles Known role slugs (null = no check beyond the characters).
	 * @param int           $dropped     Count of the unknown / unusable roles (out).
	 * @return string[]
	 */
	public static function clean_roles( $raw, ?array $valid_roles, int &$dropped = 0 ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $role ) {
			if ( ! is_string( $role ) || 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $role ) || ( null !== $valid_roles && ! in_array( $role, $valid_roles, true ) ) ) {
				++$dropped;
				continue;
			}
			if ( ! in_array( $role, $out, true ) ) {
				$out[] = $role;
			}
		}
		if ( count( $out ) > self::MAX_ROLES ) {
			$out = array_slice( $out, 0, self::MAX_ROLES );
		}
		return $out;
	}

	/**
	 * Boolean from the loose values a form or a file may carry.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function truthy( $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}

	/**
	 * Bring any stored / imported value to the canonical map. Accepts the map and a list of `{id, hidden, roles}`.
	 *
	 * @param mixed         $raw         Raw value.
	 * @param string[]|null $valid_roles Known role slugs (null = skip the check).
	 * @param array         $report      Out: dropped_ids, dropped_roles, truncated.
	 * @return array<string, array{hidden:bool,roles:string[]}>
	 */
	public static function sanitize( $raw, ?array $valid_roles = null, array &$report = array() ): array {
		$report = array(
			'dropped_ids'   => 0,
			'dropped_roles' => 0,
			'truncated'     => 0,
		);
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$pairs = array();
		foreach ( $raw as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				++$report['dropped_ids'];
				continue;
			}
			$pairs[] = array( is_int( $key ) && isset( $entry['id'] ) ? $entry['id'] : $key, $entry );
		}
		$out = array();
		foreach ( $pairs as $pair ) {
			$id = self::clean_id( $pair[0] );
			if ( '' === $id ) {
				++$report['dropped_ids'];
				continue;
			}
			$dropped = 0;
			$entry   = self::entry( self::truthy( $pair[1]['hidden'] ?? false ), self::clean_roles( $pair[1]['roles'] ?? array(), $valid_roles, $dropped ) );
			$report['dropped_roles'] += $dropped;
			if ( null === $entry ) {
				continue; // The defaults are not stored.
			}
			if ( count( $out ) >= self::MAX_ENTRIES && ! isset( $out[ $id ] ) ) {
				++$report['truncated'];
				continue;
			}
			$out[ $id ] = $entry;
		}
		return $out;
	}

	/**
	 * One entry, or null when it holds only the defaults.
	 *
	 * @param bool     $hidden Hidden for everybody.
	 * @param string[] $roles  Allowed roles ([] = all).
	 * @return array{hidden:bool,roles:string[]}|null
	 */
	private static function entry( bool $hidden, array $roles ): ?array {
		return $hidden || $roles ? array(
			'hidden' => $hidden,
			'roles'  => array_values( $roles ),
		) : null;
	}

	/**
	 * Does a user with these roles see the widget?
	 *
	 * @param array<string, array<string, mixed>> $rules      Sanitized rules.
	 * @param string                              $id         Widget id.
	 * @param string[]                            $user_roles Roles of the user.
	 */
	public static function visible( array $rules, string $id, array $user_roles ): bool {
		$rule = $rules[ $id ] ?? null;
		if ( ! is_array( $rule ) ) {
			return true;
		}
		if ( ! empty( $rule['hidden'] ) ) {
			return false;
		}
		$allowed = array_map( 'strval', (array) ( $rule['roles'] ?? array() ) );
		return ! $allowed || (bool) array_intersect( $allowed, array_map( 'strval', $user_roles ) );
	}

	/**
	 * Ids from the rules that a user does not see.
	 *
	 * @param array<string, array<string, mixed>> $rules      Sanitized rules.
	 * @param string[]                            $user_roles Roles of the user.
	 * @return string[]
	 */
	public static function hidden_for( array $rules, array $user_roles ): array {
		$out = array();
		foreach ( array_keys( $rules ) as $id ) {
			if ( ! self::visible( $rules, (string) $id, $user_roles ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * Translate the pre-0.8.4 settings: the global list `hidden_widgets` and the lists hidden per role.
	 *
	 * A widget in the global list is hidden. A widget hidden for some roles becomes shown to the roles that do NOT hide it
	 * (all roles minus the hiding ones); when every role hides it, it is hidden. A user with several roles saw a widget
	 * unless every role of theirs hid it - which is exactly "shown to at least one allowed role".
	 *
	 * @param string[]                $global    Ids hidden for everybody.
	 * @param array<string, string[]> $by_role   Role => ids hidden for that role.
	 * @param string[]                $all_roles Every role slug of the site.
	 * @return array<string, array{hidden:bool,roles:string[]}>
	 */
	public static function from_legacy( array $global, array $by_role, array $all_roles ): array {
		$rules = array();
		foreach ( $global as $id ) {
			$id = self::clean_id( $id );
			if ( '' !== $id ) {
				$rules[ $id ] = array(
					'hidden' => true,
					'roles'  => array(),
				);
			}
		}
		$hiding = array();
		foreach ( $by_role as $role => $ids ) {
			if ( ! in_array( (string) $role, $all_roles, true ) ) {
				continue; // A role the site no longer has.
			}
			foreach ( (array) $ids as $id ) {
				$id = self::clean_id( $id );
				if ( '' !== $id ) {
					$hiding[ $id ][] = (string) $role;
				}
			}
		}
		foreach ( $hiding as $id => $roles ) {
			if ( isset( $rules[ $id ] ) ) {
				continue;
			}
			$allowed = array_values( array_diff( $all_roles, $roles ) );
			$entry   = self::entry( ! $allowed, $allowed );
			if ( null !== $entry ) {
				$rules[ (string) $id ] = $entry;
			}
		}
		return array_slice( $rules, 0, self::MAX_ENTRIES, true );
	}

	/**
	 * Fingerprint of the old settings, so the conversion runs once for the same data.
	 *
	 * @param string[]                $global  Global list.
	 * @param array<string, string[]> $by_role Per role lists.
	 */
	public static function legacy_hash( array $global, array $by_role ): string {
		$by_role = array_filter( array_map( static fn( $ids ) => array_values( array_unique( array_map( 'strval', (array) $ids ) ) ), $by_role ) );
		ksort( $by_role );
		foreach ( $by_role as $role => $ids ) {
			sort( $ids );
			$by_role[ $role ] = $ids;
		}
		$global = array_values( array_unique( array_map( 'strval', $global ) ) );
		sort( $global );
		return $global || $by_role ? md5( (string) json_encode( array( $global, $by_role ) ) ) : '';
	}

	/**
	 * Set `hidden` and / or `roles` of several widgets at once.
	 *
	 * @param array<string, array<string, mixed>> $rules       Current rules.
	 * @param array<int, mixed>                   $items       List of {id, hidden?, roles?}.
	 * @param string[]|null                       $valid_roles Known role slugs.
	 * @return array<string, array{hidden:bool,roles:string[]}>
	 */
	public static function set_items( array $rules, array $items, ?array $valid_roles ): array {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = self::clean_id( $item['id'] ?? null );
			if ( '' === $id ) {
				continue;
			}
			$hidden = (bool) ( $rules[ $id ]['hidden'] ?? false );
			$roles  = (array) ( $rules[ $id ]['roles'] ?? array() );
			if ( array_key_exists( 'hidden', $item ) ) {
				$hidden = self::truthy( $item['hidden'] );
			}
			if ( array_key_exists( 'roles', $item ) ) {
				$roles = self::normalize_roles( self::clean_roles( $item['roles'], $valid_roles ), $valid_roles );
			}
			self::put( $rules, $id, $hidden, $roles );
		}
		return $rules;
	}

	/**
	 * A role set that covers every role of the site means "everybody".
	 *
	 * @param string[]      $roles       Chosen roles.
	 * @param string[]|null $valid_roles Known role slugs.
	 * @return string[]
	 */
	private static function normalize_roles( array $roles, ?array $valid_roles ): array {
		return $valid_roles && ! array_diff( $valid_roles, $roles ) ? array() : $roles;
	}

	/**
	 * Store or drop one entry.
	 *
	 * @param array<string, array<string, mixed>> $rules  Rules (by reference).
	 * @param string                              $id     Widget id.
	 * @param bool                                $hidden Hidden.
	 * @param string[]                            $roles  Allowed roles.
	 */
	private static function put( array &$rules, string $id, bool $hidden, array $roles ): void {
		$entry = self::entry( $hidden, $roles );
		if ( null === $entry ) {
			unset( $rules[ $id ] );
		} else {
			$rules[ $id ] = $entry;
		}
	}

	/**
	 * A bulk operation on several widgets.
	 *
	 * @param array<string, array<string, mixed>> $rules       Current rules.
	 * @param string                              $action      hide | show | roles | reset.
	 * @param array<int, mixed>                   $ids         Widget ids.
	 * @param array<int, mixed>                   $roles       Roles for `roles`.
	 * @param string[]|null                       $valid_roles Known role slugs.
	 * @return array<string, array{hidden:bool,roles:string[]}>
	 */
	public static function apply_bulk( array $rules, string $action, array $ids, array $roles, ?array $valid_roles ): array {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return $rules;
		}
		$clean_roles = self::normalize_roles( self::clean_roles( $roles, $valid_roles ), $valid_roles );
		foreach ( $ids as $raw ) {
			$id = self::clean_id( $raw );
			if ( '' === $id ) {
				continue;
			}
			$hidden = (bool) ( $rules[ $id ]['hidden'] ?? false );
			$have   = (array) ( $rules[ $id ]['roles'] ?? array() );
			switch ( $action ) {
				case 'hide':
					self::put( $rules, $id, true, $have );
					break;
				case 'show':
					self::put( $rules, $id, false, $have );
					break;
				case 'roles':
					self::put( $rules, $id, $hidden, $clean_roles );
					break;
				default: // reset.
					unset( $rules[ $id ] );
			}
		}
		return $rules;
	}

	/* ------------------------------------------------------------------ *
	 * Snapshot of the registered widgets
	 * ------------------------------------------------------------------ */

	/**
	 * Merge the widgets seen by a scan into the snapshot.
	 *
	 * @param array<string, mixed>              $snapshot Stored snapshot (may be empty).
	 * @param array<string, array<string, mixed>> $seen     id => {title, context, priority, source, source_name, guess} of this scan.
	 * @param int                               $now      Time of the scan.
	 * @return array{scan_at:int,widgets:array<string, array<string, mixed>>}
	 */
	public static function merge_snapshot( array $snapshot, array $seen, int $now ): array {
		$widgets = isset( $snapshot['widgets'] ) && is_array( $snapshot['widgets'] ) ? $snapshot['widgets'] : array();
		foreach ( $seen as $raw_id => $info ) {
			$id = self::clean_id( $raw_id );
			if ( '' === $id ) {
				continue;
			}
			$old            = is_array( $widgets[ $id ] ?? null ) ? $widgets[ $id ] : array();
			$widgets[ $id ] = array(
				'id'          => $id,
				'title'       => (string) ( $info['title'] ?? '' ),
				'context'     => (string) ( $info['context'] ?? 'normal' ),
				'priority'    => (string) ( $info['priority'] ?? 'core' ),
				'source'      => (string) ( $info['source'] ?? 'unknown' ),
				'source_name' => (string) ( $info['source_name'] ?? '' ),
				'guess'       => ! empty( $info['guess'] ),
				'first_seen'  => (int) ( $old['first_seen'] ?? $now ),
				'last_seen'   => $now,
			);
		}
		return array(
			'scan_at' => $now,
			'widgets' => $widgets,
		);
	}

	/**
	 * Was the widget missing in the latest scan?
	 *
	 * @param array<string, mixed> $entry   Snapshot entry.
	 * @param int                  $scan_at Time of the latest scan.
	 */
	public static function is_stale( array $entry, int $scan_at ): bool {
		return (int) ( $entry['last_seen'] ?? 0 ) < $scan_at;
	}

	/**
	 * Is the new snapshot different from the stored one in anything but the times? (Dashboard views write only then,
	 * or when the stored scan is older than $max_age.)
	 *
	 * @param array<string, mixed> $old     Stored snapshot.
	 * @param array<string, mixed> $new     Merged snapshot.
	 * @param int                  $max_age Seconds after which a scan is written anyway.
	 */
	public static function needs_write( array $old, array $new, int $max_age ): bool {
		if ( (int) ( $new['scan_at'] ?? 0 ) - (int) ( $old['scan_at'] ?? 0 ) >= $max_age ) {
			return true;
		}
		$strip = static function ( array $snapshot ): array {
			$widgets = (array) ( $snapshot['widgets'] ?? array() );
			foreach ( $widgets as $id => $entry ) {
				unset( $widgets[ $id ]['last_seen'] );
			}
			ksort( $widgets );
			return $widgets;
		};
		return $strip( $old ) !== $strip( $new );
	}

	/**
	 * Where does the code of a widget live? Works on the file path of its callback.
	 *
	 * @param string               $file Absolute path of the file that defines the callback.
	 * @param array<string, string> $dirs plugins, mu_plugins, themes, core (list separated by |), self (this plugin).
	 * @return array{source:string,folder:string} source: core | uxstudio | plugin | mu | theme | unknown.
	 */
	public static function classify_path( string $file, array $dirs ): array {
		$norm = static fn( string $p ): string => rtrim( strtolower( str_replace( '\\', '/', $p ) ), '/' ) . '/';
		$file = strtolower( str_replace( '\\', '/', $file ) );
		if ( '' === $file ) {
			return array(
				'source' => 'unknown',
				'folder' => '',
			);
		}
		$under = static function ( string $dir ) use ( $file, $norm ): ?string {
			$dir = $norm( $dir );
			return '/' !== $dir && 0 === strpos( $file, $dir ) ? substr( $file, strlen( $dir ) ) : null;
		};
		if ( ! empty( $dirs['self'] ) && null !== $under( $dirs['self'] ) ) {
			return array(
				'source' => 'uxstudio',
				'folder' => '',
			);
		}
		foreach ( array( 'plugins' => 'plugin', 'mu_plugins' => 'mu', 'themes' => 'theme' ) as $key => $type ) {
			$rest = empty( $dirs[ $key ] ) ? null : $under( $dirs[ $key ] );
			if ( null !== $rest ) {
				$folder = explode( '/', $rest )[0];
				return array(
					'source' => $type,
					'folder' => 'mu' === $type ? '' : $folder,
				);
			}
		}
		foreach ( explode( '|', (string) ( $dirs['core'] ?? '' ) ) as $core ) {
			if ( '' !== $core && null !== $under( $core ) ) {
				return array(
					'source' => 'core',
					'folder' => '',
				);
			}
		}
		return array(
			'source' => 'unknown',
			'folder' => '',
		);
	}
}

