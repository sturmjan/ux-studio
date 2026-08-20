import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, History, LoaderCircle, RotateCcw } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';

type Tab = 'plugins' | 'themes';
type ItemType = 'plugin' | 'theme';

interface RollbackItem {
	slug: string;
	name: string;
	version: string;
	file?: string;
}

interface ItemsResponse {
	plugins: RollbackItem[];
	themes: RollbackItem[];
}

interface VersionEntry {
	version: string;
	current: boolean;
}

interface RollbackResult {
	success: boolean;
	type: ItemType;
	slug: string;
	from_version: string;
	to_version: string;
}

function VersionsSelect( {
	type,
	slug,
	value,
	onChange,
}: {
	type: ItemType;
	slug: string;
	value: string;
	onChange: ( version: string ) => void;
} ): JSX.Element {
	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'rollback-manager', 'versions', type, slug ],
		queryFn: () => api< VersionEntry[] >( `rollback-manager/versions/${ type }/${ slug }` ),
		staleTime: 5 * 60_000,
	} );

	if ( isLoading ) {
		return <span className="uxs-loading" style={ { justifyContent: 'flex-start' } }><LoaderCircle size={ 14 } /></span>;
	}

	if ( isError || ! data || data.length === 0 ) {
		return <span className="uxs-badge is-warning">{ __( 'No versions found', 'ux-studio' ) }</span>;
	}

	return (
		<select value={ value } onChange={ ( e ) => onChange( e.target.value ) }>
			<option value="">{ __( 'Select a version…', 'ux-studio' ) }</option>
			{ data.map( ( v ) => (
				<option key={ v.version } value={ v.version }>
					{ v.version }{ v.current ? ` (${ __( 'current', 'ux-studio' ) })` : '' }
				</option>
			) ) }
		</select>
	);
}

function ItemsTable( { type, items }: { type: ItemType; items: RollbackItem[] } ): JSX.Element {
	const queryClient = useQueryClient();
	const [ selected, setSelected ] = useState< Record< string, string > >( {} );
	const [ result, setResult ] = useState< Record< string, { success: boolean; message: string } > >( {} );

	const rollback = useMutation( {
		mutationFn: ( vars: { slug: string; version: string } ) =>
			api< RollbackResult >( 'rollback-manager/rollback', {
				method: 'POST',
				body: JSON.stringify( { type, slug: vars.slug, version: vars.version } ),
			} ),
	} );

	const doRollback = ( item: RollbackItem ): void => {
		const version = selected[ item.slug ];
		if ( ! version ) {
			return;
		}
		rollback.mutate(
			{ slug: item.slug, version },
			{
				onSuccess: ( data ) => {
					setResult( ( prev ) => ( {
						...prev,
						[ item.slug ]: {
							success: true,
							message: sprintf(
								/* translators: %s: version number */
								__( 'Rolled back to %s', 'ux-studio' ),
								data.to_version
							),
						},
					} ) );
					queryClient.invalidateQueries( { queryKey: [ 'rollback-manager' ] } );
				},
				onError: ( error: unknown ) => {
					setResult( ( prev ) => ( {
						...prev,
						[ item.slug ]: {
							success: false,
							message: error instanceof Error ? error.message : __( 'Unknown error', 'ux-studio' ),
						},
					} ) );
				},
			}
		);
	};

	if ( items.length === 0 ) {
		return (
			<p>
				{ 'plugin' === type
					? __( 'No installed plugins from WordPress.org were found.', 'ux-studio' )
					: __( 'No installed themes from WordPress.org were found.', 'ux-studio' ) }
			</p>
		);
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Name', 'ux-studio' ) }</th>
					<th>{ __( 'Current version', 'ux-studio' ) }</th>
					<th>{ __( 'Roll back to', 'ux-studio' ) }</th>
					<th>{ __( 'Action', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ items.map( ( item ) => {
					const isBusy = rollback.isPending && rollback.variables?.slug === item.slug;
					const itemResult = result[ item.slug ];
					return (
						<tr key={ item.slug }>
							<td>{ item.name }</td>
							<td>{ item.version }</td>
							<td>
								<VersionsSelect
									type={ type }
									slug={ item.slug }
									value={ selected[ item.slug ] ?? '' }
									onChange={ ( version ) =>
										setSelected( ( prev ) => ( { ...prev, [ item.slug ]: version } ) )
									}
								/>
							</td>
							<td>
								<button
									type="button"
									className="button"
									disabled={ ! selected[ item.slug ] || isBusy }
									onClick={ () => doRollback( item ) }
								>
									{ isBusy ? (
										<LoaderCircle size={ 14 } className="uxs-spin" />
									) : (
										<RotateCcw size={ 14 } />
									) }{ ' ' }
									{ __( 'Rollback', 'ux-studio' ) }
								</button>
								{ itemResult && (
									<div style={ { marginTop: 'var(--uxs-sp-2)' } }>
										<span className={ `uxs-badge ${ itemResult.success ? 'is-success' : 'is-danger' }` }>
											{ itemResult.message }
										</span>
									</div>
								) }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'plugins' );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'rollback-manager', 'items' ],
		queryFn: () => api< ItemsResponse >( 'rollback-manager/items' ),
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
					<History size={ 18 } style={ { verticalAlign: 'middle' } } /> { __( 'Rollback Manager', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'plugins' ? 'is-active' : '' } onClick={ () => setTab( 'plugins' ) }>
					{ __( 'Plugins', 'ux-studio' ) }
				</button>
				<button className={ tab === 'themes' ? 'is-active' : '' } onClick={ () => setTab( 'themes' ) }>
					{ __( 'Themes', 'ux-studio' ) }
				</button>
			</div>
			{ isLoading || ! data ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<>
					{ tab === 'plugins' && <ItemsTable type="plugin" items={ data.plugins } /> }
					{ tab === 'themes' && <ItemsTable type="theme" items={ data.themes } /> }
				</>
			) }
		</>
	);
}
