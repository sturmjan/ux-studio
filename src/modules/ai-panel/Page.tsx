/**
 * AI Panel has its own dedicated wp-admin screen (not part of the React SPA -
 * its UI is a self-contained legacy admin page, unchanged by the port). This
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
			<div className="uxs-form" style={ { maxWidth: 480 } }>
				<div className="uxs-form__row">
					<p className="uxs-form__help" style={ { margin: 0 } }>
						<Terminal size={ 16 } style={ { verticalAlign: 'middle', marginRight: 6 } } />
						{ __(
							'AI Panel manages temporary remote access grants through its own dedicated admin screen, unchanged from before the rewrite.',
							'ux-studio'
						) }
					</p>
				</div>
				<a className="button button-primary" href={ url } style={ { width: 'fit-content' } }>
					{ __( 'Open AI Panel', 'ux-studio' ) } <ArrowUpRight size={ 14 } />
				</a>
			</div>
		</>
	);
}
