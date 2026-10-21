<?php
/**
 * A source whose data is created switched off and goes live at the switch.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Data that changes how the site behaves while the old plugin still runs (redirect rules,
 * code snippets, menu visibility ...) is created INACTIVE by the migration and listed in
 * the staging area. `Staging::apply()` calls `activate()` with the ids it listed.
 */
interface Activatable {

	/**
	 * Switch the staged objects on.
	 *
	 * @param string $kind Kind of object the source registered (`redirect`, `snippet` ...).
	 * @param array<int, int|string> $ids  Target ids as the source recorded them.
	 * @param array<string, mixed>   $data Payload per id the source stored with them (may be empty).
	 * @return int How many objects were switched on.
	 */
	public function activate( string $kind, array $ids, array $data = array() ): int;
}
