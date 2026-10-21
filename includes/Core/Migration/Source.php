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
	 * One batch of the migration. F2 sources finish in one batch; sources with
	 * data (later phases) use `$state['cursor']` and return `done => false`.
	 *
	 * @param array<string, mixed> $state   Per-source state saved by the Runner (`cursor`, ...).
	 * @param bool                 $dry_run Compute and report, write nothing.
	 * @return array{done:bool,progress:int,cursor?:mixed,items:array<int,array<string,mixed>>,written:int,unchanged:int,errors:string[]}
	 */
	public function migrate( array $state, bool $dry_run ): array;

	/**
	 * Compare the source with what is now in UX Studio.
	 *
	 * @return array<int, array{id:string,label:string,ok:bool,detail:string}>
	 */
	public function verify(): array;
}
