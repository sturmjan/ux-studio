<?php
/**
 * Human labels for the identifiers the activity log stores (module id, action code, object type), so no screen has to
 * show `activity-log`, `settings_import_undone` or `user_register` as they are. Unknown codes fall back to a readable
 * form of the code itself ("gmail_connect" becomes "Gmail connect"), so a new module never shows an empty cell.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

final class Labels {

	/**
	 * Readable form of a code: separators become spaces, first letter upper case.
	 *
	 * @param string $code Identifier such as "gmail_connect".
	 */
	public static function humanize( string $code ): string {
		$text = trim( str_replace( array( '_', '-', '.' ), ' ', $code ) );
		if ( '' === $text ) {
			return '';
		}
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/**
	 * Name of a module by its id (the language of the current user), or a readable form of the id.
	 *
	 * @param string $id Module id as stored in the log.
	 */
	public static function module( string $id ): string {
		if ( 'core' === $id ) {
			return __( 'UX Studio (core)', 'ux-studio' );
		}
		if ( 'mcp' === $id ) {
			return __( 'MCP server', 'ux-studio' );
		}
		$names = self::module_names();
		return $names[ $id ] ?? self::humanize( $id );
	}

	/**
	 * Label of an action code.
	 *
	 * @param string $code Action code as stored in the log.
	 */
	public static function action( string $code ): string {
		$map = self::actions();
		return $map[ $code ] ?? self::humanize( $code );
	}

	/**
	 * Label of an object type.
	 *
	 * @param string $type Object type as stored in the log.
	 */
	public static function object_type( string $type ): string {
		$map = array(
			'post'       => __( 'Post', 'ux-studio' ),
			'page'       => __( 'Page', 'ux-studio' ),
			'user'       => __( 'User', 'ux-studio' ),
			'plugin'     => __( 'Plugin', 'ux-studio' ),
			'theme'      => __( 'Theme', 'ux-studio' ),
			'term'       => __( 'Category or tag', 'ux-studio' ),
			'attachment' => __( 'Media file', 'ux-studio' ),
			'comment'    => __( 'Comment', 'ux-studio' ),
			'module'     => __( 'Module', 'ux-studio' ),
			'migration'  => __( 'Migration', 'ux-studio' ),
		);
		return $map[ $type ] ?? self::humanize( $type );
	}

	/**
	 * Add the readable fields to a log row (the raw ones stay, so filters keep working).
	 *
	 * @param array<string, mixed> $row Row with module, action and optionally object_type / user_id.
	 * @return array<string, mixed>
	 */
	public static function decorate( array $row ): array {
		$row['module_label'] = self::module( (string) ( $row['module'] ?? '' ) );
		$row['action_label'] = self::action( (string) ( $row['action'] ?? '' ) );
		if ( array_key_exists( 'object_type', $row ) ) {
			$row['object_label'] = self::object_type( (string) $row['object_type'] );
		}
		if ( array_key_exists( 'user_id', $row ) ) {
			$row['user_name'] = (int) $row['user_id'] > 0 ? (string) get_the_author_meta( 'display_name', (int) $row['user_id'] ) : '';
		}
		return $row;
	}

	/**
	 * Module id => name, from the registry (one lookup per request).
	 *
	 * @return array<string, string>
	 */
	private static function module_names(): array {
		static $names = null;
		if ( null !== $names ) {
			return $names;
		}
		$names  = array();
		$plugin = class_exists( '\UxStudio\Plugin' ) ? \UxStudio\Plugin::instance() : null;
		if ( $plugin && isset( $plugin->modules ) ) {
			foreach ( $plugin->modules->all() as $id => $meta ) {
				$name = (string) ( $meta['name'] ?? '' );
				// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- module names come from meta.json, as in Core\Rest.
				$names[ (string) $id ] = '' !== $name ? translate( $name, 'ux-studio' ) : self::humanize( (string) $id );
			}
		}
		return $names;
	}

	/**
	 * Action code => label.
	 *
	 * @return array<string, string>
	 */
	private static function actions(): array {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array(
			'login'                          => __( 'Signed in', 'ux-studio' ),
			'logout'                         => __( 'Signed out', 'ux-studio' ),
			'post_publish'                   => __( 'Published', 'ux-studio' ),
			'post_draft'                     => __( 'Saved as draft', 'ux-studio' ),
			'post_pending'                   => __( 'Sent for review', 'ux-studio' ),
			'post_future'                    => __( 'Scheduled', 'ux-studio' ),
			'post_private'                   => __( 'Made private', 'ux-studio' ),
			'post_trash'                     => __( 'Moved to trash', 'ux-studio' ),
			'create'                         => __( 'Created', 'ux-studio' ),
			'update'                         => __( 'Updated', 'ux-studio' ),
			'upsert'                         => __( 'Saved', 'ux-studio' ),
			'delete'                         => __( 'Deleted', 'ux-studio' ),
			'upload'                         => __( 'Uploaded', 'ux-studio' ),
			'comment'                        => __( 'Commented', 'ux-studio' ),
			'user_register'                  => __( 'User registered', 'ux-studio' ),
			'profile_update'                 => __( 'Profile updated', 'ux-studio' ),
			'role_change'                    => __( 'Role changed', 'ux-studio' ),
			'activated'                      => __( 'Activated', 'ux-studio' ),
			'deactivated'                    => __( 'Deactivated', 'ux-studio' ),
			'switch_theme'                   => __( 'Theme switched', 'ux-studio' ),
			'export'                         => __( 'Exported', 'ux-studio' ),
			'send'                           => __( 'Sent', 'ux-studio' ),
			'sync'                           => __( 'Synchronized', 'ux-studio' ),
			'settings_exported'              => __( 'Settings exported', 'ux-studio' ),
			'settings_imported'              => __( 'Settings imported', 'ux-studio' ),
			'settings_import_undone'         => __( 'Settings import undone', 'ux-studio' ),
			'overlap_ignored'                => __( 'Plugin conflict ignored', 'ux-studio' ),
			'overlap_unignored'              => __( 'Plugin conflict shown again', 'ux-studio' ),
			'overlap_plugin_activated'       => __( 'Conflicting plugin activated', 'ux-studio' ),
			'migration_started'              => __( 'Migration started', 'ux-studio' ),
			'migration_finished'             => __( 'Migration finished', 'ux-studio' ),
			'migration_dry_run'              => __( 'Migration trial run', 'ux-studio' ),
			'migration_applied'              => __( 'Migration applied', 'ux-studio' ),
			'migration_rolled_back'          => __( 'Migration rolled back', 'ux-studio' ),
			'migration_preview_started'      => __( 'Migration preview started', 'ux-studio' ),
			'migration_preview_ended'        => __( 'Migration preview ended', 'ux-studio' ),
			'migration_switch_started'       => __( 'Switch to UX Studio started', 'ux-studio' ),
			'migration_switch_reverted'      => __( 'Switch to UX Studio reverted', 'ux-studio' ),
			'migration_switch_auto_reverted' => __( 'Switch to UX Studio reverted automatically', 'ux-studio' ),
			'migration_module_enabled'       => __( 'Module turned on by migration', 'ux-studio' ),
			'starter_reverted'               => __( 'Starter setup reverted', 'ux-studio' ),
			'token_revoked'                  => __( 'Access token revoked', 'ux-studio' ),
		);
		return $map;
	}
}
