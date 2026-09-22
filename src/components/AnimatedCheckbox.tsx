/**
 * Shared animated checkbox (+ checkbox group) built on react-aria-components
 * (PLAN.md 20.4b). The checkmark animates in/out via CSS transition instead
 * of the instant native-input snap, matching the plugin's `--uxs-motion-*`
 * timing tokens. Visually consistent with the existing ToggleSwitch pattern
 * elsewhere in the admin SPA.
 */
import { Checkbox, CheckboxGroup, Label } from 'react-aria-components';
import type { ReactNode } from 'react';

interface AnimatedCheckboxProps {
	isSelected: boolean;
	onChange: ( isSelected: boolean ) => void;
	children: ReactNode;
	id?: string;
	isDisabled?: boolean;
}

export function AnimatedCheckbox( { isSelected, onChange, children, id, isDisabled }: AnimatedCheckboxProps ): JSX.Element {
	return (
		<Checkbox
			id={ id }
			isSelected={ isSelected }
			onChange={ onChange }
			isDisabled={ isDisabled }
			className="uxs-achk"
		>
			<span className="uxs-achk__box" aria-hidden="true">
				<svg viewBox="0 0 18 18" className="uxs-achk__mark">
					<polyline points="4,9.5 7.5,13 14,5.5" />
				</svg>
			</span>
			<span className="uxs-achk__label">{ children }</span>
		</Checkbox>
	);
}

export interface CheckboxGroupOption {
	label: string;
	value: string;
}

interface AnimatedCheckboxGroupProps {
	label?: string;
	options: CheckboxGroupOption[];
	value: string[];
	onChange: ( value: string[] ) => void;
}

export function AnimatedCheckboxGroup( { label, options, value, onChange }: AnimatedCheckboxGroupProps ): JSX.Element {
	return (
		<CheckboxGroup value={ value } onChange={ onChange } className="uxs-achk-group">
			{ label ? <Label>{ label }</Label> : null }
			<div className="uxs-achk-group__items">
				{ options.map( ( option ) => (
					<Checkbox key={ option.value } value={ option.value } className="uxs-achk">
						<span className="uxs-achk__box" aria-hidden="true">
							<svg viewBox="0 0 18 18" className="uxs-achk__mark">
								<polyline points="4,9.5 7.5,13 14,5.5" />
							</svg>
						</span>
						<span className="uxs-achk__label">{ option.label }</span>
					</Checkbox>
				) ) }
			</div>
		</CheckboxGroup>
	);
}

export default AnimatedCheckbox;
