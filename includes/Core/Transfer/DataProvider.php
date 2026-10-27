<?php
/**
 * A module that has portable content (definitions, never logs or submissions) opts in with this interface.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * The content of a module that is more than settings: form definitions, redirect rules, snippets, popups,
 * locations, categories. The provider knows how to write it through the module's own API (so validation,
 * sanitisation, cache refresh and the activity log of the module all run), and how to take it back.
 *
 * Rules every provider follows:
 *  - items carry no database ids, no counters, no statistics, no personal data, no secrets;
 *  - items are identified by a natural key (a title, a source URL), so an import updates the same thing
 *    instead of piling up copies;
 *  - an import only creates and updates; it never deletes anything;
 *  - undo removes what the import created and puts the previous version back on what it updated.
 */
interface DataProvider {

	/** Module id the data belongs to. */
	public function module(): string;

	/** Key of the list in the file (`data` of the module). */
	public function kind(): string;

	/** Plural name of the content for the UI ("Forms", "Redirect rules"). */
	public function label(): string;

	/** Extra capability needed to export AND import this content ('' = none beyond manage_options). */
	public function capability(): string;

	/** Why the content cannot be imported right now ('' = fine): a missing table, a blocked file editor. */
	public function blocked_reason(): string;

	/** Number of items on this site. */
	public function count(): int;

	/**
	 * Items of this site in the portable shape.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function export(): array;

	/**
	 * What an import of these items would do (nothing is written).
	 *
	 * @param array<int, mixed> $incoming Items from the file.
	 * @return array{items:array<int,array{key:string,label:string,action:string,note:string}>,rejected:array<int,array{label:string,message:string}>,warnings:string[]}
	 *         action: create | update | same.
	 */
	public function plan( array $incoming ): array;

	/**
	 * Write the items.
	 *
	 * @param array<int, mixed> $incoming Items from the file.
	 * @return array{created:array<int,mixed>,previous:array<int,array<string,mixed>>,failed:array<int,array{label:string,message:string}>,unchanged:int}
	 */
	public function import( array $incoming ): array;

	/**
	 * Take an import back.
	 *
	 * @param array<int, mixed>               $created  References returned by import() in `created`.
	 * @param array<int, array<string,mixed>> $previous Items returned by import() in `previous`.
	 * @return array{removed:int,restored:int,failed:int}
	 */
	public function undo( array $created, array $previous ): array;
}
