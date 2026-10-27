/**
 * Steps of the new-site wizard (`#/wizard?mode=new`, PLAN.md 29.4 F3 and F4): welcome with the detected
 * environment and the PHP version, the recommended modules, applying them, the custom login address, the
 * Central App connection and the finish with "Undo everything".
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	ArrowRight,
	ChevronDown,
	CircleAlert,
	CircleCheck,
	ExternalLink,
	Gauge,
	Image as ImageIcon,
	Info,
	KeyRound,
	LayoutDashboard,
	ListChecks,
	PlugZap,
	Rocket,
	ShieldAlert,
	ShieldCheck,
	TriangleAlert,
	Undo2,
	FileText,
} from 'lucide-react';
import { ApiError, api } from '../api';
import { formatDate } from '../date';
import { navigate } from '../route';
import { CaStep } from './ca-step';
import SettingExplain from '../../components/SettingExplain';
import { ConfirmModal, DrawnCheck, ErrorLine, ModuleLinkIcon, SourceIcon, StateIcon, StepHead, Warnings } from './parts';
import { LoginAddress } from './steps-preview';
import type { StarterCtx } from './starter-context';
import type { CaStatus, ConfirmLoginResponse, Detections, PhpVersionInfo, StarterItem } from './starter-types';

export interface StarterStep {
	id: string;
	title: string;
	render: ( ctx: StarterCtx ) => JSX.Element;
}

function message( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

/* ── Groups of the item list ── */

interface Group {
	id: string;
	title: string;
	icon: JSX.Element;
	ids: string[];
}

/** The pack by topic. Optional items are listed apart under "More options". */
function groups(): Group[] {
	return [
		{
			id: 'security',
			title: __( 'Security', 'ux-studio' ),
			icon: <ShieldCheck size={ 18 } aria-hidden />,
			ids: [ 'security-hardening', 'update-emails', 'captcha-shield', 'bot-throttle', 'vulnerability-scanner', 'activity-log', 'csp-audit', 'login-url' ],
		},
		{
			id: 'admin',
			title: __( 'Administration', 'ux-studio' ),
			icon: <LayoutDashboard size={ 18 } aria-hidden />,
			ids: [ 'hide-wordpress', 'dashboard-widgets', 'clean-profiles', 'user-last-login', 'email-log' ],
		},
		{
			id: 'content',
			title: __( 'Content', 'ux-studio' ),
			icon: <FileText size={ 18 } aria-hidden />,
			ids: [ 'classic', 'duplicate', 'external-permalinks' ],
		},
		{
			id: 'media',
			title: __( 'Media', 'ux-studio' ),
			icon: <ImageIcon size={ 18 } aria-hidden />,
			ids: [ 'media-trash', 'stock-photos' ],
		},
		{
			id: 'seo',
			title: __( 'Performance and SEO', 'ux-studio' ),
			icon: <Gauge size={ 18 } aria-hidden />,
			ids: [ 'image-optimizer', 'ai-markdown', 'indexing-notice' ],
		},
	];
}

/** Why an item cannot be used on this site. */
export function unavailableText( code: string ): string {
	switch ( code ) {
		case 'no_roles':
			return __( 'This site has no subscriber or customer role.', 'ux-studio' );
		case 'no_pretty_permalinks':
			return __( 'A custom login address needs pretty permalinks (Settings > Permalinks).', 'ux-studio' );
		case 'csp_set':
			return __( 'A Content Security Policy mode is already set, it stays as it is.', 'ux-studio' );
		case 'already_set':
			return __( 'A custom login address is already set.', 'ux-studio' );
		case 'collision':
			return __( 'The address /administrace is already used by a page, post or another rule of this site.', 'ux-studio' );
		case 'captcha_set':
			return __( 'A CAPTCHA is already set up on this site, it stays as it is.', 'ux-studio' );
		case 'captcha_plugin':
			return __( 'A CAPTCHA plugin is active, so the CAPTCHA is left to it.', 'ux-studio' );
		case 'captcha_proxy':
			return __( 'The site is behind a reverse proxy. The CAPTCHA limits per IP address, so set the client IP source first (Security > Firewall).', 'ux-studio' );
		case 'plugin_overlap':
			return __( 'Another plugin already takes care of the login address.', 'ux-studio' );
		default:
			return __( 'This is not possible on this site.', 'ux-studio' );
	}
}

/** Why an item was skipped while applying. */
function skipText( reason: string, plugins: string[] ): string {
	const names = plugins.join( ', ' );
	switch ( reason ) {
		case 'blocked':
			return sprintf(
				/* translators: %s: plugin names. */
				__( 'Skipped: %s already does this job.', 'ux-studio' ),
				names
			);
		case 'confirm':
			return sprintf(
				/* translators: %s: plugin names. */
				__( 'Skipped: the partial overlap with %s was not confirmed.', 'ux-studio' ),
				names
			);
		case 'unknown':
			return __( 'Skipped: the module is not available.', 'ux-studio' );
		case '':
			return __( 'Skipped.', 'ux-studio' );
		default:
			return sprintf(
				/* translators: %s: reason. */
				__( 'Skipped: %s', 'ux-studio' ),
				unavailableText( reason )
			);
	}
}

/** Every module of the item is held back by an active plugin that does the same job. */
function fullyBlocked( item: StarterItem ): boolean {
	return item.modules.length > 0 && item.modules.every( ( m ) => ! m.enabled && 'blocked' === m.overlap.status );
}

/** Plugin names of a partial overlap that needs the administrator's word. */
function confirmPlugins( item: StarterItem ): string[] {
	return Array.from( new Set( item.modules.filter( ( m ) => ! m.enabled && 'confirm' === m.overlap.status ).flatMap( ( m ) => m.overlap.plugins ) ) );
}

function blockedPlugins( item: StarterItem ): string[] {
	return Array.from( new Set( item.modules.filter( ( m ) => ! m.enabled && 'blocked' === m.overlap.status ).flatMap( ( m ) => m.overlap.plugins ) ) );
}

/* ── 1 Welcome ── */

function PhpCard( { php }: { php: PhpVersionInfo } ): JSX.Element {
	const ok = 'ok' === php.level;
	let title: string;
	if ( 'critical' === php.level ) {
		title = __( 'Critical: the PHP version of this website is not safe.', 'ux-studio' );
	} else if ( 'warning' === php.level ) {
		title = __( 'The PHP version of this website will soon lose security support.', 'ux-studio' );
	} else {
		title = sprintf(
			/* translators: %s: PHP version. */
			__( 'PHP %s', 'ux-studio' ),
			php.php_full
		);
	}
	return (
		<div className={ `uxs-wiz-phpcard is-${ php.level }` } role={ 'critical' === php.level ? 'alert' : 'group' } aria-label={ __( 'PHP version', 'ux-studio' ) }>
			{ ok ? <CircleCheck size={ 22 } aria-hidden /> : 'critical' === php.level ? <ShieldAlert size={ 22 } aria-hidden /> : <TriangleAlert size={ 22 } aria-hidden /> }
			<div>
				<strong>{ title }</strong>
				<p>
					{ ok
						? sprintf(
								/* translators: %s: PHP version. */
								__( 'This website runs on PHP %s. No known security problem with this version.', 'ux-studio' ),
								php.php_full
						  )
						: php.message }
				</p>
				{ '' !== php.backport_note && <p className="uxs-wiz-muted">{ php.backport_note }</p> }
				{ ! ok && '' !== php.advisory_url && (
					<p>
						<a href={ php.advisory_url } target="_blank" rel="noopener noreferrer">
							{ __( 'Security advisory on php.net', 'ux-studio' ) } <ExternalLink size={ 13 } aria-hidden />
						</a>
					</p>
				) }
			</div>
		</div>
	);
}

function serverName( d: Detections ): string {
	switch ( d.server ) {
		case 'apache':
			return 'Apache';
		case 'litespeed':
			return 'LiteSpeed';
		case 'nginx':
			return 'nginx';
		case 'iis':
			return 'IIS';
		default:
			return __( 'Unknown server', 'ux-studio' );
	}
}

interface EnvRow {
	key: string;
	label: string;
	text: string;
	state: 'ok' | 'warn' | 'neutral';
}

function environmentRows( d: Detections ): EnvRow[] {
	const rows: EnvRow[] = [
		{
			key: 'server',
			label: __( 'Server', 'ux-studio' ),
			text: '' !== d.server_software ? `${ serverName( d ) } (${ d.server_software })` : serverName( d ),
			state: 'neutral',
		},
		{
			key: 'cloudflare',
			label: __( 'Cloudflare', 'ux-studio' ),
			text: d.cloudflare ? __( 'Detected. Visitor IP addresses are read from the Cloudflare header.', 'ux-studio' ) : __( 'Not detected.', 'ux-studio' ),
			state: 'neutral',
		},
	];
	if ( d.xff && ! d.cloudflare ) {
		rows.push( {
			key: 'proxy',
			label: __( 'Reverse proxy', 'ux-studio' ),
			text: __( 'The site is behind a reverse proxy. Limiting login attempts stays off until the client IP source is set.', 'ux-studio' ),
			state: 'warn',
		} );
	}
	rows.push(
		{
			key: 'woo',
			label: 'WooCommerce',
			text: d.woocommerce ? __( 'Active. Comments are switched off on posts and pages only, product reviews keep working.', 'ux-studio' ) : __( 'Not installed.', 'ux-studio' ),
			state: 'neutral',
		},
		{
			key: 'permalinks',
			label: __( 'Permalinks', 'ux-studio' ),
			text: d.pretty_permalinks ? __( 'Pretty permalinks are on.', 'ux-studio' ) : __( 'Plain permalinks. A custom login address is not possible until you choose a pretty structure.', 'ux-studio' ),
			state: d.pretty_permalinks ? 'ok' : 'warn',
		}
	);
	if ( 'apache' === d.server || 'litespeed' === d.server ) {
		rows.push( {
			key: 'htaccess',
			label: '.htaccess',
			text: d.htaccess_writable ? __( 'Can be written, so the security rules go there.', 'ux-studio' ) : __( 'Cannot be written. The security headers are sent by PHP only.', 'ux-studio' ),
			state: d.htaccess_writable ? 'ok' : 'warn',
		} );
	}
	rows.push( {
		key: 'logo',
		label: __( 'Site logo', 'ux-studio' ),
		text: d.login_logo ? __( 'Set. It is shown on the login page.', 'ux-studio' ) : __( 'Not set yet. The login page keeps the WordPress logo.', 'ux-studio' ),
		state: d.login_logo ? 'ok' : 'neutral',
	} );
	return rows;
}

function WelcomeStep( { ctx }: { ctx: StarterCtx } ): JSX.Element {
	const d = ctx.data?.detections;
	const php = ctx.php;
	const phpBad = !! php && 'ok' !== php.level;
	const points = [
		{ icon: <ListChecks size={ 18 } aria-hidden />, text: __( 'You see every module and its preset first and choose what you want. Nothing is switched on without your tick.', 'ux-studio' ) },
		{ icon: <ShieldCheck size={ 18 } aria-hidden />, text: __( 'If another plugin already does a job, that item stays off or leaves that one setting out, so nothing is done twice.', 'ux-studio' ) },
		{ icon: <Undo2 size={ 18 } aria-hidden />, text: __( 'Before the first change the current state is saved. "Undo everything" at the end puts the modules and settings back.', 'ux-studio' ) },
	];
	return (
		<section className="uxs-wiz-panel uxs-wiz-welcome">
			<StepHead icon={ <Rocket size={ 26 } aria-hidden /> } title={ __( 'Set up your new website', 'ux-studio' ) }>
				{ __( 'UX Studio can switch on the modules that suit almost every WordPress site and preset them sensibly in a few minutes.', 'ux-studio' ) }
			</StepHead>

			{ ctx.loadError && <ErrorLine text={ ctx.loadError } /> }
			{ phpBad && php && <PhpCard php={ php } /> }

			<ul className="uxs-wiz-points">
				{ points.map( ( p ) => (
					<li key={ p.text }>
						{ p.icon }
						<span>{ p.text }</span>
					</li>
				) ) }
			</ul>

			{ d && (
				<>
					<dl className="uxs-wiz-facts">
						<div>
							<dt>{ __( 'Site name', 'ux-studio' ) }</dt>
							<dd>{ '' !== d.site_name ? d.site_name : '-' }</dd>
						</div>
						<div>
							<dt>{ __( 'Address', 'ux-studio' ) }</dt>
							<dd>{ d.site_url }</dd>
						</div>
					</dl>
					<h3>{ __( 'What the wizard found', 'ux-studio' ) }</h3>
					<ul className="uxs-wiz-env">
						{ environmentRows( d ).map( ( row ) => (
							<li key={ row.key }>
								{ 'neutral' === row.state ? <Info size={ 16 } aria-hidden /> : <StateIcon state={ row.state } /> }
								<strong>{ row.label }</strong>
								<span>{ row.text }</span>
							</li>
						) ) }
					</ul>
				</>
			) }

			<div className="uxs-wiz-actions">
				<button type="button" className="button button-primary" disabled={ ! d } onClick={ ctx.next }>
					{ __( 'Choose the modules', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 2 Modules ── */

function PackCard( { item, ctx, siteUrl }: { item: StarterItem; ctx: StarterCtx; siteUrl: string } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const checked = ctx.selected.includes( item.id );
	const blocked = fullyBlocked( item );
	const unavailable = ! item.available;
	const disabled = blocked || unavailable;
	const askPlugins = confirmPlugins( item );
	const needsConfirm = askPlugins.length > 0 && ! disabled;
	const confirmed = ctx.confirmed.includes( item.id );
	const someBlocked = ! blocked && blockedPlugins( item ).length > 0;
	const bodyId = `uxs-wiz-pack-${ item.id }`;
	const first = item.modules[ 0 ]?.id ?? '';
	const slug = item.preset.find( ( row ) => 'login_url' === row.key )?.value;
	const loginAddress = 'login-url' === item.id && 'string' === typeof slug ? `${ siteUrl.replace( /\/$/, '' ) }/${ slug }/` : '';
	const allOn = item.modules.length > 0 && item.modules.every( ( m ) => m.enabled );

	const byModule = useMemo( () => {
		const map = new Map< string, { name: string; rows: StarterItem[ 'preset' ] } >();
		item.preset.forEach( ( row ) => {
			const entry = map.get( row.module ) ?? { name: row.module_name, rows: [] };
			entry.rows.push( row );
			map.set( row.module, entry );
		} );
		return Array.from( map.values() );
	}, [ item.preset ] );

	return (
		<li className={ `uxs-wiz-choice uxs-wiz-pack${ checked ? ' is-selected' : '' }${ disabled ? ' is-disabled' : '' }` }>
			<div className="uxs-wiz-choice__row">
				<button
					type="button"
					role="checkbox"
					aria-checked={ checked }
					aria-label={ item.title }
					className="uxs-wiz-tick"
					disabled={ disabled || ( needsConfirm && ! confirmed ) }
					title={ needsConfirm && ! confirmed ? __( 'Confirm the partial overlap first.', 'ux-studio' ) : undefined }
					onClick={ () => ctx.toggle( item.id ) }
				>
					{ checked && <DrawnCheck size={ 16 } /> }
				</button>
				<SourceIcon module={ first } />
				<div className="uxs-wiz-choice__body">
					<div className="uxs-wiz-scancard__head">
						<strong>{ item.title }</strong>
					</div>
					<p className="uxs-wiz-muted uxs-wiz-pack__desc">{ item.description }</p>
					<div className="uxs-wiz-badges">
						{ allOn && ! disabled && <span className="uxs-badge is-info">{ __( 'Module already on', 'ux-studio' ) }</span> }
						{ item.warnings.length > 0 && ! disabled && <span className="uxs-badge is-warning">{ _n( 'Note', 'Notes', item.warnings.length, 'ux-studio' ) }</span> }
						{ someBlocked && <span className="uxs-badge is-warning">{ __( 'Part left out', 'ux-studio' ) }</span> }
						{ needsConfirm && <span className="uxs-badge is-warning">{ __( 'Partial overlap', 'ux-studio' ) }</span> }
						{ item.optional && <span className="uxs-badge is-info">{ __( 'Optional', 'ux-studio' ) }</span> }
					</div>
					{ '' !== loginAddress && ! disabled && (
						<p className="uxs-wiz-pack__address">
							{ __( 'New login address:', 'ux-studio' ) } <code>{ loginAddress }</code>
						</p>
					) }
					{ blocked && (
						<p className="uxs-wiz-partial">
							<TriangleAlert size={ 15 } aria-hidden />
							<span>
								{ sprintf(
									/* translators: %s: plugin names. */
									__( 'Not available: %s already does this job on this site.', 'ux-studio' ),
									blockedPlugins( item ).join( ', ' )
								) }
							</span>
						</p>
					) }
					{ unavailable && (
						<p className="uxs-wiz-partial">
							<TriangleAlert size={ 15 } aria-hidden />
							<span>{ unavailableText( item.unavailable ) }</span>
						</p>
					) }
					{ needsConfirm && (
						<label className="uxs-wiz-option">
							<input type="checkbox" checked={ confirmed } onChange={ () => ctx.toggleConfirmed( item.id ) } />
							<span>
								{ sprintf(
									/* translators: %s: plugin names. */
									__( 'Switch it on although it partly overlaps with %s', 'ux-studio' ),
									askPlugins.join( ', ' )
								) }
							</span>
						</label>
					) }
					{ ! disabled && <Warnings warnings={ item.warnings.map( ( w ) => w.message ) } /> }
					{ someBlocked && (
						<p className="uxs-wiz-muted">
							{ sprintf(
								/* translators: %s: plugin names. */
								__( 'A module of this item is not switched on because %s already does the same job.', 'ux-studio' ),
								blockedPlugins( item ).join( ', ' )
							) }
						</p>
					) }
				</div>
				{ ! disabled && (
					<button type="button" className="button-link uxs-wiz-expand" aria-expanded={ open } aria-controls={ bodyId } onClick={ () => setOpen( ! open ) }>
						{ __( 'What will be set', 'ux-studio' ) }
						<ChevronDown size={ 15 } aria-hidden className={ open ? 'is-open' : '' } />
					</button>
				) }
			</div>
			{ open && ! disabled && (
				<div id={ bodyId } className="uxs-wiz-choice__plan">
					<p className="uxs-wiz-muted">
						{ sprintf(
							/* translators: %s: module names. */
							__( 'Modules: %s', 'ux-studio' ),
							item.modules.map( ( m ) => ( m.enabled ? sprintf( /* translators: %s: module name. */ __( '%s (already on)', 'ux-studio' ), m.name ) : m.name ) ).join( ', ' )
						) }
					</p>
					{ 0 === item.preset.length ? (
						<p className="uxs-wiz-muted">{ __( 'The module is just switched on with its default settings.', 'ux-studio' ) }</p>
					) : (
						byModule.map( ( entry ) => (
							<div key={ entry.name } className="uxs-wiz-preset">
								{ byModule.length > 1 && <h4>{ entry.name }</h4> }
								<ul className="uxs-wiz-plan__list">
									{ entry.rows.map( ( row ) => (
										<li key={ `${ row.module }-${ row.key }` }>
											<span>{ row.label }</span>
											<code>{ row.value_label }</code>
											{ row.explain ? <SettingExplain explain={ row.explain } /> : null }
										</li>
									) ) }
								</ul>
							</div>
						) )
					) }
					{ ( item.left_out ?? [] ).length > 0 && (
						<div className="uxs-wiz-preset">
							<h4>{ __( 'Left out on this site', 'ux-studio' ) }</h4>
							<ul className="uxs-wiz-plan__list">
								{ ( item.left_out ?? [] ).map( ( row ) => (
									<li key={ row.key }>
										<span>{ row.label }</span>
										<code>{ __( 'Left out', 'ux-studio' ) }</code>
										<span className="uxs-wiz-muted uxs-wiz-plan__reason">{ row.reason }</span>
										{ row.explain ? <SettingExplain explain={ row.explain } /> : null }
									</li>
								) ) }
							</ul>
						</div>
					) }
				</div>
			) }
		</li>
	);
}

function ModulesStep( { ctx }: { ctx: StarterCtx } ): JSX.Element {
	const data = ctx.data;
	const [ more, setMore ] = useState( false );
	if ( ! data ) {
		return (
			<section className="uxs-wiz-panel">
				<StepHead title={ __( 'Loading…', 'ux-studio' ) } />
				<ErrorLine text={ ctx.loadError } />
			</section>
		);
	}

	const byId = new Map( data.items.map( ( item ) => [ item.id, item ] ) );
	const core = data.items.filter( ( item ) => ! item.optional );
	const extra = data.items.filter( ( item ) => item.optional );
	const placed = new Set< string >();
	const sections = groups()
		.map( ( group ) => {
			const items = group.ids.map( ( id ) => byId.get( id ) ).filter( ( item ): item is StarterItem => !! item && ! item.optional );
			items.forEach( ( item ) => placed.add( item.id ) );
			return { group, items };
		} )
		.filter( ( s ) => s.items.length > 0 );
	// A core item nobody mapped to a group (a newer pack) still shows up.
	const stray = core.filter( ( item ) => ! placed.has( item.id ) );
	const usable = data.items.filter( ( item ) => item.available && ! fullyBlocked( item ) );
	const recommended = usable.filter( ( item ) => item.checked && 0 === confirmPlugins( item ).length ).map( ( item ) => item.id );
	const moreSelected = extra.filter( ( item ) => ctx.selected.includes( item.id ) ).length;

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <ListChecks size={ 26 } aria-hidden /> } title={ __( 'Recommended modules', 'ux-studio' ) }>
				{ __( 'These modules and presets suit almost every site. Untick what you do not want. Open "What will be set" to see every value.', 'ux-studio' ) }
			</StepHead>

			<div className="uxs-wiz-bulk">
				<button type="button" className="button-link" onClick={ () => ctx.setSelected( recommended ) }>
					{ __( 'Select the recommended', 'ux-studio' ) }
				</button>
				<button type="button" className="button-link" onClick={ () => ctx.setSelected( [] ) }>
					{ __( 'Clear selection', 'ux-studio' ) }
				</button>
			</div>

			{ [ ...sections, ...( stray.length > 0 ? [ { group: { id: 'other', title: __( 'Other', 'ux-studio' ), icon: <Info size={ 18 } aria-hidden />, ids: [] }, items: stray } ] : [] ) ].map( ( { group, items } ) => (
				<div key={ group.id } className="uxs-wiz-packgroup">
					<h3 className="uxs-wiz-packgroup__title">
						{ group.icon } { group.title }
					</h3>
					<ul className="uxs-wiz-choices">
						{ items.map( ( item ) => (
							<PackCard key={ item.id } item={ item } ctx={ ctx } siteUrl={ data.detections.site_url } />
						) ) }
					</ul>
				</div>
			) ) }

			{ extra.length > 0 && (
				<div className="uxs-wiz-packgroup">
					<h3 className="uxs-wiz-packgroup__title">
						<button type="button" className="button-link uxs-wiz-expand" aria-expanded={ more } onClick={ () => setMore( ! more ) }>
							{ __( 'More options', 'ux-studio' ) } ({ moreSelected }/{ extra.length })
							<ChevronDown size={ 15 } aria-hidden className={ more ? 'is-open' : '' } />
						</button>
					</h3>
					{ more && (
						<ul className="uxs-wiz-choices">
							{ extra.map( ( item ) => (
								<PackCard key={ item.id } item={ item } ctx={ ctx } siteUrl={ data.detections.site_url } />
							) ) }
						</ul>
					) }
				</div>
			) }

			<div className="uxs-wiz-actions">
				<button type="button" className="button" onClick={ ctx.back }>
					{ __( 'Back', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" disabled={ 0 === ctx.selected.length } onClick={ ctx.next }>
					{ sprintf(
						/* translators: %d: number of selected items. */
						_n( 'Set up %d item', 'Set up %d items', ctx.selected.length, 'ux-studio' ),
						ctx.selected.length
					) }{ ' ' }
					<ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 3 Apply ── */

function ApplyStep( { ctx }: { ctx: StarterCtx } ): JSX.Element {
	const { apply, data } = ctx;
	const started = useRef( false );
	useEffect( () => {
		if ( ! started.current && 'idle' === apply.status ) {
			started.current = true;
			ctx.startApply();
		}
	}, [ apply.status, ctx ] );

	const titles = new Map( ( data?.items ?? [] ).map( ( item ) => [ item.id, item.title ] ) );
	const done = 'done' === apply.status;
	const list = Object.values( apply.results );
	const okCount = list.filter( ( r ) => 'applied' === r.status || 'partial' === r.status ).length;
	const skipped = list.filter( ( r ) => 'skipped' === r.status ).length;
	const failed = list.filter( ( r ) => 'error' === r.status ).length + Object.keys( apply.errors ).length;

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <PlugZap size={ 26 } aria-hidden /> } title={ done ? __( 'Setup finished', 'ux-studio' ) : __( 'Setting up…', 'ux-studio' ) }>
				{ __( 'Please keep this page open. Each item is saved on its own, so a problem in one does not stop the others.', 'ux-studio' ) }
			</StepHead>
			<ul className="uxs-wiz-progress">
				{ apply.order.map( ( id ) => {
					const result = apply.results[ id ];
					const error = apply.errors[ id ];
					let state: 'pending' | 'running' | 'ok' | 'error' | 'warn' = 'pending';
					let text = __( 'Waiting', 'ux-studio' );
					if ( error ) {
						state = 'error';
						text = error;
					} else if ( result ) {
						if ( 'error' === result.status ) {
							state = 'error';
							text = result.errors[ 0 ] ?? __( 'Failed', 'ux-studio' );
						} else if ( 'skipped' === result.status ) {
							state = 'warn';
							text = skipText( result.reason, result.plugins );
						} else if ( 'partial' === result.status ) {
							state = 'warn';
							text = '' !== result.reason ? `${ __( 'Done in part.', 'ux-studio' ) } ${ skipText( result.reason, result.plugins ) }` : __( 'Done in part.', 'ux-studio' );
						} else {
							state = 'ok';
							text = __( 'Done', 'ux-studio' );
						}
					} else if ( apply.current === id ) {
						state = 'running';
						text = __( 'Working…', 'ux-studio' );
					}
					const percent = 'ok' === state || 'warn' === state || 'error' === state ? 100 : 'running' === state ? 55 : 0;
					return (
						<li key={ id } className={ `uxs-wiz-prow is-${ state }` }>
							<StateIcon state={ state } />
							<div className="uxs-wiz-prow__body">
								<strong>{ titles.get( id ) ?? id }</strong>
								<span className={ 'error' === state ? 'uxs-wiz-error-text' : 'uxs-wiz-muted' }>{ text }</span>
								{ ( result?.notes ?? [] ).map( ( note ) => (
									<span key={ note } className="uxs-wiz-muted">
										{ note }
									</span>
								) ) }
							</div>
							<span className="uxs-wiz-bar" role="progressbar" aria-valuemin={ 0 } aria-valuemax={ 100 } aria-valuenow={ percent } aria-label={ titles.get( id ) ?? id }>
								<span className={ 'error' === state ? 'is-error' : '' } style={ { width: `${ percent }%` } } />
							</span>
						</li>
					);
				} ) }
			</ul>

			{ done && (
				<p className={ `uxs-wiz-banner ${ 0 === failed ? 'is-ok' : 'is-bad' }` } role="status">
					{ 0 === failed ? <CircleCheck size={ 16 } aria-hidden /> : <CircleAlert size={ 16 } aria-hidden /> }
					{ sprintf(
						/* translators: 1: items set up, 2: items skipped, 3: items that failed. */
						__( '%1$d set up, %2$d skipped, %3$d failed. You can undo everything on the last page.', 'ux-studio' ),
						okCount,
						skipped,
						failed
					) }
				</p>
			) }

			<div className="uxs-wiz-actions">
				<button type="button" className="button button-primary" disabled={ ! done } onClick={ ctx.next }>
					{ __( 'Continue', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 4 Login address ── */

function LoginStep( { ctx }: { ctx: StarterCtx } ): JSX.Element {
	const login = ctx.data?.login_url;
	const [ seen, setSeen ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ force, setForce ] = useState< string | null >( null );
	const [ forceSeen, setForceSeen ] = useState( false );
	const [ result, setResult ] = useState< ConfirmLoginResponse | null >( null );

	const confirm = async ( forced: boolean ): Promise< void > => {
		setBusy( true );
		setError( null );
		try {
			const res = await api< ConfirmLoginResponse >( 'onboarding/login-url/confirm', { method: 'POST', body: JSON.stringify( { force: forced } ) } );
			setResult( res );
			setForce( null );
			ctx.setLoginConfirmed( true );
			await ctx.reload();
		} catch ( e ) {
			if ( e instanceof ApiError && true === e.data.can_force ) {
				setForce( e.message );
			} else {
				setError( message( e ) );
			}
		} finally {
			setBusy( false );
		}
	};

	if ( ! result && ( ! login || 'none' === login.state ) ) {
		return (
			<section className="uxs-wiz-panel">
				<StepHead icon={ <KeyRound size={ 26 } aria-hidden /> } title={ __( 'Login address', 'ux-studio' ) }>
					{ __( 'No new login address is waiting for confirmation. The login page stays where it was.', 'ux-studio' ) }
				</StepHead>
				<div className="uxs-wiz-actions">
					<button type="button" className="button button-primary" onClick={ ctx.next }>
						{ __( 'Continue', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
					</button>
				</div>
			</section>
		);
	}

	if ( result ) {
		return (
			<section className="uxs-wiz-panel">
				<StepHead icon={ <KeyRound size={ 26 } aria-hidden /> } title={ __( 'The login address is confirmed', 'ux-studio' ) }>
					{ __( 'wp-login.php is closed now. Log in at the new address and save it.', 'ux-studio' ) }
				</StepHead>
				<LoginAddress url={ result.url } />
				<p className={ `uxs-wiz-banner ${ 'sent' === result.mail ? 'is-ok' : 'is-bad' }` } role="status">
					{ 'sent' === result.mail ? <CircleCheck size={ 16 } aria-hidden /> : <CircleAlert size={ 16 } aria-hidden /> }
					{ 'sent' === result.mail ? __( 'An e-mail with the address was sent to the site administrator.', 'ux-studio' ) : __( 'The e-mail with the address could not be sent. Save the address now.', 'ux-studio' ) }
				</p>
				<div className="uxs-wiz-actions">
					<button type="button" className="button button-primary" onClick={ ctx.next }>
						{ __( 'Continue', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
					</button>
				</div>
			</section>
		);
	}

	const url = login?.url ?? '';
	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <KeyRound size={ 26 } aria-hidden /> } title={ __( 'Confirm the new login address', 'ux-studio' ) }>
				{ __( 'The login page is moving to a new address. Open it first and check that you see the login form.', 'ux-studio' ) }
			</StepHead>

			<LoginAddress url={ url } />
			<p>
				<a className="button" href={ url } target="_blank" rel="noopener noreferrer">
					<ExternalLink size={ 16 } aria-hidden /> { __( 'Open in a new window', 'ux-studio' ) }
				</a>
			</p>

			<div className="uxs-wiz-callout" role="group">
				<Info size={ 20 } aria-hidden />
				<div>
					<strong>{ __( 'Until you confirm, nobody is locked out', 'ux-studio' ) }</strong>
					<p>{ __( 'The usual wp-login.php stays open until you confirm the new address. After the confirmation it is closed for everybody and an e-mail with the new address is sent to you.', 'ux-studio' ) }</p>
					{ 'expired' === login?.state && <p>{ __( 'The confirmation has been waiting for more than 24 hours. wp-login.php has been open the whole time.', 'ux-studio' ) }</p> }
				</div>
			</div>

			<label className="uxs-wiz-checkrow">
				<input type="checkbox" checked={ seen } disabled={ busy } onChange={ ( e ) => setSeen( e.target.checked ) } />
				<span>{ __( 'I saw the login form', 'ux-studio' ) }</span>
			</label>

			<ErrorLine text={ error } />

			{ force && (
				<div className="uxs-wiz-callout is-warn" role="alert">
					<TriangleAlert size={ 20 } aria-hidden />
					<div>
						<strong>{ __( 'The server could not check the address', 'ux-studio' ) }</strong>
						<p>{ force }</p>
						<label className="uxs-wiz-checkrow">
							<input type="checkbox" checked={ forceSeen } disabled={ busy } onChange={ ( e ) => setForceSeen( e.target.checked ) } />
							<span>{ __( 'I opened the address in my browser and saw the login form there. Close wp-login.php anyway.', 'ux-studio' ) }</span>
						</label>
						<button type="button" className="button" disabled={ busy || ! forceSeen } onClick={ () => void confirm( true ) }>
							{ __( 'Close wp-login.php', 'ux-studio' ) }
						</button>
					</div>
				</div>
			) }

			<div className="uxs-wiz-actions">
				<button type="button" className="button" disabled={ busy } onClick={ ctx.next }>
					{ __( 'Confirm later', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" disabled={ busy || ! seen || null !== force } onClick={ () => void confirm( false ) }>
					{ busy ? __( 'Checking…', 'ux-studio' ) : __( 'Confirm the new address', 'ux-studio' ) }
				</button>
			</div>
			<p className="uxs-wiz-muted">{ __( 'If you confirm later, wp-login.php stays open and a notice in the administration reminds you. You can also come back to this wizard.', 'ux-studio' ) }</p>
		</section>
	);
}

/* ── 6 Done ── */

function DoneStep( { ctx }: { ctx: StarterCtx } ): JSX.Element {
	const [ confirm, setConfirm ] = useState( false );
	const data = ctx.data;
	const ca = useQuery( {
		queryKey: [ 'onboarding', 'ca-status' ],
		queryFn: () => api< CaStatus >( 'onboarding/ca/status' ),
		staleTime: 0,
	} );

	if ( ctx.reverted ) {
		return (
			<section className="uxs-wiz-panel uxs-wiz-finish">
				<span className="uxs-wiz-finish__badge is-undo">
					<Undo2 size={ 30 } aria-hidden />
				</span>
				<h2>{ __( 'Everything was undone', 'ux-studio' ) }</h2>
				<p>{ __( 'The modules and settings are back the way they were before. If you connected the site to the central application, remove it there as well.', 'ux-studio' ) }</p>
				<div className="uxs-wiz-actions is-center">
					<button type="button" className="button" onClick={ () => ctx.goTo( 'welcome' ) }>
						{ __( 'Start over', 'ux-studio' ) }
					</button>
					<button type="button" className="button button-primary" onClick={ () => navigate( '' ) }>
						{ __( 'Back to modules', 'ux-studio' ) }
					</button>
				</div>
			</section>
		);
	}

	const results = Object.values( ctx.apply.results );
	const okIds = new Set( results.filter( ( r ) => 'applied' === r.status || 'partial' === r.status ).map( ( r ) => r.id ) );
	const skipped = results.filter( ( r ) => 'skipped' === r.status );
	const titles = new Map( ( data?.items ?? [] ).map( ( item ) => [ item.id, item.title ] ) );
	const modules = new Map< string, string >();
	( data?.items ?? [] )
		.filter( ( item ) => ( results.length > 0 ? okIds.has( item.id ) : true ) )
		.forEach( ( item ) => item.modules.filter( ( m ) => m.enabled ).forEach( ( m ) => modules.set( m.id, m.name ) ) );
	const login = data?.login_url;
	const loginWaiting = !! login && 'none' !== login.state && ! ctx.loginConfirmed;

	return (
		<section className="uxs-wiz-panel uxs-wiz-finish">
			<span className="uxs-wiz-finish__badge">
				<DrawnCheck size={ 34 } />
			</span>
			<h2>{ __( 'The new site is set up', 'ux-studio' ) }</h2>
			<p>
				{ results.length > 0
					? sprintf(
							/* translators: %d: number of items set up. */
							_n( '%d item was set up.', '%d items were set up.', okIds.size, 'ux-studio' ),
							okIds.size
					  )
					: data && data.applied > 0
					? sprintf(
							/* translators: %s: date and time. */
							__( 'The recommended modules were set up on %s.', 'ux-studio' ),
							formatDate( data.applied )
					  )
					: __( 'Nothing was changed.', 'ux-studio' ) }
			</p>

			<div className="uxs-wiz-next">
				<h3>{ __( 'Summary', 'ux-studio' ) }</h3>
				<ul>
					{ loginWaiting && login && (
						<li>
							{ __( 'The new login address is waiting for your confirmation. wp-login.php is still open.', 'ux-studio' ) }{ ' ' }
							<button type="button" className="button-link" onClick={ () => ctx.goTo( 'login' ) }>
								{ __( 'Confirm it now', 'ux-studio' ) }
							</button>
						</li>
					) }
					{ ctx.loginConfirmed && <li>{ __( 'The custom login address is confirmed and wp-login.php is closed.', 'ux-studio' ) }</li> }
					<li>
						{ ca.data?.ready
							? __( 'The site is prepared for the central application (Content Sync as a managed site).', 'ux-studio' )
							: __( 'The site is not connected to the central application. You can do it later in the Content Sync module.', 'ux-studio' ) }
					</li>
					{ skipped.map( ( r ) => (
						<li key={ r.id }>
							<strong>{ titles.get( r.id ) ?? r.id }</strong>: { skipText( r.reason, r.plugins ) }
						</li>
					) ) }
				</ul>
			</div>

			{ modules.size > 0 && (
				<div className="uxs-wiz-next is-wide">
					<h3>{ __( 'Open a module', 'ux-studio' ) }</h3>
					<div className="uxs-wiz-modulelinks">
						{ Array.from( modules.entries() ).map( ( [ id, name ] ) => (
							<button key={ id } type="button" className="button button-small uxs-wiz-modulelink" onClick={ () => navigate( 'module', { id } ) }>
								<ModuleLinkIcon module={ id } /> { name }
							</button>
						) ) }
					</div>
				</div>
			) }

			<ErrorLine text={ ctx.revertError } />
			<div className="uxs-wiz-actions is-center">
				<button type="button" className="button" disabled={ ctx.revertBusy || ! data?.can_revert } onClick={ () => setConfirm( true ) }>
					<Undo2 size={ 16 } aria-hidden /> { __( 'Undo everything', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" onClick={ () => navigate( '' ) }>
					{ __( 'Back to modules', 'ux-studio' ) }
				</button>
			</div>

			{ confirm && (
				<ConfirmModal
					id="uxs-wiz-revert-title"
					title={ __( 'Undo everything?', 'ux-studio' ) }
					confirmLabel={ ctx.revertBusy ? __( 'Restoring…', 'ux-studio' ) : __( 'Undo everything', 'ux-studio' ) }
					busy={ ctx.revertBusy }
					error={ null }
					onCancel={ () => setConfirm( false ) }
					onConfirm={ () => {
						ctx.revert();
						setConfirm( false );
					} }
				>
					<p>{ __( 'The modules this wizard switched on are switched off again and the settings it changed go back to their earlier values. Modules that were on before stay on.', 'ux-studio' ) }</p>
					<p>{ __( 'A custom login address goes back as well, so wp-login.php works again. A connection already made in the central application is not removed there.', 'ux-studio' ) }</p>
				</ConfirmModal>
			) }
		</section>
	);
}

/**
 * Ordered steps. The login address step exists only when the pack sets one (decided by the shell).
 */
export function getStarterSteps( withLogin: boolean ): StarterStep[] {
	const steps: StarterStep[] = [
		{ id: 'welcome', title: __( 'Welcome', 'ux-studio' ), render: ( ctx ) => <WelcomeStep ctx={ ctx } /> },
		{ id: 'modules', title: __( 'Recommended modules', 'ux-studio' ), render: ( ctx ) => <ModulesStep ctx={ ctx } /> },
		{ id: 'apply', title: __( 'Apply', 'ux-studio' ), render: ( ctx ) => <ApplyStep ctx={ ctx } /> },
	];
	if ( withLogin ) {
		steps.push( { id: 'login', title: __( 'Login address', 'ux-studio' ), render: ( ctx ) => <LoginStep ctx={ ctx } /> } );
	}
	steps.push(
		{ id: 'ca', title: __( 'Central application', 'ux-studio' ), render: ( ctx ) => <CaStep ctx={ ctx } /> },
		{ id: 'done', title: __( 'Done', 'ux-studio' ), render: ( ctx ) => <DoneStep ctx={ ctx } /> }
	);
	return steps;
}

