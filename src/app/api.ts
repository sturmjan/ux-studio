/**
 * Shared React Query client + typed REST helper.
 */
import { QueryClient } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { pushToast } from './notify';
import type { GlossaryData } from '../components/glossary';

declare global {
	interface Window {
		uxStudioBoot: {
			restUrl: string;
			nonce: string;
			version: string;
			apiVersion: number;
			locale: string;
			/** Definitions behind the [[term]] markers of help texts (Core\Glossary), in the user's language. */
			glossary?: GlossaryData;
		};
	}
}

export const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			refetchOnWindowFocus: false,
			// Prefetched data stays fresh for 60 s so page switches are instant;
			// mutations still invalidate explicitly after save.
			staleTime: 60_000,
			gcTime: 5 * 60_000,
		},
	},
} );

interface Envelope< T > {
	data: T;
	meta?: Record< string, unknown >;
}

/**
 * Error thrown by api(): keeps the REST error code (e.g. `reauth_required`),
 * HTTP status and the error `data` payload next to the message.
 */
export class ApiError extends Error {
	code: string;
	status: number;
	data: Record< string, unknown >;

	constructor( message: string, code: string, status: number, data: Record< string, unknown > ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.data = data;
	}
}

/**
 * Fetch from uxstudio/v1 with nonce; throws on HTTP or API error.
 */
export async function api< T >( path: string, init?: RequestInit ): Promise< T > {
	// Settings saves get a global status toast (spinner -> green "saved" / red error).
	const isSave = /^\/?settings\//.test( path ) && 'POST' === init?.method;
	if ( ! isSave ) {
		return request< T >( path, init );
	}
	const loading = pushToast( 'loading', __( 'Saving…', 'ux-studio' ) );
	try {
		const data = await request< T >( path, init );
		pushToast( 'success', __( 'Settings saved.', 'ux-studio' ), loading );
		return data;
	} catch ( e ) {
		pushToast( 'error', e instanceof Error ? e.message : String( e ), loading );
		throw e;
	}
}

async function request< T >( path: string, init?: RequestInit ): Promise< T > {
	const boot = window.uxStudioBoot;
	const res = await fetch( `${ boot.restUrl }/${ path.replace( /^\//, '' ) }`, {
		...init,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': boot.nonce,
			...( init?.headers ?? {} ),
		},
	} );
	if ( ! res.ok ) {
		const body: { message?: string; code?: string; data?: Record< string, unknown > } = await res.json().catch( () => ( {} ) );
		throw new ApiError( body.message ?? `HTTP ${ res.status }`, body.code ?? '', res.status, body.data ?? {} );
	}
	const json: Envelope< T > = await res.json();
	return json.data;
}

export interface ModuleInfo {
	id: string;
	name: string;
	description: string;
	group: string;
	icon: string;
	settings: boolean;
	enabled: boolean;
	/** Group tile this module ("feature") is nested under, if any. */
	parent?: string | null;
	/** Feature ids of a virtual group tile (empty for normal modules). */
	children?: string[];
	/** Short "How it works" text (meta.json `how`), shown on the page of a module without settings. */
	how?: string;
}

/** One overlap between an enabled UX Studio module and an active foreign plugin (GET /overlaps). */
export interface OverlapRow {
	/** `<plugin basename>><module id>` - identifies the pair for ignore/unignore. */
	key: string;
	basename: string;
	slug: string;
	name: string;
	module: string;
	module_name: string;
	type: 'full' | 'partial';
	group: 'full' | 'customiser' | 'partial';
	confirm: boolean;
	/** Overlapping feature of a broad module (e.g. "Disable comments"), or ''. */
	feature: string;
	/** The migration wizard has a source for this plugin. */
	migratable?: boolean;
}

export interface OverlapsData {
	full: OverlapRow[];
	customiser: OverlapRow[];
	partial: OverlapRow[];
	ignored: OverlapRow[];
}

/** `data` of the 409 `uxstudio_plugin_overlap` error returned when enabling a module. */
export interface OverlapErrorData {
	plugins: { name: string; basename: string; slug?: string; migratable?: boolean; type: 'full' | 'partial'; module: string; module_name: string }[];
	requires_confirmation: boolean;
}
