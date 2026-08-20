/**
 * File Manager has its own dedicated wp-admin screen (not part of the React
 * SPA - the embedded Tiny File Manager needs a real page, not an iframe
 * inside the SPA shell). This tile just points there. Route: #/module?id=file-manager
 */
import { __ } from '@wordpress/i18n';
import { ArrowLeft, ArrowUpRight, FolderOpen } from 'lucide-react';
import { navigate } from '../../app/route';

export default function Page(): JSX.Element {
	const url = 'admin.php?page=ux-studio-file-manager';
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
					{ __( 'File Manager', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-form" style={ { maxWidth: 480 } }>
				<div className="uxs-form__row">
					<p className="uxs-form__help" style={ { margin: 0 } }>
						<FolderOpen size={ 16 } style={ { verticalAlign: 'middle', marginRight: 6 } } />
						{ __(
							'File Manager grants browser access to the entire server filesystem, restricted to an explicit whitelist of administrators. It manages its own access and settings through a dedicated admin screen.',
							'ux-studio'
						) }
					</p>
				</div>
				<a className="button button-primary" href={ url } style={ { width: 'fit-content' } }>
					{ __( 'Open File Manager', 'ux-studio' ) } <ArrowUpRight size={ 14 } />
				</a>
			</div>
		</>
	);
}
