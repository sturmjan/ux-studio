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
	summary: { settings: number; data: DataRow[] };
}

/** One count of what the foreign plugin holds ("338 redirects", "12 of them switched off"). */
export interface DataRow {
	key: string;
	label: string;
	count: number;
}

/** An extra choice a source offers (e.g. "Also move the history"). */
export interface SourceOption {
	key: string;
	label: string;
	help: string;
	default: boolean;
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
	/** The module is already on: the value waits in the staging area until the switch. */
	staged?: boolean;
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
	/** Objects (rules, snippets, folders ...) with their counts. */
	data: DataRow[];
	options: SourceOption[];
	/** The target module is on: values and data are only prepared, they start at the switch. */
	staged: boolean;
	lossy: string[];
	warnings: string[];
	notes: string[];
	counts: { transfer: number; changed: number; lossy: number; data: number };
}

export type RunSourceStatus = 'pending' | 'running' | 'done' | 'error';

export interface RunSource {
	status: RunSourceStatus;
	label: string;
	module_name: string;
	progress: number;
	/** Objects handled so far / in total (sources that go through their data in batches). */
	processed?: number;
	total?: number;
	options?: Record< string, boolean >;
	written: number;
	unchanged: number;
	/** How many of the written values were only prepared (module already on). */
	staged?: number;
	items: PlanItem[];
	errors: string[];
}

export interface Check {
	id: string;
	label: string;
	ok: boolean;
	detail: string;
	/** Correct, but prepared only: takes effect after the switch. */
	staged?: boolean;
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
	sources: { id: string; label: string; status: string; written: number; staged?: number }[];
	errors: number;
	snapshot: boolean;
	rolled_back: number;
	snapshot_purged?: boolean;
	rollback_until: string;
	can_rollback: boolean;
	/** Values and data prepared for the switch (not yet applied). */
	staging?: { settings: number; secrets: number; activations: number; modules: string[] };
	applied?: number;
	/** The switch (F4): when it happened, when it was undone, and whether it can be undone now. */
	switched?: number;
	switch_reverted?: number;
	switch_active?: boolean;
	can_revert_switch?: boolean;
	revert_until?: string;
	/** Totals of the last safe-preview tests. */
	preview?: { at: number; counts: SmokeCounts } | null;
	switch_live?: SmokeCounts | null;
}

/* ── Safe preview and switch (F4) ── */

export type SmokeStatus = 'ok' | 'warn' | 'fail' | 'info';

export interface SmokeCounts {
	ok: number;
	warn: number;
	fail: number;
	info: number;
}

/** One test of the plan (POST migration/smoke without `check`). */
export interface SmokePlanItem {
	id: string;
	label: string;
	/** `site` or the id of the source the test belongs to. */
	group: string;
}

export interface SmokeResult extends SmokePlanItem {
	status: SmokeStatus;
	detail: string;
	ms: number;
}

export interface PreviewAvailability {
	ok: boolean;
	reason: string;
	path: string;
}

export interface PreviewEntry {
	run_id: string;
	expires: number;
	expires_at: string;
	plugins: string[];
	modules: { id: string; name: string }[];
	results: Record< string, SmokeResult >;
	enter_url: string;
	exit_url: string;
}

/** GET / POST migration/preview. */
export interface PreviewPayload {
	available: PreviewAvailability;
	entry: PreviewEntry | null;
	plan?: SmokePlanItem[];
}

/** GET migration/switch-plan. */
export interface SwitchPlan {
	run_id: string;
	switched: boolean;
	plugins: { basename: string; name: string; source: string }[];
	modules: { id: string; name: string; enabled: boolean }[];
	staging: { settings: number; secrets: number; activations: number; modules: string[] };
	login: { changes: boolean; url: string; slug: string; now_url: string };
	errors: number;
	preview: { available: PreviewAvailability; tested: boolean; fresh: boolean; complete: boolean; counts: SmokeCounts; total: number };
	kill_switch: string;
	revert_days: number;
}

/** POST migration/switch. */
export interface SwitchResult {
	run_id: string;
	enabled: string[];
	deactivated: string[];
	errors: string[];
	revert_until: string;
	live_plan: SmokePlanItem[];
	runs: HistoryRun[];
}

/** The migrated run the preview and the switch work on. */
export interface WizardTarget {
	id: string;
	sources: { id: string; label: string }[];
}
