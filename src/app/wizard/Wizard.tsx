/**
 * Migration wizard (`#/wizard`, PLAN.md 26.3): shell + state. The steps themselves
 * are in steps.tsx. All server work is driven from here in small REST batches
 * (one source per call), so a page reload can resume a run (GET migration/status).
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { ApiError, api, queryClient } from '../api';
import { refreshOverlaps } from '../Overlaps';
import { hashParam } from '../route';
import type { WizardCtx } from './context';
import { usePreview, useSwitch } from './flows';
import { pause } from './parts';
import { RunHistory } from './RunHistory';
import { Stepper } from './Stepper';
import { getSteps } from './steps';
import type { Check, HistoryRun, RunState, ScanSource, SourcePlan, WizardTarget } from './types';

const post = ( path: string, body: Record< string, unknown > ): Promise< { run: RunState } > =>
	api< { run: RunState } >( path, { method: 'POST', body: JSON.stringify( body ) } );

function message( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

export function Wizard(): JSX.Element {
	const steps = useMemo( getSteps, [] );
	const preselect = useMemo( () => ( hashParam( 'plugin' ) ?? '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ), [] );

	// Coming from a plugin conflict skips the welcome page: the intent is already clear.
	const [ index, setIndex ] = useState( preselect.length > 0 ? 1 : 0 );
	const [ selected, setSelected ] = useState< string[] >( [] );
	const [ options, setOptions ] = useState< Record< string, Record< string, boolean > > >( {} );

	const [ scan, setScan ] = useState< ScanSource[] | undefined >();
	const [ scanError, setScanError ] = useState< string | null >( null );
	const [ plans, setPlans ] = useState< Record< string, SourcePlan > | undefined >();

	const [ dry, setDry ] = useState< RunState | null >( null );
	const [ dryBusy, setDryBusy ] = useState( false );
	const [ dryError, setDryError ] = useState< string | null >( null );

	const [ run, setRun ] = useState< RunState | null >( null );
	const [ runBusy, setRunBusy ] = useState( false );
	const [ runError, setRunError ] = useState< string | null >( null );

	const [ verify, setVerify ] = useState< Record< string, Check[] > | null >( null );
	const [ verifyBusy, setVerifyBusy ] = useState( false );
	const [ verifyError, setVerifyError ] = useState< string | null >( null );
	const [ rolledBack, setRolledBack ] = useState( false );
	const [ rollbackBusy, setRollbackBusy ] = useState( false );

	// A finished run picked from the history (to preview and switch it later).
	const [ picked, setPicked ] = useState< WizardTarget | null >( null );
	const finished = useMemo(
		(): WizardTarget | null =>
			run && 'done' === run.status && ! run.dry_run ? { id: run.id, sources: run.sources.map( ( id ) => ( { id, label: run.per_source[ id ]?.label ?? id } ) ) } : null,
		[ run ]
	);
	const target = picked ?? finished;
	const preview = usePreview( target?.id ?? null );
	const sw = useSwitch( target?.id ?? null );

	// Only the newest loop may talk to the server; an abandoned one must not advance a later run.
	const token = useRef( 0 );
	const preselected = useRef( false );

	const stepId = steps[ index ]?.id ?? 'welcome';

	/** Drive a run to its end: `first` starts/continues, then `continue` calls until it stops running. */
	const drive = useCallback( async ( first: Promise< { run: RunState } >, mine: number, set: ( r: RunState ) => void ): Promise< RunState | null > => {
		let state = ( await first ).run;
		set( state );
		while ( 'running' === state.status ) {
			await pause( 380 );
			if ( mine !== token.current ) {
				return null;
			}
			state = ( await post( 'migration/run', { continue: true } ) ).run;
			set( state );
		}
		return state;
	}, [] );

	const finishedRun = useCallback( () => {
		void queryClient.invalidateQueries( { queryKey: [ 'migration' ] } );
		void refreshOverlaps();
	}, [] );

	/* Resume a real run that was still going when the page was left. */
	useEffect( () => {
		let alive = true;
		void ( async () => {
			try {
				const { run: current } = await api< { run: RunState | null } >( 'migration/status' );
				if ( ! alive || ! current || 'running' !== current.status || current.dry_run ) {
					return;
				}
				const mine = ++token.current;
				setSelected( current.sources );
				setOptions( Object.fromEntries( current.sources.map( ( id ) => [ id, current.per_source[ id ]?.options ?? {} ] ) ) );
				setRun( current );
				setIndex( steps.findIndex( ( s ) => 'migrate' === s.id ) );
				setRunBusy( true );
				await drive( post( 'migration/run', { continue: true } ), mine, setRun );
				finishedRun();
			} catch ( e ) {
				setRunError( message( e ) );
			} finally {
				setRunBusy( false );
			}
		} )();
		return () => {
			alive = false;
		};
	}, [ drive, finishedRun, steps ] );

	/* Step 2: scan every time the step is entered. */
	useEffect( () => {
		if ( 'scan' !== stepId ) {
			return undefined;
		}
		let alive = true;
		setScan( undefined );
		setPlans( undefined );
		setScanError( null );
		void ( async () => {
			try {
				const { sources } = await api< { sources: ScanSource[] } >( 'migration/scan' );
				if ( ! alive ) {
					return;
				}
				const ids = sources.map( ( s ) => s.id );
				if ( ! preselected.current ) {
					preselected.current = true;
					// A partial match is never ticked for the admin: they must open the list of what is lost first.
					setSelected( preselect.filter( ( id ) => ids.includes( id ) && 'partial' !== sources.find( ( s ) => s.id === id )?.match ) );
				} else {
					setSelected( ( cur ) => cur.filter( ( id ) => ids.includes( id ) ) );
				}
				setScan( sources );
			} catch ( e ) {
				if ( alive ) {
					setScanError( message( e ) );
				}
			}
		} )();
		return () => {
			alive = false;
		};
	}, [ stepId, preselect ] );

	/* Plans of everything the scan found. Kept apart from the step so a slow answer is not
	 * thrown away when the admin has already moved on to the selection. */
	useEffect( () => {
		if ( ! scan || 0 === scan.length ) {
			return undefined;
		}
		let alive = true;
		void ( async () => {
			try {
				const res = await post2< { plans: SourcePlan[] } >( 'migration/plan', { sources: scan.map( ( s ) => s.id ) } );
				if ( alive ) {
					setPlans( Object.fromEntries( res.plans.map( ( p ) => [ p.id, p ] ) ) );
				}
			} catch ( e ) {
				if ( alive ) {
					setScanError( message( e ) );
				}
			}
		} )();
		return () => {
			alive = false;
		};
	}, [ scan ] );

	/* Step 4: trial run for the current selection. */
	const runDry = useCallback( async () => {
		const mine = ++token.current;
		setDryBusy( true );
		setDryError( null );
		setDry( null );
		try {
			await drive( post( 'migration/run', { sources: selected, dry_run: true, options } ), mine, setDry );
		} catch ( e ) {
			setDryError( message( e ) );
		} finally {
			setDryBusy( false );
		}
	}, [ drive, options, selected ] );

	useEffect( () => {
		if ( 'check' === stepId ) {
			void runDry();
		}
		// Re-run only when the step is entered (the selection can't change while on it).
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ stepId ] );

	/* Step 5: the real run. */
	const startMigration = useCallback( async () => {
		const mine = ++token.current;
		setIndex( steps.findIndex( ( s ) => 'migrate' === s.id ) );
		setRunBusy( true );
		setRunError( null );
		setRun( null );
		setPicked( null );
		setVerify( null );
		setRolledBack( false );
		try {
			let first = post( 'migration/run', { sources: selected, dry_run: false, options } );
			try {
				await first;
			} catch ( e ) {
				// A real run is already going (another tab): carry that one on instead.
				if ( e instanceof ApiError && 'uxstudio_migration_busy' === e.code && e.data.run ) {
					first = post( 'migration/run', { continue: true } );
				} else {
					throw e;
				}
			}
			await drive( first, mine, setRun );
			finishedRun();
		} catch ( e ) {
			setRunError( message( e ) );
		} finally {
			setRunBusy( false );
		}
	}, [ drive, finishedRun, options, selected, steps ] );

	/* Step 6: verify when entered. */
	useEffect( () => {
		if ( 'verify' !== stepId || ! run || verify ) {
			return undefined;
		}
		let alive = true;
		setVerifyBusy( true );
		setVerifyError( null );
		void ( async () => {
			try {
				const res = await post2< { verify: Record< string, Check[] > } >( 'migration/verify', { sources: run.sources } );
				if ( alive ) {
					setVerify( res.verify );
				}
			} catch ( e ) {
				if ( alive ) {
					setVerifyError( message( e ) );
				}
			} finally {
				if ( alive ) {
					setVerifyBusy( false );
				}
			}
		} )();
		return () => {
			alive = false;
		};
	}, [ stepId, run, verify ] );

	const rollback = useCallback( async () => {
		if ( ! run ) {
			return;
		}
		setRollbackBusy( true );
		try {
			await post2( 'migration/rollback', { run_id: run.id } );
			setRolledBack( true );
			finishedRun();
			setIndex( steps.findIndex( ( s ) => 'done' === s.id ) );
		} catch ( e ) {
			setVerifyError( message( e ) );
		} finally {
			setRollbackBusy( false );
		}
	}, [ finishedRun, run, steps ] );

	const ctx: WizardCtx = {
		selected,
		setSelected,
		toggle: ( id ) => setSelected( ( cur ) => ( cur.includes( id ) ? cur.filter( ( x ) => x !== id ) : [ ...cur, id ] ) ),
		options,
		setOption: ( sourceId, key, value ) => setOptions( ( cur ) => ( { ...cur, [ sourceId ]: { ...( cur[ sourceId ] ?? {} ), [ key ]: value } } ) ),
		scan,
		scanError,
		plans,
		dry,
		dryBusy,
		dryError,
		retryDry: () => void runDry(),
		run,
		runBusy,
		runError,
		startMigration: () => void startMigration(),
		verify,
		verifyBusy,
		verifyError,
		rolledBack,
		rollbackBusy,
		rollback: () => void rollback(),
		target,
		preview,
		sw,
		next: () => setIndex( ( i ) => Math.min( i + 1, steps.length - 1 ) ),
		back: () => setIndex( ( i ) => Math.max( i - 1, 0 ) ),
		goTo: ( id ) => setIndex( Math.max( 0, steps.findIndex( ( s ) => s.id === id ) ) ),
		restart: () => {
			token.current++;
			setDry( null );
			setRun( null );
			setPicked( null );
			setVerify( null );
			setRolledBack( false );
			setRunError( null );
			setSelected( [] );
			setOptions( {} );
			setIndex( 0 );
		},
	};

	return (
		<div className="uxs-wiz">
			<header className="uxs-pagehead">
				<h1>{ __( 'Migration wizard', 'ux-studio' ) }</h1>
			</header>
			<Stepper steps={ steps } current={ index } />
			<div className="uxs-wiz-stage" key={ stepId }>
				{ steps[ index ]?.render( ctx ) }
			</div>
			<RunHistory
				onRolledBack={ ( id ) => {
					if ( run?.id === id ) {
						setRolledBack( true );
					}
					if ( target?.id === id ) {
						setPicked( null );
					}
				} }
				onContinue={ ( item: HistoryRun ) => {
					setPicked( { id: item.id, sources: item.sources.filter( ( s ) => 'done' === s.status ).map( ( s ) => ( { id: s.id, label: s.label } ) ) } );
					setRolledBack( false );
					setIndex( steps.findIndex( ( s ) => 'preview' === s.id ) );
					window.scrollTo( { top: 0, behavior: 'auto' } );
				} }
				onReverted={ ( id ) => {
					if ( target?.id === id ) {
						sw.clear();
					}
				} }
			/>
		</div>
	);
}

/** POST returning an arbitrary payload. */
function post2< T >( path: string, body: Record< string, unknown > ): Promise< T > {
	return api< T >( path, { method: 'POST', body: JSON.stringify( body ) } );
}
