/**
 * "Generate with AI" (PLAN.md 20.11/F3): a plain-language description goes through the shared AI core
 * (AiAssistant\ContentGenerator, no bespoke AI client) and comes back as a batch of already-sanitized fields, which
 * the admin still reviews / edits / reorders in the canvas before Save. The AI only drafts, it never writes anything
 * by itself.
 *
 * Layout: one prompt card (auto-growing textarea, option pill, counter, round submit), suggestion chips below it.
 * It stays a modal so the builder canvas under it does not jump.
 */
import { useEffect, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowUp, Layers, LoaderCircle, Sparkles, TriangleAlert, X } from 'lucide-react';
import { ApiError, api } from '../../app/api';
import { navigate } from '../../app/route';
import type { FormField } from './types';

/** Longest description the server accepts (ContentGenerator::FORM_PROMPT_MAX). */
const MAX_LENGTH = 1000;

interface AiStatus {
	available: boolean;
	reason: '' | 'module_off' | 'no_key';
	provider: string;
}

/** Starting points: label of the chip and the description it puts into the box. */
function suggestions(): Array< [ string, string ] > {
	return [
		[ __( 'Contact form', 'ux-studio' ), __( 'A contact form with name, e-mail, phone and a message, plus a GDPR consent checkbox.', 'ux-studio' ) ],
		[ __( 'Event registration', 'ux-studio' ), __( 'Registration for an event with name, e-mail, number of attendees, a choice of ticket type and a note.', 'ux-studio' ) ],
		[ __( 'Job application', 'ux-studio' ), __( 'A job application with name, e-mail, phone, the position applied for, a short cover letter, a CV upload and a consent to data processing.', 'ux-studio' ) ],
		[ __( 'Newsletter signup', 'ux-studio' ), __( 'A newsletter signup with first name, e-mail and a consent checkbox.', 'ux-studio' ) ],
		[ __( 'Service enquiry', 'ux-studio' ), __( 'An enquiry for a service with name, e-mail, phone, type of service, a short description and the best time to call.', 'ux-studio' ) ],
		[ __( 'Booking', 'ux-studio' ), __( 'A booking form with name, e-mail, phone, preferred date and time, number of people and a note.', 'ux-studio' ) ],
	];
}

export default function AiGenerateModal( {
	formId,
	onClose,
	onGenerated,
}: {
	formId: number;
	onClose: () => void;
	onGenerated: ( fields: FormField[] ) => void;
} ): JSX.Element {
	const [ description, setDescription ] = useState( '' );
	const [ multistep, setMultistep ] = useState( false );
	const area = useRef< HTMLTextAreaElement >( null );

	const status = useQuery( {
		queryKey: [ 'forms', 'ai-status' ],
		queryFn: () => api< AiStatus >( 'forms/ai-status' ),
		staleTime: 0,
		refetchOnMount: 'always',
	} );

	const generate = useMutation( {
		mutationFn: () =>
			api< { fields: FormField[] } >( `forms/${ formId }/ai-generate`, {
				method: 'POST',
				body: JSON.stringify( { description: description.trim(), multistep } ),
			} ),
		onSuccess: ( result ) => {
			onGenerated( result.fields );
			onClose();
		},
	} );

	// The box grows with the text (the CSS caps it at about ten lines, then it scrolls).
	useEffect( () => {
		const el = area.current;
		if ( el ) {
			el.style.height = 'auto';
			el.style.height = `${ el.scrollHeight }px`;
		}
	}, [ description, status.data ] );

	useEffect( () => {
		const onKey = ( e: KeyboardEvent ): void => {
			if ( 'Escape' === e.key && ! generate.isPending ) {
				onClose();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ generate.isPending, onClose ] );

	const length = description.length;
	const ready = length >= 10 && ! generate.isPending;
	const submit = (): void => {
		if ( ready ) {
			generate.mutate();
		}
	};

	// The server can also say "not available" (settings changed meanwhile): treat that like the status call.
	const serverReason = generate.error instanceof ApiError && 'uxstudio_forms_ai_unavailable' === generate.error.code ? String( generate.error.data?.reason ?? '' ) : '';
	const unavailable = status.data && ! status.data.available ? status.data.reason : serverReason;
	const errorText = generate.isError && '' === serverReason ? ( generate.error as Error ).message : '';

	return (
		<div className="uxs-overlay" onClick={ () => ! generate.isPending && onClose() }>
			<div className="uxs-modal uxs-aigen" role="dialog" aria-modal="true" aria-labelledby="uxs-aigen-title" onClick={ ( e ) => e.stopPropagation() }>
				<div className="uxs-aigen__head">
					<h2 id="uxs-aigen-title">
						<Sparkles size={ 18 } aria-hidden /> { __( 'Generate fields with AI', 'ux-studio' ) }
					</h2>
					<button type="button" className="button-link" aria-label={ __( 'Close', 'ux-studio' ) } onClick={ onClose }>
						<X size={ 18 } />
					</button>
				</div>

				{ status.isLoading && (
					<p className="uxs-form__help">
						<LoaderCircle size={ 14 } aria-hidden /> { __( 'Loading…', 'ux-studio' ) }
					</p>
				) }

				{ '' !== unavailable ? (
					<div className="uxs-aigen__empty" role="alert">
						<TriangleAlert size={ 22 } aria-hidden />
						<div>
							<strong>{ __( 'AI is not set up yet', 'ux-studio' ) }</strong>
							<p>
								{ 'module_off' === unavailable
									? __( 'The AI Assistant module is off. Switch it on and add an API key to generate form fields.', 'ux-studio' )
									: __( 'No API key is set for the AI Assistant. Add one in its settings to generate form fields.', 'ux-studio' ) }
							</p>
							<button
								type="button"
								className="button button-primary"
								onClick={ () => {
									onClose();
									navigate( 'module', { id: 'ai-assistant' } );
								} }
							>
								{ __( 'Open AI Assistant settings', 'ux-studio' ) }
							</button>
						</div>
					</div>
				) : (
					status.data && (
						<>
							<div className="uxs-aigen__card">
								<textarea
									ref={ area }
									rows={ 3 }
									maxLength={ MAX_LENGTH }
									value={ description }
									disabled={ generate.isPending }
									aria-label={ __( 'Describe the form you want', 'ux-studio' ) }
									placeholder={ __( 'Describe the form you need…', 'ux-studio' ) }
									onChange={ ( e ) => setDescription( e.target.value ) }
									onKeyDown={ ( e ) => {
										if ( 'Enter' === e.key && ( e.ctrlKey || e.metaKey ) ) {
											e.preventDefault();
											submit();
										}
									} }
								/>
								<div className="uxs-aigen__bar">
									<button
										type="button"
										className={ `uxs-aigen__pill${ multistep ? ' is-on' : '' }` }
										aria-pressed={ multistep }
										disabled={ generate.isPending }
										onClick={ () => setMultistep( ! multistep ) }
									>
										<Layers size={ 14 } aria-hidden /> { __( 'Multi-step', 'ux-studio' ) }
									</button>
									<span className="uxs-aigen__count" aria-live="polite">
										<Sparkles size={ 14 } aria-hidden /> { sprintf( '%1$d/%2$d', length, MAX_LENGTH ) }
									</span>
									<button
										type="button"
										className="uxs-aigen__send"
										disabled={ ! ready }
										aria-label={ generate.isPending ? __( 'Generating…', 'ux-studio' ) : __( 'Generate', 'ux-studio' ) }
										title={ __( 'Generate (Ctrl+Enter)', 'ux-studio' ) }
										onClick={ submit }
									>
										{ generate.isPending ? <LoaderCircle size={ 18 } aria-hidden /> : <ArrowUp size={ 18 } aria-hidden /> }
									</button>
								</div>
							</div>

							<div className="uxs-aigen__chips" role="group" aria-label={ __( 'Examples', 'ux-studio' ) }>
								{ suggestions().map( ( [ label, text ] ) => (
									<button
										key={ label }
										type="button"
										className="uxs-aigen__chip"
										disabled={ generate.isPending }
										onClick={ () => {
											setDescription( text );
											area.current?.focus();
										} }
									>
										{ label }
									</button>
								) ) }
							</div>

							{ '' !== errorText && (
								<p className="uxs-aigen__error" role="alert">
									<TriangleAlert size={ 16 } aria-hidden /> { errorText }
								</p>
							) }

							<p className="uxs-form__help">
								{ __( 'Generated fields are appended to the canvas below for you to review, edit or remove - nothing is saved until you click "Save changes".', 'ux-studio' ) }
							</p>
						</>
					)
				) }
			</div>
		</div>
	);
}
