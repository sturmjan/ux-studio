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
		const body: { message?: string } = await res.json().catch( () => ( {} ) );
		throw new Error( body.message ?? `HTTP ${ res.status }` );
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
}
