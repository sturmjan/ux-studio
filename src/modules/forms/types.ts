/**
 * Shared TypeScript types for the Form Builder admin SPA. Mirrors the PHP
 * shapes in includes/Modules/Forms/Fields.php and Module.php - keep the two
 * in sync when adding an attribute.
 */

export const FIELD_TYPES = [
	'text',
	'textarea',
	'email',
	'url',
	'tel',
	'number',
	'password',
	'hidden',
	'select',
	'radio',
	'checkbox',
	'checkbox_group',
	'multiselect',
	'acceptance',
	'date',
	'time',
	'file',
	'signature',
	'html',
	'step',
	'captcha',
] as const;

export type FieldType = ( typeof FIELD_TYPES )[ number ];

export const LAYOUT_TYPES: FieldType[] = [ 'html', 'step' ];
export const CHOICE_TYPES: FieldType[] = [ 'select', 'radio', 'checkbox_group', 'multiselect' ];
/** Types whose submitted value is a stored-file entry, same shape as `file` (PLAN.md 20.11/F4). Mirrors Fields::FILE_LIKE_TYPES. */
export const FILE_LIKE_TYPES: FieldType[] = [ 'file', 'signature' ];

export const ALLOWED_WIDTHS = [ 25, 33, 50, 66, 75, 100 ] as const;
export type FieldWidth = ( typeof ALLOWED_WIDTHS )[ number ];

export type LabelDisplay = 'inherit' | 'visible' | 'placeholder_only';
/** How radio / checkbox-group options are drawn. Mirrors Fields::CHOICE_STYLES. */
export type ChoiceStyle = 'list' | 'cards' | 'inline';
export type ConditionOperator = 'equals' | 'not_equals' | 'contains' | 'empty' | 'not_empty' | 'greater' | 'less';
export type ConditionLogic = 'all' | 'any';

export interface FieldOption {
	label: string;
	value: string;
}

export interface FieldCondition {
	field: string;
	operator: ConditionOperator;
	value: string;
}

export interface FormField {
	key: string;
	type: FieldType;
	label: string;
	placeholder: string;
	required: boolean;
	css_class: string;
	default_value: string;
	width: number;
	width_tablet: number;
	width_mobile: number;
	label_display: LabelDisplay;
	conditions: FieldCondition[];
	logic: ConditionLogic;
	options?: FieldOption[];
	html?: string;
	step_title?: string;
	step_description?: string;
	choice_style?: ChoiceStyle;
	terms_url?: string;
	min?: number | null;
	max?: number | null;
	minlength?: number | null;
	maxlength?: number | null;
	rows?: number;
	accept?: string[];
	max_size_mb?: number;
	multiple?: boolean;
}

export type EmailTemplate = 'minimal' | 'card' | 'branded' | 'custom';

export interface EmailAction {
	type: 'email';
	to: string;
	subject: string;
	message: string;
	template: EmailTemplate;
	include_table: boolean;
	cta_text: string;
	cta_url: string;
	/** Full hand-written HTML document, used only when template === 'custom'. */
	custom_html: string;
	/** Header logo from the Media Library (0 = the site logo). */
	logo_id: number;
	/** Read-only thumbnail URL derived by the server from logo_id. */
	logo_url: string;
	logo_position: LogoPosition;
	/** Logo width in px, 40 to 400. */
	logo_width: number;
	/** #rrggbb, '' = the template's default accent. */
	header_bg: string;
	/** #rrggbb, '' = the template's default accent. */
	link_color: string;
}

export type LogoPosition = 'left' | 'center' | 'right';

/** Response of POST forms/{id}/preview-email. */
export interface EmailPreview {
	subject: string;
	html: string;
	warnings: { code: string; message: string }[];
	/** Header and link colors the server resolved (the template default where the action sets none). */
	layout?: { header_bg: string; link_color: string };
}

export interface WebhookAction {
	type: 'webhook';
	url: string;
}

export interface RedirectAction {
	type: 'redirect';
	url: string;
}

export type PostStatus = 'draft' | 'pending' | 'private' | 'publish';

export interface CreatePostAction {
	type: 'create_post';
	post_type: string;
	post_status: PostStatus;
	title_template: string;
	content_template: string;
	/** 0 = auto (form creator, else the site's first administrator). */
	author_id: number;
}

export type FormAction = EmailAction | WebhookAction | RedirectAction | CreatePostAction;

export interface PostTypeOption {
	id: string;
	label: string;
}

export type ProgressStyle = 'steps' | 'numbers' | 'bar' | 'none';
export type FormLayout = 'plain' | 'card' | 'inline';

export interface FormSettings {
	label_display: 'visible' | 'placeholder_only';
	progress_style: ProgressStyle;
	/** plain | card (header with title and description, footer with the buttons) | inline (one-row signup). */
	layout: FormLayout;
	/** 2 = fields left at full width become two columns. */
	columns: 1 | 2;
	/** Numbered badge in front of every step title. */
	numbered_sections: boolean;
	/** Text of the final button, '' = the default "Submit". */
	submit_text: string;
	success_text: string;
	captcha_enabled: boolean;
	/** Per-form HMAC secret for the webhook action - server-generated, read-only. */
	webhook_secret: string;
	actions: FormAction[];
	/** Optional GDPR/retention auto-delete (PLAN.md 20.2/20.6/F4) - null (default) keeps the archive forever. */
	retention_days: number | null;
}

export type FormStatus = 'active' | 'draft' | 'archived';

export interface FormListItem {
	id: number;
	title: string;
	description: string;
	status: FormStatus;
	created_at: string;
	updated_at: string;
	submissions: number;
	unread: number;
}

export interface FormDefinition {
	id: number;
	title: string;
	description: string;
	fields: FormField[];
	settings: FormSettings;
	status: FormStatus;
	created_by: number;
	created_at: string;
	updated_at: string;
}

export type SubmissionStatus = 'unread' | 'read' | 'spam' | 'trash';

export interface SubmissionListItem {
	id: number;
	form_id: number;
	form_title: string;
	values: Record< string, unknown >;
	fields_snapshot: Record< string, { label: string; type: FieldType } >;
	meta: { ip_hash?: string; user_agent?: string; referrer?: string; page_url?: string };
	status: SubmissionStatus;
	created_at: string;
}

export interface SubmissionFile {
	id: number;
	field_key: string;
	original_name: string;
	stored_path: string;
	mime: string;
	size: number;
}

export interface ActionLogEntry {
	id: number;
	action_type: string;
	status: 'ok' | 'fail';
	detail: string;
	created_at: string;
}

export interface SubmissionDetail extends SubmissionListItem {
	files: SubmissionFile[];
	action_log: ActionLogEntry[];
}

/** Revision list-row shape returned by GET /forms/{id}/revisions (PLAN.md 20.11/F3). */
export interface FormRevision {
	id: number;
	title: string;
	field_count: number;
	created_by: number;
	created_by_name: string;
	created_at: string;
}

export function emptyField( type: FieldType, key: string ): FormField {
	return {
		key,
		type,
		label: '',
		placeholder: '',
		required: false,
		css_class: '',
		default_value: '',
		width: 100,
		width_tablet: 100,
		width_mobile: 100,
		label_display: 'inherit',
		conditions: [],
		logic: 'all',
		options: CHOICE_TYPES.includes( type ) ? [] : undefined,
		accept: type === 'file' ? [] : undefined,
		max_size_mb: type === 'file' ? 10 : undefined,
		rows: type === 'textarea' ? 4 : undefined,
	};
}

export function defaultSettings(): FormSettings {
	return {
		label_display: 'visible',
		progress_style: 'steps',
		layout: 'plain',
		columns: 1,
		numbered_sections: false,
		submit_text: '',
		success_text: '',
		captcha_enabled: false,
		webhook_secret: '',
		actions: [],
		retention_days: null,
	};
}

/** One card of the template gallery (GET forms/templates). Fields are sanitized, used for the live mini preview. */
export interface FormTemplate {
	id: string;
	category: string;
	title: string;
	description: string;
	field_count: number;
	step_count: number;
	fields: FormField[];
	settings: Pick< FormSettings, 'layout' | 'columns' | 'numbered_sections' | 'progress_style' | 'submit_text' >;
}

export interface TemplateCatalog {
	categories: { id: string; label: string }[];
	templates: FormTemplate[];
}
