/**
 * Shared multi-select field: a trigger button + popover ListBox (checked
 * items) with removable "chip" tags for the current selection.
 *
 * Built on react-aria-components (PLAN.md 20.4b) instead of a hand-rolled
 * dropdown - full keyboard navigation and screen-reader semantics for free.
 * Used by the Form Builder's `multiselect` field type (both in the builder's
 * live preview and anywhere else in the admin SPA that needs the same
 * control), but lives here (not in modules/forms) because it is meant to be
 * reused by any future module with the same need.
 */
import { useState } from 'react';
import {
	Button,
	DialogTrigger,
	Label,
	ListBox,
	ListBoxItem,
	Popover,
	Tag,
	TagGroup,
	TagList,
} from 'react-aria-components';
import { ChevronDown, X } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

export interface MultiSelectOption {
	label: string;
	value: string;
}

interface MultiSelectFieldProps {
	label?: string;
	options: MultiSelectOption[];
	value: string[];
	onChange: ( value: string[] ) => void;
	placeholder?: string;
	id?: string;
	/** Show a filter box in the popover (default: when there are more than 10 options). */
	searchable?: boolean;
}

export default function MultiSelectField( {
	label,
	options,
	value,
	onChange,
	placeholder,
	id,
	searchable,
}: MultiSelectFieldProps ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const showSearch = searchable ?? options.length > 10;
	const needle = query.trim().toLocaleLowerCase();
	const shown = needle ? options.filter( ( o ) => o.label.toLocaleLowerCase().includes( needle ) ) : options;
	const selectedOptions = options.filter( ( o ) => value.includes( o.value ) );

	return (
		<div className="uxs-multiselect">
			{ label ? <Label id={ id ? `${ id }-label` : undefined }>{ label }</Label> : null }

			<DialogTrigger>
				<Button
					className="uxs-multiselect__trigger"
					onPress={ () => setOpen( ( v ) => !v ) }
					aria-label={ label || placeholder || __( 'Choose options', 'ux-studio' ) }
				>
					<span>
						{ selectedOptions.length > 0
							? sprintf(
									/* translators: %d: number of selected options. */
									__( '%d selected', 'ux-studio' ),
									selectedOptions.length
							  )
							: placeholder || __( 'Choose…', 'ux-studio' ) }
					</span>
					<ChevronDown size={ 14 } aria-hidden="true" />
				</Button>
				<Popover
					// Render inside the plugin root so the --uxs-* theme variables apply to the popover.
					UNSTABLE_portalContainer={ document.getElementById( 'ux-studio-root' ) ?? undefined }
					isOpen={ open }
					onOpenChange={ ( o ) => {
						setOpen( o );
						if ( ! o ) {
							setQuery( '' );
						}
					} }
					className="uxs-multiselect__popover"
				>
					{ showSearch ? (
						<input
							type="search"
							className="uxs-multiselect__search"
							placeholder={ __( 'Search...', 'ux-studio' ) }
							aria-label={ __( 'Search...', 'ux-studio' ) }
							value={ query }
							onChange={ ( e ) => setQuery( e.target.value ) }
							// eslint-disable-next-line jsx-a11y/no-autofocus
							autoFocus
						/>
					) : null }
					<ListBox
						aria-label={ label || __( 'Options', 'ux-studio' ) }
						selectionMode="multiple"
						selectedKeys={ new Set( value ) }
						onSelectionChange={ ( keys ) => {
							if ( keys === 'all' ) {
								onChange( options.map( ( o ) => o.value ) );
								return;
							}
							// Keep selections that are currently filtered out of the list.
							const hidden = value.filter( ( v ) => ! shown.some( ( o ) => o.value === v ) );
							onChange( [ ...hidden, ...Array.from( keys ).map( String ).filter( ( k ) => ! hidden.includes( k ) ) ] );
						} }
						className="uxs-multiselect__listbox"
					>
						{ shown.map( ( option ) => (
							<ListBoxItem key={ option.value } id={ option.value } textValue={ option.label } className="uxs-multiselect__item">
								{ option.label }
							</ListBoxItem>
						) ) }
					</ListBox>
				</Popover>
			</DialogTrigger>

			{ selectedOptions.length > 0 ? (
				<TagGroup
					aria-label={ __( 'Selected options', 'ux-studio' ) }
					onRemove={ ( keys ) => onChange( value.filter( ( v ) => ! keys.has( v ) ) ) }
					className="uxs-multiselect__chips"
				>
					<TagList>
						{ selectedOptions.map( ( option ) => (
							<Tag key={ option.value } id={ option.value } textValue={ option.label } className="uxs-multiselect__chip">
								{ option.label }
								<Button slot="remove" aria-label={ __( 'Remove', 'ux-studio' ) }>
									<X size={ 12 } />
								</Button>
							</Tag>
						) ) }
					</TagList>
				</TagGroup>
			) : null }
		</div>
	);
}
