/**
 * State of the F4 steps: the safe preview with its tests, the switch, the live tests after it.
 * The server does the work in small calls (one test = one call), so the wizard can show the rows
 * filling in one by one and a slow site never runs into a PHP time limit.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, api, queryClient } from '../api';
import { refreshOverlaps } from '../Overlaps';
import { pause } from './parts';
import type { PreviewPayload, SmokePlanItem, SmokeResult, SwitchPlan, SwitchResult } from './types';

function message( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

const post = < T >( path: string, body: Record< string, unknown > ): Promise< T > => api< T >( path, { method: 'POST', body: JSON.stringify( body ) } );

/* ── A row-by-row test run (preview mode or live mode) ── */

export interface SmokeRun {
	plan: SmokePlanItem[];
	results: Record< string, SmokeResult >;
	/** Id of the test being run right now. */
	current: string | null;
	running: boolean;
	/** Every test of the plan has a result. */
	done: boolean;
	error: string | null;
	/** The preview ran out while testing. */
	expired: boolean;
	run: ( runId: string, plan: SmokePlanItem[] ) => Promise< void >;
	reset: ( results?: Record< string, SmokeResult >, plan?: SmokePlanItem[] ) => void;
}

export function useSmoke( mode: 'preview' | 'live' ): SmokeRun {
	const [ plan, setPlan ] = useState< SmokePlanItem[] >( [] );
	const [ results, setResults ] = useState< Record< string, SmokeResult > >( {} );
	const [ current, setCurrent ] = useState< string | null >( null );
	const [ running, setRunning ] = useState( false );
	const [ done, setDone ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ expired, setExpired ] = useState( false );
	const token = useRef( 0 );

	const run = useCallback(
		async ( runId: string, items: SmokePlanItem[] ): Promise< void > => {
			const mine = ++token.current;
			setPlan( items );
			setResults( {} );
			setDone( false );
			setError( null );
			setExpired( false );
			setRunning( true );
			try {
				for ( const item of items ) {
					if ( mine !== token.current ) {
						return;
					}
					setCurrent( item.id );
					await pause( 240 );
					try {
						const res = await post< { result: SmokeResult } >( 'migration/smoke', { run_id: runId, mode, check: item.id } );
						if ( mine !== token.current ) {
							return;
						}
						setResults( ( cur ) => ( { ...cur, [ item.id ]: res.result } ) );
					} catch ( e ) {
						if ( e instanceof ApiError && 'uxstudio_preview_expired' === e.code ) {
							setExpired( true );
							setError( e.message );
							return;
						}
						setResults( ( cur ) => ( { ...cur, [ item.id ]: { ...item, status: 'fail', detail: message( e ), ms: 0 } } ) );
					}
				}
				if ( mine === token.current ) {
					setDone( true );
				}
			} finally {
				if ( mine === token.current ) {
					setCurrent( null );
					setRunning( false );
				}
			}
		},
		[ mode ]
	);

	const reset = useCallback( ( nextResults: Record< string, SmokeResult > = {}, nextPlan: SmokePlanItem[] = [] ): void => {
		token.current++;
		setPlan( nextPlan );
		setResults( nextResults );
		setCurrent( null );
		setRunning( false );
		setDone( nextPlan.length > 0 && nextPlan.every( ( item ) => !! nextResults[ item.id ] ) );
		setError( null );
		setExpired( false );
	}, [] );

	return { plan, results, current, running, done, error, expired, run, reset };
}

/* ── Safe preview ── */

export interface PreviewFlow {
	payload: PreviewPayload | null;
	loading: boolean;
	/** Starting / ending the preview. */
	busy: boolean;
	error: string | null;
	tests: SmokeRun;
	load: () => Promise< void >;
	start: () => Promise< void >;
	rerun: () => Promise< void >;
	end: () => Promise< void >;
}

export function usePreview( runId: string | null ): PreviewFlow {
	const tests = useSmoke( 'preview' );
	const [ payload, setPayload ] = useState< PreviewPayload | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const { reset, run } = tests;

	// Another run: forget everything about the previous one.
	useEffect( () => {
		setPayload( null );
		setError( null );
		reset();
	}, [ runId, reset ] );

	const adopt = useCallback(
		( next: PreviewPayload ): void => {
			setPayload( next );
			if ( next.entry && next.plan ) {
				reset( next.entry.results, next.plan );
			} else {
				reset();
			}
		},
		[ reset ]
	);

	const load = useCallback( async (): Promise< void > => {
		if ( ! runId ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			adopt( await api< PreviewPayload >( `migration/preview?run_id=${ encodeURIComponent( runId ) }` ) );
		} catch ( e ) {
			setError( message( e ) );
		} finally {
			setLoading( false );
		}
	}, [ adopt, runId ] );

	const start = useCallback( async (): Promise< void > => {
		if ( ! runId ) {
			return;
		}
		setBusy( true );
		setError( null );
		try {
			const next = await post< PreviewPayload >( 'migration/preview', { run_id: runId } );
			setPayload( next );
			setBusy( false );
			if ( next.plan ) {
				await run( runId, next.plan );
			}
		} catch ( e ) {
			setError( message( e ) );
		} finally {
			setBusy( false );
		}
	}, [ run, runId ] );

	const rerun = useCallback( async (): Promise< void > => {
		if ( runId && payload?.plan ) {
			await run( runId, payload.plan );
		}
	}, [ payload, run, runId ] );

	const end = useCallback( async (): Promise< void > => {
		if ( ! runId ) {
			return;
		}
		setBusy( true );
		try {
			const next = await post< PreviewPayload >( 'migration/preview/end', { run_id: runId } );
			setPayload( next );
			void queryClient.invalidateQueries( { queryKey: [ 'migration' ] } );
		} catch ( e ) {
			setError( message( e ) );
		} finally {
			setBusy( false );
		}
	}, [ runId ] );

	return { payload, loading, busy, error, tests, load, start, rerun, end };
}

/* ── Switch ── */

export interface SwitchFlags {
	ack_login: boolean;
	override_failures: boolean;
	no_preview: boolean;
}

export interface SwitchFlow {
	plan: SwitchPlan | null;
	loading: boolean;
	error: string | null;
	flags: SwitchFlags;
	setFlag: ( key: keyof SwitchFlags, value: boolean ) => void;
	switching: boolean;
	switchError: string | null;
	result: SwitchResult | null;
	live: SmokeRun;
	reverting: boolean;
	revertError: string | null;
	/** The switch was undone from this wizard. */
	reverted: boolean;
	/** Forget the state of this run (after its switch was undone elsewhere). */
	clear: () => void;
	loadPlan: () => Promise< void >;
	doSwitch: () => Promise< boolean >;
	revert: () => Promise< boolean >;
	rerunLive: () => Promise< void >;
}

const NO_FLAGS: SwitchFlags = { ack_login: false, override_failures: false, no_preview: false };

export function useSwitch( runId: string | null ): SwitchFlow {
	const live = useSmoke( 'live' );
	const [ plan, setPlan ] = useState< SwitchPlan | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ flags, setFlags ] = useState< SwitchFlags >( NO_FLAGS );
	const [ switching, setSwitching ] = useState( false );
	const [ switchError, setSwitchError ] = useState< string | null >( null );
	const [ result, setResult ] = useState< SwitchResult | null >( null );
	const [ reverting, setReverting ] = useState( false );
	const [ revertError, setRevertError ] = useState< string | null >( null );
	const [ reverted, setReverted ] = useState( false );
	const { reset, run } = live;

	const clear = useCallback( (): void => {
		setPlan( null );
		setError( null );
		setFlags( NO_FLAGS );
		setResult( null );
		setSwitchError( null );
		setRevertError( null );
		setReverted( false );
		reset();
	}, [ reset ] );

	useEffect( () => {
		clear();
	}, [ runId, clear ] );

	const loadPlan = useCallback( async (): Promise< void > => {
		if ( ! runId ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setPlan( await api< SwitchPlan >( `migration/switch-plan?run_id=${ encodeURIComponent( runId ) }` ) );
		} catch ( e ) {
			setError( message( e ) );
		} finally {
			setLoading( false );
		}
	}, [ runId ] );

	const changed = useCallback( (): void => {
		void queryClient.invalidateQueries( { queryKey: [ 'migration' ] } );
		void refreshOverlaps();
	}, [] );

	const doSwitch = useCallback( async (): Promise< boolean > => {
		if ( ! runId ) {
			return false;
		}
		setSwitching( true );
		setSwitchError( null );
		try {
			const res = await post< SwitchResult >( 'migration/switch', { run_id: runId, ...flags } );
			setResult( res );
			setReverted( false );
			changed();
			void run( runId, res.live_plan );
			return true;
		} catch ( e ) {
			setSwitchError( message( e ) );
			return false;
		} finally {
			setSwitching( false );
		}
	}, [ changed, flags, run, runId ] );

	const revert = useCallback( async (): Promise< boolean > => {
		if ( ! runId ) {
			return false;
		}
		setReverting( true );
		setRevertError( null );
		try {
			await post( 'migration/revert-switch', { run_id: runId } );
			setReverted( true );
			setResult( null );
			reset();
			changed();
			return true;
		} catch ( e ) {
			setRevertError( message( e ) );
			return false;
		} finally {
			setReverting( false );
		}
	}, [ changed, reset, runId ] );

	const rerunLive = useCallback( async (): Promise< void > => {
		if ( runId && result ) {
			await run( runId, result.live_plan );
		}
	}, [ result, run, runId ] );

	return {
		plan,
		loading,
		error,
		flags,
		setFlag: ( key, value ) => setFlags( ( cur ) => ( { ...cur, [ key ]: value } ) ),
		switching,
		switchError,
		result,
		live,
		reverting,
		revertError,
		reverted,
		clear,
		loadPlan,
		doSwitch,
		revert,
		rerunLive,
	};
}
