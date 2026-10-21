/**
 * Shapes returned by uxstudio/v1/migration/* (PLAN.md 26). Mirrors the PHP side:
 * includes/Core/Migration/{SourceRegistry,AbstractSource,Runner,History}.php.
 */

export type SourceStatus = 'active' | 'inactive' | 'data_only' | 'absent';

/** One entry of GET migration/scan. */
export interface ScanSource {
	id: string;
	label: string;
	/** Target module id, `*` = every module (WP Extended). */
	module: string;
	module_name: string;
	/** `full` = the module replaces the plugin, `partial` = covers a part. */
	match: 'full' | 'partial';
	installed: boolean;
	active: boolean;
	version: string;
	basename: string;
	has_data: boolean;
	status: SourceStatus;
	summary: { settings: number };
}

/** One value a source would write (plan / dry run / result). */
export interface PlanItem {
	module: string;
	key: string;
	label: string;
	secret: boolean;
	from: string;
	to: string;
	/** The effective value differs from what UX Studio has now. */
	changed: boolean;
	/** The key is missing or differs, so a write is needed. */
	write: boolean;
	/** The module did not accept the whole value (e.g. unknown post type). */
	dropped: boolean;
	target: string;
}

/** One entry of POST migration/plan. */
export interface SourcePlan {
	id: string;
	label: string;
	module: string;
	module_name: string;
	status: SourceStatus;
	transfer: PlanItem[];
	lossy: string[];
	warnings: string[];
	notes: string[];
	counts: { transfer: number; changed: number; lossy: number };
}

export type RunSourceStatus = 'pending' | 'running' | 'done' | 'error';

export interface RunSource {
	status: RunSourceStatus;
	label: string;
	module_name: string;
	progress: number;
	written: number;
	unchanged: number;
	items: PlanItem[];
	errors: string[];
}

export interface Check {
	id: string;
	label: string;
	ok: boolean;
	detail: string;
}

/** State of the resumable runner (`uxstudio_migration_run`). */
export interface RunState {
	id: string;
	dry_run: boolean;
	status: 'running' | 'done' | 'rolled_back';
	started: number;
	started_at: string;
	finished: number;
	snapshot: boolean;
	sources: string[];
	per_source: Record< string, RunSource >;
	verify: Record< string, Check[] >;
}

/** One entry of GET migration/runs. */
export interface HistoryRun {
	id: string;
	started_at: string;
	finished: number;
	sources: { id: string; label: string; status: string; written: number }[];
	errors: number;
	snapshot: boolean;
	rolled_back: number;
	snapshot_purged?: boolean;
	rollback_until: string;
	can_rollback: boolean;
}
