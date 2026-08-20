import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'settings' | 'accounts';

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
			{ tab === 'accounts' && (
				<div className="uxs-form">
					<p>
						{ __(
							'Visitors sign in through the central app, which handles the OAuth handshake with Google, Facebook or Apple and posts a signed result back to this site.',
							'ux-studio'
						) }
					</p>
					<p>
						{ __(
							'Account linking happens automatically on first login: if a WordPress user already exists with the matching email address, the provider account is linked to it. Otherwise a new WordPress user is created.',
							'ux-studio'
						) }
					</p>
					<p>
						{ __(
							'No separate list of connected accounts is stored by this module beyond WordPress’s own users — each linked provider is recorded as user meta on the corresponding WordPress user.',
							'ux-studio'
						) }
					</p>
				</div>
			) }
		</>
	);
}
