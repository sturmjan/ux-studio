import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { LoaderCircle, RefreshCw } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { formatDate } from '../../app/date';

interface CookieRow {
	name: string;
	purpose: string;
	duration: string;
	source: 'catalogue' | 'ocd';
}

interface ServiceRow {
	label: string;
	category: string;
	processor: string;
	country_name: string;
	transfer_text: string;
	policy: string;
	note: string;
	cookies: CookieRow[];
	hosts: string[];
	found: boolean;
}

interface QueueRow {
	host: string;
	kinds: Record< string, number >;
	pages: number;
	sources: string[];
	hits: number;
	examples: string[];
}

interface DecidedRow {
	host: string;
	category: string;
	label: string;
}

interface InventoryData {
	scanned_at: number;
	next_scan: number;
	pages: string[];
	services: ServiceRow[];
	queue: QueueRow[];
	decided: DecidedRow[];
	known: { host: string; label: string }[];
	ocd: { source?: string; license?: string; fetched?: string; cookies?: number };
}

type Choice = 'preferences' | 'statistics' | 'marketing' | 'necessary';

const KEY = [ 'consent', 'inventory' ];

function categoryLabel( category: string ): string {
	switch ( category ) {
		case 'necessary':
			return __( 'Necessary', 'ux-studio' );
		case 'preferences':
			return __( 'Preferences', 'ux-studio' );
		case 'statistics':
			return __( 'Statistics', 'ux-studio' );
		case 'marketing':
			return __( 'Marketing', 'ux-studio' );
		default:
			return category;
	}
}

function Loading(): JSX.Element {
	return (
		<div className="uxs-loading">
			<LoaderCircle size={ 18 } /> { __( 'Loading…', 'ux-studio' ) }
		</div>
	);
}

function kindsText( kinds: Record< string, number > ): string {
	const names: Record< string, string > = {
		script: __( 'script', 'ux-studio' ),
		style: __( 'style', 'ux-studio' ),
		frame: __( 'embedded frame', 'ux-studio' ),
		img: __( 'image', 'ux-studio' ),
	};
	return Object.keys( kinds )
		.map( ( k ) => names[ k ] ?? k )
		.join( ', ' );
}

/** Tab of the Cookie Consent page: services the site loads, their cookies, and the hosts waiting for a decision. */
export default function Inventory(): JSX.Element {
	const [ choice, setChoice ] = useState< Record< string, Choice > >( {} );
	const inventory = useQuery( { queryKey: KEY, queryFn: () => api< InventoryData >( '/consent/inventory' ) } );

	const scan = useMutation( {
		mutationFn: () => api< InventoryData >( '/consent/inventory/scan', { method: 'POST' } ),
		onSuccess: ( data ) => queryClient.setQueryData( KEY, data ),
	} );
	const assign = useMutation( {
		mutationFn: ( v: { host: string; category: Choice | 'remove' } ) => api< InventoryData >( '/consent/inventory/assign', { method: 'POST', body: JSON.stringify( v ) } ),
		onSuccess: ( data ) => queryClient.setQueryData( KEY, data ),
	} );

	if ( inventory.isLoading ) {
		return <Loading />;
	}
	if ( inventory.isError || ! inventory.data ) {
		return <p className="uxs-badge is-danger">{ ( inventory.error as Error | null )?.message ?? __( 'Could not load the inventory.', 'ux-studio' ) }</p>;
	}
	const data = inventory.data;

	return (
		<div className="uxs-form" style={ { maxWidth: 'none' } }>
			<p className="uxs-form__help">
				{ __(
					'The module reads a sample of your pages (home page, a few posts and pages, and the product, cart and checkout when WooCommerce runs) plus the hosts reported by the Content Security Policy, and matches every external host against the built-in list of services. It runs once a week and whenever you press the button. A host nobody knows waits below until you decide.',
					'ux-studio'
				) }
			</p>
			<div className="uxs-actions">
				<button type="button" className="button button-primary" disabled={ scan.isPending } onClick={ () => scan.mutate() }>
					{ scan.isPending ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Scan now', 'ux-studio' ) }
				</button>
				<span className="uxs-form__help">
					{ data.scanned_at
						? sprintf(
								/* translators: 1: date of the last scan, 2: number of pages read. */
								__( 'Last scan: %1$s, %2$d page(s) read.', 'ux-studio' ),
								formatDate( data.scanned_at ),
								data.pages.length
						  )
						: __( 'Not scanned yet.', 'ux-studio' ) }
					{ data.next_scan ? ' ' + sprintf( /* translators: %s: date of the next automatic scan. */ __( 'Next automatic scan: %s.', 'ux-studio' ), formatDate( data.next_scan ) ) : '' }
				</span>
			</div>
			{ scan.isError && <p className="uxs-badge is-danger">{ ( scan.error as Error ).message }</p> }
			{ assign.isError && <p className="uxs-badge is-danger">{ ( assign.error as Error ).message }</p> }

			<h3>{ __( 'To be classified', 'ux-studio' ) }</h3>
			{ data.queue.length === 0 ? (
				<p>{ data.scanned_at ? __( 'Nothing waits for a decision.', 'ux-studio' ) : __( 'Run a scan to find external hosts.', 'ux-studio' ) }</p>
			) : (
				<div className="uxs-table-wrap">
					<table className="uxs-table">
						<thead>
							<tr>
								<th>{ __( 'Host', 'ux-studio' ) }</th>
								<th>{ __( 'Found', 'ux-studio' ) }</th>
								<th>{ __( 'Category', 'ux-studio' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ data.queue.map( ( row ) => {
								const value = choice[ row.host ] ?? 'statistics';
								return (
									<tr key={ row.host }>
										<td>
											<code>{ row.host }</code>
											{ row.examples[ 0 ] && <div className="uxs-form__help">{ row.examples[ 0 ] }</div> }
										</td>
										<td>
											{ kindsText( row.kinds ) }
											{ row.pages > 0 && ' · ' + sprintf( /* translators: %d: number of pages. */ __( '%d page(s)', 'ux-studio' ), row.pages ) }
											{ row.sources.includes( 'csp' ) && ' · ' + __( 'CSP report', 'ux-studio' ) }
										</td>
										<td>
											<select value={ value } aria-label={ __( 'Category', 'ux-studio' ) } onChange={ ( e ) => setChoice( { ...choice, [ row.host ]: e.target.value as Choice } ) }>
												<option value="preferences">{ __( 'Preferences', 'ux-studio' ) }</option>
												<option value="statistics">{ __( 'Statistics', 'ux-studio' ) }</option>
												<option value="marketing">{ __( 'Marketing', 'ux-studio' ) }</option>
												<option value="necessary">{ __( 'Necessary (no consent needed)', 'ux-studio' ) }</option>
											</select>
										</td>
										<td>
											<button type="button" className="button button-small" disabled={ assign.isPending } onClick={ () => assign.mutate( { host: row.host, category: value } ) }>
												{ __( 'Classify', 'ux-studio' ) }
											</button>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
					<p className="uxs-form__help">
						{ __( 'A classified host is held back when its script is added by WordPress or a plugin. A script pasted directly into a page is held back only when "Also catch snippets pasted directly into the page" is on (tab Blocking).', 'ux-studio' ) }
					</p>
				</div>
			) }

			<h3>{ __( 'Services and cookies', 'ux-studio' ) }</h3>
			<p className="uxs-form__help">
				{ __( 'This is what the cookie banner and the cookie policy list. The texts come from the built-in catalogue; they describe what the services usually set, not what your site was proven to set.', 'ux-studio' ) }
			</p>
			<div className="uxs-table-wrap">
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Service', 'ux-studio' ) }</th>
							<th>{ __( 'Category', 'ux-studio' ) }</th>
							<th>{ __( 'Provider', 'ux-studio' ) }</th>
							<th>{ __( 'Cookies', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.services.map( ( s ) => (
							<tr key={ s.category + s.label }>
								<td>
									<strong>{ s.label }</strong>
									{ s.found && <span className="uxs-badge is-success" style={ { marginLeft: 6 } }>{ __( 'found', 'ux-studio' ) }</span> }
									{ s.hosts.length > 0 && <div className="uxs-form__help">{ s.hosts.join( ', ' ) }</div> }
								</td>
								<td>
									<span className="uxs-badge">{ categoryLabel( s.category ) }</span>
								</td>
								<td>
									{ s.processor }
									{ s.country_name && ' (' + s.country_name + ')' }
									{ s.transfer_text && <div className="uxs-form__help">{ s.transfer_text }</div> }
								</td>
								<td>
									{ s.cookies.length === 0 && <span className="uxs-form__help">{ s.note || __( 'No cookies of its own.', 'ux-studio' ) }</span> }
									{ s.cookies.map( ( c ) => (
										<div key={ c.name }>
											<code>{ c.name }</code> { c.purpose }
											{ c.duration && <span className="uxs-form__help"> ({ c.duration })</span> }
										</div>
									) ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>

			{ data.decided.length > 0 && (
				<>
					<h3>{ __( 'Your decisions', 'ux-studio' ) }</h3>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr>
									<th>{ __( 'Host', 'ux-studio' ) }</th>
									<th>{ __( 'Category', 'ux-studio' ) }</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ data.decided.map( ( d ) => (
									<tr key={ d.host }>
										<td>
											<code>{ d.host }</code>
										</td>
										<td>{ categoryLabel( d.category ) }</td>
										<td>
											<button type="button" className="button button-small" disabled={ assign.isPending } onClick={ () => assign.mutate( { host: d.host, category: 'remove' } ) }>
												{ __( 'Forget', 'ux-studio' ) }
											</button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</>
			) }

			{ data.known.length > 0 && (
				<p className="uxs-form__help">
					{ sprintf(
						/* translators: %s: list of hosts. */
						__( 'Known and not needing consent (fonts, script libraries and similar): %s.', 'ux-studio' ),
						data.known.map( ( k ) => k.host ).join( ', ' )
					) }
				</p>
			) }
			{ data.ocd.source && (
				<p className="uxs-form__help">
					{ sprintf(
						/* translators: 1: number of cookies, 2: date. */
						__( 'Cookie descriptions for services without a list of their own come from the Open Cookie Database (%1$d cookies, downloaded %2$s), Apache License 2.0, https://github.com/jkwakman/Open-Cookie-Database. The copy is reduced and the descriptions are shortened.', 'ux-studio' ),
						data.ocd.cookies ?? 0,
						data.ocd.fetched ?? ''
					) }
				</p>
			) }
		</div>
	);
}
