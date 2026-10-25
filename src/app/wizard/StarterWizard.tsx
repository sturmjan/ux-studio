/**
 * The wizard for a new site (`#/wizard?mode=new`, PLAN.md 29): recommended modules, applying them, the custom
 * login address and the Central App connection. The steps are in starter-steps.tsx; the server work is driven
 * from here, one item per request, so a problem in one item never stops the others.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ApiError, api, queryClient } from '../api';
import { refreshOverlaps } from '../Overlaps';
import { hashParam } from '../route';
import { pause } from './parts';
import { Stepper } from './Stepper';
import type { ApplyState, StarterCtx } from './starter-context';
import { getStarterSteps } from './starter-steps';
import type { ApplyResponse, PhpVersionInfo, RevertResponse, StarterData, StarterItem } from './starter-types';

const STARTER_KEY = [ 'onboarding', 'starter' ];

function message( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

function sleep( ms: number ): Promise< void > {
	return new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );
}

/** POST that waits and tries again when the per-user write budget (60 a minute) is used up. */
async function postRetry< T >( path: string, body: Record< string, unknown > ): Promise< T > {
	for ( let attempt = 0; ; attempt++ ) {
		try {
			return await api< T >( path, { method: 'POST', body: JSON.stringify( body ) } );
		} catch ( e ) {
			if ( e instanceof ApiError && 429 === e.status && attempt < 3 ) {
				await sleep( 5000 );
				continue;
			}
			throw e;
		}
	}
}

/** Items that can be ticked on this site and are on by default. */
function defaultSelection( items: StarterItem[] ): string[] {
	return items
		.filter( ( item ) => {
			const blocked = item.modules.length > 0 && item.modules.every( ( m ) => ! m.enabled && 'blocked' === m.overlap.status );
			const needsConfirm = item.modules.some( ( m ) => ! m.enabled && 'confirm' === m.overlap.status );
			return item.checked && item.available && ! blocked && ! needsConfirm;
		} )
		.map( ( item ) => item.id );
}

const IDLE: ApplyState = { status: 'idle', current: null, results: {}, errors: {}, order: [] };

export function StarterWizard(): JSX.Element {
	const starter = useQuery( {
		queryKey: STARTER_KEY,
		queryFn: () => api< StarterData >( 'onboarding/starter' ),
		staleTime: 0,
		refetchOnMount: 'always',
	} );
	const phpQuery = useQuery( {
		queryKey: [ 'onboarding', 'php-version' ],
		queryFn: () => api< PhpVersionInfo >( 'security/php-version' ),
		staleTime: 0,
		refetchOnMount: 'always',
	} );
	const data = starter.data;

	const [ selected, setSelected ] = useState< string[] >( [] );
	const [ confirmed, setConfirmed ] = useState< string[] >( [] );
	const [ apply, setApply ] = useState< ApplyState >( IDLE );
	const [ loginConfirmed, setLoginConfirmed ] = useState( false );
	const [ reverted, setReverted ] = useState( false );
	const [ revertBusy, setRevertBusy ] = useState( false );
	const [ revertError, setRevertError ] = useState< string | null >( null );
	// The login step stays in the list once it was there, so the indicator never shifts under the administrator.
	const [ sawLogin, setSawLogin ] = useState( false );
	// Only steps that change nothing may be opened by address (`step=apply` would start applying).
	const [ stepId, setStepId ] = useState( () => {
		const wanted = hashParam( 'step' ) ?? '';
		return [ 'welcome', 'modules', 'login', 'ca', 'done' ].includes( wanted ) ? wanted : 'welcome';
	} );
	const token = useRef( 0 );
	const preselected = useRef( false );

	// Default ticks, once.
	useEffect( () => {
		if ( data && ! preselected.current ) {
			preselected.current = true;
			setSelected( defaultSelection( data.items ) );
		}
	}, [ data ] );

	// A pending login address from an earlier run also needs its step.
	useEffect( () => {
		if ( data && 'none' !== data.login_url.state ) {
			setSawLogin( true );
		}
	}, [ data ] );

	const loginTicked = !! data?.items.some( ( item ) => 'login-url' === item.id && item.available && selected.includes( item.id ) );
	const steps = useMemo( () => getStarterSteps( sawLogin || loginTicked ), [ sawLogin, loginTicked ] );
	const index = Math.max( 0, steps.findIndex( ( s ) => s.id === stepId ) );

	const reload = useCallback( async (): Promise< void > => {
		await queryClient.invalidateQueries( { queryKey: STARTER_KEY } );
		await starter.refetch();
	}, [ starter ] );

	const startApply = useCallback( () => {
		if ( ! data ) {
			return;
		}
		const mine = ++token.current;
		const order = data.items.filter( ( item ) => selected.includes( item.id ) ).map( ( item ) => item.id );
		setApply( { status: 'running', current: null, results: {}, errors: {}, order } );
		void ( async () => {
			for ( const id of order ) {
				if ( mine !== token.current ) {
					return;
				}
				setApply( ( cur ) => ( { ...cur, current: id } ) );
				try {
					const res = await postRetry< ApplyResponse >( 'onboarding/starter/apply', { items: [ id ], confirmed: confirmed.includes( id ) ? [ id ] : [] } );
					const result = res.results[ 0 ];
					if ( 'none' !== res.login_url.state ) {
						setSawLogin( true );
					}
					setApply( ( cur ) => ( result ? { ...cur, results: { ...cur.results, [ id ]: result } } : { ...cur, errors: { ...cur.errors, [ id ]: __( 'The server did not answer for this item.', 'ux-studio' ) } } ) );
				} catch ( e ) {
					setApply( ( cur ) => ( { ...cur, errors: { ...cur.errors, [ id ]: message( e ) } } ) );
				}
				await pause( 320 );
			}
			if ( mine !== token.current ) {
				return;
			}
			setApply( ( cur ) => ( { ...cur, status: 'done', current: null } ) );
			void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'migration-hint' ] } );
			void refreshOverlaps();
			void reload();
		} )();
	}, [ confirmed, data, reload, selected ] );

	const revert = useCallback( () => {
		setRevertBusy( true );
		setRevertError( null );
		void ( async () => {
			try {
				await api< RevertResponse >( 'onboarding/starter/revert', { method: 'POST' } );
				setReverted( true );
				setApply( IDLE );
				setLoginConfirmed( false );
				preselected.current = false;
				void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
				void queryClient.invalidateQueries( { queryKey: [ 'migration-hint' ] } );
				void refreshOverlaps();
				await reload();
			} catch ( e ) {
				setRevertError( message( e ) );
			} finally {
				setRevertBusy( false );
			}
		} )();
	}, [ reload ] );

	const ctx: StarterCtx = {
		data,
		loadError: starter.error ? message( starter.error ) : null,
		reload,
		php: phpQuery.data,
		selected,
		toggle: ( id ) => setSelected( ( cur ) => ( cur.includes( id ) ? cur.filter( ( x ) => x !== id ) : [ ...cur, id ] ) ),
		setSelected,
		confirmed,
		toggleConfirmed: ( id ) => {
			const on = ! confirmed.includes( id );
			setConfirmed( ( cur ) => ( on ? [ ...cur, id ] : cur.filter( ( x ) => x !== id ) ) );
			// Confirming the overlap is what ticks such an item; withdrawing it unticks it.
			setSelected( ( cur ) => ( on ? ( cur.includes( id ) ? cur : [ ...cur, id ] ) : cur.filter( ( x ) => x !== id ) ) );
		},
		apply,
		startApply,
		loginConfirmed,
		setLoginConfirmed,
		reverted,
		revertBusy,
		revertError,
		revert,
		next: () => {
			setStepId( steps[ Math.min( index + 1, steps.length - 1 ) ]?.id ?? 'welcome' );
			window.scrollTo( { top: 0, behavior: 'auto' } );
		},
		back: () => setStepId( steps[ Math.max( index - 1, 0 ) ]?.id ?? 'welcome' ),
		goTo: ( id ) => {
			if ( steps.some( ( s ) => s.id === id ) ) {
				if ( 'welcome' === id ) {
					setReverted( false );
				}
				setStepId( id );
			}
		},
	};

	return (
		<div className="uxs-wiz uxs-wiz-starter">
			<header className="uxs-pagehead">
				<h1>{ __( 'New site setup', 'ux-studio' ) }</h1>
			</header>
			<Stepper steps={ steps } current={ index } label={ __( 'Setup steps', 'ux-studio' ) } />
			<div className="uxs-wiz-stage" key={ steps[ index ]?.id ?? 'welcome' }>
				{ steps[ index ]?.render( ctx ) }
			</div>
		</div>
	);
}
