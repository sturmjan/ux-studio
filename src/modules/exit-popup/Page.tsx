import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Download, LoaderCircle } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { TopicSettings, inSections } from '../../app/TopicSettings';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';


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
								<td>{ formatDate( row.created_at ) }</td>
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

const MODULE = 'exit-popup';

/** One menu item per settings section; subscribers sit with the e-mail capture they come from. */
const TOPICS: { id: string; label: string }[] = [
	{ id: 'content', label: __( 'Content', 'ux-studio' ) },
	{ id: 'appearance', label: __( 'Appearance', 'ux-studio' ) },
	{ id: 'triggers', label: __( 'Triggers', 'ux-studio' ) },
	{ id: 'targeting', label: __( 'Targeting', 'ux-studio' ) },
	{ id: 'email', label: __( 'Email capture', 'ux-studio' ) },
];

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< string >( 'content' );
	const topic = TOPICS.find( ( t ) => t.id === tab ) ?? ( TOPICS[ 0 ] as ( typeof TOPICS )[ number ] );

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
			<TabsNav>
				{ TOPICS.map( ( t ) => (
					<button key={ t.id } className={ tab === t.id ? 'is-active' : '' } onClick={ () => setTab( t.id ) }>
						{ t.label }
					</button>
				) ) }
			</TabsNav>
			<TopicSettings key={ topic.id } moduleId={ MODULE } include={ inSections( topic.label ) } />
			{ topic.id === 'email' && (
				<section className="uxs-topic-data">
					<h3>{ __( 'Subscribers', 'ux-studio' ) }</h3>
					<SubscribersTable />
				</section>
			) }
		</>
	);
}
