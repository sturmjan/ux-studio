<?php
/**
 * MCP prompts of the UX Studio server.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * The text is for the model, so it stays English.
 */
final class Prompts {

	/**
	 * Prompt list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list(): array {
		return array(
			array(
				'name'        => 'migrate-from-plugins',
				'title'       => 'Migrate from other plugins to UX Studio',
				'description' => 'Walks through moving the settings and data of other plugins (SEO, redirects, security, SMTP, ...) into UX Studio: scan, plan, rehearsal, real run, verification, safe preview, switch. Stops for explicit user confirmation before anything is switched on.',
				'arguments'   => array(
					array(
						'name'        => 'plugins',
						'description' => 'Optional: plugins or source ids the user wants to move (comma separated). Empty = everything the scan finds.',
						'required'    => false,
					),
				),
			),
		);
	}

	/**
	 * One prompt.
	 *
	 * @param string               $name      Prompt name.
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $name, array $arguments ): ?array {
		if ( 'migrate-from-plugins' !== $name ) {
			return null;
		}
		$wanted = trim( (string) ( $arguments['plugins'] ?? '' ) );
		$text   = <<<'TXT'
You are migrating this WordPress site from other plugins to UX Studio. Work in this order and report to the user after each step. UX Studio never changes the source plugins' data, a real run only COPIES into a staging area, and nothing goes live before step 8.

1. Orientation. Call uxstudio-site-overview, then uxstudio-overlaps-list (plugins that do the same job as a UX Studio module) and uxstudio-migration-scan (what can be moved).
2. Choice. Tell the user what you found and ask which sources to move. Do not guess.
3. Plan. Call uxstudio-migration-plan for the chosen sources and explain, per source, what WILL and what will NOT be transferred. Anything lossy must be said out loud.
4. Rehearsal. Call uxstudio-migration-run with dry_run=true (the default). Read the result; stop and report on any error.
5. Real run. Ask the user for a go-ahead, then call uxstudio-migration-run with dry_run=false. If the status is "running", call it again with continue=true until it is done. Keep the run id.
6. Verification. Call uxstudio-migration-verify for the sources and report every difference. Differences are never ignored; the user decides whether they matter.
7. Safe preview. Call uxstudio-migration-preview-start with the run id, then uxstudio-migration-smoke with mode=preview and check="all". Show the failures. Visitors still see the old setup during the preview; the user can open enter_url to look at the site without the old plugins.
8. Switch. STOP and ask the user explicitly. Call uxstudio-migration-switch-plan and read out which plugins will be deactivated, which modules enabled and whether the login address changes (then the user must save the new address first and you pass ack_login=true). Only after a clear "yes" call uxstudio-migration-switch with confirm=true. Never use override=true unless the user asks for it in so many words. Afterwards run uxstudio-migration-smoke with mode=live and check="all".
9. Undo. If anything is wrong, offer uxstudio-migration-revert (needs confirm=true again). uxstudio-migration-rollback restores the snapshot taken before the real run.

Rules: never set confirm=true or override=true on your own initiative; secrets (API keys, passwords) are never shown to you and cannot be changed without an admin token; if a call is refused, read the message and report it instead of retrying with other parameters.
TXT;
		if ( '' !== $wanted ) {
			$text .= "\n\nThe user wants to move: " . $wanted . '.';
		}
		return array(
			'description' => 'Step-by-step migration from other plugins to UX Studio.',
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}
}
