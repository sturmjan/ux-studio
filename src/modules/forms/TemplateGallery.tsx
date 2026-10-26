/**
 * Template gallery under the "New form" row (PLAN.md 20.12): a card per ready-made form with a live mini preview
 * drawn from the template's own field definitions (no images), the name, a short description, the number of fields
 * and, for multi-step templates, a badge with the number of steps. Category chips filter the list; a click creates
 * the form on the server (POST forms/from-template, the same sanitizing as a normal save) and opens the builder.
 */
import { Fragment, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ChevronDown, FilePlus2, Layers, LoaderCircle, Upload } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import type { FormDefinition, FormField, FormTemplate, TemplateCatalog } from './types';

/** Fields of the first step, without the ones that only appear on a condition. */
function firstStepFields( fields: FormField[] ): { title: string; items: FormField[] } {
	const items: FormField[] = [];
	let title = '';
	let seenBreak = false;
	for ( const field of fields ) {
		if ( field.type === 'step' ) {
			if ( seenBreak || items.length > 0 ) {
				break;
			}
			seenBreak = true;
			title = field.step_title || field.label;
			continue;
		}
		if ( field.conditions.length === 0 && field.type !== 'hidden' && field.type !== 'captcha' ) {
			items.push( field );
		}
	}
	return { title, items };
}

function MiniControl( { field }: { field: FormField } ): JSX.Element {
	const options = field.options ?? [];
	switch ( field.type ) {
		case 'textarea':
			return <span className="uxs-tpl-mini__box is-tall" />;
		case 'select':
		case 'multiselect':
			return (
				<span className="uxs-tpl-mini__box is-select">
					<ChevronDown size={ 8 } aria-hidden="true" />
				</span>
			);
		case 'file':
			return <span className="uxs-tpl-mini__drop"><Upload size={ 9 } aria-hidden="true" /></span>;
		case 'radio':
		case 'checkbox_group':
			if ( field.choice_style === 'inline' ) {
				return (
					<span className="uxs-tpl-mini__pills">
						{ options.slice( 0, 5 ).map( ( o, i ) => (
							<span key={ o.value } className={ `uxs-tpl-mini__pill${ i === 3 ? ' is-on' : '' }` }>{ o.label }</span>
						) ) }
					</span>
				);
			}
			if ( field.choice_style === 'cards' ) {
				return (
					<span className="uxs-tpl-mini__cards">
						{ options.slice( 0, 3 ).map( ( o, i ) => (
							<span key={ o.value } className={ `uxs-tpl-mini__card${ i === 0 ? ' is-on' : '' }` }>
								<i />
								{ o.label }
							</span>
						) ) }
					</span>
				);
			}
			return (
				<span className="uxs-tpl-mini__list">
					{ options.slice( 0, 3 ).map( ( o ) => (
						<span key={ o.value } className="uxs-tpl-mini__opt">
							<i className={ field.type === 'radio' ? 'is-round' : '' } />
							{ o.label }
						</span>
					) ) }
				</span>
			);
		case 'checkbox':
		case 'acceptance':
			return (
				<span className="uxs-tpl-mini__opt">
					<i />
					<b />
				</span>
			);
		default:
			return <span className="uxs-tpl-mini__box">{ field.placeholder }</span>;
	}
}

/** A small, non-interactive picture of the form made from its definition. */
function MiniForm( { template }: { template: FormTemplate } ): JSX.Element {
	const { title, items } = firstStepFields( template.fields );
	const { settings } = template;
	const twoCols = settings.columns === 2;
	const hideLabel = ( f: FormField ): boolean => f.type === 'checkbox' || f.type === 'acceptance' || f.label_display === 'placeholder_only';
	const isInline = settings.layout === 'inline';
	const shown = items.slice( 0, isInline ? 2 : 6 );

	return (
		<div className={ `uxs-tpl-mini is-${ settings.layout }` } aria-hidden="true">
			{ settings.layout === 'card' && (
				<div className="uxs-tpl-mini__head">
					<b>{ template.title }</b>
					<span />
				</div>
			) }
			{ template.step_count > 1 && (
				<div className="uxs-tpl-mini__steps">
					{ Array.from( { length: template.step_count }, ( _, i ) => (
						<span key={ i } className={ i === 0 ? 'is-on' : '' }>
							{ i + 1 }
						</span>
					) ) }
				</div>
			) }
			{ title && <div className="uxs-tpl-mini__title">{ title }</div> }
			<div className="uxs-tpl-mini__grid">
				{ shown.map( ( f, index ) => {
					let cols = Math.round( ( f.width / 100 ) * 12 );
					if ( twoCols && cols === 12 && [ 'text', 'email', 'tel', 'number', 'url', 'date', 'time', 'select' ].includes( f.type ) ) {
						cols = 6;
					}
					return (
						<Fragment key={ f.key }>
							<div className="uxs-tpl-mini__field" style={ { gridColumn: `span ${ cols }` } }>
								{ ! hideLabel( f ) && <span className="uxs-tpl-mini__label">{ f.label }</span> }
								<MiniControl field={ f } />
							</div>
							{ isInline && index === 0 && <span className="uxs-tpl-mini__btn is-inline">{ settings.submit_text }</span> }
						</Fragment>
					);
				} ) }
			</div>
			{ ! isInline && (
				<div className="uxs-tpl-mini__foot">
					<span className="uxs-tpl-mini__btn">{ template.step_count > 1 ? __( 'Next', 'ux-studio' ) : settings.submit_text || __( 'Submit', 'ux-studio' ) }</span>
				</div>
			) }
		</div>
	);
}

export default function TemplateGallery( {
	title,
	onCreated,
}: {
	/** Name typed into the "New form title" field ('' = use the template's own title). */
	title: string;
	onCreated: ( id: number ) => void;
} ): JSX.Element {
	const [ category, setCategory ] = useState< string >( 'all' );
	const [ busy, setBusy ] = useState< string | null >( null );

	const catalog = useQuery( {
		queryKey: [ 'forms', 'templates' ],
		queryFn: () => api< TemplateCatalog >( 'forms/templates' ),
		staleTime: 10 * 60_000,
	} );

	const create = useMutation( {
		mutationFn: ( templateId: string ) =>
			templateId === 'blank'
				? api< FormDefinition >( 'forms', { method: 'POST', body: JSON.stringify( { title: title || __( 'New form', 'ux-studio' ), status: 'draft' } ) } )
				: api< FormDefinition >( 'forms/from-template', { method: 'POST', body: JSON.stringify( { template: templateId, title } ) } ),
		onSuccess: ( form ) => {
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } );
			onCreated( form.id );
		},
		onSettled: () => setBusy( null ),
	} );

	function pick( id: string ): void {
		setBusy( id );
		create.mutate( id );
	}

	const templates = ( catalog.data?.templates ?? [] ).filter( ( t ) => category === 'all' || t.category === category );

	return (
		<section className="uxs-tpl" aria-labelledby="uxs-tpl-heading">
			<div className="uxs-tpl__top">
				<h2 id="uxs-tpl-heading">{ __( 'Start from a template', 'ux-studio' ) }</h2>
				<div className="uxs-tpl__chips" role="group" aria-label={ __( 'Template categories', 'ux-studio' ) }>
					<button type="button" aria-pressed={ category === 'all' } className={ category === 'all' ? 'is-active' : '' } onClick={ () => setCategory( 'all' ) }>
						{ __( 'All', 'ux-studio' ) }
					</button>
					{ ( catalog.data?.categories ?? [] ).map( ( c ) => (
						<button key={ c.id } type="button" aria-pressed={ category === c.id } className={ category === c.id ? 'is-active' : '' } onClick={ () => setCategory( c.id ) }>
							{ c.label }
						</button>
					) ) }
				</div>
			</div>

			{ create.isError && (
				<p className="uxs-form__help uxs-tpl__error" role="alert">
					{ create.error instanceof Error ? create.error.message : __( 'The form could not be created.', 'ux-studio' ) }
				</p>
			) }

			{ catalog.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 20 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<ul className="uxs-tpl__grid">
					<li>
						<button type="button" className="uxs-tpl__card is-blank" disabled={ busy !== null } onClick={ () => pick( 'blank' ) }>
							<span className="uxs-tpl__blank">
								{ busy === 'blank' ? <LoaderCircle size={ 22 } aria-hidden="true" /> : <FilePlus2 size={ 22 } aria-hidden="true" /> }
							</span>
							<span className="uxs-tpl__body">
								<strong>{ __( 'Blank form', 'ux-studio' ) }</strong>
								<span>{ __( 'Start from scratch and add the fields yourself.', 'ux-studio' ) }</span>
							</span>
						</button>
					</li>
					{ templates.map( ( t ) => (
						<li key={ t.id }>
							<button type="button" className="uxs-tpl__card" disabled={ busy !== null } aria-busy={ busy === t.id } onClick={ () => pick( t.id ) }>
								<span className="uxs-tpl__preview">
									<MiniForm template={ t } />
									{ busy === t.id && (
										<span className="uxs-tpl__busy">
											<LoaderCircle size={ 22 } aria-label={ __( 'Creating the form…', 'ux-studio' ) } />
										</span>
									) }
								</span>
								<span className="uxs-tpl__body">
									<strong>{ t.title }</strong>
									<span>{ t.description }</span>
									<span className="uxs-tpl__meta">
										<span className="uxs-badge">{ sprintf( /* translators: %d: number of fields of a form template. */ __( 'Fields: %d', 'ux-studio' ), t.field_count ) }</span>
										{ t.step_count > 1 && (
											<span className="uxs-badge is-info">
												<Layers size={ 12 } aria-hidden="true" />{ ' ' }
												{ sprintf( /* translators: %d: number of steps of a multi-step form template. */ __( 'Multi-step: %d', 'ux-studio' ), t.step_count ) }
											</span>
										) }
									</span>
								</span>
							</button>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}
