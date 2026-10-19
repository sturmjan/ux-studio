import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Check, LoaderCircle, Send } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

interface HealthResult {
	success: boolean;
	error: string;
	method: string;
	recipient: string;
	tested_at: string;
	timestamp: number;
}

function LastResult( { result }: { result: HealthResult | null | undefined } ): JSX.Element {
	if ( ! result ) {
		return <p>{ __( 'No test has been run yet.', 'ux-studio' ) }</p>;
	}
	return (
		<p>
			{ result.success ? (
				<span className="uxs-badge is-success">
					<Check size={ 12 } /> { __( 'Last test succeeded', 'ux-studio' ) }
				</span>
			) : (
				<span className="uxs-badge is-danger">{ __( 'Last test failed', 'ux-studio' ) }</span>
			) }{ ' ' }
			<span className="uxs-form__help">
				{ __( 'Method:', 'ux-studio' ) } { result.method || __( 'unknown', 'ux-studio' ) }
				{ ' · ' }
				{ __( 'Recipient:', 'ux-studio' ) } { result.recipient }
				{ ' · ' }
				{ __( 'Time:', 'ux-studio' ) } { result.tested_at }
			</span>
			{ ! result.success && result.error ? (
				<span className="uxs-form__help" style={ { display: 'block' } }>
					{ result.error }
				</span>
			) : null }
		</p>
	);
}

export default function Page(): JSX.Element {
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'email-health' );

	const status = useQuery( {
		queryKey: [ 'email-health', 'status' ],
		queryFn: () => api< HealthResult | null >( 'email-health/status' ),
	} );

	const test = useMutation( {
		mutationFn: () => api< HealthResult >( 'email-health/test', { method: 'POST', body: JSON.stringify( {} ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'email-health', 'status' ] } ),
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
					{ __( 'Email Health', 'ux-studio' ) }
				</h1>
			</header>

			{ status.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<LastResult result={ status.data } />
			) }
			<p>
				<button type="button" className="button" disabled={ test.isPending } onClick={ () => test.mutate() }>
					<Send size={ 14 } /> { __( 'Send test email now', 'ux-studio' ) }
				</button>
				{ test.error ? <span className="uxs-badge is-danger"> { ( test.error as Error ).message }</span> : null }
			</p>

			{ ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ data && (
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
