<?php
/**
 * Which editor opens a post when users may choose (no WordPress calls, covered by tests/content-b3.php).
 *
 * The rules and the stored values are those of the Classic Editor plugin, so a site that used its "allow users to
 * switch editors" mode keeps every choice: the user option `classic-editor-settings` (`classic` | `block`) and the post
 * meta `classic-editor-remember` (`classic-editor` | `block-editor`).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ClassicEditor;

defined( 'ABSPATH' ) || exit;

final class EditorChoice {

	/** User option (per site, stored by update_user_option) with the default editor of a user. */
	public const USER_OPTION = 'classic-editor-settings';

	/** Post meta that remembers the editor a post was last opened in. */
	public const REMEMBER_META = 'classic-editor-remember';

	/**
	 * The editor a user gets by default.
	 *
	 * @param bool   $site_classic The site default for the post type is the classic editor.
	 * @param string $user_choice  `classic`, `block` or anything else = follow the site.
	 */
	public static function default_is_classic( bool $site_classic, string $user_choice ): bool {
		if ( 'classic' === $user_choice ) {
			return true;
		}
		if ( 'block' === $user_choice ) {
			return false;
		}
		return $site_classic;
	}

	/**
	 * Whether a post opens in the classic editor.
	 *
	 * @param array{default_classic:bool,new_post:bool,forget:bool,qs_classic:bool,remembered:string,has_blocks:bool} $ctx
	 *        default_classic: the editor of the user (see default_is_classic()); new_post: "Add new" (no post yet or an
	 *        auto draft); forget: `?classic-editor__forget` (the user picked an editor explicitly, so what the post
	 *        remembers is ignored); qs_classic: `?classic-editor` is in the address; remembered: the post meta;
	 *        has_blocks: the content contains blocks.
	 */
	public static function is_classic( array $ctx ): bool {
		if ( $ctx['new_post'] ) {
			return ( $ctx['default_classic'] && ! $ctx['forget'] ) || ( $ctx['qs_classic'] && $ctx['forget'] );
		}

		if ( ! $ctx['forget'] ) {
			if ( 'classic-editor' === $ctx['remembered'] ) {
				return true;
			}
			if ( 'block-editor' === $ctx['remembered'] ) {
				return false;
			}
			// Nothing remembered: a post without blocks is edited in the classic editor, as it was written there.
			return ! $ctx['has_blocks'];
		}

		return $ctx['qs_classic'];
	}

	/**
	 * Clean a value that arrives from the profile form.
	 *
	 * @param string $value Raw value.
	 * @return string `classic`, `block` or '' (follow the site).
	 */
	public static function clean_choice( string $value ): string {
		return in_array( $value, array( 'classic', 'block' ), true ) ? $value : '';
	}
}
