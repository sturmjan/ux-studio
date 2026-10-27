/**
 * Shapes of the transfer REST routes (uxstudio/v1/transfer/*, includes/Core/Transfer/RestController.php).
 */

export interface SnapshotInfo {
	available: boolean;
	time: number;
	expires: number;
	modules: string[];
	user_id: number;
}

export interface TransferModule {
	id: string;
	name: string;
	description: string;
	group: string;
	icon: string;
	enabled: boolean;
	parent: string | null;
	parent_name: string;
	/** Number of settings that travel as ordinary settings. */
	settings: number;
	/** Number of portable secrets that have a value on this site. */
	secrets: number;
	data: null | {
		kind: string;
		label: string;
		count: number;
		allowed: boolean;
		needs: string;
	};
}

export interface TransferStatus {
	modules: TransferModule[];
	sodium: boolean;
	min_passphrase: number;
	can_unfiltered_html: boolean;
	max_bytes: number;
	snapshot: SnapshotInfo;
	format: string;
	format_version: number;
	non_portable: {
		keys: Record< string, string[] >;
		options: string[];
	};
}

export interface ExportOptions {
	include_enabled: boolean;
	include_data: boolean;
	include_secrets: boolean;
	passphrase: string;
	passphrase_confirm: string;
}

export interface ExportSummary {
	modules: number;
	settings: number;
	items: number;
	secrets: number;
	manual: number;
	secrets_encrypted: boolean;
	warnings: string[];
}

export interface ExportResult {
	filename: string;
	size: number;
	download_url: string;
	/** The file text, only for small files. */
	content: string | null;
	summary: ExportSummary;
}

/* ── Import ── */

export interface DiffRow {
	key: string;
	label: string;
	section: string;
	old: string;
	new: string;
	secret: boolean;
	sensitive: boolean;
}

export interface KeyNote {
	key: string;
	label: string;
}

export interface PreviewMeasure {
	key: string;
	label: string;
	code: string;
	message: string;
}

export interface DataPlan {
	kind: string;
	label: string;
	blocked: string;
	items: { key: string; label: string; action: 'create' | 'update' | 'same'; note: string }[];
	rejected: { label: string; message: string }[];
	warnings: string[];
	counts: { create: number; update: number; same: number };
}

export interface PreviewModule {
	id: string;
	name: string;
	icon: string;
	group: string;
	known: boolean;
	blocked: string;
	selectable: boolean;
	has_work: boolean;
	writable: boolean;
	enabled: {
		file: boolean | null;
		current: boolean;
		change: 'none' | 'enable' | 'disable' | 'keep';
		overlap: { status: 'ok' | 'confirm' | 'blocked'; plugins: string[] };
		holds_data: boolean;
	};
	changes: DiffRow[];
	same: number;
	unknown: string[];
	non_portable: KeyNote[];
	rejected: ( KeyNote & { message: string } )[];
	skipped: ( KeyNote & { code: string; message: string } )[];
	dropped: ( KeyNote & { count: number } )[];
	media_missing: KeyNote[];
	secrets: ( KeyNote & { has_current: boolean } )[];
	data: DataPlan | null;
	needs_manual: KeyNote[];
	warnings: string[];
	measures: PreviewMeasure[];
}

export interface PreviewResponse {
	file: {
		site: { name: string; host: string };
		exported_at: string;
		plugin_version: string;
		format_version: number;
		options: { include_enabled: boolean; include_data: boolean; has_secrets: boolean };
	};
	warnings: { code: string; message: string }[];
	secrets_state: 'none' | 'needs_passphrase' | 'decrypted';
	sodium: boolean;
	measures: ( PreviewMeasure & { module: string; module_name: string } )[];
	safety_rules: string[];
	modules: PreviewModule[];
	totals: { changes: number; same: number; items: number; modules: number };
	snapshot: SnapshotInfo;
}

export interface ImportOptions {
	mode: 'merge' | 'replace';
	apply_enabled: boolean;
	disable_with_data: boolean;
	allow_maintenance: boolean;
}

export interface ApplyResultRow {
	id: string;
	name: string;
	status: 'ok' | 'partial' | 'skipped' | 'failed';
	reason: string;
	enabled: string;
	written: number;
	skipped: Record< string, string >;
	warnings: string[];
	data: null | {
		label: string;
		created: number;
		updated: number;
		unchanged: number;
		failed: { label: string; message: string }[];
	};
}

export interface LoginState {
	touched: boolean;
	lock_active: boolean;
	pending: boolean;
	slug: string;
	url: string;
	reachable: boolean;
	reverted: boolean;
}

export interface ApplyResponse {
	results: ApplyResultRow[];
	summary: { ok: number; skipped: number; failed: number; changes: number };
	snapshot: SnapshotInfo;
	login: LoginState;
}

export interface UndoResponse {
	modules_off: string[];
	restored: { restored: number; deleted: number };
	errors: string[];
	login: LoginState;
}
