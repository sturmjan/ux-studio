/**
 * Stock Photos: search stock-photo providers and import a result into the
 * media library. Route: #/module?id=stock-photos
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Check, Download, LoaderCircle, Search } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'search' | 'settings';

interface Provider {
	id: string;
	name: string;
	needs_key: boolean;
	has_key: boolean;
}

interface SearchResult {
	id: string;
	thumb_url: string;
	full_url: string;
	width: number;
	height: number;
	author: string;
	source_page_url: string;
}

interface SearchResponse {
	results: SearchResult[];
	total: number;
	page: number;
}

function SearchTab(): JSX.Element {
	const [ provider, setProvider ] = useState( 'openverse' );
	const [ query, setQuery ] = useState( '' );
	const [ activeQuery, setActiveQuery ] = useState( '' );
	const [ imported, setImported ] = useState< Record< string, boolean > >( {} );

	const { data: providers } = useQuery( {
		queryKey: [ 'stock-photos', 'providers' ],
		queryFn: () => api< Provider[] >( 'stock-photos/providers' ),
	} );

	const { data, isFetching } = useQuery( {
		queryKey: [ 'stock-photos', 'search', provider, activeQuery ],
		queryFn: () =>
			api< SearchResponse >(
				`stock-photos/search?provider=${ encodeURIComponent( provider ) }&query=${ encodeURIComponent( activeQuery ) }`
			),
		enabled: activeQuery !== '',
	} );

	const doImport = useMutation( {
		mutationFn: ( r: SearchResult ) =>
			api< { id: number } >( 'stock-photos/import', {
				method: 'POST',
				body: JSON.stringify( { provider, image_url: r.full_url, title: r.author } ),
			} ),
		onSuccess: ( _res, r ) => setImported( ( m ) => ( { ...m, [ r.id ]: true } ) ),
	} );

	return (
		<>
			<div
				className="uxs-form"
				style={ { marginBottom: 'var(--uxs-sp-5)', flexDirection: 'row', alignItems: 'flex-end', gap: 'var(--uxs-sp-3)' } }
			>
				<div className="uxs-form__row">
					<label htmlFor="uxs-sp-provider">{ __( 'Provider', 'ux-studio' ) }</label>
					<select id="uxs-sp-provider" value={ provider } onChange={ ( e ) => setProvider( e.target.value ) }>
						{ ( providers ?? [] ).map( ( p ) => (
							<option key={ p.id } value={ p.id } disabled={ p.needs_key && ! p.has_key }>
								{ p.name }{ p.needs_key && ! p.has_key ? ` (${ __( 'no API key', 'ux-studio' ) })` : '' }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row" style={ { flex: 1 } }>
					<label htmlFor="uxs-sp-query">{ __( 'Search', 'ux-studio' ) }</label>
					<input
						id="uxs-sp-query"
						type="text"
						value={ query }
						onChange={ ( e ) => setQuery( e.target.value ) }
						onKeyDown={ ( e ) => e.key === 'Enter' && setActiveQuery( query ) }
					/>
				</div>
				<button type="button" className="button button-primary" onClick={ () => setActiveQuery( query ) }>
					<Search size={ 14 } /> { __( 'Search', 'ux-studio' ) }
				</button>
			</div>

			{ isFetching ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<div className="uxs-grid">
					{ ( data?.results ?? [] ).map( ( r ) => (
						<div key={ r.id } className="uxs-tile" style={ { cursor: 'default' } }>
							<img
								src={ r.thumb_url }
								alt={ r.author }
								style={ { width: '100%', borderRadius: 'var(--uxs-radius-s)', aspectRatio: '4/3', objectFit: 'cover' } }
							/>
							<span className="uxs-tile__desc">{ r.author }</span>
							<button
								type="button"
								className="button"
								disabled={ doImport.isPending || imported[ r.id ] }
								onClick={ () => doImport.mutate( r ) }
							>
								{ imported[ r.id ] ? <Check size={ 14 } /> : <Download size={ 14 } /> }
								{ imported[ r.id ] ? __( 'Imported', 'ux-studio' ) : __( 'Import', 'ux-studio' ) }
							</button>
						</div>
					) ) }
					{ activeQuery && ! data?.results.length ? (
						<p>{ __( 'No results.', 'ux-studio' ) }</p>
					) : null }
				</div>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'search' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'stock-photos' );

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
					{ __( 'Stock Photos', 'ux-studio' ) }
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
				<button className={ tab === 'search' ? 'is-active' : '' } onClick={ () => setTab( 'search' ) }>
					{ __( 'Search', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'search' && <SearchTab /> }
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
