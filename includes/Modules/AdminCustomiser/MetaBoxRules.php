<?php
/**
 * Meta boxes of the edit screens hidden per post type and role.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\RoleRules;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The settings are one multi-select per role (`hidden_metaboxes_<role>`, values `post_type|box_id`), so the generic settings
 * form edits them. The list of boxes a screen has is not known up front (plugins add their own), so it is captured from
 * `$wp_meta_boxes` whenever an edit screen is opened and kept in an option (the catalog): union of everything seen so far plus
 * the core boxes, so a rule never loses a box that a later request does not show.
 *
 * Hiding is done with `remove_meta_box()` on `do_meta_boxes`, after the capture, so a hidden box stays in the catalog and
 * can be switched back on. The Publish box (`submitdiv`) is not offered: hiding it would stop the role from publishing.
 * A user with several roles has a box hidden only when every one of their roles hides it (the same "one role that may" rule
 * as the menu).
 */
final class MetaBoxRules {

	public const CATALOG_OPTION = 'uxstudio_metabox_catalog';

	public const KEY_PREFIX = 'hidden_metaboxes_';

	/** Boxes that can never be hidden. */
	private const NEVER = array( 'submitdiv' );

	/** Largest number of boxes kept per post type (a runaway plugin that registers unique ids cannot grow the option forever). */
	private const MAX_PER_TYPE = 200;

	/** @var array<string, bool> Post types handled in this request. */
	private static array $done = array();

	/**
	 * Hook it up (edit screens only; the hook is cheap elsewhere).
	 */
	public static function register(): void {
		add_action( 'do_meta_boxes', array( self::class, 'on_meta_boxes' ), 9999, 3 );
	}

	/**
	 * Boxes WordPress itself puts on the edit screens (known before anyone opens an edit screen).
	 *
	 * @return array<string, array<string, string>> Post type => id => title.
	 */
	public static function core_catalog(): array {
		$common = array(
			'postcustom'       => __( 'Custom Fields', 'ux-studio' ),
			'commentstatusdiv' => __( 'Discussion', 'ux-studio' ),
			'commentsdiv'      => __( 'Comments', 'ux-studio' ),
			'slugdiv'          => __( 'Slug', 'ux-studio' ),
			'authordiv'        => __( 'Author', 'ux-studio' ),
			'revisionsdiv'     => __( 'Revisions', 'ux-studio' ),
			'postimagediv'     => __( 'Featured image', 'ux-studio' ),
			'postexcerpt'      => __( 'Excerpt', 'ux-studio' ),
		);
		return array(
			'post' => array_merge(
				$common,
				array(
					'trackbacksdiv'    => __( 'Send Trackbacks', 'ux-studio' ),
					'categorydiv'      => __( 'Categories', 'ux-studio' ),
					'tagsdiv-post_tag' => __( 'Tags', 'ux-studio' ),
					'formatdiv'        => __( 'Format', 'ux-studio' ),
				)
			),
			'page' => array_merge(
				$common,
				array(
					'pageparentdiv' => __( 'Page Attributes', 'ux-studio' ),
				)
			),
		);
	}

	/**
	 * Everything known: core boxes plus the captured ones.
	 *
	 * @return array<string, array<string, string>> Post type => id => title.
	 */
	public static function catalog(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::CATALOG_OPTION, array() ) : array();
		$out    = self::core_catalog();
		foreach ( (array) $stored as $type => $boxes ) {
			foreach ( (array) $boxes as $id => $title ) {
				$out[ (string) $type ][ (string) $id ] = '' !== (string) $title ? (string) $title : (string) $id;
			}
		}
		foreach ( $out as $type => $boxes ) {
			foreach ( self::NEVER as $never ) {
				unset( $out[ $type ][ $never ] );
			}
		}
		return $out;
	}

	/**
	 * Options of the per-role multi-selects: `post_type|box_id` => "Posts: Author".
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		$out = array();
		foreach ( self::catalog() as $type => $boxes ) {
			$label = (string) $type;
			if ( function_exists( 'get_post_type_object' ) ) {
				$object = get_post_type_object( (string) $type );
				if ( $object && ! empty( $object->labels->name ) ) {
					$label = (string) $object->labels->name;
				}
			}
			foreach ( $boxes as $id => $title ) {
				$out[ $type . '|' . $id ] = $label . ': ' . $title;
			}
		}
		return $out;
	}

	/**
	 * Settings key of a role.
	 *
	 * @param string $role Role id.
	 */
	public static function key( string $role ): string {
		return self::KEY_PREFIX . $role;
	}

	/**
	 * Schema fields: one multi-select per role of the site.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function schema(): array {
		$fields  = array();
		$options = self::options();
		foreach ( MenuOrganizer::role_list() as $role => $name ) {
			$fields[] = array(
				'key'     => self::key( (string) $role ),
				'section' => __( 'Meta boxes', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => sprintf(
					/* translators: %s: role name. */
					__( 'Hide meta boxes on edit screens for the role: %s', 'ux-studio' ),
					$name
				),
				'help'    => __( 'The list is filled by the edit screens you open (open the edit screen of a post type once to see the boxes plugins add). The Publish box can not be hidden. A user with several roles has a box hidden only when every role hides it.', 'ux-studio' ),
				'options' => $options,
				'default' => array(),
			);
		}
		return $fields;
	}

	/**
	 * Box ids (`post_type|box_id`) hidden for a user: hidden by every one of the user's roles. Pure.
	 *
	 * @param array<string, string[]> $by_role Role => `post_type|box_id` list.
	 * @param string[]                $roles   Roles of the user.
	 * @return string[]
	 */
	public static function hidden_for( array $by_role, array $roles ): array {
		return RoleRules::hidden_for( $by_role, $roles );
	}

	/**
	 * Box ids of one post type hidden for a user.
	 *
	 * @param array<string, string[]> $by_role  Role => `post_type|box_id` list.
	 * @param string[]                $roles    Roles of the user.
	 * @param string                  $post_type Post type.
	 * @return string[] Box ids.
	 */
	public static function boxes_to_hide( array $by_role, array $roles, string $post_type ): array {
		$out    = array();
		$prefix = $post_type . '|';
		foreach ( self::hidden_for( $by_role, $roles ) as $entry ) {
			if ( 0 === strpos( $entry, $prefix ) ) {
				$id = substr( $entry, strlen( $prefix ) );
				if ( '' !== $id && ! in_array( $id, self::NEVER, true ) ) {
					$out[] = $id;
				}
			}
		}
		return $out;
	}

	/**
	 * Role => hidden list, read from the module settings.
	 *
	 * @param Settings $settings Module settings.
	 * @return array<string, string[]>
	 */
	public static function rules( Settings $settings ): array {
		$out = array();
		foreach ( array_keys( MenuOrganizer::role_list() ) as $role ) {
			$list = $settings->get( self::key( (string) $role ), array() );
			if ( is_array( $list ) && $list ) {
				$out[ (string) $role ] = array_map( 'strval', $list );
			}
		}
		return $out;
	}

	/**
	 * Merge the boxes of a post type into a catalog (pure).
	 *
	 * @param array<string, array<string, string>> $catalog   Stored catalog.
	 * @param string                               $post_type Post type.
	 * @param array<string, string>                $seen      Box id => title seen now.
	 * @return array<string, array<string, string>>
	 */
	public static function merge_catalog( array $catalog, string $post_type, array $seen ): array {
		$known = (array) ( $catalog[ $post_type ] ?? array() );
		foreach ( $seen as $id => $title ) {
			$id = (string) $id;
			if ( '' === $id || in_array( $id, self::NEVER, true ) ) {
				continue;
			}
			if ( ! isset( $known[ $id ] ) && count( $known ) >= self::MAX_PER_TYPE ) {
				continue;
			}
			$known[ $id ] = (string) $title;
		}
		$catalog[ $post_type ] = $known;
		return $catalog;
	}

	/**
	 * `do_meta_boxes` (priority 9999): remember what the screen has, then hide what the user's roles hide.
	 *
	 * @param mixed $post_type Post type (or a screen for other screens).
	 * @param mixed $context   Context being filled.
	 * @param mixed $post      Post.
	 */
	public static function on_meta_boxes( $post_type, $context = '', $post = null ): void {
		global $wp_meta_boxes;

		if ( ! is_string( $post_type ) || '' === $post_type || isset( self::$done[ $post_type ] ) || ! is_admin() || ! post_type_exists( $post_type ) ) {
			return;
		}
		if ( ! isset( $wp_meta_boxes[ $post_type ] ) || ! is_array( $wp_meta_boxes[ $post_type ] ) ) {
			return;
		}
		self::$done[ $post_type ] = true;

		// 1) Capture (before anything is removed, so a hidden box stays on the list).
		$seen = array();
		foreach ( $wp_meta_boxes[ $post_type ] as $priorities ) {
			foreach ( (array) $priorities as $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( is_array( $box ) ) {
						$title                = isset( $box['title'] ) ? trim( wp_strip_all_tags( (string) $box['title'] ) ) : '';
						$seen[ (string) $id ] = '' !== $title ? $title : (string) $id;
					}
				}
			}
		}
		if ( current_user_can( 'edit_posts' ) && $seen ) {
			$stored = get_option( self::CATALOG_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$merged = self::merge_catalog( $stored, $post_type, $seen );
			if ( $merged !== $stored ) {
				update_option( self::CATALOG_OPTION, $merged, false );
			}
		}

		// 2) Hide.
		$user = wp_get_current_user();
		$ids  = self::boxes_to_hide( self::rules( new Settings( 'uxstudio_admin_customiser' ) ), array_map( 'strval', (array) $user->roles ), $post_type );
		foreach ( $ids as $id ) {
			foreach ( array( 'normal', 'advanced', 'side' ) as $ctx ) {
				remove_meta_box( $id, $post_type, $ctx );
			}
		}
	}
}
