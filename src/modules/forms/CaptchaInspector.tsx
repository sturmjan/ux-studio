/**
 * Inspector of the CAPTCHA field: which provider the field really uses, where its keys come from, and what the
 * administrator can do about it. The order is decided on the server (Forms FormsCaptcha, SecurityOptimization
 * CaptchaResolver): keys from the central application, then the site's own keys, then the key-less Shield. No secret
 * ever reaches this screen.
 */
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { CircleAlert, CircleCheck, LoaderCircle, ShieldCheck } from 'lucide-react';
import { ApiError, api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';

type Provider = 'turnstile' | 'recaptcha_v2' | 'recaptcha_v3' | 'shield';

interface CaptchaStatus {
	provider: Provider;
	source: 'ca' | 'own' | 'shield';
	connected: boolean;
	has_keys: boolean;
	keys_from_ca: boolean;
	can_provision: boolean;
}

const KEY = [ 'forms', 'captcha-status' ];

function providerName( provider: Provider ): string {
	switch ( provider ) {
		case 'turnstile':
			return __( 'Cloudflare Turnstile', 'ux-studio' );
		case 'recaptcha_v2':
			return __( 'Google reCAPTCHA v2', 'ux-studio' );
		case 'recaptcha_v3':
			return __( 'Google reCAPTCHA v3', 'ux-studio' );
		default:
			return __( 'UX Studio Shield', 'ux-studio' );
	}
}

/** The line at the top: "Using: ... (where the keys come from)". */
export function captchaStatusLine( status: CaptchaStatus ): string {
	if ( 'shield' === status.provider ) {
		return sprintf(
			/* translators: %s: provider name. */
			__( 'Using: %s (no keys)', 'ux-studio' ),
			providerName( status.provider )
		);
	}
	if ( 'ca' === status.source ) {
		return sprintf(
			/* translators: %s: provider name. */
			__( 'Using: %s (keys from the central application)', 'ux-studio' ),
			providerName( status.provider )
		);
	}
	return sprintf(
		/* translators: %s: provider name. */
		__( 'Using: %s (own keys)', 'ux-studio' ),
		providerName( status.provider )
	);
}

export default function CaptchaInspector(): JSX.Element {
	const status = useQuery( { queryKey: KEY, queryFn: () => api< CaptchaStatus >( 'forms/captcha/status' ) } );

	const provision = useMutation( {
		mutationFn: () => api< CaptchaStatus >( 'forms/captcha/provision', { method: 'POST' } ),
		onSuccess: ( data ) => queryClient.setQueryData( KEY, data ),
	} );

	if ( status.isLoading || ! status.data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 18 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const s = status.data;
	const error = provision.error instanceof ApiError || provision.error instanceof Error ? provision.error.message : '';

	return (
		<div className="uxs-form__row uxs-fb-captcha">
			<p style={ { display: 'flex', gap: 'var(--uxs-sp-2)', alignItems: 'center', margin: 0 } }>
				<ShieldCheck size={ 16 } aria-hidden />
				<strong>{ captchaStatusLine( s ) }</strong>
			</p>

			{ 'shield' === s.provider && (
				<p className="uxs-form__help">
					{ __( 'The visitor\'s browser solves a small puzzle by itself: no account, no keys, no cookies. It stops mass scripted submissions, not a determined attacker.', 'ux-studio' ) }
				</p>
			) }

			{ s.can_provision && ! s.has_keys && (
				<>
					<p className="uxs-form__help">
						{ __( 'This site is connected to the central application, which can give it Cloudflare Turnstile keys. The same keys then protect the login forms too.', 'ux-studio' ) }
					</p>
					<button type="button" className="button" disabled={ provision.isPending } onClick={ () => provision.mutate() }>
						{ provision.isPending ? __( 'Getting keys…', 'ux-studio' ) : __( 'Get keys from the central application', 'ux-studio' ) }
					</button>
				</>
			) }

			{ provision.isSuccess && (
				<p className="uxs-form__help" role="status" style={ { display: 'flex', gap: 'var(--uxs-sp-2)', alignItems: 'center' } }>
					<CircleCheck size={ 16 } aria-hidden /> { __( 'The keys were stored. The form now uses Cloudflare Turnstile.', 'ux-studio' ) }
				</p>
			) }
			{ '' !== error && (
				<p className="uxs-form__help" role="alert" style={ { display: 'flex', gap: 'var(--uxs-sp-2)', alignItems: 'center', color: 'var(--uxs-danger)' } }>
					<CircleAlert size={ 16 } aria-hidden />
					{ sprintf(
						/* translators: %s: error text of the central application. */
						__( 'Getting the keys failed: %s', 'ux-studio' ),
						error
					) }
				</p>
			) }

			{ ! s.connected && (
				<p className="uxs-form__help">
					{ __( 'The site is not connected to the central application, so the Shield protects this form for now.', 'ux-studio' ) }{ ' ' }
					<button type="button" className="button-link" onClick={ () => navigate( 'wizard', { mode: 'new', step: 'ca' } ) }>
						{ __( 'Connect to the central application', 'ux-studio' ) }
					</button>
				</p>
			) }
		</div>
	);
}
