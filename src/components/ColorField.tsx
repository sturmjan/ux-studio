/**
 * Color field of the settings forms. A square swatch shows the color that is in effect: the chosen one or, while
 * nothing is chosen, the default (a striped swatch when the default is not known, because it is the color of the
 * theme and not a value of ours). The native color input sits invisibly on top of the swatch, so a click or the
 * keyboard opens the picker. "Reset" is a real small button and only appears once a color is chosen.
 * Styles are inline and use the app variables, so light and dark mode follow without extra CSS.
 */
import { __ } from '@wordpress/i18n';

interface ColorFieldProps {
	/** Chosen color (#rrggbb) or '' for "use the default". */
	value: string;
	/** Default color when the schema knows it, '' otherwise. */
	defaultColor?: string;
	/** Accessible name of the picker. */
	label: string;
	onChange: ( value: string ) => void;
}

const STRIPES = 'repeating-linear-gradient(45deg, var(--uxs-surface) 0 5px, var(--uxs-border) 5px 7px)';

export function ColorField( { value, defaultColor = '', label, onChange }: ColorFieldProps ): JSX.Element {
	const effective = value || defaultColor;

	return (
		<span style={ { display: 'inline-flex', alignItems: 'center', gap: 'var(--uxs-sp-3)', flexWrap: 'wrap' } }>
			<span
				style={ {
					position: 'relative',
					display: 'inline-block',
					width: 46,
					height: 46,
					flex: '0 0 46px',
					boxSizing: 'border-box',
					border: '1px solid var(--uxs-border)',
					borderRadius: 'var(--uxs-radius-s)',
					background: effective || STRIPES,
					overflow: 'hidden',
				} }
			>
				<input
					type="color"
					aria-label={ label }
					value={ value || defaultColor || '#000000' }
					onChange={ ( e ) => onChange( e.target.value ) }
					style={ {
						position: 'absolute',
						inset: 0,
						width: '100%',
						height: '100%',
						margin: 0,
						padding: 0,
						border: 0,
						opacity: 0,
						cursor: 'pointer',
					} }
				/>
			</span>
			<span style={ { color: value ? 'var(--uxs-text)' : 'var(--uxs-text-soft)', fontVariantNumeric: 'tabular-nums' } }>
				{ value ? value.toUpperCase() : __( 'Default (theme color)', 'ux-studio' ) }
			</span>
			{ value ? (
				<button type="button" className="button button-small" onClick={ () => onChange( '' ) }>
					{ __( 'Reset', 'ux-studio' ) }
				</button>
			) : null }
		</span>
	);
}
