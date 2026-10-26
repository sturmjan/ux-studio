/**
 * Wizard step "Central application": a short presentation of what the Central App gives the owner of the site,
 * what connecting changes on the site, and the connect / skip toolbar that stays at the bottom edge of the screen
 * (PLAN.md 29.6). The connect protocol itself (prepare + a top-level POST to the Central App) is unchanged.
 */
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import {
	Accessibility,
	Activity,
	ArrowRight,
	Bell,
	CircleAlert,
	CircleCheck,
	Cloud,
	Gauge,
	Globe,
	Hourglass,
	Info,
	LifeBuoy,
	Network,
	Newspaper,
	PlugZap,
	ScanEye,
	ShieldAlert,
	TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { api, queryClient } from '../api';
import { formatDate } from '../date';
import { hashParam } from '../route';
import { ErrorLine } from './parts';
import type { StarterCtx } from './starter-context';
import type { CaPrepare, CaStatus } from './starter-types';

function message( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

interface Feature {
	id: string;
	icon: LucideIcon;
	title: string;
	text: string;
}

/** What the Central App really does (checked against its code, see PLAN.md 29.6). */
function features(): Feature[] {
	return [
		{
			id: 'uptime',
			icon: Activity,
			title: __( 'Availability', 'ux-studio' ),
			text: __( 'Checks the site regularly, alerts you when it goes down and when it is back, and keeps the history of uptime and response time. It also tracks when the SSL certificate and the domain expire.', 'ux-studio' ),
		},
		{
			id: 'vulnerabilities',
			icon: ShieldAlert,
			title: __( 'Known vulnerabilities', 'ux-studio' ),
			text: __( 'Compares the plugins, themes and WordPress version of the site with a vulnerability database and warns you when something installed is affected. Google Safe Browsing tells you if the site gets flagged as dangerous.', 'ux-studio' ),
		},
		{
			id: 'php',
			icon: Hourglass,
			title: __( 'Outdated PHP', 'ux-studio' ),
			text: __( 'Shows which PHP version the site runs on and warns you when it no longer gets security updates or has a critical vulnerability.', 'ux-studio' ),
		},
		{
			id: 'speed',
			icon: Gauge,
			title: __( 'Speed and SEO', 'ux-studio' ),
			text: __( 'Measures the speed with Google PageSpeed regularly and keeps the history. A public SEO test and audit check the basics of search engine optimisation.', 'ux-studio' ),
		},
		{
			id: 'quality',
			icon: ScanEye,
			title: __( 'Looks and accessibility', 'ux-studio' ),
			text: __( 'Takes screenshots of key pages every day and compares them, so a broken layout does not go unnoticed. Once a month it scans the site for accessibility problems.', 'ux-studio' ),
		},
		{
			id: 'alerts',
			icon: Bell,
			title: __( 'Alerts and reports', 'ux-studio' ),
			text: __( 'Alerts arrive by e-mail, Discord, Slack or as a notification on your phone or computer, with a daily digest if you prefer fewer messages. Regular e-mail reports sum up how the site is doing.', 'ux-studio' ),
		},
		{
			id: 'shared',
			icon: Network,
			title: __( 'Shared protection', 'ux-studio' ),
			text: __( 'Ban an IP address, a range or a whole country once and it is blocked on every connected site. The central application also gives the site its CAPTCHA keys, so you set nothing up by hand.', 'ux-studio' ),
		},
		{
			id: 'tickets',
			icon: LifeBuoy,
			title: __( 'Support tickets', 'ux-studio' ),
			text: __( 'Service requests from the site land in one ticket queue, with replies and response deadlines.', 'ux-studio' ),
		},
		{
			id: 'content',
			icon: Newspaper,
			title: __( 'Content, social networks and reviews', 'ux-studio' ),
			text: __( 'Edit posts, pages and media of the site from one place, plan posts for Facebook, Instagram and LinkedIn and get help from the AI assistant. Google reviews of the business are collected in one list.', 'ux-studio' ),
		},
	];
}

/** Hub and satellites, drawn with CSS and lucide icons only. */
function HeroArt(): JSX.Element {
	return (
		<div className="uxs-wiz-ca__art" aria-hidden>
			<svg viewBox="0 0 200 150" className="uxs-wiz-ca__lines" fill="none">
				<path d="M100 75 L34 34 M100 75 L168 30 M100 75 L30 116 M100 75 L170 112" />
			</svg>
			<span className="uxs-wiz-ca__hub">
				<Cloud size={ 34 } aria-hidden />
			</span>
			<span className="uxs-wiz-ca__node is-a"><Globe size={ 16 } aria-hidden /></span>
			<span className="uxs-wiz-ca__node is-b"><Globe size={ 16 } aria-hidden /></span>
			<span className="uxs-wiz-ca__node is-c"><Globe size={ 16 } aria-hidden /></span>
			<span className="uxs-wiz-ca__node is-d"><Globe size={ 16 } aria-hidden /></span>
		</div>
	);
}

function CaCaptcha( { status, onChange }: { status: CaStatus; onChange: () => void } ): JSX.Element {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ note, setNote ] = useState< string | null >( null );
	const captcha = status.captcha;

	const toggle = async ( enabled: boolean ): Promise< void > => {
		setBusy( true );
		setError( null );
		setNote( null );
		try {
			const res = await api< CaStatus >( 'onboarding/ca/captcha', { method: 'POST', body: JSON.stringify( { enabled } ) } );
			queryClient.setQueryData( [ 'onboarding', 'ca-status' ], res );
			if ( enabled && ! res.captcha.has_keys ) {
				setNote( __( 'The central application did not give this site CAPTCHA keys. The CAPTCHA is not enforced, so logging in works as before. You can add the keys by hand in Security > CAPTCHA.', 'ux-studio' ) );
			}
			onChange();
		} catch ( e ) {
			setError( message( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="uxs-wiz-box uxs-wiz-captcha">
			<label className="uxs-wiz-checkrow">
				<input type="checkbox" checked={ captcha.enabled } disabled={ busy || ! captcha.security_enabled } onChange={ ( e ) => void toggle( e.target.checked ) } />
				<span>{ __( 'CAPTCHA with keys from the central application', 'ux-studio' ) }</span>
			</label>
			<p className="uxs-wiz-muted">
				{ __( 'Adds a Cloudflare Turnstile check to the login, registration and lost password forms. The central application gives the site its keys, so you paste nothing. If the keys do not arrive, the check is not enforced and nobody is locked out.', 'ux-studio' ) }
			</p>
			{ ! captcha.security_enabled && <p className="uxs-wiz-muted">{ __( 'The Security module is off. Switch it on to use the CAPTCHA.', 'ux-studio' ) }</p> }
			{ captcha.enabled && captcha.has_keys && (
				<p className="uxs-wiz-banner is-ok" role="status">
					<CircleCheck size={ 16 } aria-hidden /> { __( 'The CAPTCHA is on with keys from the central application.', 'ux-studio' ) }
				</p>
			) }
			{ note && (
				<p className="uxs-wiz-banner is-bad" role="status">
					<CircleAlert size={ 16 } aria-hidden /> { note }
				</p>
			) }
			<ErrorLine text={ error } />
		</div>
	);
}

function Faq( { question, children }: { question: string; children: ReactNode } ): JSX.Element {
	return (
		<details className="uxs-wiz-ca__faq">
			<summary>{ question }</summary>
			<p>{ children }</p>
		</details>
	);
}

export function CaStep( { ctx }: { ctx: StarterCtx } ): JSX.Element {
	const returned = 'done' === hashParam( 'ca' );
	const cancelled = '1' === hashParam( 'ux1_connect_cancelled' );
	const connected = '1' === hashParam( 'ux1_connected' );
	const status = useQuery( {
		queryKey: [ 'onboarding', 'ca-status' ],
		queryFn: () => api< CaStatus >( 'onboarding/ca/status' ),
		staleTime: 0,
		refetchOnMount: 'always',
	} );
	const [ url, setUrl ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ form, setForm ] = useState< CaPrepare | null >( null );
	const [ go, setGo ] = useState( false );
	const formRef = useRef< HTMLFormElement >( null );
	const st = status.data;

	// The address field starts with what is stored (or the production address).
	useEffect( () => {
		if ( st && '' === url ) {
			setUrl( '' !== st.central_app_url ? st.central_app_url : st.default_url );
		}
	}, [ st, url ] );

	// The browser leaves for the central application: a plain top-level POST, the only way to carry the key there.
	useEffect( () => {
		if ( go && form ) {
			formRef.current?.submit();
		}
	}, [ go, form ] );

	const prepare = async (): Promise< void > => {
		setBusy( true );
		setError( null );
		try {
			const res = await api< CaPrepare >( 'onboarding/ca/prepare', { method: 'POST', body: JSON.stringify( { url } ) } );
			setForm( res );
			if ( res.ping.ok && 0 === res.warnings.length ) {
				setGo( true );
			}
		} catch ( e ) {
			setError( message( e ) );
		} finally {
			setBusy( false );
		}
	};

	const showSuccess = returned && connected && !! st?.ready;
	const attention = !! form && ! go;
	const steps: Array< [ string, string ] > = [
		[ __( 'Connect', 'ux-studio' ), __( 'One click here. The site proves who it is with a secret key.', 'ux-studio' ) ],
		[ __( 'Sign in or create an account', 'ux-studio' ), __( 'You land in the central application, log in or sign up and confirm the connection.', 'ux-studio' ) ],
		[ __( 'Done, the site is watched', 'ux-studio' ), __( 'You come back here and the central application starts keeping an eye on the site.', 'ux-studio' ) ],
	];

	return (
		<section className="uxs-wiz-panel uxs-wiz-ca">
			<div className="uxs-wiz-ca__hero">
				<div className="uxs-wiz-ca__herotext">
					<span className="uxs-wiz-ca__eyebrow">
						<PlugZap size={ 16 } aria-hidden /> { __( 'Optional step', 'ux-studio' ) }
					</span>
					<h2>{ __( 'Central application', 'ux-studio' ) }</h2>
					<p>{ __( 'One place to watch over all your websites. Connect this one and the central application keeps an eye on it for you: whether it runs, whether it is safe and fast, and whether anything is about to expire.', 'ux-studio' ) }</p>
				</div>
				<HeroArt />
			</div>

			{ showSuccess && st && (
				<>
					<p className="uxs-wiz-banner is-ok" role="status">
						<CircleCheck size={ 16 } aria-hidden />
						{ __( 'The connection was completed in the central application.', 'ux-studio' ) }
						{ st.contact_after && st.last_contact > 0 && (
							<>
								{ ' ' }
								{ sprintf(
									/* translators: %s: date and time. */
									__( 'It last contacted the site on %s.', 'ux-studio' ),
									formatDate( st.last_contact )
								) }
							</>
						) }
					</p>
					<CaCaptcha status={ st } onChange={ () => void status.refetch() } />
				</>
			) }

			{ returned && cancelled && (
				<p className="uxs-wiz-banner is-bad" role="status">
					<CircleAlert size={ 16 } aria-hidden /> { __( 'The connection was cancelled in the central application. Nothing is connected. You can try again.', 'ux-studio' ) }
				</p>
			) }

			{ returned && ! cancelled && ! connected && st && (
				<p className={ `uxs-wiz-banner ${ st.ready && st.contact_after ? 'is-ok' : 'is-bad' }` } role="status">
					{ st.ready && st.contact_after ? <CircleCheck size={ 16 } aria-hidden /> : <CircleAlert size={ 16 } aria-hidden /> }
					{ st.ready && st.contact_after
						? __( 'The central application has contacted the site. The connection looks complete.', 'ux-studio' )
						: __( 'The central application has not confirmed the connection yet. Try again if you did not finish it there.', 'ux-studio' ) }
				</p>
			) }

			{ ! showSuccess && (
				<>
					<h3 className="uxs-wiz-ca__h">{ __( 'How it works', 'ux-studio' ) }</h3>
					<ol className="uxs-wiz-ca__how">
						{ steps.map( ( [ title, text ], i ) => (
							<li key={ title }>
								<span className="uxs-wiz-ca__num">{ i + 1 }</span>
								<div>
									<strong>{ title }</strong>
									<p>{ text }</p>
								</div>
							</li>
						) ) }
					</ol>

					<h3 className="uxs-wiz-ca__h">{ __( 'What you get', 'ux-studio' ) }</h3>
					<ul className="uxs-wiz-ca__grid">
						{ features().map( ( f ) => (
							<li key={ f.id } className="uxs-wiz-ca__card">
								<span className="uxs-wiz-ca__cardicon">
									<f.icon size={ 20 } aria-hidden />
								</span>
								<h4>{ f.title }</h4>
								<p>{ f.text }</p>
							</li>
						) ) }
					</ul>

					<div className="uxs-wiz-ca__changes">
						<h3>{ __( 'What changes on your site', 'ux-studio' ) }</h3>
						<ul>
							<li>
								<Info size={ 18 } aria-hidden />
								<span>{ __( 'This switches on Content Sync as a managed site and creates a secret key for it. Administrator sign-in and package installation from the central application stay off.', 'ux-studio' ) }</span>
							</li>
							<li>
								<Info size={ 18 } aria-hidden />
								<span>{ __( 'The central application can then read the state of the site and work with its posts, pages and media. It cannot sign in as an administrator or install plugins.', 'ux-studio' ) }</span>
							</li>
							<li>
								<Info size={ 18 } aria-hidden />
								<span>{ __( 'The site must be reachable from the internet: the central application checks it while connecting.', 'ux-studio' ) }</span>
							</li>
						</ul>
					</div>

					{ st?.local && (
						<div className="uxs-wiz-callout is-warn" role="group">
							<TriangleAlert size={ 20 } aria-hidden />
							<div>
								<strong>{ __( 'This site runs on your computer', 'ux-studio' ) }</strong>
								<p>{ __( 'A central application on the internet cannot reach it, so the connection will fail, unless the central application runs on your computer too.', 'ux-studio' ) }</p>
							</div>
						</div>
					) }

					<h3 className="uxs-wiz-ca__h">{ __( 'Questions', 'ux-studio' ) }</h3>
					<div className="uxs-wiz-ca__faqs">
						<Faq question={ __( 'What can the central application do on my site?', 'ux-studio' ) }>
							{ __( 'It reads the state of the site and works with posts, pages and media through requests signed with the secret key. Signing in as an administrator and installing packages from the central application are switched off.', 'ux-studio' ) }
						</Faq>
						<Faq question={ __( 'How do I disconnect the site?', 'ux-studio' ) }>
							{ __( 'Switch off the Content Sync module and the central application can no longer reach the site. You can also remove the site from the list of websites in the central application.', 'ux-studio' ) }
						</Faq>
						<Faq question={ __( 'Can I do this later?', 'ux-studio' ) }>
							{ __( 'Yes. Skip this step now and connect later in the Content Sync module.', 'ux-studio' ) }
						</Faq>
					</div>

					<details className="uxs-wiz-advanced">
						<summary>{ __( 'Advanced', 'ux-studio' ) }</summary>
						<label htmlFor="uxs-wiz-ca-url">{ __( 'Address of the central application', 'ux-studio' ) }</label>
						<input id="uxs-wiz-ca-url" type="url" className="regular-text" value={ url } disabled={ busy || go } onChange={ ( e ) => setUrl( e.target.value ) } />
						<p className="uxs-wiz-muted">{ __( 'Only https:// addresses work. http:// is accepted for localhost, for testing on your own computer.', 'ux-studio' ) }</p>
					</details>

					<ErrorLine text={ error } />

					{ attention && form && (
						<div className="uxs-wiz-callout is-warn" role="alert">
							<TriangleAlert size={ 20 } aria-hidden />
							<div>
								<strong>{ __( 'Check before you continue', 'ux-studio' ) }</strong>
								{ ! form.ping.ok && (
									<p>
										{ sprintf(
											/* translators: %s: error detail. */
											__( 'The site did not answer its own signed test request (%s). The central application will probably not be able to confirm the connection either.', 'ux-studio' ),
											'' !== form.ping.error ? form.ping.error : `HTTP ${ form.ping.code }`
										) }
									</p>
								) }
								{ form.warnings.includes( 'return_url_origin' ) && (
									<p>{ __( 'The administration address differs from the site address, so the central application cannot send you back here. Come back to this wizard by hand when you finish.', 'ux-studio' ) }</p>
								) }
								<div className="uxs-wiz-actions">
									<button type="button" className="button" onClick={ () => setForm( null ) }>
										{ __( 'Cancel', 'ux-studio' ) }
									</button>
									<button type="button" className="button button-primary" onClick={ () => setGo( true ) }>
										{ __( 'Continue anyway', 'ux-studio' ) }
									</button>
								</div>
							</div>
						</div>
					) }

					{ form && (
						<form ref={ formRef } method="post" action={ form.action } className="uxs-wiz-hidden-form">
							{ Object.entries( form.fields ).map( ( [ name, value ] ) => (
								<input key={ name } type="hidden" name={ name } value={ value } />
							) ) }
						</form>
					) }
				</>
			) }

			<div className="uxs-wiz-ca__bar" role="group" aria-label={ __( 'Connect or skip', 'ux-studio' ) }>
				<span className="uxs-wiz-ca__barhint">{ showSuccess ? '' : __( 'You can also connect later.', 'ux-studio' ) }</span>
				<div className="uxs-wiz-ca__barbtns">
					{ ! showSuccess && (
						<button type="button" className="button" disabled={ go } onClick={ ctx.next }>
							{ __( 'Skip', 'ux-studio' ) }
						</button>
					) }
					{ ! showSuccess ? (
						<button type="button" className="button button-primary" disabled={ busy || go || attention } onClick={ () => void prepare() }>
							{ busy || go ? __( 'Connecting…', 'ux-studio' ) : __( 'Connect to the central application', 'ux-studio' ) }
						</button>
					) : (
						<button type="button" className="button button-primary" onClick={ ctx.next }>
							{ __( 'Continue', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
						</button>
					) }
				</div>
			</div>
		</section>
	);
}
