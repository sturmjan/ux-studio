/**
 * MCP (Model Context Protocol) overview + JWT token management. Lets admins
 * see which tools/resources are exposed to external MCP clients (Claude
 * Desktop, Cursor, Windsurf, ...) and issue/revoke the JWT bearer tokens
 * those clients authenticate with.
 *
 * The actual on/off switch for MCP lives in the "Settings" tab (mcp_enabled)
 * - this tab only shows its current state; JWT tokens can still be prepared
 * here while MCP is disabled.
 *
 * Named export (not default) - the orchestrator wires this into
 * ai-assistant/Page.tsx's tab registry.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Copy, KeyRound, LoaderCircle, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { useModuleSettings } from '../../app/SettingsForm';

interface JwtToken {
	jti: string;
	user: string;
	issued_at: string;
	expires_at: string;
}

interface GeneratedToken {
	token: string;
	jti: string;
	expires_at: string;
}

const QK_TOKENS = [ 'ai-assistant', 'mcp', 'tokens' ];

const EXPIRES_OPTIONS: { value: number; label: string }[] = [
	{ value: 3600, label: __( '1 hour', 'ux-studio' ) },
	{ value: 14400, label: __( '4 hours', 'ux-studio' ) },
	{ value: 28800, label: __( '8 hours', 'ux-studio' ) },
	{ value: 43200, label: __( '12 hours', 'ux-studio' ) },
	{ value: 86400, label: __( '24 hours', 'ux-studio' ) },
];

interface ToolGroup {
	label: string;
	operations: string;
}

const TOOL_GROUPS: ToolGroup[] = [
	{ label: __( 'Posts', 'ux-studio' ), operations: __( 'List, Get, Create, Update, Delete', 'ux-studio' ) },
	{ label: __( 'Pages', 'ux-studio' ), operations: __( 'List, Get, Create, Update, Delete', 'ux-studio' ) },
	{ label: __( 'Categories', 'ux-studio' ), operations: __( 'List, Get, Create, Update, Delete', 'ux-studio' ) },
	{ label: __( 'Tags', 'ux-studio' ), operations: __( 'List, Get, Create, Update, Delete', 'ux-studio' ) },
	{ label: __( 'Users', 'ux-studio' ), operations: __( 'List, Get, Create, Update, Delete', 'ux-studio' ) },
	{ label: __( 'Media', 'ux-studio' ), operations: __( 'List, Get, Delete', 'ux-studio' ) },
	{ label: __( 'Custom Post Types', 'ux-studio' ), operations: __( 'List Types, List Taxonomies, Get Items', 'ux-studio' ) },
	{ label: __( 'Settings', 'ux-studio' ), operations: __( 'Get, Update', 'ux-studio' ) },
	{ label: __( 'Site Stats', 'ux-studio' ), operations: __( 'Get', 'ux-studio' ) },
	{ label: __( 'WooCommerce Products', 'ux-studio' ), operations: __( 'List, Get, Create, Update, Delete (if WooCommerce is active)', 'ux-studio' ) },
	{ label: __( 'WooCommerce Orders', 'ux-studio' ), operations: __( 'List, Get, Create (if WooCommerce is active)', 'ux-studio' ) },
	{ label: __( 'Elementor', 'ux-studio' ), operations: __( 'List Pages, Get Structure, Find Widgets, Update Widget (if Elementor is active)', 'ux-studio' ) },
];

interface ResourceItem {
	label: string;
	uri: string;
}

const RESOURCES: ResourceItem[] = [
	{ label: __( 'Site Information', 'ux-studio' ), uri: 'wordpress://site-info' },
	{ label: __( 'Site Settings', 'ux-studio' ), uri: 'wordpress://site-settings' },
	{ label: __( 'Plugins Info', 'ux-studio' ), uri: 'wordpress://plugins-info' },
	{ label: __( 'Theme Info', 'ux-studio' ), uri: 'wordpress://theme-info' },
	{ label: __( 'Users Info', 'ux-studio' ), uri: 'wordpress://users-info' },
];

function TokenGenerator(): JSX.Element {
	const [ expiresIn, setExpiresIn ] = useState( 86400 );
	const [ generated, setGenerated ] = useState< GeneratedToken | null >( null );
	const [ copied, setCopied ] = useState( false );

	const generate = useMutation( {
		mutationFn: () =>
			api< GeneratedToken >( 'ai-assistant/jwt/token', {
				method: 'POST',
				body: JSON.stringify( { expires_in: expiresIn } ),
			} ),
		onSuccess: ( data ) => {
			setGenerated( data );
			setCopied( false );
			void queryClient.invalidateQueries( { queryKey: QK_TOKENS } );
		},
	} );

	const copyToken = () => {
		if ( ! generated ) {
			return;
		}
		void navigator.clipboard.writeText( generated.token );
		setCopied( true );
		window.setTimeout( () => setCopied( false ), 2000 );
	};

	return (
		<div className="uxs-form">
			<div className="uxs-form__row">
				<label htmlFor="uxs-mcp-expires">{ __( 'Validity', 'ux-studio' ) }</label>
				<div>
					<select
						id="uxs-mcp-expires"
						value={ expiresIn }
						onChange={ ( e ) => setExpiresIn( Number( e.target.value ) ) }
					>
						{ EXPIRES_OPTIONS.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>{ ' ' }
					<button
						type="button"
						className="button button-primary"
						disabled={ generate.isPending }
						onClick={ () => generate.mutate() }
					>
						<KeyRound size={ 14 } /> { __( 'Generate token', 'ux-studio' ) }
					</button>
				</div>
			</div>

			{ generated && (
				<div className="uxs-form__row">
					<label>{ __( 'New JWT token', 'ux-studio' ) }</label>
					<div>
						<div style={ { display: 'flex', gap: 'var(--uxs-sp-2, 8px)' } }>
							<input type="text" readOnly value={ generated.token } style={ { flex: 1 } } />
							<button type="button" className="button" onClick={ copyToken }>
								<Copy size={ 14 } /> { copied ? __( 'Copied!', 'ux-studio' ) : __( 'Copy', 'ux-studio' ) }
							</button>
						</div>
						<p className="uxs-form__help" style={ { color: 'var(--uxs-danger, #d63638)' } }>
							{ __(
								'This token is shown only once. Copy it now - once you leave this page it cannot be displayed again.',
								'ux-studio'
							) }
						</p>
					</div>
				</div>
			) }
		</div>
	);
}

function TokensTable(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: QK_TOKENS,
		queryFn: () => api< JwtToken[] >( 'ai-assistant/jwt/tokens' ),
	} );

	const revoke = useMutation( {
		mutationFn: ( jti: string ) => api( 'ai-assistant/jwt/revoke', { method: 'POST', body: JSON.stringify( { jti } ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_TOKENS } ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 20 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const tokens = data ?? [];

	if ( 0 === tokens.length ) {
		return <p>{ __( 'No active tokens yet.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Token ID', 'ux-studio' ) }</th>
					<th>{ __( 'User', 'ux-studio' ) }</th>
					<th>{ __( 'Created', 'ux-studio' ) }</th>
					<th>{ __( 'Expires', 'ux-studio' ) }</th>
					<th>{ __( 'Actions', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ tokens.map( ( t ) => (
					<tr key={ t.jti }>
						<td><code>{ t.jti }</code></td>
						<td>{ t.user }</td>
						<td>{ t.issued_at }</td>
						<td>{ t.expires_at }</td>
						<td>
							<button
								type="button"
								className="button button-link-delete"
								disabled={ revoke.isPending }
								onClick={ () => revoke.mutate( t.jti ) }
							>
								<Trash2 size={ 14 } /> { __( 'Revoke', 'ux-studio' ) }
							</button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export function McpTab(): JSX.Element {
	const { draft, isLoading } = useModuleSettings( 'ai-assistant' );
	const mcpEnabled = Boolean( draft.mcp_enabled );

	return (
		<>
			<h2>{ __( 'MCP Server', 'ux-studio' ) }</h2>
			<p>
				{ __(
					'MCP (Model Context Protocol) is an open standard that lets external AI tools talk to this WordPress site. Once enabled, Claude Desktop, Cursor, Windsurf or another MCP client can manage posts, pages, products, media and settings directly. Clients authenticate with a JWT token (see below) and only ever get the operations you allow.',
					'ux-studio'
				) }
			</p>
			<p>
				{ __( 'Status:', 'ux-studio' ) }{ ' ' }
				{ isLoading ? (
					'…'
				) : (
					<span className={ `uxs-badge${ mcpEnabled ? ' is-success' : '' }` }>
						{ mcpEnabled ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }
					</span>
				) }
			</p>
			<p className="uxs-form__help" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				{ __( 'This toggle lives in the Settings tab ("Enable MCP (tool calling)") - it is shown here for reference only.', 'ux-studio' ) }
			</p>

			<h2>{ __( 'Registered tools & resources', 'ux-studio' ) }</h2>
			<p>
				{ __(
					'These WordPress REST API endpoints are automatically exposed as MCP tools and resources. Tools let the AI perform actions (CRUD operations). Resources provide read-only context about the site.',
					'ux-studio'
				) }
			</p>
			<h3>{ __( 'Tools', 'ux-studio' ) }</h3>
			<table className="uxs-table" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<thead>
					<tr>
						<th>{ __( 'Tool', 'ux-studio' ) }</th>
						<th>{ __( 'Operations', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ TOOL_GROUPS.map( ( g ) => (
						<tr key={ g.label }>
							<td>{ g.label }</td>
							<td>{ g.operations }</td>
						</tr>
					) ) }
				</tbody>
			</table>

			<h3>{ __( 'Resources', 'ux-studio' ) }</h3>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Resource', 'ux-studio' ) }</th>
						<th>{ __( 'URI', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ RESOURCES.map( ( r ) => (
						<tr key={ r.uri }>
							<td>{ r.label }</td>
							<td><code>{ r.uri }</code></td>
						</tr>
					) ) }
				</tbody>
			</table>
			<p className="uxs-form__help" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				{ __( 'Resources are read-only - the AI can read them but cannot use them to change data.', 'ux-studio' ) }
			</p>

			<h2>{ __( 'JWT access tokens', 'ux-studio' ) }</h2>
			<p>
				{ __(
					'To connect an external AI client to the MCP server, generate a JWT (JSON Web Token). Paste it into the AI client\'s configuration (e.g. the Claude Desktop config). Each token has a limited lifetime and can be revoked at any time.',
					'ux-studio'
				) }
			</p>
			<TokenGenerator />
			<div style={ { marginTop: 'var(--uxs-sp-5)' } }>
				<TokensTable />
			</div>
		</>
	);
}
