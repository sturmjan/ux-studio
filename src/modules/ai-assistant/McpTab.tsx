/**
 * MCP tab of the AI Assistant. The MCP server itself (switch, tokens, client setup) moved to the
 * UX Studio core and works without this module: Settings -> MCP server. This tab only points
 * there; the tools of this module (posts, pages, media, shop, SEO) show up on that screen
 * whenever the module is on.
 *
 * Named export (not default) - ai-assistant/Page.tsx wires it into its tab registry.
 */
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { Plug } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';

interface McpState {
	enabled: boolean;
}

export function McpTab(): JSX.Element {
	const { data } = useQuery( {
		queryKey: [ 'mcp-admin' ],
		queryFn: () => api< McpState >( 'mcp-admin' ),
	} );

	return (
		<>
			<h2>{ __( 'MCP Server', 'ux-studio' ) }</h2>
			<p>
				{ __(
					'The MCP server is now part of UX Studio itself and no longer depends on this module. Switch it on, create access tokens with their own permissions and copy the client configuration in Settings. The content tools of this module (posts, pages, media, shop, SEO) are offered to MCP clients while the module is enabled.',
					'ux-studio'
				) }
			</p>
			<p>
				{ __( 'Status:', 'ux-studio' ) }{ ' ' }
				{ data ? (
					<span className={ `uxs-badge${ data.enabled ? ' is-success' : '' }` }>
						{ data.enabled ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }
					</span>
				) : (
					'…'
				) }
			</p>
			<p>
				<button type="button" className="button button-primary" onClick={ () => navigate( 'settings', { section: 'mcp' } ) }>
					<Plug size={ 14 } aria-hidden /> { __( 'Open MCP server settings', 'ux-studio' ) }
				</button>
			</p>
		</>
	);
}
