/**
 * Duplicate Menu: pick a navigation menu, name the copy, duplicate it.
 * Route: #/module?id=duplicate-menu
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';

interface MenuOption {
	id: number;
	name: string;
}

interface DuplicateResult {
	menu_id: number;
	menu_name: string;
	edit_url: string;
}

export default function Page(): JSX.Element {
	const queryClient = useQueryClient();
	const [ menuId, setMenuId ] = useState( '' );
	const [ menuName, setMenuName ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState< DuplicateResult | null >( null );

	const menus = useQuery( {
		queryKey: [ 'duplicate-menu', 'menus' ],
		queryFn: () => api< MenuOption[] >( 'duplicate-menu/menus' ),
	} );

	const duplicate = useMutation( {
		mutationFn: () =>
			api< DuplicateResult >( 'duplicate-menu', {
				method: 'POST',
				body: JSON.stringify( { menu_id: Number( menuId ), menu_name: menuName } ),
			} ),
		onSuccess: ( data ) => {
			setResult( data );
			setMenuName( '' );
			void queryClient.invalidateQueries( { queryKey: [ 'duplicate-menu', 'menus' ] } );
		},
		onError: ( e: Error ) => setError( e.message ),
	} );

	const submit = (): void => {
		setError( '' );
		setResult( null );
		if ( ! menuId ) {
			setError( __( 'Please select a menu', 'ux-studio' ) );
			return;
		}
		if ( menuName.trim() === '' ) {
			setError( __( 'Please enter a name for the menu', 'ux-studio' ) );
			return;
		}
		duplicate.mutate();
	};

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
					{ __( 'Duplicate Menu', 'ux-studio' ) }
				</h1>
			</header>

			{ menus.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! menus.data || menus.data.length === 0 ? (
				<div className="uxs-notice">
					<p>{ __( 'No menus have been created yet. Please create a menu first.', 'ux-studio' ) }</p>
					<a className="button button-primary" href="nav-menus.php">
						{ __( 'Create a menu', 'ux-studio' ) }
					</a>
				</div>
			) : (
				<div className="uxs-form">
					<div className="uxs-form__row">
						<label htmlFor="uxs-dm-menu">{ __( 'Select Menu', 'ux-studio' ) }</label>
						<select
							id="uxs-dm-menu"
							value={ menuId }
							disabled={ duplicate.isPending }
							onChange={ ( e ) => setMenuId( e.target.value ) }
						>
							<option value="">{ __( 'Select a menu…', 'ux-studio' ) }</option>
							{ menus.data.map( ( m ) => (
								<option key={ m.id } value={ m.id }>
									{ m.name }
								</option>
							) ) }
						</select>
						<p className="uxs-form__help">{ __( 'Choose the menu to copy.', 'ux-studio' ) }</p>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-dm-name">{ __( 'Menu Name', 'ux-studio' ) }</label>
						<input
							id="uxs-dm-name"
							type="text"
							value={ menuName }
							disabled={ duplicate.isPending }
							onChange={ ( e ) => setMenuName( e.target.value ) }
						/>
						<p className="uxs-form__help">{ __( 'Enter a name for the new menu', 'ux-studio' ) }</p>
					</div>
					{ error ? (
						<div className="uxs-badge is-danger" role="alert" style={ { display: 'block', padding: 'var(--uxs-sp-2)' } }>
							{ error }
						</div>
					) : null }
					{ result ? (
						<div className="uxs-badge is-success" role="status" style={ { display: 'block', padding: 'var(--uxs-sp-2)' } }>
							{ __( 'Menu duplicated successfully.', 'ux-studio' ) }{ ' ' }
							<a href={ result.edit_url }>{ __( 'View Menu', 'ux-studio' ) }</a>
						</div>
					) : null }
					<div>
						<button type="button" className="button button-primary" disabled={ duplicate.isPending } onClick={ submit }>
							{ duplicate.isPending ? __( 'Duplicating...', 'ux-studio' ) : __( 'Duplicate Menu', 'ux-studio' ) }
						</button>
					</div>
				</div>
			) }
		</>
	);
}
