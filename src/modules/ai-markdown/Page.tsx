/**
 * AI Markdown: serves a local (non-AI) markdown version of content for bots.
 * Route: #/module?id=ai-markdown
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle, RefreshCw } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'cache' | 'settings';

interface Stats {
	cached_posts: number;
	log_entries_24h: number;
}

interface CacheRow {
	post_id: number;
	title: string;
	generated_at: string;
}

function CacheTab(): JSX.Element {
	const { data: stats } = useQuery( {
		queryKey: [ 'ai-markdown', 'stats' ],
		queryFn: () => api< Stats >( 'ai-markdown/stats' ),
	} );
	const { data: rows, isLoading } = useQuery( {
		queryKey: [ 'ai-markdown', 'list' ],
		queryFn: () => api< CacheRow[] >( 'ai-markdown/list' ),
	} );

	const regenerateOne = useMutation( {
		mutationFn: ( id: number ) => api( `ai-markdown/regenerate/${ id }`, { method: 'POST' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'ai-markdown' ] } ),
	} );

	const regenerateAll = useMutation( {
		mutationFn: () => api( 'ai-markdown/regenerate-all', { method: 'POST' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'ai-markdown' ] } ),
	} );

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)', flexDirection: 'row', alignItems: 'center', gap: 'var(--uxs-sp-4)' } }>
				<p className="uxs-form__help" style={ { margin: 0 } }>
					{ __( 'Cached posts', 'ux-studio' ) }: { stats?.cached_posts ?? 0 } &nbsp;·&nbsp;
					{ __( 'Bot requests (24h)', 'ux-studio' ) }: { stats?.log_entries_24h ?? 0 }
				</p>
				<button
					type="button"
					className="button button-primary"
					disabled={ regenerateAll.isPending }
					onClick={ () => regenerateAll.mutate() }
				>
					<RefreshCw size={ 14 } /> { __( 'Regenerate all', 'ux-studio' ) }
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
							<th>{ __( 'Post', 'ux-studio' ) }</th>
							<th>{ __( 'Generated', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ ( rows ?? [] ).map( ( row ) => (
							<tr key={ row.post_id }>
								<td>{ row.title }</td>
								<td>{ row.generated_at }</td>
								<td>
									<button
										type="button"
										className="button-link"
										disabled={ regenerateOne.isPending }
										onClick={ () => regenerateOne.mutate( row.post_id ) }
									>
										{ __( 'Regenerate', 'ux-studio' ) }
									</button>
								</td>
							</tr>
						) ) }
						{ ! rows?.length ? (
							<tr>
								<td colSpan={ 3 }>{ __( 'Nothing cached yet.', 'ux-studio' ) }</td>
							</tr>
						) : null }
					</tbody>
				</table>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'cache' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'ai-markdown' );

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
					{ __( 'AI Markdown', 'ux-studio' ) }
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
				<button className={ tab === 'cache' ? 'is-active' : '' } onClick={ () => setTab( 'cache' ) }>
					{ __( 'Cache', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'cache' && <CacheTab /> }
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
