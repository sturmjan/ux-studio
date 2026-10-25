<?php
/**
 * Contract of one migration source (a foreign plugin whose settings move into UX Studio).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 26.2. A source only READS the foreign plugin (its options, tables,
 * files) and writes to UX Studio. It never enables the target module and never
 * deactivates the plugin - that is a separate, confirmed step (later phase).
 */
interface Source {

	/** Stable id of the source = folder slug of the plugin (`classic-editor`). */
	public function id(): string;

	/**
	 * Folder slugs of the plugins this source covers (usually just the id).
	 *
	 * @return string[]
	 */
	public function plugin_slugs(): array;

	/** Id of the UX Studio module that takes over. */
	public function target_module(): string;

	/** Display name of the plugin. */
	public function label(): string;

	/** Catalog type: `full` (module replaces the plugin) or `partial`. */
	public function match_type(): string;

	/**
	 * What exists on this site.
	 *
	 * @return array{id:string,label:string,module:string,module_name:string,match:string,installed:bool,active:bool,version:string,basename:string,has_data:bool,status:string,summary:array<string,int>}
	 *         status: `active` | `inactive` | `data_only` (plugin gone, its settings are still in the database) | `absent`.
	 */
	public function detect(): array;

	/**
	 * What WILL be transferred and what will NOT.
	 *
	 * @return array{id:string,label:string,module:string,module_name:string,transfer:array<int,array<string,mixed>>,lossy:string[],warnings:string[],notes:string[],counts:array<string,int>}
	 */
	public function plan(): array;

	/**
	 * Options the migration may write (the Snapshot saves their previous values first).
	 *
	 * @return string[]
	 */
	public function target_options(): array;

	/**
	 * UX Studio tables (name WITHOUT the WordPress prefix) the migration may write into. The
	 * Snapshot copies them before the first write and the rollback restores them.
	 *
	 * @return string[]
	 */
	public function target_tables(): array;

	/**
	 * Options the user can choose per source (shown in the wizard), e.g. "also move the history".
	 *
	 * @return array<int, array{key:string,label:string,help:string,default:bool}>
	 */
	public function options(): array;

	/**
	 * One batch of the migration. F2 sources finish in one batch; sources with
	 * data (later phases) use `$state['cursor']` and return `done => false`.
	 *
	 * @param array<string, mixed> $state   Per-source state saved by the Runner (`run_id`, `cursor`, `options`).
	 * @param bool                 $dry_run Compute and report, write nothing.
	 * @return array{done:bool,progress:int,cursor?:mixed,total?:int,processed?:int,staged?:int,items:array<int,array<string,mixed>>,written:int,unchanged:int,errors:string[]}
	 */
	public function migrate( array $state, bool $dry_run ): array;

	/**
	 * Compare the source with what is now in UX Studio.
	 *
	 * @return array<int, array{id:string,label:string,ok:bool,detail:string}>
	 */
	public function verify(): array;

	/**
	 * Optional automated checks for the safe preview and the live test after the switch
	 * (PLAN.md 26.10). Each entry: `id` (unique within the source), `label`, `type` (one of
	 * `redirect_sample`, `comment_form_absent`, `login_slug`, `smtp_test`, `block_editor_off`,
	 * `pages_clean`, `admin_access`) and the parameters of that type; `replaces` lists generic checks the source
	 * makes pointless (`wp-login`). Empty = nothing source specific to check.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function smoke_checks(): array;
}
