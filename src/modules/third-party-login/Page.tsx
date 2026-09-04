import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, LoaderCircle, Link2, Unlink } from 'lucide-react';
import { navigate } from '../../app/route';
import { api, queryClient } from '../../app/api';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'settings' | 'accounts';

interface Identity {
	provider: string;
	label: string;
	linked: boolean;
	email: string;
	linked_at: number;
}

interface IdentitiesPayload {
	identities: Identity[];
	can_link: boolean;
	role_allowed: boolean;
}

function AccountsTab(): JSX.Element {
	const identities = useQuery( {
		queryKey: [ 'tpl-identities' ],
		queryFn: () => api< IdentitiesPayload >( 'third-party-login/identities' ),
	} );

	const link = useMutation( {
		mutationFn: ( provider: string ) =>
			api< { redirect: string } >( `third-party-login/link/${ provider }`, { method: 'POST' } ),
		onSuccess: ( data ) => {
			if ( data.redirect ) {
				window.location.assign( data.redirect );
			}
		},
	} );

	const unlink = useMutation( {
		mutationFn: ( provider: string ) =>
			api( `third-party-login/unlink/${ provider }`, { method: 'POST' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'tpl-identities' ] } );
		},
	} );

	if ( identities.isLoading || ! identities.data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const { identities: rows, can_link: canLink, role_allowed: roleAllowed } = identities.data;

	return (
		<div className="uxs-form">
			<p>
				{ __(
					'Connect or disconnect a provider for your own account. Sign-in itself is handled by the central app, which posts a signed result back to this site.',
					'ux-studio'
				) }
			</p>
			{ ! roleAllowed && (
				<p className="uxs-form__help">
					{ __( 'Your role is not in the allowed list, so linking is disabled.', 'ux-studio' ) }
				</p>
			) }
			{ rows.length === 0 && (
				<p className="uxs-form__help">
					{ __( 'No providers are enabled yet. Enable providers in the Settings tab.', 'ux-studio' ) }
				</p>
			) }
			{ rows.map( ( row ) => (
				<div key={ row.provider } className="uxs-form__row">
					<label>{ row.label }</label>
					{ row.linked ? (
						<span style={ { display: 'flex', alignItems: 'center', gap: '8px' } }>
							<span>
								{ row.email
									? row.email
									: __( 'Connected', 'ux-studio' ) }
							</span>
							<button
								type="button"
								className="button"
								disabled={ unlink.isPending }
								onClick={ () => unlink.mutate( row.provider ) }
							>
								<Unlink size={ 14 } /> { __( 'Disconnect', 'ux-studio' ) }
							</button>
						</span>
					) : (
						<button
							type="button"
							className="button button-primary"
							disabled={ ! canLink || ! roleAllowed || link.isPending }
							onClick={ () => link.mutate( row.provider ) }
						>
							<Link2 size={ 14 } /> { __( 'Connect', 'ux-studio' ) }
						</button>
					) }
				</div>
			) ) }
			{ ! canLink && rows.length > 0 && (
				<p className="uxs-form__help">
					{ __(
						'Linking needs a central app URL and an HMAC secret configured in the Settings tab.',
						'ux-studio'
					) }
				</p>
			) }
		</div>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'settings' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'third-party-login' );

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
					{ __( 'Third-Party Login', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
				<button className={ tab === 'accounts' ? 'is-active' : '' } onClick={ () => setTab( 'accounts' ) }>
					{ __( 'Connected accounts', 'ux-studio' ) }
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
					<p>
						{ draft.has_hmac_secret
							? __( 'An HMAC secret is currently set.', 'ux-studio' )
							: __( 'No HMAC secret set yet.', 'ux-studio' ) }
					</p>
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
			{ tab === 'accounts' && <AccountsTab /> }
		</>
	);
}
