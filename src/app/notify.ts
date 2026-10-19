/**
 * Tiny global status-toast store (saving spinner, success / error message).
 * api() feeds it for settings saves, <Toasts /> renders it.
 */
import { useSyncExternalStore } from 'react';

export type ToastKind = 'loading' | 'success' | 'error';

export interface Toast {
	id: number;
	kind: ToastKind;
	message: string;
}

const AUTO_DISMISS_MS = 10_000;

let toasts: Toast[] = [];
let nextId = 1;
const listeners = new Set< () => void >();
const timers = new Map< number, number >();

function emit(): void {
	toasts = [ ...toasts ];
	listeners.forEach( ( l ) => l() );
}

export function dismissToast( id: number ): void {
	window.clearTimeout( timers.get( id ) );
	timers.delete( id );
	toasts = toasts.filter( ( t ) => t.id !== id );
	emit();
}

/** Show a toast; non-loading ones disappear on their own after 10 s. Returns its id. */
export function pushToast( kind: ToastKind, message: string, replace?: number ): number {
	if ( undefined !== replace ) {
		dismissToast( replace );
	}
	const id = nextId++;
	toasts = [ ...toasts, { id, kind, message } ];
	if ( 'loading' !== kind ) {
		timers.set( id, window.setTimeout( () => dismissToast( id ), AUTO_DISMISS_MS ) );
	}
	emit();
	return id;
}

function subscribe( l: () => void ): () => void {
	listeners.add( l );
	return () => listeners.delete( l );
}

export function useToasts(): Toast[] {
	return useSyncExternalStore( subscribe, () => toasts );
}
