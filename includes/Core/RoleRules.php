<?php
/**
 * "Hide X for these roles" rules: how the list of a user is worked out from the lists of their roles.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Same principle as the Menu Organizer: a user with several roles is limited only when EVERY role of theirs limits the thing
 * (one role that may see it is enough). A user without roles gets no hidden items (nothing to base a rule on).
 */
final class RoleRules {

	/**
	 * Ids hidden for a user: hidden by every one of the user's roles.
	 *
	 * @param array<string, string[]> $by_role Role => ids hidden for that role.
	 * @param string[]                $roles   Roles of the user.
	 * @return string[]
	 */
	public static function hidden_for( array $by_role, array $roles ): array {
		if ( ! $roles ) {
			return array();
		}
		$hidden = null;
		foreach ( $roles as $role ) {
			$list   = array_map( 'strval', (array) ( $by_role[ (string) $role ] ?? array() ) );
			$hidden = null === $hidden ? $list : array_values( array_intersect( $hidden, $list ) );
		}
		return array_values( array_unique( (array) $hidden ) );
	}

	/**
	 * Does a user with these roles belong to a role list where an empty list means everybody?
	 *
	 * @param string[] $allowed Roles the thing is for ([] = everybody).
	 * @param string[] $roles   Roles of the user.
	 */
	public static function applies_to( array $allowed, array $roles ): bool {
		$allowed = array_map( 'strval', $allowed );
		return ! $allowed || (bool) array_intersect( $allowed, array_map( 'strval', $roles ) );
	}
}
