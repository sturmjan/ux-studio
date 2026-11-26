import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ImageIcon, LoaderCircle } from 'lucide-react';
import { api } from '../../app/api';

interface TierInfo {
	label: string;
	free: boolean;
}

interface ImageConfig {
	provider: string;
	label: string;
	has_key: boolean;
	capabilities: {
		styles: Record< string, { label: string } >;
		aspect_ratios: string[];
		resolutions: string[];
		qualities: Record< string, string >;
		tiers: Record< string, TierInfo >;
	};
}

interface CostEstimate {
	cost_usd: number;
	is_estimate: boolean;
	model: string;
}

interface GeneratedImage {
	attachment_id: number;
	url: string;
	edit_url: string;
	alt: string;
	width: number | null;
	height: number | null;
	model: string;
	cost_usd: number;
	cost_is_estimate: boolean;
}

/** English source labels for the option ids of the core (its own labels are Czech only). */
const styleLabel = ( id: string, fallback: string ): string => {
	const labels: Record< string, string > = {
		none: __( 'No style', 'ux-studio' ),
		photo: __( 'Photo', 'ux-studio' ),
		illustration: __( 'Illustration', 'ux-studio' ),
		'3d': __( '3D render', 'ux-studio' ),
		watercolor: __( 'Watercolor', 'ux-studio' ),
		anime: __( 'Anime', 'ux-studio' ),
		sketch: __( 'Sketch', 'ux-studio' ),
	};
	return labels[ id ] ?? fallback;
};

const qualityLabel = ( id: string, fallback: string ): string => {
	const labels: Record< string, string > = {
		low: __( 'Low (cheapest)', 'ux-studio' ),
		medium: __( 'Medium', 'ux-studio' ),
		high: __( 'High (most expensive)', 'ux-studio' ),
		auto: __( 'Automatic', 'ux-studio' ),
	};
	return labels[ id ] ?? fallback;
};

const tierLabel = ( id: string, fallback: string ): string => {
	const labels: Record< string, string > = {
		preview: __( 'Preview, free (Gemini 2.5 Flash)', 'ux-studio' ),
		final: __( 'Final quality, paid', 'ux-studio' ),
	};
	return labels[ id ] ?? fallback;
};

const formatCost = ( usd: number ): string =>
	0 === usd ? __( 'Free', 'ux-studio' ) : `${ usd < 0.01 ? usd.toFixed( 4 ) : usd.toFixed( 3 ) } USD`;

/**
 * "Image" card of the Content Creator: prompt, style, shape, resolution/quality, live cost estimate, then the result
 * as a Media Library item.
 */
export function ImageSection(): JSX.Element {
	const config = useQuery( {
		queryKey: [ 'ai-assistant', 'image', 'config' ],
		queryFn: () => api< ImageConfig >( 'ai-assistant/image/config' ),
	} );

	const [ prompt, setPrompt ] = useState( '' );
	const [ style, setStyle ] = useState( 'none' );
	const [ aspectRatio, setAspectRatio ] = useState( '1:1' );
	const [ resolution, setResolution ] = useState( '1K' );
	const [ quality, setQuality ] = useState( 'medium' );
	const [ tier, setTier ] = useState( '' );
	const [ result, setResult ] = useState< GeneratedImage | null >( null );

	const caps = config.data?.capabilities;
	const tiers = Object.keys( caps?.tiers ?? {} );
	const activeTier = tiers.includes( tier ) ? tier : tiers[ 0 ] ?? 'final';
	const qualities = Object.keys( caps?.qualities ?? {} );
	const resolutions = caps?.resolutions ?? [];
	const hasKey = Boolean( config.data?.has_key );

	const payload = {
		prompt: 'x', // The price does not depend on the text; a placeholder keeps the estimate live without a request per keystroke.
		style,
		aspect_ratio: aspectRatio,
		resolution,
		quality: qualities.includes( quality ) ? quality : qualities[ 0 ] ?? 'medium',
		tier: activeTier,
	};

	const estimate = useQuery( {
		queryKey: [ 'ai-assistant', 'image', 'estimate', payload.style, payload.aspect_ratio, payload.resolution, payload.quality, payload.tier ],
		queryFn: () => api< CostEstimate >( 'ai-assistant/image/estimate', { method: 'POST', body: JSON.stringify( payload ) } ),
		enabled: hasKey,
	} );

	const generate = useMutation( {
		mutationFn: () =>
			api< GeneratedImage >( 'ai-assistant/image/generate', {
				method: 'POST',
				body: JSON.stringify( { ...payload, prompt: prompt.trim() } ),
			} ),
		onSuccess: ( data ) => setResult( data ),
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<h2>{ __( 'Generate an image', 'ux-studio' ) }</h2>

			{ config.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 18 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }

			{ config.data && ! hasKey ? (
				<p className="uxs-form__help" role="status">
					{ sprintf(
						/* translators: %s: image provider name */
						__( 'Image generation is off: no API key is set for %s. Add it in the AI Assistant settings (Settings tab, Image provider).', 'ux-studio' ),
						config.data.label
					) }
				</p>
			) : null }

			{ config.data ? (
				<>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cc-image-prompt">{ __( 'Describe the image', 'ux-studio' ) }</label>
						<textarea
							id="uxs-cc-image-prompt"
							rows={ 3 }
							maxLength={ 4000 }
							disabled={ ! hasKey }
							value={ prompt }
							onChange={ ( e ) => setPrompt( e.target.value ) }
						/>
						<p className="uxs-form__help">{ __( 'Describe the subject, setting and mood. Avoid text and real people in the image.', 'ux-studio' ) }</p>
					</div>

					<div className="uxs-form__row">
						<label htmlFor="uxs-cc-image-style">{ __( 'Style', 'ux-studio' ) }</label>
						<select id="uxs-cc-image-style" disabled={ ! hasKey } value={ style } onChange={ ( e ) => setStyle( e.target.value ) }>
							{ Object.entries( caps?.styles ?? {} ).map( ( [ id, s ] ) => (
								<option key={ id } value={ id }>
									{ styleLabel( id, s.label ) }
								</option>
							) ) }
						</select>
					</div>

					<div className="uxs-form__row">
						<label htmlFor="uxs-cc-image-ratio">{ __( 'Aspect ratio', 'ux-studio' ) }</label>
						<select id="uxs-cc-image-ratio" disabled={ ! hasKey } value={ aspectRatio } onChange={ ( e ) => setAspectRatio( e.target.value ) }>
							{ ( caps?.aspect_ratios ?? [] ).map( ( r ) => (
								<option key={ r } value={ r }>
									{ r }
								</option>
							) ) }
						</select>
					</div>

					{ tiers.length > 1 ? (
						<div className="uxs-form__row">
							<label htmlFor="uxs-cc-image-tier">{ __( 'Quality tier', 'ux-studio' ) }</label>
							<select id="uxs-cc-image-tier" disabled={ ! hasKey } value={ activeTier } onChange={ ( e ) => setTier( e.target.value ) }>
								{ tiers.map( ( id ) => (
									<option key={ id } value={ id }>
										{ tierLabel( id, caps?.tiers[ id ]?.label ?? id ) }
									</option>
								) ) }
							</select>
						</div>
					) : null }

					{ resolutions.length > 0 && 'preview' !== activeTier ? (
						<div className="uxs-form__row">
							<label htmlFor="uxs-cc-image-resolution">{ __( 'Resolution', 'ux-studio' ) }</label>
							<select id="uxs-cc-image-resolution" disabled={ ! hasKey } value={ resolution } onChange={ ( e ) => setResolution( e.target.value ) }>
								{ resolutions.map( ( r ) => (
									<option key={ r } value={ r }>
										{ r }
									</option>
								) ) }
							</select>
						</div>
					) : null }

					{ qualities.length > 0 ? (
						<div className="uxs-form__row">
							<label htmlFor="uxs-cc-image-quality">{ __( 'Quality', 'ux-studio' ) }</label>
							<select id="uxs-cc-image-quality" disabled={ ! hasKey } value={ payload.quality } onChange={ ( e ) => setQuality( e.target.value ) }>
								{ qualities.map( ( q ) => (
									<option key={ q } value={ q }>
										{ qualityLabel( q, caps?.qualities[ q ] ?? q ) }
									</option>
								) ) }
							</select>
						</div>
					) : null }

					{ hasKey && estimate.data ? (
						<p className="uxs-form__help" aria-live="polite">
							{ estimate.data.is_estimate
								? sprintf(
										/* translators: %s: estimated price in USD, or "Free" */
										__( 'Estimated cost: %s', 'ux-studio' ),
										formatCost( estimate.data.cost_usd )
								  )
								: sprintf(
										/* translators: %s: price in USD */
										__( 'Cost: %s', 'ux-studio' ),
										formatCost( estimate.data.cost_usd )
								  ) }
						</p>
					) : null }

					<button
						type="button"
						className="button button-primary"
						disabled={ ! hasKey || prompt.trim() === '' || generate.isPending }
						onClick={ () => generate.mutate() }
					>
						{ generate.isPending ? <LoaderCircle size={ 14 } /> : <ImageIcon size={ 14 } /> } { __( 'Generate image', 'ux-studio' ) }
					</button>
					{ generate.isPending ? <p className="uxs-form__help">{ __( 'Generating, this can take up to a minute.', 'ux-studio' ) }</p> : null }
					{ generate.isError ? <p className="uxs-form__help" role="alert">{ ( generate.error as Error ).message }</p> : null }
				</>
			) : null }

			{ result ? (
				<div style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<img src={ result.url } alt={ result.alt } style={ { maxWidth: '100%', maxHeight: 420, borderRadius: 8 } } />
					<p className="uxs-form__help">
						{ result.width && result.height ? `${ result.width } x ${ result.height } px · ` : '' }
						{ result.model } · { formatCost( result.cost_usd ) }
						{ ' · ' }
						<a href={ result.edit_url } target="_blank" rel="noreferrer">
							{ __( 'Open in Media Library', 'ux-studio' ) }
						</a>
					</p>
				</div>
			) : null }
		</div>
	);
}
