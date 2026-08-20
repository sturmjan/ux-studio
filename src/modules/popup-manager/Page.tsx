/**
 * Popup Manager: on-site popups with delay/scroll/exit triggers + tracking.
 * Route: #/module?id=popup-manager
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';

interface Popup {
	id: number;
	title: string;
	status: string;
	created_at: string;
	content: Record< string, unknown >;
	trigger: Record< string, unknown >;
	targeting: Record< string, unknown >;
}

interface Stats {
	shown: number;
	closed: number;
	clicked: number;
}

function StatsBadges( { id }: { id: number } ): JSX.Element {
	const { data } = useQuery( {
		queryKey: [ 'popup-manager', 'stats', id ],
		queryFn: () => api< Stats >( `popup-manager/stats/${ id }` ),
	} );
	if ( ! data ) {
		return <span />;
	}
	return (
		<span>
			<span className="uxs-badge is-success">{ __( 'Shown', 'ux-studio' ) }: { data.shown }</span>{ ' ' }
			<span className="uxs-badge is-warning">{ __( 'Closed', 'ux-studio' ) }: { data.closed }</span>{ ' ' }
			<span className="uxs-badge is-success">{ __( 'Clicked', 'ux-studio' ) }: { data.clicked }</span>
		</span>
	);
}

export default function Page(): JSX.Element {
	const [ newTitle, setNewTitle ] = useState( '' );

	const { data: popups, isLoading } = useQuery( {
		queryKey: [ 'popup-manager', 'popups' ],
		queryFn: () => api< Popup[] >( 'popup-manager/popups' ),
	} );

	const create = useMutation( {
		mutationFn: () =>
			api( 'popup-manager/popups', {
				method: 'POST',
				body: JSON.stringify( { title: newTitle, status: 'draft' } ),
			} ),
		onSuccess: () => {
			setNewTitle( '' );
			void queryClient.invalidateQueries( { queryKey: [ 'popup-manager', 'popups' ] } );
		},
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `popup-manager/popups/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'popup-manager', 'popups' ] } ),
	} );

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
					{ __( 'Popup Manager', 'ux-studio' ) }
				</h1>
			</header>

			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)', flexDirection: 'row', alignItems: 'flex-end', gap: 'var(--uxs-sp-3)' } }>
				<div className="uxs-form__row" style={ { flex: 1 } }>
					<label htmlFor="uxs-new-popup-title">{ __( 'New popup title', 'ux-studio' ) }</label>
					<input
						id="uxs-new-popup-title"
						type="text"
						value={ newTitle }
						onChange={ ( e ) => setNewTitle( e.target.value ) }
					/>
				</div>
				<button
					type="button"
					className="button button-primary"
					disabled={ ! newTitle || create.isPending }
					onClick={ () => create.mutate() }
				>
					<Plus size={ 14 } /> { __( 'Add popup', 'ux-studio' ) }
				</button>
			</div>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Stats', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ ( popups ?? [] ).map( ( p ) => (
							<tr key={ p.id }>
								<td>{ p.title }</td>
								<td>
									<span className={ `uxs-badge ${ p.status === 'publish' ? 'is-success' : 'is-warning' }` }>
										{ p.status }
									</span>
								</td>
								<td>
									<StatsBadges id={ p.id } />
								</td>
								<td>
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Delete popup', 'ux-studio' ) }
										onClick={ () => remove.mutate( p.id ) }
										style={ { color: 'var(--uxs-danger)' } }
									>
										<Trash2 size={ 16 } />
									</button>
								</td>
							</tr>
						) ) }
						{ ! popups?.length ? (
							<tr>
								<td colSpan={ 4 }>{ __( 'No popups yet.', 'ux-studio' ) }</td>
							</tr>
						) : null }
					</tbody>
				</table>
			) }
		</>
	);
}
