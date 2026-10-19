import { __ } from '@wordpress/i18n';
import { CircleAlert, CircleCheck, LoaderCircle, X } from 'lucide-react';
import { dismissToast, useToasts } from './notify';

/** Fixed status area (bottom right): saving spinner, green success, red error. */
export function Toasts(): JSX.Element | null {
	const toasts = useToasts();
	if ( 0 === toasts.length ) {
		return null;
	}
	return (
		<div className="uxs-toasts" role="status" aria-live="polite">
			{ toasts.map( ( t ) => (
				<div key={ t.id } className={ `uxs-toast is-${ t.kind }` }>
					{ 'loading' === t.kind && <LoaderCircle size={ 16 } className="uxs-toast__spin" aria-hidden /> }
					{ 'success' === t.kind && <CircleCheck size={ 16 } aria-hidden /> }
					{ 'error' === t.kind && <CircleAlert size={ 16 } aria-hidden /> }
					<span className="uxs-toast__msg">{ t.message }</span>
					{ 'loading' !== t.kind && (
						<button type="button" className="uxs-toast__close" aria-label={ __( 'Close', 'ux-studio' ) } onClick={ () => dismissToast( t.id ) }>
							<X size={ 14 } aria-hidden />
						</button>
					) }
				</div>
			) ) }
		</div>
	);
}
