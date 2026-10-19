/**
 * Shared React Query client + typed REST helper.
 */
import { QueryClient } from '@tanstack/react-query';

declare global {
	interface Window {
		uxStudioBoot: {
			restUrl: string;
			nonce: string;
			version: string;
			apiVersion: number;
			locale: string;
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
}
