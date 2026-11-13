/**
 * Indexing & Sharing: refresh of the Facebook preview after a change (through UX1 Network app or with own keys)
 * and IndexNow. The settings are the generic cards (schema in the module); this page adds what a form cannot say:
 * whether UX1 Network app is connected, a "Verify keys" button, the IndexNow key address.
 * Route: #/module?id=indexing-notice
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle, TriangleAlert } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { TopicSettings } from '../../app/TopicSettings';
import type { Value } from '../../app/SettingsForm';

const MODULE = 'indexing-notice';

interface Status {
	central_app: { connected: boolean; paused: { state: 'clone' | 'superseded'; url: string } | null };
	own: { has_secret: boolean };
	indexnow: { key_url: string; others: string[]; indexable: boolean };
}

interface VerifyResult {
	ok: boolean;
	message: string;
	app_name: string;
}

/** Notes under the cards, driven by what is being edited. */
function Extras( { draft, status }: { draft: Record< string, Value >; status: Status | undefined } ): JSX.Element {
	const [ result, setResult ] = useState< VerifyResult | null >( null );
	const verify = useMutation( {
		mutationFn: () =>
			api< VerifyResult >( 'indexing-notice/verify-keys', {
				method: 'POST',
				body: JSON.stringify( { app_id: String( draft.social_fb_app_id ?? '' ), app_secret: String( draft.social_fb_app_secret ?? '' ) } ),
			} ),
		onSuccess: ( res ) => setResult( res ),
		onError: ( e ) => setResult( { ok: false, message: e instanceof Error ? e.message : String( e ), app_name: '' } ),
	} );

	const social = !! draft.social_refresh_enabled;
	const own = 'own' === draft.social_key_source;

	return (
		<>
			{ social && ! own && status && ! status.central_app.connected && (
				<p className="uxs-callout" role="note">
					<TriangleAlert size={ 14 } aria-hidden />{ ' ' }
					{ __( 'The site is not connected to UX1 Network app, so previews are not refreshed. Connect it (the setup wizard or the Content Sync module), or choose your own Facebook app.', 'ux-studio' ) }
				</p>
			) }
			{ social && ! own && status?.central_app.paused && (
				<p className="uxs-callout" role="note">
					<TriangleAlert size={ 14 } aria-hidden />{ ' ' }
					{ __( 'The connection to UX1 Network app is paused, because this site is a copy or was moved. Nothing is sent.', 'ux-studio' ) }
				</p>
			) }
			{ social && own && (
				<div className="uxs-form__row">
					<button type="button" className="button" disabled={ verify.isPending } onClick={ () => verify.mutate() }>
						{ verify.isPending ? <LoaderCircle size={ 14 } aria-hidden /> : <Check size={ 14 } aria-hidden /> } { __( 'Verify keys', 'ux-studio' ) }
					</button>
					<p className="uxs-form__help" role="status">
						{ result
							? result.ok && '' !== result.app_name
								? `${ result.message } (${ result.app_name })`
								: result.message
							: status?.own.has_secret
							? __( 'A secret is saved. Leave the field empty to keep it.', 'ux-studio' )
							: __( 'No secret is saved yet.', 'ux-studio' ) }
					</p>
				</div>
			) }
			{ !! draft.indexnow_enabled && status && (
				<>
					{ ! status.indexnow.indexable && (
						<p className="uxs-callout" role="note">
							<TriangleAlert size={ 14 } aria-hidden />{ ' ' }
							{ __( 'Search engines are discouraged on this site (Settings, Reading), so nothing is sent.', 'ux-studio' ) }
						</p>
					) }
					{ status.indexnow.others.length > 0 && (
						<p className="uxs-callout" role="note">
							{ sprintf(
								/* translators: %s: plugin names. */
								__( 'IndexNow is already sent by %s. This site stays quiet so a page is not sent twice.', 'ux-studio' ),
								status.indexnow.others.join( ', ' )
							) }
						</p>
					) }
					{ '' !== status.indexnow.key_url && (
						<p className="uxs-form__help">
							{ __( 'Key address', 'ux-studio' ) }: <a href={ status.indexnow.key_url } target="_blank" rel="noreferrer">{ status.indexnow.key_url }</a>
						</p>
					) }
				</>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const status = useQuery( {
		queryKey: [ MODULE, 'status' ],
		queryFn: () => api< Status >( 'indexing-notice/status' ),
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
					{ __( 'Indexing & Sharing', 'ux-studio' ) }
				</h1>
			</header>
			<TopicSettings moduleId={ MODULE } include={ () => true } onSaved={ () => void status.refetch() }>
				{ ( draft ) => <Extras draft={ draft } status={ status.data } /> }
			</TopicSettings>
		</>
	);
}
