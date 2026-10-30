/**
 * AI Panel has its own dedicated wp-admin screen (not part of the React SPA -
 * its UI is a self-contained legacy admin page). This
 * tile just points there. Route: #/module?id=ai-panel
 */
import { __ } from '@wordpress/i18n';
import { ArrowLeft, ArrowUpRight, Terminal } from 'lucide-react';
import { navigate } from '../../app/route';

export default function Page(): JSX.Element {
	const url = 'admin.php?page=ux-studio-ai-panel';
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
					{ __( 'AI Panel', 'ux-studio' ) }
				</h1>
			</header>
			<section className="uxs-empty" aria-label={ __( 'AI Panel', 'ux-studio' ) }>
				<span className="uxs-empty__icon">
					<Terminal size={ 28 } />
				</span>
				<p className="uxs-empty__desc">
					{ __(
						'AI Panel has its own admin screen. There you allow temporary access for an AI agent, read the audit log and remove the access at any moment.',
						'ux-studio'
					) }
				</p>
				<a className="button button-primary" href={ url }>
					{ __( 'Open AI Panel', 'ux-studio' ) } <ArrowUpRight size={ 14 } />
				</a>
			</section>
		</>
	);
}
