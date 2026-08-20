import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Send } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'stats' | 'settings';

interface GrrStat {
	id: number;
	created_at: string;
	recipient_email: string;
	status: string;
	order_id: number | null;
}

interface SendResult {
	success: boolean;
	email: string;
}

function SendForm(): JSX.Element {
	const [ email, setEmail ] = useState( '' );
	const [ orderId, setOrderId ] = useState( '' );

	const send = useMutation( {
		mutationFn: () =>
			api< SendResult >( 'google-review-request/send', {
				method: 'POST',
				body: JSON.stringify( {
					email,
					...( orderId ? { order_id: Number( orderId ) } : {} ),
				} ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'google-review-request', 'stats' ] } );
			setEmail( '' );
			setOrderId( '' );
		},
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-grr-email">{ __( 'Recipient email', 'ux-studio' ) }</label>
				<input
					id="uxs-grr-email"
					type="email"
					value={ email }
					onChange={ ( e ) => setEmail( e.target.value ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-grr-order-id">{ __( 'Order ID (optional)', 'ux-studio' ) }</label>
				<input
					id="uxs-grr-order-id"
					type="number"
					value={ orderId }
					onChange={ ( e ) => setOrderId( e.target.value ) }
				/>
			</div>
			<button
				type="button"
				className="button button-primary"
				disabled={ send.isPending || ! email }
				onClick={ () => send.mutate() }
			>
				<Send size={ 14 } /> { __( 'Send', 'ux-studio' ) }
			</button>
			{ send.data && (
				<p>
					{ send.data.success ? (
						<span className="uxs-badge is-success">{ __( 'Sent to', 'ux-studio' ) } { send.data.email }</span>
					) : (
						<span className="uxs-badge is-danger">{ __( 'Failed to send', 'ux-studio' ) }</span>
					) }
				</p>
			) }
		</div>
	);
}

function StatsTable(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'google-review-request', 'stats' ],
		queryFn: () => api< GrrStat[] >( 'google-review-request/stats' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No review requests have been sent yet.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th>{ __( 'Recipient', 'ux-studio' ) }</th>
					<th>{ __( 'Status', 'ux-studio' ) }</th>
					<th>{ __( 'Order ID', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( row ) => (
					<tr key={ row.id }>
						<td>{ row.created_at }</td>
						<td>{ row.recipient_email }</td>
						<td>
							<span className={ `uxs-badge ${ row.status === 'sent' ? 'is-success' : 'is-danger' }` }>
								{ row.status === 'sent' ? __( 'Sent', 'ux-studio' ) : __( 'Failed', 'ux-studio' ) }
							</span>
						</td>
						<td>{ row.order_id ?? '' }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'stats' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'google-review-request' );

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
					{ __( 'Google Review Request', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'stats' ? 'is-active' : '' } onClick={ () => setTab( 'stats' ) }>
					{ __( 'Stats', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'stats' && (
				<>
					<SendForm />
					<StatsTable />
				</>
			) }
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
		</>
	);
}
