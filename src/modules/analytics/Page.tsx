import { __ } from '@wordpress/i18n';
import { ArrowLeft } from 'lucide-react';
import { navigate } from '../../app/route';
import AnalyticsOverview from './AnalyticsOverview';

export default function Page(): JSX.Element {
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
					{ __( 'Statistiky návštěv', 'ux-studio' ) }
				</h1>
			</header>
			<AnalyticsOverview />
		</>
	);
}
