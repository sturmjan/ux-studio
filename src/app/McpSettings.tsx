/**
 * MCP server screen (Settings -> MCP server): on/off switch, endpoint, access tokens with
 * scopes, ready-to-copy client configuration, list of the tools the server offers.
 * Backend: includes/Core/Mcp (PLAN.md 26.6).
 */
import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { Check, Copy, KeyRound, LoaderCircle, Plus, ShieldAlert, Trash2 } from 'lucide-react';
import { AnimatedCheckbox } from '../components/AnimatedCheckbox';
import { api, queryClient } from './api';
import { formatDate } from './date';

type Scope = 'read' | 'write' | 'migration' | 'admin';

interface McpToken {
	id: string;
	label: string;
	user: string;
	scopes: Scope[];
	created_at: string;
	expires_at: string;
	expired: boolean;
	last_used: string | null;
	ips: string[];
	legacy: boolean;
}

interface McpAbility {
	name: string;
	tool: string;
	label: string;
	category: string;
	scope: Scope;
	readonly: boolean;
	confirm: boolean;
}

interface McpState {
	enabled: boolean;
	endpoint: string;
	site_name: string;
	tokens: McpToken[];
	abilities: McpAbility[];
	max_days: number;
	rate_limit: number;
	abilities_api: boolean;
	ai_assistant: boolean;
	adapter: boolean;
}

interface CreatedToken {
	token: string;
	record: McpToken;
}

const QK = [ 'mcp-admin' ];

const SCOPES: { id: Scope; label: string; help: string }[] = [
	{
		id: 'read',
		label: __( 'Read access', 'ux-studio' ),
		help: __( 'View modules, settings (never secrets), plugin overlaps, logs and the state of migrations.', 'ux-studio' ),
	},
	{
		id: 'write',
		label: __( 'Write access', 'ux-studio' ),
		help: __( 'Enable and disable modules, change module settings, manage redirects. Includes Read.', 'ux-studio' ),
	},
	{
		id: 'migration',
		label: __( 'Migration access', 'ux-studio' ),
		help: __( 'Run, verify, preview and switch on migrations from other plugins, and undo them. Includes Read.', 'ux-studio' ),
	},
	{
		id: 'admin',
		label: __( 'Admin access', 'ux-studio' ),
		help: __( 'Everything above, and the only scope that may set credentials. Also accepted by third party MCP adapters.', 'ux-studio' ),
	},
];

const EXPIRY_DAYS = [ 7, 30, 60, 90 ];

function scopeLabel( scope: Scope ): string {
	return SCOPES.find( ( s ) => s.id === scope )?.label ?? scope;
}

function scopeClass( scope: Scope ): string {
	if ( 'admin' === scope ) {
		return 'is-danger';
	}
	if ( 'read' === scope ) {
		return 'is-info';
	}
	return 'is-warning';
}

function useCopy(): { copied: string; copy: ( key: string, text: string ) => void } {
	const [ copied, setCopied ] = useState( '' );
	useEffect( () => {
		if ( ! copied ) {
			return undefined;
		}
		const timer = window.setTimeout( () => setCopied( '' ), 2000 );
		return () => window.clearTimeout( timer );
	}, [ copied ] );
	return {
		copied,
		copy: ( key, text ) => {
			void navigator.clipboard.writeText( text );
			setCopied( key );
		},
	};
}

function CopyButton( { id, text, copied, copy }: { id: string; text: string; copied: string; copy: ( key: string, text: string ) => void } ): JSX.Element {
	return (
		<button type="button" className="button button-small" onClick={ () => copy( id, text ) }>
			{ copied === id ? <Check size={ 14 } aria-hidden /> : <Copy size={ 14 } aria-hidden /> }
			{ copied === id ? __( 'Copied', 'ux-studio' ) : __( 'Copy', 'ux-studio' ) }
		</button>
	);
}

/** Connection snippets for the three supported clients. */
function clientConfigs( state: McpState, token: string ): { id: string; label: string; hint: string; text: string }[] {
	const name = `uxstudio-${ state.site_name }`;
	return [
		{
			id: 'claude-code',
			label: 'Claude Code',
			hint: __( 'Run this in a terminal.', 'ux-studio' ),
			text: `claude mcp add --transport http ${ name } ${ state.endpoint } --header "Authorization: Bearer ${ token }"`,
		},
		{
			id: 'claude-desktop',
			label: 'Claude Desktop',
			hint: __( 'Add to claude_desktop_config.json (needs Node.js; mcp-remote connects the app to the server).', 'ux-studio' ),
			text: JSON.stringify(
				{
					mcpServers: {
						[ name ]: {
							command: 'npx',
							args: [ '-y', 'mcp-remote', state.endpoint, '--header', 'Authorization:${AUTH_HEADER}' ],
							env: { AUTH_HEADER: `Bearer ${ token }` },
						},
					},
				},
				null,
				2
			),
		},
		{
			id: 'cursor',
			label: 'Cursor',
			hint: __( 'Add to ~/.cursor/mcp.json (or .cursor/mcp.json in a project).', 'ux-studio' ),
			text: JSON.stringify(
				{
					mcpServers: {
						[ name ]: {
							url: state.endpoint,
							headers: { Authorization: `Bearer ${ token }` },
						},
					},
				},
				null,
				2
			),
		},
	];
}

function ClientConfigs( { state, token }: { state: McpState; token: string } ): JSX.Element {
	const configs = useMemo( () => clientConfigs( state, token ), [ state, token ] );
	const [ active, setActive ] = useState( 'claude-code' );
	const { copied, copy } = useCopy();
	const current = configs.find( ( c ) => c.id === active ) ?? configs[ 0 ];
	if ( ! current ) {
		return <></>;
	}
	return (
		<div className="uxs-mcp-config">
			<div className="uxs-tabs" role="tablist">
				{ configs.map( ( c ) => (
					<button
						key={ c.id }
						type="button"
						role="tab"
						aria-selected={ c.id === active }
						className={ c.id === active ? 'is-active' : '' }
						onClick={ () => setActive( c.id ) }
					>
						{ c.label }
					</button>
				) ) }
			</div>
			<p className="uxs-form__help">{ current.hint }</p>
			<pre className="uxs-mcp-code">{ current.text }</pre>
			<CopyButton id={ `cfg-${ current.id }` } text={ current.text } copied={ copied } copy={ copy } />
		</div>
	);
}

function CreateDialog( { state, onClose }: { state: McpState; onClose: () => void } ): JSX.Element {
	const [ label, setLabel ] = useState( '' );
	const [ scopes, setScopes ] = useState< Scope[] >( [ 'read' ] );
	const [ days, setDays ] = useState( 30 );
	const [ ips, setIps ] = useState( '' );
	const [ created, setCreated ] = useState< CreatedToken | null >( null );
	const { copied, copy } = useCopy();

	const create = useMutation( {
		mutationFn: () =>
			api< CreatedToken >( 'mcp-admin/tokens', {
				method: 'POST',
				body: JSON.stringify( {
					label,
					scopes,
					days,
					ips: ips.split( /[\s,;]+/ ).filter( Boolean ),
				} ),
			} ),
		onSuccess: ( data ) => {
			setCreated( data );
			void queryClient.invalidateQueries( { queryKey: QK } );
		},
	} );

	useEffect( () => {
		const onKey = ( e: KeyboardEvent ): void => {
			if ( 'Escape' === e.key && ! create.isPending ) {
				onClose();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ create.isPending, onClose ] );

	const toggle = ( scope: Scope, on: boolean ): void => {
		setScopes( ( prev ) => ( on ? [ ...new Set( [ ...prev, scope ] ) ] : prev.filter( ( s ) => s !== scope ) ) );
	};
	const isAdmin = scopes.includes( 'admin' );
	const valid = label.trim().length > 0 && scopes.length > 0;

	return (
		<div className="uxs-overlay">
			<div className="uxs-modal uxs-mcp-modal" role="dialog" aria-modal="true" aria-labelledby="uxs-mcp-dialog-title">
				{ created ? (
					<>
						<h2 id="uxs-mcp-dialog-title">{ __( 'Token created', 'ux-studio' ) }</h2>
						<div className="uxs-notice uxs-mcp-warn">
							<p>
								<strong>{ __( 'Copy the token now.', 'ux-studio' ) }</strong>{ ' ' }
								{ __( 'It is shown only once and cannot be displayed again. If you lose it, revoke it and create a new one.', 'ux-studio' ) }
							</p>
						</div>
						<div className="uxs-mcp-token">
							<input type="text" readOnly value={ created.token } onFocus={ ( e ) => e.target.select() } aria-label={ __( 'Token', 'ux-studio' ) } />
							<CopyButton id="token" text={ created.token } copied={ copied } copy={ copy } />
						</div>
						<h3>{ __( 'Connect a client', 'ux-studio' ) }</h3>
						<ClientConfigs state={ state } token={ created.token } />
						<div className="uxs-modal__actions">
							<button type="button" className="button button-primary" onClick={ onClose }>
								{ __( 'Done', 'ux-studio' ) }
							</button>
						</div>
					</>
				) : (
					<>
						<h2 id="uxs-mcp-dialog-title">{ __( 'Create token', 'ux-studio' ) }</h2>
						<div className="uxs-form">
							<div className="uxs-form__row">
								<label htmlFor="uxs-mcp-label">{ __( 'Name', 'ux-studio' ) }</label>
								<div>
									<input
										id="uxs-mcp-label"
										type="text"
										maxLength={ 60 }
										autoFocus
										value={ label }
										onChange={ ( e ) => setLabel( e.target.value ) }
									/>
									<p className="uxs-form__help">{ __( 'For example the client that will use it: "Claude Code on my laptop".', 'ux-studio' ) }</p>
								</div>
							</div>
							<div className="uxs-form__row">
								<label>{ __( 'Scopes', 'ux-studio' ) }</label>
								<div className="uxs-mcp-scopes">
									{ SCOPES.map( ( s ) => (
										<div key={ s.id } className="uxs-mcp-scope">
											<AnimatedCheckbox
												isSelected={ isAdmin || scopes.includes( s.id ) }
												isDisabled={ isAdmin && 'admin' !== s.id }
												onChange={ ( on ) => toggle( s.id, on ) }
											>
												{ s.label }
											</AnimatedCheckbox>
											<span className="uxs-form__help">{ s.help }</span>
										</div>
									) ) }
								</div>
							</div>
							<div className="uxs-form__row">
								<label htmlFor="uxs-mcp-days">{ __( 'Valid for', 'ux-studio' ) }</label>
								<div>
									<select id="uxs-mcp-days" value={ days } onChange={ ( e ) => setDays( Number( e.target.value ) ) }>
										{ EXPIRY_DAYS.filter( ( d ) => d <= state.max_days ).map( ( d ) => (
											<option key={ d } value={ d }>
												{ sprintf(
													/* translators: %d: number of days. */
													__( '%d days', 'ux-studio' ),
													d
												) }
											</option>
										) ) }
									</select>
									<p className="uxs-form__help">
										{ sprintf(
											/* translators: %d: maximum number of days. */
											__( 'A token always expires, after %d days at most.', 'ux-studio' ),
											state.max_days
										) }
									</p>
								</div>
							</div>
							<div className="uxs-form__row">
								<label htmlFor="uxs-mcp-ips">{ __( 'Allowed IP addresses', 'ux-studio' ) }</label>
								<div>
									<textarea
										id="uxs-mcp-ips"
										rows={ 3 }
										placeholder="203.0.113.5&#10;198.51.100.0/24"
										value={ ips }
										onChange={ ( e ) => setIps( e.target.value ) }
									/>
									<p className="uxs-form__help">
										{ __( 'Optional. One address or range (CIDR) per line. Empty = the token works from anywhere.', 'ux-studio' ) }
									</p>
								</div>
							</div>
						</div>
						{ isAdmin || scopes.includes( 'migration' ) || scopes.includes( 'write' ) ? (
							<div className="uxs-notice uxs-mcp-warn">
								<p>
									<ShieldAlert size={ 14 } aria-hidden />{ ' ' }
									{ __( 'Whoever holds this token can make changes to the site as an administrator within these scopes. Store it like a password.', 'ux-studio' ) }
								</p>
							</div>
						) : null }
						{ create.isError ? <p className="uxs-notice__error">{ ( create.error as Error ).message }</p> : null }
						<div className="uxs-modal__actions">
							<button type="button" className="button" disabled={ create.isPending } onClick={ onClose }>
								{ __( 'Cancel', 'ux-studio' ) }
							</button>
							<button type="button" className="button button-primary" disabled={ ! valid || create.isPending } onClick={ () => create.mutate() }>
								<KeyRound size={ 14 } aria-hidden /> { create.isPending ? __( 'Creating…', 'ux-studio' ) : __( 'Create token', 'ux-studio' ) }
							</button>
						</div>
					</>
				) }
			</div>
		</div>
	);
}

function TokensTable( { tokens }: { tokens: McpToken[] } ): JSX.Element {
	const revoke = useMutation( {
		mutationFn: ( id: string ) => api( 'mcp-admin/revoke', { method: 'POST', body: JSON.stringify( { id } ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK } ),
	} );

	if ( 0 === tokens.length ) {
		return <p className="uxs-form__help">{ __( 'No tokens yet.', 'ux-studio' ) }</p>;
	}
	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Name', 'ux-studio' ) }</th>
					<th>{ __( 'Scopes', 'ux-studio' ) }</th>
					<th>{ __( 'Expires', 'ux-studio' ) }</th>
					<th>{ __( 'Last used', 'ux-studio' ) }</th>
					<th>{ __( 'IP addresses', 'ux-studio' ) }</th>
					<th>{ __( 'Actions', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ tokens.map( ( t ) => (
					<tr key={ t.id }>
						<td>
							{ t.label }
							{ t.legacy ? (
								<>
									{ ' ' }
									<span className="uxs-badge">{ __( 'old', 'ux-studio' ) }</span>
								</>
							) : null }
							<br />
							<code>{ t.id.slice( 0, 12 ) }</code>
						</td>
						<td>
							<span className="uxs-mcp-badges">
								{ t.scopes.map( ( s ) => (
									<span key={ s } className={ `uxs-badge ${ scopeClass( s ) }` }>
										{ scopeLabel( s ) }
									</span>
								) ) }
							</span>
						</td>
						<td>
							{ formatDate( t.expires_at, { utc: true } ) }
							{ t.expired ? (
								<>
									{ ' ' }
									<span className="uxs-badge is-danger">{ __( 'expired', 'ux-studio' ) }</span>
								</>
							) : null }
						</td>
						<td>{ t.last_used ? formatDate( t.last_used, { utc: true } ) : __( 'never', 'ux-studio' ) }</td>
						<td>{ t.ips.length ? t.ips.join( ', ' ) : __( 'any', 'ux-studio' ) }</td>
						<td>
							<button
								type="button"
								className="button button-small button-link-delete"
								disabled={ revoke.isPending }
								onClick={ () => {
									if ( window.confirm( sprintf( /* translators: %s: token name. */ __( 'Revoke the token "%s"? Clients using it stop working immediately.', 'ux-studio' ), t.label ) ) ) {
										revoke.mutate( t.id );
									}
								} }
							>
								<Trash2 size={ 14 } aria-hidden /> { __( 'Revoke', 'ux-studio' ) }
							</button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function Abilities( { abilities }: { abilities: McpAbility[] } ): JSX.Element {
	const counts = SCOPES.map( ( s ) => ( { ...s, count: abilities.filter( ( a ) => a.scope === s.id ).length } ) ).filter( ( s ) => s.count > 0 );
	return (
		<details className="uxs-mcp-abilities">
			<summary>
				{ sprintf(
					/* translators: %d: number of tools. */
					__( '%d tools offered', 'ux-studio' ),
					abilities.length
				) }
				{ ' · ' }
				{ counts.map( ( s ) => `${ s.label }: ${ s.count }` ).join( ', ' ) }
			</summary>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Tool', 'ux-studio' ) }</th>
						<th>{ __( 'Scope', 'ux-studio' ) }</th>
						<th>{ __( 'Notes', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ abilities.map( ( a ) => (
						<tr key={ a.name }>
							<td>
								<code>{ a.tool }</code>
							</td>
							<td>
								<span className={ `uxs-badge ${ scopeClass( a.scope ) }` }>{ scopeLabel( a.scope ) }</span>
							</td>
							<td>
								{ a.confirm ? __( 'needs confirmation', 'ux-studio' ) : a.readonly ? __( 'read only', 'ux-studio' ) : '' }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</details>
	);
}

export function McpSettings(): JSX.Element {
	const { data, isLoading, error } = useQuery( {
		queryKey: QK,
		queryFn: () => api< McpState >( 'mcp-admin' ),
		staleTime: 0,
	} );
	const [ dialog, setDialog ] = useState( false );
	const { copied, copy } = useCopy();

	const toggle = useMutation( {
		mutationFn: ( enabled: boolean ) => api( 'mcp-admin/enabled', { method: 'POST', body: JSON.stringify( { enabled } ) } ),
		// The endpoint exists only while the server is on, and the tool list depends on it.
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK } ),
	} );

	if ( isLoading || ! data ) {
		return error ? (
			<p className="uxs-notice__error">{ ( error as Error ).message }</p>
		) : (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<div className="uxs-mcp">
			<p className="uxs-group__intro">
				{ __(
					'MCP (Model Context Protocol) lets AI tools such as Claude Code, Claude Desktop or Cursor work with this site: list and switch modules, read settings and move settings over from other plugins with the migration wizard. Every call is recorded in the Activity Log.',
					'ux-studio'
				) }
			</p>

			<div className="uxs-group__item">
				<div className="uxs-group__head">
					<div className="uxs-group__text">
						<h3 className="uxs-tile__name">{ __( 'MCP server', 'ux-studio' ) }</h3>
						<span className="uxs-tile__desc">
							{ __( 'Off by default. While it is off the endpoint does not exist and no token works.', 'ux-studio' ) }
						</span>
					</div>
					<span className="uxs-group__state">{ data.enabled ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }</span>
					<button
						type="button"
						className={ `uxs-switch${ data.enabled ? ' is-on' : '' }` }
						aria-label={ data.enabled ? __( 'Disable the MCP server', 'ux-studio' ) : __( 'Enable the MCP server', 'ux-studio' ) }
						aria-pressed={ data.enabled }
						disabled={ toggle.isPending }
						onClick={ () => toggle.mutate( ! data.enabled ) }
					/>
				</div>
			</div>

			<h2 className="uxs-section-title">{ __( 'Endpoint', 'ux-studio' ) }</h2>
			<div className="uxs-mcp-token">
				<input type="text" readOnly value={ data.endpoint } onFocus={ ( e ) => e.target.select() } aria-label={ __( 'Endpoint URL', 'ux-studio' ) } />
				<CopyButton id="endpoint" text={ data.endpoint } copied={ copied } copy={ copy } />
			</div>
			<p className="uxs-form__help">
				{ sprintf(
					/* translators: %d: calls per minute. */
					__( 'Streamable HTTP, JSON-RPC over POST. Clients send the token as "Authorization: Bearer". At most %d calls per minute per token.', 'ux-studio' ),
					data.rate_limit
				) }
			</p>
			{ data.adapter ? (
				<p className="uxs-form__help">
					{ __( 'The WordPress MCP Adapter plugin is active too. It keeps working with admin tokens; this endpoint is the one that applies scopes.', 'ux-studio' ) }
				</p>
			) : null }

			<h2 className="uxs-section-title">{ __( 'Access tokens', 'ux-studio' ) }</h2>
			<div className="uxs-notice uxs-mcp-warn">
				<p>
					<ShieldAlert size={ 14 } aria-hidden />{ ' ' }
					{ __(
						'A token lets an AI client act on this site as an administrator, within its scopes. Give it only the scopes it needs, keep it secret and revoke it when it is no longer used.',
						'ux-studio'
					) }
				</p>
			</div>
			<div className="uxs-mcp-actions">
				<button type="button" className="button button-primary" onClick={ () => setDialog( true ) }>
					<Plus size={ 14 } aria-hidden /> { __( 'Create token', 'ux-studio' ) }
				</button>
			</div>
			<TokensTable tokens={ data.tokens } />

			<h2 className="uxs-section-title">{ __( 'Connect a client', 'ux-studio' ) }</h2>
			<p className="uxs-form__help">
				{ __( 'Replace YOUR_TOKEN with a token from above. A freshly created token is filled in for you when it is created.', 'ux-studio' ) }
			</p>
			<ClientConfigs state={ data } token="YOUR_TOKEN" />

			<h2 className="uxs-section-title">{ __( 'Tools', 'ux-studio' ) }</h2>
			<p className="uxs-form__help">
				{ data.ai_assistant
					? __( 'Includes the content tools of the AI Assistant module (posts, pages, media, shop, SEO).', 'ux-studio' )
					: __( 'Content tools (posts, pages, media) appear here when the AI Assistant module is enabled.', 'ux-studio' ) }
			</p>
			<Abilities abilities={ data.abilities } />

			{ dialog ? <CreateDialog state={ data } onClose={ () => setDialog( false ) } /> : null }
		</div>
	);
}
