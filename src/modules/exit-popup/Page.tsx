import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Download, LoaderCircle } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'settings' | 'subscribers';

interface Subscriber {
	id: number;
	created_at: string;
	email: string;
	page_url: string;
}

interface ExportUrl {
	url: string;
}

function SubscribersTable(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'exit-popup', 'subscribers' ],
		queryFn: () => api< Subscriber[] >( 'exit-popup/subscribers' ),
	} );

	const exportUrl = useQuery( {
		queryKey: [ 'exit-popup', 'export-url' ],
		queryFn: () => api< ExportUrl >( 'exit-popup/export-url' ),
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
			<p>
				{ exportUrl.data ? (
					<a className="button" href={ exportUrl.data.url }>
						<Download size={ 14 } /> { __( 'Export CSV', 'ux-studio' ) }
					</a>
				) : null }
			</p>
			{ ! data || data.length === 0 ? (
				<p>{ __( 'No subscribers captured yet.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Date', 'ux-studio' ) }</th>
							<th>{ __( 'Email', 'ux-studio' ) }</th>
							<th>{ __( 'Page', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( row ) => (
							<tr key={ row.id }>
								<td>{ row.created_at }</td>
								<td>{ row.email }</td>
								<td>{ row.page_url }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'settings' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'exit-popup' );

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
					{ __( 'Exit Popup', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
				<button className={ tab === 'subscribers' ? 'is-active' : '' } onClick={ () => setTab( 'subscribers' ) }>
					{ __( 'Subscribers', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'settings' && ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
			{ tab === 'subscribers' && <SubscribersTable /> }
		</>
	);
}
