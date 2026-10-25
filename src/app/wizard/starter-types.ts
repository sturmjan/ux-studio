/**
 * Shapes returned by uxstudio/v1/onboarding/* and security/php-version (PLAN.md 29). Mirrors
 * includes/Core/Onboarding/{Applier,CentralApp,StarterPack}.php and PhpVersionMaintenance::payload().
 */

export type OverlapStatus = 'ok' | 'blocked' | 'confirm';

export interface StarterModule {
	id: string;
	name: string;
	enabled: boolean;
	overlap: { status: OverlapStatus; plugins: string[] };
}

/** One setting the item would save, in words. */
export interface PresetRow {
	module: string;
	module_name: string;
	key: string;
	label: string;
	value: unknown;
	value_label: string;
}

export interface StarterWarning {
	code: string;
	message: string;
}

export interface StarterItem {
	id: string;
	title: string;
	description: string;
	optional: boolean;
	checked: boolean;
	available: boolean;
	/** Reason code when `available` is false. */
	unavailable: string;
	modules: StarterModule[];
	preset: PresetRow[];
	warnings: StarterWarning[];
}

export interface Detections {
	woocommerce: boolean;
	cloudflare: boolean;
	xff: boolean;
	server: 'apache' | 'litespeed' | 'nginx' | 'iis' | 'other';
	server_software: string;
	htaccess_writable: boolean;
	pretty_permalinks: boolean;
	login_slug_collides: boolean;
	login_logo: boolean;
	site_name: string;
	site_url: string;
}

export type LoginState = 'none' | 'pending' | 'expired';

export interface LoginUrlInfo {
	state: LoginState;
	since: number;
	slug: string;
	url: string;
}

/** GET onboarding/starter. */
export interface StarterData {
	detections: Detections;
	items: StarterItem[];
	applied: number;
	can_revert: boolean;
	login_url: LoginUrlInfo;
}

export type ItemStatus = 'applied' | 'partial' | 'skipped' | 'error';

/** One entry of POST onboarding/starter/apply `results`. */
export interface ApplyResult {
	id: string;
	status: ItemStatus;
	/** Reason code: an `unavailable` code, or blocked | confirm | unknown from the activation guard. */
	reason: string;
	modules: Record< string, string >;
	plugins: string[];
	errors: string[];
}

export interface ApplyResponse {
	results: ApplyResult[];
	applied: number;
	login_url: LoginUrlInfo;
}

export interface RevertResponse {
	modules_off: string[];
	restored: unknown;
	errors: string[];
}

export interface ConfirmLoginResponse {
	confirmed: boolean;
	url: string;
	mail: 'sent' | 'failed';
}

/** GET security/php-version. */
export interface PhpVersionInfo {
	level: 'ok' | 'warning' | 'critical';
	reason: '' | 'outdated' | 'unsupported' | 'eol_soon' | 'unknown';
	branch: string;
	current: string;
	php_full: string;
	minimum: string;
	recommended: string;
	eol: string;
	days_to_eol: number | null;
	cves: string[];
	advisory_url: string;
	distro_build: boolean;
	message: string;
	backport_note: string;
	snoozed: boolean;
}

/** POST onboarding/ca/prepare. */
export interface CaPrepare {
	action: string;
	fields: Record< string, string >;
	ping: { ok: boolean; code: number; error: string };
	warnings: string[];
	local: boolean;
}

/** GET onboarding/ca/status. */
export interface CaStatus {
	ready: boolean;
	central_app_url: string;
	default_url: string;
	prepared: number;
	last_contact: number;
	contact_after: boolean;
	local: boolean;
	captcha: { security_enabled: boolean; enabled: boolean; has_keys: boolean };
}
