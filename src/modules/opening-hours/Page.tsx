/**
 * Opening Hours: branch locations with weekly hours + live open/closed status.
 * Route: #/module?id=opening-hours
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'locations' | 'settings';

interface WeeklyHours {
	[ day: string ]: Array< { open: string; close: string } >;
}

interface Location {
	id: number;
	title: string;
	address: string;
	hours: WeeklyHours;
	exceptions: unknown[];
	lat: number | null;
	lng: number | null;
}

interface Status {
	open: boolean;
	checked_at: string;
	next_change: string | null;
}

function StatusBadge( { id }: { id: number } ): JSX.Element {
	const { data } = useQuery( {
		queryKey: [ 'opening-hours', 'status', id ],
		queryFn: () => api< Status >( `opening-hours/locations/${ id }/status` ),
	} );
	if ( ! data ) {
		return <span />;
	}
	return (
		<span className={ `uxs-badge ${ data.open ? 'is-success' : 'is-danger' }` }>
			{ data.open ? __( 'Open now', 'ux-studio' ) : __( 'Closed', 'ux-studio' ) }
		</span>
	);
}

function LocationsTab(): JSX.Element {
	const { data: locations, isLoading } = useQuery( {
		queryKey: [ 'opening-hours', 'locations' ],
		queryFn: () => api< Location[] >( 'opening-hours/locations' ),
	} );

	const [ newTitle, setNewTitle ] = useState( '' );
	const [ newAddress, setNewAddress ] = useState( '' );
	const [ hoursDraft, setHoursDraft ] = useState< Record< number, string > >( {} );

	useEffect( () => {
		if ( locations ) {
			setHoursDraft( ( prev ) => {
				const next = { ...prev };
				locations.forEach( ( l ) => {
					if ( ! ( l.id in next ) ) {
						next[ l.id ] = JSON.stringify( l.hours, null, 2 );
					}
				} );
				return next;
			} );
		}
	}, [ locations ] );

	const create = useMutation( {
		mutationFn: () =>
			api( 'opening-hours/locations', {
				method: 'POST',
				body: JSON.stringify( { title: newTitle, address: newAddress } ),
			} ),
		onSuccess: () => {
			setNewTitle( '' );
			setNewAddress( '' );
			void queryClient.invalidateQueries( { queryKey: [ 'opening-hours', 'locations' ] } );
		},
	} );

	const saveHours = useMutation( {
		mutationFn: ( { id, hours }: { id: number; hours: unknown } ) =>
			api( `opening-hours/locations/${ id }`, { method: 'POST', body: JSON.stringify( { hours } ) } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'opening-hours', 'locations' ] } ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `opening-hours/locations/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'opening-hours', 'locations' ] } ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<>
			<div
				className="uxs-form"
				style={ { marginBottom: 'var(--uxs-sp-5)', flexDirection: 'row', alignItems: 'flex-end', gap: 'var(--uxs-sp-3)' } }
			>
				<div className="uxs-form__row" style={ { flex: 1 } }>
					<label htmlFor="uxs-oh-title">{ __( 'Name', 'ux-studio' ) }</label>
					<input id="uxs-oh-title" type="text" value={ newTitle } onChange={ ( e ) => setNewTitle( e.target.value ) } />
				</div>
				<div className="uxs-form__row" style={ { flex: 1 } }>
					<label htmlFor="uxs-oh-address">{ __( 'Address', 'ux-studio' ) }</label>
					<input id="uxs-oh-address" type="text" value={ newAddress } onChange={ ( e ) => setNewAddress( e.target.value ) } />
				</div>
				<button type="button" className="button button-primary" disabled={ ! newTitle } onClick={ () => create.mutate() }>
					<Plus size={ 14 } /> { __( 'Add location', 'ux-studio' ) }
				</button>
			</div>

			{ ( locations ?? [] ).map( ( loc ) => (
				<div key={ loc.id } className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
					<div className="uxs-form__row">
						<label style={ { display: 'flex', justifyContent: 'space-between' } }>
							<span>{ loc.title } <StatusBadge id={ loc.id } /></span>
							<button
								type="button"
								className="button-link"
								aria-label={ __( 'Delete location', 'ux-studio' ) }
								onClick={ () => remove.mutate( loc.id ) }
								style={ { color: 'var(--uxs-danger)' } }
							>
								<Trash2 size={ 16 } />
							</button>
						</label>
						<p className="uxs-form__help">{ loc.address || __( 'No address set.', 'ux-studio' ) }</p>
					</div>
					<div className="uxs-form__row">
						<label htmlFor={ `uxs-oh-hours-${ loc.id }` }>{ __( 'Weekly hours (JSON)', 'ux-studio' ) }</label>
						<textarea
							id={ `uxs-oh-hours-${ loc.id }` }
							rows={ 6 }
							value={ hoursDraft[ loc.id ] ?? '' }
							onChange={ ( e ) => setHoursDraft( ( d ) => ( { ...d, [ loc.id ]: e.target.value } ) ) }
						/>
						<p className="uxs-form__help">
							{ __( 'Example', 'ux-studio' ) }: {`{"mon":[{"open":"09:00","close":"17:00"}]}`}
						</p>
					</div>
					<button
						type="button"
						className="button"
						onClick={ () => {
							try {
								const hours = JSON.parse( hoursDraft[ loc.id ] || '{}' );
								saveHours.mutate( { id: loc.id, hours } );
							} catch {
								// Invalid JSON - silently ignored, the textarea keeps the user's input.
							}
						} }
					>
						{ __( 'Save hours', 'ux-studio' ) }
					</button>
				</div>
			) ) }
			{ ! locations?.length ? <p>{ __( 'No locations yet.', 'ux-studio' ) }</p> : null }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'locations' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'opening-hours' );

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( '' ) }
						aria-label={ __( 'Back to modules', 'ux-studio' ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', verticalAlign: 'middle' } }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ __( 'Opening Hours', 'ux-studio' ) }
				</h1>
				{ tab === 'settings' && (
					<button
						type="button"
						className="button button-primary"
						disabled={ save.isPending }
						onClick={ () => save.mutate() }
					>
						{ saved ? <Check size={ 14 } /> : null }
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				) }
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'locations' ? 'is-active' : '' } onClick={ () => setTab( 'locations' ) }>
					{ __( 'Locations', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'locations' && <LocationsTab /> }
			{ tab === 'settings' &&
				( isLoading || ! data ? (
					<div className="uxs-loading">
						<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
					</div>
				) : (
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
				) ) }
		</>
	);
}
